<?php
// Status do MikroTik (online/offline) — leve, para o painel verificar de forma
// constante e independente da tabela de leads. Autenticado por sessão.
// Cliente vê o próprio roteador; admin passa ?roteador=.
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$comprador = comprador_logado();
if (!$comprador) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'erro' => 'nao autenticado']));
}

$isAdmin = (int) $comprador['is_admin'] === 1;
$pedido  = trim((string) ($_GET['roteador'] ?? ''));

// Cliente: ?roteador= vazio -> TODOS os da conta (LED verde = todos online);
// identity da conta -> só ele. Admin: ?roteador=X ou ?cliente_id=N (todos).
if ($isAdmin) {
    $cid   = (int) ($_GET['cliente_id'] ?? 0);
    $lista = $cid > 0 ? roteadores_conta($cid) : ($pedido !== '' ? [$pedido] : []);
} else {
    $lista = roteadores_conta((int) $comprador['id']);
    if ($pedido !== '' && in_array($pedido, $lista, true)) {
        $lista = [$pedido];
    }
}

echo json_encode(['ok' => true, 'online' => mikrotiks_online($lista)]);
