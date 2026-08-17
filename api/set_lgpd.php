<?php
// Salva os dois documentos de LGPD do portal (Termos de Uso e Política de
// Privacidade), por roteador.
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

$termos = (string) ($_POST['lgpd_termos'] ?? '');
$priv   = (string) ($_POST['lgpd_privacidade'] ?? '');

// Teto generoso, mas teto: os dois textos vão para a flash do roteador dentro
// do tema.js, e a flash do hEX tem 16 MB no total.
if (mb_strlen($termos) > LGPD_MAX || mb_strlen($priv) > LGPD_MAX) {
    voltar_msg($voltar, 'lgpd_erro', 'Documento muito longo (máx. ' . LGPD_MAX . ' caracteres em cada um).');
}

if (!lgpd_set($roteador, $termos, $priv)) {
    voltar_msg($voltar, 'lgpd_erro', 'Não foi possível salvar os documentos.');
}
voltar_msg($voltar, 'lgpd_ok', 'Documentos salvos. O roteador aplica em até 1 minuto.');
