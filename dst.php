<?php
// Endpoint PÚBLICO: informa ao login.html o site de destino pós-anúncio do roteador.
// Carregado via <script src="https://captivedata.com.br/dst.php?r=$(identity)">, que
// funciona no navegador restrito do captive portal (inclusive iPhone/CNA), onde
// fetch/XHR são bloqueados mas carregar recursos do domínio liberado é permitido.
// Domínio já está no Walled Garden (mesmo do /api/lead).
ini_set('display_errors', '0');

require_once __DIR__ . '/inc/util.php';

$roteador = isset($_GET['r']) ? (string) $_GET['r'] : '';
$url = ($roteador !== '') ? dst_atual($roteador) : null;
if ($url === null || $url === '') {
    $url = DST_PADRAO;
}

header('Content-Type: application/javascript; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');

// json_encode gera um literal JS seguro (escapa aspas/barras), evitando injeção.
echo 'window.PORTAL_DST=' . json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';';
