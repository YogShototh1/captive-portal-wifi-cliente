<?php
// Log GERAL de acessos (aba lateral). SOMENTE ADMIN. Ordenado do mais recente
// para o mais antigo: nome/telefone / ip / ip destino (+ host) / aparelho.
// Filtro opcional ?roteador= (o cliente aberto no admin_leads).
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$comprador = comprador_logado();
if (!$comprador || (int) $comprador['is_admin'] !== 1) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'erro' => 'sem permissao']));
}

$roteador = trim((string) ($_GET['roteador'] ?? ''));
$POR_PAG  = max(5, min(100, (int) ($_GET['por_pagina'] ?? 50)));
$pagina   = max(1, (int) ($_GET['pagina'] ?? 1));

try {
    $where = $roteador !== '' ? 'WHERE a.roteador = ?' : '';
    $args  = $roteador !== '' ? [$roteador] : [];

    $qt = db()->prepare("SELECT COUNT(*) FROM acessos a $where");
    $qt->execute($args);
    $total = (int) $qt->fetchColumn();
    $paginas = max(1, (int) ceil($total / $POR_PAG));
    $pagina  = min($paginas, $pagina);

    // Nome/telefone do dono do MAC (via conexão mais recente daquele MAC).
    $c = db()->prepare(
        "SELECT a.visto_em, a.ip_cliente, a.ip_destino, a.mac, hc.host,
                (SELECT l.telefone FROM conexoes cx JOIN leads l ON l.id = cx.lead_id
                   WHERE cx.mac = a.mac AND l.roteador = a.roteador ORDER BY cx.id DESC LIMIT 1) AS telefone,
                (SELECT l.nome FROM conexoes cx JOIN leads l ON l.id = cx.lead_id
                   WHERE cx.mac = a.mac AND l.roteador = a.roteador ORDER BY cx.id DESC LIMIT 1) AS nome,
                (SELECT cx.dispositivo FROM conexoes cx WHERE cx.mac = a.mac ORDER BY cx.id DESC LIMIT 1) AS dispositivo
           FROM acessos a
           LEFT JOIN host_cache hc ON hc.ip = a.ip_destino
           $where
          ORDER BY a.visto_em DESC
          LIMIT $POR_PAG OFFSET " . (($pagina - 1) * $POR_PAG)
    );
    $c->execute($args);
    $log = $c->fetchAll();
} catch (Throwable $e) {
    exit(json_encode(['ok' => true, 'log' => [], 'pagina' => 1, 'paginas' => 1]));
}

echo json_encode(['ok' => true, 'log' => $log, 'pagina' => $pagina, 'paginas' => $paginas]);
