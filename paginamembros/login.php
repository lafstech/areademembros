<?php
// login.php - Versão Completa com Login, Registro e Reset de Senha Robusto

// --- LÓGICA PHP (BACKEND) ---
require_once '../config.php';
require_once '../funcoes.php'; // Incluindo funcoes.php para envio de email

// Detecta qual view exibir
$view = $_GET['view'] ?? 'login';
$allowed_views = ['login', 'register', 'forgot_password', 'reset_password'];
if (!in_array($view, $allowed_views)) {
    $view = 'login';
}

// Mensagens de feedback
$feedback_message = '';
$feedback_type = ''; // success, error, info

// Redireciona usuários já logados
if (isset($_SESSION['usuario_id'])) {
    if (($_SESSION['usuario_nivel_acesso'] ?? '') === 'admin') {
        header('Location: ../paineladmin/index.php');
    } else {
        header('Location: index.php'); // Assume membro como padrão
    }
    exit();
}


// --- Processamento de Formulários ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    // --- AÇÃO: REGISTRO ---
    if ($action === 'register') {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';
        if (empty($nome) || empty($email) || empty($senha)) {
            $feedback_message = "Por favor, preencha todos os campos."; $feedback_type = 'error'; $view = 'register';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $feedback_message = "Formato de e-mail inválido."; $feedback_type = 'error'; $view = 'register';
        } elseif (strlen($senha) < 6) { // Adiciona validação de tamanho de senha no registro
            $feedback_message = "A senha deve ter pelo menos 6 caracteres."; $feedback_type = 'error'; $view = 'register';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->rowCount() > 0) {
                $feedback_message = "Este e-mail já está cadastrado."; $feedback_type = 'error'; $view = 'register';
            } else {
                $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
                $sql = "INSERT INTO usuarios (nome, email, senha, nivel_acesso, status) VALUES (?, ?, ?, 'membro', 'ativo')";
                $pdo->prepare($sql)->execute([$nome, $email, $senhaHash]);
                header('Location: login.php?status=register_success'); exit();
            }
        }
    }
    // --- AÇÃO: LOGIN ---
    elseif ($action === 'login') {
        $email = trim($_POST['email']); $senha = $_POST['senha'];
        if (empty($email) || empty($senha)) {
            $feedback_message = "Por favor, preencha e-mail e senha."; $feedback_type = 'error';
        } else {
            $sql = "SELECT id, nome, email, senha, nivel_acesso, status FROM usuarios WHERE email = ?";
            $stmt = $pdo->prepare($sql); $stmt->execute([$email]); $usuario = $stmt->fetch();
            if ($usuario && password_verify($senha, $usuario['senha'])) {
                if ($usuario['status'] === 'bloqueado') {
                    header('Location: login.php?erro=bloqueado'); exit();
                }
                session_regenerate_id(true);
                $_SESSION['usuario_id'] = $usuario['id']; $_SESSION['usuario_nome'] = $usuario['nome'];
                $_SESSION['usuario_nivel_acesso'] = $usuario['nivel_acesso']; $_SESSION['usuario_status'] = $usuario['status'];
                if ($usuario['nivel_acesso'] === 'admin') { header('Location: ../paineladmin/index.php'); } else { header('Location: index.php'); }
                exit();
            } else {
                $feedback_message = "As credenciais fornecidas são inválidas."; $feedback_type = 'error';
            }
        }
    }
    // --- AÇÃO: SOLICITAR CÓDIGO DE RESET ---
    elseif ($action === 'request_reset') {
        $view = 'forgot_password';
        $email_digitado = trim($_POST['reset_email'] ?? '');

        if (!filter_var($email_digitado, FILTER_VALIDATE_EMAIL)) {
            $feedback_message = "Por favor, insira um endereço de e-mail válido.";
            $feedback_type = 'error';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, nome, reset_token, reset_expira_em FROM usuarios WHERE email = ?");
                $stmt->execute([$email_digitado]);
                $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

                // ⭐ ROBUSTEZ: Verifica se o usuário FOI encontrado
                if ($usuario) {
                    $expiracao = $usuario['reset_expira_em'] ? strtotime($usuario['reset_expira_em']) : 0;
                    if ($usuario['reset_token'] && $expiracao > time() + 300) { // Ainda válido por 5+ min
                         header('Location: login.php?view=reset_password&email='.urlencode($email_digitado).'&status=info&message=' . urlencode("Um código já foi enviado recentemente para {$email_digitado}. Verifique sua caixa de entrada e spam."));
                         exit();
                    }

                    $token = strtoupper(bin2hex(random_bytes(3)));
                    $expira_em = date('Y-m-d H:i:s', time() + 3600); // 1 hora

                    $stmt_update = $pdo->prepare("UPDATE usuarios SET reset_token = ?, reset_expira_em = ? WHERE id = ?");
                    $stmt_update->execute([$token, $expira_em, $usuario['id']]);

                    $nome_usuario_email = $usuario['nome'];
                    // Corpo do E-mail (Reutilizado e melhorado)
                    $html_body = "
                        <!DOCTYPE html><html lang='pt-BR'><head><meta charset='UTF-8'><title>Redefinição de Senha</title><style>body{font-family: 'Poppins', sans-serif; background-color: #f4f4f4;} .container{max-width: 600px; margin: 20px auto; background-color: #1f2937; border-radius: 8px; color: #f9fafb;} .header{background-color: #00aaff; color: #fff; padding: 20px; text-align: center; border-top-left-radius: 8px; border-top-right-radius: 8px;} .content{padding: 30px;} .code-box{background-color: #111827; color: #22c55e; font-size: 28px; font-weight: 700; text-align: center; padding: 15px; margin: 25px 0; border-radius: 6px; border: 1px dashed #00aaff; letter-spacing: 5px;} .footer{padding: 20px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #374151;}</style></head>
                        <body><div class='container'><div class='header'><h1>LAFS TECH - Redefinição de Senha</h1></div><div class='content'>
                        <p>Olá, {$nome_usuario_email},</p>
                        <p>Recebemos uma solicitação para redefinir a senha da sua conta. Utilize o código de segurança abaixo:</p>
                        <div class='code-box'><strong>{$token}</strong></div>
                        <p>Este código é válido por 1 hora. Se você não solicitou esta alteração, ignore este e-mail.</p>
                        </div><div class='footer'>E-mail automático. Não responda.</div></div></body></html>";

                    $email_enviado = enviarEmailMailgun($email_digitado, "LAFS TECH - Seu código de redefinição de senha", $html_body); // Use sua função

                    if ($email_enviado) {
                        header('Location: login.php?view=reset_password&email='.urlencode($email_digitado).'&status=success&message=' . urlencode("Código enviado para {$email_digitado}! Verifique sua caixa de entrada e spam."));
                        exit();
                    } else {
                        throw new Exception("Falha ao enviar o e-mail de redefinição. Tente novamente mais tarde ou contate o suporte.");
                    }
                } else {
                    // ⭐ ROBUSTEZ: E-mail NÃO encontrado, informa o usuário explicitamente.
                    $feedback_message = "O e-mail informado (".htmlspecialchars($email_digitado).") não foi encontrado em nosso sistema.";
                    $feedback_type = 'error';
                    // Mantém na mesma view ('forgot_password')
                }
            } catch (Exception $e) {
                $feedback_message = "Erro ao solicitar código: " . $e->getMessage();
                $feedback_type = 'error';
            }
        }
    }
    // --- AÇÃO: REDEFINIR SENHA COM CÓDIGO ---
    elseif ($action === 'reset_password') {
        $view = 'reset_password';
        $email_digitado = trim($_POST['reset_form_email'] ?? '');
        $token_digitado = strtoupper(trim($_POST['token_digitado'] ?? ''));
        $nova_senha = $_POST['nova_senha_token'] ?? '';
        $confirma_nova_senha = $_POST['confirma_nova_senha_token'] ?? '';

        try {
            if (empty($email_digitado) || empty($token_digitado) || empty($nova_senha) || empty($confirma_nova_senha)) { throw new Exception("Todos os campos são obrigatórios."); }
            if ($nova_senha !== $confirma_nova_senha) { throw new Exception("As novas senhas não coincidem."); }
            if (strlen($nova_senha) < 6) { throw new Exception("A nova senha deve ter pelo menos 6 caracteres."); }

            $stmt = $pdo->prepare("SELECT id, senha, reset_expira_em FROM usuarios WHERE email = ? AND reset_token = ?");
            $stmt->execute([$email_digitado, $token_digitado]);
            $dados_token = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$dados_token) { throw new Exception("Código inválido ou e-mail incorreto."); }

            $expiracao = strtotime($dados_token['reset_expira_em']);
            if ($expiracao < time()) {
                $pdo->prepare("UPDATE usuarios SET reset_token = NULL, reset_expira_em = NULL WHERE id = ?")->execute([$dados_token['id']]);
                throw new Exception("Código expirado. Por favor, solicite um novo.");
            }

            // ⭐ ROBUSTEZ: Verifica se a nova senha é diferente da atual
            $senha_atual_hash = $dados_token['senha'];
            if ($senha_atual_hash && password_verify($nova_senha, $senha_atual_hash)) {
                throw new Exception("A nova senha não pode ser igual à senha anterior.");
            }

            $nova_senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            $stmt_update = $pdo->prepare("UPDATE usuarios SET senha = ?, reset_token = NULL, reset_expira_em = NULL WHERE id = ?");
            $stmt_update->execute([$nova_senha_hash, $dados_token['id']]);

            // ⭐ ROBUSTEZ: REGISTRO NO HISTÓRICO
            $ip_usuario = $_SERVER['REMOTE_ADDR'] ?? null;
            $metodo_usado = 'RESET_EXTERNO'; // Define o método usado
            try { // Envolve em try-catch caso a tabela/coluna não exista ainda
                 $stmt_hist = $pdo->prepare("INSERT INTO historico_senhas (usuario_id, metodo, ip_origem) VALUES (?, ?, ?)");
                 $stmt_hist->execute([$dados_token['id'], $metodo_usado, $ip_usuario]);
            } catch (PDOException $hist_e) {
                 error_log("Erro ao registrar histórico de senha (reset externo): " . $hist_e->getMessage());
                 // Não interrompe o fluxo principal se o histórico falhar
            }
            // ------------------------------------

            header('Location: login.php?status=reset_success'); exit();

        } catch (Exception $e) {
            $feedback_message = "Erro na redefinição: " . $e->getMessage();
            $feedback_type = 'error';
            $_GET['email'] = $email_digitado; // Mantém o e-mail na URL
        }
    }
}

// --- Tratamento de Mensagens via GET ---
if (isset($_GET['status']) && isset($_GET['message'])) {
    $feedback_message = htmlspecialchars(urldecode($_GET['message']));
    $feedback_type = htmlspecialchars($_GET['status']);
}
if (isset($_GET['status']) && $_GET['status'] === 'register_success') {
    $feedback_message = "Conta criada com sucesso! Faça login para continuar."; $feedback_type = 'success'; $view = 'login';
}
if (isset($_GET['status']) && $_GET['status'] === 'reset_success') {
    $feedback_message = "Senha redefinida com sucesso! Faça login com sua nova senha."; $feedback_type = 'success'; $view = 'login';
}
$erro_bloqueado = (isset($_GET['erro']) && $_GET['erro'] === 'bloqueado');

// --- SVGs para o JavaScript ---
function get_svg_eye_closed() { return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"></path></svg>'; }
function get_svg_eye_open() { return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"></path></svg>'; }
$svg_oculto = get_svg_eye_closed();
$svg_visivel = get_svg_eye_open();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php
        switch ($view) {
            case 'register': echo 'Criar Conta'; break;
            case 'forgot_password': echo 'Recuperar Senha'; break;
            case 'reset_password': echo 'Redefinir Senha'; break;
            default: echo 'Acessar Plataforma'; break;
        }
    ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/x-icon" href="/favicon1.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        /* --- CSS COMPLETO (Incluindo estilos para feedback e links extras) --- */
        :root {
            --primary-color: #00aaff; --background-color: #111827; --glass-background: rgba(31, 41, 55, 0.7);
            --text-color: #f9fafb; --input-border: rgba(255, 255, 255, 0.2); --success-color: #22c55e;
            --icon-color: #9ca3af; --yellow-color: #f59e0b; --error-color: #ef4444; --info-color: #3b82f6;
            --text-muted: #9ca3af;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--background-color); color: var(--text-color); display: flex; justify-content: center; align-items: center; min-height: 100vh; overflow: hidden; }
        #particles-js { position: absolute; width: 100%; height: 100%; top: 0; left: 0; z-index: 0; }
        .login-container { position: relative; z-index: 1; width: 100%; max-width: 420px; padding: 3rem 2.5rem; background: var(--glass-background); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); text-align: center; margin: 1rem; }
        .form-hidden { display: none; }
        .login-container h1 { font-weight: 600; font-size: 2rem; margin-bottom: 1rem; }
        .login-container p { font-weight: 300; margin-bottom: 2.5rem; color: rgba(255, 255, 255, 0.7); line-height: 1.6; }
        .input-group { position: relative; margin-bottom: 2rem; display: flex; align-items: center; border-bottom: 1px solid var(--input-border); }
        .input-field { width: 100%; background: transparent; border: none; padding: 10px 5px; font-size: 1rem; color: var(--text-color); outline: none; z-index: 1; }
        .input-label { position: absolute; top: 10px; left: 5px; font-size: 1rem; color: rgba(255, 255, 255, 0.5); pointer-events: none; transition: all 0.3s ease; z-index: 0; }
        .input-field:focus + .input-label, .input-field:valid + .input-label { top: -12px; font-size: 0.8rem; color: var(--primary-color); }
        .toggle-password { background: none; border: none; color: var(--icon-color); cursor: pointer; padding: 0 5px; z-index: 2; transition: color 0.3s; line-height: 1; margin-left: auto; }
        .toggle-password:hover { color: var(--primary-color); }
        .toggle-password svg { width: 20px; height: 20px; display: block; }
        .login-button { width: 100%; padding: 1rem; background: var(--primary-color); border: none; border-radius: 8px; color: #fff; font-size: 1.1rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease; margin-top: 1rem; }
        .login-button:hover { box-shadow: 0 0 20px rgba(0, 170, 255, 0.5); transform: translateY(-2px); }
        .switch-form { margin-top: 1.5rem; font-size: 0.9rem; color: var(--text-muted); }
        .switch-form a { color: var(--primary-color); text-decoration: none; font-weight: 600; transition: color 0.3s; }
        .switch-form a:hover { color: #fff; }
        .extra-links { text-align: right; margin-top: -1rem; margin-bottom: 1.5rem; }
        .extra-links a { color: var(--text-muted); font-size: 0.85rem; text-decoration: none; transition: color 0.3s; }
        .extra-links a:hover { color: var(--primary-color); }
        #feedback-container { width: 100%; margin-bottom: 1.5rem; }
        .feedback-message { color: var(--text-color); background-color: rgba(255, 255, 255, 0.05); border: 1px solid var(--border-color); padding: 10px 15px; border-radius: 8px; font-size: 0.9rem; text-align: left; }
        .feedback-message.success { background-color: rgba(34, 197, 94, 0.15); border-color: rgba(34, 197, 94, 0.4); color: var(--success-color); }
        .feedback-message.error { background-color: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.4); color: var(--error-color); }
        .feedback-message.info { background-color: rgba(59, 130, 246, 0.15); border-color: rgba(59, 130, 246, 0.4); color: var(--info-color); }

        /* Estilos dos Modais */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(5px); z-index: 100; display: none; justify-content: center; align-items: center; animation: fadeIn 0.3s ease; }
        .modal-content { background: #1f2937; padding: 2rem; border-radius: 12px; width: 90%; max-width: 450px; text-align: center; border: 1px solid rgba(255, 255, 255, 0.1); transform: scale(0.9); animation: slideIn 0.4s ease forwards; }
        .modal-content h2 { margin-bottom: 1rem; }
        .modal-content .icon { margin-bottom: 1rem; }
        .modal-content .icon svg { width: 48px; height: 48px; }
        .modal-content p { margin-bottom: 1.5rem; line-height: 1.6; color: rgba(255, 255, 255, 0.8); }
        .modal-buttons { display: flex; gap: 1rem; justify-content: center; }
        .modal-buttons button { padding: 0.75rem 1.5rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; }
        .modal-btn-primary { background-color: var(--primary-color); color: #fff; }
        .modal-btn-secondary { background-color: transparent; color: rgba(255, 255, 255, 0.6); border: 1px solid rgba(255, 255, 255, 0.2); }
        .modal-btn-secondary:hover { background-color: var(--glass-background); color: #fff; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideIn { from { opacity: 0; transform: scale(0.9) translateY(20px); } to { opacity: 1; transform: scale(1) translateY(0); } }
    </style>
</head>
<body>
    <div id="particles-js"></div>

    <div class="login-container">

        <div id="feedback-container">
            <?php if ($feedback_message): ?>
                <div class="feedback-message <?php echo htmlspecialchars($feedback_type); ?>">
                    <?php echo $feedback_message; // Mensagem já está segura ou foi sanitizada via htmlspecialchars onde necessário ?>
                </div>
            <?php endif; ?>
        </div>

        <form action="login.php?view=login" method="POST" id="login-form" class="<?php echo ($view !== 'login') ? 'form-hidden' : ''; ?>">
            <h1>Bem-Vindo(a) de Volta</h1>
            <p>Acesse sua conta para continuar.</p>
            <input type="hidden" name="action" value="login">
            <div class="input-group">
                <input type="email" name="email" id="login-email" class="input-field" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                <label for="login-email" class="input-label">Seu e-mail</label>
            </div>
            <div class="input-group">
                <input type="password" name="senha" id="login-senha" class="input-field" required>
                <label for="login-senha" class="input-label">Sua senha</label>
                <button type="button" class="toggle-password" data-target="login-senha" title="Mostrar Senha"><?php echo $svg_oculto; ?></button>
            </div>
            <div class="extra-links">
                 <a href="login.php?view=forgot_password">Esqueceu a senha?</a>
            </div>
            <button type="submit" class="login-button">Acessar Plataforma</button>
            <div class="switch-form">
                Não tem conta? <a href="login.php?view=register">Crie uma aqui</a>
            </div>
        </form>

        <form action="login.php?view=register" method="POST" id="register-form" class="<?php echo ($view !== 'register') ? 'form-hidden' : ''; ?>">
            <h1>Crie Sua Conta</h1>
            <p>Comece sua jornada hoje.</p>
            <input type="hidden" name="action" value="register">
            <div class="input-group">
                <input type="text" name="nome" id="register-nome" class="input-field" required value="<?php echo htmlspecialchars($_POST['nome'] ?? ''); ?>">
                <label for="register-nome" class="input-label">Seu Nome Completo</label>
            </div>
            <div class="input-group">
                <input type="email" name="email" id="register-email" class="input-field" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                <label for="register-email" class="input-label">Seu e-mail</label>
            </div>
            <div class="input-group">
                <input type="password" name="senha" id="register-senha" class="input-field" required minlength="6">
                <label for="register-senha" class="input-label">Crie uma Senha (mín. 6 caracteres)</label>
                <button type="button" class="toggle-password" data-target="register-senha" title="Mostrar Senha"><?php echo $svg_oculto; ?></button>
            </div>
            <button type="submit" class="login-button" style="background-color: var(--success-color);">Registrar</button>
            <div class="switch-form">
                Já tem conta? <a href="login.php?view=login">Acesse agora</a>
            </div>
        </form>

        <form action="login.php?view=forgot_password" method="POST" id="forgot-form" class="<?php echo ($view !== 'forgot_password') ? 'form-hidden' : ''; ?>">
            <h1>Recuperar Senha</h1>
            <p>Insira seu e-mail cadastrado para enviarmos um código de redefinição.</p>
            <input type="hidden" name="action" value="request_reset">
            <div class="input-group">
                <input type="email" name="reset_email" id="reset-email" class="input-field" required value="<?php echo htmlspecialchars($_GET['email'] ?? ($_POST['reset_email'] ?? '')); // Mantém o e-mail preenchido ?>">
                <label for="reset-email" class="input-label">Seu e-mail cadastrado</label>
            </div>
            <button type="submit" class="login-button">Enviar Código</button>
            <div class="switch-form">
                Lembrou a senha? <a href="login.php?view=login">Voltar para o Login</a>
            </div>
        </form>

        <form action="login.php?view=reset_password" method="POST" id="reset-form" class="<?php echo ($view !== 'reset_password') ? 'form-hidden' : ''; ?>">
            <h1>Definir Nova Senha</h1>
            <p>Insira o código enviado para <strong><?php echo htmlspecialchars($_GET['email'] ?? 'seu e-mail'); ?></strong> e defina sua nova senha.</p>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="reset_form_email" value="<?php echo htmlspecialchars($_GET['email'] ?? ''); ?>">

            <div class="input-group">
                <input type="text" name="token_digitado" id="token_digitado" class="input-field" required maxlength="6" style="text-transform: uppercase;" autocomplete="off">
                <label for="token_digitado" class="input-label">Código recebido (6 caracteres)</label>
            </div>
            <div class="input-group">
                <input type="password" name="nova_senha_token" id="nova_senha_token" class="input-field" required minlength="6">
                <label for="nova_senha_token" class="input-label">Nova Senha (mín. 6 caracteres)</label>
                <button type="button" class="toggle-password" data-target="nova_senha_token" title="Mostrar Senha"><?php echo $svg_oculto; ?></button>
            </div>
            <div class="input-group">
                <input type="password" name="confirma_nova_senha_token" id="confirma_nova_senha_token" class="input-field" required minlength="6">
                <label for="confirma_nova_senha_token" class="input-label">Confirmar Nova Senha</label>
                <button type="button" class="toggle-password" data-target="confirma_nova_senha_token" title="Mostrar Senha"><?php echo $svg_oculto; ?></button>
            </div>
            <button type="submit" class="login-button">Redefinir Senha</button>
            <div class="switch-form">
                <a href="login.php?view=login">Voltar para o Login</a> | <a href="login.php?view=forgot_password&email=<?php echo urlencode($_GET['email'] ?? ''); ?>">Reenviar código</a>
            </div>
        </form>

    </div>

    <div class="modal-overlay" id="welcome-modal" style="display: none;">
        <div class="modal-content">
             <h2>Olá, Explorador(a) do Conhecimento!</h2>
             <p>Este é o seu portal de acesso a um universo de cursos e projetos. Faça o login para começar a transformar suas ideias em realidade.</p>
             <div class="modal-buttons">
                 <button id="dont-show-again-btn" class="modal-btn-secondary">Não mostrar novamente</button>
                 <button id="close-modal-btn" class="modal-btn-primary">Entendi!</button>
             </div>
         </div>
     </div>
     <div class="modal-overlay" id="blocked-modal" style="display: none;">
         <div class="modal-content">
             <div class="icon" style="color: var(--yellow-color);"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="48" height="48"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg></div>
             <h2 style="color: var(--yellow-color);">Acesso Bloqueado</h2>
             <p>Sua conta foi temporariamente bloqueada. Por favor, entre em contato com o administrador do sistema para mais informações.</p>
             <div class="modal-buttons"><button id="close-blocked-modal-btn" class="modal-btn-primary">Entendi</button></div>
         </div>
     </div>

    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script>
        // --- VARIÁVEIS PHP PARA JS ---
        const svgOculto = `<?php echo addslashes($svg_oculto); ?>`;
        const svgVisivel = `<?php echo addslashes($svg_visivel); ?>`;
        const currentView = '<?php echo $view; ?>'; // Passa a view atual para o JS

        // --- FUNCIONALIDADE MOSTRAR SENHA ---
        document.querySelectorAll('.toggle-password').forEach(button => {
            button.addEventListener('click', () => {
                const targetId = button.dataset.target;
                const input = document.getElementById(targetId);
                if (!input) return; // Segurança caso o elemento não exista
                if (input.type === 'password') {
                    input.type = 'text'; button.title = 'Ocultar Senha'; button.innerHTML = svgVisivel;
                } else {
                    input.type = 'password'; button.title = 'Mostrar Senha'; button.innerHTML = svgOculto;
                }
            });
        });

        // --- LÓGICA DAS PARTÍCULAS ---
        particlesJS('particles-js', {
             "particles": { "number": { "value": 80, "density": { "enable": true, "value_area": 800 } }, "color": { "value": "#00aaff" }, "shape": { "type": "circle" }, "opacity": { "value": 0.5, "random": false }, "size": { "value": 3, "random": true }, "line_linked": { "enable": true, "distance": 150, "color": "#ffffff", "opacity": 0.1, "width": 1 }, "move": { "enable": true, "speed": 2, "direction": "none", "random": false, "straight": false, "out_mode": "out", "bounce": false } },
             "interactivity": { "detect_on": "canvas", "events": { "onhover": { "enable": true, "mode": "repulse" }, "onclick": { "enable": true, "mode": "push" }, "resize": true }, "modes": { "repulse": { "distance": 100, "duration": 0.4 }, "push": { "particles_nb": 4 } } },
             "retina_detect": true
        });

        document.addEventListener('DOMContentLoaded', () => {
            // --- LÓGICA PARA EXIBIR O POP-UP DE BLOQUEIO ---
            const isBlocked = <?php echo json_encode($erro_bloqueado); ?>;
            const blockedModal = document.getElementById('blocked-modal');
            const closeBlockedModalBtn = document.getElementById('close-blocked-modal-btn');

            if (isBlocked && blockedModal) {
                blockedModal.style.display = 'flex';
            }
            if (closeBlockedModalBtn && blockedModal) {
                closeBlockedModalBtn.addEventListener('click', () => {
                    blockedModal.style.display = 'none';
                    // Limpa erro da URL e volta para view de login
                    window.history.replaceState({}, document.title, window.location.pathname + '?view=login');
                    // Mostra o formulário de login
                    document.getElementById('login-form').classList.remove('form-hidden');
                    document.getElementById('register-form').classList.add('form-hidden');
                    document.getElementById('forgot-form').classList.add('form-hidden');
                    document.getElementById('reset-form').classList.add('form-hidden');
                });
            }

            // --- LÓGICA MODAL DE BOAS-VINDAS ---
            const welcomeModal = document.getElementById('welcome-modal');
            const closeModalBtn = document.getElementById('close-modal-btn');
            const dontShowAgainBtn = document.getElementById('dont-show-again-btn');
            const shouldHideModal = localStorage.getItem('hideWelcomeModal');

            if (welcomeModal && !shouldHideModal && !isBlocked && currentView === 'login' && !document.querySelector('.feedback-message')) {
                welcomeModal.style.display = 'flex';
            }
            const closeWelcomeModal = () => { if(welcomeModal) welcomeModal.style.display = 'none'; };
            if(closeModalBtn) closeModalBtn.addEventListener('click', closeWelcomeModal);
            if(dontShowAgainBtn) dontShowAgainBtn.addEventListener('click', () => {
                localStorage.setItem('hideWelcomeModal', 'true');
                closeWelcomeModal();
            });

             // Foco automático no primeiro campo do formulário ativo
            const activeForm = document.querySelector('form:not(.form-hidden)');
            if (activeForm) {
                const firstInput = activeForm.querySelector('input[type="text"], input[type="email"], input[type="tel"], input[type="password"]');
                if (firstInput) {
                    // Timeout para garantir renderização e animações
                    setTimeout(() => firstInput.focus(), 150);
                }
            }

            // Adiciona listener para links de troca de view
            document.querySelectorAll('.switch-form a, .extra-links a').forEach(link => {
                link.addEventListener('click', (e) => {
                    // Verifica se é um link interno para trocar a view
                    const urlParams = new URLSearchParams(link.search);
                    if (urlParams.has('view')) {
                         // Apenas limpa a mensagem de feedback atual, o PHP cuidará da troca de view no recarregamento
                         const feedbackDiv = document.getElementById('feedback-container');
                         if (feedbackDiv) feedbackDiv.innerHTML = '';
                         // Não impede o comportamento padrão (e.preventDefault()), pois queremos que a página recarregue com a nova view.
                    }
                });
            });
        });
    </script>
</body>
</html>