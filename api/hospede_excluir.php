<?php
// Apaga um hóspede. Autenticado por sessão; só roteador da conta aberta.
// Apagar corta o Wi-Fi: o portal deixa de reconhecer o número na hora.
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

$id      = (int) ($in['id'] ?? 0);
$isAdmin = (int) $comprador['is_admin'] === 1;
$cid     = $isAdmin ? (int) ($in['cliente_id'] ?? 0) : (int) $comprador['id'];
$lista   = roteadores_conta($cid > 0 ? $cid : (int) $comprador['id']);

try {
    $q = db()->prepare('SELECT roteador FROM hospedes WHERE id = ?');
    $q->execute([$id]);
    $rot = $q->fetchColumn();
    if ($rot === false || !in_array($rot, $lista, true)) {
        http_response_code(404);
        exit(json_encode(['ok' => false, 'erro' => 'Hóspede não encontrado.']));
    }
    db()->prepare('DELETE FROM hospedes WHERE id = ?')->execute([$id]);
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'erro' => 'Falha ao apagar.']));
}

echo json_encode(['ok' => true, 'id' => $id]);
