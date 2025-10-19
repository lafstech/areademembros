<?php
// --- LÓGICA PHP (BACKEND) ---
require_once '../config.php';
verificarAcesso('membro'); // Garante que apenas membros acessem

$nome_usuario = htmlspecialchars($_SESSION['usuario_nome']);
$usuario_id = $_SESSION['usuario_id'];

// 1. BUSCAR TODOS OS CURSOS DISPONÍVEIS NA PLATAFORMA
// A ordenação por ID garante uma exibição consistente
$stmt_todos_cursos = $pdo->query("SELECT * FROM cursos ORDER BY id");
$todos_cursos = $stmt_todos_cursos->fetchAll(PDO::FETCH_ASSOC);

// 2. BUSCAR O PLANO DE ACESSO TOTAL
// Buscamos um plano específico para garantir que só ele seja exibido no banner
$stmt_plano = $pdo->prepare("SELECT * FROM planos WHERE tipo_acesso = ? LIMIT 1");
$stmt_plano->execute(['TODOS_CURSOS']);
$plano_acesso_total = $stmt_plano->fetch(PDO::FETCH_ASSOC);

// 3. BUSCAR OS IDs DOS CURSOS QUE O USUÁRIO JÁ POSSUI
// Isso é crucial para sabermos qual botão exibir (Comprar vs. Acessar)
$stmt_cursos_usuario = $pdo->prepare("SELECT curso_id FROM usuario_cursos WHERE usuario_id = ?");
$stmt_cursos_usuario->execute([$usuario_id]);
// Usamos PDO::FETCH_COLUMN para obter apenas a coluna 'curso_id' em um array simples (ex: [1, 5, 8])
$cursos_do_usuario_ids = $stmt_cursos_usuario->fetchAll(PDO::FETCH_COLUMN, 0);

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprar Cursos - <?php echo $nome_usuario; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/x-icon" href="/favicon1.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        /* ============================================== */
        /* === ESTILOS GLOBAIS E VARIÁVEIS DE CORES === */
        /* ============================================== */
        :root {
            --primary-color: #00aaff;
            --success-color: #10b981; /* Verde para itens já adquiridos */
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

        /* ============================================== */
        /* === ESTILOS DA SIDEBAR (MENU LATERAL) === */
        /* ============================================== */
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
        .sidebar nav a { display: flex; align-items: center; gap: 1rem; padding: 1rem; color: var(--text-muted); text-decoration: none; border-radius: 8px; margin-bottom: 0.5rem; transition: all 0.3s ease; border: 1px solid transparent; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2); background-color: var(--glass-background); }
        .sidebar nav a:hover, .sidebar nav a.active { background-color: rgba(0, 170, 255, 0.2); color: var(--text-color); border-color: var(--primary-color); box-shadow: 0 4px 15px rgba(0, 170, 255, 0.4); }
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

        /* ============================================== */
        /* === CONTEÚDO PRINCIPAL E CARDS === */
        /* ============================================== */
        .main-content { margin-left: 260px; flex-grow: 1; padding: 2rem 3rem; min-height: 100vh; overflow-y: auto; transition: margin-left 0.3s ease; width: calc(100% - 260px); }
        .main-content header { margin-bottom: 2.5rem; }
        .main-content header h1 { font-size: 2.25rem; font-weight: 600; }
        .main-content header p { font-size: 1.1rem; color: var(--text-muted); margin-top: 17px; }
        .section-title { font-size: 1.5rem; font-weight: 600; margin-bottom: 1.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color); }

        /* CARD DE DESTAQUE DO PLANO */
        .plan-highlight-card {
            background: linear-gradient(45deg, #0052d4, #4364f7, #6fb1fc);
            border-radius: 12px;
            padding: 2.5rem;
            margin-bottom: 3rem;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 82, 212, 0.4);
        }
        .plan-highlight-card h2 { font-size: 2rem; font-weight: 700; margin-bottom: 0.5rem; }
        .plan-highlight-card p { font-size: 1.1rem; max-width: 600px; margin: 0 auto 1.5rem auto; color: rgba(255, 255, 255, 0.9); }
        .plan-price { font-size: 2.5rem; font-weight: 700; margin-bottom: 1.5rem; }
        .plan-price span { font-size: 1rem; font-weight: 400; }
        .btn-buy-plan { display: inline-block; background-color: #ffffff; color: #0052d4; padding: 1rem 2.5rem; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 1.1rem; transition: all 0.3s ease; }
        .btn-buy-plan:hover { transform: scale(1.05); box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2); }

        /* GRID E CARDS DE CURSOS */
        .course-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 2rem; }
        .course-card { background: var(--glass-background); border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden; transition: all 0.3s ease; display: flex; flex-direction: column; }
        .course-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3); }
        .course-card img { width: 100%; height: 180px; object-fit: cover; }
        .course-card-content { padding: 1.5rem; display: flex; flex-direction: column; flex-grow: 1; }
        .course-card-content h3 { font-size: 1.2rem; margin-bottom: 0.5rem; font-weight: 600; flex-grow: 0; }
        .course-card-content p { font-size: 0.9rem; color: var(--text-muted); line-height: 1.6; flex-grow: 1; }

        /* RODAPÉ DOS CARDS (PREÇO E BOTÕES) */
        .course-card-footer {
            margin-top: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0; /* Impede que o rodapé encolha */
        }
        .course-price { font-size: 1.2rem; font-weight: 600; color: var(--primary-color); }
        .course-status-owned { font-size: 1.0rem; font-weight: 600; color: var(--success-color); }
        .btn-comprar { padding: 0.6rem 1.2rem; background-color: var(--primary-color); color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background-color 0.3s ease; text-align: center; }
        .btn-comprar:hover { background-color: #0088cc; }
        .btn-acessar-loja { padding: 0.6rem 1.2rem; background-color: transparent; border: 2px solid var(--success-color); color: var(--success-color); text-decoration: none; border-radius: 8px; font-weight: 600; transition: all 0.3s ease; text-align: center; }
        .btn-acessar-loja:hover { background-color: var(--success-color); color: #fff; }

        /* ============================================== */
        /* === RESPONSIVIDADE E MENU MOBILE === */
        /* ============================================== */
        .menu-toggle { display: none; position: fixed; top: 1.5rem; left: 1.5rem; z-index: 1001; cursor: pointer; padding: 10px; background-color: var(--sidebar-color); border-radius: 8px; border: 1px solid var(--border-color); }
        .menu-toggle svg { width: 24px; height: 24px; color: var(--text-color); }

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
            .main-content { padding: 1rem; padding-top: 5rem; }
            .main-content header { text-align: center; }
            .main-content header h1 { font-size: 1.8rem; }
            .main-content header p { font-size: 1rem; }
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
            <h1>Explore Nossos Cursos</h1>
            <p>Escolha seu próximo desafio ou adquira acesso total a todo o nosso conteúdo.</p>
        </header>

        <?php if ($plano_acesso_total): ?>
        <section class="plan-highlight-card">
            <h2><?php echo htmlspecialchars($plano_acesso_total['nome']); ?></h2>
            <p><?php echo htmlspecialchars($plano_acesso_total['descricao']); ?></p>
            <div class="plan-price">
                R$ <?php echo number_format($plano_acesso_total['valor'], 2, ',', '.'); ?>
                <span>/ Acesso Vitalício</span>
            </div>
            <a href="checkout.php?plano_id=<?php echo $plano_acesso_total['id']; ?>" class="btn-buy-plan">Quero Acesso Total</a>
        </section>
        <?php endif; ?>

        <h2 class="section-title">Cursos Individuais</h2>

        <section class="course-grid">
            <?php if (empty($todos_cursos)): ?>
                <p>Nenhum curso disponível para venda no momento.</p>
            <?php else: ?>
                <?php foreach ($todos_cursos as $curso): ?>
                    <?php
                        // Verifica se o ID do curso atual está no array de cursos que o usuário já possui
                        $usuario_possui_curso = in_array($curso['id'], $cursos_do_usuario_ids);
                    ?>
                    <div class="course-card">
                        <img src="<?php echo htmlspecialchars($curso['imagem_thumbnail'] ?: 'https://images.unsplash.com/photo-1544256718-3bcf237f3974?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wzNTc5fDB8MXxzZWFyY2h8N3x8ZGVwbG95bWVudHxlbnwwfHx8fDE3MjEyMDA4NTV8MA&ixlib=rb-4.0.3&q=80&w=400'); ?>" alt="Thumbnail do curso <?php echo htmlspecialchars($curso['titulo']); ?>">
                        <div class="course-card-content">
                            <h3><?php echo htmlspecialchars($curso['titulo']); ?></h3>
                            <p><?php echo htmlspecialchars($curso['descricao']); ?></p>

                            <div class="course-card-footer">
                                <?php if ($usuario_possui_curso): ?>
                                    <span class="course-status-owned">Já Adquirido</span>
                                    <a href="cursos.php?id=<?php echo $curso['id']; ?>" class="btn-acessar-loja">Acessar Curso</a>
                                <?php else: ?>
                                    <span class="course-price">R$ <?php echo number_format($curso['valor'], 2, ',', '.'); ?></span>
                                    <a href="checkout.php?curso_id=<?php echo $curso['id']; ?>" class="btn-comprar">Comprar Agora</a>
                                <?php endif; ?>
                            </div>
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
            "particles": { "number": { "value": 80, "density": { "enable": true, "value_area": 800 } }, "color": { "value": "#00aaff" }, "shape": { "type": "circle" }, "opacity": { "value": 0.5, "random": false }, "size": { "value": 3, "random": true }, "line_linked": { "enable": true, "distance": 150, "color": "#ffffff", "opacity": 0.1, "width": 1 }, "move": { "enable": true, "speed": 2, "direction": "none", "random": false, "straight": false, "out_mode": "out", "bounce": false } }, "interactivity": { "detect_on": "canvas", "events": { "onhover": { "enable": true, "mode": "repulse" }, "onclick": { "enable": true, "mode": "push" } } }, "retina_detect": true
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

                body.addEventListener('click', (event) => {
                    if (body.classList.contains('sidebar-open') && !sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                        body.classList.remove('sidebar-open');
                    }
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