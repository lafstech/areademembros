<?php
// admin/suporte.php
declare(strict_types=1);

require_once '../config.php';

// ===================================================================
// === MANIPULADOR DE REQUISIÇÕES AJAX (MODIFICADO)
// ===================================================================
function handleAjaxRequest($pdo) {
    if (isset($_GET['ajax_action'])) {
        verificarAcesso('admin'); // Protege os endpoints AJAX
        header('Content-Type: application/json');

        $action = $_GET['ajax_action'];
        $response = ['success' => false, 'message' => 'Ação inválida.'];
        $admin_id = (int)$_SESSION['usuario_id']; // Pega o ID do admin da sessão

        try {
            // --- AÇÃO: Admin envia uma nova resposta (via POST AJAX) ---
            if ($action === 'admin_reply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $post_ticket_id = (int)($_POST['ticket_id'] ?? 0);
                $mensagem = trim((string)($_POST['mensagem'] ?? ''));
                $anexo_url = null; // URL do anexo a ser salva no DB

                if ($post_ticket_id <= 0) {
                    throw new Exception("ID do ticket inválido.");
                }
                if (empty($mensagem) && empty($_FILES['anexo']['name'])) {
                    throw new Exception("A mensagem ou anexo não pode estar vazio.");
                }

                // --- Lógica de Upload de Anexo ---
                if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['anexo'];
                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                    $max_size = 5 * 1024 * 1024; // 5 MB

                    if ($file['size'] > $max_size) {
                        throw new Exception("Arquivo muito grande. Limite de 5MB.");
                    }
                    if (!in_array($file['type'], $allowed_types)) {
                        throw new Exception("Formato de arquivo inválido. Apenas JPG, PNG e GIF são permitidos.");
                    }

                    // Define o diretório de upload
                    $upload_dir_base = '../uploads/suporte/';
                    if (!is_dir($upload_dir_base)) {
                        if (!mkdir($upload_dir_base, 0755, true)) {
                            throw new Exception("Falha ao criar diretório de uploads. Verifique as permissões.");
                        }
                    }

                    // Gera nome único
                    $filename = uniqid('ticket_' . $post_ticket_id . '_') . '_' . basename($file['name']);
                    $upload_path = $upload_dir_base . $filename;

                    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                        // Salva o caminho *relativo* à raiz do site (não ao admin)
                        $anexo_url = 'uploads/suporte/' . $filename;
                    } else {
                        throw new Exception("Falha ao mover o arquivo enviado.");
                    }
                }

                $pdo->beginTransaction();

                // 1. Insere a mensagem do admin
                $stmt_msg = $pdo->prepare("
                    INSERT INTO suporte_mensagens (ticket_id, remetente_id, mensagem, anexo_url)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt_msg->execute([$post_ticket_id, $admin_id, $mensagem, $anexo_url]);
                $new_message_id = $pdo->lastInsertId();

                // 2. Atualiza o status do ticket
                $stmt_ticket = $pdo->prepare("
                    UPDATE suporte_tickets
                    SET status = 'AGUARDANDO_USUARIO', admin_ultima_visualizacao = NOW()
                    WHERE id = ?
                ");
                $stmt_ticket->execute([$post_ticket_id]);

                $pdo->commit();

                // 3. Busca a mensagem recém-criada para retornar ao JS
                $stmt_new = $pdo->prepare("
                    SELECT m.*, u.nome AS remetente_nome, u.nivel_acesso AS remetente_nivel
                    FROM suporte_mensagens m
                    JOIN usuarios u ON m.remetente_id = u.id
                    WHERE m.id = ?
                ");
                $stmt_new->execute([$new_message_id]);
                $new_message_data = $stmt_new->fetch(PDO::FETCH_ASSOC);

                $response = [
                    'success' => true,
                    'message' => $new_message_data
                ];
            }

            // --- AÇÃO: Buscar Novas Mensagens (Polling) ---
            elseif ($action === 'get_new_messages') {
                $ticket_id = (int)($_GET['ticket_id'] ?? 0);
                $last_message_id = (int)($_GET['last_message_id'] ?? 0);

                if ($ticket_id <= 0) {
                    throw new Exception("ID do ticket inválido.");
                }

                $stmt_msg = $pdo->prepare("
                    SELECT m.*, u.nome AS remetente_nome, u.nivel_acesso AS remetente_nivel
                    FROM suporte_mensagens m
                    JOIN usuarios u ON m.remetente_id = u.id
                    WHERE m.ticket_id = ?
                      AND m.id > ?
                      AND u.nivel_acesso != 'admin'
                    ORDER BY m.data_envio ASC
                ");
                $stmt_msg->execute([$ticket_id, $last_message_id]);
                $new_messages = $stmt_msg->fetchAll(PDO::FETCH_ASSOC);

                $status_changed = false;
                if (count($new_messages) > 0) {
                    // Se buscamos novas mensagens, marcamos que o admin as viu
                    // E mudamos o status do ticket para ABERTO (pois o usuário respondeu)
                    $stmt_update = $pdo->prepare("
                        UPDATE suporte_tickets
                        SET admin_ultima_visualizacao = NOW(), status = 'ABERTO'
                        WHERE id = ?
                    ");
                    $stmt_update->execute([$ticket_id]);
                    $status_changed = true; // ⭐ Indica que o status mudou
                }

                $response = [
                    'success' => true,
                    'messages' => $new_messages,
                    'status_changed' => $status_changed
                ];
            }

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $response['message'] = $e->getMessage();
        }

        echo json_encode($response);
        exit; // Termina a execução do script aqui
    }
}
// Executa o manipulador AJAX. Se for uma chamada AJAX, o script para aqui.
handleAjaxRequest($pdo);


// ===================================================================
// === EXECUÇÃO NORMAL DA PÁGINA (se não for AJAX)
// ===================================================================

verificarAcesso('admin'); // Proteção para administradores

$nome_usuario = htmlspecialchars($_SESSION['usuario_nome']);
$admin_id = (int)$_SESSION['usuario_id']; // ID do admin logado
$pagina_atual = basename($_SERVER['PHP_SELF']);

$successMessage = null;
$errorMessage = null;

$ticket_id_view = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;


// ===================================================================
// === LÓGICA DE POST (Apenas Fechar Ticket)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        $action = $_POST['action'] ?? '';
        $post_ticket_id = (int)($_POST['ticket_id'] ?? 0);

        if ($post_ticket_id <= 0) {
            throw new Exception("ID do ticket inválido.");
        }

        // --- AÇÃO: Admin fecha o ticket ---
        if ($action === 'close_ticket') {

            $stmt_ticket = $pdo->prepare("
                UPDATE suporte_tickets
                SET status = 'FECHADO', data_fechamento = NOW(), fechado_por = 'admin'
                WHERE id = ?
            ");
            $stmt_ticket->execute([$post_ticket_id]);

            $mensagem_sistema = "[TICKET FECHADO PELO SUPORTE]";
            $stmt_msg = $pdo->prepare("
                INSERT INTO suporte_mensagens (ticket_id, remetente_id, mensagem)
                VALUES (?, ?, ?)
            ");
            $stmt_msg->execute([$post_ticket_id, $admin_id, $mensagem_sistema]);

            $pdo->commit();

            $_SESSION['flash_success'] = "Ticket #" . $post_ticket_id . " fechado com sucesso!";
            header("Location: suporte.php");
            exit;
        }

        // Se houver outras ações POST (não-ajax), elas iriam aqui
        // $pdo->commit(); // Removido pois a única ação é 'close_ticket' que já faz commit/exit

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errorMessage = "Ocorreu um erro: " . $e->getMessage();
    }
}

if (isset($_SESSION['flash_success'])) {
    $successMessage = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}


// ===================================================================
// === LÓGICA DE EXIBIÇÃO (GET) - (Sem alteração)
// ===================================================================

$view_data = [
    'modo_lista' => true,
    'tickets' => [],
    'ticket_info' => null,
    'mensagens' => [],
    'usuario_ticket' => null,
    'filtro_status' => 'ABERTO',
    'last_message_id' => 0
];

if ($ticket_id_view > 0) {
    // --- MODO DETALHE (CHAT VIEW) ---
    $view_data['modo_lista'] = false;

    // 1. Marcar o ticket como lido pelo admin
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
        $errorMessage = "Ticket não encontrado.";
        $view_data['modo_lista'] = true;

    } else {
        // 3. Buscar informações do usuário
        $stmt_user = $pdo->prepare("SELECT id, nome, email FROM usuarios WHERE id = ?");
        $stmt_user->execute([$view_data['ticket_info']['usuario_id']]);
        $view_data['usuario_ticket'] = $stmt_user->fetch(PDO::FETCH_ASSOC);

        // 4. Buscar todas as mensagens
        $stmt_msgs = $pdo->prepare("
            SELECT m.*, u.nome AS remetente_nome, u.nivel_acesso AS remetente_nivel
            FROM suporte_mensagens m
            JOIN usuarios u ON m.remetente_id = u.id
            WHERE m.ticket_id = ?
            ORDER BY m.data_envio ASC
        ");
        $stmt_msgs->execute([$ticket_id_view]);
        $view_data['mensagens'] = $stmt_msgs->fetchAll(PDO::FETCH_ASSOC);

        // 5. Armazena o ID da última mensagem para o polling
        if (!empty($view_data['mensagens'])) {
            $last_msg = end($view_data['mensagens']);
            $view_data['last_message_id'] = $last_msg['id'];
        }
    }

} else {
    // --- MODO LISTA (TABLE VIEW) ---
    $view_data['modo_lista'] = true;
    $view_data['filtro_status'] = $_GET['status'] ?? 'ABERTO';

    $params = [];
    if ($view_data['filtro_status'] === 'FECHADO') {
        $sql_where = "WHERE t.status = 'FECHADO'";
    } else {
        $sql_where = "WHERE t.status IN ('ABERTO', 'AGUARDANDO_USUARIO')";
    }

    // (t.data_ultima_atualizacao > t.admin_ultima_visualizacao AND t.status = 'ABERTO') AS admin_nao_leu
    $stmt_list = $pdo->prepare("
        SELECT
            t.id, t.assunto, t.status, t.data_ultima_atualizacao,
            u.nome AS usuario_nome,
            (t.data_ultima_atualizacao > t.admin_ultima_visualizacao AND t.status = 'ABERTO') AS admin_nao_leu
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
        /* === CSS (Base) === */
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

        .management-card { background: var(--glass-background); border: 1px solid var(--border-color); border-radius: 12px; padding: 2rem; margin-bottom: 2rem; }
        .management-card h2 { font-size: 1.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; }
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 0.5rem; }
        .form-group input, .form-group textarea { width: 100%; padding: 0.75rem 1rem; background-color: var(--background-color); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-color); font-size: 1rem; font-family: 'Poppins', sans-serif; }
        /* ⭐ NOVO: Estilo para input[type=file] */
        .form-group input[type="file"] {
            padding: 0.5rem;
            background-color: var(--glass-background);
        }
        .form-group input[type="file"]::file-selector-button {
            background-color: var(--primary-color);
            color: white; border: none; padding: 0.5rem 1rem;
            border-radius: 6px; cursor: pointer; margin-right: 1rem;
            font-family: 'Poppins', sans-serif; font-weight: 500;
        }
        .form-group textarea { resize: vertical; min-height: 120px; }
        .form-group input:focus, .form-group textarea:focus { border-color: var(--primary-color); outline: none; }

        .btn { padding: 0.8rem 1.5rem; background-color: var(--primary-color); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 1rem; transition: background-color 0.3s; text-decoration: none; }
        .btn:hover:not(:disabled) { background-color: #c01a3f; }
        .btn:disabled { background-color: #4b5563; opacity: 0.7; cursor: not-allowed; }
        .btn-success { background-color: var(--success-color); }
        .btn-success:hover:not(:disabled) { background-color: #1a9c4b; }
        .btn-info { background-color: var(--info-color); }
        .btn-info:hover:not(:disabled) { background-color: #2563eb; }
        .btn-secondary { background-color: #4b5563; }
        .btn-secondary:hover:not(:disabled) { background-color: #374151; }

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

        .filter-tabs { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); }
        .filter-tabs a { padding: 0.75rem 1.5rem; text-decoration: none; color: var(--text-muted); font-weight: 500; border-bottom: 3px solid transparent; }
        .filter-tabs a:hover { color: var(--text-color); }
        .filter-tabs a.active { color: var(--primary-color); border-bottom-color: var(--primary-color); }

        .badge { padding: 0.25rem 0.6rem; font-size: 0.75rem; font-weight: 600; border-radius: 20px; }
        .badge-warning { background-color: rgba(245, 158, 11, 0.2); color: var(--warning-color); }
        .badge-info { background-color: rgba(59, 130, 246, 0.2); color: var(--info-color); }
        .badge-muted { background-color: rgba(156, 163, 175, 0.2); color: var(--text-muted); }
        .badge-success { background-color: rgba(34, 197, 94, 0.2); color: var(--success-color); }
        .badge-new { margin-left: 8px; background-color: var(--primary-color); color: white; }

        .data-table .btn-respond { font-size: 0.9rem; padding: 0.5rem 1rem; }

        .ticket-header {
            display: flex; justify-content: space-between; align-items: flex-start;
            flex-wrap: wrap; gap: 1rem;
        }
        .ticket-header .user-info-ticket { font-size: 0.9rem; color: var(--text-muted); }
        .ticket-header .user-info-ticket strong { color: var(--text-color); }

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
            white-space: pre-wrap;
            word-wrap: break-word;

            /* ⭐ CORREÇÃO: Largura e Alinhamento */
            width: -moz-fit-content;
            width: fit-content;
            text-align: left; /* Garante alinhamento à esquerda */
        }
        .message-time { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem; }

        .message-user {
            align-self: flex-start;
        }
        .message-user .message-bubble {
            background-color: var(--glass-background);
            border-top-left-radius: 0;
        }

        .message-admin {
            align-self: flex-end;
            align-items: flex-end;
        }
        .message-admin .message-bubble {
            background-color: var(--primary-color);
            color: white;
            border-top-right-radius: 0;
        }
        .message-admin .message-time { color: var(--text-muted); }

        .message-system {
            align-self: center;
            text-align: center;
            font-size: 0.85rem;
            color: var(--text-muted);
            background-color: var(--glass-background);
            padding: 0.5rem 1rem;
            border-radius: 20px;
        }

        /* ⭐ NOVO: Estilo para Imagem no Chat */
        .chat-image-anexo {
            max-width: 100%;
            width: 300px; /* Largura máxima para imagem não ficar gigante */
            height: auto;
            border-radius: 8px;
            margin-top: 10px; /* Espaço entre texto e imagem */
            cursor: pointer;
            display: block; /* Garante que ocupe a linha */
        }

        .chat-reply-form {
            border-top: 1px solid var(--border-color);
            padding: 1.5rem;
        }
        .chat-reply-form .btn { margin-top: 1rem; }


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
                        <div id="ticket-status-badge">
                            <?php echo getStatusBadge($ticket['status']); ?>
                        </div>
                    </div>

                    <div class="chat-wrapper">
                        <div class="chat-history" id="chat-history"
                             data-ticket-id="<?php echo $ticket['id']; ?>"
                             data-last-message-id="<?php echo $view_data['last_message_id']; ?>">

                            <?php foreach ($view_data['mensagens'] as $msg): ?>
                                <?php
                                    $is_admin = ($msg['remetente_nivel'] === 'admin');
                                    $is_system = (strpos($msg['mensagem'], '[TICKET') === 0);

                                    if ($is_system) $msg_class = 'message-system';
                                    else $msg_class = $is_admin ? 'message-admin' : 'message-user';
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

                                            <?php if (!empty($msg['anexo_url'])): ?>
                                                <a href="../<?php echo htmlspecialchars($msg['anexo_url']); ?>" target="_blank">
                                                    <img src="../<?php echo htmlspecialchars($msg['anexo_url']); ?>" alt="Anexo" class="chat-image-anexo">
                                                </a>
                                            <?php endif; ?>
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
                                <form method="POST" action="suporte.php?ajax_action=admin_reply" id="chat-reply-form" enctype="multipart/form-data">
                                    <input type="hidden" name="ticket_id" value="<?php echo $ticket['id']; ?>">

                                    <div class="form-group">
                                        <label for="mensagem">Sua Resposta</label>
                                        <textarea id="mensagem" name="mensagem" rows="4" placeholder="Digite sua resposta aqui..."></textarea>
                                    </div>

                                    <div class="form-group" style="margin-bottom: 0.5rem;">
                                        <label for="anexo">Anexar Imagem (Opcional - Max 5MB)</label>
                                        <input type="file" name="anexo" id="anexo" accept="image/png, image/jpeg, image/gif">
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
            menuToggle.addEventListener('click', (event) => { event.stopPropagation(); body.classList.toggle('sidebar-open'); });
            body.addEventListener('click', (event) => {
                if (body.classList.contains('sidebar-open') && !sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                    body.classList.remove('sidebar-open');
                }
            });
            sidebar.querySelectorAll('nav a').forEach(link => {
                link.addEventListener('click', () => {
                    if (body.classList.contains('sidebar-open')) body.classList.remove('sidebar-open');
                });
            });
        }

        const userProfileMenu = document.getElementById('user-profile-menu');
        const profileDropdown = document.getElementById('profile-dropdown');
        if(userProfileMenu && profileDropdown){
            userProfileMenu.addEventListener('click', (event) => { event.stopPropagation(); profileDropdown.classList.toggle('show'); });
            window.addEventListener('click', () => {
                if (profileDropdown.classList.contains('show')) profileDropdown.classList.remove('show');
            });
        }

        // --- Lógica Específica (suporte.php) ---
        const chatHistory = document.getElementById('chat-history');
        const statusBadge = document.getElementById('ticket-status-badge');

        // --- Helpers JS ---
        const formatDate_JS = (dateStr) => {
            if (!dateStr) return 'N/A';
            return new Date(dateStr).toLocaleString('pt-BR', {
                day: '2-digit', month: '2-digit', year: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });
        };

        // ⭐ NOVO: Helper para gerar HTML do badge
        const getBadgeHTML = (status) => {
            if (status === 'ABERTO') {
                return '<span class="badge badge-warning">Aguardando Suporte</span>';
            } else if (status === 'AGUARDANDO_USUARIO') {
                return '<span class="badge badge-info">Aguardando Usuário</span>';
            } else {
                return '<span class="badge badge-muted">Fechado</span>';
            }
        };

        // ⭐ NOVO: Helper para sanitizar HTML (evitar XSS)
        const sanitizeHTML = (str) => {
            return str.replace(/</g, "&lt;").replace(/>/g, "&gt;");
        };

        // ⭐ NOVO: Helper para adicionar mensagens do *USUÁRIO* (do polling)
        const appendUserMessageToChat = (msg) => {
            const msgDiv = document.createElement('div');
            msgDiv.className = 'message-user chat-message';

            const senderDiv = document.createElement('div');
            senderDiv.className = 'message-sender';
            senderDiv.textContent = sanitizeHTML(msg.remetente_nome || 'Usuário');

            const bubbleDiv = document.createElement('div');
            bubbleDiv.className = 'message-bubble';
            // Sanitiza e formata quebras de linha
            bubbleDiv.innerHTML = msg.mensagem ? sanitizeHTML(msg.mensagem).replace(/\n/g, '<br>') : '';

            if (msg.anexo_url) {
                const link = document.createElement('a');
                link.href = '../' + sanitizeHTML(msg.anexo_url);
                link.target = '_blank';

                const img = document.createElement('img');
                img.src = '../' + sanitizeHTML(msg.anexo_url);
                img.alt = 'Anexo';
                img.className = 'chat-image-anexo';

                link.appendChild(img);
                bubbleDiv.appendChild(link);
            }

            const timeDiv = document.createElement('div');
            timeDiv.className = 'message-time';
            timeDiv.textContent = formatDate_JS(msg.data_envio);

            msgDiv.appendChild(senderDiv);
            msgDiv.appendChild(bubbleDiv);
            msgDiv.appendChild(timeDiv);

            chatHistory.appendChild(msgDiv);
        };

        // ⭐ NOVO: Helper para adicionar mensagens do *ADMIN* (do form AJAX)
        const appendAdminMessageToChat = (msg) => {
            const msgDiv = document.createElement('div');
            msgDiv.className = 'message-admin chat-message';

            const senderDiv = document.createElement('div');
            senderDiv.className = 'message-sender';
            senderDiv.textContent = sanitizeHTML(msg.remetente_nome || 'Admin') + ' (Suporte)';

            const bubbleDiv = document.createElement('div');
            bubbleDiv.className = 'message-bubble';
            bubbleDiv.innerHTML = msg.mensagem ? sanitizeHTML(msg.mensagem).replace(/\n/g, '<br>') : '';

            if (msg.anexo_url) {
                const link = document.createElement('a');
                link.href = '../' + sanitizeHTML(msg.anexo_url);
                link.target = '_blank';

                const img = document.createElement('img');
                img.src = '../' + sanitizeHTML(msg.anexo_url);
                img.alt = 'Anexo';
                img.className = 'chat-image-anexo';

                link.appendChild(img);
                bubbleDiv.appendChild(link);
            }

            const timeDiv = document.createElement('div');
            timeDiv.className = 'message-time';
            timeDiv.textContent = formatDate_JS(msg.data_envio);

            msgDiv.appendChild(senderDiv);
            msgDiv.appendChild(bubbleDiv);
            msgDiv.appendChild(timeDiv);

            chatHistory.appendChild(msgDiv);
        };

        // --- Lógica de Polling e Envio de Formulário ---
        if (chatHistory) {
            // 1. Scrollar o chat para a última mensagem no carregamento
            chatHistory.scrollTop = chatHistory.scrollHeight;

            const ticketId = chatHistory.dataset.ticketId;
            let lastMessageId = parseInt(chatHistory.dataset.lastMessageId || '0');

            // 2. Função de Polling (a cada 3 segundos)
            const checkNewMessages = async () => {
                try {
                    const params = new URLSearchParams({
                        ajax_action: 'get_new_messages',
                        ticket_id: ticketId,
                        last_message_id: lastMessageId
                    });

                    const response = await fetch(`suporte.php?${params.toString()}`);
                    const data = await response.json();

                    if (data.success && data.messages.length > 0) {
                        let newLastId = lastMessageId;
                        data.messages.forEach(msg => {
                            appendUserMessageToChat(msg);
                            newLastId = msg.id;
                        });

                        lastMessageId = newLastId;
                        chatHistory.dataset.lastMessageId = lastMessageId;

                        if (chatHistory.scrollHeight - chatHistory.scrollTop - chatHistory.clientHeight < 150) {
                             chatHistory.scrollTop = chatHistory.scrollHeight;
                        }
                    }

                    // ⭐ NOVO: Atualiza o status se o backend informar que mudou
                    if (data.success && data.status_changed && statusBadge) {
                         statusBadge.innerHTML = getBadgeHTML('ABERTO');
                    }

                } catch (error) {
                    console.error('Erro ao buscar novas mensagens:', error);
                    if (pollingInterval) clearInterval(pollingInterval);
                }
            };

            // 3. Inicia o Polling
            <?php if ($view_data['ticket_info'] && $view_data['ticket_info']['status'] !== 'FECHADO'): ?>
                const pollingInterval = setInterval(checkNewMessages, 3000);
            <?php endif; ?>

            // 4. ⭐ NOVO: Intercepta o envio do formulário de resposta
            const replyForm = document.getElementById('chat-reply-form');
            if (replyForm) {
                replyForm.addEventListener('submit', async function(e) {
                    e.preventDefault();

                    const formData = new FormData(replyForm);
                    const submitButton = replyForm.querySelector('button[type="submit"]');
                    submitButton.disabled = true;
                    submitButton.textContent = 'Enviando...';

                    try {
                        const response = await fetch('suporte.php?ajax_action=admin_reply', {
                            method: 'POST',
                            body: formData
                        });

                        const data = await response.json();

                        if (data.success && data.message) {
                            appendAdminMessageToChat(data.message);

                            lastMessageId = data.message.id;
                            chatHistory.dataset.lastMessageId = lastMessageId;

                            chatHistory.scrollTop = chatHistory.scrollHeight;
                            replyForm.reset(); // Limpa o texto e o arquivo

                            if (statusBadge) {
                                statusBadge.innerHTML = getBadgeHTML('AGUARDANDO_USUARIO');
                            }

                        } else {
                            alert('Erro ao enviar resposta: ' + (data.message || 'Erro desconhecido.'));
                        }

                    } catch (error) {
                        console.error('Erro no fetch:', error);
                        alert('Erro de conexão ao enviar resposta.');
                    } finally {
                        submitButton.disabled = false;
                        submitButton.textContent = 'Enviar Resposta';
                    }
                });
            }
        }
    });
    </script>
</body>
</html>