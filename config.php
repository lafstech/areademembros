<?php
// config.php

// --- CONFIGURAÇÃO DA SESSÃO PARA 7 DIAS (IMPERATIVO) ---
$validade_em_segundos = 60 * 60 * 24 * 7; // 7 dias

// --- NOVO: LÓGICA PARA SESSÕES SEPARADAS (Membro vs Admin) ---
// Define um nome de cookie de sessão diferente baseado no caminho da URL.
if (strpos($_SERVER['REQUEST_URI'], '/paineladmin') !== false) {
    // Se o caminho contiver 'paineladmin', usa um cookie específico
    session_name('ADM_SESSID');
} else {
    // Para a área de membros e o resto do site, usa um cookie diferente
    session_name('MEMB_SESSID');
}

// 1. Define os parâmetros do cookie de sessão (agora com nomes diferentes)
// O path '/' garante que o cookie é válido para todo o site.
session_set_cookie_params([
    'lifetime' => $validade_em_segundos,
    'path' => '/',
    // Adicione 'secure' => true, 'httponly' => true, se estiver em produção com HTTPS.
]);

// 2. Define o tempo máximo que o PHP vai guardar os dados da sessão no servidor
ini_set('session.gc_maxlifetime', $validade_em_segundos);

// Inicia a sessão em todas as páginas, se ainda não estiver iniciada
if (session_status() === PHP_SESSION_NONE) {
    // session_start() usará o nome de cookie definido acima (ADM_SESSID ou MEMB_SESSID)
    session_start();
}


// -- DETECÇÃO DE AMBIENTE E CONFIGURAÇÃO DO BANCO DE DADOS --

// Verifica se a variável de ambiente do Heroku/Render (DATABASE_URL) existe
$database_url = getenv('DATABASE_URL');

// Variável para controle de ambiente
$is_production = $database_url !== false;

if ($database_url) {
    // --- AMBIENTE DE PRODUÇÃO (HEROKU/RENDER) ---
    $db_parts = parse_url($database_url);

    $host   = $db_parts['host'];
    $port   = $db_parts['port'] ?? '5432';
    $user   = $db_parts['user'];
    $pass   = $db_parts['pass'];
    $dbname = ltrim($db_parts['path'], '/');

} else {
    // --- AMBIENTE DE DESENVOLVIMENTO (LOCAL) ---
    $host   = 'localhost';
    $port   = '5432';
    $user   = 'postgres';
    $pass   = 'sua_senha_local'; // <-- Troque pela sua senha do Postgres local
    $dbname = 'area_membros_db';
}


// -- STRING DE CONEXÃO PDO (UNIFICADA) --
$dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

try {
    // Cria a conexão PDO
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // ⭐ PASSO 1: CHAMADA AUTOMÁTICA DA MIGRAÇÃO
    // Usa __DIR__ para garantir o caminho absoluto
    if (file_exists(__DIR__ . '/migrations.php')) {
        require_once __DIR__ . '/migrations.php';
        run_migrations($pdo);
    }

    // ⭐ PASSO 2: CARREGAR AS CONFIGURAÇÕES DO BANCO (LOAD_SETTINGS)
    // Usa __DIR__ para garantir o caminho absoluto
    if (file_exists(__DIR__ . '/load_settings.php')) {
        require_once __DIR__ . '/load_settings.php';
    }


} catch (PDOException $e) {
    // Em caso de erro de conexão, exibe detalhes apenas no ambiente local
    if (!$is_production) {
        die("Erro fatal: Não foi possível conectar ao banco de dados. Detalhe: " . $e->getMessage());
    }
    // Em produção, registra o erro e exibe uma mensagem genérica
    error_log("DB Connection Failed: " . $e->getMessage());
    die("Erro fatal: Serviço de banco de dados indisponível.");
}


// -- FUNÇÕES GLOBAIS --
/**
 * Função para verificar se o usuário está logado e se tem o nível de acesso necessário.
 * Redireciona para o login se não estiver autorizado.
 * @param string|null $nivel_necessario 'membro' ou 'admin'. Se for nulo, apenas verifica se está logado.
 */
function verificarAcesso($nivel_necessario = null) {
    // Define o destino do login com base no nível de acesso da página protegida
    if ($nivel_necessario === 'admin') {
        $login_url = '/paineladmin/login.php';
    } else {
        $login_url = '/paginamembros/login.php';
    }

    // Se a sessão do usuário não existe, redireciona para o login correspondente.
    if (!isset($_SESSION['usuario_id'])) {
        header("Location: $login_url?erro=restrito");
        exit();
    }

    // --- NOVA VERIFICAÇÃO DE STATUS ---
    // Verifica se o status do usuário na sessão não é 'ativo'.
    if (isset($_SESSION['usuario_status']) && $_SESSION['usuario_status'] !== 'ativo') {
        // Limpa todas as variáveis de sessão
        session_unset();
        // Destroi a sessão
        session_destroy();
        // Redireciona para a página de login com uma mensagem de que a conta foi bloqueada
        header("Location: $login_url?erro=bloqueado");
        exit();
    }

    // Se um nível de acesso específico é requerido, verifica a permissão.
    if ($nivel_necessario) {
        if (!isset($_SESSION['usuario_nivel_acesso']) || $_SESSION['usuario_nivel_acesso'] !== $nivel_necessario) {
            // Se não tem a permissão, redireciona com mensagem de erro.
            header("Location: $login_url?erro=permissao");
            exit();
        }
    }
}
?>