<?php
// membros/resetpassword.php

// --- LÓGICA PHP (BACKEND) ---
require_once '../config.php';
require_once '../funcoes.php';
verificarAcesso('membro');

$nome_usuario_logado = htmlspecialchars($_SESSION['usuario_nome']);
$usuario_id = $_SESSION['usuario_id'];

$feedback_message = '';
$feedback_type = '';
$exibir_form_token = false;
$tab_ativa = $_GET['tab'] ?? 'troca_rapida';
$ip_usuario = $_SERVER['REMOTE_ADDR'] ?? null; // Captura o IP do usuário para o histórico

$stmt = $pdo->prepare("SELECT senha, email, reset_token, reset_expira_em FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$usuario_dados = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario_dados) {
    header('Location: ../logout.php');
    exit();
}

$usuario_email = $usuario_dados['email'];

if (isset($_GET['show_token']) && $_GET['show_token'] === 'true') {
    $exibir_form_token = true;
    $tab_ativa = 'redefinicao_codigo';
}

// --- BUSCA DO HISTÓRICO DE SENHAS ---
try {
    $stmt_historico = $pdo->prepare("SELECT data_alteracao, metodo, ip_origem FROM historico_senhas WHERE usuario_id = ? ORDER BY data_alteracao DESC LIMIT 10");
    $stmt_historico->execute([$usuario_id]);
    $historico_senhas = $stmt_historico->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $historico_senhas = [];
    error_log("Erro ao buscar histórico de senhas: " . $e->getMessage());
}
// ------------------------------------


// --- Processamento de Formulários ---
if (isset($_POST['action']) && $_POST['action'] === 'trocar_senha_logado') {
    $tab_ativa = 'troca_rapida';
    $senha_atual_digitada = $_POST['senha_atual'] ?? '';
    $nova_senha = $_POST['nova_senha'] ?? '';
    $confirma_nova_senha = $_POST['confirma_nova_senha'] ?? '';

    try {
        if (empty($senha_atual_digitada) || empty($nova_senha) || empty($confirma_nova_senha)) {
            throw new Exception("Todos os campos são obrigatórios.");
        }
        if ($nova_senha !== $confirma_nova_senha) {
            throw new Exception("A nova senha e a confirmação não coincidem.");
        }
        if (strlen($nova_senha) < 6) {
            throw new Exception("A nova senha deve ter pelo menos 6 caracteres.");
        }
        if ($nova_senha === $senha_atual_digitada) {
             throw new Exception("A nova senha deve ser diferente da atual.");
        }

        if (!password_verify($senha_atual_digitada, $usuario_dados['senha'])) {
            header('Location: resetpassword.php?status=error&message=' . urlencode("Senha atual incorreta. Redirecionando para redefinição por código.") . '&show_token=true');
            exit();
        }

        $nova_senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE usuarios SET senha = ?, reset_token = NULL, reset_expira_em = NULL WHERE id = ?");
        $stmt->execute([$nova_senha_hash, $usuario_id]);

        // --- REGISTRO NO HISTÓRICO (Troca Rápida) ---
        $metodo_usado = 'TROCA_RAPIDA';
        $stmt_hist = $pdo->prepare("INSERT INTO historico_senhas (usuario_id, metodo, ip_origem) VALUES (?, ?, ?)");
        $stmt_hist->execute([$usuario_id, $metodo_usado, $ip_usuario]);
        // ------------------------------------------

        $feedback_message = "Sua senha foi alterada com sucesso!";
        $feedback_type = 'success';

    } catch (Exception $e) {
        $feedback_message = "Erro ao trocar a senha: " . $e->getMessage();
        $feedback_type = 'error';
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'solicitar_token') {
    $tab_ativa = 'redefinicao_codigo';

    try {
        $expiracao = $usuario_dados['reset_expira_em'] ? strtotime($usuario_dados['reset_expira_em']) : 0;
        // Permite reenviar se o token for muito novo (menos de 1 segundo) ou se não houver
        if ($usuario_dados['reset_token'] && $expiracao > time() && $expiracao < time() + 3500) {
            $feedback_message = "Você já solicitou um código recentemente. Verifique seu e-mail **{$usuario_email}**.";
            $feedback_type = 'info';
        } else {
            $token = strtoupper(bin2hex(random_bytes(3)));
            $expira_em = date('Y-m-d H:i:s', time() + 3600);

            $stmt = $pdo->prepare("UPDATE usuarios SET reset_token = ?, reset_expira_em = ? WHERE id = ?");
            $stmt->execute([$token, $expira_em, $usuario_id]);

            // --- CORPO DO E-MAIL ---
            $html_body = "
                <!DOCTYPE html>
                <html lang='pt-BR'>
                <head>
                    <meta charset='UTF-8'>
                    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                    <style>
                        body { font-family: 'Poppins', Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
                        .container { max-width: 600px; margin: 20px auto; background-color: #1f2937; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); }
                        .header { background-color: #00aaff; color: #ffffff; padding: 20px 30px; text-align: center; }
                        .content { padding: 30px; color: #f9fafb; line-height: 1.6; }
                        .code-box {
                            background-color: #111827;
                            color: #22c55e;
                            font-size: 28px;
                            font-weight: 700;
                            text-align: center;
                            padding: 15px;
                            margin: 25px 0;
                            border-radius: 6px;
                            border: 1px dashed #00aaff;
                            letter-spacing: 5px;
                        }
                        .footer { padding: 20px 30px; border-top: 1px solid #374151; text-align: center; font-size: 12px; color: #9ca3af; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h1 style='margin: 0; font-size: 24px;'>LAFS TECH - Alteração de Senha</h1>
                        </div>
                        <div class='content'>
                            <p style='margin-bottom: 20px;'>Olá, {$nome_usuario_logado},</p>
                            <p>Recebemos uma solicitação para alterar a senha da sua conta. Utilize o código de segurança abaixo na tela de redefinição:</p>
                            <div class='code-box'>
                                <strong>{$token}</strong>
                            </div>
                            <p style='margin-top: 20px;'>ATENÇÃO: Este código é válido por apenas 1 hora. Se você não solicitou esta alteração, por favor, ignore este e-mail.</p>
                        </div>
                        <div class='footer'>
                            Este é um e-mail automático. Por favor, não responda. <br>
                            Segurança LAFS TECH.
                        </div>
                    </div>
                </body>
                </html>
            ";
            // --- FIM CORPO DO E-MAIL ---


            $email_enviado = enviarEmailMailgun($usuario_email, "Seu código de redefinição de senha", $html_body);

            if ($email_enviado) {
                header('Location: resetpassword.php?status=success&message=' . urlencode("O código de redefinição foi enviado para o seu e-mail.") . '&show_token=true');
                exit();
            } else {
                throw new Exception("Falha ao enviar e-mail. Por favor, verifique a configuração da Mailgun.");
            }
        }

    } catch (Exception $e) {
        $feedback_message = "Erro na solicitação de código: " . $e->getMessage();
        $feedback_type = 'error';
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'validar_token') {
    $tab_ativa = 'redefinicao_codigo';
    $token_digitado = strtoupper(trim($_POST['token_digitado'] ?? ''));
    $nova_senha = $_POST['nova_senha_token'] ?? '';
    $confirma_nova_senha = $_POST['confirma_nova_senha_token'] ?? '';

    try {
        if (empty($token_digitado) || $nova_senha !== $confirma_nova_senha || strlen($nova_senha) < 6) {
            throw new Exception("Dados inválidos. Verifique o código e as senhas.");
        }

        $stmt = $pdo->prepare("SELECT reset_expira_em FROM usuarios WHERE id = ? AND reset_token = ?");
        $stmt->execute([$usuario_id, $token_digitado]);
        $dados_token = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dados_token) {
            throw new Exception("Código inválido ou não encontrado.");
        }

        $expiracao = strtotime($dados_token['reset_expira_em']);
        if ($expiracao < time()) {
            $pdo->prepare("UPDATE usuarios SET reset_token = NULL, reset_expira_em = NULL WHERE id = ?")->execute([$usuario_id]);
            throw new Exception("Código expirado. Por favor, solicite um novo.");
        }

        $nova_senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE usuarios SET senha = ?, reset_token = NULL, reset_expira_em = NULL WHERE id = ?");
        $stmt->execute([$nova_senha_hash, $usuario_id]);

        // --- REGISTRO NO HISTÓRICO (Redefinição por Código) ---
        $metodo_usado = 'REDEFINICAO_CODIGO';
        $stmt_hist = $pdo->prepare("INSERT INTO historico_senhas (usuario_id, metodo, ip_origem) VALUES (?, ?, ?)");
        $stmt_hist->execute([$usuario_id, $metodo_usado, $ip_usuario]);
        // ----------------------------------------------------

        header('Location: resetpassword.php?status=success&message=' . urlencode("Sua senha foi redefinida com sucesso utilizando o código!") . '&tab=troca_rapida');
        exit();

    } catch (Exception $e) {
        $feedback_message = "Erro na redefinição com código: " . $e->getMessage();
        $feedback_type = 'error';
    }
}

if (isset($_GET['status']) && isset($_GET['message'])) {
    $feedback_message = htmlspecialchars($_GET['message']);
    $feedback_type = htmlspecialchars($_GET['status']);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alterar Senha - Área de Membros</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/x-icon" href="/favicon1.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        /* VARIÁVEIS E ESTILOS GLOBAIS */
        :root {
            --primary-color: #00aaff;
            --background-color: #111827;
            --sidebar-color: #1f2937;
            --glass-background: rgba(31, 41, 55, 0.5);
            --text-color: #f9fafb;
            --text-muted: #9ca3af;
            --border-color: rgba(255, 255, 255, 0.1);
            --success-color: #22c55e;
            --error-color: #ef4444;
            --info-color: #3b82f6;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--background-color);
            color: var(--text-color);
            display: flex;
            min-height: 100vh;
        }
        #particles-js { position: fixed; width: 100%; height: 100%; top: 0; left: 0; z-index: -1; }

        /* ---------------------------------------------------- */
        /* ESTILOS DA SIDEBAR (PADRÃO - DESKTOP FIRST) */
        /* ---------------------------------------------------- */
        .sidebar {
            width: 260px;
            background-color: var(--sidebar-color);
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            padding: 2rem 1.5rem;
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--border-color);
            z-index: 1000;
            transition: transform 0.3s ease;
        }
        .sidebar .logo-area { display: flex; flex-direction: column; align-items: center; margin-bottom: -1rem; padding-bottom: 1rem; }
        .sidebar .logo-circle { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 0.75rem; box-shadow: 0 0 15px rgba(0, 170, 255, 0.6); overflow: hidden; }
        .sidebar .logo-circle img { max-width: 100%; max-height: 100%; display: block; object-fit: contain; }
        .sidebar .logo-text { font-size: 1.2rem; font-weight: 600; color: var(--text-color); text-align: center; }
        .sidebar .divider { width: 100%; height: 1px; background-color: var(--border-color); margin: 1.5rem 0; }
        .sidebar nav { flex-grow: 1; } /* Mantido no desktop */
        .sidebar nav a {
            display: flex; align-items: center; gap: 1rem; padding: 1rem; color: var(--text-muted);
            text-decoration: none; border-radius: 8px; margin-bottom: 0.5rem; transition: all 0.3s ease;
            border: 1px solid transparent; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2); background-color: var(--glass-background);
        }
        .sidebar nav a:hover, .sidebar nav a.active {
            background-color: rgba(0, 170, 255, 0.2); color: var(--text-color);
            border-color: var(--primary-color); box-shadow: 0 4px 15px rgba(0, 170, 255, 0.4);
        }
        .sidebar nav a svg { width: 24px; height: 24px; }
        .user-profile { position: relative; margin-top: auto; background-color: var(--glass-background); padding: 0.75rem; border-radius: 8px; display: flex; align-items: center; gap: 1rem; cursor: pointer; border: 1px solid transparent; transition: all 0.3s ease; }
        .user-profile:hover { border-color: var(--border-color); }
        .avatar { width: 40px; height: 40px; border-radius: 50%; background-color: var(--primary-color); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 1rem; }
        .user-info .user-name { font-weight: 600; font-size: 0.9rem; line-height: 1.2; }
        .user-info .user-level { font-size: 0.75rem; color: var(--text-muted); }
        .profile-dropdown { position: absolute; bottom: calc(100% + 10px); left: 0; width: 100%; background-color: #2c3a4f; border-radius: 8px; border: 1px solid var(--border-color); padding: 0.5rem; z-index: 20; visibility: hidden; opacity: 0; transform: translateY(10px); transition: all 0.3s ease; }
        .profile-dropdown.show { visibility: visible; opacity: 1; transform: translateY(0); }
        .profile-dropdown a { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; color: var(--text-muted); text-decoration: none; font-size: 0.9rem; border-radius: 6px; }
        .profile-dropdown a:hover { background-color: var(--glass-background); color: var(--text-color); }

        /* ---------------------------------------------------- */
        /* ESTILOS DO CONTEÚDO PRINCIPAL (DESKTOP) */
        /* ---------------------------------------------------- */
        .main-content {
            margin-left: 260px;
            flex-grow: 1;
            padding: 2rem 3rem;
            min-height: 100vh;
            overflow-y: auto;
            transition: margin-left 0.3s ease;
            width: calc(100% - 260px);
            display: flex; /* Adicionado para centralizar o container */
            flex-direction: column;
            align-items: center;
        }
        .container { max-width: 700px; width: 100%; margin-top: 2rem; }
        h1 { font-size: 2.25rem; font-weight: 600; margin-bottom: 2rem; text-align: center; }

        /* Estilos de Abas e Formulário */
        .card { background: var(--sidebar-color); padding: 2rem; border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3); margin-bottom: 2rem; border: 1px solid var(--border-color); }
        .card h2 { font-size: 1.5rem; margin-bottom: 1.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color); }
        .form-group { margin-bottom: 1.5rem; position: relative; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-color); }
        .form-group input { width: 100%; padding: 0.75rem; background-color: var(--background-color); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-color); font-size: 1rem; transition: border-color 0.3s; }
        .form-group input:focus { border-color: var(--primary-color); outline: none; }
        .btn-primary { width: 100%; padding: 0.75rem; background-color: var(--primary-color); color: #fff; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: background-color 0.3s ease; }
        .btn-primary:hover { background-color: #0088cc; }
        .or-divider { text-align: center; margin: 2.5rem 0; position: relative; }
        .or-divider::before { content: ''; position: absolute; top: 50%; left: 0; right: 0; height: 1px; background-color: var(--border-color); z-index: 1; }
        .or-text { display: inline-block; background-color: var(--sidebar-color); padding: 0 1rem; position: relative; z-index: 2; color: var(--text-muted); font-weight: 500; }
        .feedback-message { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 500; border: 1px solid transparent; width: 100%; }
        .feedback-message.success { background-color: rgba(34, 197, 94, 0.1); border-color: rgba(34, 197, 94, 0.4); color: var(--success-color); }
        .feedback-message.error { background-color: rgba(225, 29, 72, 0.1); border-color: rgba(225, 29, 72, 0.4); color: var(--error-color); }
        .feedback-message.info { background-color: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.4); color: var(--info-color); }
        .tabs-nav { display: flex; margin-bottom: 2rem; background-color: rgba(0, 0, 0, 0.2); border-radius: 8px; border: 1px solid var(--border-color); overflow: hidden; }
        .tabs-nav a { flex-grow: 1; text-align: center; padding: 1rem 1.5rem; text-decoration: none; color: var(--text-muted); font-weight: 600; transition: all 0.3s ease; border-right: 1px solid var(--border-color); }
        .tabs-nav a:last-child { border-right: none; }
        .tabs-nav a.active { background-color: var(--primary-color); color: var(--text-color); }
        .tabs-content > div { display: none; }
        .tabs-content > div.active { display: block; }
        .toggle-password { position: absolute; right: 15px; top: 50%; transform: translateY(2px); cursor: pointer; padding: 5px; }
        .toggle-password svg { width: 24px; height: 24px; color: var(--text-muted); transition: color 0.2s; }
        .toggle-password:hover svg { color: var(--text-color); }
        .email-display { background-color: var(--glass-background); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid var(--border-color); }
        .email-display p { font-size: 0.9rem; color: var(--text-muted); }
        .email-display strong { color: var(--text-color); font-weight: 600; }

        /* Estilos do Histórico */
        .historico-item div {
            flex-grow: 1;
        }

        /* ---------------------------------------------------- */
        /* BOTÃO HAMBÚRGUER (MOSTRAR APENAS EM TELAS PEQUENAS) */
        /* ---------------------------------------------------- */
        .menu-toggle {
            display: none;
            position: fixed;
            top: 1.5rem;
            left: 1.5rem;
            z-index: 1001;
            cursor: pointer;
            padding: 10px;
            background-color: var(--sidebar-color);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        .menu-toggle svg {
            width: 24px;
            height: 24px;
            color: var(--text-color);
        }


        /* ---------------------------------------------------- */
        /* BREAKPOINT PARA TABLETS E MOBILE (Telas <= 1024px) */
        /* ---------------------------------------------------- */
        @media (max-width: 1024px) {
            /* 1. Sidebar Off-Canvas */
            .sidebar {
                width: 280px;
                transform: translateX(-280px);
                box-shadow: 5px 0 15px rgba(0, 0, 0, 0.5);
                z-index: 1002;
                height: 100%;
                overflow-y: auto;
            }

            /* Correção do Perfil no Mobile */
            .user-profile {
                margin-top: 1.5rem;
                position: relative;
            }

            /* 2. Menu Toggle */
            .menu-toggle {
                display: flex;
            }

            /* 3. Conteúdo Principal */
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 1.5rem;
                padding-top: 5rem;
            }
            .main-content h1 { font-size: 2rem; }

            /* 4. Estado "Menu Aberto" */
            body.sidebar-open .sidebar {
                transform: translateX(0);
            }
            body.sidebar-open::after {
                content: '';
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.7);
                z-index: 1001;
            }
        }

        /* ---------------------------------------------------- */
        /* BREAKPOINT PARA CELULARES PEQUENOS (Telas <= 576px) */
        /* ---------------------------------------------------- */
        @media (max-width: 576px) {
            .main-content {
                padding: 1rem;
                padding-top: 4.5rem;
                margin-left: 10px;
                margin-right: 10px;
                margin-top: -80px;
            }
            .main-content h1 { font-size: 1.8rem; }
            .tabs-nav a { padding: 0.75rem 0.5rem; font-size: 0.85rem; } /* Ajuste de tabs no celular */
        }
    </style>
</head>
<body>

    <div class="menu-toggle" id="menu-toggle">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
        </svg>
    </div>

    <div id="particles-js"></div>

    <?php include '_sidebar.php'; ?>

    <main class="main-content">
        <div class="container">
            <h1>Alterar Senha</h1>

            <div class="email-display">
                <p>E-mail cadastrado: <strong><?php echo htmlspecialchars($usuario_email); ?></strong> (Use este e-mail para receber o código)</p>
            </div>

            <?php if ($feedback_message): ?>
                <div class="feedback-message <?php echo $feedback_type; ?>">
                    <?php echo $feedback_message; ?>
                </div>
            <?php endif; ?>

            <div class="tabs-nav">
                <a href="?tab=troca_rapida" class="<?php echo ($tab_ativa === 'troca_rapida') ? 'active' : ''; ?>">Troca Rápida</a>
                <a href="?tab=redefinicao_codigo" class="<?php echo ($tab_ativa === 'redefinicao_codigo') ? 'active' : ''; ?>">Redefinir com Código</a>
                <a href="?tab=historico" class="<?php echo ($tab_ativa === 'historico') ? 'active' : ''; ?>">Histórico</a>
            </div>

            <div class="tabs-content">

                <div id="troca_rapida" class="card <?php echo ($tab_ativa === 'troca_rapida') ? 'active' : ''; ?>">
                    <h2>Trocar Senha (Se souber a atual)</h2>
                    <form action="resetpassword.php" method="POST">
                        <input type="hidden" name="action" value="trocar_senha_logado">

                        <div class="form-group">
                            <label for="senha_atual">Sua Senha Atual</label>
                            <input type="password" id="senha_atual" name="senha_atual" required>
                            <span class="toggle-password" data-target="senha_atual"><?php echo get_svg_eye_closed(); ?></span>
                        </div>

                        <div class="form-group">
                            <label for="nova_senha">Nova Senha</label>
                            <input type="password" id="nova_senha" name="nova_senha" required>
                            <span class="toggle-password" data-target="nova_senha"><?php echo get_svg_eye_closed(); ?></span>
                        </div>

                        <div class="form-group">
                            <label for="confirma_nova_senha">Confirmar Nova Senha</label>
                            <input type="password" id="confirma_nova_senha" name="confirma_nova_senha" required>
                            <span class="toggle-password" data-target="confirma_nova_senha"><?php echo get_svg_eye_closed(); ?></span>
                        </div>

                        <button type="submit" class="btn-primary">Atualizar Senha</button>
                    </form>

                    <div class="or-divider">
                        <span class="or-text">OU</span>
                    </div>

                    <form action="resetpassword.php" method="POST">
                        <input type="hidden" name="action" value="solicitar_token">
                        <button type="submit" class="btn-primary" style="background-color: #f59e0b;">
                            Esqueci minha senha
                        </button>
                    </form>
                </div>

                <div id="redefinicao_codigo" class="card <?php echo ($tab_ativa === 'redefinicao_codigo') ? 'active' : ''; ?>">
                    <h2>Redefinir com Código</h2>
                    <p style="color: var(--text-muted); margin-bottom: 1.5rem;">
                        Insira o código de redefinição enviado para <strong><?php echo htmlspecialchars($usuario_email); ?></strong> e defina sua nova senha.
                    </p>

                    <form action="resetpassword.php" method="POST">
                        <input type="hidden" name="action" value="validar_token">

                        <div class="form-group">
                            <label for="token_digitado">Código de Redefinição Recebido</label>
                            <input type="text" id="token_digitado" name="token_digitado" required autocomplete="off" maxlength="6" placeholder="Ex: A1B2C3" style="text-transform: uppercase;">
                        </div>

                        <div class="form-group">
                            <label for="nova_senha_token">Nova Senha</label>
                            <input type="password" id="nova_senha_token" name="nova_senha_token" required>
                            <span class="toggle-password" data-target="nova_senha_token"><?php echo get_svg_eye_closed(); ?></span>
                        </div>

                        <div class="form-group">
                            <label for="confirma_nova_senha_token">Confirmar Nova Senha</label>
                            <input type="password" id="confirma_nova_senha_token" name="confirma_nova_senha_token" required>
                            <span class="toggle-password" data-target="confirma_nova_senha_token"><?php echo get_svg_eye_closed(); ?></span>
                        </div>

                        <button type="submit" class="btn-primary">Redefinir Senha</button>
                    </form>

                    <div class="or-divider">
                        <span class="or-text">OU</span>
                    </div>

                    <form action="resetpassword.php" method="POST">
                        <input type="hidden" name="action" value="solicitar_token">
                        <button type="submit" class="btn-primary" style="background-color: #555;">
                            Reenviar código para <?php echo htmlspecialchars($usuario_email); ?>
                        </button>
                    </form>
                </div>

                <div id="historico" class="card <?php echo ($tab_ativa === 'historico') ? 'active' : ''; ?>">
                    <h2>Histórico de Alterações (Últimas 10)</h2>

                    <?php if (empty($historico_senhas)): ?>
                        <p style="color: var(--text-muted); text-align: center; padding: 1rem 0;">
                            Nenhuma alteração de senha encontrada.
                        </p>
                    <?php else: ?>
                        <ul style="list-style: none; padding: 0; color: var(--text-color);">
                            <?php foreach ($historico_senhas as $registro): ?>
                                <?php
                                    // Formata a data (assumindo formato TIMESTAMP do PostgreSQL/PHP)
                                    $data_formatada = date('d/m/Y H:i', strtotime($registro['data_alteracao']));

                                    // Formata o método para exibição amigável
                                    $metodo_formatado = str_replace(['_', 'RAPIDA', 'CODIGO'], [' ', 'RÁPIDA', 'CÓDIGO'], $registro['metodo']);
                                    $metodo_formatado = ucwords(strtolower($metodo_formatado));
                                ?>
                                <li style="border-bottom: 1px solid var(--border-color); padding: 0.75rem 0; display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <strong style="color: var(--primary-color);"><?php echo $data_formatada; ?></strong> -
                                        <?php echo htmlspecialchars($metodo_formatado); ?>
                                    </div>
                                    <span style="font-size: 0.85rem; color: var(--text-muted);" title="IP de Origem">
                                        IP: <?php echo htmlspecialchars($registro['ip_origem'] ?? 'N/A'); ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <?php
    function get_svg_eye_closed() {
        return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"></path></svg>';
    }
    function get_svg_eye_open() {
        return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"></path></svg>';
    }
    ?>

    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script>
        // Inicializa o fundo de partículas
        particlesJS('particles-js', {
            "particles": { "number": { "value": 80, "density": { "enable": true, "value_area": 800 } }, "color": { "value": "#00aaff" }, "shape": { "type": "circle" }, "opacity": { "value": 0.5, "random": false }, "size": { "value": 3, "random": true }, "line_linked": { "enable": true, "distance": 150, "color": "#ffffff", "opacity": 0.1, "width": 1 }, "move": { "enable": true, "speed": 2, "direction": "none", "random": false, "straight": false, "out_mode": "out", "bounce": false } },
            "interactivity": { "detect_on": "canvas", "events": { "onhover": { "enable": true, "mode": "repulse" }, "onclick": { "enable": true, "mode": "push" } } },
            "retina_detect": true
        });

        document.addEventListener('DOMContentLoaded', () => {
            // --- Lógica do Menu Hambúrguer (Toggle) ---
            const menuToggle = document.getElementById('menu-toggle');
            const sidebar = document.querySelector('.sidebar');
            const body = document.body;

            // Adiciona o ícone toggle ao HTML se ele não existir
            if (!menuToggle && window.innerWidth <= 1024) {
                 const toggleDiv = document.createElement('div');
                 toggleDiv.className = 'menu-toggle';
                 toggleDiv.id = 'menu-toggle';
                 toggleDiv.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>';
                 document.body.prepend(toggleDiv);
                 menuToggle = document.getElementById('menu-toggle'); // Reatribui a variável
            }

            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', (event) => {
                    event.stopPropagation();
                    body.classList.toggle('sidebar-open');
                });

                // Lógica para fechar a sidebar ao clicar fora (ou no overlay)
                body.addEventListener('click', (event) => {
                    if (body.classList.contains('sidebar-open') &&
                        !sidebar.contains(event.target) &&
                        !menuToggle.contains(event.target)) {
                        body.classList.remove('sidebar-open');
                    }
                });

                // Fechar o menu ao clicar em um link de navegação
                sidebar.querySelectorAll('nav a').forEach(link => {
                    link.addEventListener('click', () => {
                        if (body.classList.contains('sidebar-open')) {
                            body.classList.remove('sidebar-open');
                        }
                    });
                });
            }

            // --- Lógica para Visualização/Ocultação de Senha ---
            const toggleButtons = document.querySelectorAll('.toggle-password');
            const svgEyeOpen = '<?php echo str_replace(["\r", "\n", "'"], '', get_svg_eye_open()); ?>';
            const svgEyeClosed = '<?php echo str_replace(["\r", "\n", "'"], '', get_svg_eye_closed()); ?>';

            toggleButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const passwordInput = document.getElementById(targetId);

                    if (passwordInput.type === 'password') {
                        passwordInput.type = 'text';
                        this.innerHTML = svgEyeOpen;
                    } else {
                        passwordInput.type = 'password';
                        this.innerHTML = svgEyeClosed;
                    }
                });
            });

            // --- Lógica de Abas (Tabs) ---
            const tabsNav = document.querySelectorAll('.tabs-nav a');
            const tabsContent = document.querySelectorAll('.tabs-content > div');

            tabsNav.forEach(tabLink => {
                tabLink.addEventListener('click', function(event) {
                    event.preventDefault();

                    const targetTabId = new URL(this.href).searchParams.get('tab');

                    const newUrl = window.location.pathname + `?tab=${targetTabId}`;
                    history.pushState(null, '', newUrl);

                    tabsNav.forEach(link => link.classList.remove('active'));
                    tabsContent.forEach(content => content.classList.remove('active'));

                    this.classList.add('active');

                    const targetContent = document.getElementById(targetTabId);
                    if (targetContent) {
                        targetContent.classList.add('active');
                    }
                });
            });

            // Garante que a aba correta é ativada no carregamento da página
            const activeTab = '<?php echo $tab_ativa; ?>';
            const initialTabLink = document.querySelector(`.tabs-nav a[href*="tab=${activeTab}"]`);
            const initialTabContent = document.getElementById(activeTab);

            if (initialTabLink && initialTabContent) {
                tabsNav.forEach(link => link.classList.remove('active'));
                tabsContent.forEach(content => content.classList.remove('active'));

                initialTabLink.classList.add('active');
                initialTabContent.classList.add('active');
            } else {
                document.getElementById('troca_rapida').classList.add('active');
                document.querySelector('.tabs-nav a').classList.add('active');
            }

            // --- Lógica para o dropdown do perfil (MANTIDA) ---
            const userProfileMenu = document.getElementById('user-profile-menu');
            const dropdown = document.getElementById('profile-dropdown');

            if (userProfileMenu && dropdown) {
                userProfileMenu.addEventListener('click', (event) => {
                    event.stopPropagation();
                    dropdown.classList.toggle('show');
                });

                window.addEventListener('click', (event) => {
                    if (dropdown.classList.contains('show') && !dropdown.contains(event.target)) {
                        dropdown.classList.remove('show');
                    }
                });
            }
        });
    </script>
</body>
</html>