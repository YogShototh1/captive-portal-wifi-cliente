<?php
// TEMPORARIO — diagnostico da contagem de tempo de conexao.
//
// So leitura. Nao mostra telefone nem nome; o MAC sai mascarado. Serve para
// responder uma pergunta: o polling (api/status.php) roda de quanto em quanto
// tempo de verdade, e onde a duracao das sessoes esta sendo esticada.
//
// Apagar quando a investigacao terminar (sai tambem do tools/.htaccess).
//   /tools/diag_tempo.php?token=SEU_ADMIN_TOKEN&r=NOME-DO-ROTEADOR
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/util.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals((string) config()['admin_token'], (string) ($_REQUEST['token'] ?? ''))) {
        http_response_code(403);
        exit("token invalido\n");
    }
}
$rot = trim((string) ($_REQUEST['r'] ?? ''));
if ($rot === '') {
    echo "roteadores com leads:\n";
    foreach (db()->query('SELECT roteador, COUNT(*) n, MAX(conectado_em) ult FROM leads GROUP BY roteador ORDER BY n DESC')->fetchAll() as $r) {
        echo '  ' . $r['roteador'] . '   ' . $r['n'] . ' leads   ultimo ' . $r['ult'] . "\n";
    }
    exit("\nrode de novo com &r=<roteador>\n");
}

function mask(?string $m): string { return $m === null ? '?' : substr($m, 0, 8) . '..' . substr($m, -2); }

$now = db_now();
// Heartbeat: prova que o status.php esta sendo CHAMADO, com ou sem gente online.
$f = mikrotik_seen_file($rot);
$idade = is_file($f) ? (time() - (int) @filemtime($f)) : null;
echo "agora (banco): $now\n";
echo 'ultima chamada do status.php: ' . ($idade === null ? 'NUNCA' : $idade . 's atras') . "\n";
echo "roteador: $rot   |  MIKROTIK_TIMEOUT_SEG = " . MIKROTIK_TIMEOUT_SEG
     . '  |  SESSAO_PASSO_MAX_SEG = ' . SESSAO_PASSO_MAX_SEG . "\n";
$tem = db()->query("SHOW COLUMNS FROM conexoes LIKE 'seg_ac'")->fetch();
echo 'coluna conexoes.seg_ac: ' . ($tem ? 'existe' : 'NAO EXISTE') . "\n\n";

// 0) O que o roteador REPORTOU em cada rodada com gente online. E a unica
//    forma de responder se o aparelho sai da lista de ativos logo depois de
//    entrar — o visto_em sozinho nao conta essa historia porque e sobrescrito.
$lg = anuncio_base($rot) . '.diag';
echo "== rodadas reportadas pelo roteador (ultimas 30) ==\n";
if (!is_file($lg)) {
    echo "  (nada ainda — o registro so grava quando ha alguem online)\n\n";
} else {
    $lin = array_slice(array_filter(explode("\n", (string) @file_get_contents($lg))), -30);
    $ant = null;
    foreach ($lin as $l) {
        $ts = strtotime(substr($l, 0, 19));
        echo '  ' . $l . ($ant === null ? '' : '   (+' . ($ts - $ant) . 's)') . "\n";
        $ant = $ts;
    }
    echo '  linhas guardadas: ' . count($lin) . "\n\n";
}

// 1) Cadencia real do polling: os instantes distintos de visto_em sao as
//    rodadas do status.php que encontraram alguem online.
$q = db()->prepare(
    "SELECT DISTINCT c.visto_em FROM conexoes c JOIN leads l ON l.id = c.lead_id
      WHERE l.roteador = ? AND c.visto_em IS NOT NULL ORDER BY c.visto_em DESC LIMIT 40"
);
$q->execute([$rot]);
$vs = array_column($q->fetchAll(), 'visto_em');
echo "== rodadas do polling (visto_em distintos, mais novo primeiro) ==\n";
for ($i = 0; $i < count($vs); $i++) {
    $gap = isset($vs[$i + 1]) ? (strtotime($vs[$i]) - strtotime($vs[$i + 1])) : null;
    echo '  ' . $vs[$i] . ($gap === null ? '' : '   (+' . $gap . "s)") . "\n";
}
echo '  total de rodadas listadas: ' . count($vs) . "\n\n";

// 2) Ultimas conexoes: e aqui que a duracao aparece esticada.
$q2 = db()->prepare(
    "SELECT c.id, c.mac, c.conectado_em, c.visto_em, c.segundos, c.bytes
       FROM conexoes c JOIN leads l ON l.id = c.lead_id
      WHERE l.roteador = ? ORDER BY c.id DESC LIMIT 25"
);
$q2->execute([$rot]);
echo "== ultimas 25 conexoes ==\n";
echo "  id     mac          conectado_em         visto_em             segundos    (visto-con)\n";
foreach ($q2->fetchAll() as $c) {
    $con = strtotime((string) $c['conectado_em']);
    $vt  = $c['visto_em'] ? strtotime((string) $c['visto_em']) : null;
    printf("  %-6s %-12s %-20s %-20s %-11s %s\n",
        $c['id'], mask($c['mac']), $c['conectado_em'], $c['visto_em'] ?? '(nunca visto)',
        $c['segundos'] === null ? 'ABERTA' : $c['segundos'],
        $vt === null ? '-' : ($vt - $con) . 's');
}

// 3) Leads marcados online: se visto_em ficou velho, a flag esta presa.
$q3 = db()->prepare(
    'SELECT id, conectado_em, visto_em, segundos_conectado, online
       FROM leads WHERE roteador = ? ORDER BY conectado_em DESC LIMIT 15'
);
$q3->execute([$rot]);
echo "\n== ultimos 15 leads ==\n";
echo "  id     on  conectado_em         visto_em             seg_gravado  idade do visto_em\n";
$nowTs = strtotime($now);
foreach ($q3->fetchAll() as $l) {
    $vt = $l['visto_em'] ? strtotime((string) $l['visto_em']) : null;
    printf("  %-6s %-3s %-20s %-20s %-12s %s\n",
        $l['id'], (int) $l['online'], $l['conectado_em'], $l['visto_em'] ?? '(nunca)',
        $l['segundos_conectado'] === null ? 'NULL' : $l['segundos_conectado'],
        $vt === null ? '-' : ($nowTs - $vt) . 's');
}

// 4) Distribuicao das duracoes fechadas: separa o joio (horas) do trigo.
$q4 = db()->prepare(
    "SELECT
        SUM(c.segundos IS NULL)                              AS abertas,
        SUM(c.segundos <= 60)                                AS ate_1min,
        SUM(c.segundos > 60 AND c.segundos <= 3600)          AS ate_1h,
        SUM(c.segundos > 3600 AND c.segundos <= 86400)       AS ate_1dia,
        SUM(c.segundos > 86400)                              AS mais_de_1dia,
        COUNT(*)                                             AS total
       FROM conexoes c JOIN leads l ON l.id = c.lead_id WHERE l.roteador = ?"
);
$q4->execute([$rot]);
echo "\n== duracao das conexoes ==\n";
foreach ($q4->fetch(PDO::FETCH_ASSOC) as $k => $v) { echo "  $k: " . (int) $v . "\n"; }
