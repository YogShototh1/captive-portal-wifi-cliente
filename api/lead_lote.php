<?php
// Entregue pelo MikroTik (leadsync.rsc), não pelo navegador.
// Recebe os leads que o ROTEADOR tem em mãos: o telefone viaja no username da
// sessão do hotspot (T-<mac>-<telefone>, ver login.html).
//
// Para que serve: com a internet do estabelecimento fora ou muito lenta, o
// navegador do cliente não alcança o painel e o lead se perderia. O roteador,
// porém, ficou com o número na sessão — quando a linha volta, o leadsync
// despeja tudo aqui.
//
// Formato: d=<mac>|<telefone>,<mac>|<telefone>,...
// Auth: token = admin_token do config.php (igual portal.php/macs.php/tema.php).
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/util.php';

$cfg   = config();
$token = (string) ($_REQUEST['token'] ?? '');
if (!hash_equals((string) $cfg['admin_token'], $token)) {
    http_response_code(403);
    exit('');
}

$roteador = trim((string) ($_REQUEST['roteador'] ?? ''));
if ($roteador === '') {
    http_response_code(400);
    exit('');
}

mikrotik_tocar($roteador);
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$d = trim((string) ($_REQUEST['d'] ?? ''));
if ($d === '') {
    exit('0');
}

// Janela de idempotência: o roteador pode mandar o mesmo par MAC+telefone mais
// de uma vez (a sessão continua ativa entre as rodadas, ou o navegador já tinha
// conseguido enviar). Se já existe conexão desse MAC nos últimos minutos, o
// lead é atualizado mas NÃO entra outra conexão no histórico — senão uma tarde
// de internet instável viraria dezenas de visitas falsas nos relatórios.
const LOTE_JANELA_MIN = 10;

$gravados = 0;
$repetidos = 0;

// ponytail: o upsert abaixo espelha o de api/lead.php (só o caminho COM
// telefone). Preferi repetir a query a refatorar o endpoint público, que está
// em produção e tem outras responsabilidades (rate limit, user-agent, revisita
// sem telefone). Mudou a regra do lead lá? Rever aqui também.
try {
    $agora = db_now();
    $temConexao = db()->prepare(
        'SELECT COUNT(*) FROM conexoes
          WHERE mac = ? AND conectado_em > (NOW() - INTERVAL ' . LOTE_JANELA_MIN . ' MINUTE)'
    );
    $selLead = db()->prepare('SELECT id FROM leads WHERE roteador = ? AND telefone = ? LIMIT 1');
    $upLead  = db()->prepare(
        'UPDATE leads SET mac = ?, conectado_em = ?, total_conexoes = total_conexoes + 1 WHERE id = ?'
    );
    $upLeadSemConta = db()->prepare('UPDATE leads SET mac = ?, conectado_em = ? WHERE id = ?');
    $insConexao = db()->prepare(
        'INSERT INTO conexoes (lead_id, conectado_em, mac) VALUES (?, ?, ?)'
    );

    foreach (explode(',', $d) as $item) {
        $item = trim($item);
        if ($item === '') {
            continue;
        }
        $barra = strpos($item, '|');
        if ($barra === false) {
            continue;
        }
        $mac = strtoupper(trim(substr($item, 0, $barra)));
        $tel = preg_replace('/\D/', '', substr($item, $barra + 1));

        // Mesma validação do endpoint público: lixo fica de fora.
        if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
            continue;
        }
        if (strlen($tel) < 10 || strlen($tel) > 11) {
            continue;
        }

        $temConexao->execute([$mac]);
        $jaVeio = (int) $temConexao->fetchColumn() > 0;

        $selLead->execute([$roteador, $tel]);
        $leadId = $selLead->fetchColumn();

        if ($leadId !== false) {
            $leadId = (int) $leadId;
            if ($jaVeio) {
                $upLeadSemConta->execute([$mac, $agora, $leadId]);
                $repetidos++;
                continue;
            }
            $upLead->execute([$mac, $agora, $leadId]);
        } else {
            $ins = db()->prepare(
                'INSERT INTO leads (roteador, telefone, mac, conectado_em, primeira_conexao,
                                    total_conexoes, consentimento, tempo_limite_min, banda_limite)
                 VALUES (?, ?, ?, ?, ?, 1, 1, ?, ?)'
            );
            $ins->execute([
                $roteador, $tel, $mac, $agora, $agora,
                roteador_cfg_get($roteador, 'tlimit'), roteador_cfg_get($roteador, 'banda'),
            ]);
            $leadId = (int) db()->lastInsertId();
        }

        $insConexao->execute([$leadId, $agora, $mac]);
        $gravados++;
    }
} catch (Throwable $e) {
    error_log('lead_lote: ' . $e->getMessage());
    http_response_code(500);
    exit('erro');
}

echo $gravados . '/' . ($gravados + $repetidos);
