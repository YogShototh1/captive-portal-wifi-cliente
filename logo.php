<?php
// Endpoint PÚBLICO: serve a logo da tela de login do roteador pedido.
// Chamado pelo login.html do captive portal (cliente ainda não autenticado):
//   <img src="https://captivedata.com.br/logo.php?r=$(identity)">
// Domínio já liberado no Walled Garden (igual ao ad.php). Sem logo -> 404, e o
// login.html cai no ícone Wi-Fi padrão (onerror).
ini_set('display_errors', '0');

require_once __DIR__ . '/inc/util.php';

$roteador = isset($_GET['r']) ? (string) $_GET['r'] : '';
$file = $roteador !== '' ? logo_atual($roteador) : null;

if (!$file) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'sem logo';
    exit;
}

$ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime  = ($ext === 'png') ? 'image/png' : 'image/jpeg';
$mtime = filemtime($file);
$etag  = '"' . md5($roteador . '|' . $mtime) . '"';

header('Content-Type: ' . $mime);
header('Access-Control-Allow-Origin: *');
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
