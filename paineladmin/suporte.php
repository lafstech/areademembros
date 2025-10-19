<?php
// admin/suporte.php
declare(strict_types=1);

require_once '../config.php';
verificarAcesso('admin'); // Proteção para administradores

$nome_usuario = htmlspecialchars($_SESSION['usuario_nome']);
$admin_id = (int)$_SESSION['usuario_id']; // ID do admin logado
$pagina_atual = basename($_SERVER['PHP_SELF']);

$successMessage = null;
$errorMessage = null;

// Pega o ID do ticket pela URL. Se for 0, mostra a lista.
$ticket_id_view = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;


// ===================================================================
// === LÓGICA DE POST (Responder / Fechar Ticket)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        $action = $_POST['action'] ?? '';
        $post_ticket_id = (int)($_POST['ticket_id'] ?? 0);

        if ($post_ticket_id <= 0) {
            throw new Exception("ID do ticket inválido.");
        }

        // --- AÇÃO: Admin envia uma nova resposta ---
        if ($action === 'admin_reply') {
            $mensagem = trim((string)($_POST['mensagem'] ?? ''));
            if (empty($mensagem)) {
                throw new Exception("A mensagem não pode estar vazia.");
            }

            // 1. Insere a mensagem do admin
            $stmt_msg = $pdo->prepare("
                INSERT INTO suporte_mensagens (ticket_id, remetente_id, mensagem)
                VALUES (?, ?, ?)
            ");
            $stmt_msg->execute([$post_ticket_id, $admin_id, $mensagem]);

            // 2. Atualiza o status do ticket para "Aguardando Usuário"
            // A data_ultima_atualizacao será atualizada automaticamente pelo TRIGGER do DB
            $stmt_ticket = $pdo->prepare("
                UPDATE suporte_tickets
                SET status = 'AGUARDANDO_USUARIO', admin_ultima_visualizacao = NOW()
                WHERE id = ?
            ");
            $stmt_ticket->execute([$post_ticket_id]);

            $successMessage = "Resposta enviada com sucesso!";
            // Define o $ticket_id_view para continuar na mesma página
            $ticket_id_view = $post_ticket_id;

        }

        // --- AÇÃO: Admin fecha o ticket ---
        elseif ($action === 'close_ticket') {

            // 1. Atualiza o status do ticket
            $stmt_ticket = $pdo->prepare("
                UPDATE suporte_tickets
                SET status = 'FECHADO', data_fechamento = NOW(), fechado_por = 'admin'
                WHERE id = ?
            ");
            $stmt_ticket->execute([$post_ticket_id]);

            // 2. (Opcional) Adiciona uma mensagem de sistema informando o fechamento
            $mensagem_sistema = "[TICKET FECHADO PELO SUPORTE]";
            $stmt_msg = $pdo->prepare("
                INSERT INTO suporte_mensagens (ticket_id, remetente_id, mensagem)
                VALUES (?, ?, ?)
            ");
            $stmt_msg->execute([$post_ticket_id, $admin_id, $mensagem_sistema]);

            $pdo->commit();

            // Redireciona para a lista de tickets após fechar
            $_SESSION['flash_success'] = "Ticket #" . $post_ticket_id . " fechado com sucesso!";
            header("Location: suporte.php");
            exit;
        }

        $pdo->commit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errorMessage = "Ocorreu um erro: " . $e->getMessage();
    }
}

// Mensagem de sucesso vinda de um redirecionamento (ex: fechar ticket)
if (isset($_SESSION['flash_success'])) {
    $successMessage = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}


// ===================================================================
// === LÓGICA DE EXIBIÇÃO (GET)
// ===================================================================

$view_data = [
    'modo_lista' => true,
    'tickets' => [],
    'ticket_info' => null,
    'mensagens' => [],
    'usuario_ticket' => null,
    'filtro_status' => 'ABERTO'
];

if ($ticket_id_view > 0) {
    // --- MODO DETALHE (CHAT VIEW) ---
    $view_data['modo_lista'] = false;

    // 1. Marcar o ticket como lido pelo admin (atualiza a visualização)
    $stmt_mark_read = $pdo->prepare("
        UPDATE suporte_tickets SET admin_ultima_visualizacao = NOW()
        WHERE id = ? AND (admin_ultima_visualizacao IS NULL OR admin_ultima_visualizacao < data_ultima_atualizacao)
    ");
    $stmt_mark_read->execute([$ticket_id_view]);


    // 2. Buscar informações do ticket
    $stmt_ticket = $pdo->prepare("SELECT * FROM suporte_tickets WHERE id = ?");
    $stmt_ticket->execute([$ticket_id_view]);
    $view_data['ticket_info'] = $stmt_ticket->fetch(PDO::FETCH_ASSOC);

    if (!$view_data['ticket_info']) {
        // Ticket não existe, redireciona para a lista
        $errorMessage = "Ticket não encontrado.";
        $view_data['modo_lista'] = true; // Força o modo lista

    } else {
        // 3. Buscar informações do usuário que abriu o ticket
        $stmt_user = $pdo->prepare("SELECT id, nome, email FROM usuarios WHERE id = ?");
        $stmt_user->execute([$view_data['ticket_info']['usuario_id']]);
        $view_data['usuario_ticket'] = $stmt_user->fetch(PDO::FETCH_ASSOC);

        // 4. Buscar todas as mensagens do ticket (JOIN com usuarios para saber quem enviou)
        $stmt_msgs = $pdo->prepare("
            SELECT m.*, u.nome AS remetente_nome, u.nivel_acesso AS remetente_nivel
            FROM suporte_mensagens m
            JOIN usuarios u ON m.remetente_id = u.id
            WHERE m.ticket_id = ?
            ORDER BY m.data_envio ASC
        ");
        $stmt_msgs->execute([$ticket_id_view]);
        $view_data['mensagens'] = $stmt_msgs->fetchAll(PDO::FETCH_ASSOC);
    }

} else {
    // --- MODO LISTA (TABLE VIEW) ---
    $view_data['modo_lista'] = true;
    $view_data['filtro_status'] = $_GET['status'] ?? 'ABERTO';

    // Define a cláusula WHERE com base no filtro
    $params = [];
    if ($view_data['filtro_status'] === 'FECHADO') {
        $sql_where = "WHERE t.status = 'FECHADO'";
    } else {
        // 'ABERTO' inclui tickets esperando resposta do admin ('ABERTO')
        // e tickets esperando resposta do usuário ('AGUARDANDO_USUARIO')
        $sql_where = "WHERE t.status IN ('ABERTO', 'AGUARDANDO_USUARIO')";
    }

    // Busca a lista de tickets
    $stmt_list = $pdo->prepare("
        SELECT
            t.id, t.assunto, t.status, t.data_ultima_atualizacao,
            u.nome AS usuario_nome,
            (t.data_ultima_atualizacao > t.admin_ultima_visualizacao) AS admin_nao_leu
        FROM suporte_tickets t
        JOIN usuarios u ON t.usuario_id = u.id
        $sql_where
        ORDER BY t.data_ultima_atualizacao DESC
    ");
    $stmt_list->execute($params);
    $view_data['tickets'] = $stmt_list->fetchAll(PDO::FETCH_ASSOC);
}

// Função helper para formatar datas
function formatarData($data) {
    if (!$data) return 'N/A';
    // Adicionado 'H:i' para ver a hora
    return date("d/m/Y H:i", strtotime($data));
}

// Função helper para badges de status
function getStatusBadge($status) {
    switch ($status) {
        case 'ABERTO':
            return '<span class="badge badge-warning">Aguardando Suporte</span>';
        case 'AGUARDANDO_USUARIO':
            return '<span class="badge badge-info">Aguardando Usuário</span>';
        case 'FECHADO':
            return '<span class="badge badge-muted">Fechado</span>';
        default:
            return '<span class="badge badge-muted">' . htmlspecialchars($status) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suporte ao Cliente - Admin Panel</title>
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

        /* === ESTILOS DE COMPONENTES (Unificados) === */
        .management-card { background: var(--glass-background); border: 1px solid var(--border-color); border-radius: 12px; padding: 2rem; margin-bottom: 2rem; }
        .management-card h2 { font-size: 1.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; }
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 0.5rem; }
        .form-group input, .form-group textarea { width: 100%; padding: 0.75rem 1rem; background-color: var(--background-color); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-color); font-size: 1rem; font-family: 'Poppins', sans-serif; }
        .form-group textarea { resize: vertical; min-height: 120px; }
        .form-group input:focus, .form-group textarea:focus { border-color: var(--primary-color); outline: none; }

        .btn { padding: 0.8rem 1.5rem; background-color: var(--primary-color); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 1rem; transition: background-color 0.3s; text-decoration: none; }
        .btn:hover { background-color: #c01a3f; }
        .btn-success { background-color: var(--success-color); }
        .btn-success:hover { background-color: #1a9c4b; }
        .btn-info { background-color: var(--info-color); }
        .btn-info:hover { background-color: #2563eb; }
        .btn-secondary { background-color: #4b5563; }
        .btn-secondary:hover { background-color: #374151; }

        .table-wrapper { overflow-x: auto; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: 0.75rem 1rem; text-align: left; border-bottom: 1px solid var(--border-color); white-space: nowrap; }
        .data-table thead { background-color: rgba(0,0,0,0.2); }
        .data-table th { font-weight: 600; font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table td .user-email { font-size: 0.85rem; color: var(--text-muted); display: block; }

        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 600; }
        .alert-success { background-color: rgba(34, 197, 94, 0.2); color: var(--success-color); }
        .alert-error { background-color: rgba(248, 113, 113, 0.2); color: var(--error-color); }

        /* === ESTILOS ESPECÍFICOS (suporte.php) === */

        /* Abas de Filtro */
        .filter-tabs { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); }
        .filter-tabs a { padding: 0.75rem 1.5rem; text-decoration: none; color: var(--text-muted); font-weight: 500; border-bottom: 3px solid transparent; }
        .filter-tabs a:hover { color: var(--text-color); }
        .filter-tabs a.active { color: var(--primary-color); border-bottom-color: var(--primary-color); }

        /* Badges de Status e "Novo" */
        .badge { padding: 0.25rem 0.6rem; font-size: 0.75rem; font-weight: 600; border-radius: 20px; }
        .badge-warning { background-color: rgba(245, 158, 11, 0.2); color: var(--warning-color); }
        .badge-info { background-color: rgba(59, 130, 246, 0.2); color: var(--info-color); }
        .badge-muted { background-color: rgba(156, 163, 175, 0.2); color: var(--text-muted); }
        .badge-success { background-color: rgba(34, 197, 94, 0.2); color: var(--success-color); }
        .badge-new { margin-left: 8px; background-color: var(--primary-color); color: white; }

        .data-table .btn-respond { font-size: 0.9rem; padding: 0.5rem 1rem; }

        /* Header do Ticket (Modo Detalhe) */
        .ticket-header {
            display: flex; justify-content: space-between; align-items: flex-start;
            flex-wrap: wrap; gap: 1rem;
        }
        .ticket-header .user-info-ticket { font-size: 0.9rem; color: var(--text-muted); }
        .ticket-header .user-info-ticket strong { color: var(--text-color); }

        /* Chat */
        .chat-wrapper {
            background-color: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            margin-top: 2rem;
            display: flex;
            flex-direction: column;
        }
        .chat-history {
            height: 400px;
            overflow-y: auto;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            scrollbar-width: thin; scrollbar-color: var(--primary-color) transparent;
        }
        .chat-history::-webkit-scrollbar { width: 5px; }
        .chat-history::-webkit-scrollbar-thumb { background-color: var(--primary-color); border-radius: 10px; }

        .chat-message {
            display: flex;
            flex-direction: column;
            max-width: 75%;
        }
        .message-sender { font-weight: 600; font-size: 0.9rem; margin-bottom: 0.25rem; }
        .message-bubble {
            padding: 1rem;
            border-radius: 12px;
            line-height: 1.6;
            white-space: pre-wrap; /* Preserva quebras de linha */
        }
        .message-time { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem; }

        /* Mensagem do Usuário (Esquerda) */
        .message-user {
            align-self: flex-start;
        }
        .message-user .message-bubble {
            background-color: var(--glass-background);
            border-top-left-radius: 0;
        }

        /* Mensagem do Admin (Direita) */
        .message-admin {
            align-self: flex-end;
            align-items: flex-end; /* Alinha a data à direita */
        }
        .message-admin .message-bubble {
            background-color: var(--primary-color);
            color: white;
            border-top-right-radius: 0;
        }
        .message-admin .message-time { color: var(--text-muted); }

        /* Mensagem do Sistema (Central) */
        .message-system {
            align-self: center;
            text-align: center;
            font-size: 0.85rem;
            color: var(--text-muted);
            background-color: var(--glass-background);
            padding: 0.5rem 1rem;
            border-radius: 20px;
        }

        /* Formulário de Resposta */
        .chat-reply-form {
            border-top: 1px solid var(--border-color);
            padding: 1.5rem;
        }
        .chat-reply-form .btn { margin-top: 1rem; }


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
        @media (max-width: 576px) {
             .main-content { padding: 1rem; padding-top: 4.5rem; }
             .chat-message { max-width: 90%; }
        }
    </style>
</head>
<body class="<?php echo $view_data['modo_lista'] ? '' : 'chat-view-active'; ?>">

    <div class="menu-toggle" id="menu-toggle">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="24" height="24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
    </div>

    <?php include '_sidebar_admin.php'; // Inclui a sidebar universal ?>

    <main class="main-content">

        <?php if ($view_data['modo_lista']): ?>
            <header>
                <h1>Central de Suporte</h1>
                <p>Gerencie os tickets de suporte abertos pelos usuários.</p>
            </header>

            <?php if ($successMessage): ?><div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div><?php endif; ?>
            <?php if ($errorMessage): ?><div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div><?php endif; ?>

            <div class="filter-tabs">
                <a href="suporte.php?status=ABERTO"
                   class="<?php echo ($view_data['filtro_status'] === 'ABERTO') ? 'active' : ''; ?>">
                   Abertos
                </a>
                <a href="suporte.php?status=FECHADO"
                   class="<?php echo ($view_data['filtro_status'] === 'FECHADO') ? 'active' : ''; ?>">
                   Fechados
                </a>
            </div>

            <section class="management-card" style="padding: 0;">
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Ticket</th>
                                <th>Assunto</th>
                                <th>Usuário</th>
                                <th>Status</th>
                                <th>Última Atualização</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($view_data['tickets'])): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                                        Nenhum ticket <?php echo $view_data['filtro_status'] === 'ABERTO' ? 'aberto' : 'fechado'; ?> encontrado.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($view_data['tickets'] as $ticket): ?>
                                    <tr>
                                        <td>#<?php echo $ticket['id']; ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($ticket['assunto']); ?>
                                            <?php if ($ticket['admin_nao_leu']): ?>
                                                <span class="badge badge-new">Novo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($ticket['usuario_nome']); ?></td>
                                        <td><?php echo getStatusBadge($ticket['status']); ?></td>
                                        <td><?php echo formatarData($ticket['data_ultima_atualizacao']); ?></td>
                                        <td>
                                            <a href="suporte.php?ticket_id=<?php echo $ticket['id']; ?>" class="btn btn-info btn-respond">
                                                <?php echo $view_data['filtro_status'] === 'ABERTO' ? 'Responder' : 'Ver'; ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php else: ?>
            <?php if ($view_data['ticket_info']):
                $ticket = $view_data['ticket_info'];
                $usuario = $view_data['usuario_ticket'];
            ?>
                <header>
                    <a href="suporte.php" style="text-decoration: none; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                        Voltar para a lista
                    </a>
                    <h1><?php echo htmlspecialchars($ticket['assunto']); ?></h1>
                </header>

                <?php if ($successMessage): ?><div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div><?php endif; ?>
                <?php if ($errorMessage): ?><div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div><?php endif; ?>

                <section class="management-card">
                    <div class="ticket-header">
                        <div>
                            <h2>Ticket #<?php echo $ticket['id']; ?></h2>
                            <div class="user-info-ticket">
                                Aberto por: <strong><?php echo htmlspecialchars($usuario['nome'] ?? 'Usuário Deletado'); ?></strong> (<?php echo htmlspecialchars($usuario['email'] ?? 'N/A'); ?>)
                                <br>
                                Em: <?php echo formatarData($ticket['data_criacao']); ?>
                            </div>
                        </div>
                        <div>
                            <?php echo getStatusBadge($ticket['status']); ?>
                        </div>
                    </div>

                    <div class="chat-wrapper">
                        <div class="chat-history" id="chat-history">
                            <?php foreach ($view_data['mensagens'] as $msg): ?>
                                <?php
                                    $is_admin = ($msg['remetente_nivel'] === 'admin');
                                    $is_system = (strpos($msg['mensagem'], '[TICKET') === 0);

                                    if ($is_system) {
                                        $msg_class = 'message-system';
                                    } else {
                                        $msg_class = $is_admin ? 'message-admin' : 'message-user';
                                    }
                                ?>

                                <?php if ($is_system): ?>
                                    <div class="<?php echo $msg_class; ?>">
                                        <?php echo htmlspecialchars($msg['mensagem']); ?> - <?php echo formatarData($msg['data_envio']); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="<?php echo $msg_class; ?>">
                                        <div class="message-sender">
                                            <?php echo $is_admin ? htmlspecialchars($msg['remetente_nome']) . ' (Suporte)' : htmlspecialchars($msg['remetente_nome']); ?>
                                        </div>
                                        <div class="message-bubble">
                                            <?php echo nl2br(htmlspecialchars($msg['mensagem'])); ?>
                                        </div>
                                        <div class="message-time">
                                            <?php echo formatarData($msg['data_envio']); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                            <?php endforeach; ?>
                        </div>

                        <?php if ($ticket['status'] !== 'FECHADO'): ?>
                            <div class="chat-reply-form">
                                <form method="POST" action="suporte.php?ticket_id=<?php echo $ticket['id']; ?>">
                                    <input type="hidden" name="action" value="admin_reply">
                                    <input type="hidden" name="ticket_id" value="<?php echo $ticket['id']; ?>">
                                    <div class="form-group">
                                        <label for="mensagem">Sua Resposta</label>
                                        <textarea id="mensagem" name="mensagem" rows="5" required placeholder="Digite sua resposta aqui..."></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-info">Enviar Resposta</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($ticket['status'] !== 'FECHADO'): ?>
                    <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color); text-align: right;">
                        <form method="POST" action="suporte.php" onsubmit="return confirm('Tem certeza que deseja fechar este ticket? O usuário não poderá mais responder.');">
                            <input type="hidden" name="action" value="close_ticket">
                            <input type="hidden" name="ticket_id" value="<?php echo $ticket['id']; ?>">
                            <button type="submit" class="btn btn-secondary">Fechar Ticket</button>
                        </form>
                    </div>
                    <?php endif; ?>

                </section>

            <?php else: ?>
                <header><h1>Erro</h1></header>
                <div class="alert alert-error">O ticket que você está tentando acessar não foi encontrado.</div>
                <a href="suporte.php" class="btn btn-info">Voltar para a lista</a>
            <?php endif; ?>

        <?php endif; ?>

    </main>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        // --- LÓGICA PADRÃO SIDEBAR/DROPDOWN (Unificada) ---
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

        // --- Lógica Específica (suporte.php) ---
        // Scrollar o chat para a última mensagem
        const chatHistory = document.getElementById('chat-history');
        if (chatHistory) {
            chatHistory.scrollTop = chatHistory.scrollHeight;
        }
    });
    </script>
</body>
</html>