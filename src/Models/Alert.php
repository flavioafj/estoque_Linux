<?php
namespace Models;

use PDOException;
use Helpers\Session;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Models\User;

require_once __DIR__ . '/../../vendor/autoload.php'; 

class Alert extends BaseModel {
    protected $table = 'alertas_estoque';

    public function checkLowStock($produto_id) {
        $this->checkLowStockBatch([$produto_id]);
    }

    public function checkLowStockBatch(array $productIds): void {
        $triggeredIds = [];
        foreach (array_unique($productIds) as $productId) {
            if ($this->createPendingAlerts($productId)) {
                $triggeredIds[$productId] = true;
            }
        }

        if ($triggeredIds) {
            $this->sendLowStockSummary($triggeredIds);
        }
    }

    private function createPendingAlerts($produto_id): bool {
        try {
            $stmt = $this->db->prepare("SELECT * FROM vw_produtos_estoque_critico WHERE id = :produto_id");
            if (!$stmt || !$stmt->execute([':produto_id' => $produto_id])) {
                return false;
            }
            $result = $stmt->fetch();

            if ($result) {
                $adminStmt = $this->db->prepare(
                    "SELECT u.id FROM usuarios u
                     WHERE u.perfil_id = 1 AND u.ativo = TRUE
                     AND NOT EXISTS (
                         SELECT 1 FROM alertas_estoque a
                         WHERE a.produto_id = :produto_id AND a.tipo_alerta = 'MINIMO'
                         AND a.lido = FALSE AND a.usuario_leitura_id = u.id
                     ) ORDER BY u.id"
                );
                if (!$adminStmt || !$adminStmt->execute([':produto_id' => $produto_id])) {
                    return false;
                }
                $adminUsers = $adminStmt->fetchAll();
                if (!$adminUsers) {
                    return false;
                }

                $mensagem = "Produto {$result['nome']} com estoque baixo: {$result['estoque_atual']} <= {$result['estoque_minimo']}.";
                
                $created = false;
                foreach ($adminUsers as $admin) {
                    $insertStmt = $this->db->prepare("INSERT INTO alertas_estoque (produto_id, tipo_alerta, mensagem, lido, usuario_leitura_id, criado_em) VALUES (:produto_id, 'MINIMO', :mensagem, FALSE, :admin_id, CURRENT_TIMESTAMP)");
                    if ($insertStmt && $insertStmt->execute([
                        ':produto_id' => $produto_id,
                        ':mensagem' => $mensagem,
                        ':admin_id' => $admin['id'],
                    ])) {
                        $created = true;
                        $alertId = $this->db->lastInsertId();
                        $this->logAudit('INSERT', $alertId, null, ['produto_id' => $produto_id, 'mensagem' => $mensagem]);
                    }
                }
                return $created;
            }
        } catch (PDOException $e) {
            error_log("Alert::checkLowStock: " . $e->getMessage(), 3, __DIR__ . '/../../logs/error.log');
        }
        return false;
    }

    private function sendLowStockSummary(array $triggeredIds): void {
        $stmt = $this->db->prepare(
            "SELECT id, nome, estoque_atual, estoque_minimo
             FROM vw_produtos_estoque_critico ORDER BY nome, id"
        );
        if (!$stmt || !$stmt->execute()) {
            return;
        }
        $products = $stmt->fetchAll();
        if (!$products) {
            return;
        }

        $triggered = [];
        $otherProducts = [];
        foreach ($products as $product) {
            if (isset($triggeredIds[$product['id']])) {
                $triggered[] = $product;
            } else {
                $otherProducts[] = $product;
            }
        }
        $mail = self::createMailer($otherProducts, 'Outros produtos com estoque baixo', $triggered);
        foreach ((new User())->getAdmins() as $admin) {
            $email = trim($admin['email'] ?? '');
            if (!PHPMailer::validateAddress($email)) {
                continue;
            }

            try {
                $mail->addAddress($email);
                $mail->send();
            } catch (Exception $e) {
                error_log("Alert::sendLowStockSummary: " . $e->getMessage(), 3, __DIR__ . '/../../logs/error.log');
            } finally {
                $mail->clearAddresses();
            }
        }
    }

    public static function createMailer(array $products, string $intro = 'Produtos com estoque baixo', array $triggered = []): PHPMailer {
        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->isSMTP();
        $mail->Host = ($_ENV['SMTP_HOST'] ?? '') ?: 'smtp.exemplo.com';
        $mail->SMTPAuth = true;
        $mail->Username = ($_ENV['SMTP_USER'] ?? '') ?: 'seu_email@exemplo.com';
        $mail->Password = ($_ENV['SMTP_PASS'] ?? '') ?: 'sua_senha';
        $mail->SMTPSecure = 'tls';
        $mail->Port = ($_ENV['SMTP_PORT'] ?? '') ?: 587;

        $mail->setFrom('no-reply@sorveteria.com', 'Sistema de Estoque');
        $mail->isHTML(true);
        $mail->Subject = 'Alerta de Estoque Baixo';
        $body = '';
        foreach ($triggered as $product) {
            $name = htmlspecialchars((string) $product['nome'], ENT_QUOTES, 'UTF-8');
            $body .= "<p><strong>{$name} está com estoque baixo.</strong></p>";
        }
        if (!$products) {
            $mail->Body = $body;
            return $mail;
        }
        $body .= '<p>' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p><ul>';
        foreach ($products as $product) {
            $name = htmlspecialchars((string) $product['nome'], ENT_QUOTES, 'UTF-8');
            $stock = htmlspecialchars((string) $product['estoque_atual'], ENT_QUOTES, 'UTF-8');
            $minimum = htmlspecialchars((string) $product['estoque_minimo'], ENT_QUOTES, 'UTF-8');
            $body .= "<li><strong>{$name}</strong> — Estoque Atual: {$stock} | Estoque Mínimo: {$minimum}</li>";
        }
        $mail->Body = $body . '</ul>';
        return $mail;
    }

    public function markAsRead($alert_id, $usuario_id) {
        try {
            $stmt = $this->db->prepare("UPDATE alertas_estoque SET lido = TRUE, data_leitura = CURRENT_TIMESTAMP WHERE id = :alert_id AND usuario_leitura_id = :usuario_id");
            $stmt->bind(':alert_id', $alert_id, \PDO::PARAM_INT);
            $stmt->bind(':usuario_id', $usuario_id, \PDO::PARAM_INT);
            $stmt->execute();

            // Registra na auditoria
            $this->logAudit('UPDATE', $alert_id, ['lido' => false], ['lido' => true]);
        } catch (PDOException $e) {
            error_log("Alert::markAsRead: " . $e->getMessage(), 3, __DIR__ . '/../../logs/error.log');
        }
    }

    public function markAllAsRead(int $usuarioId): bool {
        $stmt = $this->db->prepare(
            "UPDATE alertas_estoque
             SET lido = TRUE, data_leitura = CURRENT_TIMESTAMP
             WHERE usuario_leitura_id = :usuario_id AND lido = FALSE"
        );
        return $stmt !== false && $stmt->execute([':usuario_id' => $usuarioId]);
    }

    public function getPendingAlerts() {
        try {
            $stmt = $this->db->prepare("SELECT a.id, a.produto_id, a.tipo_alerta, a.mensagem, a.criado_em, p.nome FROM alertas_estoque a JOIN produtos p ON a.produto_id = p.id WHERE a.lido = FALSE AND a.usuario_leitura_id = :usuario_id ORDER BY a.criado_em DESC LIMIT 10");
            $stmt->bind(':usuario_id', Session::get('user_id'), \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Alert::getPendingAlerts: " . $e->getMessage(), 3, __DIR__ . '/../../logs/error.log');
            return [];
        }
    }

    // Placeholder para futura implementação de WhatsApp
    private function sendWhatsApp($alert) {
        // Futura implementação usando API externa (ex.: Twilio)
        // Exemplo: $twilio = new Client(getenv('TWILIO_SID'), getenv('TWILIO_TOKEN'));
        // $twilio->messages->create('whatsapp:+numero', ['from' => 'whatsapp:+seu_numero', 'body' => $alert['mensagem']]);
    }
}