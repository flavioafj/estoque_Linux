<?php
/**
 * Controlador de Sincronização
 * src/Controllers/SyncController.php
 */

namespace Controllers;

use Models\Database;
use Middleware\Auth;

class SyncController extends BaseController {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Recebe alterações do sistema local via API
     */
    public function receiveSync() {
        // Verificar token de autenticação
        $headers = getallheaders();
        $token = $headers['Authorization'] ?? '';
        if ($token !== 'Bearer ' . SYNC_TOKEN) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Token inválido']);
            exit;
        }

        // Receber dados JSON
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (!$data || !isset($data['id'], $data['tabela'], $data['acao'], $data['registro_id'], $data['dados'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Dados inválidos']);
            exit;
        }

        try {
            $this->db->beginTransaction();

            // Processar ação (INSERT, UPDATE, DELETE)
            switch ($data['acao']) {
                case 'INSERT':
                    $this->db->insert($data['tabela'], $data['dados']);
                    break;
                case 'UPDATE':
                    $this->db->update($data['tabela'], $data['dados'], "id = :id", ['id' => $data['registro_id']]);
                    break;
                case 'DELETE':
                    $this->db->delete($data['tabela'], "id = :id", ['id' => $data['registro_id']]);
                    break;
                default:
                    throw new \Exception('Ação inválida');
            }

            // Registrar sincronização no servidor web
            $this->db->insert('sincronizacao', [
                'tabela' => $data['tabela'],
                'registro_id' => $data['registro_id'],
                'acao' => $data['acao'],
                'dados' => json_encode($data['dados']),
                'sincronizado' => true,
                'sincronizado_em' => date('Y-m-d H:i:s')
            ]);

            $this->db->commit();
            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Sincronização concluída']);
        } catch (\Exception $e) {
            $this->db->rollback();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erro ao processar sincronização: ' . $e->getMessage()]);
        }
    }

    /**
     * Envia alterações pendentes do servidor web para o sistema local
     */
    public function sendSync() {
        // Verificar token
        $headers = getallheaders();
        $token = $headers['Authorization'] ?? '';
        if ($token !== 'Bearer ' . SYNC_TOKEN) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Token inválido']);
            exit;
        }

        // Buscar alterações pendentes
        $pendentes = $this->db->query("SELECT * FROM sincronizacao WHERE sincronizado = 0 AND tentativas < 3");
        if (empty($pendentes)) {
            echo json_encode(['success' => true, 'data' => [], 'message' => 'Nenhuma alteração pendente']);
            return;
        }

        echo json_encode(['success' => true, 'data' => $pendentes]);
    }
}