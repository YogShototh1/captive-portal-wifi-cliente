<?php
// Recebe a imagem do anúncio (painel do comprador OU admin) e a grava por roteador.
// Segurança de isolamento:
//   - comprador comum: destino é SEMPRE o próprio roteador (ignora qualquer input);
//   - admin: destino é o roteador do cliente cuja tela de leads está aberta
//            (cliente_id), resolvido no servidor via banco — nunca um roteador
//            arbitrário vindo do formulário.
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';

$c = exigir_login();

// Volta e destino conforme o tipo de usuário. O roteador do POST é VALIDADO
// contra a lista da conta (cliente) ou do cliente aberto (admin) — nunca um
// identity arbitrário do formulário.
if ((int) $c['is_admin'] === 1) {
    $cid      = (int) ($_POST['cliente_id'] ?? 0);
    $roteador = (string) roteador_escolhido(roteadores_conta($cid), $_POST['roteador'] ?? null);
    // Caminho relativo à raiz: este script está em /api/, os painéis estão um
    // nível acima. Sem o "../", o redirect vira /api/admin_leads.php → 404.
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
    voltar_msg($voltar, 'anuncio_erro', 'Requisição inválida.');
}
if (!csrf_valido($_POST['csrf'] ?? '')) {
    voltar_msg($voltar, 'anuncio_erro', 'Sessão expirada. Recarregue e tente de novo.');
}
if ($roteador === '') {
    voltar_msg($voltar, 'anuncio_erro', 'Este cliente não tem roteador vinculado.');
}

$err = $_FILES['anuncio']['error'] ?? UPLOAD_ERR_NO_FILE;
if (empty($_FILES['anuncio']) || $err === UPLOAD_ERR_NO_FILE) {
    voltar_msg($voltar, 'anuncio_erro', 'Selecione uma imagem.');
}
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
    voltar_msg($voltar, 'anuncio_erro', 'Imagem muito grande (máx. 3 MB).');
}
if ($err !== UPLOAD_ERR_OK) {
    voltar_msg($voltar, 'anuncio_erro', 'Falha no upload. Tente novamente.');
}

$f   = $_FILES['anuncio'];
$MAX = 3 * 1024 * 1024; // 3 MB
if ($f['size'] > $MAX) {
    voltar_msg($voltar, 'anuncio_erro', 'Imagem maior que 3 MB.');
}
if (!is_uploaded_file($f['tmp_name'])) {
    voltar_msg($voltar, 'anuncio_erro', 'Upload inválido.');
}

// Confirma que é MESMO uma imagem JPEG/PNG (não confia no nome/extensão enviados).
$info = @getimagesize($f['tmp_name']);
if ($info === false) {
    voltar_msg($voltar, 'anuncio_erro', 'O arquivo não é uma imagem válida.');
}
$type = $info[2];
if ($type === IMAGETYPE_JPEG) {
    $ext = 'jpg';
} elseif ($type === IMAGETYPE_PNG) {
    $ext = 'png';
} else {
    voltar_msg($voltar, 'anuncio_erro', 'Envie apenas JPG, JPEG ou PNG.');
}

$dir = ads_dir();
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
// Remove o anúncio anterior deste roteador (qualquer extensão) e grava o novo.
foreach (['jpg', 'png'] as $e) {
    @unlink(anuncio_base($roteador) . '.' . $e);
}
$dest = anuncio_base($roteador) . '.' . $ext;
if (!move_uploaded_file($f['tmp_name'], $dest)) {
    voltar_msg($voltar, 'anuncio_erro', 'Não foi possível salvar a imagem.');
}
@chmod($dest, 0644);

voltar_msg($voltar, 'anuncio_ok', 'Anúncio atualizado! Já vale para as próximas conexões.');
