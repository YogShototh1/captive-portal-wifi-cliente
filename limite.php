<?php
// Endpoint PÚBLICO: diz ao login.html se este aparelho (MAC) já gastou o limite
// DIÁRIO de tempo definido no painel (tempo_limite_min). Carregado via
// <script src="https://captivedata.com.br/limite.php?r=$(identity)&mac=$(mac)">,
// que funciona no navegador restrito do captive portal (inclusive iPhone/CNA).
// Em qualquer dúvida/erro responde "não bloqueado" — nunca travar o portal.
ini_set('display_errors', '0');

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/util.php';
require_once __DIR__ . '/inc/validacao.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');

function responde(bool $bloqueado): void
{
    echo 'window.PORTAL_BLOQUEADO=' . ($bloqueado ? 'true' : 'false') . ';';
    exit;
}

$roteador = trim((string) ($_GET['r'] ?? ''));
$mac      = sanitiza_mac(isset($_GET['mac']) ? (string) $_GET['mac'] : null);
if ($roteador === '' || !is_string($mac)) {
    responde(false);
}

try {
    // Lead mais recente deste MAC neste roteador — dá o limite vigente.
    $q = db()->prepare(
        'SELECT id, conectado_em, online, segundos_conectado, visto_em, tempo_limite_min
           FROM leads WHERE roteador = ? AND mac = ? ORDER BY conectado_em DESC LIMIT 1'
    );
    $q->execute([$roteador, $mac]);
    $lead = $q->fetch();
    if (!$lead || $lead['tempo_limite_min'] === null || (int) $lead['tempo_limite_min'] <= 0) {
        responde(false);
    }

    // Uso de HOJE do APARELHO (por MAC, atravessando leads: trocar o telefone
    // digitado não zera o orçamento do dia): sessões fechadas de hoje...
    $qs = db()->prepare(
        'SELECT COALESCE(SUM(c.segundos), 0)
           FROM conexoes c JOIN leads l ON l.id = c.lead_id
          WHERE c.mac = ? AND l.roteador = ? AND c.conectado_em >= CURRENT_DATE'
    );
    $qs->execute([$mac, $roteador]);
    $usado = (int) $qs->fetchColumn();

    // ...mais a sessão aberta agora, se houver (lead_estado corrige "online preso").
    $st = lead_estado($lead, strtotime(db_now()));
    if ($st['online'] === 1) {
        $usado += $st['elapsed'];
    }

    responde($usado >= (int) $lead['tempo_limite_min'] * 60);
} catch (Throwable $e) {
    responde(false);
}
