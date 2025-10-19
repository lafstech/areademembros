<?php
// --- LÓGICA PHP (BACKEND) ---
// A lógica segura que já tínhamos, validando especificamente administradores.
require_once '../config.php';

// Se um admin já está logado, vai para o dashboard dele
if (isset($_SESSION['usuario_id']) && $_SESSION['usuario_nivel_acesso'] === 'admin') {
    header('Location: index.php');
    exit();
}

$erro_login = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $senha = $_POST['senha'];

    if (empty($email) || empty($senha)) {
        $erro_login = "Por favor, preencha e-mail e senha.";
    } else {
        $sql = "SELECT id, nome, email, senha, nivel_acesso FROM usuarios WHERE email = ? AND nivel_acesso = 'admin'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if ($usuario && password_verify($senha, $usuario['senha'])) {
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nome'] = $usuario['nome'];
            $_SESSION['usuario_nivel_acesso'] = $usuario['nivel_acesso'];
            header('Location: index.php');
            exit();
        } else {
            $erro_login = "Credenciais inválidas ou acesso não autorizado.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso Administrativo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">

    <style>
        :root {
            /* Identidade visual do admin: trocamos o azul por um vermelho/carmesim */
            --primary-color: #e11d48;
            --background-color: #111827;
            --glass-background: rgba(255, 255, 255, 0.05);
            --text-color: #f9fafb;
            --input-border: rgba(255, 255, 255, 0.2);
        }

        /* Os estilos base são os mesmos da outra tela de login para consistência */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--background-color);
            color: var(--text-color);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            overflow: hidden;
        }

        #particles-js {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            z-index: 0;
        }

        .login-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 420px;
            padding: 4rem 2.5rem;
            background: var(--glass-background);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            text-align: center;
        }

        .login-container h1 {
            font-weight: 600;
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        .login-container p {
            font-weight: 300;
            margin-bottom: 2.5rem;
            color: rgba(255, 255, 255, 0.7);
        }

        .input-group {
            position: relative;
            margin-bottom: 2rem;
        }

        .input-field {
            width: 100%;
            background: transparent;
            border: none;
            border-bottom: 1px solid var(--input-border);
            padding: 10px 5px;
            font-size: 1rem;
            color: var(--text-color);
            outline: none;
        }

        .input-label {
            position: absolute;
            top: 10px;
            left: 5px;
            font-size: 1rem;
            color: rgba(255, 255, 255, 0.5);
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .input-field:focus + .input-label,
        .input-field:valid + .input-label {
            top: -12px;
            font-size: 0.8rem;
            color: var(--primary-color); /* A cor de foco agora será vermelha */
        }

        .login-button {
            width: 100%;
            padding: 1rem;
            background: var(--primary-color); /* Botão vermelho */
            border: none;
            border-radius: 8px;
            color: #fff;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 1rem;
        }

        .login-button:hover {
            box-shadow: 0 0 25px rgba(225, 29, 72, 0.6); /* Sombra vermelha */
            transform: translateY(-2px);
        }

        .error-message {
            color: #ff8a8a;
            background-color: rgba(255, 77, 77, 0.1);
            border: 1px solid rgba(255, 77, 77, 0.3);
            padding: 10px;
            border-radius: 4px;
            margin-top: 20px;
            font-size: 0.9rem;
        }

        .security-notice {
            margin-top: 2.5rem;
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

    </style>
</head>
<body>
    <div id="particles-js"></div>

    <div class="login-container">
        <h1>Acesso Administrativo</h1>
        <p>Insira suas credenciais para gerenciar a plataforma.</p>

        <form action="login.php" method="POST">
            <div class="input-group">
                <input type="email" name="email" id="email" class="input-field" required>
                <label for="email" class="input-label">E-mail de Administrador</label>
            </div>

            <div class="input-group">
                <input type="password" name="senha" id="senha" class="input-field" required>
                <label for="senha" class="input-label">Senha</label>
            </div>

            <button type="submit" class="login-button">Entrar no Painel</button>
        </form>

        <?php if (!empty($erro_login)): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($erro_login); ?>
            </div>
        <?php endif; ?>

        <div class="security-notice">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M8 1a2 2 0 0 1 2 2v2H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/></svg>
            <span>Acesso restrito e monitorado.</span>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script>
        // --- LÓGICA DO FUNDO DE PARTÍCULAS (versão Admin) ---
        particlesJS('particles-js', {
            "particles": {
                "number": { "value": 60, "density": { "enable": true, "value_area": 800 } },
                // A cor das partículas e linhas agora é a primária do admin (vermelho)
                "color": { "value": "#e11d48" },
                "shape": { "type": "circle" },
                "opacity": { "value": 0.6, "random": true },
                "size": { "value": 3, "random": true },
                "line_linked": { "enable": true, "distance": 150, "color": "#ffffff", "opacity": 0.1, "width": 1 },
                "move": { "enable": true, "speed": 2, "direction": "none", "random": false, "straight": false, "out_mode": "out", "bounce": false }
            },
            "interactivity": {
                "detect_on": "canvas",
                "events": { "onhover": { "enable": true, "mode": "repulse" }, "onclick": { "enable": false }, "resize": true },
                "modes": { "repulse": { "distance": 100, "duration": 0.4 } }
            },
            "retina_detect": true
        });
    </script>
</body>
</html>