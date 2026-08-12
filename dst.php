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
// Forma da logo (quadrado/arredondado/redondo) — o login.html aplica a classe.
echo 'window.LOGO_FORMA=' . json_encode($roteador !== '' ? logo_forma($roteador) : 'quadrado') . ';';
// Cores da tela de login (por roteador) — o login.html aplica nas CSS vars.
echo 'window.CORES=' . json_encode($roteador !== '' ? cores_get($roteador) : cores_padrao()) . ';';
// Efeitos ligados/desligados no painel — o login.html vira classes no <html>.
echo 'window.ESTILO=' . json_encode($roteador !== '' ? estilo_get($roteador) : estilo_padrao()) . ';';
// Modo do roteador: 'hospedagem' faz o portal VALIDAR o número contra a lista de
// hóspedes em vez de simplesmente capturá-lo. Vai junto porque o login.html já
// espera este script — não custa uma requisição a mais no portal.
echo 'window.PORTAL_MODO=' . json_encode($roteador !== '' ? roteador_modo($roteador) : 'varejo') . ';';
