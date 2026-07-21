<?php
// Dados ao vivo dos leads (JSON) para o painel atualizar sem recarregar a página.
// Autenticado por sessão. Cliente vê o próprio roteador; admin passa ?roteador=.
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

// Lista de roteadores da consulta:
//   cliente: ?roteador= vazio -> TODOS os da conta; identity da conta -> só ele.
//   admin:   ?roteador=X -> só ele; ?cliente_id=N -> todos os do cliente.
if ($isAdmin) {
    $cid   = (int) ($_GET['cliente_id'] ?? 0);
    $lista = $cid > 0 ? roteadores_conta($cid) : ($pedido !== '' ? [$pedido] : []);
} else {
    $lista = roteadores_conta((int) $comprador['id']);
    if ($pedido !== '' && in_array($pedido, $lista, true)) {
        $lista = [$pedido];
    }
}

if (!$lista) {
    exit(json_encode([
        'ok'       => true,
        'now'      => db_now(),
        'leads'    => [],
        'resumo'   => ['online' => 0, 'hoje' => 0, 'cadastrados' => 0, 'total' => 0],
        'mikrotik' => false,
    ]));
}

// Mesma janela da tabela renderizada (50 por página): o JS passa ?pagina=N e o
// poll devolve só o que a página mostra — leads fora dela não viram "linhas novas".
// ?filtro= replica o filtro dos cartões (online/hoje/cadastrados) da página.
$filtro  = filtro_leads($_GET['filtro'] ?? '');
$POR_PAG = 50;
$pagina  = max(1, (int) ($_GET['pagina'] ?? 1));
$ph = implode(',', array_fill(0, count($lista), '?'));
$q = db()->prepare(
    "SELECT id, telefone, nome, ip, dispositivo, conectado_em, online, segundos_conectado, visto_em, tempo_limite_min, banda_limite, total_conexoes,
            (SELECT COALESCE(SUM(c.bytes), 0) FROM conexoes c WHERE c.lead_id = leads.id) AS bytes_total
       FROM leads WHERE roteador IN ($ph)" . filtro_leads_sql($filtro) . '
      ORDER BY conectado_em DESC
      LIMIT ' . $POR_PAG . ' OFFSET ' . (($pagina - 1) * $POR_PAG)
);
$q->execute($lista);
$leads = $q->fetchAll();

$dbNow = db_now();
$nowTs = strtotime($dbNow);

// Tipos numéricos coerentes + tempo conectado (elapsed) calculado no servidor.
foreach ($leads as &$l) {
    // lead_estado corrige o "online preso" (flag velha sem confirmação do sync).
    $st = lead_estado($l, $nowTs);
    $l['id']                 = (int) $l['id'];
    $l['online']             = $st['online'];
    $l['total_conexoes']     = (int) $l['total_conexoes'];
    $l['segundos_conectado'] = $st['seg'];
    $l['tempo_limite_min']   = $l['tempo_limite_min'] === null ? null : (int) $l['tempo_limite_min'];
    $l['banda_limite']       = $l['banda_limite'] === null ? null : (int) $l['banda_limite'];
    $l['elapsed']            = $st['elapsed'];
    $l['bytes_total']        = (int) ($l['bytes_total'] ?? 0);
}
unset($l);

// Contadores dos cartões de resumo (mesmos números do carregamento da página).
$resumo = resumo_leads($lista);

echo json_encode(['ok' => true, 'now' => $dbNow, 'leads' => $leads, 'resumo' => $resumo, 'mikrotik' => mikrotiks_online($lista)]);
