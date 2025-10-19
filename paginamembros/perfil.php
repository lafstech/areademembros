<?php
// perfil.php
// --- LÓGICA PHP (BACKEND) ---
require_once '../config.php';
verificarAcesso('membro'); // Garante que apenas membros acessem

$nome_usuario = htmlspecialchars($_SESSION['usuario_nome']);
$usuario_id = $_SESSION['usuario_id'];

$usuario_dados = null;
$cursos_disponiveis = [];

try {
    // 1. Buscar dados detalhados do usuário logado
    $sql_usuario = "SELECT nome, email, nivel_acesso, data_criacao
                    FROM usuarios
                    WHERE id = ?";
    $stmt_usuario = $pdo->prepare($sql_usuario);
    $stmt_usuario->execute([$usuario_id]);
    $usuario_dados = $stmt_usuario->fetch(PDO::FETCH_ASSOC);

    // 2. Buscar cursos disponíveis para este usuário
    $sql_cursos = "SELECT c.titulo, c.id
                   FROM cursos c
                   JOIN usuario_cursos uc ON c.id = uc.curso_id
                   WHERE uc.usuario_id = ?
                   ORDER BY c.titulo ASC";
    $stmt_cursos = $pdo->prepare($sql_cursos);
    $stmt_cursos->execute([$usuario_id]);
    $cursos_disponiveis = $stmt_cursos->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    // Em caso de erro no DB, loga e define um array vazio
    error_log("Erro ao carregar perfil: " . $e->getMessage());
    $usuario_dados = ['nome' => 'Erro', 'email' => 'Erro', 'nivel_acesso' => 'membro', 'data_criacao' => ''];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Perfil - <?php echo $nome_usuario; ?></title>
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
        body { font-family: 'Poppins', sans-serif; background-color: var(--background-color); color: var(--text-color); display: flex; min-height: 100vh; }
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
        .sidebar nav { flex-grow: 1; }
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
        /* Dropdown do perfil */
        .profile-dropdown { position: absolute; bottom: calc(100% + 10px); left: 0; width: 100%; background-color: #2c3a4f; border-radius: 8px; border: 1px solid var(--border-color); padding: 0.5rem; z-index: 20; visibility: hidden; opacity: 0; transform: translateY(10px); transition: all 0.3s ease; }
        .profile-dropdown.show { visibility: visible; opacity: 1; transform: translateY(0); }
        .profile-dropdown a { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; color: var(--text-muted); text-decoration: none; font-size: 0.9rem; border-radius: 6px; }
        .profile-dropdown a:hover { background-color: var(--glass-background); color: var(--text-color); }

        /* ---------------------------------------------------- */
        /* ESTILOS DO CONTEÚDO PRINCIPAL (DESKTOP) */
        /* ---------------------------------------------------- */
        .main-content { margin-left: 260px; flex-grow: 1; padding: 2rem 3rem; min-height: 100vh; overflow-y: auto; transition: margin-left 0.3s ease; width: calc(100% - 260px); }
        .main-content header { margin-bottom: 2.5rem; }
        .main-content header h1 { font-size: 2.25rem; font-weight: 600; }
        .main-content header p { font-size: 1.1rem; color: var(--text-muted); margin-top: 18px; }
        .section-title { font-size: 1.5rem; font-weight: 600; margin-bottom: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border-color); }

        /* --- Estilos Específicos do Perfil --- */
        .profile-container {
            display: flex;
            gap: 3rem;
            align-items: flex-start;
        }

        .user-details {
            flex: 1;
            background: var(--glass-background);
            padding: 2rem;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            max-width: 450px;
        }

        .user-avatar-large {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background-color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 2rem;
            margin: 0 auto 1.5rem auto;
        }
        .detail-item {
            margin-bottom: 1rem;
            line-height: 1.5;
            color: var(--text-color);
            font-size: 1rem;
        }
        .detail-item strong {
            color: var(--text-muted);
            font-weight: 400;
            margin-right: 0.5rem;
        }

        /* --- Cursos do Usuário (Centralização) --- */
        .user-courses {
            /* Permite que o bloco ocupe o espaço e centraliza seu próprio conteúdo: */
            flex: 1;
            min-width: 300px;

            /* Centraliza o conteúdo dentro do bloco */
            display: flex;
            flex-direction: column;
            align-items: flex-start; /* Centraliza itens flex */
            text-align: left; /* Garante que textos soltos e p's centralizem */
        }

        .course-list {
            list-style: none;
            padding: 0;
            /* Garante que a lista não se espalhe desnecessariamente */
            width: 100%;
            max-width: 400px;
        }
        /* Centraliza o título da seção de cursos e ações */
        .user-courses h2.section-title {
                width: 100%;
            padding-left: 0;
            padding-right: 0;
        }

        .course-list-item {
            background-color: var(--glass-background);
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 10px;
            font-weight: 500;
            border-left: 4px solid var(--primary-color);
            transition: transform 0.2s, box-shadow 0.2s;
            text-align: left; /* Mantém o texto do item da lista alinhado à esquerda para leitura */
        }
        .course-list-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 170, 255, 0.15);
        }

        /* Botão para alterar senha */
        .user-courses a button {
            /* O <a> deve ser um bloco com largura limitada e margens auto para centralizar */
            display: block;
            width: 100%;
            max-width: 250px;
            margin: 1rem auto 0 auto;

            /* Estilos do botão */
            padding: 0.75rem 1.5rem;
            background-color: var(--sidebar-color); /* Você definiu a cor do sidebar aqui, mantendo */
            border: 1px solid var(--primary-color);
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.3s ease;
            box-shadow: 0 4px 10px rgba(0, 170, 255, 0.3);
        }
        .user-courses a button:hover {
            background-color: var(--primary-color); /* Inverte a cor no hover para o primary */
            box-shadow: 0 6px 15px rgba(0, 170, 255, 0.4);
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
                margin-left: 6px;
            }

            /* 3. Conteúdo Principal */
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 1.5rem;
                padding-top: 5rem;
            }
            .main-content header h1 { font-size: 2rem; }

            /* 4. Layout do Perfil (Torna-se Coluna) */
            .profile-container {
                flex-direction: column;
                gap: 2rem;
            }
            .user-details {
                max-width: 100%;
                margin-top: -17px;
            }

            /* NO MOBILE: Remove a centralização forçada do bloco user-courses para ele ocupar 100% */
            .user-courses {
                align-items: flex-start; /* Alinha o conteúdo à esquerda no mobile */
                text-align: left;
                margin: 0; /* Remove margem auto */
                max-width: 100%;
                margin-left: 32px;
            }
            .user-courses h2.section-title {
                text-align: left; /* Alinha o título à esquerda no mobile */
            }
            .user-courses a button {
                margin-left: 0; /* Desfaz a centralização do botão no mobile */
                max-width: 100%;
            }


            /* 5. Estado "Menu Aberto" */
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
                margin-left: 16px;
            }
            .main-content header h1 { font-size: 1.8rem; margin-left: 67px; margin-top: -45px; }
            .main-content header p { font-size: 1rem; margin-top: 14px; }

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
            <h1>Detalhes da Conta</h1>
            <p>Visualize suas informações pessoais e os cursos aos quais você tem acesso.</p>
        </header>

        <?php if ($usuario_dados): ?>
        <div class="profile-container">
            <div class="user-details">
                <div class="user-avatar-large">
                    <?php echo strtoupper(substr($usuario_dados['nome'], 0, 2)); ?>
                </div>

                <div class="detail-item">
                    <strong>Nome:</strong> <?php echo htmlspecialchars($usuario_dados['nome']); ?>
                </div>
                <div class="detail-item">
                    <strong>E-mail:</strong> <?php echo htmlspecialchars($usuario_dados['email']); ?>
                </div>
                <div class="detail-item">
                    <strong>Nível de Acesso:</strong> <span style="text-transform: capitalize;"><?php echo htmlspecialchars($usuario_dados['nivel_acesso']); ?></span>
                </div>
                <div class="detail-item">
                    <strong>Membro Desde:</strong> <?php echo (new DateTime($usuario_dados['data_criacao']))->format('d/m/Y'); ?>
                </div>
            </div>

            <div class="user-courses">
                <h2 class="section-title">Meus Cursos Ativos</h2>

                <?php if (empty($cursos_disponiveis)): ?>
                    <p style="color: var(--text-muted);">Você ainda não possui acesso a nenhum curso. Contate o suporte!</p>
                <?php else: ?>
                    <ul class="course-list">
                        <?php foreach ($cursos_disponiveis as $curso): ?>
                            <li class="course-list-item">
                                <a href="cursos.php?id=<?php echo $curso['id']; ?>" style="color: inherit; text-decoration: none;">
                                    <?php echo htmlspecialchars($curso['titulo']); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <h2 class="section-title">Ações</h2>
                <a href="resetpassword.php">
                    <button class="btn-primary" style="margin-top: 1rem; background-color: var(--sidebar-color); border: 1px solid var(--primary-color);">
                        Alterar Senha
                    </button>
                </a>
            </div>
        </div>
        <?php else: ?>
            <p style="color: var(--primary-color);">Não foi possível carregar os dados do usuário.</p>
        <?php endif; ?>

    </main>

    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script>
        // Inicializa o fundo de partículas (copiado da sua index.php)
        particlesJS('particles-js', {
            "particles": { "number": { "value": 80, "density": { "enable": true, "value_area": 800 } }, "color": { "value": "#00aaff" }, "shape": { "type": "circle" }, "opacity": { "value": 0.5, "random": false }, "size": { "value": 3, "random": true }, "line_linked": { "enable": true, "distance": 150, "color": "#ffffff", "opacity": 0.1, "width": 1 }, "move": { "enable": true, "speed": 2, "direction": "none", "random": false, "straight": false, "out_mode": "out", "bounce": false } },
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
                    // Verifica se o menu está aberto E se o clique não foi dentro da sidebar ou no próprio botão
                    if (body.classList.contains('sidebar-open') &&
                        !sidebar.contains(event.target) &&
                        !menuToggle.contains(event.target)) {
                        body.classList.remove('sidebar-open');
                    }
                });

                // Opcional: Fechar o menu ao clicar em um link de navegação (melhora a UX mobile)
                sidebar.querySelectorAll('nav a').forEach(link => {
                    link.addEventListener('click', () => {
                        // Verifica se o menu está aberto antes de fechar
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
                    // Garante que o clique não foi no próprio dropdown
                    if (dropdown.classList.contains('show') && !dropdown.contains(event.target)) {
                        dropdown.classList.remove('show');
                    }
                });
            }
        });
    </script>
</body>
</html>