<?php
// Endpoint PÚBLICO: recebe o lead do login.html e grava em `leads`.
// Aceita POST (JSON, fetch/sendBeacon) e GET (beacon via <img>, usado no iOS/CNA).
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/validacao.php';
require_once __DIR__ . '/../inc/util.php';

$cfg    = config();
$method = $_SERVER['REQUEST_METHOD'];
$isGet  = ($method === 'GET');

header('Access-Control-Allow-Origin: ' . $cfg['cors_origin']);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning');

// Responde 1x1 GIF transparente (para o beacon via <img>).
function beacon_gif(): void
{
    http_response_code(200);
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, max-age=0');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

// Encerra a resposta conforme o método: imagem no GET, JSON no POST.
function terminar(bool $isGet, int $code, array $arr): void
{
    if ($isGet) {
        beacon_gif();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

// Preflight CORS (só para POST/fetch)
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Coleta os dados conforme o método
if ($isGet) {
    $data = $_GET;
} elseif ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST;
    }
} else {
    terminar(false, 405, ['ok' => false, 'erro' => 'metodo nao permitido']);
}

// --- Validação ---
// Telefone null = REVISITA de aparelho já cadastrado (login.html pulou a
// pergunta pela lista local de MACs): só registra a conexão no lead que já
// tem esse MAC neste roteador. Nunca cria lead novo sem telefone.
$telefone = sanitiza_telefone((string) ($data['telefone'] ?? ''));

$roteador = trim((string) ($data['roteador'] ?? ''));
if ($roteador === '' || strlen($roteador) > 120) {
    terminar($isGet, 422, ['ok' => false, 'erro' => 'roteador invalido']);
}

// Heartbeat secundário: se chegou um lead, o roteador está online e alcança a API.
mikrotik_tocar($roteador);

$mac = sanitiza_mac(isset($data['mac']) ? (string) $data['mac'] : null);
if ($mac === false) {
    terminar($isGet, 422, ['ok' => false, 'erro' => 'mac invalido']);
}

$ip   = null;
$ipIn = isset($data['ip']) ? trim((string) $data['ip']) : '';
if ($ipIn !== '') {
    if (!filter_var($ipIn, FILTER_VALIDATE_IP)) {
        terminar($isGet, 422, ['ok' => false, 'erro' => 'ip invalido']);
    }
    $ip = $ipIn;
}
if ($ip === null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
}

$consentimento = !empty($data['consentimento']) ? 1 : 0;

try {
    // Rate limit por IP: conta as CONEXÕES recentes (não os leads deduplicados).
    $limite = (int) ($cfg['lead_rate_limit'] ?? 0);
    if ($limite > 0 && $ip !== null) {
        $q = db()->prepare(
            'SELECT COUNT(*) FROM conexoes WHERE ip = ? AND conectado_em > (NOW() - INTERVAL 60 SECOND)'
        );
        $q->execute([$ip]);
        if ((int) $q->fetchColumn() >= $limite) {
            terminar($isGet, 429, ['ok' => false, 'erro' => 'muitas requisicoes']);
        }
    }

    $dispositivo = detecta_dispositivo($_SERVER['HTTP_USER_AGENT'] ?? '');
    $agora = db_now();

    // Revisita sem telefone: acha o lead pelo MAC (mais recente) e registra.
    if ($telefone === null) {
        if ($mac === null || $mac === '') {
            terminar($isGet, 422, ['ok' => false, 'erro' => 'telefone invalido']);
        }
        $sel = db()->prepare(
            'SELECT l.id FROM leads l
              WHERE l.roteador = ? AND l.id IN (SELECT c.lead_id FROM conexoes c WHERE c.mac = ?)
              ORDER BY l.conectado_em DESC LIMIT 1'
        );
        $sel->execute([$roteador, $mac]);
        $leadId = $sel->fetchColumn();
        if ($leadId === false) {
            terminar($isGet, 422, ['ok' => false, 'erro' => 'telefone invalido']);
        }
        $leadId = (int) $leadId;
        $u = db()->prepare(
            'UPDATE leads SET mac = ?, ip = ?, dispositivo = ?, conectado_em = ?,
                    total_conexoes = total_conexoes + 1 WHERE id = ?'
        );
        $u->execute([$mac, $ip, $dispositivo, $agora, $leadId]);
        $c = db()->prepare('INSERT INTO conexoes (lead_id, conectado_em, mac, ip, dispositivo) VALUES (?, ?, ?, ?, ?)');
        $c->execute([$leadId, $agora, $mac, $ip, $dispositivo]);
        terminar($isGet, 201, ['ok' => true, 'id' => $leadId]);
    }

    // UM lead por (roteador, telefone): atualiza a linha existente ou cria.
    $sel = db()->prepare('SELECT id FROM leads WHERE roteador = ? AND telefone = ? LIMIT 1');
    $sel->execute([$roteador, $telefone]);
    $leadId = $sel->fetchColumn();

    if ($leadId !== false) {
        $leadId = (int) $leadId;
        $u = db()->prepare(
            'UPDATE leads SET mac = ?, ip = ?, dispositivo = ?, conectado_em = ?,
                    total_conexoes = total_conexoes + 1, consentimento = ? WHERE id = ?'
        );
        $u->execute([$mac, $ip, $dispositivo, $agora, $consentimento, $leadId]);
    } else {
        // Novo número herda os limites-padrão do roteador (definidos no painel).
        $tlDefault    = roteador_cfg_get($roteador, 'tlimit');
        $bandaDefault = roteador_cfg_get($roteador, 'banda');
        $ins = db()->prepare(
            'INSERT INTO leads (roteador, telefone, mac, ip, dispositivo, conectado_em, primeira_conexao, total_conexoes, consentimento, tempo_limite_min, banda_limite)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)'
        );
        $ins->execute([$roteador, $telefone, $mac, $ip, $dispositivo, $agora, $agora, $consentimento, $tlDefault, $bandaDefault]);
        $leadId = (int) db()->lastInsertId();
    }

    // Registra esta conexão no histórico (para o "ver conexões").
    $c = db()->prepare('INSERT INTO conexoes (lead_id, conectado_em, mac, ip, dispositivo) VALUES (?, ?, ?, ?, ?)');
    $c->execute([$leadId, $agora, $mac, $ip, $dispositivo]);

    terminar($isGet, 201, ['ok' => true, 'id' => $leadId]);
} catch (Throwable $e) {
    terminar($isGet, 500, ['ok' => false, 'erro' => 'falha ao gravar']);
}
