<?php
// admin/settings.php
declare(strict_types=1);

require_once '../config.php';
// Certifique-se de que o load_settings.php foi executado no config.php para definir $pdo.

verificarAcesso('admin'); // Proteção para administradores

$nome_usuario = htmlspecialchars($_SESSION['usuario_nome']);
$pagina_atual = basename($_SERVER['PHP_SELF']); // Identifica a página atual para o menu

// Variáveis para mensagens de feedback
$successMessage = null;
$errorMessage = null;

// ===================================================================
// === LÓGICA DE ATUALIZAÇÃO, INSERÇÃO E EXCLUSÃO (POST) ===========
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        $pdo->beginTransaction();

        $id = (int)($_POST['id'] ?? 0);
        $chave = trim((string)($_POST['chave'] ?? ''));
        $valor = (string)($_POST['valor'] ?? '');
        $descricao = trim((string)($_POST['descricao'] ?? ''));

        // --- AÇÃO: ATUALIZAR OU INSERIR UMA CHAVE ---
        if ($action === 'save_key') {
            if (empty($chave) || $chave === '') {
                throw new Exception("O campo Chave (Key) é obrigatório.");
            }

            if ($id > 0) {
                // ATUALIZAR
                $stmt = $pdo->prepare("UPDATE configuracoes SET chave = ?, valor = ?, descricao = ? WHERE id = ?");
                $stmt->execute([$chave, $valor, $descricao, $id]);
                $successMessage = "Chave de configuração '{$chave}' atualizada com sucesso!";
            } else {
                // INSERIR (NOVA CHAVE)
                $stmt = $pdo->prepare("INSERT INTO configuracoes (chave, valor, descricao) VALUES (?, ?, ?)");
                $stmt->execute([$chave, $valor, $descricao]);
                $successMessage = "Nova chave de configuração '{$chave}' adicionada com sucesso!";
            }
        }

        // --- AÇÃO: EXCLUIR UMA CHAVE ---
        elseif ($action === 'delete_key') {
            if ($id <= 0) {
                 throw new Exception("ID da configuração inválido para exclusão.");
            }
            $stmt = $pdo->prepare("DELETE FROM configuracoes WHERE id = ?");
            $stmt->execute([$id]);
            $successMessage = "Chave de configuração excluída com sucesso.";
        }

        $pdo->commit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();

        // Trata a exceção de chave duplicada (PostgreSQL error code 23505)
        if (strpos($e->getMessage(), '23505') !== false) {
             $errorMessage = "Ocorreu um erro: A CHAVE ({$chave}) já existe. As chaves devem ser únicas.";
        } else {
             $errorMessage = "Ocorreu um erro: " . $e->getMessage();
        }
    }
}

// =====================================================================
// === LÓGICA DE EXIBIÇÃO (Sempre busca os dados mais recentes) =======
// =====================================================================

// Busca todas as configurações, ordenadas pela chave
$stmt_configs = $pdo->prepare("SELECT id, chave, valor, descricao FROM configuracoes ORDER BY chave ASC");
$stmt_configs->execute();
$todas_configuracoes = $stmt_configs->fetchAll(PDO::FETCH_ASSOC);

// Inicializa a variável para edição/criação
$config_edicao = [
    'id' => 0,
    'chave' => '',
    'valor' => '',
    'descricao' => '',
    'titulo' => 'Adicionar Nova Chave'
];

// Se houver um ID para edição na URL (GET)
if (isset($_GET['edit_id']) && (int)$_GET['edit_id'] > 0) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt_edit = $pdo->prepare("SELECT id, chave, valor, descricao FROM configuracoes WHERE id = ?");
    $stmt_edit->execute([$edit_id]);
    $data = $stmt_edit->fetch(PDO::FETCH_ASSOC);

    if ($data) {
        $config_edicao = $data;
        $config_edicao['titulo'] = 'Editar Chave: ' . htmlspecialchars($data['chave']);
    } else {
        $errorMessage = "Chave de configuração não encontrada para edição.";
    }
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações Globais - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* === CSS BASE (da index.php) === */
        :root {
            --primary-color: #e11d48; --background-color: #111827; --sidebar-color: #1f2937;
            --glass-background: rgba(31, 41, 55, 0.5); --text-color: #f9fafb; --text-muted: #9ca3af;
            --border-color: rgba(255, 255, 255, 0.1); --success-color: #22c55e; --error-color: #f87171;
            --info-color: #3b82f6; --warning-color: #f59e0b;
            --delete-color: #dc2626; /* Específico de settings.php */
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

        .main-content header { margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem; }
        .main-content header h1 { font-size: 2rem; font-weight: 600; }
        .main-content header p { color: var(--text-muted); }

        /* === ESTILOS DE COMPONENTES GLOBAIS (Unificados) === */
        .management-card { background: var(--glass-background); border: 1px solid var(--border-color); border-radius: 12px; padding: 2rem; margin-bottom: 2rem; }
        .management-card h2 { font-size: 1.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; }

        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 0.5rem; }
        .form-group input, .form-group textarea {
            width: 100%; padding: 0.75rem 1rem; background-color: var(--background-color);
            border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-color);
            font-size: 1rem; font-family: 'Poppins', sans-serif;
        }
        .form-group textarea { resize: vertical; min-height: 100px; }
        .form-group input:focus, .form-group textarea:focus { border-color: var(--primary-color); outline: none; }

        .btn-save { padding: 0.8rem 2rem; background-color: var(--primary-color); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 1rem; transition: background-color 0.3s; }
        .btn-save:hover { background-color: #c01a3f; }

        .table-wrapper { overflow-x: auto; }

        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 600; }
        .alert-success { background-color: rgba(34, 197, 94, 0.2); color: var(--success-color); }
        .alert-error { background-color: rgba(248, 113, 113, 0.2); color: var(--error-color); }

        /* === ESTILOS ESPECÍFICOS (settings.php) === */
        .btn-new-key {
            padding: 0.6rem 1rem;
            background-color: var(--success-color);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.3s;
        }
        .btn-new-key:hover { background-color: #1a9e4e; }

        .table-configs { width: 100%; border-collapse: collapse; min-width: 800px; }
        .table-configs th, .table-configs td { padding: 1rem; text-align: left; border-bottom: 1px solid var(--border-color); vertical-align: top; }
        .table-configs th { font-weight: 600; white-space: nowrap; }
        .table-configs td:nth-child(2) { font-family: monospace; font-size: 0.9rem; word-break: break-all; }

        .action-btns { display: flex; gap: 0.5rem; white-space: nowrap; }
        .action-btns .btn-edit { background-color: var(--info-color); color: white; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; font-size: 0.9rem; }
        .action-btns .btn-delete { background-color: var(--delete-color); color: white; padding: 0.5rem 1rem; border: none; border-radius: 6px; cursor: pointer; font-size: 0.9rem; }
        .action-btns .btn-edit:hover { background-color: #2563eb; }
        .action-btns .btn-delete:hover { background-color: #b91c1c; }


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
        @media (max-width: 768px) {
             .table-configs { min-width: 700px; }
        }
        @media (max-width: 576px) {
             .main-content { padding: 1rem; padding-top: 4.5rem; }
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
            <div>
                <h1>Configurações Globais</h1>
                <p>Gerencie chaves de API, URLs de postback e outras variáveis do sistema.</p>
            </div>
            <a href="settings.php" class="btn-new-key">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Nova Chave
            </a>
        </header>

        <?php if ($successMessage): ?><div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div><?php endif; ?>
        <?php if ($errorMessage): ?><div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div><?php endif; ?>

        <section class="management-card">
            <h2><?php echo htmlspecialchars($config_edicao['titulo']); ?></h2>
            <form method="POST" action="settings.php">
                <input type="hidden" name="action" value="save_key">
                <input type="hidden" name="id" value="<?php echo $config_edicao['id']; ?>">

                <div class="form-group">
                    <label for="chave">Chave/Key (Ex: MAILGUN_API_KEY)</label>
                    <input type="text" id="chave" name="chave" value="<?php echo htmlspecialchars($config_edicao['chave']); ?>" required <?php echo $config_edicao['id'] > 0 ? 'readonly' : ''; ?> placeholder="Nome da constante em MAIÚSCULAS">
                    <?php if ($config_edicao['id'] > 0): ?>
                        <small style="color: var(--text-muted);">A Chave não pode ser alterada após a criação para evitar erros no código.</small>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="valor">Valor</label>
                    <textarea id="valor" name="valor" required><?php echo htmlspecialchars($config_edicao['valor']); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="descricao">Descrição (Opcional)</label>
                    <input type="text" id="descricao" name="descricao" value="<?php echo htmlspecialchars($config_edicao['descricao']); ?>">
                </div>

                <button type="submit" class="btn-save">Salvar Chave</button>
                <?php if ($config_edicao['id'] > 0): ?>
                    <a href="settings.php" class="btn-save" style="background-color: #4b5563; text-decoration: none;">Cancelar Edição</a>
                <?php endif; ?>
            </form>
        </section>

        <section class="management-card">
            <h2>Chaves Ativas</h2>
            <div class="table-wrapper">
                <?php if (!empty($todas_configuracoes)): ?>
                    <table class="table-configs">
                        <thead>
                            <tr>
                                <th>CHAVE</th>
                                <th>VALOR</th>
                                <th>DESCRIÇÃO</th>
                                <th>AÇÕES</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todas_configuracoes as $config): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($config['chave']); ?></td>
                                    <td><?php echo strlen($config['valor']) > 50 ? htmlspecialchars(substr($config['valor'], 0, 50)) . '...' : htmlspecialchars($config['valor']); ?></td>
                                    <td><?php echo htmlspecialchars($config['descricao']); ?></td>
                                    <td class="action-btns">
                                        <a href="settings.php?edit_id=<?php echo $config['id']; ?>" class="btn-edit">Editar</a>
                                        <form method="POST" action="settings.php" onsubmit="return confirm('Tem certeza que deseja EXCLUIR esta chave? Esta ação pode quebrar o sistema.');" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_key">
                                            <input type="hidden" name="id" value="<?php echo $config['id']; ?>">
                                            <button type="submit" class="btn-delete">Excluir</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: var(--text-muted);">Nenhuma chave de configuração encontrada. Comece adicionando uma chave.</p>
                <?php endif; ?>
            </div>
        </section>
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

        // --- Lógica Específica (settings.php) ---
        // Redireciona o botão "Nova Chave" (no header) para resetar o formulário
        const newKeyBtn = document.querySelector('.btn-new-key');
        if (newKeyBtn) {
            newKeyBtn.addEventListener('click', function(e) {
                const idInput = document.querySelector('input[name="id"]');
                // Se o formulário de edição estiver ativo, desativa para ir para o modo "Nova Chave"
                if (idInput && idInput.value !== '0') {
                    e.preventDefault();
                    window.location.href = 'settings.php'; // Recarrega para o modo de criação
                }
            });
        }
    });
    </script>
</body>
</html>