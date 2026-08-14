<?php
// Travas da tela de login do hotspot (mikrotik/portal/login.html).
//
// Tres defeitos ja prenderam o cliente no ciclo "numero -> anuncio -> numero".
// Os tres sao invisiveis lendo o codigo com pressa, e os tres voltariam com uma
// edicao inocente. Um teste por defeito:
//
// 1) USERNAME DO TRIAL. Tem que ser "T-$(mac-esc)" e nada mais — e a convencao
//    do RouterOS, nao escolha nossa. Em 05/08/2026 foi grudado "-<telefone>" no
//    fim (para o roteador guardar o numero quando a internet da loja caisse) e
//    o roteador passou a recusar todo login de quem digitava o numero.
//
// 2) DESTINO LOCAL. Houve um teste que perguntava ao roteador se existia uma
//    copia da pagina do Instagram na flash. Antes de autenticar, o hotspot
//    responde 200 a QUALQUER requisicao (devolve a propria tela de login),
//    entao o teste passava sempre e o destino virava um endereco do hotspot.
//
// 3) RECUSA INVISIVEL. O aparelho conhecido pula a tela de numero com uma capa
//    por cima e o .main escondido — e o $(error) do hotspot mora dentro desse
//    .main. Recusado o login, o cliente girava no anuncio sem ver o motivo.
//
// Rodar:  php tools/teste_portal_login.php
// Teste de linha de comando, nunca pela web. O tools/.htaccess ja bloqueia a
// pasta; esta guarda existe para o bloqueio nao depender de o servidor honrar
// aquele arquivo.
if (PHP_SAPI !== "cli") { http_response_code(404); exit; }

$raiz = dirname(__DIR__);
$html = (string) @file_get_contents($raiz . '/mikrotik/portal/login.html');

$falhas = 0;
$ok = function ($cond, $nome, $viu = '') use (&$falhas) {
    if ($cond) { echo "  ok   $nome\n"; }
    else { $falhas++; echo "  FALHOU  $nome  $viu\n"; }
};

// ---------------------------------------------------------------
echo "1) username do trial\n";
preg_match('/function runAuthentication\(\)\s*\{(.*?)\n        \}/s', $html, $m);
$corpo = $m[1] ?? '';
$ok($corpo !== '', 'runAuthentication() encontrada');
$ok(strpos($corpo, '&username=T-$(mac-esc)') !== false,
    'manda exatamente &username=T-$(mac-esc)');
// Qualquer coisa concatenada depois do T-$(mac-esc) e o bug de 05/08 voltando.
$ok(!preg_match('/T-\$\(mac-esc\)"\s*\+/', $corpo),
    'nada e concatenado no fim do username');
$ok(strpos($corpo, "getElementById('celular')") === false,
    'o telefone nao entra no username');
// O link de fabrica na mesma pagina tem que seguir a mesma convencao: e a
// referencia de que o formato esta certo.
$ok(substr_count($html, 'username=T-$(mac-esc)') >= 2,
    'o link de fabrica e o runAuthentication usam o mesmo formato',
    substr_count($html, 'username=T-$(mac-esc)'));

// ---------------------------------------------------------------
echo "\n2) destino sempre do painel\n";
$ok(strpos($html, 'dstLocalOk') === false, 'o teste do ig.html local nao voltou');
$ok(strpos($html, 'window.PORTAL_DST_LOCAL') === false, 'PORTAL_DST_LOCAL nao e mais lido');
$ok(!preg_match('/location\.origin\s*\+/', $html), 'o destino nao aponta para o proprio hotspot');
preg_match('/function portalDst\(\)\s*\{(.*?)\n        \}/s', $html, $mp);
$ok(strpos($mp[1] ?? '', 'window.PORTAL_DST') !== false, 'portalDst() devolve o destino do painel');

// ---------------------------------------------------------------
echo "\n3) recusa do roteador nunca fica invisivel\n";
$ok(strpos($html, '$(if error)<script>window.CD_ERRO = 1;</script>$(endif)') !== false,
    'o $(error) do hotspot marca CD_ERRO');
$ok(strpos($html, "(k && !window.CD_ERRO) ? ' cd-known' : ' cd-unknown'") !== false,
    'login recusado nunca pega o caminho rapido do aparelho conhecido');
// A marca tem que estar FORA do $(if chap-id): o RouterOS nao aninha dois $(if).
// O bloco de verdade e a diretiva sozinha na linha — nao a mencao dentro do
// comentario logo acima, que foi o que esta comparacao pegou na primeira versao.
$posErro = strpos($html, '$(if error)<script>');
$posChap = strpos($html, "\n    \$(if chap-id)\n");
$ok($posErro !== false && $posChap !== false && $posErro < $posChap,
    'a marca fica fora do $(if chap-id) — o RouterOS nao aninha $(if)');

// ---------------------------------------------------------------
// 4) O RouterOS troca $(...) por texto ANTES de existir HTML: ele nao sabe o
//    que e comentario. Um "$(if chap-id)" escrito dentro de <!-- --> abre um
//    condicional de verdade. Foi o que aconteceu: dois deles, num comentario
//    explicando os condicionais, deixavam 10 aberturas para 8 $(endif). Num
//    perfil sem http-chap (login-by=trial, o da pousada) o chap-id vem vazio,
//    o RouterOS entrava em modo "pular" e nunca achava o fecho que faltava:
//    a pagina inteira sumia depois daquela linha. Tela branca no celular.
echo "\n4) condicionais do RouterOS\n";
$ok(!preg_match('/<!--(?:(?!-->).)*?\$\(/s', $html),
    'nenhum $( dentro de comentario de HTML');

// Anda pelo texto como o RouterOS andaria e conta a profundidade.
$prof = 0; $minProf = 0;
if (preg_match_all('/\$\((if\b[^)]*|else|elif\b[^)]*|endif)\)/', $html, $mm)) {
    foreach ($mm[1] as $tok) {
        if (strncmp($tok, 'if', 2) === 0) { $prof++; }
        elseif ($tok === 'endif') { $prof--; }
        // else/elif nao mudam a profundidade.
        $minProf = min($minProf, $prof);
    }
}
$ok($prof === 0, 'todo $(if) tem o seu $(endif)', "sobrou profundidade $prof");
$ok($minProf === 0, 'nenhum $(endif) sem $(if) antes', "chegou a $minProf");

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
