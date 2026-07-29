<?php
// Salva as cores da tela de login (painel do comprador OU admin), por roteador.
// Mesmo isolamento do upload_logo.php.
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';

$c = exigir_login();

if ((int) $c['is_admin'] === 1) {
    $cid      = (int) ($_POST['cliente_id'] ?? 0);
    $roteador = (string) roteador_escolhido(roteadores_conta($cid), $_POST['roteador'] ?? null);
    $voltar   = '../admin_leads.php?id=' . $cid . ($roteador !== '' ? '&r=' . rawurlencode($roteador) : '') . '#anuncio';
} else {
    $roteador = (string) roteador_escolhido(roteadores_conta((int) $c['id']), $_POST['roteador'] ?? null);
    $voltar   = '../painel.php' . ($roteador !== '' ? '?r=' . rawurlencode($roteador) : '') . '#anuncio';
}

function voltar_msg(string $to, string $key, string $msg): void
{
    $hash = '';
    if (($h = strpos($to, '#')) !== false) { $hash = substr($to, $h); $to = substr($to, 0, $h); }
    $sep = (strpos($to, '?') === false) ? '?' : '&';
    header('Location: ' . $to . $sep . $key . '=' . rawurlencode($msg) . $hash);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido($_POST['csrf'] ?? '')) {
    voltar_msg($voltar, 'cores_erro', 'Sessão expirada. Recarregue e tente de novo.');
}
if ($roteador === '') {
    voltar_msg($voltar, 'cores_erro', 'Este cliente não tem roteador vinculado.');
}

// Só as chaves conhecidas; cores_set valida cada #hex e cai no padrão se inválida.
$cores = [];
foreach (array_keys(cores_padrao()) as $k) {
    if (isset($_POST[$k])) {
        $cores[$k] = (string) $_POST[$k];
    }
}
cores_set($roteador, $cores);

// So o form AVANCADO manda flags (sentinela). Sem ele, nao mexe nos efeitos —
// checkbox desmarcado nao e enviado, entao sem sentinela nao da p/ distinguir
// "desmarcou tudo" de "form simples".
if (!empty($_POST['tem_flags'])) {
    estilo_set($roteador, $_POST);
}

voltar_msg($voltar, 'cores_ok', 'Cores atualizadas! Já valem na tela de login.');
