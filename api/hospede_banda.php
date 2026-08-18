<?php
// Banda máxima de UM hóspede (Mbps). Autenticado por sessão; só roteador da
// conta aberta.
//
// Por que não é o api/set_banda.php: aquele grava em `leads`, e lead só existe
// depois da primeira conexão. O hóspede tem o teto desde o check-in, então o
// valor mora no CADASTRO. Grava nos dois lugares:
//   hospedes.banda_limite  -> vale para as conexões futuras (api/lead.php copia)
//   leads.banda_limite     -> vale AGORA, para quem já está no Wi-Fi
// (o api/status.php lê o lead a cada rodada e é ele que aplica o PCQ).
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

// Mesmas regras do api/set_banda.php: vazio/0 = sem limite, teto 10000 Mbps.
$bRaw  = $in['banda'] ?? '';
$banda = ($bRaw === '' || $bRaw === null) ? null : max(0, (int) $bRaw);
if ($banda === 0) {
    $banda = null;
}
if ($banda !== null) {
    $banda = min($banda, 10000);
}

$isAdmin = (int) $comprador['is_admin'] === 1;
$cid     = $isAdmin ? (int) ($in['cliente_id'] ?? 0) : (int) $comprador['id'];
$lista   = roteadores_conta($cid > 0 ? $cid : (int) $comprador['id']);

try {
    $q = db()->prepare('SELECT roteador, telefone FROM hospedes WHERE id = ?');
    $q->execute([$id]);
    $h = $q->fetch();
    if (!$h || !in_array($h['roteador'], $lista, true)) {
        http_response_code(404);
        exit(json_encode(['ok' => false, 'erro' => 'Hóspede não encontrado.']));
    }
    db()->prepare('UPDATE hospedes SET banda_limite = ? WHERE id = ?')->execute([$banda, $id]);
    db()->prepare('UPDATE leads SET banda_limite = ? WHERE roteador = ? AND telefone = ?')
        ->execute([$banda, $h['roteador'], $h['telefone']]);
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'erro' => 'Falha ao salvar.']));
}

echo json_encode(['ok' => true, 'id' => $id, 'banda' => $banda]);
