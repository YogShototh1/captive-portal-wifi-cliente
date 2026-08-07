<?php
// Salva a página-ponte do Instagram do roteador E a aplica como destino
// pós-anúncio. As duas coisas juntas de propósito: o botão do modal diz
// "Salvar e usar como destino", e configurar sem aplicar deixaria o comprador
// achando que mudou o Wi-Fi quando não mudou.
//
// Isolamento igual ao set_dst.php:
//   - comprador comum: sempre o próprio roteador;
//   - admin: o roteador do cliente da tela aberta (cliente_id), resolvido no
//     servidor — nunca um identity arbitrário vindo do formulário.
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
    voltar_msg($voltar, 'ig_erro', 'Requisição inválida.');
}
if (!csrf_valido($_POST['csrf'] ?? '')) {
    voltar_msg($voltar, 'ig_erro', 'Sessão expirada. Recarregue e tente de novo.');
}
if ($roteador === '') {
    voltar_msg($voltar, 'ig_erro', 'Este cliente não tem roteador vinculado.');
}

// O @ é o único campo obrigatório: sem ele o botão da página não teria destino.
// A limpeza (tirar o @, aceitar o link colado inteiro) é feita no ig_set.
$perfil = trim((string) ($_POST['perfil'] ?? ''));
if ($perfil === '') {
    voltar_msg($voltar, 'ig_erro', 'Informe o perfil do Instagram.');
}

$cfg = ['perfil' => $perfil, 'tem_flags' => !empty($_POST['tem_flags']), 'cores' => [], 'estilo' => []];
foreach (['titulo', 'sub', 'chamada', 'botao', 'rodape'] as $k) {
    if (isset($_POST[$k])) { $cfg[$k] = (string) $_POST[$k]; }
}
foreach (array_keys(ig_padrao()['cores']) as $k) {
    if (isset($_POST['cor_' . $k])) { $cfg['cores'][$k] = (string) $_POST['cor_' . $k]; }
}
foreach (array_keys(ig_padrao()['estilo']) as $k) {
    $cfg['estilo'][$k] = !empty($_POST['est_' . $k]);
}

if (!ig_set($roteador, $cfg)) {
    voltar_msg($voltar, 'ig_erro', 'Não foi possível salvar. Verifique a pasta de dados.');
}

// O ig_set rejeita perfil fora do formato do Instagram e grava vazio. Se isso
// aconteceu, a página não serve de destino — avisa em vez de apontar o Wi-Fi
// para uma página que redireciona para o Google.
if (!ig_pronta($roteador)) {
    voltar_msg($voltar, 'ig_erro', 'Perfil inválido. Use só letras, números, ponto e sublinhado (ex.: sualoja).');
}

// Aplica como destino do hotspot.
$dir = ads_dir();
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
if (@file_put_contents(dst_file($roteador), ig_url($roteador)) === false) {
    voltar_msg($voltar, 'ig_erro', 'A página foi salva, mas não deu para aplicá-la como destino.');
}
@chmod(dst_file($roteador), 0644);

voltar_msg($voltar, 'ig_ok', 'Página salva e já em uso como destino do Wi-Fi.');
