<?php
// --- LÓGICA PHP (BACKEND) ---
require_once '../config.php';
verificarAcesso('admin'); // Proteção máxima

$nome_usuario = htmlspecialchars($_SESSION['usuario_nome']);
$pagina_atual = basename($_SERVER['PHP_SELF']); // Define a página atual para a sidebar

// --- BUSCA DE DADOS REAIS DO BANCO PARA O DASHBOARD ---
$total_usuarios = $pdo->query("SELECT COUNT(id) FROM usuarios")->fetchColumn();
$total_cursos = $pdo->query("SELECT COUNT(id) FROM cursos")->fetchColumn();
$total_aulas = $pdo->query("SELECT COUNT(id) FROM aulas")->fetchColumn();
$novos_usuarios_hoje = $pdo->query("SELECT COUNT(id) FROM usuarios WHERE DATE(data_criacao) = CURRENT_DATE")->fetchColumn();

// --- DADOS PARA O GRÁFICO (Últimos 6 meses) ---
$stmt = $pdo->query("
    SELECT TO_CHAR(DATE_TRUNC('month', data_criacao), 'Mon/YY') as mes, COUNT(id) as total
    FROM usuarios
    WHERE data_criacao >= NOW() - INTERVAL '6 months'
    GROUP BY DATE_TRUNC('month', data_criacao)
    ORDER BY DATE_TRUNC('month', data_criacao) ASC
");
$chart_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

$chart_labels = []; $chart_data = [];
foreach ($chart_results as $row) {
    $chart_labels[] = $row['mes'];
    $chart_data[] = $row['total'];
}
$chart_labels_json = json_encode($chart_labels);
$chart_data_json = json_encode($chart_data);

// --- DADOS PARA OS PAINÉIS INTELIGENTES ---
$ultimos_usuarios = $pdo->query("SELECT nome, email FROM usuarios ORDER BY data_criacao DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$cursos_sem_aulas = $pdo->query("
    SELECT c.id, c.titulo FROM cursos c
    LEFT JOIN aulas a ON c.id = a.curso_id
    WHERE a.id IS NULL
    ORDER BY c.titulo ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Painel Administrativo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --primary-color: #e11d48; --background-color: #111827; --sidebar-color: #1f2937;
            --glass-background: rgba(31, 41, 55, 0.5); --text-color: #f9fafb; --text-muted: #9ca3af;
            --border-color: rgba(255, 255, 255, 0.1); --success-color: #22c55e;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--background-color); color: var(--text-color); display: flex; }

        /* --- CSS DA SIDEBAR --- */
        .sidebar { width: 260px; background-color: var(--sidebar-color); height: 100vh; position: fixed; left: 0; top: 0; padding: 2rem 1.5rem; display: flex; flex-direction: column; border-right: 1px solid var(--border-color); z-index: 1000; transition: transform 0.3s ease; }
        .sidebar .logo { font-size: 1.5rem; font-weight: 700; margin-bottom: 3rem; text-align: center; }
        .sidebar .logo span { color: var(--primary-color); }
        .sidebar nav { flex-grow: 1; overflow-y: auto; scrollbar-width: thin; scrollbar-color: var(--primary-color) transparent; }
        .sidebar nav::-webkit-scrollbar { width: 5px; }
        .sidebar nav::-webkit-scrollbar-thumb { background-color: var(--primary-color); border-radius: 10px; }
        .sidebar nav a { display: flex; align-items: center; gap: 1rem; padding: 1rem; color: var(--text-muted); text-decoration: none; border-radius: 8px; margin-bottom: 0.5rem; transition: all 0.3s ease; }
        .sidebar nav a:hover, .sidebar nav a.active { background-color: var(--glass-background); color: var(--text-color); }
        .sidebar nav a svg { width: 24px; height: 24px; flex-shrink: 0; }
        .user-profile { position: relative; margin-top: auto; background-color: var(--glass-background); padding: 0.75rem; border-radius: 8px; display: flex; align-items: center; gap: 1rem; cursor: pointer; border: 1px solid transparent; transition: all 0.3s ease; flex-shrink: 0; }
        .user-profile:hover { border-color: var(--border-color); }
        .avatar { width: 40px; height: 40px; border-radius: 50%; background-color: var(--primary-color); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 1rem; flex-shrink: 0; }
        .user-info { overflow: hidden; }
        .user-info .user-name { font-weight: 600; font-size: 0.9rem; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-info .user-level { font-size: 0.75rem; color: var(--text-muted); }
        .profile-dropdown { position: absolute; bottom: calc(100% + 10px); left: 0; width: 100%; background-color: #2c3a4f; border-radius: 8px; border: 1px solid var(--border-color); padding: 0.5rem; z-index: 20; visibility: hidden; opacity: 0; transform: translateY(10px); transition: all 0.3s ease; }
        .profile-dropdown.show { visibility: visible; opacity: 1; transform: translateY(0); }
        .profile-dropdown a { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; color: var(--text-muted); text-decoration: none; font-size: 0.9rem; border-radius: 6px; }
        .profile-dropdown a:hover { background-color: var(--glass-background); color: var(--text-color); }

        /* --- CSS DO CONTEÚDO PRINCIPAL --- */
        .main-content { margin-left: 260px; flex-grow: 1; padding: 2rem 3rem; height: 100vh; overflow-y: auto; transition: margin-left 0.3s ease; width: calc(100% - 260px); }
        .main-content header { margin-bottom: 2.5rem; }
        .main-content header h1 { font-size: 2rem; font-weight: 600; }
        .main-content header p { color: var(--text-muted); }

        /* --- CSS DOS CARDS E GRÁFICOS --- */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem; }
        .stat-card { background: var(--glass-background); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color); display: flex; align-items: center; gap: 1.5rem; }
        .stat-card .icon-wrapper { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: rgba(225, 29, 72, 0.1); border: 1px solid rgba(225, 29, 72, 0.3); flex-shrink: 0; }
        .stat-card .icon-wrapper svg { width: 28px; height: 28px; color: var(--primary-color); }
        .stat-info .stat-number { font-size: 2rem; font-weight: 700; line-height: 1.2; }
        .stat-info .stat-label { font-size: 0.9rem; color: var(--text-muted); }
        .chart-container { background: var(--glass-background); padding: 2rem; border-radius: 12px; border: 1px solid var(--border-color); min-height: 400px; }
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-top: 2.5rem; }
        .info-panel { background: var(--glass-background); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color); }
        .info-panel h3 { font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; display:flex; align-items:center; gap: 0.5rem; }
        .info-panel h3 svg { color: var(--primary-color); width: 20px; height: 20px; }
        .info-panel ul { list-style: none; }
        .info-panel li { padding: 0.75rem 0; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .info-panel li:last-child { border-bottom: none; }
        .info-panel .item-main { overflow: hidden; }
        .info-panel .item-main .item-title { font-weight: 500; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .info-panel .item-main .item-subtitle { font-size: 0.8rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .info-panel .item-action a { font-size: 0.8rem; color: var(--primary-color); text-decoration: none; font-weight: 500; flex-shrink: 0; }

        /* --- CSS DO MENU RESPONSIVO --- */
        .menu-toggle { display: none; position: fixed; top: 1.5rem; left: 1.5rem; z-index: 1001; cursor: pointer; padding: 10px; background-color: var(--sidebar-color); border-radius: 8px; border: 1px solid var(--border-color); }
        .menu-toggle svg { width: 24px; height: 24px; color: var(--text-color); }

        @media (max-width: 1024px) {
            .sidebar { width: 280px; transform: translateX(-280px); box-shadow: 5px 0 15px rgba(0, 0, 0, 0.5); z-index: 1002; }
            .user-profile { margin-top: 1.5rem; position: relative; }
            .menu-toggle { display: flex; }
            .main-content { margin-left: 0; width: 100%; padding: 1.5rem; padding-top: 5rem; }
            .main-content header h1 { font-size: 1.8rem; }
            body.sidebar-open .sidebar { transform: translateX(0); }
            body.sidebar-open::after { content: ''; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.7); z-index: 1001; }
        }
        @media (max-width: 576px) {
             .main-content { padding: 1rem; padding-top: 4.5rem; }
             .stat-card { flex-direction: column; align-items: flex-start; }
             .dashboard-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <div class="menu-toggle" id="menu-toggle">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
        </svg>
    </div>

    <?php include '_sidebar_admin.php'; // Inclui a sidebar universal ?>

    <main class="main-content">
        <header>
            <h1>Dashboard Administrativo</h1>
            <p>Visão geral e estatísticas da sua plataforma.</p>
        </header>

        <section class="stats-grid">
            <div class="stat-card">
                <div class="icon-wrapper"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m-7.5-2.962a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18 18.72v-5.25A2.25 2.25 0 0015.75 11.25H12M18 18.72l-2.178-2.178m0 0a2.25 2.25 0 10-3.182-3.182m3.182 3.182L12 15.364" /></svg></div>
                <div class="stat-info"><div class="stat-number"><?php echo $total_usuarios; ?></div><div class="stat-label">Total de Usuários</div></div>
            </div>
            <div class="stat-card">
                <div class="icon-wrapper"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg></div>
                <div class="stat-info"><div class="stat-number"><?php echo $total_cursos; ?></div><div class="stat-label">Cursos Ativos</div></div>
            </div>
            <div class="stat-card">
                <div class="icon-wrapper"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.91 11.672a.375.375 0 010 .656l-5.603 3.113a.375.375 0 01-.557-.328V8.887c0-.286.307-.466.557-.327l5.603 3.112z" /></svg></div>
                <div class="stat-info"><div class="stat-number"><?php echo $total_aulas; ?></div><div class="stat-label">Total de Aulas</div></div>
            </div>
            <div class="stat-card">
                <div class="icon-wrapper"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.766z" /></svg></div>
                <div class="stat-info"><div class="stat-number"><?php echo $novos_usuarios_hoje; ?></div><div class="stat-label">Novos Usuários Hoje</div></div>
            </div>
        </section>

        <section class="chart-container">
             <canvas id="usersChart"></canvas>
        </section>

        <section class="dashboard-grid">
            <div class="info-panel">
                <h3><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="24" height="24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>Cursos Precisando de Atenção</h3>
                <ul>
                    <?php if (empty($cursos_sem_aulas)): ?>
                        <li><div class="item-main"><div class="item-subtitle">Ótimo trabalho! Todos os cursos têm aulas.</div></div></li>
                    <?php else: ?>
                        <?php foreach($cursos_sem_aulas as $curso): ?>
                        <li>
                            <div class="item-main">
                                <div class="item-title"><?php echo htmlspecialchars($curso['titulo']); ?></div>
                                <div class="item-subtitle">Este curso ainda não tem aulas.</div>
                            </div>
                            <div class="item-action">
                                <a href="cursos.php?view=lessons&curso_id=<?php echo $curso['id']; ?>">Adicionar</a>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="info-panel">
                <h3>Últimos Usuários Cadastrados</h3>
                <ul>
                    <?php foreach($ultimos_usuarios as $usuario): ?>
                        <li>
                            <div class="item-main">
                                <div class="item-title"><?php echo htmlspecialchars($usuario['nome']); ?></div>
                                <div class="item-subtitle"><?php echo htmlspecialchars($usuario['email']); ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>

    </main>

    <script>
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
        if(userProfileMenu && dropdown){
            userProfileMenu.addEventListener('click', (event) => {
                event.stopPropagation();
                dropdown.classList.toggle('show');
            });
            window.addEventListener('click', () => {
                if (dropdown.classList.contains('show')) {
                    dropdown.classList.remove('show');
                }
            });
        }

        // --- LÓGICA DO GRÁFICO ---
        const ctx = document.getElementById('usersChart').getContext('2d');
        const usersChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo $chart_labels_json; ?>,
                datasets: [{
                    label: 'Novos Usuários',
                    data: <?php echo $chart_data_json; ?>,
                    backgroundColor: 'rgba(225, 29, 72, 0.2)',
                    borderColor: 'rgba(225, 29, 72, 1)',
                    borderWidth: 2,
                    pointBackgroundColor: 'rgba(225, 29, 72, 1)',
                    pointRadius: 4,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#111827', titleColor: '#ffffff', bodyColor: '#9ca3af',
                        borderColor: 'rgba(225, 29, 72, 1)', borderWidth: 1, padding: 10, cornerRadius: 8, displayColors: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: { color: 'var(--text-muted)' }
                    },
                    x: {
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: { color: 'var(--text-muted)' }
                    }
                }
            }
        });
    });
    </script>
</body>
</html>