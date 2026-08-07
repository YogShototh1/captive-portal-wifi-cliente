<?php
// Serve uma imagem do histórico de logos/anúncios, para a miniatura do painel.
//
// Autenticado por sessão, com o mesmo isolamento do resto: o roteador do
// pedido é VALIDADO contra a lista da conta (ou do cliente aberto, no admin).
// Sem isso, qualquer comprador logado veria a logo de qualquer outro só
// trocando o identity na URL.
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';

$c = comprador_logado();
if (!$c) {
    http_response_code(401);
    exit('');
}

if ((int) $c['is_admin'] === 1) {
    $cid   = (int) ($_GET['cliente_id'] ?? 0);
    $lista = $cid > 0 ? roteadores_conta($cid) : [];
} else {
    $lista = roteadores_conta((int) $c['id']);
}
$roteador = (string) ($_GET['roteador'] ?? '');
if ($roteador === '' || !in_array($roteador, $lista, true)) {
    http_response_code(403);
    exit('');
}

$tipo = (string) ($_GET['tipo'] ?? '');
$id   = (string) ($_GET['id'] ?? '');
if (!in_array($tipo, midia_tipos(), true) || !preg_match('/^[0-9a-f]{1,16}$/', $id)) {
    http_response_code(400);
    exit('');
}

// O caminho sai do índice, nunca do que veio na URL.
$arq = midia_hist_arquivo($roteador, $tipo, $id);
if ($arq === null) {
    http_response_code(404);
    exit('');
}

// Miniatura: o painel mostra em ~120px, então não faz sentido mandar o arquivo
// cheio a cada linha da lista. Reaproveita o mesmo redutor da flash.
$mini = imagem_flash($arq, 360, strtolower(pathinfo($arq, PATHINFO_EXTENSION)) === 'png', 80, 120);

header('Content-Type: ' . (@mime_content_type($mini) ?: 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($mini));
header('Cache-Control: private, max-age=86400');   // o id é o hash do conteúdo
readfile($mini);
