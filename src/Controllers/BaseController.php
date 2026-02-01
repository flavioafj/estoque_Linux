<?php
/**
 * Controlador Base
 * src/Controllers/BaseController.php
 */

namespace Controllers;

abstract class BaseController {
    /**
     * Carrega uma view, envolvendo-a com o header e footer padrão.
     *
     * @param string $viewName O nome do arquivo da view (sem .php) dentro da pasta templates.
     * @param array $data Dados a serem extraídos e disponibilizados para a view.
     */
    protected function render($viewName, $data = []) {
        extract($data); // Transforma chaves do array em variáveis ($data['titulo'] vira $titulo)

        $templatePath = dirname(__DIR__) . '/../templates/';

        // Inclui o cabeçalho
        if (file_exists($templatePath . 'header.php')) {
            require_once $templatePath . 'header.php';
        }

        // Inclui o conteúdo principal da view
        $viewFile = $templatePath . $viewName . '.php';
        if (file_exists($viewFile)) {
            require_once $viewFile;
        } else {
            // Tratar erro de view não encontrada
            echo "<p>Erro: View '$viewName' não encontrada.</p>";
        }

        // Inclui o rodapé
        if (file_exists($templatePath . 'footer.php')) {
            require_once $templatePath . 'footer.php';
        }
    }
}