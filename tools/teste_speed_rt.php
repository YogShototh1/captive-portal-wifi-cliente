<?php
// Teste do fluxo do speedtest do roteador (pedido -> consumo -> resultado).
// Rodar:  php tools/teste_speed_rt.php
// Teste de linha de comando, nunca pela web. O tools/.htaccess ja bloqueia a
// pasta; esta guarda existe para o bloqueio nao depender de o servidor honrar
// aquele arquivo. Vale a pena: estes testes rodam sem autenticacao nenhuma, e
// alguns gravam e apagam arquivos em ads/.
if (PHP_SAPI !== "cli") { http_response_code(404); exit; }

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
// A VELOCIDADE e calculada com o que o ROTEADOR mediu (bytes + duracao), nao
// com o tempo do servidor: o Cloudflare no meio faz o servidor cronometrar so
// o trecho ate ele.
echo "\nduracao do RouterOS -> segundos\n";
foreach (['6s500ms' => 6.5, '00:00:06.500000' => 6.5, '1m2s' => 62.0, '250ms' => 0.25,
          '1s' => 1.0, '3.75' => 3.75, '00:01:30' => 90.0] as $e => $esp) {
    $r = speed_dur_seg((string) $e);
    $ok($r !== null && abs($r - $esp) < 0.001, "\"$e\" -> {$esp}s", var_export($r, true));
}
$ok(speed_dur_seg('') === null, 'vazio nao vira zero');
$ok(speed_dur_seg('nada') === null, 'lixo nao vira zero');
$ok(speed_dur_seg('0') === null, 'zero nao passa (evitaria divisao por zero)');

echo "\nconta da velocidade\n";
// 6 MB em 6,29 s = 8 Mbps
$bytes = 6 * 1024 * 1024;
$seg = speed_dur_seg('6s290ms');
$mbps = round($bytes * 8 / $seg / 1e6, 2);
$ok(abs($mbps - 8.0) < 0.05, '6 MiB em 6,29s = 8 Mbps', $mbps);
// 6 MB em 0,5 s = 100 Mbps
$mbps = round($bytes * 8 / speed_dur_seg('500ms') / 1e6, 2);
$ok(abs($mbps - 100.66) < 0.05, '6 MiB em 0,5s = ~100 Mbps', $mbps);

// ---------------------------------------------------------------
// O /ping do RouterOS NAO devolve avg-rtt no as-value: devolve uma lista, um
// item por pacote. A media e feita aqui.
echo "\nmedia dos tempos de cada pacote\n";
$media = function (string $ping): ?float {
    $v = [];
    foreach (explode(',', $ping) as $p) {
        $ms = speed_rtt_ms(trim($p));
        if ($ms !== null) { $v[] = $ms; }
    }
    return $v ? round(array_sum($v) / count($v), 1) : null;
};
// O formato de baixo (00:00:00.021866) e o que o roteador manda DE VERDADE —
// copiado de uma medicao real da Primix, 09/08/2026 16:27. Ficou meses sem
// aparecer no painel porque o servidor so sabia ler "12ms300us"; o roteador
// sempre mandou certo.
foreach (['00:00:00.021866,00:00:00.015775,00:00:00.015864,' => 17.9,
          '00:00:00.015989,00:00:00.015927,00:00:00.015895,' => 15.9,
          '12ms300us,11ms800us,13ms100us' => 12.4,
          '15ms,16ms,14ms,'               => 15.0,   // virgula sobrando no fim
          '8ms500us'                      => 8.5,    // um pacote so
          '1s200ms,1s100ms'               => 1150.0] as $e => $esp) {
    $r = $media((string) $e);
    $ok($r !== null && abs($r - $esp) < 0.05, "\"$e\" -> {$esp} ms", var_export($r, true));
}
$ok($media(',,') === null, 'lista vazia nao vira zero');
$ok($media('') === null, 'string vazia nao vira zero');

// ---------------------------------------------------------------
echo "\nrtt do RouterOS -> ms\n";
foreach (['12ms300us' => 12.3, '1s200ms' => 1200.0, '500us' => 0.5, '23' => 23.0,
          '2s' => 2000.0, '45ms' => 45.0,
          '00:00:00.021866' => 21.9,   // formato de relogio: o do /ping
          '00:00:01.500000' => 1500.0] as $e => $esp) {
    $r = speed_rtt_ms((string) $e);
    $ok($r !== null && abs($r - $esp) < 0.01, "\"$e\" -> {$esp}ms", var_export($r, true));
}
$ok(speed_rtt_ms('') === null, 'vazio nao vira zero');
$ok(speed_rtt_ms('nada') === null, 'lixo nao vira zero');

$limpar();
echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
