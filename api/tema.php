<?php
// Entregue ao MikroTik (leadsync.rsc), não ao navegador.
// Personalização da tela de login (cores, efeitos, destino, logo e anúncio)
// para ficar NA FLASH do roteador.
//   sem &f    : versão curta — o roteador compara e só rebaixa quando muda.
//   &f=js     : tema.js  -> window.CORES / window.ESTILO / window.PORTAL_DST
//                           / window.PORTAL_MODO
//   &f=logo   : a imagem da logo do comprador (ou 404 se não tiver)
//   &f=ad     : a imagem do anúncio do comprador (ou 404 se não tiver)
//
// Por que na flash: antes o login.html buscava tudo isso do painel A CADA
// conexão de cliente (dst.php, logo.php, ad.php). Com a internet do
// estabelecimento lenta ou fora, nada disso chegava — a tela abria sem o tema,
// sem logo e sem anúncio, quando abria. Na flash, o roteador aplica sozinho o
// que o comprador escolheu, sem depender da internet naquele momento.
//
// A versão cobre TUDO (cores, efeitos, destino, forma, e o arquivo da logo e do
// anúncio): trocou qualquer um no painel, o roteador rebaixa os três na rodada
// seguinte. Auth: token = admin_token do config.php (igual portal.php/macs.php).
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/util.php';

$cfg   = config();
$token = (string) ($_REQUEST['token'] ?? '');
if (!hash_equals((string) $cfg['admin_token'], $token)) {
    http_response_code(403);
    exit('');
}

$roteador = trim((string) ($_REQUEST['roteador'] ?? ''));
if ($roteador === '') {
    http_response_code(400);
    exit('');
}

mikrotik_tocar($roteador);
header('X-Content-Type-Options: nosniff');

$cores  = cores_get($roteador);
$estilo = estilo_get($roteador);
$dst    = dst_atual($roteador) ?? DST_PADRAO;
$forma  = logo_forma($roteador);
$logo   = logo_atual($roteador);        // caminho no disco, ou null
$anun   = anuncio_atual($roteador);     // idem

// mtime+tamanho entram na conta: trocar a imagem sem mudar o nome também muda
// a versão, senão o roteador ficaria com a logo antiga para sempre.
function carimbo(?string $f): string
{
    return $f && is_file($f) ? (string) @filemtime($f) . ':' . (string) @filesize($f) : '-';
}
// A config da página de Instagram entra na conta: trocou a cor ou o texto dela
// no painel, o roteador rebaixa o ig.html na rodada seguinte.
$ig = ig_get($roteador);
// O MODO (varejo/hospedagem) vem junto porque o login.html só consulta o
// dst.php quando NÃO existe tema.js na flash — e existindo, o portal ficava sem
// saber o modo e tratava todo mundo como varejo: a pousada liberava qualquer
// número. Na assinatura também, senão trocar o tipo do cliente no painel não
// chegaria ao roteador.
$modo = roteador_modo($roteador);
$assinatura = substr(sha1(json_encode(
    [$cores, $estilo, $dst, $forma, carimbo($logo), carimbo($anun), $ig, $modo]
)), 0, 12);

$f = (string) ($_REQUEST['f'] ?? '');

if ($f === '') {
    header('Content-Type: text/plain; charset=utf-8');
    exit($assinatura);
}

if ($f === 'js') {
    header('Content-Type: application/javascript; charset=utf-8');
    // Mesmos nomes que o dst.php já usava: o login.html não precisa saber se o
    // tema veio da flash ou do painel.
    echo "/* tema do painel — api/tema.php, versao $assinatura */\n";
    echo 'window.PORTAL_DST=' . json_encode($dst, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ";\n";
    echo 'window.LOGO_FORMA=' . json_encode($forma) . ";\n";
    echo 'window.CORES=' . json_encode($cores) . ";\n";
    echo 'window.ESTILO=' . json_encode($estilo) . ";\n";
    echo 'window.PORTAL_MODO=' . json_encode($modo) . ";\n";
    // Havia aqui um window.PORTAL_DST_LOCAL, que mandava o login.html usar uma
    // cópia da página do Instagram guardada na flash. Removido: causava loop
    // infinito no portal (o porquê está no comentário do portalDst(), em
    // mikrotik/portal/login.html). O destino agora é sempre o PORTAL_DST.
    exit;
}

// A página-ponte para viver na flash. Só existe se o comprador montou a dele e
// ela é o destino em uso — senão o roteador não tem o que guardar.

// Imagens: entregues REDUZIDAS, não como o comprador enviou.
//
// O que vai para cá é gravado na flash do roteador, e o hEX Gr3 tem 16 MB no
// total — um anúncio de 2,5 MB (foto de celular, tamanho normal) come 15% do
// disco do equipamento sozinho. Como a imagem aparece na tela de um telefone,
// 1080px de largura já é mais do que se enxerga; a logo, menor ainda.
// A conversão é feita uma vez e guardada (ver imagem_flash em inc/util.php).
//
// Isso NÃO muda o que o painel mostra: quem serve a prévia e a tela do
// comprador é o ad.php/logo.php, que continuam entregando o original.
$alvo = null;
if ($f === 'logo' && $logo !== null) {
    // A logo aparece com no máximo 220px de largura (style.css do portal). Num
    // iPhone são 3 pixels físicos por pixel CSS, logo 660px — 700 dá a folga.
    // Mantém PNG quando a origem é PNG: a logo pode ter fundo vazado.
    $alvo = imagem_flash($logo, 700, true, 90, 150);
} elseif ($f === 'ad' && $anun !== null) {
    // O anúncio ocupa a tela inteira. Num iPhone Pro Max isso dá 1290px de
    // largura, então 1600 no maior lado cobre qualquer celular — na prática
    // quase nenhum anúncio é reduzido, e a economia vem toda de sair do PNG.
    // Alpha aqui não serve para nada: a imagem cobre a tela.
    $alvo = imagem_flash($anun, 1600, false, 90, 700);
}
if ($alvo === null || !is_file($alvo)) {
    http_response_code(404);
    exit('');
}
$tipo = @mime_content_type($alvo) ?: 'application/octet-stream';
header('Content-Type: ' . $tipo);
header('Content-Length: ' . (string) filesize($alvo));
readfile($alvo);
