<?php
/**
 * Script de Sincronização Local-Web
 * scripts/sync.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Models/Database.php';

use Models\Database;

$db = Database::getInstance();

// Enviar alterações locais para o servidor web
$pendentes = $db->query("SELECT * FROM sincronizacao WHERE sincronizado = 0 AND tentativas < 3");
foreach ($pendentes as $item) {
    $ch = curl_init(SYNC_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($item));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . SYNC_TOKEN
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $db->update('sincronizacao', [
            'sincronizado' => 1,
            'sincronizado_em' => date('Y-m-d H:i:s')
        ], "id = :id", ['id' => $item['id']]);
    } else {
        $db->update('sincronizacao', [
            'tentativas' => $item['tentativas'] + 1,
            'erro_mensagem' => $response
        ], "id = :id", ['id' => $item['id']]);
    }
}

// Receber alterações do servidor web
$ch = curl_init(SYNC_URL);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . SYNC_TOKEN
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200) {
    $data = json_decode($response, true);
    if ($data['success'] && !empty($data['data'])) {
        $db->beginTransaction();
        try {
            foreach ($data['data'] as $item) {
                switch ($item['acao']) {
                    case 'INSERT':
                        $db->insert($item['tabela'], json_decode($item['dados'], true));
                        break;
                    case 'UPDATE':
                        $db->update($item['tabela'], json_decode($item['dados'], true), "id = :id", ['id' => $item['registro_id']]);
                        break;
                    case 'DELETE':
                        $db->delete($item['tabela'], "id = :id", ['id' => $item['registro_id']]);
                        break;
                }
                // Registrar sincronização local
                $db->update('sincronizacao', [
                    'sincronizado' => 1,
                    'sincronizado_em' => date('Y-m-d H:i:s')
                ], "id = :id", ['id' => $item['id']]);
            }
            $db->commit();
        } catch (\Exception $e) {
            $db->rollback();
            error_log("Erro ao processar sincronização local: " . $e->getMessage(), 3, LOG_PATH . '/sync_error.log');
        }
    }
}