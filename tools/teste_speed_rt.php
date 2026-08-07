<?php
// Teste do fluxo do speedtest do roteador (pedido -> consumo -> resultado).
// Rodar:  php tools/teste_speed_rt.php
require_once __DIR__ . '/../inc/util.php';

$falhas = 0;
$ok = function ($cond, $nome, $viu = '') use (&$falhas) {
    if ($cond) { echo "  ok   $nome\n"; }
    else { $falhas++; echo "  FALHOU  $nome  $viu\n"; }
};

$R = '__teste_speed__';
$limpar = function () use ($R) {
    @unlink(speed_pedido_file($R));
    @unlink(speed_hist_file($R));
};
$limpar();

// ---------------------------------------------------------------
echo "pedido\n";
$ok(speed_pedido_ler($R) === 0, 'sem pedido, o roteador nao roda nada');
$ok(speed_pendente($R) === false, 'nada pendente');

speed_pedir($R, 6);
$ok(speed_pendente($R) === true, 'depois de pedir, fica pendente');
$ok(speed_pedido_ler($R) === 6, 'o roteador recebe os MB pedidos');
$ok(speed_pendente($R) === false, 'e o pedido e CONSUMIDO na leitura', var_export(speed_pendente($R), true));
$ok(speed_pedido_ler($R) === 0, 'ler de novo nao repete o teste');

// O teto existe para um clique nao torrar a franquia da loja.
speed_pedir($R, 999);
$ok(speed_pedido_ler($R) === 24, 'pedido acima do teto e limitado a 24 MB');
speed_pedir($R, 0);
$ok(speed_pedido_ler($R) === 1, 'pedido abaixo de 1 MB vira 1');

// Pedido velho e descartado: se o roteador estava fora e voltou horas depois,
// ninguem esta mais olhando o resultado.
speed_pedir($R, 6);
@touch(speed_pedido_file($R), time() - 3600);
$ok(speed_pendente($R) === false, 'pedido de 1h atras nao conta como pendente');
$ok(speed_pedido_ler($R) === 0, 'e o roteador nao roda teste velho');

// ---------------------------------------------------------------
echo "\nresultado chega em duas partes\n";
$limpar();
// O download vem do servidor; o ping vem depois, do roteador. Os dois tem que
// cair na MESMA medicao.
speed_gravar($R, ['down' => 42.5, 'mb' => 6]);
speed_gravar($R, ['ping' => 18.2]);
$h = speed_hist($R);
$ok(count($h) === 1, 'as duas partes viram UMA medicao', count($h));
$ok($h[0]['down'] === 42.5 && $h[0]['ping'] === 18.2, 'com download e ping juntos');

// Medicao antiga nao recebe o ping de um teste novo.
$h = speed_hist($R);
$h[0]['em'] = date('Y-m-d H:i:s', time() - 600);
@file_put_contents(speed_hist_file($R), json_encode($h));
speed_gravar($R, ['down' => 10.0]);
$h = speed_hist($R);
$ok(count($h) === 2, 'passados 10 min, comeca medicao nova', count($h));
// == e nao ===: o JSON devolve 10.0 como int 10 na volta.
$ok($h[0]['down'] == 10 && $h[0]['ping'] === null, 'e ela nasce sem o ping da anterior',
    'down=' . var_export($h[0]['down'], true) . ' ping=' . var_export($h[0]['ping'], true));
$ok($h[1]['down'] == 42.5, 'a anterior fica intacta no historico');

// ---------------------------------------------------------------
echo "\nhistorico\n";
$limpar();
for ($i = 0; $i < SPEED_HIST_MAX + 5; $i++) {
    $h = speed_hist($R);
    // forca cada uma a ser uma medicao nova
    foreach ($h as $k => $v) { $h[$k]['em'] = date('Y-m-d H:i:s', time() - 600 - $k); }
    @file_put_contents(speed_hist_file($R), json_encode($h));
    speed_gravar($R, ['down' => $i + 1.0]);
}
$ok(count(speed_hist($R)) === SPEED_HIST_MAX, 'para no teto de ' . SPEED_HIST_MAX, count(speed_hist($R)));
$ok(speed_hist($R)[0]['down'] == SPEED_HIST_MAX + 5, 'a mais recente sobrevive', speed_hist($R)[0]['down']);

// ---------------------------------------------------------------
echo "\nrtt do RouterOS -> ms\n";
foreach (['12ms300us' => 12.3, '1s200ms' => 1200.0, '500us' => 0.5, '23' => 23.0,
          '2s' => 2000.0, '45ms' => 45.0] as $e => $esp) {
    $r = speed_rtt_ms((string) $e);
    $ok($r !== null && abs($r - $esp) < 0.01, "\"$e\" -> {$esp}ms", var_export($r, true));
}
$ok(speed_rtt_ms('') === null, 'vazio nao vira zero');
$ok(speed_rtt_ms('nada') === null, 'lixo nao vira zero');

$limpar();
echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
