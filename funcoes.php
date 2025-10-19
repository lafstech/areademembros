<?php
// Arquivo: funcoes.php (Na raiz do projeto)

/**
 * Funções utilitárias para o sistema, incluindo envio de e-mails via Mailgun.
 */

// Garante que as configurações da Mailgun estejam carregadas
// CORREÇÃO: Usamos __DIR__ para garantir que o arquivo seja encontrado
if (file_exists(__DIR__ . '/mailgun_config.php')) {
    require_once __DIR__ . '/mailgun_config.php';
}

/**
 * Envia um e-mail através da API da Mailgun.
 * * @param string $para O endereço de e-mail do destinatário.
 * @param string $assunto O assunto do e-mail.
 * @param string $html_body O corpo do e-mail em formato HTML.
 * @return bool True se o envio for bem-sucedido (API retornar ID), False caso contrário.
 */
function enviarEmailMailgun($para, $assunto, $html_body) {
    // 1. Verifica se as constantes de configuração foram definidas
    if (!defined('MAILGUN_API_KEY') || !defined('MAILGUN_DOMAIN') || !defined('MAILGUN_FROM_EMAIL')) {
        // Esta mensagem de erro será registrada se o mailgun_config.php não foi lido ou está incompleto.
        error_log("ERRO: Configurações da Mailgun (API KEY, DOMAIN ou FROM_EMAIL) ausentes.");
        return false;
    }

    // A constante MAILGUN_API_URL deve ser definida no mailgun_config.php
    if (!defined('MAILGUN_API_URL')) {
        error_log("ERRO: A constante MAILGUN_API_URL está ausente.");
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
        // Endpoint: Ex: https://api.mailgun.net/v3/seudominio/messages
        CURLOPT_URL => MAILGUN_API_URL . "/" . MAILGUN_DOMAIN . "/messages",

        // Autenticação HTTP básica com a chave da API
        CURLOPT_USERPWD => "api:" . MAILGUN_API_KEY,

        // Método POST
        CURLOPT_POST => true,

        // Dados a serem enviados
        CURLOPT_POSTFIELDS => $postData,

        // Retornar a resposta em vez de exibi-la
        CURLOPT_RETURNTRANSFER => true,

        // Nota: CURLOPT_SSL_VERIFYPEER = true (padrão e seguro)
        CURLOPT_SSL_VERIFYPEER => true,

        // Tempo limite
        CURLOPT_TIMEOUT => 30,

        // Garante que o PHP use o tipo correto para o POST
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
        // Verifica se a Mailgun retornou um ID de mensagem
        return isset($result['id']);
    } else {
        error_log("ERRO MAILGUN HTTP {$httpCode}: Resposta: " . $response);
        return false;
    }
}

// --- Você pode adicionar outras funções utilitárias aqui no futuro ---

?>