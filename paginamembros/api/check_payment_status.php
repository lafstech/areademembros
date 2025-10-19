<?php
// paginamembros/api/check_payment_status.php
declare(strict_types=1);

// 1. Conectar ao DB e iniciar a sessão
require_once '../../config.php';

header('Content-Type: application/json; charset=utf-8');

// 2. Validar a requisição
// Garante que o usuário está logado e que um ID de pedido foi enviado
if (!isset($_SESSION['usuario_id']) || !isset($_GET['pedido_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'ERRO_AUTENTICACAO']);
    exit;
}

// 3. Obter dados e sanitizar
$pedidoId = (int)$_GET['pedido_id'];
$userId = (int)$_SESSION['usuario_id'];

try {
    // 4. Consultar o banco de dados de forma segura
    // A consulta verifica o ID do pedido E se o pedido pertence ao usuário logado
    $stmt = $pdo->prepare("SELECT status FROM pedidos WHERE id = ? AND usuario_id = ?");
    $stmt->execute([$pedidoId, $userId]);

    $status = $stmt->fetchColumn();

    // 5. Retornar o status em formato JSON
    if ($status) {
        echo json_encode(['status' => $status]);
    } else {
        // Se não encontrar o pedido, retorna um status específico
        echo json_encode(['status' => 'NAO_ENCONTRADO']);
    }

} catch (PDOException $e) {
    // Em caso de erro de banco, logar e retornar um erro genérico
    error_log("Erro ao checar status do pedido: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'ERRO_SERVIDOR']);
}
?>