<?php
// O painel pede o teste de velocidade do roteador e acompanha o resultado.
//   GET  -> estado atual (pendente + últimas medições)
//   POST -> pede um teste novo
// Autenticado por sessão, com o isolamento de sempre: o roteador do pedido é
// validado contra a lista da conta (ou do cliente aberto, no admin).
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$c = comprador_logado();
if (!$c) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'erro' => 'nao autenticado']));
}

if ((int) $c['is_admin'] === 1) {
    $cid   = (int) ($_REQUEST['cliente_id'] ?? 0);
    $lista = $cid > 0 ? roteadores_conta($cid) : [];
} else {
    $lista = roteadores_conta((int) $c['id']);
}
$roteador = trim((string) ($_REQUEST['roteador'] ?? ''));
if ($roteador === '' || !in_array($roteador, $lista, true)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'erro' => 'roteador invalido']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'erro' => 'sessao expirada']));
    }
    // Roteador fora do ar não vai buscar o pedido — melhor dizer isso agora do
    // que deixar o painel girando à toa por dez minutos.
    if (!mikrotik_online($roteador)) {
        exit(json_encode(['ok' => false, 'erro' => 'O roteador está fora do ar. O teste roda quando ele voltar.']));
    }
    speed_pedir($roteador, 10);
}

echo json_encode([
    'ok'       => true,
    'pendente' => speed_pendente($roteador),
    'online'   => mikrotik_online($roteador),
    'medicoes' => speed_hist($roteador),
]);
