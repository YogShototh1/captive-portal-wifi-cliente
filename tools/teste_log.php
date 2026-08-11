<?php
// Trava do alinhamento do Log de acessos.
//
// O bug que isto pega: o cabecalho e a linha do log tinham 6 celulas, mas a
// grade que valia era a ".pc-conex-list li" (0,1,1) do pop-up de conexoes, que
// declara 4 colunas e ganhava da ".pc-log-row" (0,1,0). Resultado na tela: 6
// titulos em cima e cada linha quebrada em duas, com os valores caindo debaixo
// do titulo errado (o IP embaixo de "Cliente", o aparelho embaixo de "IP").
//
// Nao da para renderizar CSS aqui, entao o que se confere e o que importa:
//   1) cabecalho e linha tem a MESMA quantidade de celulas;
//   2) a grade do CSS declara essa mesma quantidade de colunas;
//   3) a lista do log nao voltou a usar a classe do pop-up.
//
// Rodar:  php tools/teste_log.php
$raiz = dirname(__DIR__);
$js  = (string) @file_get_contents($raiz . '/assets/acessolog.js');
$css = (string) @file_get_contents($raiz . '/assets/style.css');

$falhas = 0;
$ok = function ($cond, $nome, $viu = '') use (&$falhas) {
    if ($cond) { echo "  ok   $nome\n"; }
    else { $falhas++; echo "  FALHOU  $nome  $viu\n"; }
};

// 1) celulas do cabecalho
preg_match('/class="pc-log-head">(.*?)<\/div>/s', $js, $mh);
$titulos = $mh ? preg_match_all('/<span>/', $mh[1]) : 0;
$ok($titulos === 6, 'cabecalho com 6 titulos', $titulos);

// 2) celulas da linha: do <li class="pc-log-row"> ate o </li>
preg_match('/<li class="pc-log-row">(.*?)<\/li>/s', $js, $mr);
// So os <span> de primeiro nivel contam como celula da grade. O da coluna
// Destino tem um <span> aninhado dentro, que NAO e celula.
$celulas = 0;
if ($mr) {
    $prof = 0;
    foreach (preg_split('/(<span[ >]|<\/span>)/', $mr[1], -1, PREG_SPLIT_DELIM_CAPTURE) as $p) {
        if (strpos($p, '</span>') === 0) { $prof--; }
        elseif (strpos($p, '<span') === 0) { if ($prof === 0) { $celulas++; } $prof++; }
    }
}
$ok($celulas === 6, 'linha com 6 celulas de primeiro nivel', $celulas);
$ok($titulos === $celulas, 'cabecalho e linha com a mesma contagem', "$titulos vs $celulas");

// 3) a grade do CSS tem que declarar essa mesma quantidade
preg_match('/\.pc-log-head,\s*\.pc-log-row\s*\{[^}]*grid-template-columns:\s*([^;]+);/', $css, $mg);
$cols = $mg ? count(preg_split('/\s+/', trim($mg[1]))) : 0;
$ok($cols === $celulas, "CSS declara $celulas colunas", $mg ? trim($mg[1]) : 'regra nao encontrada');

// 4) a lista do log tem que ser a dela
$ok(strpos($js, 'pc-conex-list') === false,
    'o log nao usa mais a lista do pop-up de conexoes');
$ok(strpos($css, '.pc-log-list {') !== false, 'a .pc-log-list existe no CSS');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
