<?php
// Salva o site de destino pós-anúncio (dst do hotspot) por roteador.
// Isolamento (igual ao upload de anúncio):
//   - comprador comum: destino é SEMPRE o próprio roteador;
//   - admin: o roteador é o do cliente da tela de leads aberta (cliente_id),
//            resolvido no servidor — nunca um roteador arbitrário do formulário.
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';

$c = exigir_login();

// O roteador do POST é VALIDADO contra a lista da conta (cliente) ou do
// cliente aberto (admin) — nunca um identity arbitrário do formulário.
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
    voltar_msg($voltar, 'dst_erro', 'Requisição inválida.');
}
if (!csrf_valido($_POST['csrf'] ?? '')) {
    voltar_msg($voltar, 'dst_erro', 'Sessão expirada. Recarregue e tente de novo.');
}
if ($roteador === '') {
    voltar_msg($voltar, 'dst_erro', 'Este cliente não tem roteador vinculado.');
}

$url = trim((string) ($_POST['dst'] ?? ''));
if ($url === '') {
    voltar_msg($voltar, 'dst_erro', 'Informe a URL do site de destino.');
}
if (strlen($url) > 2048) {
    voltar_msg($voltar, 'dst_erro', 'URL muito longa.');
}
// Sem esquema → assume https://.
if (!preg_match('~^[a-z][a-z0-9+.\-]*://~i', $url)) {
    $url = 'https://' . $url;
}
$parts = parse_url($url);
$scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
if ($parts === false || empty($parts['host']) || !in_array($scheme, ['http', 'https'], true)) {
    voltar_msg($voltar, 'dst_erro', 'URL inválida. Use um endereço http:// ou https://');
}
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    voltar_msg($voltar, 'dst_erro', 'URL inválida.');
}

// Link de PERFIL do Instagram vira a página-ponte (ig.php). Destino direto no
// instagram.com NÃO funciona no CNA do iPhone: sem cookies, o Instagram força
// o redirect instagram:// e o CNA bloqueia com "Erro ao Abrir a Página" —
// mesmo com utm_source=igweb (no Safari funciona só porque há cookies).
if (preg_match('~^https?://(www\.)?instagram\.com/([A-Za-z0-9._]{1,30})/?(\?.*)?$~i', $url, $m)
    && !in_array(strtolower($m[2]), ['p', 'reel', 'reels', 'stories', 'explore', 'accounts', '.', '..'], true)) {
    // Cliente com página personalizada (pasta /<perfil>/ na raiz)? Usa ela.
    $url = is_dir(__DIR__ . '/../' . $m[2])
        ? 'https://captivedata.com.br/' . $m[2] . '/'
        : 'https://captivedata.com.br/ig.php?u=' . $m[2];
}

$dir = ads_dir();
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
if (@file_put_contents(dst_file($roteador), $url) === false) {
    voltar_msg($voltar, 'dst_erro', 'Não foi possível salvar. Verifique a pasta de dados.');
}
@chmod(dst_file($roteador), 0644);

voltar_msg($voltar, 'dst_ok', 'Site de destino atualizado! Já vale para as próximas conexões.');
