<?php
// Salva os dois textos de LGPD da tela de login, por roteador.
// Mesmo isolamento do set_dst.php: o roteador do POST é validado contra a lista
// da conta (cliente) ou do cliente aberto (admin) — nunca um identity qualquer.
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

function voltar_msg(string $to, string $key, string $msg): void
{
    $sep = (strpos($to, '?') === false) ? '?' : '&';
    header('Location: ' . $to . $sep . $key . '=' . rawurlencode($msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    voltar_msg($voltar, 'lgpd_erro', 'Requisição inválida.');
}
if (!csrf_valido($_POST['csrf'] ?? '')) {
    voltar_msg($voltar, 'lgpd_erro', 'Sessão expirada. Recarregue e tente de novo.');
}
if ($roteador === '') {
    voltar_msg($voltar, 'lgpd_erro', 'Este cliente não tem roteador vinculado.');
}

$aviso = (string) ($_POST['lgpd_aviso'] ?? '');
$fins  = (string) ($_POST['lgpd_finalidades'] ?? '');

// Teto generoso, mas teto: o texto vai para a flash do roteador dentro do
// tema.js, e a flash do hEX tem 16 MB no total.
if (mb_strlen($aviso) > 900 || mb_strlen($fins) > 900) {
    voltar_msg($voltar, 'lgpd_erro', 'Texto muito longo (máx. 900 caracteres em cada campo).');
}

if (!lgpd_set($roteador, $aviso, $fins)) {
    voltar_msg($voltar, 'lgpd_erro', 'Não foi possível salvar os textos.');
}
voltar_msg($voltar, 'lgpd_ok', 'Textos salvos. O roteador aplica em até 1 minuto.');
