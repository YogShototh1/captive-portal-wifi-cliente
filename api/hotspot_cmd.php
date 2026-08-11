<?php
// O painel lê o estado do hotspot do roteador e manda ligar/desligar.
//   GET  -> estado atual (servidores, ligado, ordem pendente)
//   POST -> pede a ordem;  acao=ligar | desligar
//
// SÓ ADMIN. Desligar o hotspot abre o Wi-Fi da loja sem tela de login: para de
// entrar lead e o anúncio do comprador deixa de aparecer. É a alavanca de
// socorro de quem opera o sistema, não um botão de autoatendimento.
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$c = comprador_logado();
if (!$c) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'erro' => 'nao autenticado']));
}
if ((int) $c['is_admin'] !== 1) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'erro' => 'sem permissao']));
}

// Mesmo isolamento do resto: o roteador tem que pertencer à conta aberta. Admin
// não escapa disso — evita ordem para um roteador digitado na mão na URL.
$cid   = (int) ($_REQUEST['cliente_id'] ?? 0);
$lista = $cid > 0 ? roteadores_conta($cid) : roteadores_conta((int) $c['id']);

$roteador = trim((string) ($_REQUEST['roteador'] ?? ''));
if ($roteador === '' || !in_array($roteador, $lista, true)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'erro' => 'roteador invalido']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'erro' => 'sessao expirada']));
    }
    $acao = (string) ($_POST['acao'] ?? '');
    if ($acao !== 'ligar' && $acao !== 'desligar') {
        http_response_code(400);
        exit(json_encode(['ok' => false, 'erro' => 'acao invalida']));
    }
    // Roteador fora do ar não vem buscar a ordem, e ela expira em 10 min. Dizer
    // isso agora é melhor que deixar o admin achando que mandou.
    if (!mikrotik_online($roteador)) {
        exit(json_encode(['ok' => false, 'erro' => 'O roteador está fora do ar. A ordem só vale quando ele voltar.']));
    }
    hotspot_ordem_pedir($roteador, $acao === 'ligar');
}

$e = hotspot_estado_get($roteador);
echo json_encode([
    'ok'         => true,
    'online'     => mikrotik_online($roteador),
    'conhecido'  => $e !== null,
    'ligado'     => $e['ligado'] ?? null,
    'servidores' => $e['servidores'] ?? [],
    'perfis'     => $e['perfis'] ?? [],
    'idade'      => $e['idade'] ?? null,
    'pendente'   => hotspot_ordem_pendente($roteador),   // true=ligar, false=desligar, null=nada
]);
