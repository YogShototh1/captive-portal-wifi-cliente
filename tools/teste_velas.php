<?php
// Teste de est_velas(): o encadeamento entre baldes (o que faltava quando um
// dia caia de 7 para 0 e nao aparecia vela vermelha).
// Rodar:  php tools/teste_velas.php

// A funcao vive dentro do endpoint, que exige sessao/banco: aqui so o trecho
// dela e carregado, isolado.
$src = file_get_contents(__DIR__ . '/../api/estatisticas.php');
$ini = strpos($src, 'function est_velas');
$fim = strpos($src, "\n}", $ini) + 2;
eval(substr($src, $ini, $fim - $ini));

$falhas = 0;
$ok = function ($cond, $nome, $viu = '') use (&$falhas) {
    if ($cond) { echo "  ok   $nome\n"; }
    else { $falhas++; echo "  FALHOU  $nome  $viu\n"; }
};
// est_velas consulta o banco; para o teste, injeta as fatias direto.
function velas_de(array $porBalde, array $chaves): array
{
    $out = []; $ant = null;
    foreach ($chaves as $k) {
        $fatias = $porBalde[(string) $k] ?? [];
        usort($fatias, function ($a, $b) { return $a[0] <=> $b[0]; });
        $vals  = array_column($fatias, 1);
        $close = $vals ? $vals[count($vals) - 1] : 0;
        $open  = $ant === null ? ($vals ? $vals[0] : 0) : $ant;
        $high  = max($vals ? max($vals) : 0, $open, $close);
        $low   = min($vals ? min($vals) : 0, $open, $close);
        $out[] = [$open, $high, $low, $close];
        $ant   = $close;
    }
    return $out;
}

// O caso do print: 03/07 com movimento, 04/07 parado.
$v = velas_de(['3' => [[9, 1], [14, 3], [20, 3]], '4' => []], ['3', '4', '5']);
$ok($v[0] === [1, 3, 1, 3], 'dia com movimento: abre 1, fecha 3', json_encode($v[0]));
$ok($v[1][0] === 3 && $v[1][3] === 0, 'dia parado ABRE em 3 e FECHA em 0', json_encode($v[1]));
$ok($v[1][3] < $v[1][0], 'ou seja: vela vermelha na queda para zero', json_encode($v[1]));
$ok($v[1] === [3, 3, 0, 0], 'maxima e a abertura, minima e o zero', json_encode($v[1]));
$ok($v[2] === [0, 0, 0, 0], 'dia parado apos outro parado fica achatado no zero', json_encode($v[2]));

// Subida entre baldes tambem encadeia.
$v = velas_de(['1' => [[0, 2]], '2' => [[0, 5], [1, 9]]], ['1', '2']);
$ok($v[1][0] === 2 && $v[1][3] === 9, 'balde seguinte abre no fechamento anterior', json_encode($v[1]));
$ok($v[1][3] > $v[1][0], 'alta vira vela verde');
$ok($v[1][1] === 9 && $v[1][2] === 2, 'maxima e minima cobrem abertura e fatias', json_encode($v[1]));

// Retomada depois de um vale.
$v = velas_de(['1' => [[0, 4]], '2' => [], '3' => [[0, 6]]], ['1', '2', '3']);
$ok($v[2] === [0, 6, 0, 6], 'volta do zero abre em 0 e fecha em 6', json_encode($v[2]));

// Primeiro balde nao tem anterior: abre na propria primeira fatia.
$v = velas_de(['1' => [[0, 7], [1, 5]]], ['1']);
$ok($v[0] === [7, 7, 5, 5], 'primeiro balde abre na primeira fatia', json_encode($v[0]));

// Coerencia geral: minima <= abertura/fechamento <= maxima, sempre.
$v = velas_de(['1' => [[0, 3], [1, 8], [2, 2]], '2' => [[0, 5]], '3' => []], ['1', '2', '3']);
$bom = true;
foreach ($v as $x) {
    if (!($x[2] <= $x[0] && $x[0] <= $x[1] && $x[2] <= $x[3] && $x[3] <= $x[1])) { $bom = false; }
}
$ok($bom, 'minima <= abertura e fechamento <= maxima em todas', json_encode($v));

echo $falhas ? "\n$falhas falha(s)\n" : "\ntudo certo\n";
exit($falhas ? 1 : 0);
