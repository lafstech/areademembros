<?php
// load_settings.php - Carrega configurações do banco de dados

// Assume que $pdo já está definido e a conexão está ativa (vindo do config.php)

// Array global para armazenar as configurações
$GLOBALS['SETTINGS'] = [];

try {
    $stmt = $pdo->query("SELECT chave, valor FROM configuracoes");
    $settings_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Armazena as configurações no array global
    $GLOBALS['SETTINGS'] = $settings_data;

    // Opcional: Define como constantes para compatibilidade com código antigo
    foreach ($settings_data as $chave => $valor) {
        if (!defined($chave)) {
            define($chave, $valor);
        }
    }

} catch (Exception $e) {
    error_log("ERRO ao carregar configurações do DB: " . $e->getMessage());
    // Se a tabela falhar ou não existir, o código continua e o sistema usa valores vazios/nulos,
    // que serão tratados como erro no envio de email/pix.
}
?>