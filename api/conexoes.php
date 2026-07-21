<?php
// Histórico de conexões de um número (para o pop-up "ver conexões"). Sessão + dono.
// Paginado: ?pagina=N e ?por_pagina=M (o painel manda quantas linhas cabem na
// tela); responde também pagina/paginas.
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

$leadId = (int) ($_GET['lead_id'] ?? 0);

$q = db()->prepare('SELECT telefone, roteador, online, conectado_em, segundos_conectado, visto_em FROM leads WHERE id = ?');
$q->execute([$leadId]);
$lead = $q->fetch();
if (!$lead) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'erro' => 'lead nao encontrado']));
}

$isAdmin = (int) $comprador['is_admin'] === 1;
if (!$isAdmin && !in_array($lead['roteador'], roteadores_conta((int) $comprador['id']), true)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'erro' => 'sem permissao']));
}

// Tamanho da página vem do painel (quantas linhas cabem na tela SEM rolar),
// clampado aqui — a fronteira é o servidor, não o JS.
$POR_PAG = max(3, min(30, (int) ($_GET['por_pagina'] ?? 10)));
$qt = db()->prepare('SELECT COUNT(*) FROM conexoes WHERE lead_id = ?');
$qt->execute([$leadId]);
$paginas = max(1, (int) ceil(((int) $qt->fetchColumn()) / $POR_PAG));
$pagina  = min($paginas, max(1, (int) ($_GET['pagina'] ?? 1)));

try {
    $c = db()->prepare(
        'SELECT conectado_em, dispositivo, ip, segundos, bytes FROM conexoes WHERE lead_id = ?
          ORDER BY conectado_em DESC, id DESC
          LIMIT ' . $POR_PAG . ' OFFSET ' . (($pagina - 1) * $POR_PAG)
    );
    $c->execute([$leadId]);
    $conexoes = $c->fetchAll();
} catch (Throwable $e) {
    // Causa clássica: banco antigo, sem a coluna `segundos` (tempo por conexão).
    http_response_code(500);
    exit(json_encode(['ok' => false, 'erro' => 'Banco desatualizado: rode sql/migracao_conexoes.sql no phpMyAdmin.']));
}

foreach ($conexoes as &$cx) {
    $cx['segundos'] = $cx['segundos'] === null ? null : (int) $cx['segundos'];
    $cx['bytes']    = $cx['bytes'] === null ? null : (int) $cx['bytes'];
}
unset($cx);

// Sessão em andamento: a conexão mais recente ainda não tem duração gravada —
// mostra o tempo decorrido até agora (lead_estado corrige o "online preso").
if ($pagina === 1 && $conexoes && $conexoes[0]['segundos'] === null) {
    $st = lead_estado($lead, strtotime(db_now()));
    if ($st['online'] === 1) {
        $conexoes[0]['segundos'] = $st['elapsed'];
    }
}

echo json_encode([
    'ok'       => true,
    'telefone' => $lead['telefone'],
    'conexoes' => $conexoes,
    'pagina'   => $pagina,
    'paginas'  => $paginas,
]);
