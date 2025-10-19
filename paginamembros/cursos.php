<?php
// membros/cursos.php

// --- LÓGICA PHP (BACKEND) FUNCIONAL ---
require_once '../config.php'; // Caminho para config.php na raiz
verificarAcesso('membro');     // Protege a página para usuários com nível 'membro'

$nome_usuario_logado = htmlspecialchars($_SESSION['usuario_nome']);
$usuario_id = $_SESSION['usuario_id']; // ID do usuário logado

$curso_info = null;
$aulas = [];
$aula_ativa_dados = null; // Informações da aula atualmente exibida

// Variáveis para as interações da aula ativa (serão preenchidas abaixo)
$usuario_curtiu_aula = false;
$usuario_favoritou_aula = false;
$usuario_avaliacao_aula = 0;
$media_avaliacoes_aula = 0;
$total_votos_aula = 0;
$comentarios_aula = [];

// --- Variáveis para feedback de operações (sucesso/erro) ---
$feedback_message = '';
$feedback_type = ''; // 'success', 'error', 'info', 'warning'

// Processar mensagens de feedback da URL (após redirecionamento POST)
if (isset($_GET['status']) && isset($_GET['message'])) {
    $feedback_message = htmlspecialchars($_GET['message']);
    $feedback_type = htmlspecialchars($_GET['status']);
}

try {
    // 1. Obter ID do curso da URL. Se não houver, para a execução.
    if (!isset($_GET['id'])) {
        // Se não tem ID do curso, redireciona para o dashboard com erro
        header('Location: index.php?status=error&message=' . urlencode('Curso não especificado.'));
        exit();
    }
    $id_curso_atual = (int)$_GET['id'];
    $id_aula_ativa_param = isset($_GET['aula_id']) ? (int)$_GET['aula_id'] : null;

    // 2. VERIFICAÇÃO DE SEGURANÇA: O membro tem acesso a este curso?
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuario_cursos WHERE usuario_id = ? AND curso_id = ?");
    $stmt->execute([$usuario_id, $id_curso_atual]);
    if ($stmt->fetchColumn() == 0) {
        // Redireciona se não tiver acesso
        header('Location: index.php?status=error&message=' . urlencode('Você não tem acesso a este curso.'));
        exit();
    }

    // 3. Buscar informações do curso e TODAS as suas aulas
    $stmt = $pdo->prepare("SELECT * FROM cursos WHERE id = ?");
    $stmt->execute([$id_curso_atual]);
    $curso_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$curso_info) {
        // Curso não encontrado
        header('Location: index.php?status=error&message=' . urlencode('Curso não encontrado.'));
        exit();
    }

    $stmt = $pdo->prepare("SELECT * FROM aulas WHERE curso_id = ? ORDER BY ordem ASC");
    $stmt->execute([$id_curso_atual]);
    $aulas = $stmt->fetchAll(PDO::FETCH_ASSOC); // Esta variável DEVE ser preenchida

    // 4. Determinar qual aula está ativa para o player
    if (!empty($aulas)) {
        if ($id_aula_ativa_param) {
            // Procura a aula da URL na lista de aulas do curso
            foreach ($aulas as $aula) {
                if ($aula['id'] === $id_aula_ativa_param) {
                    $aula_ativa_dados = $aula;
                    break;
                }
            }
        }
        // Se nenhuma aula foi passada na URL, ou a aula da URL não pertence a este curso,
        // simplesmente pega a primeira aula da lista como padrão.
        if (!$aula_ativa_dados) {
            $aula_ativa_dados = $aulas[0];
        }
    }

    // --- PROCESSAMENTO DE AÇÕES VIA POST (LIKES, FAVORITOS, AVALIAÇÕES, COMENTÁRIOS) ---
    // Somente se houver uma aula ativa para interagir
    if ($aula_ativa_dados && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        try {
            switch ($action) {
                case 'toggle_like':
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM aula_curtidas WHERE usuario_id = ? AND aula_id = ?");
                    $stmt->execute([$usuario_id, $aula_ativa_dados['id']]);
                    if ($stmt->fetchColumn() > 0) {
                        $pdo->prepare("DELETE FROM aula_curtidas WHERE usuario_id = ? AND aula_id = ?")->execute([$usuario_id, $aula_ativa_dados['id']]);
                        $message = 'Curtida removida.';
                    } else {
                        $pdo->prepare("INSERT INTO aula_curtidas (usuario_id, aula_id) VALUES (?, ?)")->execute([$usuario_id, $aula_ativa_dados['id']]);
                        $message = 'Aula curtida com sucesso!';
                    }
                    header('Location: cursos.php?id=' . $id_curso_atual . '&aula_id=' . $aula_ativa_dados['id'] . '&status=success&message=' . urlencode($message));
                    exit();
                    break;

                case 'toggle_favorite':
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM aula_favoritos WHERE usuario_id = ? AND aula_id = ?");
                    $stmt->execute([$usuario_id, $aula_ativa_dados['id']]);
                    if ($stmt->fetchColumn() > 0) {
                        $pdo->prepare("DELETE FROM aula_favoritos WHERE usuario_id = ? AND aula_id = ?")->execute([$usuario_id, $aula_ativa_dados['id']]);
                        $message = 'Aula removida dos favoritos.';
                    } else {
                        $pdo->prepare("INSERT INTO aula_favoritos (usuario_id, aula_id) VALUES (?, ?)")->execute([$usuario_id, $aula_ativa_dados['id']]);
                        $message = 'Aula adicionada aos favoritos!';
                    }
                    header('Location: cursos.php?id=' . $id_curso_atual . '&aula_id=' . $aula_ativa_dados['id'] . '&status=success&message=' . urlencode($message));
                    exit();
                    break;

                case 'submit_rating':
                    $new_rating = $_POST['rating'] ?? 0;
                    $new_rating = max(1, min(5, (int)$new_rating)); // Garante que a avaliação está entre 1 e 5

                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM aula_avaliacoes WHERE usuario_id = ? AND aula_id = ?");
                    $stmt->execute([$usuario_id, $aula_ativa_dados['id']]);

                    if ($stmt->fetchColumn() > 0) {
                        $pdo->prepare("UPDATE aula_avaliacoes SET avaliacao = ? WHERE usuario_id = ? AND aula_id = ?")->execute([$new_rating, $usuario_id, $aula_ativa_dados['id']]);
                        $message = 'Sua avaliação foi atualizada!';
                    } else {
                        $pdo->prepare("INSERT INTO aula_avaliacoes (usuario_id, aula_id, avaliacao) VALUES (?, ?, ?)")->execute([$usuario_id, $aula_ativa_dados['id'], $new_rating]);
                        $message = 'Sua avaliação foi registrada!';
                    }
                    header('Location: cursos.php?id=' . $id_curso_atual . '&aula_id=' . $aula_ativa_dados['id'] . '&status=success&message=' . urlencode($message));
                    exit();
                    break;

                case 'submit_comment':
                    $comentario_texto = trim($_POST['comentario'] ?? '');
                    if (empty($comentario_texto)) {
                        throw new Exception("O comentário não pode estar vazio.");
                    }
                    $pdo->prepare("INSERT INTO aula_comentarios (usuario_id, aula_id, comentario) VALUES (?, ?, ?)")
                        ->execute([$usuario_id, $aula_ativa_dados['id'], $comentario_texto]);
                    header('Location: cursos.php?id=' . $id_curso_atual . '&aula_id=' . $aula_ativa_dados['id'] . '&status=success&message=' . urlencode('Comentário enviado com sucesso!'));
                    exit();
                    break;
            }
        } catch (Exception $e) {
            // Em caso de erro durante POST, redireciona com mensagem de erro
            header('Location: cursos.php?id=' . $id_curso_atual . '&aula_id=' . $aula_ativa_dados['id'] . '&status=error&message=' . urlencode($e->getMessage()));
            exit();
        }
    } // Fim do processamento POST

    // --- Carregar DADOS DE INTERAÇÃO da aula ativa (APÓS qualquer POST) ---
    if ($aula_ativa_dados) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM aula_curtidas WHERE usuario_id = ? AND aula_id = ?");
        $stmt->execute([$usuario_id, $aula_ativa_dados['id']]);
        $usuario_curtiu_aula = $stmt->fetchColumn() > 0;

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM aula_favoritos WHERE usuario_id = ? AND aula_id = ?");
        $stmt->execute([$usuario_id, $aula_ativa_dados['id']]);
        $usuario_favoritou_aula = $stmt->fetchColumn() > 0;

        $stmt = $pdo->prepare("SELECT avaliacao FROM aula_avaliacoes WHERE usuario_id = ? AND aula_id = ?");
        $stmt->execute([$usuario_id, $aula_ativa_dados['id']]);
        $avaliacao_usuario_db = $stmt->fetchColumn();
        $usuario_avaliacao_aula = $avaliacao_usuario_db ? (int)$avaliacao_usuario_db : 0;

        // Média de avaliações e total de votos da aula
        $stmt = $pdo->prepare("SELECT AVG(avaliacao) as media, COUNT(*) as total FROM aula_avaliacoes WHERE aula_id = ?");
        $stmt->execute([$aula_ativa_dados['id']]);
        $stats = $stmt->fetch();
        $media_avaliacoes_aula = $stats['media'] ? round($stats['media'], 1) : 0;
        $total_votos_aula = $stats['total'] ? (int)$stats['total'] : 0;

        // Comentários da aula (incluindo nome do autor)
        $stmt = $pdo->prepare("SELECT ac.*, u.nome AS nome_usuario FROM aula_comentarios ac JOIN usuarios u ON ac.usuario_id = u.id WHERE ac.aula_id = ? ORDER BY ac.data_comentario DESC");
        $stmt->execute([$aula_ativa_dados['id']]);
        $comentarios_aula = $stmt->fetchAll();
    }

} catch (Exception $e) {
    // Em caso de erro geral (curso não especificado, não encontrado etc.)
    error_log($e->getMessage()); // É uma boa prática logar o erro
    $feedback_message = 'Ocorreu um erro: ' . $e->getMessage();
    $feedback_type = 'error';
    $curso_info = null; // Garante que o layout não tente exibir dados de um curso inexistente
    $aulas = [];
    $aula_ativa_dados = null;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php echo $curso_info ? htmlspecialchars($curso_info['titulo']) : 'Curso'; ?> - Área de Membros
    </title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/x-icon" href="/favicon1.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #00aaff;
            --background-color: #111827;
            --sidebar-color: #1f2937;
            --glass-background: rgba(31, 41, 55, 0.5);
            --text-color: #f9fafb;
            --text-muted: #9ca3af;
            --border-color: rgba(255, 255, 255, 0.1);
            --success-color: #22c55e;
            --error-color: #ef4444;
            --info-color: #3b82f6;
            --warning-color: #f59e0b;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--background-color); color: var(--text-color); display: flex; min-height: 100vh; }
        #particles-js { position: fixed; width: 100%; height: 100%; top: 0; left: 0; z-index: -1; }

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
            transition: transform 0.3s ease;
            /* AJUSTE: z-index maior para que a sidebar passe POR CIMA do botão do menu */
            z-index: 1003;
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

        .menu-toggle {
            display: none;
            position: fixed;
            top: 1.5rem;
            left: 1.5rem;
            cursor: pointer;
            padding: 10px;
            background-color: var(--sidebar-color);
            border-radius: 8px;
            border: 1px solid var(--border-color);
            /* AJUSTE: z-index menor para que o botão fique ABAIXO da sidebar */
            z-index: 1002;
        }
        .menu-toggle svg { width: 24px; height: 24px; color: var(--text-color); }

        .main-content {
            margin-left: 260px;
            flex-grow: 1;
            padding: 2rem 3rem;
            width: calc(100% - 260px);
            transition: margin-left 0.3s ease;
        }

        .course-layout { display: flex; gap: 2rem; max-width: 1400px; margin: 0 auto; align-items: flex-start; }
        .video-main-content { flex: 3; min-width: 0; width: 400px; }
        .video-player-wrapper { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; background-color: #000; border-radius: 12px; border: 1px solid var(--border-color); }
        .video-player-wrapper iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
        .lesson-details { margin-top: 1.5rem; padding: 1.5rem; background: var(--glass-background); border-radius: 12px; }
        .lesson-details h1 { font-size: 1.8rem; font-weight: 600; margin-bottom: 0.5rem; }
        .lesson-details p { font-size: 1rem; color: var(--text-muted); line-height: 1.7; }
        .playlist-sidebar { flex: 1; max-width: 400px; min-width: 300px; background-color: var(--sidebar-color); border-radius: 12px; padding: 1rem; max-height: 80vh; overflow-y: auto; }
        .playlist-sidebar h2 { font-size: 1.2rem; padding: 0 0.5rem 1rem 0.5rem; border-bottom: 1px solid var(--border-color); margin-bottom: 1rem; text-align: center; }
        .playlist-item { display: flex; gap: 1rem; padding: 1rem; border-radius: 8px; text-decoration: none; color: var(--text-color); transition: background-color 0.3s ease; }
        .playlist-item:hover { background-color: var(--glass-background); }
        .playlist-item.active-lesson { background-color: var(--primary-color); color: #fff; }
        .playlist-item.active-lesson .lesson-desc, .playlist-item.active-lesson .lesson-title { color: #fff; }
        .playlist-item .lesson-icon svg { width: 24px; height: 24px; color: var(--text-muted); }
        .playlist-item.active-lesson .lesson-icon svg { color: #fff; }
        .lesson-info .lesson-title { font-weight: 600; font-size: 0.95rem; margin-bottom: 0.25rem; }
        .lesson-info .lesson-desc { font-size: 0.85rem; color: var(--text-muted); line-height: 1.5; }
        .course-page-header { display: flex; align-items: center; gap: 1.5rem; margin-bottom: 2rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); }
        .course-page-header h1 { font-size: 1.8rem; font-weight: 600; margin: 0; }
        .back-button { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1.2rem; background-color: var(--glass-background); color: var(--text-muted); text-decoration: none; border: 1px solid var(--border-color); border-radius: 8px; font-weight: 500; transition: all 0.3s ease; }
        .back-button:hover { background-color: rgba(255, 255, 255, 0.1); color: var(--text-color); border-color: rgba(255, 255, 255, 0.2); }
        .back-button svg { width: 20px; height: 20px; }
        .feedback-message { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 500; border: 1px solid transparent; }
        .feedback-message.success { background-color: rgba(34, 197, 94, 0.1); border-color: rgba(34, 197, 94, 0.4); color: var(--success-color); }
        .feedback-message.error { background-color: rgba(225, 29, 72, 0.1); border-color: rgba(225, 29, 72, 0.4); color: var(--error-color); }
        .interaction-section { background-color: var(--glass-background); border-radius: 12px; padding: 1.5rem; margin-top: 1.5rem; border: 1px solid var(--border-color); }
        .interaction-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
        .interaction-header h3 { font-size: 1.3rem; margin: 0; }
        .interaction-buttons { display: flex; gap: 1rem; flex-wrap: wrap; }
        .interaction-button { background: none; border: 1px solid var(--border-color); color: var(--text-muted); padding: 0.75rem 1.25rem; border-radius: 8px; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; transition: all 0.2s ease; }
        .interaction-button:hover { border-color: var(--primary-color); color: var(--text-color); }
        .interaction-button.active { background-color: var(--primary-color); color: #fff; border-color: var(--primary-color); }
        .interaction-button svg { width: 20px; height: 20px; }
        .aula-rating { margin-top: 1.5rem; }
        .aula-rating h3 { font-size: 1.2rem; margin-bottom: 0.75rem; }
        .stars-input { display: flex; gap: 0.2rem; font-size: 2rem; cursor: pointer; color: #ffec07; margin-bottom: 0.5rem; }
        .average-rating { font-size: 1rem; color: var(--text-muted); }
        .comments-section { margin-top: 2rem; background-color: var(--glass-background); border-radius: 12px; padding: 1.5rem; border: 1px solid var(--border-color); }
        .comments-section h3 { font-size: 1.5rem; margin-bottom: 1.5rem; }
        .comment-form { margin-bottom: 2rem; display: flex; flex-direction: column; gap: 1rem; }
        .comment-form textarea { width: 100%; min-height: 100px; padding: 1rem; background-color: var(--background-color); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-color); font-size: 1rem; resize: vertical; }
        .comment-form button.btn-primary { align-self: flex-end; padding: 0.75rem 2rem; font-size: 1rem; background-color: var(--primary-color); color: #fff; border: none; border-radius: 8px; font-weight: 500; cursor: pointer; transition: background-color 0.3s ease; }
        .comment-form button.btn-primary:hover { background-color: #0088cc; }
        .comment-list { list-style: none; display: flex; flex-direction: column; gap: 1.5rem; }
        .comment-item { background-color: rgba(31, 41, 55, 0.7); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem; }
        .comment-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }
        .comment-author { font-weight: 600; color: var(--text-color); }
        .comment-time { font-size: 0.8rem; color: var(--text-muted); }
        .comment-body { color: var(--text-muted); line-height: 1.6; }

        @media (max-width: 1024px) {
            .sidebar {
                width: 280px;
                transform: translateX(-280px);
                box-shadow: 5px 0 15px rgba(0, 0, 0, 0.5);
                height: 100%;
                overflow-y: auto;
            }
            .user-profile {
                margin-top: 1.5rem;
            }
            .menu-toggle {
                display: flex;
                margin-left: -5px;
            }
            .main-content {
                margin-left: 0;
                width: 100%;
                /* AJUSTE: O padding aqui cria as margens laterais no celular e alinha todo o conteúdo */
                padding: 6rem 1.5rem 1.5rem 1.5rem;
            }
            body.sidebar-open .sidebar {
                transform: translateX(0);
            }
            body.sidebar-open::after {
                content: '';
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0, 0, 0, 0.7);
                z-index: 1001;
            }
            .course-layout {
                flex-direction: column;
                gap: 1.5rem;
                width: 399px;
            }
            .playlist-sidebar {
                order: -1;
                max-width: 100%;
                min-width: 100%;
                max-height: 45vh;
            }
            .course-page-header {
                flex-direction: column;
                align-items: flex-start;
                text-align: left;
                gap: 1rem;
            }
            .course-page-header h1 {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                 /* AJUSTE: Padding um pouco menor para telas de celular pequenas */
                padding: 5.5rem 1rem 1rem 1rem;
                margin-left: 3px;
            }
             .course-page-header {
                margin-bottom: 1.5rem;
            }
            .lesson-details {
                margin-top: 1.5rem;
                padding: 1.5rem;
                background: var(--glass-background);
                border-radius:12px;
                width: 398px;
            }

            .lesson-details h1 {
                font-size: 1.4rem;
            }

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

    <?php include '_sidebar.php'; // Inclui a sidebar unificada ?>

    <main class="main-content">
        <div class="course-page-header">
            <a href="index.php" class="back-button" title="Voltar ao Dashboard">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
                <span>Voltar</span>
            </a>
            <h1><?php echo htmlspecialchars($curso_info['titulo'] ?? 'Carregando Curso...'); ?></h1>
        </div>

        <?php if ($feedback_message): ?>
            <div class="feedback-message <?php echo $feedback_type; ?>">
                <?php echo htmlspecialchars($feedback_message); ?>
            </div>
        <?php endif; ?>

        <div class="course-layout">
            <div class="video-main-content">
                <div class="video-player-wrapper">
                    <?php if ($aula_ativa_dados && !empty($aula_ativa_dados['url_video'])): ?>
                        <iframe src="<?php echo htmlspecialchars($aula_ativa_dados['url_video']); ?>"
                                frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen>
                        </iframe>
                    <?php else: ?>
                        <div style="position: absolute; top: 0; left: 0; display:flex; justify-content:center; align-items:center; width:100%; height:100%; color:var(--text-muted); text-align:center; padding:1rem;">
                            <h2><?php echo empty($aulas) ? 'Este curso ainda não possui aulas.' : 'Selecione uma aula na lista para começar.'; ?></h2>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="lesson-details">
                    <h1><?php echo htmlspecialchars($aula_ativa_dados['titulo'] ?? 'Nenhuma aula selecionada'); ?></h1>
                    <p><?php echo nl2br(htmlspecialchars($aula_ativa_dados['descricao'] ?? 'Detalhes da aula aparecerão aqui.')); ?></p>
                </div>

                <?php if ($aula_ativa_dados): ?>
                <div class="interaction-section">
                    <div class="interaction-header">
                        <h3>Interaja com a aula</h3>
                        <div class="interaction-buttons">
                            <form method="POST" style="display:inline-block;">
                                <input type="hidden" name="action" value="toggle_like">
                                <button type="submit" class="interaction-button <?php echo $usuario_curtiu_aula ? 'active' : ''; ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="<?php echo $usuario_curtiu_aula ? 'currentColor' : 'none'; ?>">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.835 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" />
                                    </svg>
                                    <span>Curtir</span>
                                </button>
                            </form>
                            <form method="POST" style="display:inline-block;">
                                <input type="hidden" name="action" value="toggle_favorite">
                                <button type="submit" class="interaction-button <?php echo $usuario_favoritou_aula ? 'active' : ''; ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="<?php echo $usuario_favoritou_aula ? 'currentColor' : 'none'; ?>">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.385a.562.562 0 00-.182-.557L3.929 10.42a.562.562 0 01.32-1.004l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" />
                                    </svg>
                                    <span>Favoritar</span>
                                </button>
                            </form>
                        </div>
                    </div>
                    <div class="aula-rating">
                        <h3>Avalie esta aula:</h3>
                        <form id="rating-form" method="POST" class="stars-input">
                            <input type="hidden" name="action" value="submit_rating">
                            <input type="hidden" name="rating" id="stars-input-value" value="<?php echo $usuario_avaliacao_aula; ?>">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <span class="star-vote <?php echo $i <= $usuario_avaliacao_aula ? 'selected' : ''; ?>" data-value="<?php echo $i; ?>">
                                    <?php echo $i <= $usuario_avaliacao_aula ? '★' : '☆'; ?>
                                </span>
                            <?php endfor; ?>
                        </form>
                        <div class="average-rating">
                             Média: <span>
                            <?php
                            $fullStars = floor($media_avaliacoes_aula);
                            $emptyStars = 5 - $fullStars;
                            echo str_repeat('★', $fullStars) . str_repeat('☆', $emptyStars);
                            ?>
                             </span>
                            <span>(<?php echo number_format($media_avaliacoes_aula, 1); ?> / 5) - <?php echo $total_votos_aula; ?> votos</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($aula_ativa_dados): ?>
                <div class="comments-section">
                    <h3>Comentários (<?php echo count($comentarios_aula); ?>)</h3>
                    <form id="comment-form" method="POST" class="comment-form">
                        <input type="hidden" name="action" value="submit_comment">
                        <textarea name="comentario" placeholder="Escreva seu comentário..." required></textarea>
                        <button type="submit" class="btn-primary">Enviar Comentário</button>
                    </form>
                    <ul class="comment-list">
                        <?php if (empty($comentarios_aula)): ?>
                            <li style="color: var(--text-muted); text-align: center; padding: 1rem;">Seja o primeiro a comentar!</li>
                        <?php else: ?>
                            <?php foreach ($comentarios_aula as $comentario): ?>
                            <li class="comment-item">
                                <div class="comment-header">
                                    <span class="comment-author"><?php echo htmlspecialchars($comentario['nome_usuario']); ?></span>
                                    <span class="comment-time"><?php echo date("d/m/Y H:i", strtotime($comentario['data_comentario'])); ?></span>
                                </div>
                                <div class="comment-body">
                                    <?php echo nl2br(htmlspecialchars($comentario['comentario'])); ?>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>

            <aside class="playlist-sidebar">
                <h2>Aulas do Curso</h2>
                <?php if (empty($aulas)): ?>
                    <p style="padding: 1rem; color: var(--text-muted);">Nenhuma aula encontrada para este curso.</p>
                <?php else: ?>
                    <?php foreach ($aulas as $aula_item): ?>
                        <a href="cursos.php?id=<?php echo $id_curso_atual; ?>&aula_id=<?php echo $aula_item['id']; ?>"
                           class="playlist-item <?php echo ($aula_ativa_dados && $aula_item['id'] === $aula_ativa_dados['id']) ? 'active-lesson' : ''; ?>">
                            <div class="lesson-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15.91 11.672a.375.375 0 010 .656l-5.603 3.113a.375.375 0 01-.557-.328V8.887c0-.286.307-.466.557-.327l5.603 3.112z" /></svg>
                            </div>
                            <div class="lesson-info">
                                <div class="lesson-title"><?php echo htmlspecialchars($aula_item['titulo']); ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </aside>
        </div>
    </main>

<script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script>
        // Inicializa o fundo de partículas
        particlesJS('particles-js', {
            "particles": { "number": { "value": 80, "density": { "enable": true, "value_area": 800 } }, "color": { "value": "#00aaff" }, "shape": { "type": "circle" }, "opacity": { "value": 0.5, "random": false }, "size": { "value": 3, "random": true }, "line_linked": { "enable": true, "distance": 150, "color": "#ffffff", "opacity": 0.1, "width": 1 }, "move": { "enable": true, "speed": 2, "direction": "none", "random": false, "straight": false, "out_mode": "out", "bounce": false } },
            "interactivity": { "detect_on": "canvas", "events": { "onhover": { "enable": true, "mode": "repulse" }, "onclick": { "enable": true, "mode": "push" } } },
            "retina_detect": true
        });

        document.addEventListener('DOMContentLoaded', () => {
            // --- LÓGICA DO MENU HAMBÚRGUER ---
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

            // --- LÓGICA DO DROPDOWN DO PERFIL ---
            const userProfileMenu = document.getElementById('user-profile-menu'); // ID do _sidebar.php
            const dropdown = document.getElementById('profile-dropdown'); // ID do _sidebar.php

            if (userProfileMenu && dropdown) {
                userProfileMenu.addEventListener('click', (event) => {
                    event.stopPropagation();
                    dropdown.classList.toggle('show');
                });

                window.addEventListener('click', (event) => {
                    if (dropdown.classList.contains('show') && !userProfileMenu.contains(event.target)) {
                       dropdown.classList.remove('show');
                    }
                });
            }

            // --- Lógica de Avaliação por Estrelas ---
            const ratingForm = document.getElementById('rating-form');
            if (ratingForm) {
                const starsInput = document.getElementById('stars-input-value');
                const stars = ratingForm.querySelectorAll('.star-vote');
                let currentRating = parseInt(starsInput.value);

                function updateStarAppearance(ratingValue) {
                    stars.forEach(star => {
                        const value = parseInt(star.dataset.value);
                        if (value <= ratingValue) {
                            star.classList.add('selected');
                            star.innerHTML = '★'; // Estrela cheia
                        } else {
                            star.classList.remove('selected');
                            star.innerHTML = '☆'; // Estrela vazia
                        }
                    });
                }
                updateStarAppearance(currentRating);
                stars.forEach(star => {
                    star.addEventListener('mouseover', function() {
                        updateStarAppearance(parseInt(this.dataset.value));
                    });
                });
                ratingForm.addEventListener('mouseout', function() {
                    updateStarAppearance(currentRating);
                });
                stars.forEach(star => {
                    star.addEventListener('click', function() {
                        const newRating = parseInt(this.dataset.value);
                        starsInput.value = newRating;
                        currentRating = newRating;
                        ratingForm.submit();
                    });
                });
            }

            // --- Lógica para feedback (opcional: faz a mensagem desaparecer) ---
            const feedbackMessageElement = document.querySelector('.feedback-message');
            if (feedbackMessageElement) {
                setTimeout(() => {
                    feedbackMessageElement.style.transition = 'opacity 1s ease-out';
                    feedbackMessageElement.style.opacity = '0';
                    setTimeout(() => feedbackMessageElement.remove(), 1000);
                }, 5000);
            }
        });
    </script>
</body>
</html>