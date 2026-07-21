<?php
// Baixa (.zip) os arquivos atuais da página de login do hotspot deste roteador.
// São os MESMOS arquivos que o MikroTik espelha em flash/hostsv7: o servidor é a
// fonte e o roteador só PUXA daqui (a hospedagem não alcança o roteador). Só
// leitura — não apaga nem altera nada, nem no servidor nem no MikroTik.
// Isolamento igual ao upload_portal.php: admin via cliente_id; cliente só o
// próprio roteador e só se a conta tiver portal_habilitado.
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';

$c = exigir_login();

if ((int) $c['is_admin'] === 1) {
    $cid      = (int) ($_GET['cliente_id'] ?? 0);
    $roteador = (string) roteador_escolhido(roteadores_conta($cid), $_GET['roteador'] ?? null);
    $habil    = true; // admin sempre pode
    $voltar   = '../admin_leads.php?id=' . $cid . ($roteador !== '' ? '&r=' . rawurlencode($roteador) : '');
} else {
    $q = db()->prepare('SELECT portal_habilitado FROM compradores WHERE id = ?');
    $q->execute([(int) $c['id']]);
    $roteador = (string) roteador_escolhido(roteadores_conta((int) $c['id']), $_GET['roteador'] ?? null);
    $habil    = ((int) $q->fetchColumn() === 1);
    $voltar   = '../painel.php' . ($roteador !== '' ? '?r=' . rawurlencode($roteador) : '');
}

function voltar_msg(string $to, string $key, string $msg): void
{
    $sep = (strpos($to, '?') === false) ? '?' : '&';
    header('Location: ' . $to . $sep . $key . '=' . rawurlencode($msg));
    exit;
}

if ($roteador === '') {
    voltar_msg($voltar, 'portal_erro', 'Este cliente não tem roteador vinculado.');
}
if (!$habil) {
    voltar_msg($voltar, 'portal_erro', 'Este recurso não está liberado para este usuário.');
}

$files = portal_files($roteador);
if (!$files) {
    voltar_msg($voltar, 'portal_erro', 'Nenhum arquivo no servidor para baixar. Envie o template primeiro.');
}
if (!class_exists('ZipArchive')) {
    voltar_msg($voltar, 'portal_erro', 'O servidor não tem suporte a ZIP (extensão ZipArchive).');
}

// Monta o zip num temporário. Os nomes planos do disco (css~style.css) voltam a
// ser caminhos reais (css/style.css): o zip sai com a mesma árvore do hostsv7 —
// e pode ser reenviado no upload sem nenhum ajuste.
$base = portal_dir($roteador);
$tmp  = tempnam(sys_get_temp_dir(), 'cdzip');
$zip  = new ZipArchive();
if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    voltar_msg($voltar, 'portal_erro', 'Não foi possível montar o .zip. Tente de novo.');
}
foreach ($files as $f) {
    $zip->addFile($base . '/' . portal_encode($f), $f);
}
$zip->close();

// Nome do download: identity saneado (só letras/números/._-) para o header.
$nome = preg_replace('/[^A-Za-z0-9._-]+/', '-', $roteador);
header('Content-Type: application/zip');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="hostsv7-' . $nome . '.zip"');
header('Content-Length: ' . (string) filesize($tmp));
readfile($tmp);
@unlink($tmp);
