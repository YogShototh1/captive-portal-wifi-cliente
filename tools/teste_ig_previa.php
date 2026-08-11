<?php
// Trava da prévia ao vivo da página de Instagram.
//
// O bug que isto pega: logo, botão "copiar @" e rodapé só iam para o HTML se
// já estivessem LIGADOS quando a página era montada. Na prévia do painel isso
// significava que marcar a opção não fazia nada — não havia elemento para
// mostrar — e o comprador só via o efeito depois de salvar. Agora, na prévia,
// os três estão sempre no HTML e quem os liga/desliga é o CSS (cd-no-*).
//
// A outra metade importa igual: a página REAL, a que o cliente recebe, não
// pode ganhar o que está desligado.
//
// Renderiza o ig.php de verdade, sem banco: o require do util sai e as três
// funções que ele usaria entram como dublê aqui.
// Rodar:  php tools/teste_ig_previa.php
// Teste de linha de comando, nunca pela web. O tools/.htaccess ja bloqueia a
// pasta; esta guarda existe para o bloqueio nao depender de o servidor honrar
// aquele arquivo. Vale a pena: estes testes rodam sem autenticacao nenhuma, e
// alguns gravam e apagam arquivos em ads/.
if (PHP_SAPI !== "cli") { http_response_code(404); exit; }

$raiz = dirname(__DIR__);

$falhas = 0;
$ok = function ($cond, $nome, $viu = '') use (&$falhas) {
    if ($cond) { echo "  ok   $nome\n"; }
    else { $falhas++; echo "  FALHOU  $nome  $viu\n"; }
};

$CFG = [
    'perfil' => 'primix_variedades',
    'titulo' => 'Siga a gente no Instagram',
    'sub'    => 'Wi-Fi liberado!',
    'chamada'=> 'Novidades e promocoes.',
    'botao'  => 'Abrir no Instagram',
    'rodape' => '',                       // vazio: o caso que sumia
    'cores'  => ['bg'=>'#fff','card'=>'#fff','fg'=>'#000','fg2'=>'#666',
                 'btn'=>'#c13584','btn2'=>'#833ab4','btnfg'=>'#fff','border'=>'#eee'],
    // logo e copiar DESLIGADOS: e assim que o elemento sumia do HTML
    'estilo' => ['logo'=>0,'cartao'=>1,'sombra'=>1,'manchas'=>1,'grad'=>1,'redondo'=>1,'copiar'=>0],
];
function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function ig_get_hash($h) { global $CFG; return $CFG; }
function ig_logo_hash($h) { return 'logo.php'; }   // o comprador TEM logo enviada
function ig_padrao() { global $CFG; return $CFG; }

// O ig.php sem o require do util (as funcoes acima fazem o papel dele).
$src = str_replace("require_once __DIR__ . '/inc/util.php';", '',
                   (string) file_get_contents($raiz . '/ig.php'));

function render(string $src, array $get): string
{
    $_GET = $get;
    ob_start();
    eval('?>' . $src);
    return (string) ob_get_clean();
}

$hash   = str_repeat('a', 40);
$real   = render($src, ['r' => $hash]);
$previa = render($src, ['previa' => '1', 'r' => $hash]);

// ---------------------------------------------------------------
echo "previa: o elemento existe para poder ser ligado ao vivo\n";
foreach (['id="cd-logo"' => 'logo', 'id="copiar"' => 'botao copiar @', 'id="cd-rodape"' => 'rodape'] as $alvo => $nome) {
    $ok(strpos($previa, $alvo) !== false, "$nome esta no HTML da previa");
}
// E o CSS tem que saber escondê-los, senão apareceriam ligados de saída.
$ok(strpos($previa, 'html.cd-no-logo #cd-logo{display:none}') !== false, 'CSS esconde a logo desligada');
$ok(strpos($previa, 'html.cd-no-copiar #copiar') !== false, 'CSS esconde o copiar desligado');
$ok(strpos($previa, 'id="cd-rodape" style="display:none"') !== false, 'rodape vazio nasce escondido');

// ---------------------------------------------------------------
echo "\npagina real: o que esta desligado NAO vai para o cliente\n";
foreach (['id="cd-logo"' => 'logo', 'id="copiar"' => 'botao copiar @', 'id="cd-rodape"' => 'rodape'] as $alvo => $nome) {
    $ok(strpos($real, $alvo) === false, "$nome fora do HTML do cliente");
}
// O script da previa tambem nao pode vazar: e ele que escuta postMessage.
$ok(strpos($real, "tipo: 'ig-pronto'") === false, 'a pagina do cliente nao escuta o painel');
$ok(strpos($previa, "tipo: 'ig-pronto'") !== false, 'a previa escuta o painel');

// ---------------------------------------------------------------
echo "\nligado continua indo para o cliente\n";
$CFG['estilo']['logo'] = 1;
$CFG['estilo']['copiar'] = 1;
$CFG['rodape'] = 'MINHA LOJA';
$real2 = render($src, ['r' => $hash]);
foreach (['id="cd-logo"' => 'logo', 'id="copiar"' => 'botao copiar @', 'id="cd-rodape"' => 'rodape'] as $alvo => $nome) {
    $ok(strpos($real2, $alvo) !== false, "$nome ligado chega ao cliente");
}
$ok(strpos($real2, 'MINHA LOJA') !== false, 'o texto do rodape chega ao cliente');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
