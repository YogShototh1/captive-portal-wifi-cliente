<?php
// Trava do template PADRAO da pagina de login.
//
// Roteador recem-configurado nao tem pagina nenhuma: portal_files() so devolve
// o que o COMPRADOR subiu pelo painel. O conjunto padrao (mikrotik/portal/) e o
// que faz o setup terminar com a tela no ar sem ninguem arrastar arquivo no
// Winbox — e e por ele que uma correcao no template chega a quem nunca
// personalizou a tela.
//
// O que nao pode mudar: os caminhos LOGICOS. O roteador grava cada um em
// hostsv7/<caminho>, e o login.html pede "css/style.css" e "fonts/*.woff2".
// Um caminho errado aqui = tela sem CSS e sem fonte no cliente.
//
// Rodar:  php tools/teste_portal_base.php
// Teste de linha de comando, nunca pela web. O tools/.htaccess ja bloqueia a
// pasta; esta guarda existe para o bloqueio nao depender de o servidor honrar
// aquele arquivo.
if (PHP_SAPI !== "cli") { http_response_code(404); exit; }

// So as funcoes puras: o resto do util.php fala com o banco.
$src = (string) file_get_contents(__DIR__ . '/../inc/util.php');
$ini = strpos($src, 'function portal_base_dir');
$fim = strpos($src, '// O conjunto que o ROTEADOR deve ter');
eval(substr($src, $ini, $fim - $ini));

$falhas = 0;
$ok = function ($cond, $nome, $viu = '') use (&$falhas) {
    if ($cond) { echo "  ok   $nome\n"; }
    else { $falhas++; echo "  FALHOU  $nome  $viu\n"; }
};

$lista = portal_base_files();

echo "o conjunto padrao\n";
$ok(in_array('login.html', $lista, true), 'tem login.html');
$ok(in_array('css/style.css', $lista, true),
    'o CSS vai para css/style.css (e o caminho que o login.html pede)');
$ok(count($lista) >= 9, 'login.html + css + as 7 fontes', count($lista) . ' arquivos');
$fontes = array_filter($lista, function ($f) { return strpos($f, 'fonts/') === 0; });
$ok(count($fontes) === 7, 'as 7 fontes woff2 entram', count($fontes) . ' fontes');
foreach ($fontes as $f) {
    $ok(substr($f, -6) === '.woff2', "fonte com extensao certa: $f");
}

echo "\ncada caminho logico aponta para um arquivo que existe\n";
foreach ($lista as $f) {
    $p = portal_base_path($f);
    $ok($p !== null && is_file($p), "$f -> arquivo no disco", (string) $p);
}

echo "\nnada fora do conjunto sai daqui\n";
// portal_base_path e usado com o `f=` da URL: e uma fronteira, nao um helper.
$ok(portal_base_path('../../inc/config.php') === null, 'traversal recusado');
$ok(portal_base_path('style.css') === null,
    'o nome em disco nao vale: so o caminho logico (css/style.css)');
$ok(portal_base_path('login.html/../../inc/db.php') === null, 'traversal disfarcado recusado');
$ok(portal_base_path('') === null, 'vazio recusado');
$ok(portal_base_path('macs.js') === null, 'arquivo que o leadsync gera nao vem daqui');

echo "\no login.html do template pede o que o conjunto entrega\n";
$html = (string) file_get_contents(portal_base_dir() . '/login.html');
$ok(strpos($html, 'href="css/style.css"') !== false, 'o HTML pede css/style.css');
$css = (string) file_get_contents(portal_base_dir() . '/style.css');
preg_match_all("/url\('fonts\/([^']+)'\)/", $css, $m);
$pedidas = array_unique($m[1]);
$ok(count($pedidas) > 0, 'o CSS pede fontes', count($pedidas) . '');
foreach ($pedidas as $nome) {
    $ok(in_array('fonts/' . $nome, $lista, true), "fonte pedida pelo CSS esta no conjunto: $nome");
}

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
