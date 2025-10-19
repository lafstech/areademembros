<?php

// --- PARTE 1: LÓGICA DO BACKEND ---
require_once '../config.php';
verificarAcesso('admin'); // Proteção máxima da página

$nome_usuario_admin = htmlspecialchars($_SESSION['usuario_nome']);
$feedback_message = '';
$feedback_type = '';


// --- PROCESSAMENTO DE AÇÕES (POST/GET) ---
try {
    // Ação AJAX para Bloquear/Desbloquear Usuário
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
        header('Content-Type: application/json'); // Define o cabeçalho para JSON
        $userId = $_POST['user_id'];
        $currentStatus = $_POST['current_status'];

        if ($userId == $_SESSION['usuario_id']) {
            http_response_code(403); // Forbidden
            echo json_encode(['success' => false, 'message' => 'Você não pode alterar o status da sua própria conta.']);
            exit();
        }

        $newStatus = ($currentStatus === 'ativo') ? 'bloqueado' : 'ativo';
        $stmt = $pdo->prepare("UPDATE usuarios SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $userId]);

        echo json_encode(['success' => true, 'newStatus' => $newStatus]);
        exit(); // Encerra a execução após a resposta AJAX
    }

    // Ação de Deletar (via GET)
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
        $user_id_to_delete = $_GET['id'];
        if ($user_id_to_delete == $_SESSION['usuario_id']) {
            throw new Exception("Você não pode deletar sua própria conta de administrador.");
        }
        $sql = "DELETE FROM usuarios WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id_to_delete]);
        header('Location: usuarios.php?status=deleted');
        exit();
    }

    // Ações de formulário (Adicionar, Editar, Gerenciar Acesso)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        switch ($action) {
            case 'add_edit_user':
                $user_id = $_POST['user_id'];
                $nome = trim($_POST['nome']);
                $email = trim($_POST['email']);
                $nivel_acesso = $_POST['nivel_acesso'];
                $senha = $_POST['senha'];

                if (empty($nome) || empty($email) || empty($nivel_acesso)) throw new Exception("Nome, e-mail e nível são obrigatórios.");

                if (!empty($user_id)) { // UPDATE
                    $sql = "UPDATE usuarios SET nome = ?, email = ?, nivel_acesso = ? WHERE id = ?";
                    $params = [$nome, $email, $nivel_acesso, $user_id];
                    if (!empty($senha)) {
                        $sql = "UPDATE usuarios SET nome = ?, email = ?, nivel_acesso = ?, senha = ? WHERE id = ?";
                        $params = [$nome, $email, $nivel_acesso, password_hash($senha, PASSWORD_DEFAULT), $user_id];
                    }
                    $pdo->prepare($sql)->execute($params);
                    $feedback_message = 'Usuário atualizado com sucesso!';
                } else { // INSERT
                    if (empty($senha)) throw new Exception("A senha é obrigatória para novos usuários.");
                    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) throw new Exception("Este e-mail já está cadastrado.");

                    $sql = "INSERT INTO usuarios (nome, email, senha, nivel_acesso) VALUES (?, ?, ?, ?)";
                    $pdo->prepare($sql)->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $nivel_acesso]);
                    $feedback_message = 'Novo usuário cadastrado com sucesso!';
                }
                $feedback_type = 'success';
                break;

            case 'manage_access':
                $user_id = $_POST['access_user_id'];
                $cursos_selecionados = $_POST['cursos'] ?? [];
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM usuario_cursos WHERE usuario_id = ?")->execute([$user_id]);
                if (!empty($cursos_selecionados)) {
                    $sql = "INSERT INTO usuario_cursos (usuario_id, curso_id) VALUES (?, ?)";
                    $stmt = $pdo->prepare($sql);
                    foreach ($cursos_selecionados as $curso_id) {
                        $stmt->execute([$user_id, $curso_id]);
                    }
                }
                $pdo->commit();
                $feedback_message = 'Acessos do usuário atualizados com sucesso!';
                $feedback_type = 'success';
                break;
        }
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $feedback_message = 'Erro: ' . $e->getMessage();
    $feedback_type = 'error';
}

if (isset($_GET['status']) && $_GET['status'] === 'deleted') {
    $feedback_message = 'Usuário deletado com sucesso!';
    $feedback_type = 'success';
}

// --- BUSCA DE DADOS DO BANCO ---
$usuarios = $pdo->query("SELECT * FROM usuarios ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
$cursos_disponiveis = $pdo->query("SELECT id, titulo FROM cursos ORDER BY titulo ASC")->fetchAll(PDO::FETCH_ASSOC);
$acessos_raw = $pdo->query("SELECT usuario_id, curso_id FROM usuario_cursos")->fetchAll(PDO::FETCH_ASSOC);
$acessos_por_usuario = [];
foreach ($acessos_raw as $acesso) {
    $acessos_por_usuario[$acesso['usuario_id']][] = (int)$acesso['curso_id'];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Usuários - Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #e11d48;
            --background-color: #111827;
            --sidebar-color: #1f2937;
            --glass-background: rgba(31, 41, 55, 0.5);
            --text-color: #f9fafb;
            --text-muted: #9ca3af;
            --border-color: rgba(255, 255, 255, 0.1);
            --success-color: #22c55e;
            --info-color: #3b82f6;
            --red-color: #ef4444;
            --yellow-color: #f59e0b;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--background-color); color: var(--text-color); display: flex; }
        .sidebar { width: 260px; background-color: var(--sidebar-color); height: 100vh; position: fixed; padding: 2rem 1.5rem; display: flex; flex-direction: column; border-right: 1px solid var(--border-color); z-index: 10; transition: transform 0.3s ease; }
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
        .main-content { margin-left: 260px; flex-grow: 1; padding: 2rem 3rem; height: 100vh; overflow-y: auto; transition: margin-left 0.3s ease; }
        .main-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem; }
        .main-header h1 { font-size: 2rem; font-weight: 600; }
        .btn { padding: 0.6rem 1.2rem; border: none; border-radius: 8px; font-weight: 500; font-size: 0.9rem; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .btn-primary { background-color: var(--primary-color); color: #fff; }
        .btn-primary:hover { filter: brightness(1.2); }
        .btn-secondary { background-color: var(--glass-background); color: var(--text-muted); border: 1px solid var(--border-color); }
        .btn-secondary:hover { background-color: var(--border-color); color: var(--text-color); }
        .feedback-message { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 500; border: 1px solid transparent; }
        .feedback-message.success { background-color: rgba(34, 197, 94, 0.1); border-color: rgba(34, 197, 94, 0.4); color: var(--success-color); }
        .feedback-message.error { background-color: rgba(225, 29, 72, 0.1); border-color: rgba(225, 29, 72, 0.4); color: var(--primary-color); }

        /* --- ESTILOS PARA FILTROS --- */
        .filter-bar { display: flex; gap: 1rem; margin-bottom: 1.5rem; background-color: var(--glass-background); padding: 1rem; border-radius: 12px; border: 1px solid var(--border-color); flex-wrap: wrap; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 0.5rem; }
        .filter-group label { font-size: 0.8rem; font-weight: 500; color: var(--text-muted); }
        .filter-bar input[type="text"], .filter-bar input[type="date"] { background-color: var(--background-color); border: 1px solid var(--border-color); color: var(--text-color); padding: 0.6rem 1rem; border-radius: 8px; font-family: inherit; font-size: 0.9rem; }

        .table-container { background: var(--glass-background); border: 1px solid var(--border-color); border-radius: 12px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 1rem 1.5rem; text-align: left; white-space: nowrap; }
        thead { background-color: rgba(0,0,0,0.2); }
        th { font-weight: 600; font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); }
        tbody tr { border-bottom: 1px solid var(--border-color); transition: background-color 0.2s; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background-color: rgba(255,255,255,0.03); }
        .user-info-cell { display: flex; align-items: center; gap: 1rem; }
        .user-info-cell .avatar { width: 40px; height: 40px; border-radius: 50%; background-color: var(--sidebar-color); display: flex; align-items: center; justify-content: center; font-weight: 600; flex-shrink: 0; }
        .user-info-cell .user-name { font-weight: 500; }
        .user-info-cell .user-email { font-size: 0.85rem; color: var(--text-muted); }
        .badge { padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; text-transform: capitalize; }
        .badge.admin { background-color: rgba(225, 29, 72, 0.2); color: var(--primary-color); }
        .badge.membro { background-color: rgba(59, 130, 246, 0.2); color: var(--info-color); }

        /* --- BADGES DE STATUS --- */
        .badge.ativo { background-color: rgba(34, 197, 94, 0.2); color: var(--success-color); }
        .badge.bloqueado { background-color: rgba(245, 158, 11, 0.2); color: var(--yellow-color); }
        .access-link { color: var(--primary-color); text-decoration: none; font-weight: 500; cursor: pointer; }
        .actions-cell { display: flex; gap: 0.5rem; align-items: center; }
        .actions-cell button, .actions-cell a { background: none; border: none; cursor: pointer; padding: 0.5rem; display: flex; align-items: center; justify-content: center; }
        .actions-cell svg { width: 20px; height: 20px; color: var(--text-muted); transition: color 0.3s; }
        .actions-cell button:hover svg, .actions-cell a:hover svg { color: var(--primary-color); }

        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(5px); z-index: 100; display: none; justify-content: center; align-items: center; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .modal-overlay.show { display: flex; }
        .modal-content { background-color: var(--sidebar-color); border: 1px solid var(--border-color); border-radius: 12px; width: 90%; max-width: 500px; padding: 2rem; position: relative; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .modal-header h2 { font-size: 1.5rem; }
        .close-modal { background: none; border: none; color: var(--text-muted); font-size: 1.5rem; cursor: pointer; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-size: 0.9rem; font-weight: 500; }
        .form-group input, .form-group select { width: 100%; padding: 0.75rem; background-color: var(--background-color); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-color); font-size: 1rem; }
        .modal-footer { margin-top: 2rem; text-align: right; }
        .checkbox-group { display: flex; flex-direction: column; gap: 0.75rem; max-height: 200px; overflow-y: auto; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 8px; }
        .checkbox-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem; }

        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); width: 280px; }
            .main-content { margin-left: 0; }
            .menu-toggle { display: flex; } /* Mostra o botão hambúrguer */
        }
        @media (max-width: 768px) {
            .main-content { padding: 1.5rem; padding-top: 6rem; }
            .filter-bar { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>

    <button class="menu-toggle" id="menu-toggle">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="24" height="24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
    </button>

    <aside class="sidebar">
        <div class="logo">Admin<span>Panel</span></div>
        <nav>
            <a href="index.php">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>
                <span>Dashboard</span>
            </a>
            <a href="usuarios.php" class="active">
                 <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-4.663M12 12.375a3.75 3.75 0 100-7.5 3.75 3.75 0 000 7.5z" /></svg>
                <span>Usuários</span>
            </a>
            <a href="cursos.php">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
                <span>Cursos</span>
            </a>
        </nav>
        <div class="user-profile" id="user-profile-menu">
             <div class="avatar"><?php echo strtoupper(substr($nome_usuario_admin, 0, 2)); ?></div>
            <div class="user-info"> <div class="user-name"><?php echo $nome_usuario_admin; ?></div> <div class="user-level">Administrador</div> </div>
            <div class="profile-dropdown" id="profile-dropdown"> <a href="../logout.php"><span>Sair</span></a> </div>
        </div>
    </aside>

    <main class="main-content">
        <header class="main-header">
            <h1>Gerenciar Usuários</h1>
            <button class="btn btn-primary" id="add-user-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M6 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0zm4 8c0 1-1 1-1 1H1s-1 0-1-1 1-4 6-4 6 3 6 4zm-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10c-2.29 0-3.516.68-4.168 1.332-.678.678-.83 1.418-.832 1.664h10z"/><path d="M13.5 5a.5.5 0 0 1 .5.5V7h1.5a.5.5 0 0 1 0 1H14v1.5a.5.5 0 0 1-1 0V8h-1.5a.5.5 0 0 1 0-1H13V5.5a.5.5 0 0 1 .5-.5z"/></svg>
                <span>Adicionar Usuário</span>
            </button>
        </header>

        <?php if ($feedback_message): ?>
            <div class="feedback-message <?php echo $feedback_type; ?>">
                <?php echo htmlspecialchars($feedback_message); ?>
            </div>
        <?php endif; ?>

        <div class="filter-bar">
            <div class="filter-group" style="flex-grow: 1;">
                <label for="search-input">Buscar por nome ou e-mail</label>
                <input type="text" id="search-input" placeholder="Digite para buscar...">
            </div>
            <div class="filter-group">
                <label for="date-start">Data de Início</label>
                <input type="date" id="date-start">
            </div>
            <div class="filter-group">
                <label for="date-end">Data Final</label>
                <input type="date" id="date-end">
            </div>
            <div class="filter-group">
                 <button class="btn btn-secondary" id="clear-filters-btn" style="white-space: nowrap;">Limpar Filtros</button>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Usuário</th>
                        <th>Nível</th>
                        <th>Status</th> <th>Acesso a Cursos</th>
                        <th>Data de Cadastro</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody id="users-table-body">
                    <?php foreach ($usuarios as $usuario): ?>
                        <tr data-user-id="<?php echo $usuario['id']; ?>"
                            data-user-name="<?php echo htmlspecialchars(strtolower($usuario['nome'])); ?>"
                            data-user-email="<?php echo htmlspecialchars(strtolower($usuario['email'])); ?>"
                            data-user-level="<?php echo $usuario['nivel_acesso']; ?>"
                            data-user-status="<?php echo $usuario['status']; ?>"
                            data-user-created="<?php echo $usuario['data_criacao']; ?>"
                            data-raw-name="<?php echo htmlspecialchars($usuario['nome']); ?>"
                            data-raw-email="<?php echo htmlspecialchars($usuario['email']); ?>"
                            data-user-accesses='<?php echo json_encode($acessos_por_usuario[$usuario['id']] ?? []); ?>'>
                            <td>
                                <div class="user-info-cell">
                                    <div class="avatar"><?php echo strtoupper(substr($usuario['nome'], 0, 2)); ?></div>
                                    <div>
                                        <div class="user-name"><?php echo htmlspecialchars($usuario['nome']); ?></div>
                                        <div class="user-email"><?php echo htmlspecialchars($usuario['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge <?php echo $usuario['nivel_acesso']; ?>"><?php echo $usuario['nivel_acesso']; ?></span></td>
                            <td><span class="badge <?php echo $usuario['status']; ?>"><?php echo $usuario['status']; ?></span></td>
                            <td>
                                <?php $num_acessos = count($acessos_por_usuario[$usuario['id']] ?? []); ?>
                                <a href="#" class="access-link manage-access-btn"><?php echo $num_acessos; ?> Cursos</a>
                            </td>
                            <td><?php echo date("d/m/Y", strtotime($usuario['data_criacao'])); ?></td>
                            <td class="actions-cell">
                                <button class="toggle-status-btn" title="<?php echo $usuario['status'] === 'ativo' ? 'Bloquear Usuário' : 'Desbloquear Usuário'; ?>" data-user-id="<?php echo $usuario['id']; ?>" data-current-status="<?php echo $usuario['status']; ?>">
                                    <?php if ($usuario['status'] === 'ativo'): ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                                    <?php else: ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                                    <?php endif; ?>
                                </button>
                                <button class="edit-user-btn" title="Editar Usuário"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125" /></svg></button>
                                <a href="usuarios.php?action=delete&id=<?php echo $usuario['id']; ?>" class="delete-user-btn" title="Deletar Usuário" onclick="return confirm('Tem certeza que deseja deletar este usuário? Esta ação é irreversível.');">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="modal-overlay" id="user-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="modal-title">Adicionar Novo Usuário</h2>
                    <button type="button" class="close-modal" data-target="user-modal">&times;</button>
                </div>
                <form id="user-form" method="POST">
                    <input type="hidden" name="action" value="add_edit_user">
                    <input type="hidden" name="user_id" id="user_id">
                    <div class="form-group">
                        <label for="nome">Nome Completo</label>
                        <input type="text" id="nome" name="nome" required>
                    </div>
                    <div class="form-group">
                        <label for="email">E-mail</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="senha">Senha</label>
                        <input type="password" id="senha" name="senha">
                        <small style="color:var(--text-muted); display:none;">Deixe em branco para não alterar a senha existente.</small>
                    </div>
                    <div class="form-group">
                        <label for="nivel_acesso">Nível de Acesso</label>
                        <select id="nivel_acesso" name="nivel_acesso" required>
                            <option value="membro">Membro</option>
                            <option value="admin">Administrador</option>
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary" id="modal-submit-btn">Salvar</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal-overlay" id="access-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="access-modal-title">Gerenciar Acesso</h2>
                    <button type="button" class="close-modal" data-target="access-modal">&times;</button>
                </div>
                <form id="access-form" method="POST">
                    <input type="hidden" name="action" value="manage_access">
                    <input type="hidden" name="access_user_id" id="access_user_id">
                    <div class="form-group">
                        <label>Selecione os cursos que este usuário terá acesso:</label>
                        <div class="checkbox-group" id="courses-checkbox-list">
                            <?php if (empty($cursos_disponiveis)): ?>
                                <p style="color:var(--text-muted)">Nenhum curso encontrado para atribuir.</p>
                            <?php else: ?>
                                <?php foreach ($cursos_disponiveis as $curso): ?>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="cursos[]" value="<?php echo $curso['id']; ?>">
                                    <span><?php echo htmlspecialchars($curso['titulo']); ?></span>
                                </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">Salvar Acessos</button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const userProfileMenu = document.getElementById('user-profile-menu');
        const profileDropdown = document.getElementById('profile-dropdown');
        const userModal = document.getElementById('user-modal');
        const accessModal = document.getElementById('access-modal');
        const menuToggle = document.getElementById('menu-toggle');
        const sidebar = document.querySelector('.sidebar');
        const body = document.body;

        // Lógica do Menu Dropdown do Perfil
        if (userProfileMenu) {
            userProfileMenu.addEventListener('click', (event) => {
                event.stopPropagation();
                profileDropdown.classList.toggle('show');
            });
            window.addEventListener('click', () => {
                if (profileDropdown.classList.contains('show')) {
                    profileDropdown.classList.remove('show');
                }
            });
        }

        // Lógica do Menu Hambúrguer (Mobile)
        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', () => {
                sidebar.style.transform = 'translateX(0)';
            });
            document.addEventListener('click', (e) => {
                if (!sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.style.transform = 'translateX(-100%)';
                }
            });
        }

        // --- LÓGICA DE FILTRAGEM E AÇÕES ---
        const searchInput = document.getElementById('search-input');
        const dateStartInput = document.getElementById('date-start');
        const dateEndInput = document.getElementById('date-end');
        const clearFiltersBtn = document.getElementById('clear-filters-btn');
        const usersTableBody = document.getElementById('users-table-body');
        const allRows = Array.from(usersTableBody.getElementsByTagName('tr'));

        function filterUsers() {
            const searchTerm = searchInput.value.toLowerCase();
            const startDate = dateStartInput.value ? new Date(dateStartInput.value) : null;
            const endDate = dateEndInput.value ? new Date(dateEndInput.value) : null;

            if(startDate) startDate.setUTCHours(0, 0, 0, 0);
            if(endDate) endDate.setUTCHours(23, 59, 59, 999);

            allRows.forEach(row => {
                const userName = row.dataset.userName;
                const userEmail = row.dataset.userEmail;
                const userCreatedDate = new Date(row.dataset.userCreated);

                const matchesSearch = userName.includes(searchTerm) || userEmail.includes(searchTerm);

                let matchesDate = true;
                if (startDate && endDate) {
                    matchesDate = userCreatedDate >= startDate && userCreatedDate <= endDate;
                } else if (startDate) {
                    matchesDate = userCreatedDate >= startDate;
                } else if (endDate) {
                    matchesDate = userCreatedDate <= endDate;
                }

                row.style.display = (matchesSearch && matchesDate) ? '' : 'none';
            });
        }

        searchInput.addEventListener('input', filterUsers);
        dateStartInput.addEventListener('change', filterUsers);
        dateEndInput.addEventListener('change', filterUsers);

        clearFiltersBtn.addEventListener('click', () => {
            searchInput.value = '';
            dateStartInput.value = '';
            dateEndInput.value = '';
            filterUsers();
        });

        // --- LÓGICA PARA BLOQUEAR/DESBLOQUEAR USUÁRIO (AJAX) ---
        usersTableBody.addEventListener('click', function(event) {
            const toggleBtn = event.target.closest('.toggle-status-btn');
            if (toggleBtn) {
                const userId = toggleBtn.dataset.userId;
                const currentStatus = toggleBtn.dataset.currentStatus;

                if (!confirm(`Tem certeza que deseja ${currentStatus === 'ativo' ? 'BLOQUEAR' : 'DESBLOQUEAR'} este usuário?`)) {
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'toggle_status');
                formData.append('user_id', userId);
                formData.append('current_status', currentStatus);

                fetch('usuarios.php', { method: 'POST', body: formData })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(err => { throw new Error(err.message) });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        const row = toggleBtn.closest('tr');
                        const statusBadge = row.querySelector('.badge.' + currentStatus);

                        statusBadge.textContent = data.newStatus;
                        statusBadge.classList.remove(currentStatus);
                        statusBadge.classList.add(data.newStatus);

                        toggleBtn.dataset.currentStatus = data.newStatus;
                        row.dataset.userStatus = data.newStatus;

                        if (data.newStatus === 'ativo') {
                            toggleBtn.title = 'Bloquear Usuário';
                            toggleBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>`;
                        } else {
                            toggleBtn.title = 'Desbloquear Usuário';
                            toggleBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>`;
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Ocorreu um erro: ' + error.message);
                });
            }
        });

        // --- LÓGICA DOS MODAIS (SEU CÓDIGO ORIGINAL) ---
        const openModal = (modal) => modal.classList.add('show');
        const closeModal = (modal) => modal.classList.remove('show');

        document.getElementById('add-user-btn').addEventListener('click', () => {
            const form = document.getElementById('user-form');
            form.reset();
            document.getElementById('user_id').value = '';
            document.getElementById('modal-title').textContent = 'Adicionar Novo Usuário';
            document.getElementById('modal-submit-btn').textContent = 'Salvar Usuário';
            document.querySelector('#senha + small').style.display = 'none';
            document.getElementById('senha').required = true;
            openModal(userModal);
        });

        document.querySelectorAll('.edit-user-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const row = e.target.closest('tr');
                document.getElementById('user_id').value = row.dataset.userId;
                document.getElementById('nome').value = row.dataset.rawName;
                document.getElementById('email').value = row.dataset.rawEmail;
                document.getElementById('nivel_acesso').value = row.dataset.userLevel;
                document.getElementById('modal-title').textContent = 'Editar Usuário';
                document.getElementById('modal-submit-btn').textContent = 'Atualizar Usuário';
                document.querySelector('#senha + small').style.display = 'block';
                document.getElementById('senha').required = false;
                openModal(userModal);
            });
        });

        document.querySelectorAll('.manage-access-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const row = e.target.closest('tr');
                const userName = row.dataset.rawName;
                const userId = row.dataset.userId;
                const userAccesses = JSON.parse(row.dataset.userAccesses).map(Number);
                document.getElementById('access-modal-title').textContent = `Gerenciar Acesso de ${userName}`;
                document.getElementById('access_user_id').value = userId;
                document.querySelectorAll('#courses-checkbox-list input[type="checkbox"]').forEach(cb => {
                    cb.checked = userAccesses.includes(parseInt(cb.value));
                });
                openModal(accessModal);
            });
        });

        document.querySelectorAll('.close-modal, .modal-overlay').forEach(el => {
            el.addEventListener('click', (e) => {
                if (e.target === el) {
                    const modalToClose = el.closest('.modal-overlay');
                    if (modalToClose) closeModal(modalToClose);
                }
            });
        });
    });
    </script>
</body>
</html>