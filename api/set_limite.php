<?php
// O cliente (ou admin) define/limpa o limite de tempo de um lead. Autenticado por sessão.
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

// Limite: vazio/null = sem limite; senão inteiro >= 0 (minutos).
$limRaw = $in['limite'] ?? '';
$limite = ($limRaw === '' || $limRaw === null) ? null : max(0, (int) $limRaw);

// Confirma que o lead pertence ao roteador do comprador (admin pode qualquer um).
// `ids` chega quando a linha é uma pessoa mesclada de vários MikroTiks: o limite
// vale para ela, então vale nos dois.
$ids = leads_permitidos($in['ids'] ?? $id, $comprador);
if (!$ids) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'erro' => 'lead nao encontrado']));
}

$ph = implode(',', array_fill(0, count($ids), '?'));
$u = db()->prepare("UPDATE leads SET tempo_limite_min = ? WHERE id IN ($ph)");
$u->execute(array_merge([$limite], $ids));

echo json_encode(['ok' => true, 'id' => $id, 'limite' => $limite]);
