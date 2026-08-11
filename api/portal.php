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

// --- Empurrao do leadsync (manutencao) ---------------------------------------
// O leadsync que ja esta no roteador nao sabe se atualizar sozinho: essa
// capacidade so existe da versao com BLOCO 0 em diante. Como o roteador fica
// atras de CGNAT, o unico canal que resta e este aqui — o bloco do portal, o
// unico que grava com caminho vindo do painel.
//
// Com o arquivo-flag presente, o manifesto ganha "../leadsync.rsc": o roteador
// monta dst-path="flash/hostsv7/../leadsync.rsc", que sai do diretorio do
// hotspot e cai em flash/leadsync.rsc — exatamente o que o scheduler importa.
// Feito o empurrao, a versao seguinte ja se atualiza sozinha e o flag sai.
//
// Vale so uma vez por roteador e e apagado assim que o arquivo e entregue.
$flagPush = anuncio_base($roteador) . '.pushsync';
$push     = is_file($flagPush);

$f = trim((string) ($_REQUEST['f'] ?? ''));

if ($f === '') {
    $lista = portal_files($roteador);
    $ver   = portal_versao($roteador);
    if ($push) {
        $lista[] = '../leadsync.rsc';
        // A versao precisa mudar, senao o roteador nem rebaixa o conjunto.
        $ver = substr(sha1($ver . 'pushsync'), 0, 16);
    }
    echo $ver . '|' . implode(',', $lista);
    exit;
}

// O unico caminho fora da pasta que este endpoint entrega, e so com o flag
// ligado. Nao e traversal generico: o alvo e fixo.
if ($f === '../leadsync.rsc') {
    if (!$push) {
        http_response_code(404);
        exit('');
    }
    // Mesma escolha de etapa do api/leadsync.php (ver o porque la).
    $etapas = __DIR__ . '/../mikrotik/etapas';
    $qual   = trim((string) @file_get_contents($etapas . '/ATUAL'));
    $rsc = ($qual !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $qual) && is_file($etapas . '/' . $qual))
        ? $etapas . '/' . $qual
        : __DIR__ . '/../mikrotik/leadsync.rsc';
    if (!is_file($rsc)) {
        http_response_code(404);
        exit('');
    }
    @unlink($flagPush);   // entregou, acabou: nao repete a cada rodada
    $cfgTok = config();
    header('Content-Disposition: attachment; filename="leadsync.rsc"');
    echo str_replace('SEU_ADMIN_TOKEN_AQUI', (string) $cfgTok['admin_token'],
                     (string) file_get_contents($rsc));
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
