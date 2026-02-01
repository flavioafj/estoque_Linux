<?php
/**
 * Script de Sincronização - scripts/sync.php
 * Para Raspberry Pi OS (local)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Models/Database.php';

use Models\Database;

$db = Database::getInstance();

// PUSH Local → Web
$pendentes = $db->query(
    "SELECT * FROM sincronizacao WHERE sincronizado = 0 AND tentativas < 5 ORDER BY criado_em ASC LIMIT 20",
    []  // Sem params
);

if (!empty($pendentes)) {  // Usa retorno array de query()
    foreach ($pendentes as $item) {
        $payload = [
            'tabela' => $item['tabela'],
            'registro_id' => $item['registro_id'],
            'acao' => $item['acao'],
            'dados' => json_decode($item['dados'], true)
        ];

        $ch = curl_init(SYNC_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Api-Token: Bearer ' . SYNC_TOKEN],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30  // Para RPi redes lentas
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200) {
            $db->update('sincronizacao', ['sincronizado' => 1, 'sincronizado_em' => date('Y-m-d H:i:s')], "id = :id", ['id' => $item['id']]);
        } else {
            $db->update('sincronizacao', ['tentativas' => $item['tentativas'] + 1, 'erro_mensagem' => $response], "id = :id", ['id' => $item['id']]);
            error_log("Push erro: HTTP $http_code - $response", 3, LOG_PATH . '/sync_error.log');
        }
    }
}

// PULL Web → Local
$last_sync = $db->query("SELECT MAX(sincronizado_em) FROM sincronizacao")[0]['MAX(sincronizado_em)'] ?? '2000-01-01';
$ch = curl_init(SYNC_URL . '?last_sync=' . urlencode($last_sync));
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ['X-Api-Token: Bearer ' . SYNC_TOKEN],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200) {
    $data = json_decode($response, true);
    if (isset($data['success']) && !empty($data['data'])) {
        $db->beginTransaction();
        try {
            foreach ($data['data'] as $item) {
                $dados = json_decode($item['dados'], true);
                switch ($item['acao']) {
                    case 'INSERT': $db->insert($item['tabela'], $dados); break;
                    case 'UPDATE': $db->update($item['tabela'], $dados, "id = :id", ['id' => $item['registro_id']]); break;
                    case 'DELETE': $db->delete($item['tabela'], "id = :id", ['id' => $item['registro_id']]); break;
                }
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            error_log("Pull erro: " . $e->getMessage(), 3, LOG_PATH . '/sync_error.log');
        }
    }
}