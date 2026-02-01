<?php
/**
 * Classe Session - Gerenciamento de Sessões
 * src/Helpers/Session.php
 */

namespace Helpers;

class Session {
    
    // Iniciar sessão
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_start();
        }
    }
    
    // Definir valor na sessão
    public static function set($key, $value) {
        self::start();
        $_SESSION[$key] = $value;
    }
    
    // Obter valor da sessão
    public static function get($key, $default = null) {
        self::start();
        return isset($_SESSION[$key]) ? $_SESSION[$key] : $default;
    }
    
    // Verificar se existe na sessão
    public static function has($key) {
        self::start();
        return isset($_SESSION[$key]);
    }
    
    // Remover item da sessão
    public static function remove($key) {
        self::start();
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }
    
    // Limpar toda a sessão
    public static function clear() {
        self::start();
        session_unset();
    }
    
    // Destruir sessão
    public static function destroy() {
        self::start();
        session_unset();
        session_destroy();
    }
    
    // Regenerar ID da sessão (segurança)
    public static function regenerate() {
        self::start();
        session_regenerate_id(true);
    }
    
    // Definir mensagem flash
    public static function setFlash($type, $message) {
        self::set('flash_messages', [
            'type' => $type,
            'message' => $message
        ]);
    }
    
    // Obter mensagem flash
    public static function getFlash() {
        $flash = self::get('flash_messages');
        self::remove('flash_messages');
        return $flash;
    }
    
    // Verificar se tem mensagem flash
    public static function hasFlash() {
        return self::has('flash_messages');
    }
    
    // Login do usuário
    public static function login($userData) {
        self::start();
        self::regenerate();
        
        self::set('user_id', $userData['id']);
        self::set('user_name', $userData['nome_completo']);
        self::set('user_email', $userData['email']);
        self::set('user_profile', $userData['perfil_id']);
        self::set('user_profile_name', $userData['perfil_nome']);
        self::set('logged_in', true);
        self::set('login_time', time());
        
        // Salvar sessão no banco
        self::saveToDatabase($userData['id']);
    }
    
    // Logout do usuário
    public static function logout() {
        // Remover sessão do banco
        if (self::has('user_id')) {
            self::removeFromDatabase(self::get('user_id'));
        }
        
        self::destroy();
    }
    
    // Verificar se está logado
    public static function isLoggedIn() {
        return self::get('logged_in', false) === true;
    }
    
    // Verificar se é administrador
    public static function isAdmin() {
        return self::get('user_profile') == 1;
    }
    
    // Verificar se é operador
    public static function isOperator() {
        return self::get('user_profile') == 2;
    }
    
    // Obter ID do usuário logado
    public static function getUserId() {
        return self::get('user_id');
    }
    
    // Obter nome do usuário logado
    public static function getUserName() {
        return self::get('user_name');
    }

        // Gerenciamento de Carrinho  
    public static function addToCart(int $produtoId, float $quantidade) {  
        self::start();  
        if (!self::has('cart')) {  
            self::set('cart', []);  
        }  
        $cart = self::get('cart');  
        if (isset($cart[$produtoId])) {  
            $cart[$produtoId] += $quantidade;  
        } else {  
            $cart[$produtoId] = $quantidade;  
        }  
        self::set('cart', $cart);  
    }  
  
    public static function getCart(): array {  
        return self::get('cart', []);  
    }  
  
    public static function updateCartItem(int $produtoId, float $quantidade) {  
        $cart = self::getCart();  
        if (isset($cart[$produtoId])) {  
            if ($quantidade > 0) {  
                $cart[$produtoId] = $quantidade;  
            } else {  
                unset($cart[$produtoId]);  
            }  
            self::set('cart', $cart);  
        }  
    }  
  
    public static function removeFromCart(int $produtoId) {  
        $cart = self::getCart();  
        unset($cart[$produtoId]);  
        self::set('cart', $cart);  
    }  
  
    public static function clearCart() {  
        self::remove('cart');  
    }  
    
    // Verificar timeout da sessão
    public static function checkTimeout() {
        if (!self::isLoggedIn()) {
            return false;
        }
        
        $loginTime = self::get('login_time', 0);
        $currentTime = time();
        $timeout = SESSION_LIFETIME;
        
        if (($currentTime - $loginTime) > $timeout) {
            self::setFlash('warning', 'Sua sessão expirou. Por favor, faça login novamente.');
            self::logout();
            return false;
        }
        
        // Atualizar tempo de atividade
        self::set('last_activity', $currentTime);
        return true;
    }
    
    // Gerar token CSRF
    public static function generateCSRFToken() {
        $token = bin2hex(random_bytes(32));
        self::set(CSRF_TOKEN_NAME, $token);
        return $token;
    }
    
    // Validar token CSRF
    public static function validateCSRFToken($token) {
        $sessionToken = self::get(CSRF_TOKEN_NAME);
        return $sessionToken && hash_equals($sessionToken, $token);
    }
    
    // Obter token CSRF
    public static function getCSRFToken() {
        if (!self::has(CSRF_TOKEN_NAME)) {
            self::generateCSRFToken();
        }
        return self::get(CSRF_TOKEN_NAME);
    }
    
    // Salvar sessão no banco de dados
    private static function saveToDatabase($userId) {
        $db = \Models\Database::getInstance();
        
        $sessionData = [
            'id' => session_id(),
            'usuario_id' => $userId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'dados' => json_encode($_SESSION)
        ];
        
        // Verificar se já existe
        if ($db->exists('sessoes', 'id = :id', [':id' => session_id()])) {
            $db->update('sessoes', $sessionData, 'id = :id', [':id' => session_id()]);
        } else {
            $db->insert('sessoes', $sessionData);
        }
    }
    
    // Remover sessão do banco de dados
    private static function removeFromDatabase($userId) {
        $db = \Models\Database::getInstance();
        $db->delete('sessoes', 'usuario_id = :user_id', [':user_id' => $userId]);
    }
    
    // Limpar sessões antigas do banco
    public static function cleanupOldSessions() {
        $db = \Models\Database::getInstance();
        $sql = "DELETE FROM sessoes WHERE ultimo_acesso < DATE_SUB(NOW(), INTERVAL :timeout SECOND)";
        $db->prepare($sql);
        $db->bind(':timeout', SESSION_LIFETIME);
        $db->execute();
    }
}