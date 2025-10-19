<?php
// admin/financascursos.php
declare(strict_types=1);

require_once '../config.php';
verificarAcesso('admin'); // Proteção para administradores

$nome_usuario = htmlspecialchars($_SESSION['usuario_nome']);
$pagina_atual = basename($_SERVER['PHP_SELF']); // Identifica a página atual para o menu

// Variáveis para mensagens de feedback
$successMessage = null;
$errorMessage = null;

// ===================================================================
// === LÓGICA DE ATUALIZAÇÃO (Executada apenas em requisições POST) ===
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        // --- AÇÃO: ATUALIZAR O PLANO DE ACESSO TOTAL ---
        if ($action === 'update_plan') {
            $plano_id = (int)($_POST['plano_id'] ?? 0);
            $nome = trim((string)($_POST['nome'] ?? ''));
            $descricao = trim((string)($_POST['descricao'] ?? ''));
            $valor = str_replace(',', '.', (string)($_POST['valor'] ?? '0'));
            $valor = (float)filter_var($valor, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

            if ($plano_id > 0 && !empty($nome) && $valor >= 0) {
                $stmt = $pdo->prepare("UPDATE planos SET nome = ?, descricao = ?, valor = ? WHERE id = ?");
                $stmt->execute([$nome, $descricao, $valor, $plano_id]);
                $successMessage = "Plano de Acesso Total atualizado com sucesso!";
            } else {
                throw new Exception("Dados inválidos para atualizar o plano.");
            }
        }

        // --- AÇÃO: ATUALIZAR OS VALORES DOS CURSOS ---
        if ($action === 'update_courses') {
            $valores = $_POST['valores'] ?? [];
            if (!empty($valores)) {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE cursos SET valor = ? WHERE id = ?");
                foreach ($valores as $curso_id => $novo_valor) {
                    $curso_id_sanitized = (int)$curso_id;
                    $novo_valor_sanitized = str_replace(',', '.', (string)$novo_valor);
                    $novo_valor_sanitized = (float)filter_var($novo_valor_sanitized, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                    if ($curso_id_sanitized > 0 && $novo_valor_sanitized >= 0) {
                        $stmt->execute([$novo_valor_sanitized, $curso_id_sanitized]);
                    }
                }
                $pdo->commit();
                $successMessage = "Valores dos cursos atualizados com sucesso!";
            } else {
                throw new Exception("Nenhum valor de curso foi enviado.");
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errorMessage = "Ocorreu um erro: " . $e->getMessage();
    }
}

// =====================================================================
// === LÓGICA DE EXIBIÇÃO (Sempre busca os dados mais recentes) ===
// =====================================================================
$stmt_plano = $pdo->prepare("SELECT * FROM planos WHERE tipo_acesso = 'TODOS_CURSOS' LIMIT 1");
$stmt_plano->execute();
$plano_acesso_total = $stmt_plano->fetch(PDO::FETCH_ASSOC);

$stmt_cursos = $pdo->prepare("SELECT id, titulo, valor FROM cursos ORDER BY titulo ASC");
$stmt_cursos->execute();
$todos_cursos = $stmt_cursos->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finanças dos Cursos - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* === CSS COMPLETO DO ADMIN DASHBOARD === */
        :root { --primary-color: #e11d48; --background-color: #111827; --sidebar-color: #1f2937; --glass-background: rgba(31, 41, 55, 0.5); --text-color: #f9fafb; --text-muted: #9ca3af; --border-color: rgba(255, 255, 255, 0.1); --success-color: #22c55e; --error-color: #f87171; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--background-color); color: var(--text-color); display: flex; }
        .sidebar { width: 260px; background-color: var(--sidebar-color); height: 100vh; position: fixed; padding: 2rem 1.5rem; display: flex; flex-direction: column; border-right: 1px solid var(--border-color); z-index: 10; }
        .sidebar .logo { font-size: 1.5rem; font-weight: 700; margin-bottom: 3rem; text-align: center; }
        .sidebar .logo span { color: var(--primary-color); }
        .sidebar nav { flex-grow: 1; }
        .sidebar nav a { display: flex; align-items: center; gap: 1rem; padding: 1rem; color: var(--text-muted); text-decoration: none; border-radius: 8px; margin-bottom: 0.5rem; transition: all 0.3s ease; }
        .sidebar nav a:hover, .sidebar nav a.active { background-color: var(--glass-background); color: var(--text-color); }
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
        .main-content { margin-left: 260px; flex-grow: 1; padding: 2rem 3rem; height: 100vh; overflow-y: auto; }
        .main-content header { margin-bottom: 2.5rem; }
        .main-content header h1 { font-size: 2rem; font-weight: 600; }
        .main-content header p { color: var(--text-muted); }

        /* ESTILOS ESPECÍFICOS PARA ESTA PÁGINA */
        .management-card { background: var(--glass-background); border: 1px solid var(--border-color); border-radius: 12px; padding: 2rem; margin-bottom: 2rem; }
        .management-card h2 { font-size: 1.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; }
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 0.5rem; }
        .form-group input, .form-group textarea { width: 100%; padding: 0.75rem 1rem; background-color: rgba(0,0,0,0.3); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-color); font-size: 1rem; font-family: 'Poppins', sans-serif; }
        .form-group textarea { resize: vertical; min-height: 100px; }
        .form-group input:focus, .form-group textarea:focus { border-color: var(--primary-color); outline: none; }
        .btn-save { padding: 0.8rem 2rem; background-color: var(--primary-color); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 1rem; transition: background-color 0.3s; }
        .btn-save:hover { background-color: #c01a3f; }
        .table-wrapper { overflow-x: auto; }
        .table-cursos { width: 100%; border-collapse: collapse; }
        .table-cursos th, .table-cursos td { padding: 1rem; text-align: left; border-bottom: 1px solid var(--border-color); }
        .table-cursos th { font-weight: 600; }
        .table-cursos td input { width: 120px; text-align: right; }
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 600; }
        .alert-success { background-color: rgba(34, 197, 94, 0.2); color: var(--success-color); }
        .alert-error { background-color: rgba(248, 113, 113, 0.2); color: var(--error-color); }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="logo">Admin<span>Panel</span></div>
        <nav>
            <a href="index.php" class="<?php echo ($pagina_atual === 'index.php') ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>
                <span>Dashboard</span>
            </a>
            <a href="usuarios.php" class="<?php echo ($pagina_atual === 'usuarios.php') ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-4.663M12 12.375a3.75 3.75 0 100-7.5 3.75 3.75 0 000 7.5z" /></svg>
                <span>Usuários</span>
            </a>
            <a href="cursos.php" class="<?php echo ($pagina_atual === 'cursos.php') ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
                <span>Cursos</span>
            </a>
            <a href="financascursos.php" class="<?php echo ($pagina_atual === 'financascursos.php') ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                <span>Finanças Cursos</span>
            </a>
        </nav>
        <div class="user-profile" id="user-profile-menu">
            <div class="avatar"><?php echo strtoupper(substr($nome_usuario, 0, 2)); ?></div>
            <div class="user-info">
                <div class="user-name"><?php echo $nome_usuario; ?></div>
                <div class="user-level">Administrador</div>
            </div>
            <div class="profile-dropdown" id="profile-dropdown">
                <a href="logout.php"><span>Sair</span></a>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <header>
            <h1>Finanças dos Cursos</h1>
            <p>Gerencie os preços dos produtos disponíveis na sua plataforma.</p>
        </header>

        <?php if ($successMessage): ?><div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div><?php endif; ?>
        <?php if ($errorMessage): ?><div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div><?php endif; ?>

        <section class="management-card">
            <h2>Gerenciar Plano de Acesso Total</h2>
            <?php if ($plano_acesso_total): ?>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_plan">
                    <input type="hidden" name="plano_id" value="<?php echo $plano_acesso_total['id']; ?>">
                    <div class="form-group"><label for="plano-nome">Nome do Plano</label><input type="text" id="plano-nome" name="nome" value="<?php echo htmlspecialchars($plano_acesso_total['nome']); ?>" required></div>
                    <div class="form-group"><label for="plano-descricao">Descrição</label><textarea id="plano-descricao" name="descricao"><?php echo htmlspecialchars($plano_acesso_total['descricao']); ?></textarea></div>
                    <div class="form-group"><label for="plano-valor">Valor (R$)</label><input type="text" id="plano-valor" name="valor" value="<?php echo number_format((float)$plano_acesso_total['valor'], 2, ',', '.'); ?>" required></div>
                    <button type="submit" class="btn-save">Salvar Alterações do Plano</button>
                </form>
            <?php else: ?>
                <p>Nenhum plano de acesso total encontrado. Crie um na base de dados.</p>
            <?php endif; ?>
        </section>

        <section class="management-card">
            <h2>Gerenciar Cursos Individuais</h2>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_courses">
                <div class="table-wrapper">
                    <table class="table-cursos">
                        <thead><tr><th>Nome do Curso</th><th>Valor (R$)</th></tr></thead>
                        <tbody>
                            <?php foreach ($todos_cursos as $curso): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($curso['titulo']); ?></td>
                                    <td><input type="text" name="valores[<?php echo $curso['id']; ?>]" value="<?php echo number_format((float)$curso['valor'], 2, ',', '.'); ?>" required></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="submit" class="btn-save" style="margin-top: 1.5rem;">Salvar Valores dos Cursos</button>
            </form>
        </section>
    </main>

    <script>
        const userProfileMenu = document.getElementById('user-profile-menu');
        if(userProfileMenu){
            const dropdown = document.getElementById('profile-dropdown');
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
    </script>
</body>
</html>