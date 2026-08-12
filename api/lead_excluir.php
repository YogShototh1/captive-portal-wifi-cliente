<?php
// Exclui um lead e o histórico de conexões dele. Autenticado por sessão.
// Isolamento igual ao set_limite.php: cliente só exclui lead dos roteadores
// dele; admin exclui qualquer um.
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';

header('Content-Type: application/json; charset=utf-8');

$comprador = comprador_logado();
if (!$comprador) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'erro' => 'nao autenticado']));
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}
if (!csrf_valido($in['csrf'] ?? '')) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'erro' => 'csrf']));
}

$id = (int) ($in['id'] ?? 0);

// `ids` chega quando a linha da tabela é uma pessoa mesclada de vários
// MikroTiks: excluir apaga a pessoa, senão ela voltaria a aparecer pelo outro.
$ids = leads_permitidos($in['ids'] ?? $id, $comprador);
if (!$ids) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'erro' => 'Lead não encontrado.']));
}

$ph = implode(',', array_fill(0, count($ids), '?'));
try {
    db()->prepare("DELETE FROM conexoes WHERE lead_id IN ($ph)")->execute($ids);
    db()->prepare("DELETE FROM leads WHERE id IN ($ph)")->execute($ids);
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'erro' => 'Falha ao excluir.']));
}

echo json_encode(['ok' => true, 'id' => $id, 'ids' => $ids]);
