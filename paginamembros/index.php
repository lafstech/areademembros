<?php
// --- LÓGICA PHP (BACKEND) ---
require_once '../config.php';
verificarAcesso('membro'); // Garante que apenas membros acessem

$nome_usuario = htmlspecialchars($_SESSION['usuario_nome']);
$usuario_id = $_SESSION['usuario_id'];

// --- BUSCA OS CURSOS REAIS DO USUÁRIO NO BANCO ---
// Esta query busca apenas os cursos aos quais o usuário logado tem acesso.
$sql = "SELECT c.* FROM cursos c
         JOIN usuario_cursos uc ON c.id = uc.curso_id
         WHERE uc.usuario_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$usuario_id]);
$cursos_do_usuario = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Área de membros - <?php echo $nome_usuario; ?></title>
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
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--background-color);
            color: var(--text-color);
            display: flex;
            min-height: 100vh;
        }

        #particles-js {
            position: fixed;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            z-index: -1;
        }

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

        .sidebar .logo-area {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: -1rem;
            padding-bottom: 1rem;
        }

        .sidebar .logo-circle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.75rem;
            box-shadow: 0 0 15px rgba(0, 170, 255, 0.6);
            overflow: hidden;
        }

        .sidebar .logo-circle img {
            max-width: 100%;
            max-height: 100%;
            display: block;
            object-fit: contain;
        }

        .sidebar .logo-text {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
            text-align: center;
        }

        .sidebar .divider {
            width: 100%;
            height: 1px;
            background-color: var(--border-color);
            margin: 1.5rem 0;
        }

        .sidebar nav {
            flex-grow: 1;
        }

        .sidebar nav a {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            color: var(--text-muted);
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
            border: 1px solid transparent;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            background-color: var(--glass-background);
        }

        .sidebar nav a:hover, .sidebar nav a.active {
            background-color: rgba(0, 170, 255, 0.2);
            color: var(--text-color);
            border-color: var(--primary-color);
            box-shadow: 0 4px 15px rgba(0, 170, 255, 0.4);
        }

        .sidebar nav a svg {
            width: 24px;
            height: 24px;
        }

        .user-profile {
            position: relative;
            margin-top: auto; /* Empurra o perfil para o final no DESKTOP */
            background-color: var(--glass-background);
            padding: 0.75rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 1rem;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.3s ease;
        }

        .user-profile:hover {
            border-color: var(--border-color);
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
        }

        .user-info .user-name {
            font-weight: 600;
            font-size: 0.9rem;
            line-height: 1.2;
        }

        .user-info .user-level {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .profile-dropdown {
            position: absolute;
            bottom: calc(100% + 10px);
            left: 0;
            width: 100%;
            background-color: #2c3a4f;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            padding: 0.5rem;
            z-index: 20;
            visibility: hidden;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.3s ease;
        }

        .profile-dropdown.show {
            visibility: visible;
            opacity: 1;
            transform: translateY(0);
        }

        .profile-dropdown a {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.9rem;
            border-radius: 6px;
        }

        .profile-dropdown a:hover {
            background-color: var(--glass-background);
            color: var(--text-color);
        }

        /* ESTILOS DO CONTEÚDO PRINCIPAL */
        .main-content { margin-left: 260px; flex-grow: 1; padding: 2rem 3rem; min-height: 100vh; overflow-y: auto; transition: margin-left 0.3s ease; width: calc(100% - 260px); }
        .main-content header { margin-bottom: 2.5rem; }
        .main-content header h1 { font-size: 2.25rem; font-weight: 600; }
        .main-content header p { font-size: 1.1rem; color: var(--text-muted); margin-top: 17px; }
        .section-title { font-size: 1.5rem; font-weight: 600; margin-bottom: 1.5rem; }
        .course-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 2rem; }

        /* AJUSTE FINO AQUI */
        .course-card {
            background: var(--glass-background);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s ease;
            display: flex; /* 1. Transforma o card em um container flex */
            flex-direction: column; /* 2. Define a direção para vertical (imagem em cima, conteúdo embaixo) */
        }
        .course-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3); }
        .course-card img { width: 100%; height: 180px; object-fit: cover; }

        /* AJUSTE FINO AQUI */
        .course-card-content {
            padding: 1.5rem;
            display: flex; /* 3. Transforma a área de conteúdo em flex */
            flex-direction: column; /* 4. Direção vertical para o conteúdo */
            flex-grow: 1; /* 5. Faz esta área crescer para preencher o espaço do card */
        }
        .course-card-content h3 { font-size: 1.2rem; margin-bottom: 0.5rem; font-weight: 600; }

        /* AJUSTE FINO AQUI */
        .course-card-content p {
            font-size: 0.9rem;
            color: var(--text-muted);
            line-height: 1.6;
            flex-grow: 1; /* 6. Faz o parágrafo crescer e empurrar o botão para baixo */
        }

        .course-card-content .btn-acessar {
            margin-top: 1.5rem; /* Garante um espaço entre o texto e o botão */
            width: 100%;
            text-align: center;
            padding: 0.75rem;
            background-color: var(--primary-color);
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            display: block;
            font-weight: 600;
            transition: background-color 0.3s ease;
        }
        .course-card-content .btn-acessar:hover { background-color: #0088cc; }


        /* BOTÃO HAMBÚRGUER */
        .menu-toggle { display: none; position: fixed; top: 1.5rem; left: 1.5rem; z-index: 1001; cursor: pointer; padding: 10px; background-color: var(--sidebar-color); border-radius: 8px; border: 1px solid var(--border-color); }
        .menu-toggle svg { width: 24px; height: 24px; color: var(--text-color); }


        /* RESPONSIVIDADE */
        @media (max-width: 1024px) {
            .sidebar { width: 280px; transform: translateX(-280px); box-shadow: 5px 0 15px rgba(0, 0, 0, 0.5); z-index: 1002; height: 100%; overflow-y: auto; }
            .user-profile { margin-top: 1.5rem; position: relative; }
            .menu-toggle { display: flex; }
            .main-content { margin-left: 0; width: 100%; padding: 1.5rem; padding-top: 5rem; }
            .main-content header h1 { font-size: 2rem; }
            .course-grid { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }
            body.sidebar-open .sidebar { transform: translateX(0); }
            body.sidebar-open::after { content: ''; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.7); z-index: 1001; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 1rem; padding-top: 4.5rem; margin-left: 0; margin-right: 0; }
            .main-content header h1 { font-size: 1.8rem; text-align: center; margin-top: -46px; margin-left: 18px; }
            .main-content header p { font-size: 1rem; margin-top: 11px; text-align: center; }
            .course-grid { grid-template-columns: 1fr; gap: 1.5rem; }
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
        <header>
            <h1>Olá, <?php echo $nome_usuario; ?>!</h1>
            <p>Sua jornada de aprendizado continua aqui. Escolha um curso e vamos começar.</p>
        </header>

        <h2 class="section-title">Meus Cursos</h2>

        <section class="course-grid">
            <?php if (empty($cursos_do_usuario)): ?>
                <p>Você ainda não tem acesso a nenhum curso. Faça a aquisição na Loja de Cursos no meu ao lado.</p>
            <?php else: ?>
                <?php foreach ($cursos_do_usuario as $curso): ?>
                <div class="course-card">
                    <img src="<?php echo htmlspecialchars($curso['imagem_thumbnail'] ?: 'https://images.unsplash.com/photo-1544256718-3bcf237f3974?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wzNTc5fDB8MXxzZWFyY2h8N3x8ZGVwbG95bWVudHxlbnwwfHx8fDE3MjEyMDA4NTV8MA&ixlib=rb-4.0.3&q=80&w=400'); ?>" alt="Thumbnail do curso">
                    <div class="course-card-content">
                        <h3><?php echo htmlspecialchars($curso['titulo']); ?></h3>
                        <p><?php echo htmlspecialchars($curso['descricao']); ?></p>
                        <a href="cursos.php?id=<?php echo $curso['id']; ?>" class="btn-acessar">Acessar Curso</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script>
        // Inicializa o fundo de partículas
        particlesJS('particles-js', {
            "particles": {
                "number": { "value": 80, "density": { "enable": true, "value_area": 800 } },
                "color": { "value": "#00aaff" },
                "shape": { "type": "circle" },
                "opacity": { "value": 0.5, "random": false },
                "size": { "value": 3, "random": true },
                "line_linked": { "enable": true, "distance": 150, "color": "#ffffff", "opacity": 0.1, "width": 1 },
                "move": { "enable": true, "speed": 2, "direction": "none", "random": false, "straight": false, "out_mode": "out", "bounce": false }
            },
            "interactivity": { "detect_on": "canvas", "events": { "onhover": { "enable": true, "mode": "repulse" }, "onclick": { "enable": true, "mode": "push" } } },
            "retina_detect": true
        });

        document.addEventListener('DOMContentLoaded', () => {
            // --- Lógica para o Menu Hambúrguer (Toggle) ---
            const menuToggle = document.getElementById('menu-toggle');
            const sidebar = document.querySelector('.sidebar');
            const body = document.body;

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

                // Opcional: Fechar o menu ao clicar em um link de navegação (melhora a UX mobile)
                sidebar.querySelectorAll('nav a').forEach(link => {
                    link.addEventListener('click', () => {
                        if (body.classList.contains('sidebar-open')) {
                            body.classList.remove('sidebar-open');
                        }
                    });
                });
            }

            // --- Lógica para o dropdown do perfil ---
            const userProfileMenu = document.getElementById('user-profile-menu');
            const dropdown = document.getElementById('profile-dropdown');

            if (userProfileMenu && dropdown) {
                userProfileMenu.addEventListener('click', (event) => {
                    event.stopPropagation();
                    dropdown.classList.toggle('show');
                });

                // Fecha o dropdown se o usuário clicar em qualquer outro lugar da tela
                window.addEventListener('click', (event) => {
                    if (dropdown.classList.contains('show') && !userProfileMenu.contains(event.target) && !dropdown.contains(event.target)) {
                        dropdown.classList.remove('show');
                    }
                });
            }
        });
    </script>
</body>
</html>