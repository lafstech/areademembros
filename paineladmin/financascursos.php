<?php
// admin/financascursos.php - Versão Completa com Dashboard e Gerenciamento

declare(strict_types=1);

require_once '../config.php';
verificarAcesso('admin'); // Proteção para administradores

$nome_usuario = htmlspecialchars($_SESSION['usuario_nome']);
$pagina_atual = basename($_SERVER['PHP_SELF']); // Identifica a página atual para o menu

$successMessage = null;
$errorMessage = null;

// ===================================================================
// === LÓGICA DE ATUALIZAÇÃO (POST) ===
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        // --- AÇÃO: CRIAR OU ATUALIZAR PLANO DE ACESSO TOTAL ---
        if ($action === 'manage_plan') {
            $plano_id = (int)($_POST['plano_id'] ?? 0);
            $nome = trim((string)($_POST['nome'] ?? ''));
            $descricao = trim((string)($_POST['descricao'] ?? ''));
            $valor_brl = (string)($_POST['valor'] ?? '0');
            $valor_sql = str_replace('.', '', $valor_brl); $valor_sql = str_replace(',', '.', $valor_sql);
            $valor = (float)filter_var($valor_sql, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

            if (empty($nome) || $valor < 0) { throw new Exception("Nome e valor válido são obrigatórios para o plano."); }

            if ($plano_id > 0) { // ATUALIZAR PLANO EXISTENTE
                $stmt = $pdo->prepare("UPDATE planos SET nome = ?, descricao = ?, valor = ? WHERE id = ? AND tipo_acesso = 'TODOS_CURSOS'");
                $stmt->execute([$nome, $descricao, $valor, $plano_id]);
                $successMessage = "Plano de Acesso Total atualizado com sucesso!";
            } else { // CRIAR NOVO PLANO
                $stmt = $pdo->prepare("INSERT INTO planos (nome, descricao, valor, tipo_acesso) VALUES (?, ?, ?, 'TODOS_CURSOS')");
                $stmt->execute([$nome, $descricao, $valor]);
                $successMessage = "Plano de Acesso Total criado com sucesso!";
            }
        }

        // ⭐ NOVO: AÇÃO DE DELETAR PLANO
        elseif ($action === 'delete_plan') {
            $plano_id_del = (int)($_POST['plano_id_del'] ?? 0);
            if ($plano_id_del > 0) {
                // Opcional: Adicionar verificação se há vendas ativas neste plano antes de deletar
                // $check = $pdo->prepare("SELECT COUNT(id) FROM pedidos WHERE plano_id = ? AND status = 'APROVADO'");
                // $check->execute([$plano_id_del]);
                // if ($check->fetchColumn() > 0) {
                //     throw new Exception("Não é possível deletar este plano, pois existem vendas ativas associadas a ele.");
                // }

                $stmt = $pdo->prepare("DELETE FROM planos WHERE id = ? AND tipo_acesso = 'TODOS_CURSOS'");
                $stmt->execute([$plano_id_del]);

                if ($stmt->rowCount() > 0) {
                    $successMessage = "Plano deletado com sucesso!";
                } else {
                    throw new Exception("Plano não encontrado ou não pôde ser deletado.");
                }
            } else {
                throw new Exception("ID do plano inválido para deleção.");
            }
        }

        // --- AÇÃO: ATUALIZAR OS VALORES DOS CURSOS ---
        elseif ($action === 'update_courses') {
            $valores = $_POST['valores'] ?? [];
            if (!empty($valores)) {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE cursos SET valor = ? WHERE id = ?");
                foreach ($valores as $curso_id => $novo_valor_brl) {
                    $curso_id_sanitized = (int)$curso_id;
                    $novo_valor_sql = str_replace('.', '', $novo_valor_brl); $novo_valor_sql = str_replace(',', '.', $novo_valor_sql);
                    $novo_valor_sanitized = (float)filter_var($novo_valor_sql, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
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
// === LÓGICA DE EXIBIÇÃO (BUSCA DE DADOS) ===
// =====================================================================

// --- DADOS PARA OS CARDS DE STATS ---
$stats_aprovado = $pdo->query("
    SELECT COALESCE(SUM(valor), 0) as total_arrecadado, COUNT(id) as total_vendas
    FROM pedidos WHERE status = 'APROVADO'
")->fetch(PDO::FETCH_ASSOC);

$stats_pendente = $pdo->query("
    SELECT COALESCE(SUM(valor), 0) as valor_pendente, COUNT(id) as total_pendentes
    FROM pedidos WHERE status = 'PENDENTE'
")->fetch(PDO::FETCH_ASSOC);

$total_planos_criados = $pdo->query("SELECT COUNT(id) FROM planos WHERE tipo_acesso = 'TODOS_CURSOS'")->fetchColumn();

// --- DADOS PARA OS FORMULÁRIOS DE GERENCIAMENTO ---
// Busca o *primeiro* plano para pré-popular o formulário da direita
$plano_acesso_total = $pdo->query("SELECT * FROM planos WHERE tipo_acesso = 'TODOS_CURSOS' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// ⭐ NOVO: Busca *TODOS* os planos para o modal de gerenciamento
$todos_planos = $pdo->query("
    SELECT * FROM planos
    WHERE tipo_acesso = 'TODOS_CURSOS'
    ORDER BY id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$todos_cursos = $pdo->query("SELECT id, titulo, valor FROM cursos ORDER BY titulo ASC")->fetchAll(PDO::FETCH_ASSOC);

// --- DADOS PARA TABELAS DE VENDAS ---
// Vendas por Curso (Aprovadas)
$vendas_por_curso = $pdo->query("
    SELECT c.titulo, COUNT(p.id) as num_vendas, SUM(p.valor) as total_valor
    FROM pedidos p JOIN cursos c ON p.curso_id = c.id
    WHERE p.status = 'APROVADO' GROUP BY c.titulo ORDER BY total_valor DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Vendas do Plano (Aprovadas)
$vendas_plano_raw = $pdo->query("
    SELECT COUNT(p.id) as num_vendas, COALESCE(SUM(p.valor), 0) as total_valor
    FROM pedidos p WHERE p.plano_id IS NOT NULL AND p.status = 'APROVADO'
")->fetch(PDO::FETCH_ASSOC);

// Transações Aprovadas Recentes
$recent_transactions_aprovadas = $pdo->query("
    SELECT p.id, p.created_at, p.valor, u.nome as usuario_nome, u.email as usuario_email,
           COALESCE(c.titulo, pl.nome) as produto_nome
    FROM pedidos p
    JOIN usuarios u ON p.usuario_id = u.id
    LEFT JOIN cursos c ON p.curso_id = c.id
    LEFT JOIN planos pl ON p.plano_id = pl.id
    WHERE p.status = 'APROVADO'
    ORDER BY p.created_at DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Transações Pendentes Recentes
$recent_transactions_pendentes = $pdo->query("
    SELECT p.id, p.created_at, p.valor, u.nome as usuario_nome, u.email as usuario_email,
           COALESCE(c.titulo, pl.nome) as produto_nome
    FROM pedidos p
    JOIN usuarios u ON p.usuario_id = u.id
    LEFT JOIN cursos c ON p.curso_id = c.id
    LEFT JOIN planos pl ON p.plano_id = pl.id
    WHERE p.status = 'PENDENTE'
    ORDER BY p.created_at DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finanças dos Cursos - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* === CSS BASE (da index.php) === */
        :root {
            --primary-color: #e11d48; --background-color: #111827; --sidebar-color: #1f2937;
            --glass-background: rgba(31, 41, 55, 0.5); --text-color: #f9fafb; --text-muted: #9ca3af;
            --border-color: rgba(255, 255, 255, 0.1); --success-color: #22c55e; --error-color: #f87171;
            --info-color: #3b82f6; --warning-color: #f59e0b;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--background-color); color: var(--text-color); display: flex; }

        .sidebar { width: 260px; background-color: var(--sidebar-color); height: 100vh; position: fixed; left:0; top:0; padding: 2rem 1.5rem; display: flex; flex-direction: column; border-right: 1px solid var(--border-color); z-index: 1000; transition: transform 0.3s ease; }
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

        .main-content { margin-left: 260px; flex-grow: 1; padding: 2rem 3rem; height: 100vh; overflow-y: auto; transition: margin-left 0.3s ease; width: calc(100% - 260px); }
        .menu-toggle { display: none; position: fixed; top: 1.5rem; left: 1.5rem; z-index: 1001; cursor: pointer; padding: 10px; background-color: var(--sidebar-color); border-radius: 8px; border: 1px solid var(--border-color); }
        .menu-toggle svg { width: 24px; height: 24px; color: var(--text-color); }
        .main-content header { margin-bottom: 2.5rem; }
        .main-content header h1 { font-size: 2rem; font-weight: 600; }
        .main-content header p { color: var(--text-muted); }

        /* === ESTILOS ESPECÍFICOS (index.php + financascursos.php) === */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem; }
        .stat-card { background: var(--glass-background); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color); display: flex; align-items: center; gap: 1.5rem; transition: all 0.3s ease; /* ⭐ Adicionado transition */ }
        .stat-card .icon-wrapper { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: rgba(225, 29, 72, 0.1); border: 1px solid rgba(225, 29, 72, 0.3); flex-shrink: 0; }
        .stat-card .icon-wrapper svg { width: 28px; height: 28px; color: var(--primary-color); }
        .stat-info .stat-number { font-size: 2rem; font-weight: 700; line-height: 1.2; }
        .stat-info .stat-label { font-size: 0.9rem; color: var(--text-muted); }

        /* ⭐ Adicionado para card clicável de Planos */
        a.stat-card-link { text-decoration: none; color: inherit; }
        #open-planos-modal .stat-card {
            cursor: pointer;
        }
        #open-planos-modal .stat-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .dashboard-grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 1.5rem; }
        .management-card { background: var(--glass-background); border: 1px solid var(--border-color); border-radius: 12px; padding: 2rem; margin-bottom: 2rem; }
        .management-card h2 { font-size: 1.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; }
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 0.5rem; }
        .form-group input, .form-group textarea { width: 100%; padding: 0.75rem 1rem; background-color: var(--background-color); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-color); font-size: 1rem; font-family: 'Poppins', sans-serif; }
        .form-group textarea { resize: vertical; min-height: 100px; }
        .form-group input:focus, .form-group textarea:focus { border-color: var(--primary-color); outline: none; }
        .btn-save { padding: 0.8rem 2rem; background-color: var(--primary-color); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 1rem; transition: background-color 0.3s; }
        .btn-save:hover { background-color: #c01a3f; }

        .table-wrapper { overflow-x: auto; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: 0.75rem 1rem; text-align: left; border-bottom: 1px solid var(--border-color); white-space: nowrap; }
        .data-table thead { background-color: rgba(0,0,0,0.2); }
        .data-table th { font-weight: 600; font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table td .user-email { font-size: 0.85rem; color: var(--text-muted); display: block; }
        .data-table td input[type="text"] { width: 120px; text-align: right; background-color: var(--background-color); border: 1px solid var(--border-color); color: var(--text-color); padding: 0.5rem; border-radius: 6px; }

        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 600; }
        .alert-success { background-color: rgba(34, 197, 94, 0.2); color: var(--success-color); }
        .alert-error { background-color: rgba(248, 113, 113, 0.2); color: var(--error-color); }

        /* ⭐ --- CSS Modal Planos --- */
        .modal {
            display: none; /* Oculto por padrão */
            position: fixed;
            z-index: 1005; /* Acima do overlay da sidebar */
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
        }
        .modal-content {
            background-color: var(--sidebar-color);
            margin: 10% auto;
            padding: 2rem;
            border: 1px solid var(--border-color);
            width: 90%;
            max-width: 800px;
            border-radius: 12px;
            position: relative;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }
        .modal-close {
            color: var(--text-muted);
            position: absolute;
            top: 1rem;
            right: 1.5rem;
            font-size: 2rem;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }
        .modal-close:hover,
        .modal-close:focus {
            color: var(--text-color);
        }
        .modal-content h2 {
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 1rem;
        }
        .btn-edit {
            padding: 0.5rem 1rem;
            background-color: var(--info-color);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.9rem;
            text-decoration: none;
            display: inline-block;
            margin-right: 0.5rem;
            transition: background-color 0.3s;
        }
        .btn-edit:hover { background-color: #2563eb; }
        .btn-delete {
            padding: 0.5rem 1rem;
            background-color: var(--error-color);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.9rem;
            transition: background-color 0.3s;
        }
        .btn-delete:hover { background-color: #e11d48; }
        .btn-new-plan {
            padding: 0.7rem 1.5rem;
            background-color: var(--success-color);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            transition: background-color 0.3s;
            text-decoration: none;
            display: inline-block;
            margin-top: 1.5rem;
        }
        .btn-new-plan:hover { background-color: #1a9c4b; }
        .plan-actions { display: flex; align-items: center; gap: 0.5rem; }


        /* --- RESPONSIVIDADE (Unificada) --- */
        @media (max-width: 1024px) {
            .sidebar { width: 280px; transform: translateX(-280px); box-shadow: 5px 0 15px rgba(0, 0, 0, 0.5); z-index: 1002; }
            .user-profile { margin-top: 1.5rem; position: relative; }
            .menu-toggle { display: flex; }
            .main-content { margin-left: 0; width: 100%; padding: 1.5rem; padding-top: 5rem; }
            .main-content header h1 { font-size: 1.8rem; }
            body.sidebar-open .sidebar { transform: translateX(0); }
            body.sidebar-open::after { content: ''; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.7); z-index: 1001; }
        }
        @media (max-width: 992px) {
             .dashboard-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 576px) {
             .main-content { padding: 1rem; padding-top: 4.5rem; }
             .stat-card { flex-direction: column; align-items: flex-start; }
             .data-table td input[type="text"] { width: 100px; }
             .modal-content { margin: 5% auto; }
        }
    </style>
</head>
<body>

    <div class="menu-toggle" id="menu-toggle">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="24" height="24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
    </div>

    <?php include '_sidebar_admin.php'; // Inclui a sidebar universal ?>

    <main class="main-content">
        <header>
            <h1>Finanças e Vendas</h1>
            <p>Visão geral das vendas e gerenciamento de preços da plataforma.</p>
        </header>

        <?php if ($successMessage): ?><div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div><?php endif; ?>
        <?php if ($errorMessage): ?><div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div><?php endif; ?>

        <section class="stats-grid">
            <div class="stat-card">
                <div class="icon-wrapper" style="background-color: rgba(34, 197, 94, 0.1); border-color: var(--success-color);"><svg style="color: var(--success-color);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg></div>
                <div class="stat-info">
                    <div class="stat-number">R$ <?php echo number_format((float)$stats_aprovado['total_arrecadado'], 2, ',', '.'); ?></div>
                    <div class="stat-label">Total Arrecadado</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon-wrapper" style="background-color: rgba(59, 130, 246, 0.1); border-color: var(--info-color);"><svg style="color: var(--info-color);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c.51 0 .962-.343 1.087-.835l1.838-5.513c.243-.728-.364-1.415-1.118-1.415H4.5M3 7.5h16.5M7.5 18.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zM16.5 18.75a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg></div>
                <div class="stat-info"><div class="stat-number"><?php echo $stats_aprovado['total_vendas']; ?></div><div class="stat-label">Vendas Aprovadas</div></div>
            </div>
            <div class="stat-card">
                <div class="icon-wrapper" style="background-color: rgba(245, 158, 11, 0.1); border-color: var(--warning-color);"><svg style="color: var(--warning-color);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg></div>
                <div class="stat-info">
                    <div class="stat-number"><?php echo $stats_pendente['total_pendentes']; ?></div>
                    <div class="stat-label">Vendas Pendentes</div>
                    <div class="stat-label" style="font-size: 0.8rem; color: var(--warning-color);">(R$ <?php echo number_format((float)$stats_pendente['valor_pendente'], 2, ',', '.'); ?>)</div>
                </div>
            </div>

            <a href="#" class="stat-card-link" id="open-planos-modal">
                <div class="stat-card">
                    <div class="icon-wrapper"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg></div>
                    <div class="stat-info"><div class="stat-number"><?php echo $total_planos_criados; ?></div><div class="stat-label">Planos Criados</div></div>
                </div>
            </a>
        </section>

        <div class="dashboard-grid">

            <div class="left-column">
                <section class="management-card">
                    <h2>Vendas por Produto (Aprovadas)</h2>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead><tr><th>Produto</th><th>Vendas</th><th>Total (R$)</th></tr></thead>
                            <tbody>
                                <?php
                                // Combina vendas de plano e cursos para ordenação
                                $vendas_combinadas = [];
                                if ($plano_acesso_total && ($vendas_plano_raw['num_vendas'] ?? 0) > 0) {
                                    $vendas_combinadas[] = [
                                        'nome' => '<strong>' . htmlspecialchars($plano_acesso_total['nome']) . '</strong>',
                                        'num_vendas' => (int)$vendas_plano_raw['num_vendas'],
                                        'total_valor' => (float)$vendas_plano_raw['total_valor']
                                    ];
                                }
                                foreach ($vendas_por_curso as $venda) {
                                    $vendas_combinadas[] = [
                                        'nome' => htmlspecialchars($venda['titulo']),
                                        'num_vendas' => (int)$venda['num_vendas'],
                                        'total_valor' => (float)$venda['total_valor']
                                    ];
                                }
                                // Ordena pelo total_valor DESC
                                usort($vendas_combinadas, fn($a, $b) => $b['total_valor'] <=> $a['total_valor']);
                                ?>

                                <?php foreach ($vendas_combinadas as $venda): ?>
                                    <tr>
                                        <td><?php echo $venda['nome']; ?></td>
                                        <td><?php echo $venda['num_vendas']; ?></td>
                                        <td>R$ <?php echo number_format($venda['total_valor'], 2, ',', '.'); ?></td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($vendas_combinadas)): ?>
                                    <tr><td colspan="3" style="text-align: center; color: var(--text-muted);">Nenhuma venda aprovada ainda.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="management-card">
                    <h2>Últimas 5 Transações Aprovadas</h2>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead><tr><th>ID</th><th>Usuário</th><th>Produto</th><th>Valor</th><th>Data</th></tr></thead>
                            <tbody>
                                <?php foreach ($recent_transactions_aprovadas as $tx): ?>
                                <tr>
                                    <td>#<?php echo $tx['id']; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($tx['usuario_nome']); ?>
                                        <span class="user-email"><?php echo htmlspecialchars($tx['usuario_email'] ?? 'N/A'); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($tx['produto_nome']); ?></td>
                                    <td>R$ <?php echo number_format((float)$tx['valor'], 2, ',', '.'); ?></td>
                                    <td><?php echo date("d/m/Y H:i", strtotime($tx['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recent_transactions_aprovadas)): ?>
                                    <tr><td colspan="5" style="text-align: center; color: var(--text-muted);">Nenhuma transação aprovada encontrada.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="management-card">
                    <h2 style="color: var(--warning-color);">Últimas 5 Transações Pendentes</h2>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead><tr><th>ID</th><th>Usuário</th><th>Produto</th><th>Valor</th><th>Data</th></tr></thead>
                            <tbody>
                                <?php foreach ($recent_transactions_pendentes as $tx): ?>
                                <tr>
                                    <td>#<?php echo $tx['id']; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($tx['usuario_nome']); ?>
                                        <span class="user-email"><?php echo htmlspecialchars($tx['usuario_email'] ?? 'N/A'); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($tx['produto_nome']); ?></td>
                                    <td>R$ <?php echo number_format((float)$tx['valor'], 2, ',', '.'); ?></td>
                                    <td><?php echo date("d/m/Y H:i", strtotime($tx['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recent_transactions_pendentes)): ?>
                                    <tr><td colspan="5" style="text-align: center; color: var(--text-muted);">Nenhuma transação pendente encontrada.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            </div>

            <div class="right-column">
                <section class="management-card">
                    <h2>Gerenciar Plano de Acesso Total</h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: -1rem; margin-bottom: 1.5rem;">
                        Use o card "Planos Criados" para ver e editar todos os planos.
                    </p>
                    <form method="POST" action="financascursos.php" id="form-manage-plan">
                        <input type="hidden" name="action" value="manage_plan">
                        <input type="hidden" name="plano_id" value="<?php echo $plano_acesso_total['id'] ?? 0; ?>">

                        <div class="form-group">
                            <label for="plano-nome">Nome do Plano</label>
                            <input type="text" id="plano-nome" name="nome" value="<?php echo htmlspecialchars($plano_acesso_total['nome'] ?? 'Acesso Total'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="plano-descricao">Descrição</label>
                            <textarea id="plano-descricao" name="descricao"><?php echo htmlspecialchars($plano_acesso_total['descricao'] ?? 'Liberação de acesso a todos os cursos da plataforma.'); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="plano-valor">Valor (R$)</label>
                            <input type="text" id="plano-valor" name="valor" value="<?php echo number_format((float)($plano_acesso_total['valor'] ?? 197.00), 2, ',', '.'); ?>" required>
                        </div>
                        <button type="submit" class="btn-save"><?php echo $plano_acesso_total ? 'Salvar Alterações do Plano' : 'Criar Plano'; ?></button>
                    </form>
                </section>

                <section class="management-card">
                    <h2>Gerenciar Preços dos Cursos</h2>
                    <form method="POST" action="financascursos.php">
                        <input type="hidden" name="action" value="update_courses">
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead><tr><th>Curso</th><th>Valor (R$)</th></tr></thead>
                                <tbody>
                                    <?php foreach ($todos_cursos as $curso): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($curso['titulo']); ?></td>
                                            <td><input type="text" name="valores[<?php echo $curso['id']; ?>]" value="<?php echo number_format((float)$curso['valor'], 2, ',', '.'); ?>" required></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($todos_cursos)): ?>
                                        <tr><td colspan="2" style="text-align: center; color: var(--text-muted);">Nenhum curso cadastrado.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <button type="submit" class="btn-save" style="margin-top: 1.5rem;" <?php if (empty($todos_cursos)) echo 'disabled'; ?>>Salvar Valores dos Cursos</button>
                    </form>
                </section>
            </div>

        </div>
    </main>

    <div id="planos-modal" class="modal">
        <div class="modal-content">
            <span class="modal-close" id="close-planos-modal">&times;</span>
            <h2>Planos de Acesso Total Criados</h2>

            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>Valor (R$)</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($todos_planos)): ?>
                            <tr><td colspan="4" style="text-align: center; color: var(--text-muted);">Nenhum plano de acesso total foi criado ainda.</td></tr>
                        <?php else: ?>
                            <?php foreach ($todos_planos as $plano): ?>
                                <tr>
                                    <td>#<?php echo $plano['id']; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($plano['nome']); ?>
                                        <span class="user-email"><?php echo htmlspecialchars(mb_strimwidth($plano['descricao'], 0, 50, "...")); ?></span>
                                    </td>
                                    <td>R$ <?php echo number_format((float)$plano['valor'], 2, ',', '.'); ?></td>
                                    <td class="plan-actions">
                                        <button type="button" class="btn-edit btn-edit-plano"
                                            data-id="<?php echo $plano['id']; ?>"
                                            data-nome="<?php echo htmlspecialchars($plano['nome']); ?>"
                                            data-descricao="<?php echo htmlspecialchars($plano['descricao']); ?>"
                                            data-valor="<?php echo number_format((float)$plano['valor'], 2, ',', '.'); ?>">
                                            Editar
                                        </button>

                                        <form method="POST" action="financascursos.php" onsubmit="return confirm('Tem certeza que deseja deletar este plano? Esta ação não pode ser desfeita.');" style="margin: 0;">
                                            <input type="hidden" name="action" value="delete_plan">
                                            <input type="hidden" name="plano_id_del" value="<?php echo $plano['id']; ?>">
                                            <button type="submit" class="btn-delete">Deletar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <button type="button" class="btn-new-plan" id="btn-criar-novo-plano">Criar Novo Plano</button>
        </div>
    </div>


    <script>
    document.addEventListener('DOMContentLoaded', () => {
        // --- LÓGICA PADRÃO SIDEBAR/DROPDOWN (da index.php) ---
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
        const userProfileMenu = document.getElementById('user-profile-menu');
        const profileDropdown = document.getElementById('profile-dropdown');
        if(userProfileMenu && profileDropdown){
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

        // --- Lógica para formatar campos de valor como BRL ---
        function formatarCampoBRL(input) {
            let valor = input.value.replace(/\D/g, '');
            if(valor === "") valor = "0";
            valor = (parseInt(valor, 10) / 100).toFixed(2).replace('.', ',');
            let partes = valor.split(',');
            partes[0] = partes[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            input.value = partes.join(',');
        }

        document.querySelectorAll('#plano-valor, .data-table input[name^="valores["]').forEach(input => {
            if(input.value) { formatarCampoBRL(input); }
            input.addEventListener('input', (e) => {
                let originalPos = e.target.selectionStart;
                let originalLength = e.target.value.length;
                formatarCampoBRL(e.target);
                let newLength = e.target.value.length;
                e.target.setSelectionRange(originalPos + (newLength - originalLength), originalPos + (newLength - originalLength));
            });
             input.addEventListener('paste', (e) => {
                e.preventDefault();
                let text = (e.clipboardData || window.clipboardData).getData('text');
                input.value = text;
                formatarCampoBRL(input);
            });
        });

        // --- ⭐ NOVO: Lógica do Modal de Planos ---
        const modal = document.getElementById('planos-modal');
        const openBtn = document.getElementById('open-planos-modal');
        const closeBtn = document.getElementById('close-planos-modal');
        const formPlano = document.getElementById('form-manage-plan');

        if (modal && openBtn && closeBtn && formPlano) {
            // Abrir modal
            openBtn.addEventListener('click', (e) => {
                e.preventDefault();
                modal.style.display = 'block';
            });

            // Fechar modal no 'X'
            closeBtn.addEventListener('click', () => {
                modal.style.display = 'none';
            });

            // Fechar clicando fora
            window.addEventListener('click', (e) => {
                if (e.target == modal) {
                    modal.style.display = 'none';
                }
            });

            // Botão "Criar Novo Plano"
            document.getElementById('btn-criar-novo-plano').addEventListener('click', () => {
                formPlano.querySelector('input[name="plano_id"]').value = '0';
                formPlano.querySelector('input[name="nome"]').value = 'Novo Acesso Total';
                formPlano.querySelector('textarea[name="descricao"]').value = 'Liberação de acesso a todos os cursos da plataforma.';
                formPlano.querySelector('input[name="valor"]').value = '197,00'; // Valor padrão formatado
                formPlano.querySelector('button[type="submit"]').textContent = 'Criar Plano';

                modal.style.display = 'none'; // Fecha o modal
                formPlano.querySelector('input[name="nome"]').focus(); // Foca no formulário
                formPlano.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });

            // Botões "Editar" dentro do modal
            document.querySelectorAll('.btn-edit-plano').forEach(button => {
                button.addEventListener('click', (e) => {
                    const id = e.target.getAttribute('data-id');
                    const nome = e.target.getAttribute('data-nome');
                    const descricao = e.target.getAttribute('data-descricao');
                    const valor = e.target.getAttribute('data-valor');

                    // Preenche o formulário principal na direita
                    formPlano.querySelector('input[name="plano_id"]').value = id;
                    formPlano.querySelector('input[name="nome"]').value = nome;
                    formPlano.querySelector('textarea[name="descricao"]').value = descricao;
                    formPlano.querySelector('input[name="valor"]').value = valor; // Já está formatado (ex: 197,00)
                    formPlano.querySelector('button[type="submit"]').textContent = 'Salvar Alterações do Plano';

                    // Fecha o modal e foca no formulário
                    modal.style.display = 'none';
                    formPlano.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });
            });
        }

    });
    </script>
</body>
</html>