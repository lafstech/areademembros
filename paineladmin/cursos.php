<?php
// --- PARTE 1: LÓGICA DO BACKEND ---
// NOTA: É fundamental que 'config.php' defina $pdo (conexão PDO) e as funções de autenticação (verificarAcesso, etc.)
require_once '../config.php';
// ATENÇÃO: Se 'verificarAcesso' não for uma função global, você deve incluí-la ou o código falhará.
// Exemplo de verificação que deve estar em config.php ou ser incluída:
// if (!function_exists('verificarAcesso')) {
//     function verificarAcesso($permissao) {
//         if (!isset($_SESSION['usuario_nivel']) || $_SESSION['usuario_nivel'] !== $permissao) {
//             header('Location: ../login.php');
//             exit();
//         }
//     }
// }
// Assumindo que a função acima está definida e funcionando:
verificarAcesso('admin');

$nome_usuario_admin = htmlspecialchars($_SESSION['usuario_nome'] ?? 'Admin');
$feedback_message = '';
$feedback_type = '';
$view = $_GET['view'] ?? 'grid'; // Controla a visualização: 'grid' ou 'lessons'
$curso_id_ativo = isset($_GET['curso_id']) ? (int)$_GET['curso_id'] : null;
$arquivos_por_aula = []; // Variável para armazenar os arquivos mapeados
$curso_ativo = null; // Inicializa a variável para evitar erro no PHP se for para a view lessons sem ID

// --- PROCESSAMENTO DE AÇÕES ---
try {
    // Ações via POST (Adicionar, Editar, etc.)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        switch ($action) {
            case 'add_edit_course':
                $curso_id = $_POST['curso_id'];
                $titulo = trim($_POST['titulo']);
                $descricao = trim($_POST['descricao']);
                $imagem_thumbnail = trim($_POST['imagem_thumbnail']);

                if (empty($titulo)) throw new Exception("O título do curso é obrigatório.");

                if (!empty($curso_id)) { // UPDATE
                    $sql = "UPDATE cursos SET titulo = ?, descricao = ?, imagem_thumbnail = ? WHERE id = ?";
                    $pdo->prepare($sql)->execute([$titulo, $descricao, $imagem_thumbnail, $curso_id]);
                    $feedback_message = 'Curso atualizado com sucesso!';
                } else { // INSERT
                    $sql = "INSERT INTO cursos (titulo, descricao, imagem_thumbnail) VALUES (?, ?, ?)";
                    $pdo->prepare($sql)->execute([$titulo, $descricao, $imagem_thumbnail]);
                    $feedback_message = 'Curso criado com sucesso!';
                }
                $feedback_type = 'success';
                break;

            case 'add_edit_lesson':
                $aula_id = $_POST['aula_id'];
                $curso_id = $_POST['curso_id_aula'];
                $titulo = trim($_POST['titulo']);
                $descricao = trim($_POST['descricao']);
                $url_video = trim($_POST['url_video']);

                if (empty($titulo) || empty($curso_id)) throw new Exception("Título e ID do curso são obrigatórios.");

                if (!empty($aula_id)) { // UPDATE AULA
                    $sql = "UPDATE aulas SET titulo = ?, descricao = ?, url_video = ? WHERE id = ? AND curso_id = ?";
                    $pdo->prepare($sql)->execute([$titulo, $descricao, $url_video, $aula_id, $curso_id]);
                } else { // INSERT AULA
                    $stmt = $pdo->prepare("SELECT MAX(ordem) as max_ordem FROM aulas WHERE curso_id = ?");
                    $stmt->execute([$curso_id]);
                    $max_ordem = $stmt->fetchColumn();
                    $nova_ordem = is_null($max_ordem) ? 0 : $max_ordem + 1;

                    $sql = "INSERT INTO aulas (curso_id, titulo, descricao, url_video, ordem) VALUES (?, ?, ?, ?, ?)";
                    $pdo->prepare($sql)->execute([$curso_id, $titulo, $descricao, $url_video, $nova_ordem]);
                }
                header('Location: cursos.php?view=lessons&curso_id=' . $curso_id . '&status=lesson_saved');
                exit();

            case 'add_file':
                $aula_id = $_POST['aula_id_file'];
                $curso_id_redirect = $_POST['curso_id_file'];
                $titulo = trim($_POST['file_titulo']);
                $descricao = trim($_POST['file_descricao']);
                $caminho_arquivo = trim($_POST['file_caminho']);
                $arquivo_id = $_POST['arquivo_id'] ?? null; // Novo campo para edição

                if (empty($aula_id) || empty($titulo) || empty($caminho_arquivo)) {
                    throw new Exception("Título, Aula ID e Caminho do Arquivo são obrigatórios.");
                }

                $pdo->beginTransaction();

                if ($arquivo_id) { // UPDATE ARQUIVO
                    $sql_file = "UPDATE arquivos SET titulo = ?, descricao = ?, caminho_arquivo = ? WHERE id = ?";
                    $pdo->prepare($sql_file)->execute([$titulo, $descricao, $caminho_arquivo, $arquivo_id]);
                    $feedback_message = 'Arquivo atualizado com sucesso!';
                    $status_redirect = 'file_saved';
                } else { // INSERT NOVO ARQUIVO
                    $sql_file = "INSERT INTO arquivos (titulo, descricao, caminho_arquivo) VALUES (?, ?, ?)";
                    $pdo->prepare($sql_file)->execute([$titulo, $descricao, $caminho_arquivo]);
                    $arquivo_id = $pdo->lastInsertId('arquivos_id_seq');

                    // Relaciona o arquivo à aula (apenas se for novo)
                    $sql_relate = "INSERT INTO aula_arquivos (aula_id, arquivo_id) VALUES (?, ?)";
                    $pdo->prepare($sql_relate)->execute([$aula_id, $arquivo_id]);
                    $feedback_message = 'Arquivo adicionado com sucesso à aula!';
                    $status_redirect = 'file_added';
                }

                $pdo->commit();
                header('Location: cursos.php?view=lessons&curso_id=' . $curso_id_redirect . '&status=' . $status_redirect);
                exit();

            case 'update_lesson_order':
                header('Content-Type: application/json'); // Responde em JSON
                $ordered_ids = $_POST['order'] ?? [];
                if (empty($ordered_ids)) {
                    echo json_encode(['status' => 'error', 'message' => 'Nenhuma ordem recebida.']);
                    exit();
                }
                $pdo->beginTransaction();
                $sql = "UPDATE aulas SET ordem = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                foreach ($ordered_ids as $index => $id) {
                    // Garante que o ID seja um inteiro para segurança
                    $stmt->execute([$index, (int)$id]);
                }
                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Ordem das aulas atualizada.']);
                exit();
        }
    }
    // Ação de Deletar (via GET)
    if (isset($_GET['action'])) {
        if ($_GET['action'] === 'delete_course' && isset($_GET['id'])) {
            $pdo->prepare("DELETE FROM cursos WHERE id = ?")->execute([$_GET['id']]);
            header('Location: cursos.php?status=course_deleted');
            exit();
        }
        if ($_GET['action'] === 'delete_lesson' && isset($_GET['id'])) {
            $aula_id = $_GET['id'];
            $stmt = $pdo->prepare("SELECT curso_id FROM aulas WHERE id = ?");
            $stmt->execute([$aula_id]);
            $curso_id_redirect = $stmt->fetchColumn();
            $pdo->prepare("DELETE FROM aulas WHERE id = ?")->execute([$aula_id]);
            header('Location: cursos.php?view=lessons&curso_id=' . $curso_id_redirect . '&status=lesson_deleted');
            exit();
        }

        // AÇÃO PARA DELETAR ARQUIVO
        if ($_GET['action'] === 'delete_file' && isset($_GET['id']) && isset($_GET['curso_id'])) {
            $arquivo_id = (int)$_GET['id'];
            $curso_id_redirect = (int)$_GET['curso_id'];

            $pdo->beginTransaction();
            // Remove o relacionamento (caso o arquivo esteja em várias aulas)
            $pdo->prepare("DELETE FROM aula_arquivos WHERE arquivo_id = ?")->execute([$arquivo_id]);
            // Remove o arquivo da tabela principal
            $pdo->prepare("DELETE FROM arquivos WHERE id = ?")->execute([$arquivo_id]);
            $pdo->commit();

            header('Location: cursos.php?view=lessons&curso_id=' . $curso_id_redirect . '&status=file_deleted');
            exit();
        }
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $feedback_message = 'Erro: ' . $e->getMessage();
    $feedback_type = 'error';
}

if (isset($_GET['status'])) {
    $status_messages = [
        'course_deleted' => 'Curso deletado com sucesso!',
        'lesson_saved' => 'Aula salva com sucesso!',
        'lesson_deleted' => 'Aula deletada com sucesso!',
        'file_added' => 'Arquivo adicionado com sucesso à aula!',
        'file_saved' => 'Arquivo atualizado com sucesso!',
        'file_deleted' => 'Arquivo deletado com sucesso!'
    ];
    $feedback_message = $status_messages[$_GET['status']] ?? '';
    $feedback_type = 'success';
}

// --- BUSCA DE DADOS DO BANCO ---
if ($view === 'grid') {
    $sql = "SELECT c.*,
            (SELECT COUNT(*) FROM aulas WHERE curso_id = c.id) as total_aulas,
            (SELECT COUNT(*) FROM usuario_cursos WHERE curso_id = c.id) as total_alunos
            FROM cursos c ORDER BY c.titulo ASC";
    $cursos = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} elseif ($view === 'lessons' && $curso_id_ativo) {
    $stmt = $pdo->prepare("SELECT * FROM cursos WHERE id = ?");
    $stmt->execute([$curso_id_ativo]);
    $curso_ativo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$curso_ativo) {
        $view = 'grid'; // Redireciona se o curso não for encontrado
    } else {
        $stmt = $pdo->prepare("SELECT * FROM aulas WHERE curso_id = ? ORDER BY ordem ASC");
        $stmt->execute([$curso_id_ativo]);
        $aulas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Lógica para buscar e mapear arquivos por aula
        if (!empty($aulas)) {
            $aula_ids = array_column($aulas, 'id');
            // Usando placeholders para segurança (Embora implode em array_column de int seja geralmente seguro)
            $placeholders = implode(',', array_fill(0, count($aula_ids), '?'));
            $sql_files = "SELECT
                              a.id, a.titulo, a.descricao, a.caminho_arquivo, aa.aula_id
                            FROM arquivos a
                            JOIN aula_arquivos aa ON a.id = aa.arquivo_id
                            WHERE aa.aula_id IN ($placeholders)";

            $stmt_files = $pdo->prepare($sql_files);
            $stmt_files->execute($aula_ids);
            $arquivos = $stmt_files->fetchAll(PDO::FETCH_ASSOC);

            // Mapeia os arquivos por aula_id
            foreach ($arquivos as $file) {
                $arquivos_por_aula[$file['aula_id']][] = $file;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Cursos - Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <style>
        /* --- Variáveis e Reset --- */
        :root {
            --primary-color: #e11d48; /* Vermelho/Rosa Admin */
            --background-color: #111827; /* Dark Background (Base) */
            --sidebar-color: #1f2937; /* Sidebar Darker */
            --glass-background: rgba(31, 41, 55, 0.5); /* Fundo com pouca transparência */
            --text-color: #f9fafb; /* Texto Claro */
            --text-muted: #9ca3af; /* Texto Secundário/Suave */
            --border-color: rgba(255, 255, 255, 0.1); /* Bordas e linhas sutis */
            --success-color: #22c55e;
            --info-color: #3b82f6;
            --delete-color: #dc2626;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--background-color); color: var(--text-color); display: flex; min-height: 100vh; }

        /* --- Layout Geral e Sidebar --- */
        .sidebar { width: 260px; background-color: var(--sidebar-color); height: 100vh; position: fixed; padding: 2rem 1.5rem; display: flex; flex-direction: column; border-right: 1px solid var(--border-color); z-index: 10; }
        .sidebar .logo { font-size: 1.5rem; font-weight: 700; margin-bottom: 3rem; text-align: center; }
        .sidebar .logo span { color: var(--primary-color); }
        .sidebar nav { flex-grow: 1; }
        .sidebar nav a { display: flex; align-items: center; gap: 1rem; padding: 1rem; color: var(--text-muted); text-decoration: none; border-radius: 8px; margin-bottom: 0.5rem; transition: all 0.3s ease; }
        .sidebar nav a:hover, .sidebar nav a.active { background-color: var(--glass-background); color: var(--text-color); }
        .sidebar nav a svg { width: 24px; height: 24px; stroke: currentColor; /* Garante que o SVG herde a cor do link */ }
        .user-profile { position: relative; margin-top: auto; background-color: var(--glass-background); padding: 0.75rem; border-radius: 8px; display: flex; align-items: center; gap: 1rem; cursor: pointer; border: 1px solid transparent; transition: all 0.3s ease; }
        .user-profile:hover { border-color: var(--primary-color); }
        .avatar { width: 40px; height: 40px; border-radius: 50%; background-color: var(--primary-color); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 1rem; color: var(--text-color); }
        .user-info .user-name { font-weight: 600; font-size: 0.9rem; line-height: 1.2; }
        .user-info .user-level { font-size: 0.75rem; color: var(--text-muted); }
        .profile-dropdown { position: absolute; bottom: calc(100% + 10px); left: 0; width: 100%; background-color: var(--sidebar-color); border-radius: 8px; border: 1px solid var(--border-color); padding: 0.5rem; z-index: 20; visibility: hidden; opacity: 0; transform: translateY(10px); transition: all 0.3s ease; }
        .profile-dropdown.show { visibility: visible; opacity: 1; transform: translateY(0); }
        .profile-dropdown a { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; color: var(--text-muted); text-decoration: none; font-size: 0.9rem; border-radius: 6px; }
        .profile-dropdown a:hover { background-color: var(--glass-background); color: var(--text-color); }

        /* --- Conteúdo Principal e Header --- */
        .main-content { margin-left: 260px; flex-grow: 1; padding: 2rem 3rem; overflow-y: auto; }
        .main-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .main-header h1 { font-size: 2rem; font-weight: 600; }
        .btn-primary { background-color: var(--primary-color); color: #fff; padding: 0.75rem 1.5rem; border: none; border-radius: 8px; font-weight: 500; font-size: 0.9rem; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .btn-secondary { background-color: rgba(255,255,255,0.1); border: 1px solid var(--border-color); color: var(--text-color); padding: 0.75rem 0.75rem; border-radius: 8px; font-weight: 500; text-decoration: none; transition: all 0.3s; display: inline-flex; align-items: center; justify-content: center; }
        .btn-primary:hover, .btn-secondary:hover { filter: brightness(1.1); }
        .feedback-message { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 500; border: 1px solid transparent; }
        .feedback-message.success { background-color: rgba(34, 197, 94, 0.1); border-color: rgba(34, 197, 94, 0.4); color: var(--success-color); }
        .feedback-message.error { background-color: rgba(225, 29, 72, 0.1); border-color: rgba(225, 29, 72, 0.4); color: var(--primary-color); }

        /* --- Formulários e Inputs --- */
        .form-group input, .form-group textarea {
            background-color: var(--background-color);
        }

        /* --- Grid de Cursos --- */
        .grid-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; }
        .course-card { background: var(--glass-background); border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; transition: transform 0.2s; }
        .course-card:hover { transform: translateY(-5px); border-color: var(--primary-color); }
        .course-card-img { width: 100%; height: 180px; object-fit: cover; background-color: #333; }
        .course-card-content { padding: 1.5rem; flex-grow: 1; display: flex; flex-direction: column; }
        .course-card h3 { font-size: 1.2rem; margin-bottom: 0.5rem; flex-grow: 1; }
        .course-card-stats { display: flex; gap: 1.5rem; color: var(--text-muted); font-size: 0.9rem; margin: 1rem 0 1.5rem 0; }
        .course-card-actions { display: flex; gap: 1rem; margin-top: auto; }
        .course-card-actions .btn-secondary { padding: 0.75rem; line-height: 1; }
        .course-card-actions .btn-secondary svg { width:20px; height:20px; stroke: currentColor; }
        .course-card-actions a.btn-delete:hover svg { color: var(--delete-color); }


        /* --- Lista de Aulas --- */
        .lesson-manager-header { display: flex; align-items: center; gap: 1rem; margin-bottom: 2rem; }
        .lesson-list { list-style: none; }
        .lesson-item {
            display: flex; align-items: center; gap: 1rem;
            background: var(--glass-background); padding: 1rem;
            border-radius: 8px; margin-bottom: 1rem;
            border: 1px solid var(--border-color);
            transition: background 0.2s;
        }
        .lesson-item:hover { background: rgba(31, 41, 55, 0.8); }
        .drag-handle { cursor: grab; color: var(--text-muted); font-size: 1.2rem; line-height: 1; padding: 0 0.5rem; }
        .lesson-item-info { flex-grow: 1; }
        .lesson-item-title { font-weight: 600; }
        .lesson-item-desc { font-size: 0.85rem; color: var(--text-muted); }
        .actions-cell { display: flex; gap: 0.5rem; align-items: center; flex-shrink: 0; }
        .actions-cell button, .actions-cell a {
            background: none; border: none; cursor: pointer; padding: 0.5rem;
            line-height: 1; display: inline-flex; align-items: center;
            justify-content: center; border-radius: 4px;
        }
        .actions-cell button svg, .actions-cell a svg {
            width: 20px; height: 20px; stroke: currentColor;
            color: var(--text-muted); transition: color 0.2s;
        }
        .actions-cell button:hover, .actions-cell a:hover { background-color: rgba(255, 255, 255, 0.05); }
        .actions-cell button:hover svg, .actions-cell a:not(.btn-delete):hover svg { color: var(--primary-color); }
        .actions-cell a.btn-delete:hover svg { color: var(--delete-color); }
        .sortable-ghost { opacity: 0.4; background: var(--sidebar-color); border: 1px dashed var(--primary-color); }
        .lesson-item-files { font-size: 0.8em; color: var(--text-muted); margin-top: 4px; cursor: pointer; display: flex; align-items: center; }
        .lesson-item-files span { color: var(--success-color); font-weight: 600; margin-left: 5px; }
        .lesson-item-files svg { stroke: var(--success-color); }

        /* --- Modais --- */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(5px); z-index: 100; display: none; justify-content: center; align-items: center; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .modal-overlay.show { display: flex; }
        .modal-content { background-color: var(--sidebar-color); border: 1px solid var(--border-color); border-radius: 12px; width: 90%; max-width: 600px; padding: 2rem; position: relative; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .modal-header h2 { font-size: 1.5rem; }
        .close-modal { background: none; border: none; color: var(--text-muted); font-size: 1.5rem; cursor: pointer; transition: color 0.2s; }
        .close-modal:hover { color: var(--primary-color); }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-size: 0.9rem; font-weight: 500; }
        .form-group input, .form-group textarea {
            width: 100%; padding: 0.75rem; background-color: #2c3a4f; /* Um pouco mais escuro para destaque */
            border: 1px solid var(--border-color); border-radius: 8px;
            color: var(--text-color); font-size: 1rem; font-family: 'Poppins', sans-serif;
            transition: border-color 0.2s;
        }
        .form-group input:focus, .form-group textarea:focus { border-color: var(--primary-color); outline: none; }
        .form-group textarea { min-height: 100px; resize: vertical; }
        .modal-footer { margin-top: 2rem; text-align: right; }

        /* Modal de Listagem de Arquivos */
        #file-list-modal .modal-content { max-width: 750px; }
        .file-entry { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--border-color); }
        .file-entry:last-child { border-bottom: none; }
        .file-entry-info { flex-grow: 1; }
        .file-entry-info h4 { font-size: 1rem; margin-bottom: 2px; }
        .file-entry-info p { font-size: 0.8rem; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 400px; }
        .file-actions { display: flex; gap: 0.5rem; }
        .file-actions a, .file-actions button { padding: 0.5rem; }

        /* --- Responsividade --- */
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: static; padding: 1rem; flex-direction: row; justify-content: space-between; border-right: none; border-bottom: 1px solid var(--border-color); }
            .sidebar .logo { margin-bottom: 0; }
            .sidebar nav { display: none; } /* Oculta navegação para mobile para simplificar */
            .user-profile { margin-top: 0; }
            .main-content { margin-left: 0; padding: 1rem; }
            .main-header { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .main-header h1 { font-size: 1.5rem; }
            .btn-primary { width: 100%; justify-content: center; }
            .grid-container { grid-template-columns: 1fr; }
            .course-card-actions { flex-direction: column; gap: 0.5rem; }
            .course-card-actions a, .course-card-actions button { width: 100%; text-align: center; justify-content: center; }
            .modal-content { padding: 1rem; }
            .lesson-item { flex-wrap: wrap; align-items: flex-start; }
            .drag-handle { order: 0; } /* Mantém o handle no início */
            .lesson-item-info { order: 1; width: calc(100% - 70px); margin-bottom: 0.5rem; }
            .actions-cell { order: 2; margin-left: auto; }
            .file-entry { flex-wrap: wrap; }
            .file-entry-info { width: 100%; margin-bottom: 0.5rem; }
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="logo">Admin<span>Panel</span></div>
        <nav>
            <a href="index.php">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>
                <span>Dashboard</span>
            </a>
            <a href="usuarios.php">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-4.663M12 12.375a3.75 3.75 0 100-7.5 3.75 3.75 0 000 7.5z" /></svg>
                <span>Usuários</span>
            </a>
            <a href="cursos.php" class="active">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
                <span>Cursos</span>
            </a>
        </nav>
        <div class="user-profile" id="user-profile-menu">
            <div class="avatar"><?php echo strtoupper(substr($nome_usuario_admin, 0, 2)); ?></div>
            <div class="user-info"> <div class="user-name"><?php echo $nome_usuario_admin; ?></div> <div class="user-level">Administrador</div> </div>
            <div class="profile-dropdown" id="profile-dropdown"> <a href="../paginamembros/logout.php"><span>Sair</span></a> </div>
        </div>
    </aside>

    <main class="main-content">
        <?php if ($view === 'grid'): ?>
            <header class="main-header">
                <h1>Gerenciar Cursos</h1>
                <button class="btn-primary" id="add-course-btn">+ Adicionar Novo Curso</button>
            </header>
            <?php if ($feedback_message): ?><div class="feedback-message <?php echo $feedback_type; ?>"><?php echo htmlspecialchars($feedback_message); ?></div><?php endif; ?>
            <div class="grid-container">
                <?php foreach ($cursos as $curso): ?>
                <div class="course-card">
                    <img src="<?php echo htmlspecialchars($curso['imagem_thumbnail'] ?: 'https://via.placeholder.com/400x225.png/1f2937/9ca3af?text=Sem+Imagem'); ?>" alt="Thumbnail" class="course-card-img">
                    <div class="course-card-content">
                        <h3><?php echo htmlspecialchars($curso['titulo']); ?></h3>
                        <div class="course-card-stats">
                            <span>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px; height:16px; margin-right: 4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
                                <?php echo $curso['total_aulas']; ?> Aulas
                            </span>
                            <span>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px; height:16px; margin-right: 4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-4.663M12 12.375a3.75 3.75 0 100-7.5 3.75 3.75 0 000 7.5z" /></svg>
                                <?php echo $curso['total_alunos']; ?> Alunos
                            </span>
                        </div>
                        <div class="course-card-actions">
                            <a href="cursos.php?view=lessons&curso_id=<?php echo $curso['id']; ?>" class="btn-primary" style="flex:1;">Gerenciar Aulas</a>
                            <button class="btn-secondary edit-course-btn" data-curso='<?php echo htmlspecialchars(json_encode($curso), ENT_QUOTES, 'UTF-8'); ?>' title="Editar Curso">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                </svg>
                            </button>
                            <a href="cursos.php?action=delete_course&id=<?php echo $curso['id']; ?>" onclick="return confirm('ATENÇÃO: Deletar um curso também apaga TODAS as suas aulas e acessos de alunos. Deseja continuar?');" class="btn-secondary btn-delete" title="Deletar Curso">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($cursos)): ?><p>Nenhum curso encontrado. Comece adicionando um novo!</p><?php endif; ?>
            </div>

        <?php elseif ($view === 'lessons' && !empty($curso_ativo)): ?>
            <header class="lesson-manager-header">
                <a href="cursos.php" class="btn-secondary">&larr; Voltar para Cursos</a>
                <h1>Aulas de: <?php echo htmlspecialchars($curso_ativo['titulo']); ?></h1>
            </header>
            <?php if ($feedback_message): ?><div class="feedback-message <?php echo $feedback_type; ?>"><?php echo htmlspecialchars($feedback_message); ?></div><?php endif; ?>

            <button class="btn-primary" id="add-lesson-btn" style="margin-bottom: 1.5rem;">+ Adicionar Nova Aula</button>

            <ul id="lesson-list" class="lesson-list">
                <?php foreach ($aulas as $aula): ?>
                <li class="lesson-item" data-id="<?php echo $aula['id']; ?>">
                    <span class="drag-handle" title="Arrastar para reordenar">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:20px; height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9h16.5m-16.5 6h16.5" /></svg>
                    </span>
                    <div class="lesson-item-info">
                        <div class="lesson-item-title"><?php echo htmlspecialchars($aula['titulo']); ?></div>
                        <div class="lesson-item-desc"><?php echo htmlspecialchars($aula['descricao']); ?></div>

                        <?php if (!empty($arquivos_por_aula[$aula['id']])): ?>
                            <div class="lesson-item-files view-files-btn"
                                data-aula-id="<?php echo $aula['id']; ?>"
                                data-curso-id="<?php echo $curso_id_ativo; ?>"
                                data-files='<?php echo htmlspecialchars(json_encode($arquivos_por_aula[$aula['id']]), ENT_QUOTES, 'UTF-8'); ?>'
                                title="Gerenciar Arquivos Anexados">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px; height:16px; margin-right: 5px;"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m6.75 12V21a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6a2.25 2.25 0 012.25-2.25h2.25m4.5 4.5V12m4.5 4.5v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25" /></svg>
                                <span><?php echo count($arquivos_por_aula[$aula['id']]); ?> Arquivo(s)</span>
                            </div>
                        <?php endif; ?>

                    </div>
                    <div class="actions-cell">
                        <button class="add-file-btn" data-aula-id="<?php echo $aula['id']; ?>" title="Adicionar Arquivo">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </button>

                        <button class="edit-lesson-btn" data-aula='<?php echo htmlspecialchars(json_encode($aula), ENT_QUOTES, 'UTF-8'); ?>' title="Editar Aula">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                            </svg>
                        </button>
                        <a href="cursos.php?action=delete_lesson&id=<?php echo $aula['id']; ?>" onclick="return confirm('Tem certeza que deseja deletar esta aula?');" class="btn-delete" title="Deletar Aula">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                            </svg>
                        </a>
                    </div>
                </li>
                <?php endforeach; ?>
                <?php if (empty($aulas)): ?><p>Nenhuma aula encontrada para este curso. Comece adicionando uma nova!</p><?php endif; ?>
            </ul>
        <?php endif; ?>
    </main>

    <div class="modal-overlay" id="course-modal">
        <div class="modal-content">
            <div class="modal-header"> <h2 id="course-modal-title">Adicionar Novo Curso</h2> <button type="button" class="close-modal">&times;</button> </div>
            <form id="course-form" method="POST">
                <input type="hidden" name="action" value="add_edit_course">
                <input type="hidden" name="curso_id" id="curso_id">
                <div class="form-group"> <label for="curso-titulo">Título do Curso</label> <input type="text" id="curso-titulo" name="titulo" required> </div>
                <div class="form-group"> <label for="curso-descricao">Descrição</label> <textarea id="curso-descricao" name="descricao"></textarea> </div>
                <div class="form-group"> <label for="curso-thumb">URL da Thumbnail</label> <input type="url" id="curso-thumb" name="imagem_thumbnail" placeholder="https://exemplo.com/imagem.jpg"> </div>
                <div class="modal-footer"> <button type="submit" class="btn-primary" id="course-modal-submit">Salvar Curso</button> </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="lesson-modal">
        <div class="modal-content">
            <div class="modal-header"> <h2 id="lesson-modal-title">Adicionar Nova Aula</h2> <button type="button" class="close-modal">&times;</button> </div>
            <form id="lesson-form" method="POST">
                <input type="hidden" name="action" value="add_edit_lesson">
                <input type="hidden" name="aula_id" id="aula_id">
                <input type="hidden" name="curso_id_aula" id="curso_id_aula" value="<?php echo $curso_id_ativo ?? ''; ?>">
                <div class="form-group"> <label for="aula-titulo">Título da Aula</label> <input type="text" id="aula-titulo" name="titulo" required> </div>
                <div class="form-group"> <label for="aula-descricao">Descrição da Aula</label> <textarea id="aula-descricao" name="descricao"></textarea> </div>
                <div class="form-group"> <label for="aula-url">URL do Vídeo (Vimeo, Bunny, etc.)</label> <input type="url" id="aula-url" name="url_video" placeholder="https://player.vimeo.com/video/exemplo" required> </div>
                <div class="modal-footer"> <button type="submit" class="btn-primary" id="lesson-modal-submit">Salvar Aula</button> </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="file-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="file-modal-title">Adicionar Arquivo à Aula</h2>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <form id="file-form" method="POST">
                <input type="hidden" name="action" value="add_file">
                <input type="hidden" name="aula_id_file" id="aula_id_file">
                <input type="hidden" name="curso_id_file" id="curso_id_file" value="<?php echo $curso_id_ativo ?? ''; ?>">
                <input type="hidden" name="arquivo_id" id="arquivo_id">
                <div class="form-group">
                    <label for="file-titulo">Título do Arquivo</label>
                    <input type="text" id="file-titulo" name="file_titulo" required>
                </div>
                <div class="form-group">
                    <label for="file-descricao">Descrição (Opcional)</label>
                    <textarea id="file-descricao" name="file_descricao"></textarea>
                </div>
                <div class="form-group">
                    <label for="file-caminho">URL do Arquivo (Link do Drive, S3, Dropbox, etc.)</label>
                    <input type="url" id="file-caminho" name="file_caminho" required placeholder="https://drive.google.com/link_do_arquivo">
                </div>

                <div class="modal-footer">
                    <button type="submit" class="btn-primary" id="file-modal-submit">Salvar Arquivo</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="file-list-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="file-list-modal-title">Arquivos da Aula</h2>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <div id="file-list-content">
                </div>
            <div class="modal-footer">
                <button type="button" class="btn-primary" id="add-new-file-from-list">+ Adicionar Novo Arquivo</button>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const courseModal = document.getElementById('course-modal');
        const lessonModal = document.getElementById('lesson-modal');
        const fileModal = document.getElementById('file-modal');
        const fileListModal = document.getElementById('file-list-modal');
        const fileForm = document.getElementById('file-form');
        const fileListContent = document.getElementById('file-list-content');

        const userProfileMenu = document.getElementById('user-profile-menu');
        const profileDropdown = document.getElementById('profile-dropdown');

        const openModal = (modal) => modal.classList.add('show');
        const closeModal = (modal) => modal.classList.remove('show');

        // Close on overlay click
        document.querySelectorAll('.close-modal').forEach(btn => btn.addEventListener('click', (e) => closeModal(e.target.closest('.modal-overlay'))));
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    closeModal(overlay);
                }
            });
        });

        // Dropdown Menu Logic
        if (userProfileMenu) {
            userProfileMenu.addEventListener('click', (e) => { e.stopPropagation(); profileDropdown.classList.toggle('show'); });
            window.addEventListener('click', () => { if (profileDropdown.classList.contains('show')) profileDropdown.classList.remove('show'); });
        }

        // --- Course Management ---
        document.getElementById('add-course-btn')?.addEventListener('click', () => {
            const form = document.getElementById('course-form');
            form.reset();
            document.getElementById('curso_id').value = '';
            document.getElementById('course-modal-title').textContent = 'Adicionar Novo Curso';
            openModal(courseModal);
        });

        document.querySelectorAll('.edit-course-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const curso = JSON.parse(e.currentTarget.dataset.curso);
                document.getElementById('curso_id').value = curso.id;
                document.getElementById('curso-titulo').value = curso.titulo;
                document.getElementById('curso-descricao').value = curso.descricao;
                document.getElementById('curso-thumb').value = curso.imagem_thumbnail;
                document.getElementById('course-modal-title').textContent = 'Editar Curso';
                openModal(courseModal);
            });
        });

        // --- Lesson and File Logic (Apenas se estiver na view 'lessons') ---
        const activeCourseId = document.getElementById('curso_id_aula')?.value; // Pega o ID do input hidden

        if (activeCourseId) {
            // Function to configure and open the File Edit/Creation Modal
            const openFileEditModal = (aulaId, arquivo = null) => {
                fileForm.reset();

                // Garante que o ID da Aula e Curso estejam corretos no formulário de arquivo
                document.getElementById('aula_id_file').value = aulaId;
                document.getElementById('curso_id_file').value = activeCourseId;

                if (arquivo) {
                    // Modo Edição
                    document.getElementById('arquivo_id').value = arquivo.id;
                    document.getElementById('file-titulo').value = arquivo.titulo;
                    document.getElementById('file-descricao').value = arquivo.descricao || '';
                    document.getElementById('file-caminho').value = arquivo.caminho_arquivo;
                    document.getElementById('file-modal-title').textContent = 'Editar Arquivo';
                    document.getElementById('file-modal-submit').textContent = 'Salvar Alterações';
                } else {
                    // Modo Adicionar
                    document.getElementById('arquivo_id').value = '';
                    document.getElementById('file-modal-title').textContent = `Anexar Novo Arquivo à Aula #${aulaId}`;
                    document.getElementById('file-modal-submit').textContent = 'Salvar Arquivo';
                }
                closeModal(fileListModal); // Fecha a lista antes de abrir o de edição
                openModal(fileModal);
            };

            // Lesson Edit/Add Logic
            document.getElementById('add-lesson-btn')?.addEventListener('click', () => {
                const form = document.getElementById('lesson-form');
                form.reset();
                document.getElementById('aula_id').value = '';
                // 'curso_id_aula' já está setado pelo PHP com activeCourseId
                document.getElementById('lesson-modal-title').textContent = 'Adicionar Nova Aula';
                openModal(lessonModal);
            });

            document.querySelectorAll('.edit-lesson-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const aula = JSON.parse(e.currentTarget.dataset.aula);
                    document.getElementById('aula_id').value = aula.id;
                    document.getElementById('curso_id_aula').value = aula.curso_id; // Manter a consistência, embora já deva ser activeCourseId
                    document.getElementById('aula-titulo').value = aula.titulo;
                    document.getElementById('aula-descricao').value = aula.descricao;
                    document.getElementById('aula-url').value = aula.url_video;
                    document.getElementById('lesson-modal-title').textContent = 'Editar Aula';
                    openModal(lessonModal);
                });
            });

            // 1. ABRIR MODAL DE CRIAÇÃO (Botão + na Lista de Aulas)
            document.querySelectorAll('.add-file-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const aulaId = e.currentTarget.dataset.aulaId;
                    openFileEditModal(aulaId);
                });
            });

            // 2. CONSTRUIR E ABRIR MODAL DE LISTAGEM (Botão Ver Arquivos)
            document.querySelectorAll('.view-files-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const aulaId = e.currentTarget.dataset.aulaId;
                    const cursoId = e.currentTarget.dataset.cursoId;
                    // Note o uso de um regex simples para remover '&#39;' que foi usado na codificação PHP
                    const files = JSON.parse(e.currentTarget.dataset.files);

                    document.getElementById('file-list-modal-title').textContent = `Gerenciar Arquivos da Aula #${aulaId}`;

                    let htmlContent = '';
                    if (files.length === 0) {
                        htmlContent = '<p style="padding: 1rem; color: var(--text-muted);">Nenhum arquivo anexado ainda.</p>';
                    } else {
                        files.forEach(file => {
                            // Link de Visualização (Download) e Ações (Editar, Deletar)
                            htmlContent += `
                                <div class="file-entry">
                                    <div class="file-entry-info">
                                        <h4>${file.titulo}</h4>
                                        <p title="${file.caminho_arquivo}">${file.descricao || 'Sem descrição'}</p>
                                    </div>
                                    <div class="file-actions">
                                        <a href="${file.caminho_arquivo}" target="_blank" class="btn-secondary" title="Visualizar Link">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 8.25H7.5a2.25 2.25 0 0 0-2.25 2.25v9a2.25 2.25 0 0 0 2.25 2.25h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25H15M9 12l3 3m0 0 3-3m-3 3V2.25" />
                                            </svg>
                                        </a>
                                        <button class="btn-secondary edit-file-from-list" data-file='${JSON.stringify({ ...file, aula_id: aulaId }).replace(/'/g, '&#39;')}' title="Editar Arquivo">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                            </svg>
                                        </button>
                                        <a href="cursos.php?action=delete_file&id=${file.id}&curso_id=${cursoId}" onclick="return confirm('Tem certeza que deseja DELETAR o arquivo \'${file.titulo}\'?');" class="btn-secondary btn-delete" title="Deletar Arquivo">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                            </svg>
                                        </a>
                                    </div>
                                </div>
                            `;
                        });
                    }
                    fileListContent.innerHTML = htmlContent;
                    openModal(fileListModal);
                });
            });

            // 3. ABRIR MODAL DE EDIÇÃO PELA LISTA
            fileListContent.addEventListener('click', (e) => {
                const button = e.target.closest('.edit-file-from-list');
                if (button) {
                    // Deserializa a string JSON, trocando '&#39;' por "'"
                    const fileData = JSON.parse(button.dataset.file.replace(/&#39;/g, "'"));
                    openFileEditModal(fileData.aula_id, fileData);
                }
            });

            // 4. ABRIR MODAL DE CRIAÇÃO PELA LISTA (Botão 'Adicionar Novo Arquivo')
            document.getElementById('add-new-file-from-list')?.addEventListener('click', () => {
                const titleText = document.getElementById('file-list-modal-title').textContent;
                const aulaIdMatch = titleText.match(/Aula #(\d+)/);
                if (aulaIdMatch) {
                    openFileEditModal(aulaIdMatch[1]);
                }
            });


            // Lesson Sorting
            const lessonList = document.getElementById('lesson-list');
            if (lessonList) {
                new Sortable(lessonList, {
                    animation: 150, handle: '.drag-handle', ghostClass: 'sortable-ghost',
                    onEnd: function (evt) {
                        const orderedIds = Array.from(evt.to.children).map(item => item.dataset.id);
                        fetch('cursos.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: `action=update_lesson_order&order[]=${orderedIds.join('&order[]=')}`
                        }).then(res => res.json()).then(data => {
                            if (data.status !== 'success') alert('Erro ao salvar a nova ordem.');
                            // Apenas um console.log, não é necessário recarregar a página
                            console.log('Ordem atualizada:', data.message);
                        }).catch(err => console.error('Erro no Fetch:', err));
                    }
                });
            }
        }
        // Se estiver na view 'grid', a lógica acima é ignorada, resolvendo o erro 'Cannot set properties of null'.
    });
    </script>
</body>
</html>