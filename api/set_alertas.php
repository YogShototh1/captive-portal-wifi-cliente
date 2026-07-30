<?php
// Salva quais avisos o comprador quer ver. A escolha e da CONTA (nao do
// roteador): quem tem varios MikroTiks ve os mesmos avisos em todos.
// Responde JSON — o modal salva sem recarregar a pagina.
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
if (!csrf_valido($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'erro' => 'sessao expirada, recarregue a pagina']));
}

// Admin editando o painel de um cliente salva na conta DELE, nao na do admin.
$conta = (int) $c['is_admin'] === 1 && (int) ($_POST['cliente_id'] ?? 0) > 0
    ? (int) $_POST['cliente_id']
    : (int) $c['id'];

alertas_set($conta, $_POST);
echo json_encode(['ok' => true, 'marcadas' => alertas_get($conta)]);
