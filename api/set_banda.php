<?php
// O cliente (ou admin) define/limpa a banda máxima (Mbps) de UM lead. Sessão.
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

// Banda: vazio/null/0 = sem limite; senão inteiro > 0 (Mbps), teto 10000.
$bRaw  = $in['banda'] ?? '';
$banda = ($bRaw === '' || $bRaw === null) ? null : max(0, (int) $bRaw);
if ($banda === 0) {
    $banda = null;
}
if ($banda !== null) {
    $banda = min($banda, 10000);
}

// Confirma que o lead pertence ao roteador do comprador (admin pode qualquer um).
// `ids` chega quando a linha é uma pessoa mesclada de vários MikroTiks.
$ids = leads_permitidos($in['ids'] ?? $id, $comprador);
if (!$ids) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'erro' => 'lead nao encontrado']));
}

$ph = implode(',', array_fill(0, count($ids), '?'));
$u = db()->prepare("UPDATE leads SET banda_limite = ? WHERE id IN ($ph)");
$u->execute(array_merge([$banda], $ids));

echo json_encode(['ok' => true, 'id' => $id, 'banda' => $banda]);
