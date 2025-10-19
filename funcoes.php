<?php
// Arquivo: funcoes.php (Na raiz do projeto)

// Garante que a conexão PDO ($pdo) e as funções globais estejam carregadas
// (config.php já faz isso)
require_once 'config.php';

// ⭐ IMPORTANTE: O arquivo load_settings.php DEVE SER INCLUÍDO ANTES DE USAR
// as constantes de configuração.
// Como load_settings.php depende de $pdo (que está no config.php),
// você deve incluir load_settings.php no SEU config.php, após a criação de $pdo.

// Removido: require_once __DIR__ . '/mailgun_config.php'; // Chaves agora vêm do DB

/**
 * Envia um e-mail através da API da Mailgun.
 * * @param string $para O endereço de e-mail do destinatário.
 * @param string $assunto O assunto do e-mail.
 * @param string $html_body O corpo do e-mail em formato HTML.
 * @return bool True se o envio for bem-sucedido (API retornar ID), False caso contrário.
 */
function enviarEmailMailgun($para, $assunto, $html_body) {
    // 1. Verifica se as constantes de configuração foram definidas (agora lidas do DB)
    // Usamos 'defined' para verificar se foram definidas via define() em load_settings.php
    if (!defined('MAILGUN_API_KEY') || !defined('MAILGUN_DOMAIN') || !defined('MAILGUN_FROM_EMAIL')) {
        error_log("ERRO: Configurações da Mailgun (API KEY, DOMAIN ou FROM_EMAIL) ausentes no DB.");
        return false;
    }

    // 2. Configura os dados para a API
    $ch = curl_init();

    $postData = [
        'from'    => MAILGUN_FROM_EMAIL,
        'to'      => $para,
        'subject' => $assunto,
        'html'    => $html_body,
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL => MAILGUN_API_URL . "/" . MAILGUN_DOMAIN . "/messages",
        CURLOPT_USERPWD => "api:" . MAILGUN_API_KEY,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SAFE_UPLOAD => true,
    ]);

    // 3. Executa a requisição e captura erros
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log("ERRO MAILGUN cURL: Falha na comunicação: " . $err);
        return false;
    }

    // 4. Analisa a resposta da API (Mailgun retorna 200/OK em caso de sucesso)
    if ($httpCode >= 200 && $httpCode < 300) {
        $result = json_decode($response, true);
        return isset($result['id']);
    } else {
        error_log("ERRO MAILGUN HTTP {$httpCode}: Resposta: " . $response);
        return false;
    }
}
// --- Você pode adicionar outras funções utilitárias aqui no futuro ---
?>