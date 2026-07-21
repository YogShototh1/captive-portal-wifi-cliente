<?php
// Endpoint PÚBLICO: serve a imagem de anúncio do roteador pedido.
// Chamado pelo login.html do captive portal (cliente ainda não autenticado):
//   <img src="https://captivedata.com.br/ad.php?r=$(identity)">
// Liberar o domínio no Walled Garden do MikroTik (já feito para /api/lead).
ini_set('display_errors', '0');

require_once __DIR__ . '/inc/util.php';

$roteador = isset($_GET['r']) ? (string) $_GET['r'] : '';
$file = $roteador !== '' ? anuncio_atual($roteador) : null;

if (!$file) {
    // Sem anúncio para este roteador → 404 (o login.html cai no placeholder).
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'sem anuncio';
    exit;
}

$ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime  = ($ext === 'png') ? 'image/png' : 'image/jpeg';
$mtime = filemtime($file);
$etag  = '"' . md5($roteador . '|' . $mtime) . '"';

header('Content-Type: ' . $mime);
header('Access-Control-Allow-Origin: *');           // público; carregado pelo portal
// Sem cache "duro": a troca pelo painel precisa refletir rápido. Usa validação.
header('Cache-Control: no-cache, must-revalidate');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);

$ifNone = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
$ifMod  = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
if ($ifNone === $etag || ($ifMod !== '' && @strtotime($ifMod) >= $mtime)) {
    http_response_code(304);
    exit;
}

header('Content-Length: ' . filesize($file));
readfile($file);
