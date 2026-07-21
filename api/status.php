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

    // Lead "atual" (mais recente) de cada MAC online neste roteador.
    $onlineIds  = [];
    $macPorLead = [];
    foreach ($macs as $mac) {
        $q = db()->prepare(
            'SELECT id FROM leads WHERE roteador = ? AND mac = ? ORDER BY conectado_em DESC LIMIT 1'
        );
        $q->execute([$roteador, $mac]);
        $id = $q->fetchColumn();
        if ($id !== false) {
            $onlineIds[] = (int) $id;
            $macPorLead[(int) $id] = $mac;
        }
    }

    // Quem estava online e não está mais -> marca offline + tempo final.
    // O fim da sessão é o ÚLTIMO instante confirmado (visto_em), não "agora":
    // se o MikroTik ficou fora do ar (ex.: fim de semana desligado), não somamos
    // esse tempo todo — a sessão terminou quando paramos de ver o usuário.
    $prev = db()->prepare('SELECT id, conectado_em, visto_em FROM leads WHERE roteador = ? AND online = 1');
    $prev->execute([$roteador]);
    $up = db()->prepare('UPDATE leads SET online = 0, desconectado_em = ?, segundos_conectado = ? WHERE id = ?');
    // Grava a duração também no HISTÓRICO (a sessão que fecha é sempre a conexão
    // mais recente do lead) — alimenta o "tempo online" do pop-up "ver conexões".
    $upC = db()->prepare('UPDATE conexoes SET segundos = ? WHERE lead_id = ? ORDER BY id DESC LIMIT 1');
    foreach ($prev->fetchAll() as $r) {
        if (!in_array((int) $r['id'], $onlineIds, true)) {
            $fimTs = $r['visto_em'] ? strtotime((string) $r['visto_em']) : $nowTs;
            $fim   = $r['visto_em'] ?: $dbNow;
            $seg   = max(0, $fimTs - strtotime($r['conectado_em']));
            $up->execute([$fim, $seg, (int) $r['id']]);
            $upC->execute([$seg, (int) $r['id']]);
        }
    }

    // Marca online os atuais.
    if ($onlineIds) {
        $ph = implode(',', array_fill(0, count($onlineIds), '?'));
        $q  = db()->prepare("UPDATE leads SET online = 1, visto_em = ?, desconectado_em = NULL WHERE id IN ($ph)");
        $q->execute(array_merge([$dbNow], $onlineIds));
    }

    // Consumo: atualiza os bytes da conexão ABERTA (a mais recente) de cada
    // lead online. Silencioso se a coluna ainda não existir (migracao_bytes.sql)
    // — o controle de tempo/banda nunca pode parar por causa disso.
    if ($usoMap && $onlineIds) {
        try {
            $upB = db()->prepare('UPDATE conexoes SET bytes = ? WHERE lead_id = ? ORDER BY id DESC LIMIT 1');
            foreach ($onlineIds as $lid) {
                $m = $macPorLead[$lid] ?? null;
                if ($m !== null && isset($usoMap[$m])) {
                    $upB->execute([$usoMap[$m], $lid]);
                }
            }
        } catch (Throwable $e) {
            // banco sem a coluna `bytes`: ignora até a migração rodar
        }
    }

    // kick = quem passou do tempo (desconectar); bw = mac=Mbps por usuário com limite.
    // O limite de tempo é DIÁRIO: soma as sessões de hoje já fechadas (conexoes)
    // + a sessão aberta agora. Antes contava só a sessão atual — reconectar
    // zerava o cronômetro e o limite nunca valia de fato.
    $kick = [];
    $bw   = [];
    if ($onlineIds) {
        $ph = implode(',', array_fill(0, count($onlineIds), '?'));
        $qs = db()->prepare(
            "SELECT lead_id, COALESCE(SUM(segundos), 0)
               FROM conexoes WHERE lead_id IN ($ph) AND conectado_em >= CURRENT_DATE
              GROUP BY lead_id"
        );
        $qs->execute($onlineIds);
        $usadoHoje = $qs->fetchAll(PDO::FETCH_KEY_PAIR);

        $q = db()->prepare("SELECT id, mac, conectado_em, tempo_limite_min, banda_limite FROM leads WHERE id IN ($ph)");
        $q->execute($onlineIds);
        foreach ($q->fetchAll() as $r) {
            if (!$r['mac']) {
                continue;
            }
            $lim = $r['tempo_limite_min'];
            if ($lim !== null && (int) $lim > 0) {
                $usado = (int) ($usadoHoje[(int) $r['id']] ?? 0)
                       + max(0, $nowTs - strtotime($r['conectado_em']));
                if ($usado >= (int) $lim * 60) {
                    $kick[] = $r['mac'];
                    continue; // vai ser desconectado; não precisa de fila de banda
                }
            }
            $banda = $r['banda_limite'];
            if ($banda !== null && (int) $banda > 0) {
                $bw[] = $r['mac'] . '=' . (int) $banda;
            }
        }
    }

    // Resposta: "<kick,csv>|<mac=Mbps,csv>". O MikroTik (leadsync.rsc) separa no '|'.
    echo implode(',', $kick) . '|' . implode(',', $bw);
} catch (Throwable $e) {
    http_response_code(500);
    echo '';
}
