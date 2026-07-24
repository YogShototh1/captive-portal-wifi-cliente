<?php
// Chamado pelo SCRIPT AGENDADO do MikroTik (não por navegador).
// Recebe a lista de MACs online e devolve, em texto, a lista de MACs que o
// MikroTik deve DESCONECTAR (os que passaram do limite definido no painel).
//
// Auth: token = admin_token do config.php.  (Multi-comprador: trocar por token
//       por comprador no futuro.)
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/validacao.php';
require_once __DIR__ . '/../inc/util.php';

header('Content-Type: text/plain; charset=utf-8');

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

// Heartbeat: esta chamada (autenticada) prova que o MikroTik está online agora.
mikrotik_tocar($roteador);

// MACs online (separados por vírgula), normalizados e sem duplicados.
$macsSet = [];
foreach (explode(',', (string) ($_REQUEST['macs'] ?? '')) as $m) {
    $n = sanitiza_mac(trim($m));
    if (is_string($n)) {
        $macsSet[$n] = true;
    }
}
$macs = array_keys($macsSet);

// Consumo por MAC ("uso=MAC=bytes,MAC=bytes,..."): bytes-in+out da sessão ativa.
$usoMap = [];
foreach (explode(',', (string) ($_REQUEST['uso'] ?? '')) as $par) {
    $p = strrpos($par, '=');
    if ($p === false) {
        continue;
    }
    $m = sanitiza_mac(trim(substr($par, 0, $p)));
    if (is_string($m)) {
        $usoMap[$m] = max(0, (int) substr($par, $p + 1));
    }
}

try {
    $dbNow = db_now();
    $nowTs = strtotime($dbNow);

    // Auto-heal: sessao rastreada POR CONEXAO precisa de visto_em em conexoes.
    // ADD COLUMN em coluna existente da erro (1060), ignorado. Roda 1x na pratica.
    try { db()->exec('ALTER TABLE conexoes ADD COLUMN visto_em TIMESTAMP NULL AFTER bytes'); } catch (Throwable $e) {}

    // Sessao ABERTA (conexoes.segundos NULL) de cada MAC online, chaveada pelo
    // MAC — nao pelo lead. Assim 2 aparelhos no mesmo numero contam separado.
    $onlineConx    = []; // conexao id => ['mac'=>, 'lead'=>, 'ini'=> conectado_em]
    $leadIdsOnline = []; // leads com >=1 aparelho online (agregado p/ o painel)
    foreach ($macs as $mac) {
        $q = db()->prepare(
            'SELECT c.id, c.lead_id, c.conectado_em FROM conexoes c JOIN leads l ON l.id = c.lead_id
              WHERE l.roteador = ? AND c.mac = ? AND c.segundos IS NULL
              ORDER BY c.id DESC LIMIT 1'
        );
        $q->execute([$roteador, $mac]);
        $row = $q->fetch();
        if ($row) {
            $cid = (int) $row['id'];
            $onlineConx[$cid] = ['mac' => $mac, 'lead' => (int) $row['lead_id'], 'ini' => $row['conectado_em']];
            $leadIdsOnline[(int) $row['lead_id']] = true;
        }
    }

    // Marca as conexoes online como vistas agora + grava o consumo de cada uma.
    $upSeen = db()->prepare('UPDATE conexoes SET visto_em = ? WHERE id = ?');
    foreach ($onlineConx as $cid => $o) {
        $upSeen->execute([$dbNow, $cid]);
        if (isset($usoMap[$o['mac']])) {
            try { db()->prepare('UPDATE conexoes SET bytes = ? WHERE id = ?')->execute([$usoMap[$o['mac']], $cid]); }
            catch (Throwable $e) { /* banco sem a coluna bytes: ignora */ }
        }
    }

    // Fecha as conexoes que estavam abertas+vistas mas cujo MAC sumiu. O fim e o
    // ULTIMO instante confirmado (visto_em) daquela conexao — nao "agora".
    $open = db()->prepare(
        'SELECT c.id, c.conectado_em, c.visto_em FROM conexoes c JOIN leads l ON l.id = c.lead_id
          WHERE l.roteador = ? AND c.segundos IS NULL AND c.visto_em IS NOT NULL'
    );
    $open->execute([$roteador]);
    $closeC = db()->prepare('UPDATE conexoes SET segundos = ? WHERE id = ?');
    foreach ($open->fetchAll() as $r) {
        if (isset($onlineConx[(int) $r['id']])) { continue; } // ainda online
        $seg = max(0, strtotime((string) $r['visto_em']) - strtotime((string) $r['conectado_em']));
        $closeC->execute([$seg, (int) $r['id']]);
    }

    // Agregado por NUMERO (para a tabela principal / contador online): online se
    // qualquer aparelho do numero estiver online.
    $prev = db()->prepare('SELECT id, conectado_em, visto_em FROM leads WHERE roteador = ? AND online = 1');
    $prev->execute([$roteador]);
    $upOff = db()->prepare('UPDATE leads SET online = 0, desconectado_em = ?, segundos_conectado = ? WHERE id = ?');
    foreach ($prev->fetchAll() as $r) {
        if (isset($leadIdsOnline[(int) $r['id']])) { continue; } // ainda tem aparelho online
        $fimTs = $r['visto_em'] ? strtotime((string) $r['visto_em']) : $nowTs;
        $fim   = $r['visto_em'] ?: $dbNow;
        $seg   = max(0, $fimTs - strtotime($r['conectado_em']));
        $upOff->execute([$fim, $seg, (int) $r['id']]);
    }
    if ($leadIdsOnline) {
        $ids = array_keys($leadIdsOnline);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("UPDATE leads SET online = 1, visto_em = ?, desconectado_em = NULL WHERE id IN ($ph)")
            ->execute(array_merge([$dbNow], $ids));
    }

    // kick = quem passou do tempo (desconectar); bw = mac=Mbps por aparelho.
    // Limite DIARIO por NUMERO: sessoes de hoje ja fechadas + o aberto agora de
    // TODOS os aparelhos daquele numero. Estourou -> desconecta todos os aparelhos.
    $kick = [];
    $bw   = [];
    if ($leadIdsOnline) {
        $ids = array_keys($leadIdsOnline);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $qs  = db()->prepare(
            "SELECT lead_id, COALESCE(SUM(segundos), 0)
               FROM conexoes WHERE lead_id IN ($ph) AND conectado_em >= CURRENT_DATE
              GROUP BY lead_id"
        );
        $qs->execute($ids);
        $usadoHoje = $qs->fetchAll(PDO::FETCH_KEY_PAIR);

        // Tempo aberto AGORA por numero (soma dos aparelhos online).
        $abertoPorLead = [];
        foreach ($onlineConx as $o) {
            $abertoPorLead[$o['lead']] = ($abertoPorLead[$o['lead']] ?? 0) + max(0, $nowTs - strtotime((string) $o['ini']));
        }

        $ql = db()->prepare("SELECT id, tempo_limite_min, banda_limite FROM leads WHERE id IN ($ph)");
        $ql->execute($ids);
        $lim = [];
        foreach ($ql->fetchAll() as $r) { $lim[(int) $r['id']] = $r; }

        foreach ($ids as $lid) {
            $r     = $lim[$lid];
            $macsL = [];
            foreach ($onlineConx as $o) { if ($o['lead'] === $lid) { $macsL[] = $o['mac']; } }
            $tl = $r['tempo_limite_min'];
            if ($tl !== null && (int) $tl > 0) {
                $usado = (int) ($usadoHoje[$lid] ?? 0) + (int) ($abertoPorLead[$lid] ?? 0);
                if ($usado >= (int) $tl * 60) {
                    foreach ($macsL as $m) { $kick[] = $m; }
                    continue;
                }
            }
            $banda = $r['banda_limite'];
            if ($banda !== null && (int) $banda > 0) {
                foreach ($macsL as $m) { $bw[] = $m . '=' . (int) $banda; }
            }
        }
    }

    // Resposta: "<kick,csv>|<mac=Mbps,csv>". O MikroTik (leadsync.rsc) separa no '|'.
    echo implode(',', $kick) . '|' . implode(',', $bw);
} catch (Throwable $e) {
    http_response_code(500);
    echo '';
}
