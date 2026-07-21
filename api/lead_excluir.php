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

$q = db()->prepare('SELECT roteador FROM leads WHERE id = ?');
$q->execute([$id]);
$row = $q->fetch();
if (!$row) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'erro' => 'Lead não encontrado.']));
}
$isAdmin = (int) $comprador['is_admin'] === 1;
if (!$isAdmin && !in_array($row['roteador'], roteadores_conta((int) $comprador['id']), true)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'erro' => 'Sem permissão.']));
}

try {
    db()->prepare('DELETE FROM conexoes WHERE lead_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'erro' => 'Falha ao excluir.']));
}

echo json_encode(['ok' => true, 'id' => $id]);
