<?php
// Teste de semana_reparte(): os casos que produziam celula de 41h num dia.
// Rodar:  php tools/teste_semana.php
require_once __DIR__ . '/../inc/util.php';

$DOM = strtotime('2026-07-26 00:00:00');            // domingo
$lim = [];
for ($k = 0; $k <= 7; $k++) { $lim[$k] = strtotime('2026-07-26 +' . $k . ' day 00:00:00'); }
$H = 3600;
$t = function ($s) { return strtotime($s); };
$falhas = 0;
$ok = function ($cond, $nome, $viu = '') use (&$falhas) {
    if ($cond) { echo "  ok   $nome\n"; }
    else { $falhas++; echo "  FALHOU  $nome  $viu\n"; }
};

// 1) O caso real: uma sessao de 41h50 comecando segunda 08:00.
$d = semana_reparte([[$t('2026-07-27 08:00'), $t('2026-07-27 08:00') + 41 * $H + 50 * 60]], $lim);
$ok($d[1] === 16 * $H, 'segunda recebe so 08:00->24:00 (16h)', json_encode($d[1]));
$ok($d[2] === 24 * $H, 'terca cheia (24h)', json_encode($d[2]));
$ok($d[3] === 1 * $H + 50 * 60, 'quarta recebe o resto (1h50)', json_encode($d[3]));
$ok(array_sum($d) === 41 * $H + 50 * 60, 'o total continua 41h50');
$ok(max($d) <= 24 * $H, 'nenhum dia passa de 24h');

// 2) Dois aparelhos sobrepostos nao contam em dobro.
$d = semana_reparte([[$t('2026-07-27 10:00'), $t('2026-07-27 14:00')],
                     [$t('2026-07-27 12:00'), $t('2026-07-27 16:00')]], $lim);
$ok($d[1] === 6 * $H, 'sobreposicao 10-14 + 12-16 = 6h (nao 8h)', json_encode($d[1] / $H));

// 3) Sessoes separadas no mesmo dia somam normal.
$d = semana_reparte([[$t('2026-07-27 08:00'), $t('2026-07-27 09:00')],
                     [$t('2026-07-27 20:00'), $t('2026-07-27 21:00')]], $lim);
$ok($d[1] === 2 * $H, 'duas sessoes separadas somam 2h');

// 4) Sessao que comecou antes da semana entra so com a parte de dentro.
$d = semana_reparte([[$t('2026-07-25 20:00'), $t('2026-07-26 06:00')]], $lim);
$ok($d[0] === 6 * $H && array_sum($d) === 6 * $H, 'sabado 20h -> domingo 06h conta 6h no domingo');

// 5) Sessao que passa do fim da semana e cortada.
$d = semana_reparte([[$t('2026-08-01 20:00'), $t('2026-08-02 10:00')]], $lim);
$ok($d[6] === 4 * $H && array_sum($d) === 4 * $H, 'sabado 20h -> domingo seguinte conta so 4h');

// 6) Extremo: semana inteira mais aparelhos extras — teto de 24h por dia.
$d = semana_reparte([[$lim[0], $lim[7]],
                     [$t('2026-07-27 00:00'), $t('2026-07-28 00:00')],
                     [$t('2026-07-29 00:00'), $t('2026-07-29 12:00')]], $lim);
$ok(max($d) === 24 * $H && array_sum($d) === 7 * 24 * $H, 'semana cheia = 24h por dia, sem estourar');

// 7) Borda: intervalo vazio ou invertido some.
$d = semana_reparte([[$t('2026-07-27 10:00'), $t('2026-07-27 10:00')],
                     [$t('2026-07-27 15:00'), $t('2026-07-27 14:00')]], $lim);
$ok(array_sum($d) === 0, 'intervalo vazio/invertido nao vira tempo');

// ---- conexao_intervalo(): de onde sai o fim de cada sessao ----
$agora = $t('2026-07-29 12:00');
$iv = conexao_intervalo('2026-07-27 08:00:00', '2026-07-27 10:00:00', $agora);
$ok($iv === [$t('2026-07-27 08:00'), $t('2026-07-27 10:00')], 'sessao fechada vira o intervalo gravado');

$iv = conexao_intervalo('2026-07-29 09:00:00', '2026-07-29 11:30:00', $agora);
$ok($iv[1] === $t('2026-07-29 11:30'), 'sessao aberta vale ate o ultimo visto, nao ate agora');

// A regressao: conexao que o polling nunca viu (sem segundos e sem visto_em).
$ok(conexao_intervalo('2026-05-02 19:00:00', null, $agora) === null,
    'conexao nunca vista nao vira tempo (era a semana toda em 24h)');
$ok(conexao_intervalo('2026-05-02 19:00:00', '', $agora) === null, 'fim vazio tambem e descartado');

$iv = conexao_intervalo('2026-07-29 11:00:00', '2026-07-29 23:00:00', $agora);
$ok($iv[1] === $agora, 'fim no futuro e cortado no agora');
$ok(conexao_intervalo('2026-07-27 10:00:00', '2026-07-27 10:00:00', $agora) === null,
    'sessao de duracao zero nao vira tempo');

// E o efeito na grade: so a sessao real aparece, a orfa nao.
$sess = [];
foreach ([['2026-07-27 08:00:00', '2026-07-27 12:00:00'], ['2026-05-02 19:00:00', null]] as $l) {
    $x = conexao_intervalo($l[0], $l[1], $agora);
    if ($x !== null) { $sess[] = $x; }
}
$d = semana_reparte($sess, $lim);
$ok(array_sum($d) === 4 * $H && $d[1] === 4 * $H, 'grade com 1 sessao real + 1 orfa = so as 4h reais');

echo $falhas ? "\n$falhas falha(s)\n" : "\ntudo certo\n";
exit($falhas ? 1 : 0);
