<?php
namespace Helpers;

$databasePath = __DIR__ . '/../Models/Database.php';
if (!file_exists($databasePath)) {
    error_log("Validator.php: Não encontrou Database.php em $databasePath", 3, __DIR__ . '/../../logs/error.log');
    die("Erro: Não foi possível carregar Database.php.");
}
require_once $databasePath;

use Models\Database;

class Validator {
    public static function validate($data, $rules) {
        $errors = [];
        $db = Database::getInstance();

        foreach ($rules as $field => $ruleString) {
            // Inicializa $errors[$field] como array
            $errors[$field] = $errors[$field] ?? [];
            $ruleParts = explode('|', $ruleString);
             // Debugging line to check parsed rules
            foreach ($ruleParts as $rule) {
                // Regra: required
                
                if ($rule === 'required' && empty($data[$field])) {
                    $errors[$field][] = "$field é obrigatório.";
                }
                // Regra: numeric
                if ($rule === 'numeric' && !is_numeric($data[$field] ?? '')) {
                    $errors[$field][] = "$field deve ser numérico.";
                }
                // Regra: min
                if (strpos($rule, 'min:') === 0) {
                    $min = substr($rule, 4);
                    if (($data[$field] ?? 0) < $min) {
                        $errors[$field][] = "$field deve ser no mínimo $min.";
                    }
                }
                // Regra: unique
                if (strpos($rule, 'unique:') === 0) {
                    $parts = explode(',', substr($rule, 7) . ',,');
                    $table = $parts[0];
                    $column = $parts[1];
                    $ignoreId = isset($parts[2]) ? $parts[2] : null;
                    $where = "$column = :value";
                    $params = [':value' => $data[$field]];
                    if ($ignoreId !== null && $ignoreId !== '') {
                        $where .= " AND id != :id";
                        $params[':id'] = $ignoreId;
                    }
                    if ($db->exists($table, $where, $params)) {
                        $errors[$field][] = "$field já existe.";
                    }
                }
                // Regra: exists
                if (strpos($rule, 'exists:') === 0) {
                    list($table, $column) = explode(',', substr($rule, 7));
                    $where = "$column = :value";
                    $params = [':value' => $data[$field]];
                    if (!$db->exists($table, $where, $params)) {
                        $errors[$field][] = "$field não existe.";
                    }
                }
                // Regra: boolean
                if ($rule === 'boolean' && !in_array($data[$field] ?? null, [0, 1, '0', '1', true, false], true)) {
                    $errors[$field][] = "$field deve ser um valor booleano (0 ou 1).";
                }
            }
        }
        return $errors;
    }
}