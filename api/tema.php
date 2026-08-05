<?php
// Entregue ao MikroTik (leadsync.rsc), não ao navegador.
// Personalização da tela de login (cores, efeitos, destino, logo e anúncio)
// para ficar NA FLASH do roteador.
//   sem &f    : versão curta — o roteador compara e só rebaixa quando muda.
//   &f=js     : tema.js  -> window.CORES / window.ESTILO / window.PORTAL_DST
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
$assinatura = substr(sha1(json_encode(
    [$cores, $estilo, $dst, $forma, carimbo($logo), carimbo($anun)]
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
    exit;
}

// Imagens: entregues como estão, com o content-type real do arquivo.
$alvo = $f === 'logo' ? $logo : ($f === 'ad' ? $anun : null);
if ($alvo === null || !is_file($alvo)) {
    http_response_code(404);
    exit('');
}
$tipo = @mime_content_type($alvo) ?: 'application/octet-stream';
header('Content-Type: ' . $tipo);
header('Content-Length: ' . (string) filesize($alvo));
readfile($alvo);
