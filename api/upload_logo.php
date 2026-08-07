<?php
// Recebe a LOGO da tela de login (painel do comprador OU admin) e grava por
// roteador. Mesmo isolamento do upload_anuncio.php: comprador só o próprio
// roteador; admin, o do cliente aberto (cliente_id resolvido no servidor).
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
    $sep = (strpos($to, '?') === false) ? '?' : '&';
    // Preserva o #hash (aba) no fim da URL.
    $hash = '';
    if (($h = strpos($to, '#')) !== false) { $hash = substr($to, $h); $to = substr($to, 0, $h); }
    header('Location: ' . $to . $sep . $key . '=' . rawurlencode($msg) . $hash);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    voltar_msg($voltar, 'logo_erro', 'Requisição inválida.');
}
if (!csrf_valido($_POST['csrf'] ?? '')) {
    voltar_msg($voltar, 'logo_erro', 'Sessão expirada. Recarregue e tente de novo.');
}
if ($roteador === '') {
    voltar_msg($voltar, 'logo_erro', 'Este cliente não tem roteador vinculado.');
}

// Sem imagem = mudar SÓ o formato (não precisa reenviar a logo).
$err = $_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE;
if (empty($_FILES['logo']) || $err === UPLOAD_ERR_NO_FILE) {
    logo_forma_set($roteador, (string) ($_POST['forma'] ?? 'quadrado'));
    voltar_msg($voltar, 'logo_ok', 'Formato da logo atualizado!');
}
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
    voltar_msg($voltar, 'logo_erro', 'Imagem muito grande (máx. 2 MB).');
}
if ($err !== UPLOAD_ERR_OK) {
    voltar_msg($voltar, 'logo_erro', 'Falha no upload. Tente novamente.');
}

$f   = $_FILES['logo'];
$MAX = 2 * 1024 * 1024; // 2 MB
if ($f['size'] > $MAX) {
    voltar_msg($voltar, 'logo_erro', 'Imagem maior que 2 MB.');
}
if (!is_uploaded_file($f['tmp_name'])) {
    voltar_msg($voltar, 'logo_erro', 'Upload inválido.');
}

$info = @getimagesize($f['tmp_name']);
if ($info === false) {
    voltar_msg($voltar, 'logo_erro', 'O arquivo não é uma imagem válida.');
}
$type = $info[2];
if ($type === IMAGETYPE_PNG) {
    $ext = 'png';
} elseif ($type === IMAGETYPE_JPEG) {
    $ext = 'jpg';
} else {
    voltar_msg($voltar, 'logo_erro', 'Envie apenas PNG, JPG ou JPEG (PNG recomendado, com fundo transparente).');
}

$dir = ads_dir();
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
// A logo que está SAINDO entra no histórico antes de ser apagada — senão ela
// se perde justamente no momento em que passaria a fazer falta.
$anterior = logo_atual($roteador);
if ($anterior !== null) {
    midia_hist_add($roteador, 'logo', $anterior, 'logo anterior');
}
foreach (['jpg', 'png'] as $e) {
    @unlink(logo_base($roteador) . '.' . $e);
    // O derivado reduzido da logo anterior sai junto: ele é mais novo que o
    // arquivo que vai entrar e continuaria valendo como cache.
    @unlink(logo_base($roteador) . '.flash.' . $e);
}
$dest = logo_base($roteador) . '.' . $ext;
if (!move_uploaded_file($f['tmp_name'], $dest)) {
    voltar_msg($voltar, 'logo_erro', 'Não foi possível salvar a imagem.');
}
@chmod($dest, 0644);
// Guarda no histórico: dá para voltar a uma logo anterior sem reenviar nada.
midia_hist_add($roteador, 'logo', $dest, (string) ($f['name'] ?? ''));

// Forma escolhida no dropdown (quadrado/arredondado/redondo).
logo_forma_set($roteador, (string) ($_POST['forma'] ?? 'quadrado'));

voltar_msg($voltar, 'logo_ok', 'Logo atualizada! Já aparece na tela de login.');
