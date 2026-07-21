<?php
// Entregue ao MikroTik (leadsync.rsc), não ao navegador.
//   sem &f : manifesto "<versao>|arq1,arq2,..." — o roteador compara a versao e só
//            rebaixa (grava na flash) quando muda; poupa a flash.
//   com &f : conteúdo do arquivo pedido (texto puro, para dst-path=flash/hostsv7/...).
// Auth: token = admin_token do config.php (igual ao status.php).
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

// Heartbeat: esta chamada autenticada prova que o MikroTik está online agora.
mikrotik_tocar($roteador);

// Sempre texto puro para download: nunca renderiza como HTML no nosso domínio
// (anti-XSS) e o roteador só quer os bytes.
header('X-Content-Type-Options: nosniff');
header('Content-Type: text/plain; charset=utf-8');

$f = trim((string) ($_REQUEST['f'] ?? ''));

if ($f === '') {
    echo portal_versao($roteador) . '|' . implode(',', portal_files($roteador));
    exit;
}

// Arquivo específico (caminho lógico, ex.: css/style.css). Valida e converte para o
// nome plano do disco (css~style.css), já que guardamos sem subpastas.
if (!portal_path_ok($f)) {
    http_response_code(400);
    exit('');
}
$path = portal_dir($roteador) . '/' . portal_encode($f);
if (!is_file($path)) {
    http_response_code(404);
    exit('');
}
header('Content-Disposition: attachment; filename="' . basename($f) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
