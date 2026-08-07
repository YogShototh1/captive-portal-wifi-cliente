<?php
// Repõe uma logo ou anúncio do histórico como o que está no ar.
//
// Isolamento igual ao upload: comprador comum mexe só no próprio roteador;
// admin, no do cliente da tela aberta (cliente_id), resolvido no servidor.
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';

$c = exigir_login();

if ((int) $c['is_admin'] === 1) {
    $cid      = (int) ($_POST['cliente_id'] ?? 0);
    $roteador = (string) roteador_escolhido(roteadores_conta($cid), $_POST['roteador'] ?? null);
    $voltar   = '../admin_leads.php?id=' . $cid . ($roteador !== '' ? '&r=' . rawurlencode($roteador) : '');
} else {
    $roteador = (string) roteador_escolhido(roteadores_conta((int) $c['id']), $_POST['roteador'] ?? null);
    $voltar   = '../painel.php' . ($roteador !== '' ? '?r=' . rawurlencode($roteador) : '');
}

$tipo  = (string) ($_POST['tipo'] ?? '');
$chave = $tipo === 'logo' ? 'logo' : 'anuncio';

function voltar_msg(string $to, string $key, string $msg): void
{
    $sep = (strpos($to, '?') === false) ? '?' : '&';
    header('Location: ' . $to . $sep . $key . '=' . rawurlencode($msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    voltar_msg($voltar, $chave . '_erro', 'Requisição inválida.');
}
if (!csrf_valido($_POST['csrf'] ?? '')) {
    voltar_msg($voltar, $chave . '_erro', 'Sessão expirada. Recarregue e tente de novo.');
}
if ($roteador === '') {
    voltar_msg($voltar, $chave . '_erro', 'Este cliente não tem roteador vinculado.');
}
if (!in_array($tipo, midia_tipos(), true)) {
    voltar_msg($voltar, $chave . '_erro', 'Tipo inválido.');
}

$id = (string) ($_POST['id'] ?? '');
if (!preg_match('/^[0-9a-f]{1,16}$/', $id)) {
    voltar_msg($voltar, $chave . '_erro', 'Item inválido.');
}
// midia_hist_usar recusa id que não esteja no índice deste roteador.
if (!midia_hist_usar($roteador, $tipo, $id)) {
    voltar_msg($voltar, $chave . '_erro', 'Não foi possível aplicar esta imagem.');
}

voltar_msg($voltar, $chave . '_ok',
    $tipo === 'logo'
        ? 'Logo trocada! Já aparece na tela de login.'
        : 'Anúncio trocado! Já vale para as próximas conexões.');
