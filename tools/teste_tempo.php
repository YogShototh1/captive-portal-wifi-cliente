<?php
// Trava da contagem de tempo de conexao.
//
// Esta conta ja esteve errada nos dois sentidos, em producao: sessao marcando
// 11 segundos (a duracao do anuncio, e nao a da navegacao) e sessao marcando
// 95 HORAS (um buraco de quatro dias no polling somado como internet). As duas
// vinham da mesma formula: "ultima confirmacao menos a hora do login".
//
// A regra que substituiu: o tempo e SOMADO rodada a rodada, e cada rodada so
// pode creditar ate SESSAO_PASSO_MAX_SEG. Este arquivo trava isso.
//
// Rodar:  php tools/teste_tempo.php
// Teste de linha de comando, nunca pela web (o tools/.htaccess ja bloqueia a
// pasta; esta guarda existe para o bloqueio nao depender do servidor).
if (PHP_SAPI !== "cli") { http_response_code(404); exit; }

$src = (string) file_get_contents(__DIR__ . '/../inc/util.php');
$ini = strpos($src, 'const MIKROTIK_TIMEOUT_SEG');
$fim = strpos($src, '// Arquivo-marcador do último contato');
eval(substr($src, $ini, $fim - $ini));
$ini2 = strpos($src, 'function lead_estado');
$fim2 = strpos($src, '// Contadores dos cartões de resumo');
eval(substr($src, $ini2, $fim2 - $ini2));

$falhas = 0;
$ok = function ($cond, $nome, $viu = '') use (&$falhas) {
    if ($cond) { echo "  ok   $nome\n"; }
    else { $falhas++; echo "  FALHOU  $nome  $viu\n"; }
};

// O passo de UMA rodada, como o api/status.php o calcula. Copia fiel da linha
// que soma: se divergir daqui, o teste para de valer.
$passo = function (?int $vistoAnterior, int $agora): int {
    return $vistoAnterior === null ? 0 : min(max(0, $agora - $vistoAnterior), SESSAO_PASSO_MAX_SEG);
};

echo "passo de uma rodada do polling\n";
$ok($passo(null, 1000) === 0,
    'primeira confirmacao credita zero (ate ali so houve o POST do portal)');
$ok($passo(1000, 1060) === 60, 'rodada normal de 1 min credita 1 min', (string) $passo(1000, 1060));
$ok($passo(1000, 1005) === 5, 'rodada de 5s credita 5s');
// O buraco: era isto que virava 95 horas.
$ok($passo(1000, 1000 + 345600) === SESSAO_PASSO_MAX_SEG,
    'buraco de 4 dias credita um passo, nao 4 dias', (string) $passo(1000, 1000 + 345600));
$ok($passo(2000, 1000) === 0, 'relogio andando para tras nao vira tempo negativo');

echo "\numa sessao inteira, somada rodada a rodada\n";
// Sessao real: login, anuncio de 10s, e dai 20 confirmacoes de 1 min.
$t = 0; $ac = 0; $visto = null;
$t = 15;                                  // primeira confirmacao, 15s apos o POST
$ac += $passo($visto, $t); $visto = $t;
for ($i = 0; $i < 20; $i++) { $t += 60; $ac += $passo($visto, $t); $visto = $t; }
$ok($ac === 1200, 'vinte rodadas de 1 min dao 20 min cravados', $ac . 's');
// A prova do bug antigo: a subtracao daria 15s a mais, todo santo dia.
$ok($ac !== ($visto - 0), 'nao e "ultima confirmacao menos a hora do login"');

echo "\nsessao confirmada UMA vez so\n";
$ac2 = 0; $ac2 += $passo(null, 15);
$ok($ac2 === 0, 'vale zero, e nao os 11-15s do anuncio', $ac2 . 's');

echo "\nlead_estado: o que a tabela mostra\n";
$agora = 1000000;
$vivo  = date('Y-m-d H:i:s', $agora - 5);   // confirmado ha 5s
$velho = date('Y-m-d H:i:s', $agora - 9999);
$login = date('Y-m-d H:i:s', $agora - 7200);

$e = lead_estado(['online' => 1, 'conectado_em' => $login, 'segundos_conectado' => 600,
                  'visto_em' => $vivo], $agora);
$ok($e['online'] === 1, 'confirmado ha pouco continua online');
$ok($e['elapsed'] === 605, 'mostra o somado + o pedaco desde a confirmacao', $e['elapsed'] . 's');

// O caso que fazia o tempo crescer sozinho para sempre: sync parado.
$e = lead_estado(['online' => 1, 'conectado_em' => $login, 'segundos_conectado' => 600,
                  'visto_em' => $velho], $agora);
$ok($e['online'] === 0, 'sync parado -> offline');
$ok($e['elapsed'] === 600, 'o tempo PARA no que foi confirmado, nao vira 2 horas', $e['elapsed'] . 's');

// Aparelho que nunca entrou: nada a mostrar. Antes virava sessao eterna.
$e = lead_estado(['online' => 0, 'conectado_em' => $login, 'segundos_conectado' => null,
                  'visto_em' => null], $agora);
$ok($e['seg'] === null, 'nunca confirmado: a tabela mostra "—"');
$ok($e['elapsed'] === 0, 'e nao "agora menos o login"', $e['elapsed'] . 's');

// Online sem confirmacao nenhuma e contradicao: vale a confirmacao.
$e = lead_estado(['online' => 1, 'conectado_em' => $login, 'segundos_conectado' => null,
                  'visto_em' => null], $agora);
$ok($e['online'] === 0 && $e['elapsed'] === 0, 'flag online sem visto_em nao inventa tempo');

// O teto tambem vale aqui: painel aberto com o sync na janela-limite.
$e = lead_estado(['online' => 1, 'conectado_em' => $login, 'segundos_conectado' => 100,
                  'visto_em' => date('Y-m-d H:i:s', $agora - MIKROTIK_TIMEOUT_SEG)], $agora);
$ok($e['elapsed'] === 100 + MIKROTIK_TIMEOUT_SEG, 'na borda da janela ainda soma o pedaco certo',
    $e['elapsed'] . 's');

echo "\nteto coerente com o intervalo do scheduler\n";
// O teto tem de ser maior que o intervalo do leadsync, senao rodada normal
// perderia tempo. O setup instala 1m.
$rsc = (string) file_get_contents(__DIR__ . '/../mikrotik/setup-varejo.rsc');
preg_match('/scheduler add name=leadsync interval=(\S+)/', $rsc, $m);
$intv = $m[1] ?? '?';
$seg  = ['5s' => 5, '10s' => 10, '30s' => 30, '1m' => 60, '2m' => 120][$intv] ?? null;
$ok($seg !== null, "o intervalo do leadsync foi lido do setup ($intv)");
$ok($seg !== null && SESSAO_PASSO_MAX_SEG >= $seg * 2,
    'o teto cabe duas rodadas do scheduler', 'teto ' . SESSAO_PASSO_MAX_SEG . 's vs ' . $intv);

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
