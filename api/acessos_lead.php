<?php
// Log de acessos de UM lead (modal do botão "!"). SOMENTE ADMIN.
// Lista por MAC do número: hora / ip cliente / ip destino (+ host) / aparelho.
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

$leadId = (int) ($_GET['lead_id'] ?? 0);
$q = db()->prepare('SELECT telefone, nome, roteador FROM leads WHERE id = ?');
$q->execute([$leadId]);
$lead = $q->fetch();
if (!$lead) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'erro' => 'lead nao encontrado']));
}

// MACs deste número (todos os aparelhos).
$qm = db()->prepare('SELECT DISTINCT mac FROM conexoes WHERE lead_id = ? AND mac IS NOT NULL AND mac <> ""');
$qm->execute([$leadId]);
$macs = $qm->fetchAll(PDO::FETCH_COLUMN);
if (!$macs) {
    exit(json_encode(['ok' => true, 'telefone' => $lead['telefone'], 'nome' => $lead['nome'], 'acessos' => [], 'pagina' => 1, 'paginas' => 1]));
}
$ph = implode(',', array_fill(0, count($macs), '?'));

$POR_PAG = max(3, min(40, (int) ($_GET['por_pagina'] ?? 12)));
try {
    $qt = db()->prepare("SELECT COUNT(*) FROM acessos WHERE roteador = ? AND mac IN ($ph)");
    $qt->execute(array_merge([$lead['roteador']], $macs));
    $total = (int) $qt->fetchColumn();
    $paginas = max(1, (int) ceil($total / $POR_PAG));
    $pagina  = min($paginas, max(1, (int) ($_GET['pagina'] ?? 1)));

    $c = db()->prepare(
        "SELECT a.visto_em, a.ip_cliente, a.ip_destino, a.hits, hc.host,
                (SELECT cx.dispositivo FROM conexoes cx WHERE cx.mac = a.mac ORDER BY cx.id DESC LIMIT 1) AS dispositivo
           FROM acessos a
           LEFT JOIN host_cache hc ON hc.ip = a.ip_destino
          WHERE a.roteador = ? AND a.mac IN ($ph)
          ORDER BY a.visto_em DESC
          LIMIT $POR_PAG OFFSET " . (($pagina - 1) * $POR_PAG)
    );
    $c->execute(array_merge([$lead['roteador']], $macs));
    $acessos = $c->fetchAll();
} catch (Throwable $e) {
    // Tabela ainda não existe (nenhum acesso registrado) = lista vazia.
    exit(json_encode(['ok' => true, 'telefone' => $lead['telefone'], 'nome' => $lead['nome'], 'acessos' => [], 'pagina' => 1, 'paginas' => 1]));
}

echo json_encode([
    'ok'       => true,
    'telefone' => $lead['telefone'],
    'nome'     => $lead['nome'],
    'acessos'  => $acessos,
    'pagina'   => $pagina,
    'paginas'  => $paginas,
]);
