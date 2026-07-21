<?php
// Aplica um limite-padrão A TODOS os usuários do roteador (linhas atuais) e
// guarda como padrão para os PRÓXIMOS que conectarem. Dois campos possíveis:
//   - tlimite : tempo limite (min)      -> tempo_limite_min
//   - banda   : banda máxima (Mbps)     -> banda_limite
// Vazio = "sem limite" (aplica NULL a todos e apaga o padrão).
// Isolamento igual ao set_dst.php: cliente = próprio roteador; admin = cliente_id.
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

// Descobre qual campo foi enviado (uma forma por campo).
$campo = isset($_POST['tlimite']) ? 'tlimite' : (isset($_POST['banda']) ? 'banda' : '');
$keyOk  = $campo === 'banda' ? 'banda_ok'  : 'tlim_ok';
$keyErr = $campo === 'banda' ? 'banda_erro' : 'tlim_erro';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $campo === '') {
    voltar_msg($voltar, $keyErr, 'Requisição inválida.');
}
if (!csrf_valido($_POST['csrf'] ?? '')) {
    voltar_msg($voltar, $keyErr, 'Sessão expirada. Recarregue e tente de novo.');
}
if ($roteador === '') {
    voltar_msg($voltar, $keyErr, 'Este cliente não tem roteador vinculado.');
}

// Valor: vazio -> NULL (sem limite); senão inteiro > 0 (0 também vira NULL).
$raw = trim((string) $_POST[$campo]);
$val = ($raw === '') ? null : max(0, (int) $raw);
if ($val === 0) {
    $val = null;
}
// Banda com teto sensato (Mbps).
if ($campo === 'banda' && $val !== null) {
    $val = min($val, 10000);
}

$coluna = $campo === 'banda' ? 'banda_limite' : 'tempo_limite_min';
$chave  = $campo === 'banda' ? 'banda' : 'tlimit';

try {
    // 1) aplica a todos os leads atuais do roteador
    $u = db()->prepare("UPDATE leads SET $coluna = ? WHERE roteador = ?");
    $u->execute([$val, $roteador]);
    // 2) guarda como padrão dos próximos
    roteador_cfg_set($roteador, $chave, $val);
} catch (Throwable $e) {
    voltar_msg($voltar, $keyErr, 'Não foi possível aplicar. Tente de novo.');
}

$txt = ($val === null)
    ? ($campo === 'banda' ? 'Banda liberada (sem limite) para todos.' : 'Tempo liberado (sem limite) para todos.')
    : ($campo === 'banda' ? "Banda de {$val} Mbps aplicada a todos." : "Tempo limite de {$val} min aplicado a todos.");
voltar_msg($voltar, $keyOk, $txt . ' Vale também para os próximos.');
