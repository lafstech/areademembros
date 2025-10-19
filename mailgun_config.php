<?php
// Arquivo: mailgun_config.php (Na raiz do projeto)

/**
 * Configurações da API Mailgun para envio de e-mails.
 * ESTES SÃO OS DADOS FORNECIDOS PELO USUÁRIO (lafstech.store).
 */

// Seu domínio registrado na Mailgun
define('MAILGUN_DOMAIN', 'lafstech.store');

// Sua chave de API privada da Mailgun (começa com 'key-' para o SDK, mas usaremos a chave completa)
// Nota: O SDK espera a chave sem o prefixo 'key-', mas é bom tê-lo em mente.
define('MAILGUN_API_KEY', 'a70370241218ff94a9eea3e39ac6f86b-556e0aa9-fb956281');

// O endereço de e-mail que aparecerá como remetente.
// Usaremos o padrão sugerido pela Mailgun (postmaster@seudominio).
define('MAILGUN_FROM_EMAIL', 'noreply@lafstech.store');

// URL base da API da Mailgun. Geralmente 'https://api.mailgun.net/v3' (Endpoint US)
// Se você estiver na União Europeia (EU), mude para 'https://api.eu.mailgun.net/v3'.
// Presumiremos o endpoint padrão (US) para a maioria dos casos.
define('MAILGUN_API_URL', 'https://api.mailgun.net/v3');

// --- Fim da Configuração ---

?>