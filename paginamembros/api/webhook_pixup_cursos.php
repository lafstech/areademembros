<?php
// paginamembros/api/webhook_pixup_cursos.php
declare(strict_types=1);

// 1. Conectar ao banco de dados
// NENHUMA VERIFICAÇÃO DE SESSÃO AQUI, pois é a PixUp que está acessando.
require_once '../../config.php';

// Função para log, essencial para depurar webhooks
function log_webhook($message) {
    error_log("Webhook Cursos: " . $message . "\n", 3, __DIR__ . '/webhook.log');
}

// 2. Capturar e validar o payload vindo da PixUp
$input = file_get_contents('php://input');
log_webhook("Payload recebido: " . $input);

$payload = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE || !isset($payload['requestBody']['status'], $payload['requestBody']['external_id'])) {
    log_webhook("ERRO: Payload inválido ou incompleto.");
    http_response_code(400); // Bad Request
    exit;
}

$requestBody = $payload['requestBody'];
$status = $requestBody['status'];
$pedidoId = (int)$requestBody['external_id']; // Este é o nosso ID do pedido

// 3. Processar apenas se o status for 'PAID' (Pago)
if ($status === 'PAID') {
    log_webhook("Pagamento CONFIRMADO para o pedido ID: {$pedidoId}. Iniciando processamento.");

    try {
        // 4. Iniciar uma transação para garantir a integridade dos dados
        $pdo->beginTransaction();

        // 5. Encontrar o pedido PENDENTE e travar a linha para evitar processamento duplo
        $stmt = $pdo->prepare("SELECT * FROM pedidos WHERE id = ? AND status = 'PENDENTE' FOR UPDATE");
        $stmt->execute([$pedidoId]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pedido) {
            // Se encontrou um pedido pendente com este ID, continue.

            // 6. Atualizar o status do pedido para APROVADO
            $stmtUpdate = $pdo->prepare("UPDATE pedidos SET status = 'APROVADO', updated_at = NOW() WHERE id = ?");
            $stmtUpdate->execute([$pedidoId]);
            log_webhook("Status do pedido ID {$pedidoId} atualizado para APROVADO.");

            $usuario_id = (int)$pedido['usuario_id'];

            // 7. LIBERAR O ACESSO AO CONTEÚDO
            if (!empty($pedido['curso_id'])) {
                // Caso seja a compra de um curso individual
                $curso_id = (int)$pedido['curso_id'];
                log_webhook("Liberando acesso ao curso ID {$curso_id} para o usuário ID {$usuario_id}.");

                $stmtInsert = $pdo->prepare("INSERT INTO usuario_cursos (usuario_id, curso_id) VALUES (?, ?) ON CONFLICT (usuario_id, curso_id) DO NOTHING");
                $stmtInsert->execute([$usuario_id, $curso_id]);

            } elseif (!empty($pedido['plano_id'])) {
                // Caso seja a compra do plano "Acesso Total"
                log_webhook("Liberando acesso a TODOS OS CURSOS (plano) para o usuário ID {$usuario_id}.");

                $stmtCursos = $pdo->query("SELECT id FROM cursos");
                $todos_cursos_ids = $stmtCursos->fetchAll(PDO::FETCH_COLUMN, 0);

                $stmtInsertPlano = $pdo->prepare("INSERT INTO usuario_cursos (usuario_id, curso_id) VALUES (?, ?) ON CONFLICT (usuario_id, curso_id) DO NOTHING");
                foreach ($todos_cursos_ids as $curso_id) {
                    $stmtInsertPlano->execute([$usuario_id, (int)$curso_id]);
                }
            }

            // 8. Se tudo deu certo, confirma as alterações no banco
            $pdo->commit();
            log_webhook("Pedido ID {$pedidoId} processado com SUCESSO!");

        } else {
            // Se o pedido não foi encontrado ou já foi processado, apenas registre.
            log_webhook("Pedido ID {$pedidoId} não encontrado como 'PENDENTE'. Provavelmente já foi processado. Nenhuma ação tomada.");
            // Ainda commitamos a transação vazia para liberar o lock
            $pdo->commit();
        }
    } catch (Exception $e) {
        // 9. Se algo der errado, desfaz todas as alterações
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_webhook("ERRO CRÍTICO ao processar pedido {$pedidoId}: " . $e->getMessage());
        http_response_code(500); // Internal Server Error
        exit;
    }
} else {
    log_webhook("Webhook recebido para o pedido ID {$pedidoId} com status '{$status}'. Nenhuma ação tomada.");
}

// 10. Responda à PixUp com status 200 para confirmar o recebimento.
http_response_code(200);
echo json_encode(['ok' => true, 'message' => 'Webhook recebido.']);
?>