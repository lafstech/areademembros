<?php
require_once '../config.php'; // Inicia a sessão para poder destruí-la

// Limpa todas as variáveis de sessão
$_SESSION = array();

// Se você quisesse garantir a exclusão do cookie de sessão do PHP:
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destrói a sessão
session_destroy();

// Redireciona para a página de login com uma mensagem de sucesso
header('Location: index.php?status=logout_success');
exit();
?>