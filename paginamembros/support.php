<?php
// paginamembros/support.php - Versão Final Robusta, Consistente e Corrigida

require_once '../config.php';
require_once '../funcoes.php';
verificarAcesso('membro');

$usuario_id = (int)$_SESSION['usuario_id'];
$nome_usuario = htmlspecialchars($_SESSION['usuario_nome']);
$pagina_atual = basename($_SERVER['PHP_SELF']);

$feedback_message = '';
$feedback_type = '';
$view_mode = 'list';
$ticket_selecionado = null;
$mensagens_ticket = [];

define('UPLOAD_DIR_SUPPORT', '../uploads/support/'); // Garanta que /uploads/support/ exista na raiz e tenha permissão de escrita

// --- Processamento de Ações (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        $pdo->beginTransaction();

        // Ação: Criar Novo Ticket
        if ($action === 'create_ticket') {
            $assunto = trim($_POST['assunto'] ?? ''); $mensagem = trim($_POST['mensagem'] ?? ''); $anexo_path = null;
            if (empty($assunto) || empty($mensagem)) { throw new Exception("Assunto e Mensagem são obrigatórios."); }
            if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['anexo']; $allowed_types = ['image/jpeg', 'image/png', 'image/gif']; $max_size = 5 * 1024 * 1024;
                if (!in_array($file['type'], $allowed_types)) { throw new Exception("Tipo de arquivo inválido. Apenas JPG, PNG e GIF."); }
                if ($file['size'] > $max_size) { throw new Exception("Arquivo muito grande (Máx: 5MB)."); }
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION); $filename = uniqid('ticket_' . $usuario_id . '_', true) . '.' . strtolower($ext);
                $destination = UPLOAD_DIR_SUPPORT . $filename;
                if (!move_uploaded_file($file['tmp_name'], $destination)) { throw new Exception("Falha ao mover o arquivo enviado."); }
                $anexo_path = 'uploads/support/' . $filename;
            }
            $stmt_ticket = $pdo->prepare("INSERT INTO suporte_tickets (usuario_id, assunto, status) VALUES (?, ?, 'ABERTO') RETURNING id");
            $stmt_ticket->execute([$usuario_id, $assunto]); $ticket_id = $stmt_ticket->fetchColumn();
            if (!$ticket_id) throw new Exception("Não foi possível criar o ticket.");
            $stmt_msg = $pdo->prepare("INSERT INTO suporte_mensagens (ticket_id, remetente_id, mensagem, anexo_url) VALUES (?, ?, ?, ?)");
            $stmt_msg->execute([$ticket_id, $usuario_id, $mensagem, $anexo_path]);
            $pdo->commit(); header("Location: support.php?view=detail&ticket_id={$ticket_id}&status=created"); exit;
        }

        // Ação: Adicionar Resposta
        elseif ($action === 'add_reply') {
            $ticket_id = (int)($_POST['ticket_id'] ?? 0); $mensagem = trim($_POST['mensagem_resposta'] ?? ''); $anexo_path = null;
            if ($ticket_id <= 0) { throw new Exception("ID do ticket inválido."); }
            if (empty($mensagem) && (!isset($_FILES['anexo']) || $_FILES['anexo']['error'] !== UPLOAD_ERR_OK)) { throw new Exception("A mensagem ou um anexo é obrigatório para responder."); }
            if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
                 $file = $_FILES['anexo']; $allowed_types = ['image/jpeg', 'image/png', 'image/gif']; $max_size = 5 * 1024 * 1024;
                 if (!in_array($file['type'], $allowed_types)) { throw new Exception("Tipo de arquivo inválido. Apenas JPG, PNG e GIF."); }
                 if ($file['size'] > $max_size) { throw new Exception("Arquivo muito grande (Máx: 5MB)."); }
                 $ext = pathinfo($file['name'], PATHINFO_EXTENSION); $filename = uniqid('reply_' . $ticket_id . '_', true) . '.' . strtolower($ext);
                 $destination = UPLOAD_DIR_SUPPORT . $filename;
                 if (!move_uploaded_file($file['tmp_name'], $destination)) { throw new Exception("Falha ao salvar anexo."); }
                 $anexo_path = 'uploads/support/' . $filename;
            }
            $stmt_check = $pdo->prepare("SELECT status FROM suporte_tickets WHERE id = ? AND usuario_id = ?"); $stmt_check->execute([$ticket_id, $usuario_id]); $ticket_status = $stmt_check->fetchColumn();
            if (!$ticket_status || $ticket_status === 'FECHADO') { throw new Exception("Ticket não encontrado ou está fechado."); }
            $stmt_msg = $pdo->prepare("INSERT INTO suporte_mensagens (ticket_id, remetente_id, mensagem, anexo_url) VALUES (?, ?, ?, ?)"); $stmt_msg->execute([$ticket_id, $usuario_id, $mensagem, $anexo_path]);
            $novo_status = ($ticket_status === 'AGUARDANDO_USUARIO' || $ticket_status === 'RESPONDIDO_ADMIN') ? 'RESPONDIDO_USUARIO' : $ticket_status;
            $stmt_update_status = $pdo->prepare("UPDATE suporte_tickets SET status = ?, data_fechamento_automatico = NULL, data_ultima_atualizacao = NOW() WHERE id = ?"); $stmt_update_status->execute([$novo_status, $ticket_id]);
            $pdo->commit(); header("Location: support.php?view=detail&ticket_id={$ticket_id}&status=replied"); exit;
        }

        // Ação: Encerrar Ticket
        elseif ($action === 'close_ticket') {
            $ticket_id = (int)($_POST['ticket_id'] ?? 0);
            if ($ticket_id <= 0) { throw new Exception("ID do ticket inválido."); }
            $stmt_update = $pdo->prepare("UPDATE suporte_tickets SET status = 'FECHADO', data_fechamento = NOW(), fechado_por = 'USUARIO', data_ultima_atualizacao = NOW() WHERE id = ? AND usuario_id = ? AND status <> 'FECHADO'");
            $rows_affected = $stmt_update->execute([$ticket_id, $usuario_id]);
            if ($rows_affected === 0) { throw new Exception("Não foi possível fechar o ticket."); }
            $pdo->commit();
            // ⭐ AJUSTE: Redireciona de volta para a view de detalhes para avaliação
            header("Location: support.php?view=detail&ticket_id=" . $ticket_id . "&status=closed_by_user"); exit;
        }

        // Ação: Avaliar Ticket
        elseif ($action === 'rate_ticket') {
            $ticket_id = (int)($_POST['ticket_id'] ?? 0); $avaliacao = (int)($_POST['avaliacao'] ?? 0);
            if ($ticket_id <= 0) { throw new Exception("ID do ticket inválido."); }
            if ($avaliacao < 1 || $avaliacao > 5) { throw new Exception("Avaliação inválida."); }
            $stmt_check = $pdo->prepare("SELECT status, avaliacao FROM suporte_tickets WHERE id = ? AND usuario_id = ?");
            $stmt_check->execute([$ticket_id, $usuario_id]); $ticket_data = $stmt_check->fetch(PDO::FETCH_ASSOC);
            if (!$ticket_data) { throw new Exception("Ticket não encontrado."); }
            if ($ticket_data['status'] !== 'FECHADO') { throw new Exception("Só é possível avaliar tickets fechados."); }
            if ($ticket_data['avaliacao'] !== null) { throw new Exception("Este ticket já foi avaliado."); }
            $stmt_rate = $pdo->prepare("UPDATE suporte_tickets SET avaliacao = ? WHERE id = ?");
            $stmt_rate->execute([$avaliacao, $ticket_id]);
            $pdo->commit(); $feedback_message = "Obrigado pela sua avaliação!"; $feedback_type = 'success';
            $_GET['ticket_id'] = $ticket_id; $view_mode = 'detail'; // Mantém na view
        }
        else { $pdo->rollBack(); } // Nenhuma ação válida

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $feedback_message = $e->getMessage(); $feedback_type = 'error';
        if (isset($_POST['ticket_id'])) { $_GET['ticket_id'] = (int)$_POST['ticket_id']; $view_mode = 'detail'; }
        elseif ($action === 'create_ticket') { $view_mode = 'new'; }
        else { $view_mode = 'list'; }
    }
}

// --- Lógica de Exibição (GET) ---
$view_param = $_GET['view'] ?? 'list';
$ticket_id_param = (int)($_GET['ticket_id'] ?? 0);

if ($view_param === 'new') {
    $view_mode = 'new';
} elseif ($view_param === 'detail' && $ticket_id_param > 0) {
    $stmt_ticket = $pdo->prepare("SELECT *, AGE(data_fechamento_automatico, NOW()) as tempo_restante FROM suporte_tickets WHERE id = ? AND usuario_id = ?");
    $stmt_ticket->execute([$ticket_id_param, $usuario_id]);
    $ticket_selecionado = $stmt_ticket->fetch(PDO::FETCH_ASSOC);
    if ($ticket_selecionado) {
        $view_mode = 'detail';
        $stmt_msgs = $pdo->prepare("SELECT sm.*, u.nome AS nome_remetente, u.nivel_acesso FROM suporte_mensagens sm JOIN usuarios u ON sm.remetente_id = u.id WHERE sm.ticket_id = ? ORDER BY sm.data_envio ASC");
        $stmt_msgs->execute([$ticket_id_param]);
        $mensagens_ticket = $stmt_msgs->fetchAll(PDO::FETCH_ASSOC);
        try {
            $pdo->prepare("UPDATE suporte_tickets SET usuario_ultima_visualizacao = NOW() WHERE id = ?")->execute([$ticket_id_param]);
            $ticket_selecionado['usuario_ultima_visualizacao'] = date('Y-m-d H:i:s');
        } catch (PDOException $e) { error_log("Erro ao atualizar visualizacao do ticket {$ticket_id_param}: " . $e->getMessage()); }
        if(isset($_GET['status']) && $_GET['status'] === 'created') { $feedback_message = "Ticket aberto com sucesso!"; $feedback_type = 'success'; }
        if(isset($_GET['status']) && $_GET['status'] === 'replied') { $feedback_message = "Sua resposta foi enviada!"; $feedback_type = 'success'; }
        // ⭐ Nova Mensagem
        if(isset($_GET['status']) && $_GET['status'] === 'closed_by_user') { $feedback_message = "Ticket encerrado. Por favor, avalie o atendimento."; $feedback_type = 'info'; }
    } else {
        $view_mode = 'list'; $feedback_message = "Ticket não encontrado ou acesso não permitido."; $feedback_type = 'error';
    }
} else {
    $view_mode = 'list';
    if(isset($_GET['status']) && $_GET['status'] === 'closed') { $feedback_message = "Ticket #". (int)$_GET['ticket_id'] . " encerrado com sucesso!"; $feedback_type = 'success'; }
}

$lista_tickets = [];
if ($view_mode === 'list') {
    $stmt_list = $pdo->prepare("SELECT id, assunto, status, data_ultima_atualizacao FROM suporte_tickets WHERE usuario_id = ? ORDER BY data_ultima_atualizacao DESC");
    $stmt_list->execute([$usuario_id]);
    $lista_tickets = $stmt_list->fetchAll(PDO::FETCH_ASSOC);
}

// Funções Auxiliares (mantidas)
function formatarData($data) { if(empty($data)) return 'N/A'; try { $dt = new DateTime($data); return $dt->format('d/m/Y H:i'); } catch (Exception $e) { return 'Data inválida'; } }
function formatarTempoRestante($interval_string) { if (empty($interval_string) || strpos($interval_string, '-') === 0) return null; preg_match('/(?:(\d+)\s+days?\s*)?(?:(\d{1,2}):(\d{1,2}):(\d{1,2}))?/', $interval_string, $matches); $dias = (int)($matches[1] ?? 0); $horas = (int)($matches[2] ?? 0); $minutos = (int)($matches[3] ?? 0); if ($dias > 0) return "{$dias}d " . ($horas > 0 ? "{$horas}h" : ''); if ($horas > 0) return "{$horas}h " . ($minutos > 0 ? "{$minutos}m" : ''); if ($minutos > 0) return "{$minutos}m"; return "< 1m"; }

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suporte ao Cliente - <?php echo $nome_usuario; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <link rel="icon" type="image/x-icon" href="/favicon1.ico">
    <style>
        /* === CSS COMPLETO DA index.php === */
        :root { --primary-color: #00aaff; --background-color: #111827; --sidebar-color: #1f2937; --glass-background: rgba(31, 41, 55, 0.5); --text-color: #f9fafb; --text-muted: #9ca3af; --border-color: rgba(255, 255, 255, 0.1); --success-color: #10b981; --error-color: #f87171; --info-color: #3b82f6; --admin-message-bg: #374151; --warning-color: #f59e0b; --star-color: #facc15; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--background-color); color: var(--text-color); display: flex; min-height: 100vh; }
        #particles-js { position: fixed; width: 100%; height: 100%; top: 0; left: 0; z-index: -1; }
        .sidebar { width: 260px; background-color: var(--sidebar-color); height: 100vh; position: fixed; left: 0; top: 0; padding: 2rem 1.5rem; display: flex; flex-direction: column; border-right: 1px solid var(--border-color); z-index: 1000; transition: transform 0.3s ease; }
        .sidebar .logo-area { display: flex; flex-direction: column; align-items: center; margin-bottom: -1rem; padding-bottom: 1rem; }
        .sidebar .logo-circle { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 0.75rem; box-shadow: 0 0 15px rgba(0, 170, 255, 0.6); overflow: hidden; }
        .sidebar .logo-circle img { max-width: 100%; max-height: 100%; display: block; object-fit: contain; }
        .sidebar .logo-text { font-size: 1.2rem; font-weight: 600; color: var(--text-color); text-align: center; }
        .sidebar .divider { width: 100%; height: 1px; background-color: var(--border-color); margin: 1.5rem 0; }
        .sidebar nav { flex-grow: 1; overflow-y: auto; scrollbar-width: thin; scrollbar-color: var(--primary-color) transparent; }
        .sidebar nav::-webkit-scrollbar { width: 5px; }
        .sidebar nav::-webkit-scrollbar-thumb { background-color: var(--primary-color); border-radius: 10px; }
        .sidebar nav a { display: flex; align-items: center; gap: 1rem; padding: 1rem; color: var(--text-muted); text-decoration: none; border-radius: 8px; margin-bottom: 0.5rem; transition: all 0.3s ease; border: 1px solid transparent; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2); background-color: var(--glass-background); }
        .sidebar nav a:hover, .sidebar nav a.active { background-color: rgba(0, 170, 255, 0.2); color: var(--text-color); border-color: var(--primary-color); box-shadow: 0 4px 15px rgba(0, 170, 255, 0.4); }
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

        /* ⭐ LAYOUT PRINCIPAL CORRIGIDO */
        .main-content {
            margin-left: 260px; flex-grow: 1;
            padding: 2rem 3rem;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
            width: calc(100% - 260px);
            display: flex; /* Habilita flexbox */
            flex-direction: column; /* Conteúdo empilhado verticalmente */
            overflow-y: hidden; /* CRÍTICO: Previne scroll na página inteira e limita altura */
        }
        .menu-toggle { display: none; position: fixed; top: 1.5rem; left: 1.5rem; z-index: 1001; cursor: pointer; padding: 10px; background-color: var(--sidebar-color); border-radius: 8px; border: 1px solid var(--border-color); }
        .menu-toggle svg { width: 24px; height: 24px; color: var(--text-color); }

        /* === RESPONSIVIDADE BASE (Copiado da index.php) === */
        @media (max-width: 1024px) {
            .sidebar { width: 280px; transform: translateX(-280px); box-shadow: 5px 0 15px rgba(0, 0, 0, 0.5); z-index: 1002; height: 100%; overflow-y: auto; }
            .user-profile { margin-top: 1.5rem; position: relative; }
            .menu-toggle { display: flex; }
            .main-content { margin-left: 0; width: 100%; padding: 1.5rem; padding-top: 5rem; overflow-y: auto; /* Permite scroll no mobile para o conteúdo principal */ }
            body.sidebar-open .sidebar { transform: translateX(0); }
            body.sidebar-open::after { content: ''; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.7); z-index: 1001; }
        }
          @media (max-width: 576px) {
            .main-content { padding: 1rem; padding-top: 4.5rem; }
            .sidebar nav a { padding: 0.8rem; }
            .user-profile { padding: 0.5rem; gap: 0.5rem;}
            .avatar { width: 32px; height: 32px; font-size: 0.9rem;}
        }

        /* === ESTILOS ESPECÍFICOS DA PÁGINA DE SUPORTE === */
        .support-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; flex-wrap: wrap; gap: 1rem; flex-shrink: 0; }
        .support-header h1 { font-size: 2rem; font-weight: 600; }
        .btn { padding: 0.6rem 1.2rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; transition: background-color 0.3s; }
        .btn svg { width: 18px; height: 18px; }
        .btn-primary { background-color: var(--primary-color); color: white; }
        .btn-primary:hover { background-color: #0088cc; }
        .btn-secondary { background-color: var(--glass-background); color: var(--text-color); border: 1px solid var(--border-color); }
        .btn-secondary:hover { background-color: var(--sidebar-color); }
        .btn-danger { background-color: #dc2626; color: white; padding: 0.5rem 1rem; font-size: 0.9rem; }
        .btn-danger:hover { background-color: #b91c1c; }

        /* ⭐ Grid Detalhes Ticket (MANTIDO) */
        .ticket-detail-grid {
            display: grid;
            grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
            gap: 2rem;
            flex-grow: 1; /* Ocupa espaço vertical restante */
            overflow: hidden; /* Previne overflow geral */
            min-height: 0; /* CRÍTICO: Correção para flexbox em grids */
        }
        .chat-column {
            display: flex;
            flex-direction: column;
            overflow: hidden; /* Contém o chat e o form de resposta */
            min-height: 0; /* CRÍTICO: Permite que o flex-grow do .support-card funcione */
            height: 722px; /* MANTIDO COMO SOLUÇÃO TEMPORÁRIA PARA DESKTOP SCROLL */
        }
        .info-column {
            background: var(--sidebar-color);
            padding: 2.65rem;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            height: 722px; /* MANTIDO COMO SOLUÇÃO TEMPORÁRIA PARA DESKTOP SCROLL */
        }

        /* Painel de Informações */
        .info-column h3 { font-size: 1.2rem; margin-bottom: 1.5rem; padding-bottom: 0.8rem; border-bottom: 1px solid var(--border-color); }
        .info-item { margin-bottom: 1rem; }
        .info-item label { display: block; font-size: 0.8rem; color: var(--text-muted); font-weight: 500; margin-bottom: 0.2rem; text-transform: uppercase; }
        .info-item .value { font-size: 0.95rem; font-weight: 500; word-wrap: break-word; }
        .info-item .status-tag { display: inline-block; }
        .info-item .value.warning { color: var(--warning-color); font-weight: 600; }
        .info-actions { margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border-color); }
        .info-actions button { width: 100%; margin-top: 24px; }

        /* ⭐ NOVO: CAIXA DE HORÁRIO DE ATENDIMENTO */
        @keyframes pulse-border {
            0% { box-shadow: 0 0 0 0 rgba(0, 170, 255, 0.4); }
            70% { box-shadow: 0 0 0 8px rgba(0, 170, 255, 0); }
            100% { box-shadow: 0 0 0 0 rgba(0, 170, 255, 0); }
        }
        .service-hours-box {
            background-color: var(--background-color);
            border: 2px solid var(--primary-color);
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1.5rem;
            text-align: center;
            font-size: 0.9rem;
            line-height: 1.4;
            animation: pulse-border 2s infinite;
        }
        .service-hours-box strong {
            display: block;
            margin-bottom: 0.3rem;
            color: var(--primary-color);
        }
        /* FIM NOVO: CAIXA DE HORÁRIO DE ATENDIMENTO */


        /* Lista de Tickets */
        .tickets-list { list-style: none; padding: 0; }
        .ticket-item { background: var(--glass-background); border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: 1rem; padding: 1rem 1.5rem; display: flex; justify-content: space-between; align-items: center; transition: background-color 0.2s; }
        .ticket-item:hover { background-color: rgba(255, 255, 255, 0.08); }
        .ticket-info a { color: var(--text-color); text-decoration: none; font-weight: 500; }
        .ticket-info span { display: block; font-size: 0.85rem; color: var(--text-muted); margin-top: 0.2rem; }
        .ticket-status { font-weight: 600; padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.8rem; text-align: center; min-width: 100px; white-space: nowrap; }
        .status-ABERTO,.status-RESPONDIDO_USUARIO { background-color: rgba(59, 130, 246, 0.2); color: var(--info-color); }
        .status-AGUARDANDO_USUARIO { background-color: rgba(245, 158, 11, 0.2); color: var(--warning-color); }
        .status-RESPONDIDO_ADMIN { background-color: rgba(34, 197, 94, 0.2); color: var(--success-color); }
        .status-FECHADO { background-color: rgba(107, 114, 128, 0.2); color: var(--text-muted); }

        /* Formulários */
        .support-card { background: var(--sidebar-color); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color); margin-top: 1rem; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; font-weight: 500; margin-bottom: 0.5rem; }
        .form-group input[type="text"], .form-group textarea { width: 100%; padding: 0.75rem 1rem; background-color: var(--background-color); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-color); font-size: 1rem; font-family: 'Poppins', sans-serif; }
        .form-group textarea { resize: vertical; min-height: 100px; }
        .form-group input:focus, .form-group textarea:focus { border-color: var(--primary-color); outline: none; }
        .form-group input[type="file"] { background-color: transparent; border: none; padding: 0; color: var(--text-muted); }
        .form-group input[type="file"]::file-selector-button { background-color: var(--glass-background); border: 1px solid var(--border-color); color: var(--text-color); padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; transition: background-color 0.2s; margin-right: 1rem; font-weight: 500; }
        .form-group input[type="file"]::file-selector-button:hover { background-color: var(--sidebar-color); }

        /* ⭐ Chat / Mensagens (CORREÇÃO DE SCROLL UNIVERSAL) */
        .chat-column .support-card {
            display: flex;
            flex-direction: column;
            flex-grow: 1; /* Ocupa 100% da altura do chat-column */
            overflow: hidden; /* Contém o scroll para não estourar */
            padding: 1.5rem;
            margin-top: 0;
            /* min-height é ajustado no media query se necessário */
        }
        .chat-messages {
            flex-grow: 1;           /* CRÍTICO: Ocupa todo o espaço restante entre o topo e o formulário */
            overflow-y: auto;       /* ATIVA O SCROLL AQUI */
            padding-bottom: 1.5rem; /* Adiciona o espaçamento interno no final do chat */
            padding-right: 10px;
            scrollbar-width: thin;
            scrollbar-color: var(--primary-color) transparent;
        }
        .chat-messages::-webkit-scrollbar { width: 5px; }
        .chat-messages::-webkit-scrollbar-thumb { background-color: var(--primary-color); border-radius: 10px; }

        .message-wrapper { display: flex; align-items: flex-start; gap: 0.75rem; margin-bottom: 1rem; }
        .message-icon { width: 32px; height: 32px; border-radius: 50%; background-color: var(--glass-background); display: flex; align-items: center; justify-content: center; flex-shrink: 0; border: 1px solid var(--border-color); }
        .message-icon svg { width: 18px; height: 18px; color: var(--text-muted); }
        .message-content { flex-grow: 1; min-width: 0; /* Correção para flexbox */ }
        .message-bubble { max-width: 100%; padding: 0.8rem 1.2rem; border-radius: 18px; line-height: 1.5; word-wrap: break-word; position: relative; }
        .message-bubble.user { background-color: var(--primary-color); color: white; border-bottom-right-radius: 4px; }
        .message-bubble.admin { background-color: var(--admin-message-bg); color: var(--text-color); border-bottom-left-radius: 4px; }
        .message-wrapper.user { justify-content: flex-end; }
        .message-wrapper.user .message-icon { order: 2; }
        .message-wrapper.user .message-content { order: 1; align-items: flex-end; /* Alinha conteúdo */ display: flex; flex-direction: column; }
        .message-meta { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.3rem; display: flex; align-items: center; gap: 5px; }
        .message-wrapper.user .message-meta { justify-content: flex-end; }
        .message-wrapper.admin .message-meta { justify-content: flex-start; }
        .message-attachment { margin-top: 0.5rem; }
        .message-attachment img { max-width: 200px; max-height: 200px; border-radius: 8px; border: 1px solid var(--border-color); cursor: pointer; display: block; }
        .read-status svg { width: 14px; height: 14px; display: inline-block; vertical-align: middle; }
        .read-status.read svg { color: var(--primary-color); }
        .reply-form {
            margin-top: auto;
            flex-shrink: 0; /* Impede que ele seja comprimido */
            padding-top: 0.5rem; /* Adiciona um pequeno espaço acima da área de resposta */
        }
        .reply-form textarea { min-height: 80px; }

        /* Sistema de Avaliação */
        .rating-section { margin-top: 1rem; padding-top: 1rem; border-top: 1px dashed var(--border-color); }
        .rating-section label { display: block; font-weight: 500; margin-bottom: 0.8rem; text-align: center; font-size: 0.9rem; color: var(--text-muted); }
        .rating-stars { display: flex; justify-content: center; gap: 0.5rem; margin-bottom: 1rem; flex-direction: row-reverse; }
        .rating-stars input[type="radio"] { display: none; }
        .rating-stars label { cursor: pointer; transition: transform 0.2s, color 0.2s; color: var(--text-muted); }
        .rating-stars label svg { width: 28px; height: 28px; }
        .rating-stars label:hover, .rating-stars label:hover ~ label { color: var(--star-color); }
        .rating-stars input[type="radio"]:checked ~ label { color: var(--star-color); }
        .rating-section button { width: 100%; margin-top: 0.5rem; font-size: 0.9rem; padding: 0.6rem; }
        .rating-display { text-align: center; margin-top: 1rem; }
        .rating-display span { color: var(--star-color); font-weight: 600; font-size: 1.1rem; letter-spacing: 2px; }

        /* Feedback Messages */
        .feedback { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 500; border: 1px solid transparent; flex-shrink: 0; /* Impede que encolha */ }
        .feedback.success { background-color: rgba(34, 197, 94, 0.1); border-color: rgba(34, 197, 94, 0.4); color: var(--success-color); }
        .feedback.error { background-color: rgba(225, 29, 72, 0.1); border-color: rgba(225, 29, 72, 0.4); color: var(--error-color); }
        .feedback.info { background-color: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.4); color: var(--info-color); }

        /* ⭐ MODAL DE CONFIRMAÇÃO */
        .modal {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.7);
            display: none; justify-content: center; align-items: center; z-index: 9999;
        }
        .modal-content {
            background-color: var(--sidebar-color); color: var(--text-color);
            padding: 30px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
            max-width: 400px; text-align: center;
        }
        .modal-content h3 { margin-bottom: 1rem; font-size: 1.5rem; }
        .modal-buttons { margin-top: 1.5rem; display: flex; justify-content: space-around; }
        .modal-buttons .btn { width: 45%; }


        /* --- RESPONSIVIDADE ESPECÍFICA SUPORTE (Ajustada para Flexbox) --- */
        @media (max-width: 1024px) {
            .ticket-detail-grid { grid-template-columns: 1fr; }
            .info-column { margin-top: 2rem; grid-row: 1; height: auto; } /* Remove height fixo no tablet */

            .chat-column { min-height: 50vh; height: auto; } /* Remove height fixo no tablet */
            .chat-column .support-card {
                min-height: 40vh;
            }
        }
        @media (max-width: 576px) {
            .support-header { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .ticket-item { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
            .ticket-status { margin-top: 0.5rem; align-self: flex-end; }
            .message-bubble { max-width: 90%; }

            .chat-column { height: 40vh; }
            .chat-column .support-card {
                min-height: 350px;
            }
            .info-column { padding: 1.5rem; margin-top: 1px }
        }
    </style>
</head>
<body>
    <div id="particles-js"></div>
    <div class="menu-toggle" id="menu-toggle">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
    </div>

    <?php include '_sidebar.php'; ?>

    <main class="main-content">

        <?php if ($feedback_message): ?>
            <div class="feedback <?php echo $feedback_type; ?>">
                <?php echo htmlspecialchars($feedback_message); ?>
            </div>
        <?php endif; ?>

        <?php /* --- VIEWS LIST e NEW (Inalteradas) --- */ ?>
        <?php if ($view_mode === 'list'): ?>
            <div class="support-header">
                <h1>Meus Chamados</h1>
                <a href="support.php?view=new" class="btn btn-primary"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" width="20"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>Abrir Novo Ticket</a>
            </div>
            <?php if (empty($lista_tickets)): ?>
                <div class="support-card" style="text-align: center;"><p style="color: var(--text-muted);">Você ainda não abriu nenhum ticket de suporte.</p></div>
            <?php else: ?>
                <ul class="tickets-list">
                    <?php foreach ($lista_tickets as $ticket): ?>
                        <li class="ticket-item">
                            <div class="ticket-info">
                                <a href="support.php?view=detail&ticket_id=<?php echo $ticket['id']; ?>">#<?php echo $ticket['id']; ?> - <?php echo htmlspecialchars($ticket['assunto']); ?></a>
                                <span>Última atualização: <?php echo formatarData($ticket['data_ultima_atualizacao']); ?></span>
                            </div>
                            <span class="ticket-status status-<?php echo $ticket['status']; ?>"><?php echo str_replace('_', ' ', $ticket['status']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($view_mode === 'new'): ?>
            <div class="support-header">
                <h1>Abrir Novo Ticket</h1>
                <a href="support.php?view=list" class="btn btn-secondary"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" width="20"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>Voltar</a>
            </div>
            <div class="support-card">
                <form action="support.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="create_ticket">
                    <div class="form-group"><label for="assunto">Assunto</label><input type="text" id="assunto" name="assunto" required maxlength="255" value="<?php echo htmlspecialchars($_POST['assunto'] ?? ''); ?>"></div>
                    <div class="form-group"><label for="mensagem">Descreva seu problema ou dúvida</label><textarea id="mensagem" name="mensagem" required><?php echo htmlspecialchars($_POST['mensagem'] ?? ''); ?></textarea></div>
                    <div class="form-group"><label for="anexo">Anexar Imagem (Opcional - JPG, PNG, GIF, max 5MB)</label><input type="file" id="anexo" name="anexo" accept="image/jpeg, image/png, image/gif"></div>
                    <button type="submit" class="btn btn-primary">Enviar Ticket</button>
                </form>
            </div>
        <?php endif; ?>


        <?php /* --- VIEW DETAIL --- */ ?>
        <?php if ($view_mode === 'detail' && $ticket_selecionado): ?>
             <div class="support-header">
                <h1>Ticket #<?php echo $ticket_selecionado['id']; ?></h1>
                <a href="support.php?view=list" class="btn btn-secondary"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" width="20"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>Voltar</a>
            </div>

             <div class="ticket-detail-grid">

                <div class="chat-column">
                    <div class="support-card">
                        <div class="chat-messages">
                            <?php foreach ($mensagens_ticket as $msg):
                                $isAdmin = ($msg['nivel_acesso'] === 'admin');
                                $isUser = !$isAdmin;
                                $messageTimestamp = strtotime($msg['data_envio']);
                                $isAdminLastView = $ticket_selecionado['admin_ultima_visualizacao'] ? strtotime($ticket_selecionado['admin_ultima_visualizacao']) : 0;
                                $isReadByAdmin = $isUser && $isAdminLastView >= $messageTimestamp;
                            ?>
                                <div class="message-wrapper <?php echo $isAdmin ? 'admin' : 'user'; ?>">
                                    <div class="message-icon">
                                        <?php if ($isAdmin): ?>
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                                        <?php else: ?>
                                            <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                                        <?php endif; ?>
                                    </div>
                                    <div class="message-content">
                                        <div class="message-bubble <?php echo $isAdmin ? 'admin' : 'user'; ?>">
                                            <?php if(!empty($msg['mensagem'])) echo nl2br(htmlspecialchars($msg['mensagem'])); ?>
                                            <?php if (!empty($msg['anexo_url'])): ?>
                                                <div class="message-attachment">
                                                    <a href="/<?php echo htmlspecialchars($msg['anexo_url']); ?>" target="_blank" title="Clique para ampliar">
                                                        <img src="/<?php echo htmlspecialchars($msg['anexo_url']); ?>" alt="Anexo">
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                            <div class="message-meta">
                                                   <?php echo date('d/m H:i', $messageTimestamp); ?>
                                                   <?php if($isUser): ?>
                                                       <span class="read-status <?php echo $isReadByAdmin ? 'read' : ''; ?>" title="<?php echo $isReadByAdmin ? 'Visto pelo suporte' : 'Enviado'; ?>">
                                                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="14"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"></path><?php if($isReadByAdmin) echo '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 7.75l-6 6-1.5-1.5"></path>'; ?></svg>
                                                        </span>
                                                   <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($ticket_selecionado['status'] !== 'FECHADO'): ?>
                            <form id="close-ticket-form" action="support.php" method="POST" class="reply-form">
                                <input type="hidden" name="action" value="add_reply">
                                <input type="hidden" name="ticket_id" value="<?php echo $ticket_selecionado['id']; ?>">
                                <div class="form-group"><label for="mensagem_resposta">Sua Resposta</label><textarea id="mensagem_resposta" name="mensagem_resposta"></textarea></div>
                                <div class="form-group"><label for="anexo_resposta">Anexar Imagem (Opcional)</label><input type="file" id="anexo_resposta" name="anexo" accept="image/jpeg, image/png, image/gif"></div>
                                <button type="submit" class="btn btn-primary">Enviar Resposta</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info-column">
                     <h3>Detalhes do Ticket</h3>
                     <div class="info-item"><label>Assunto</label><span class="value"><?php echo htmlspecialchars($ticket_selecionado['assunto']); ?></span></div>
                     <div class="info-item"><label>Status</label><span class="ticket-status status-<?php echo $ticket_selecionado['status']; ?> status-tag value"><?php echo str_replace('_', ' ', $ticket_selecionado['status']); ?></span></div>
                     <div class="info-item"><label>Aberto em</label><span class="value"><?php echo formatarData($ticket_selecionado['data_criacao']); ?></span></div>
                     <div class="info-item"><label>Última Atualização</label><span class="value"><?php echo formatarData($ticket_selecionado['data_ultima_atualizacao']); ?></span></div>
                     <?php if ($ticket_selecionado['status'] === 'AGUARDANDO_USUARIO' && $ticket_selecionado['data_fechamento_automatico']):
                            $tempo_restante = formatarTempoRestante($ticket_selecionado['tempo_restante']); ?>
                           <div class="info-item"><label>Prazo para Resposta</label><span class="value warning"><?php echo $tempo_restante ? "Aprox. {$tempo_restante}" : 'Expirando'; ?></span><span style="font-size:0.8rem; color:var(--text-muted); display: block;">(Fecha automaticamente em <?php echo formatarData($ticket_selecionado['data_fechamento_automatico']); ?>)</span></div>
                     <?php endif; ?>
                     <?php if ($ticket_selecionado['status'] === 'FECHADO'): ?>
                           <div class="info-item"><label>Encerrado em</label><span class="value"><?php echo formatarData($ticket_selecionado['data_fechamento']); ?></span></div>
                           <?php if (!empty($ticket_selecionado['fechado_por'])): ?><div class="info-item"><label>Encerrado por</label><span class="value"><?php echo ucfirst(strtolower($ticket_selecionado['fechado_por'])); ?></span></div><?php endif; ?>

                           <div class="rating-section">
                               <?php if ($ticket_selecionado['avaliacao'] === null): ?>
                                 <label>Avalie nosso atendimento:</label>
                                 <form action="support.php" method="POST">
                                     <input type="hidden" name="action" value="rate_ticket">
                                     <input type="hidden" name="ticket_id" value="<?php echo $ticket_selecionado['id']; ?>">
                                     <div class="rating-stars" id="rating-stars">
                                         <input type="radio" id="star5" name="avaliacao" value="5" required><label for="star5" title="5 estrelas"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 17.27l-5.18 3.73l1.64-7.03L2 9.27l7.2-.61L12 2l2.8 6.66l7.2.61l-6.46 4.7l1.64 7.03L12 17.27z"></path></svg></label>
                                         <input type="radio" id="star4" name="avaliacao" value="4"><label for="star4" title="4 estrelas"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 17.27l-5.18 3.73l1.64-7.03L2 9.27l7.2-.61L12 2l2.8 6.66l7.2.61l-6.46 4.7l1.64 7.03L12 17.27z"></path></svg></label>
                                         <input type="radio" id="star3" name="avaliacao" value="3"><label for="star3" title="3 estrelas"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 17.27l-5.18 3.73l1.64-7.03L2 9.27l7.2-.61L12 2l2.8 6.66l7.2.61l-6.46 4.7l1.64 7.03L12 17.27z"></path></svg></label>
                                         <input type="radio" id="star2" name="avaliacao" value="2"><label for="star2" title="2 estrelas"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 17.27l-5.18 3.73l1.64-7.03L2 9.27l7.2-.61L12 2l2.8 6.66l7.2.61l-6.46 4.7l1.64 7.03L12 17.27z"></path></svg></label>
                                         <input type="radio" id="star1" name="avaliacao" value="1"><label for="star1" title="1 estrela"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 17.27l-5.18 3.73l1.64-7.03L2 9.27l7.2-.61L12 2l2.8 6.66l7.2.61l-6.46 4.7l1.64 7.03L12 17.27z"></path></svg></label>
                                     </div>
                                     <button type="submit" class="btn btn-primary">Enviar Avaliação</button>
                                 </form>
                               <?php else: ?>
                                 <div class="rating-display info-item">
                                        <label>Sua Avaliação</label>
                                        <span title="<?php echo $ticket_selecionado['avaliacao']; ?> estrelas"><?php echo str_repeat('⭐', $ticket_selecionado['avaliacao']); ?></span>
                                  </div>
                               <?php endif; ?>
                           </div>
                     <?php endif; ?>

                     <?php if ($ticket_selecionado['status'] !== 'FECHADO'): ?>
                         <div class="info-actions">
                            <button type="button" class="btn btn-danger" onclick="showCloseTicketModal()"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" width="18"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>Encerrar Ticket</button>
                         </div>
                     <?php endif; ?>

                     <div class="service-hours-box">
                        <strong>Horário de Atendimento</strong>
                        Segunda a Sexta-feira
                        das 10h às 18h
                    </div>
                     </div>

             </div>
        <?php endif; ?>

    </main>

    <div id="close-ticket-modal" class="modal">
        <div class="modal-content">
            <h3>Confirmar Encerramento</h3>
            <p>Tem certeza que deseja encerrar este ticket? Esta ação é definitiva e encerrará a comunicação com o suporte.</p>
            <div class="modal-buttons">
                <button type="button" class="btn btn-secondary" onclick="hideCloseTicketModal()">Cancelar</button>
                <form id="close-ticket-hidden-form" action="support.php" method="POST" style="margin: 0; display: inline;">
                    <input type="hidden" name="action" value="close_ticket">
                    <input type="hidden" name="ticket_id" value="<?php echo $ticket_selecionado['id'] ?? 0; ?>">
                    <button type="submit" class="btn btn-danger">Encerrar Agora</button>
                </form>
            </div>
        </div>
    </div>
    <script>

        // --- FUNÇÕES MODAL ---
        const modal = document.getElementById('close-ticket-modal');
        function showCloseTicketModal() {
            modal.style.display = 'flex';
        }

        function hideCloseTicketModal() {
            modal.style.display = 'none';
        }

        document.addEventListener('DOMContentLoaded', () => {
            // --- SCRIPT PADRÃO DO SITE ---
            // ⭐ Usando a configuração de particles da index.php
            particlesJS('particles-js', {
                "particles": { "number": { "value": 80, "density": { "enable": true, "value_area": 800 } }, "color": { "value": "#00aaff" }, "shape": { "type": "circle" }, "opacity": { "value": 0.5, "random": false }, "size": { "value": 3, "random": true }, "line_linked": { "enable": true, "distance": 150, "color": "#ffffff", "opacity": 0.1, "width": 1 }, "move": { "enable": true, "speed": 2, "direction": "none", "random": false, "straight": false, "out_mode": "out", "bounce": false } },
                "interactivity": { "detect_on": "canvas", "events": { "onhover": { "enable": true, "mode": "repulse" }, "onclick": { "enable": true, "mode": "push" } } }, "retina_detect": true
            });

            // --- LÓGICA PADRÃO SIDEBAR/DROPDOWN (Copiada da index.php) ---
            const menuToggle = document.getElementById('menu-toggle');
            const sidebar = document.querySelector('.sidebar');
            const body = document.body;
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', (event) => { event.stopPropagation(); body.classList.toggle('sidebar-open'); });
                body.addEventListener('click', (event) => { if (body.classList.contains('sidebar-open') && !sidebar.contains(event.target) && !menuToggle.contains(event.target)) { body.classList.remove('sidebar-open'); } });
                sidebar.querySelectorAll('nav a').forEach(link => { link.addEventListener('click', () => { if (body.classList.contains('sidebar-open')) { body.classList.remove('sidebar-open'); } }); });
            }
            const userProfileMenu = document.getElementById('user-profile-menu'); // Assegure-se que este ID exista no seu _sidebar.php
            const dropdown = document.getElementById('profile-dropdown');
            if (userProfileMenu && dropdown) {
                userProfileMenu.addEventListener('click', (event) => { event.stopPropagation(); dropdown.classList.toggle('show'); });
                window.addEventListener('click', (event) => { if (dropdown.classList.contains('show') && !userProfileMenu.contains(event.target) && !dropdown.contains(event.target)) { dropdown.classList.remove('show'); } });
            }

            // --- SCRIPT ESPECÍFICO SUPORTE ---
            // Scroll do chat
            const chatMessages = document.querySelector('.chat-messages');
            if (chatMessages) {
                chatMessages.scrollTop = chatMessages.scrollHeight; // Scroll para o final
            }

            // ⭐ Lógica das estrelas de avaliação (Melhorada)
            const ratingStarsContainer = document.getElementById('rating-stars');
            if (ratingStarsContainer) {
                // Efeito hover
                const allLabels = ratingStarsContainer.querySelectorAll('label');
                allLabels.forEach(label => {
                    label.addEventListener('mouseenter', function() {
                        // Pinta esta e todas as anteriores (no DOM, que está reverso)
                        let current = this;
                        while (current) {
                            current.style.color = 'var(--star-color)';
                            current = current.previousElementSibling;
                            // Pula os inputs
                             if (current) current = current.previousElementSibling;
                        }
                    });
                     label.addEventListener('mouseleave', function() {
                        // Limpa o hover de todas, o estado :checked do CSS assume
                        allLabels.forEach(lbl => lbl.style.color = '');
                    });
                });
            }
        });
    </script>
</body>
</html>