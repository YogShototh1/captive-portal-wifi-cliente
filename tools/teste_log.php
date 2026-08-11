<?php
// Trava do alinhamento das duas tabelas de log de acessos:
//   - a aba "Log de acessos"      (assets/acessolog.js,  6 colunas)
//   - o pop-up do botao "!"       (assets/leads-live.js, 5 colunas — nao ha
//                                  coluna Cliente, o pop-up ja e de um so)
//
// O bug que isto pega: as duas montavam as linhas dentro da <ul class=
// "pc-conex-list"> do pop-up de conexoes, e ".pc-conex-list li" (0,1,1) ganha
// de ".pc-log-row" / ".pc-acesso-row" (0,1,0). Valia a grade de 4 colunas do
// pop-up: o cabecalho mostrava 5 ou 6 titulos e cada linha quebrava em duas,
// com os valores caindo debaixo do titulo errado (o IP embaixo de "Cliente",
// o aparelho embaixo da data).
//
// Nao da para renderizar CSS aqui, entao se confere o que importa:
//   1) cabecalho e linha tem a MESMA quantidade de celulas;
//   2) a grade que vale no CSS declara essa mesma quantidade;
//   3) nenhuma das duas voltou a usar a lista do pop-up de conexoes.
//
// Rodar:  php tools/teste_log.php
// Teste de linha de comando, nunca pela web. O tools/.htaccess ja bloqueia a
// pasta; esta guarda existe para o bloqueio nao depender de o servidor honrar
// aquele arquivo. Vale a pena: estes testes rodam sem autenticacao nenhuma, e
// alguns gravam e apagam arquivos em ads/.
if (PHP_SAPI !== "cli") { http_response_code(404); exit; }

$raiz = dirname(__DIR__);
$css  = (string) @file_get_contents($raiz . '/assets/style.css');

$falhas = 0;
$ok = function ($cond, $nome, $viu = '') use (&$falhas) {
    if ($cond) { echo "  ok   $nome\n"; }
    else { $falhas++; echo "  FALHOU  $nome  $viu\n"; }
};

// So os <span> de PRIMEIRO nivel sao celulas da grade: o da coluna Destino tem
// um <span> aninhado dentro (o texto + o botao copiar), que nao conta.
$celulas = function (string $html): int {
    $n = 0; $prof = 0;
    foreach (preg_split('/(<span[ >]|<\/span>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE) as $p) {
        if (strpos($p, '</span>') === 0) { $prof--; }
        elseif (strpos($p, '<span') === 0) { if ($prof === 0) { $n++; } $prof++; }
    }
    return $n;
};

// [nome, arquivo js, regex da linha, regex da grade no css, colunas esperadas]
$tabelas = [
    ['aba Log de acessos', 'assets/acessolog.js',
     '/<li class="pc-log-row">(.*?)<\/li>/s',
     '/\.pc-log-head,\s*\.pc-log-row\s*\{[^}]*grid-template-columns:\s*([^;]+);/', 6],
    ['pop-up do botao "!"', 'assets/leads-live.js',
     '/<li class="pc-log-row pc-acesso-row">(.*?)<\/li>/s',
     '/#acessos-modal \.pc-log-head,\s*#acessos-modal \.pc-log-row\s*\{[^}]*grid-template-columns:\s*([^;]+);/', 5],
];

foreach ($tabelas as [$nome, $arq, $reLinha, $reGrade, $esperado]) {
    echo "$nome\n";
    $js = (string) @file_get_contents($raiz . '/' . $arq);

    preg_match('/class="pc-log-head">(.*?)<\/div>/s', $js, $mh);
    $titulos = $mh ? $celulas($mh[1]) : 0;
    $ok($titulos === $esperado, "cabecalho com $esperado titulos", $titulos);

    preg_match($reLinha, $js, $mr);
    $cels = $mr ? $celulas($mr[1]) : 0;
    $ok($cels === $esperado, "linha com $esperado celulas de primeiro nivel", $cels);
    $ok($titulos === $cels, 'cabecalho e linha com a mesma contagem', "$titulos vs $cels");

    preg_match($reGrade, $css, $mg);
    $cols = $mg ? count(preg_split('/\s+/', trim($mg[1]))) : 0;
    $ok($cols === $esperado, "CSS declara $esperado colunas", $mg ? trim($mg[1]) : 'regra nao encontrada');

    // A <ul> logo depois do cabecalho tem que ser a .pc-log-list. Nao vale
    // procurar "pc-conex-list" no arquivo inteiro: o leads-live.js tambem monta
    // o pop-up de CONEXOES, que usa aquela lista com razao.
    $ok(preg_match('/class="pc-log-head">.*?<\/div><ul class="pc-log-list">/s', $js) === 1,
        'a lista do log e a .pc-log-list, nao a do pop-up de conexoes');
    echo "\n";
}

$ok(strpos($css, '.pc-log-list {') !== false, 'a .pc-log-list existe no CSS');
// A grade e o display vem da .pc-log-row; sem isso o #acessos-modal so trocaria
// a contagem de colunas de uma grade que nao existe.
$ok(preg_match('/\.pc-log-head,\s*\.pc-log-row\s*\{[^}]*display:\s*grid/', $css) === 1,
    'a .pc-log-row e que declara display:grid');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
