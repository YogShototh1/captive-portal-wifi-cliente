<?php
// Entregue ao roteador (leadsync.rsc), não ao navegador.
//
// Uma chamada só faz as duas pontas do liga/desliga do hotspot:
//   - o roteador MANDA o estado atual dos servidores em `hs` ("<nome>:<0|1>,…")
//   - e RECEBE de volta a ordem em aberto: "on", "off" ou "-" (nada a fazer)
//
// Duas pontas na mesma chamada porque o roteador já paga o custo de falar com o
// painel a cada rodada; um endpoint só para perguntar "tem ordem?" seria uma
// segunda viagem para 3 bytes.
//
// Auth: token = admin_token do config.php (igual ao status.php/tema.php).
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
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

// String vazia = roteador sem NENHUM servidor de hotspot. É um estado real e
// precisa ser gravado como tal, senão a tela mostra para sempre o último estado
// de quando ainda havia um.
if (isset($_REQUEST['hs'])) {
    hotspot_estado_set($roteador, (string) $_REQUEST['hs']);
}

$ordem = hotspot_ordem_ler($roteador);
exit($ordem === null ? '-' : ($ordem ? 'on' : 'off'));
