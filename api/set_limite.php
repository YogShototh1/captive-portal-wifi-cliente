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
$q = db()->prepare('SELECT roteador FROM leads WHERE id = ?');
$q->execute([$id]);
$row = $q->fetch();
if (!$row) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'erro' => 'lead nao encontrado']));
}
$isAdmin = (int) $comprador['is_admin'] === 1;
if (!$isAdmin && !in_array($row['roteador'], roteadores_conta((int) $comprador['id']), true)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'erro' => 'sem permissao']));
}

$u = db()->prepare('UPDATE leads SET tempo_limite_min = ? WHERE id = ?');
$u->execute([$limite, $id]);

echo json_encode(['ok' => true, 'id' => $id, 'limite' => $limite]);
