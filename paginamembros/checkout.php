<?php
// checkout.php - Versão Final com Layout Refinado e Animações Corrigida

// Carrega config.php, que agora carrega load_settings.php e as constantes.
require_once '../config.php';
verificarAcesso('membro');

$usuario_id = (int)$_SESSION['usuario_id'];
$nome_usuario = htmlspecialchars($_SESSION['usuario_nome']);
$pagina_atual = basename($_SERVER['PHP_SELF']);

// ⭐ CORREÇÃO 1: INICIALIZAÇÃO DE VARIÁVEIS NA PARTE SUPERIOR
$produto = null;
$tipo_produto = null;
$pixData = null;
$errorMessage = null;
$oferta_especial = null;
$id_produto_comprado = null;
$tipo_produto_comprado = null;

// --- LÓGICA DA PIXUP (AGORA CARREGADA DO DB) ---
// Tenta carregar as constantes do DB. Se falhar, usa null.
$PIXUP_CLIENT_ID = defined('PIXUP_CLIENT_ID') ? PIXUP_CLIENT_ID : null;
$PIXUP_CLIENT_SECRET = defined('PIXUP_CLIENT_SECRET') ? PIXUP_CLIENT_SECRET : null;

function getPixUpToken(string $clientId, string $clientSecret): string {
    if (isset($_SESSION['pixup_token']) && time() < $_SESSION['pixup_token_expires']) { return $_SESSION['pixup_token']; }
    $url = 'https://api.pixupbr.com/v2/oauth/token';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret)]]);
    $response = curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($http_code !== 200) throw new Exception("Falha na autenticação com o gateway.");
    $responseData = json_decode($response, true);
    $_SESSION['pixup_token'] = $responseData['access_token'];
    $_SESSION['pixup_token_expires'] = time() + ($responseData['expires_in'] - 60);
    return $responseData['access_token'];
}


// === LÓGICA DE GERAÇÃO DE PIX (POST) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // ⭐ Corrigido o erro de Configuração do Gateway
        if (!$PIXUP_CLIENT_ID || !$PIXUP_CLIENT_SECRET) {
            throw new Exception("Configurações do Gateway PixUp estão ausentes no sistema. Contate o administrador.");
        }

        $productId = (int)($_POST['product_id'] ?? 0);
        $productType = (string)($_POST['product_type'] ?? '');
        $cpf = preg_replace('/[^0-9]/', '', (string)($_POST['cpf'] ?? ''));
        if (strlen($cpf) !== 11) { throw new Exception('CPF inválido. Por favor, insira um CPF com 11 dígitos.'); }
        if ($productId <= 0 || !in_array($productType, ['curso', 'plano'])) { throw new Exception('Produto inválido.'); }

        $pdo->beginTransaction();

        $produto = null; // Re-inicializa $produto para o bloco POST
        if ($productType === 'curso') {
            $stmt = $pdo->prepare("SELECT id, titulo AS nome, valor FROM cursos WHERE id = ?");
            $stmt->execute([$productId]);
            $produto = $stmt->fetch(PDO::FETCH_ASSOC);
            $tipo_produto = 'curso';
        } else {
            $stmt = $pdo->prepare("SELECT id, nome, valor FROM planos WHERE id = ?");
            $stmt->execute([$productId]);
            $produto = $stmt->fetch(PDO::FETCH_ASSOC);
            $tipo_produto = 'plano';
        }

        $stmtUser = $pdo->prepare("SELECT nome, email FROM usuarios WHERE id = ?");
        $stmtUser->execute([$usuario_id]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$produto || !$user) throw new Exception('Usuário ou produto não encontrado.');

        // Inserção no Pedido
        $sql = "INSERT INTO pedidos (usuario_id, curso_id, plano_id, gateway_id, valor, status) VALUES (?, ?, ?, (SELECT id FROM gateways_pagamento WHERE nome='PIX'), ?, 'PENDENTE') RETURNING id";
        $stmtPedido = $pdo->prepare($sql);
        $stmtPedido->execute([$usuario_id, $productType === 'curso' ? $produto['id'] : null, $productType === 'plano' ? $produto['id'] : null, $produto['valor']]);
        $pedidoId = $stmtPedido->fetchColumn();

        if (!$pedidoId) throw new Exception('Falha ao registrar o pedido local.');

        // Geração do Token PixUp (usando as constantes carregadas do DB)
        $pixupToken = getPixUpToken($PIXUP_CLIENT_ID, $PIXUP_CLIENT_SECRET);

        $payload = json_encode(['amount' => (float)$produto['valor'], 'external_id' => (string)$pedidoId, 'postbackUrl' => "https://sitedemembros-1.onrender.com/paginamembros/api/webhook_pixup_cursos.php", 'payerQuestion' => "Compra de " . $produto['nome'], 'payer' => ['name' => $user['nome'], 'document' => $cpf, 'email' => $user['email']]]);

        $ch = curl_init('https://api.pixupbr.com/v2/pix/qrcode');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$pixupToken}", "Content-Type: application/json"]]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) { throw new Exception("Gateway de pagamento indisponível. Resposta: " . $response); }

        $responseData = json_decode($response, true);
        if (!isset($responseData['qrcode'])) { throw new Exception("Resposta inesperada do gateway."); }

        $stmtUpdate = $pdo->prepare("UPDATE pedidos SET pix_code = ? WHERE id = ?");
        $stmtUpdate->execute([$responseData['qrcode'], $pedidoId]);

        $pdo->commit();

        $pixData = ['pedidoId' => $pedidoId, 'pix_copy_paste_code' => $responseData['qrcode']];
        $id_produto_comprado = $produto['id'];
        $tipo_produto_comprado = $productType;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errorMessage = $e->getMessage();
        // Se a geração PIX falhar, $produto ainda precisa ser carregado para a seção de exibição

        // ⭐ NOVO: RECUPERA O PRODUTO APÓS FALHA NO POST para exibir o resumo
        $id = (int)($_POST['product_id'] ?? 0);
        $type = (string)($_POST['product_type'] ?? '');
        if ($id > 0) {
            if ($type === 'curso') {
                $stmt = $pdo->prepare("SELECT id, titulo AS nome, valor FROM cursos WHERE id = ?");
                $stmt->execute([$id]);
                $produto = $stmt->fetch(PDO::FETCH_ASSOC);
            } elseif ($type === 'plano') {
                $stmt = $pdo->prepare("SELECT id, nome, valor FROM planos WHERE id = ?");
                $stmt->execute([$id]);
                $produto = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
    }
}

// === LÓGICA DE EXIBIÇÃO (GET E FALLBACK) ===
// Esta seção garante que $produto seja carregado se não foi definido pelo POST
if (!$produto) {
    if (isset($_GET['curso_id'])) {
        $stmt = $pdo->prepare("SELECT id, titulo AS nome, valor FROM cursos WHERE id = ?");
        $stmt->execute([(int)$_GET['curso_id']]);
        $produto = $stmt->fetch(PDO::FETCH_ASSOC);
        $tipo_produto = 'curso';

        // Carrega a oferta especial se for compra de curso individual
        $stmt_oferta = $pdo->query("SELECT id, nome, valor FROM planos WHERE tipo_acesso = 'TODOS_CURSOS' LIMIT 1");
        $oferta_especial = $stmt_oferta->fetch(PDO::FETCH_ASSOC);
    } elseif (isset($_GET['plano_id'])) {
        $stmt = $pdo->prepare("SELECT id, nome, valor FROM planos WHERE id = ?");
        $stmt->execute([(int)$_GET['plano_id']]);
        $produto = $stmt->fetch(PDO::FETCH_ASSOC);
        $tipo_produto = 'plano';
    }
}


// VERIFICAÇÃO FINAL: Se o produto não foi carregado por nenhum caminho (POST falho ou GET inválido), redireciona
if (!$produto) {
    header("Location: comprar_cursos.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finalizar Compra - <?php echo $nome_usuario; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs/qrcode.min.js"></script>
    <style>
        /* --- GERAL & LAYOUT PADRÃO --- */
        :root { --primary-color: #00aaff; --background-color: #111827; --sidebar-color: #1f2937; --glass-background: rgba(31, 41, 55, 0.5); --text-color: #f9fafb; --text-muted: #9ca3af; --border-color: rgba(255, 255, 255, 0.1); --success-color: #10b981; --error-color: #f87171; --highlight-color: #facc15;}
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--background-color); color: var(--text-color); display: flex; min-height: 100vh; }
        #particles-js { position: fixed; width: 100%; height: 100%; top: 0; left: 0; z-index: -1; }
        .sidebar { width: 260px; background-color: var(--sidebar-color); height: 100vh; position: fixed; left: 0; top: 0; padding: 2rem 1.5rem; display: flex; flex-direction: column; border-right: 1px solid var(--border-color); z-index: 1000; transition: transform 0.3s ease; }
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
        .main-content { margin-left: 260px; flex-grow: 1; padding: 2rem 3rem; min-height: 100vh; overflow-y: auto; transition: margin-left 0.3s ease; width: calc(100% - 260px); }
        .menu-toggle { display: none; position: fixed; top: 1.5rem; left: 1.5rem; z-index: 1001; cursor: pointer; padding: 10px; background-color: var(--sidebar-color); border-radius: 8px; border: 1px solid var(--border-color); }
        .menu-toggle svg { width: 24px; height: 24px; color: var(--text-color); }

        /* --- LAYOUT DO CHECKOUT EM GRID --- */
        .checkout-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2.5rem; max-width: 1100px; margin: 5vh auto 0 auto; }
        .order-summary, .payment-details { background: var(--glass-background); backdrop-filter: blur(10px); border: 1px solid var(--border-color); border-radius: 12px; padding: 2.5rem; }

        /* --- COLUNA ESQUERDA: RESUMO DO PEDIDO & UPSELL --- */
        .order-summary h2 { display: flex; align-items: center; gap: 0.75rem; font-size: 1.5rem; margin-bottom: 2rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;}
        .product-item { margin-bottom: 1.5rem; }
        .product-item .item-name { font-size: 1.25rem; font-weight: 600; }
        .total-section { margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: baseline; }
        .total-section .total-label { font-size: 1.1rem; font-weight: 500; }
        .total-section .total-value { font-size: 2.25rem; font-weight: 700; color: var(--primary-color); }

        /* ⭐ AJUSTE REFINADO NA ANIMAÇÃO DO UPSELL */
        .upsell-offer {
            margin-top: 2rem;
            padding: 2px; /* Espaço para a borda animada brilhar */
            border-radius: 13px; /* Um pouco maior que o content */
            text-align: center;
            position: relative;
            overflow: hidden;
            background: transparent;
        }
        .upsell-offer::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 230%;
            height: 280%;
            background: conic-gradient(transparent, var(--primary-color), transparent 30%);
            animation: rotate 4s linear infinite;
        }
        .upsell-content {
            position: relative;
            z-index: 1; /* Garante que fique sobre o ::before */
            padding: 1.5rem;
            background: var(--background-color); /* FUNDO OPACO igual ao do body */
            border-radius: 12px; /* Raio interno */
        }
        .upsell-content h3 { display: flex; justify-content: center; align-items: center; gap: 0.5rem; font-size: 1.2rem; color: var(--highlight-color); margin-bottom: 0.5rem; }
        .upsell-content p { color: var(--text-muted); margin-bottom: 1.5rem; font-size: 0.9rem; max-width: 400px; margin-left:auto; margin-right:auto; }
        .upsell-content a { display: inline-block; background-color: var(--highlight-color); color: #111827; padding: 0.8rem 2rem; border-radius: 8px; text-decoration: none; font-weight: 700; transition: transform 0.2s; }
        .upsell-content a:hover { transform: scale(1.05); }
        @keyframes rotate { 100% { transform: rotate(360deg); } }

        /* --- COLUNA DIREITA: DETALHES DO PAGAMENTO --- */
        .payment-details h2 { display: flex; align-items: center; gap: 0.75rem; font-size: 1.5rem; margin-bottom: 2rem; }
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 0.5rem; }
        .form-group input { width: 100%; padding: 0.75rem 1rem; background-color: rgba(0,0,0,0.3); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-color); font-size: 1rem; }
        .form-group input:focus { border-color: var(--primary-color); outline: none; }
        button[type="submit"] { display: flex; justify-content: center; align-items: center; gap: 0.5rem; width: 100%; padding: 1rem; font-size: 1.1rem; font-weight: 600; background-color: var(--primary-color); color: white; border: none; border-radius: 8px; cursor: pointer; transition: background-color 0.3s; }
        .spinner { width: 20px; height: 20px; border: 2px solid rgba(255,255,255,0.3); border-top-color: #fff; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        #pix-display { text-align: center; }
        #qr-code-container { width: 250px; height: 250px; margin: 0 auto 1rem auto; background-color: white; padding: 15px; border-radius: 8px; }
        #pix-code-input { width: 100%; padding: 0.75rem; background-color: #111827; border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-color); margin: 1rem 0; text-align: center; }
        #copy-btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.7rem 1.5rem; background-color: #374151; color: white; border: none; border-radius: 8px; cursor: pointer; transition: background-color 0.2s; }
        #copy-btn:hover { background-color: #4b5563; }
        .pix-timer { margin-top: 1.5rem; color: var(--text-muted); font-size: 0.9rem; }
        .pix-timer span { font-weight: 600; color: var(--text-color); }
        .alert { padding: 1rem; border-radius: 8px; font-weight: 600; }
        .alert-error { background-color: rgba(248, 113, 113, 0.2); color: var(--error-color); }

        /* --- POPUP DE SUCESSO E RESPONSIVIDADE --- */
        #success-popup-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); backdrop-filter: blur(5px); display: none; justify-content: center; align-items: center; z-index: 2000; animation: fadeIn 0.5s ease; }
        .success-popup { background-color: var(--sidebar-color); padding: 3rem; border-radius: 16px; text-align: center; animation: scaleIn 0.5s ease; }
        .success-popup h2 { font-size: 1.8rem; margin: 1.5rem 0 0.5rem 0; color: var(--success-color); }
        .success-popup p { color: var(--text-muted); }
        .checkmark-circle { width: 100px; height: 100px; border-radius: 50%; border: 3px solid var(--success-color); display: flex; align-items: center; justify-content: center; margin: 0 auto; }
        .checkmark { width: 50px; height: 100px; border-bottom: 10px solid var(--success-color); border-right: 10px solid var(--success-color); transform: rotate(45deg) translate(-10px, -5px); animation: drawCheck 0.5s ease-out 0.5s forwards; opacity: 0; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes scaleIn { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        @keyframes drawCheck { from { width: 0; height: 0; opacity: 0; } to { width: 50px; height: 100px; opacity: 1; } }

        /* --- RESPONSIVIDADE --- */
        @media (max-width: 1024px) {
            .checkout-grid { grid-template-columns: 1fr; max-width: 600px; gap: 2rem; margin-top: 0; }
            .sidebar { width: 280px; transform: translateX(-280px); box-shadow: 5px 0 15px rgba(0, 0, 0, 0.5); z-index: 1002; height: 100%; overflow-y: auto; }
            .user-profile { margin-top: 1.5rem; position: relative; }
            .menu-toggle { display: flex; }
            .main-content { margin-left: 0; width: 100%; padding: 1.5rem; padding-top: 5rem; }
            body.sidebar-open .sidebar { transform: translateX(0); }
            body.sidebar-open::after { content: ''; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.7); z-index: 1001; }
        }
        @media (max-width: 576px) {
            .main-content { padding: 1rem; padding-top: 4.5rem; }
            .order-summary, .payment-details { padding: 1.5rem; }
            .total-section .total-value { font-size: 1.75rem; }
        }
    </style>
</head>
<body>
    <div id="particles-js"></div>

    <div id="success-popup-overlay">
        <div class="success-popup">
            <div class="checkmark-circle"><div class="checkmark"></div></div>
            <h2>Pagamento Aprovado!</h2>
            <p>Obrigado por sua compra. Redirecionando...</p>
        </div>
    </div>

    <div class="menu-toggle" id="menu-toggle">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
    </div>

    <?php include '_sidebar.php'; ?>

    <main class="main-content">
        <?php if ($errorMessage && !$pixData): ?>
            <div class="alert alert-error" style="max-width: 1100px; margin: 0 auto 2rem auto;"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <div class="checkout-grid">
            <section class="order-summary">
                <h2>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="24" height="24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                    </svg>
                    Resumo do Pedido
                </h2>
                <div class="product-item">
                    <p class="item-name"><?php echo htmlspecialchars($produto['nome']); ?></p>
                </div>
                <div class="total-section">
                    <span class="total-label">Total</span>
                    <span class="total-value">R$ <?php echo number_format((float)$produto['valor'], 2, ',', '.'); ?></span>
                </div>

                <?php if ($oferta_especial && !$pixData): ?>
                    <div class="upsell-offer">
                        <div class="upsell-content">
                            <h3>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" />
                                </svg>
                                Sua Melhor Escolha
                            </h3>
                            <p>Tenha acesso a <strong>TODOS</strong> os cursos por um valor promocional e economize!</p>
                            <a href="checkout.php?plano_id=<?php echo $oferta_especial['id']; ?>">Quero Acesso Total por R$ <?php echo number_format((float)$oferta_especial['valor'], 2, ',', '.'); ?></a>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="payment-details">
                <?php if (!$pixData): ?>
                    <form method="POST" action="" id="payment-form">
                        <h2>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="24" height="24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            Pague com PIX
                        </h2>
                        <div class="form-group">
                            <label for="cpf">CPF (para validação do pagamento)</label>
                            <input type="tel" id="cpf" name="cpf" required placeholder="000.000.000-00" maxlength="14" autocomplete="off">
                        </div>
                        <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($produto['id']); ?>">
                        <input type="hidden" name="product_type" value="<?php echo htmlspecialchars($tipo_produto); ?>">
                        <button type="submit" id="submit-btn"><span class="btn-text">Gerar PIX e Finalizar Compra</span><div class="spinner" style="display: none;"></div></button>
                    </form>
                <?php else: ?>
                    <div id="pix-display">
                        <h2>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="24" height="24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            Escaneie para Pagar
                        </h2>
                        <div id="qr-code-container"></div>
                        <input type="text" id="pix-code-input" value="<?php echo htmlspecialchars($pixData['pix_copy_paste_code']); ?>" readonly>
                        <button id="copy-btn">
                            <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" width="20"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124M10.5 18.75v-5.25c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v5.25c0 .621-.504 1.125-1.125-1.125h-5.25a1.125 1.125 0 01-1.125-1.125z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 5.25v-1.5a1.125 1.125 0 00-1.125-1.125H6.75a1.125 1.125 0 00-1.125 1.125v9.75c0 .621.504 1.125 1.125 1.125h1.5"></path></svg>
                            <span>Copiar Código</span>
                        </button>
                        <p class="pix-timer">Este código expira em <span id="pix-countdown">10:00</span></p>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <script>
        // --- SCRIPT PADRÃO DO SITE (PARTICLES, SIDEBAR, DROPDOWN) ---
        particlesJS('particles-js', {"particles":{"number":{"value":80,"density":{"enable":true,"value_area":800}},"color":{"value":"#00aaff"},"shape":{"type":"circle"},"opacity":{"value":0.5,"random":false},"size":{"value":3,"random":true},"line_linked":{"enable":true,"distance":150,"color":"#ffffff","opacity":0.1,"width":1},"move":{"enable":true,"speed":2,"direction":"none","random":false,"straight":false,"out_mode":"out","bounce":false}},"interactivity":{"detect_on":"canvas","events":{"onhover":{"enable":true,"mode":"repulse"},"onclick":{"enable":true,"mode":"push"}}},"retina_detect":true});
        document.addEventListener('DOMContentLoaded', () => {
            const menuToggle = document.getElementById('menu-toggle');
            const sidebar = document.querySelector('.sidebar');
            const body = document.body;
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', (event) => { event.stopPropagation(); body.classList.toggle('sidebar-open'); });
                body.addEventListener('click', (event) => { if (body.classList.contains('sidebar-open') && !sidebar.contains(event.target) && !menuToggle.contains(event.target)) { body.classList.remove('sidebar-open'); } });
            }
            const userProfileMenu = document.getElementById('user-profile-menu');
            const dropdown = document.getElementById('profile-dropdown');
            if (userProfileMenu && dropdown) {
                userProfileMenu.addEventListener('click', (event) => { event.stopPropagation(); dropdown.classList.toggle('show'); });
                window.addEventListener('click', (event) => { if (dropdown.classList.contains('show') && !userProfileMenu.contains(event.target) && !dropdown.contains(event.target)) { dropdown.classList.remove('show'); } });
            }
        });

        // --- SCRIPTS ESPECÍFICOS DA PÁGINA DE CHECKOUT ---
        const paymentForm = document.getElementById('payment-form');
        if (paymentForm) {
            paymentForm.addEventListener('submit', function(e) {
                const btn = document.getElementById('submit-btn');
                btn.disabled = true;
                btn.querySelector('.btn-text').style.display = 'none';
                btn.querySelector('.spinner').style.display = 'block';
            });
        }

        const cpfInput = document.getElementById('cpf');
        if (cpfInput) {
            cpfInput.addEventListener('input', (e) => {
                let value = e.target.value.replace(/\D/g, '');
                value = value.replace(/(\d{3})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
                e.target.value = value;
            });
        }

        <?php if ($pixData): ?>
            const produtoComprado = { tipo: '<?php echo $tipo_produto_comprado ?? ''; ?>', id: <?php echo $id_produto_comprado ?? 0; ?> };
            const pedidoId = <?php echo $pixData['pedidoId']; ?>;

            new QRCode(document.getElementById("qr-code-container"), { text: '<?php echo $pixData['pix_copy_paste_code']; ?>', width: 220, height: 220, correctLevel: QRCode.CorrectLevel.H });

            const copyBtn = document.getElementById('copy-btn');
            const originalCopyBtnHTML = copyBtn.innerHTML;
            copyBtn.addEventListener('click', (event) => {
                const input = document.getElementById('pix-code-input');
                input.select();
                document.execCommand('copy');
                copyBtn.innerHTML = '<svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" width="20"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"></path></svg> <span>Copiado!</span>';
                setTimeout(() => { copyBtn.innerHTML = originalCopyBtnHTML; }, 2500);
            });

            // Timer do PIX
            let timeLeft = 600; // 10 minutos
            const countdownEl = document.getElementById('pix-countdown');
            const timerInterval = setInterval(() => {
                if (timeLeft <= 0) {
                    clearInterval(timerInterval);
                    countdownEl.textContent = "Expirado";
                    return;
                }
                timeLeft--;
                const minutes = Math.floor(timeLeft / 60).toString().padStart(2, '0');
                const seconds = (timeLeft % 60).toString().padStart(2, '0');
                countdownEl.textContent = `${minutes}:${seconds}`;
            }, 1000);

            // Polling de Pagamento
            let pollingInterval = setInterval(async () => {
                try {
                    const res = await fetch(`api/check_payment_status.php?pedido_id=${pedidoId}`);
                    const data = await res.json();
                    if (data.status === 'APROVADO') {
                        clearInterval(pollingInterval);
                        clearInterval(timerInterval);
                        document.querySelector('.checkout-grid').style.display = 'none';
                        document.getElementById('success-popup-overlay').style.display = 'flex';
                        setTimeout(() => {
                            if (produtoComprado.tipo === 'plano') {
                                window.location.href = 'index.php?payment=success';
                            } else if (produtoComprado.tipo === 'curso' && produtoComprado.id > 0) {
                                window.location.href = `cursos.php?id=${produtoComprado.id}&payment=success`;
                            } else {
                                window.location.href = 'index.php?payment=success'; // Fallback
                            }
                        }, 4000);
                    }
                } catch (e) { console.error('Polling error:', e); }
            }, 5000);
        <?php endif; ?>
    </script>
</body>
</html>