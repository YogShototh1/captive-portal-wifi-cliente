<?php
// Ingestão do LOG DE ACESSOS (só metadados: IP de destino por cliente).
// Chamado pelo leadsync.rsc (não por navegador). Token = admin_token.
//   map   = "ipCliente=MAC,ipCliente=MAC,..."  (do /ip hotspot active)
//   conns = "ipCliente>ipDestino,..."          (do /ip firewall connection)
// Dedup por (roteador, mac, ip_destino): 1 linha por par, com primeiro/último
// acesso e contagem — mantém o volume sob controle. Só ADMIN lê isto depois.
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
mikrotik_tocar($roteador);

try {
    db()->exec(
        'CREATE TABLE IF NOT EXISTS acessos (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            roteador VARCHAR(120) NOT NULL,
            mac VARCHAR(20) NOT NULL,
            ip_cliente VARCHAR(45) NULL,
            ip_destino VARCHAR(45) NOT NULL,
            primeiro_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            visto_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            hits INT NOT NULL DEFAULT 1,
            UNIQUE KEY uq (roteador, mac, ip_destino),
            INDEX idx_visto (roteador, visto_em),
            INDEX idx_mac (roteador, mac)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    db()->exec(
        'CREATE TABLE IF NOT EXISTS host_cache (
            ip VARCHAR(45) PRIMARY KEY,
            host VARCHAR(190) NULL,
            org VARCHAR(120) NULL,
            dns VARCHAR(190) NULL,
            em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
} catch (Throwable $e) {
    http_response_code(500);
    exit('');
}
// Auto-heal: host_cache antigo (sem a coluna org do rotulo resolvido).
try { db()->exec('ALTER TABLE host_cache ADD COLUMN org VARCHAR(120) NULL'); } catch (Throwable $e) {}
// Idem para o nome vindo do cache de DNS do roteador (api/dns_nomes.php).
try { db()->exec('ALTER TABLE host_cache ADD COLUMN dns VARCHAR(190) NULL'); } catch (Throwable $e) {}

// IP do cliente -> MAC (do hotspot ativo).
$ipMac = [];
foreach (explode(',', (string) ($_REQUEST['map'] ?? '')) as $par) {
    $eq = strpos($par, '=');
    if ($eq === false) {
        continue;
    }
    $ip  = trim(substr($par, 0, $eq));
    $mac = sanitiza_mac(trim(substr($par, $eq + 1)));
    if (filter_var($ip, FILTER_VALIDATE_IP) && is_string($mac)) {
        $ipMac[$ip] = $mac;
    }
}

$insA = db()->prepare(
    'INSERT INTO acessos (roteador, mac, ip_cliente, ip_destino) VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE visto_em = CURRENT_TIMESTAMP, hits = hits + 1'
);
$insH = db()->prepare('INSERT IGNORE INTO host_cache (ip) VALUES (?)');

$vistos = [];
$n = 0;
foreach (explode(',', (string) ($_REQUEST['conns'] ?? '')) as $c) {
    $gt = strpos($c, '>');
    if ($gt === false) {
        continue;
    }
    $sip = trim(substr($c, 0, $gt));
    $dip = trim(substr($c, $gt + 1));
    if (!filter_var($dip, FILTER_VALIDATE_IP) || !isset($ipMac[$sip])) {
        continue;
    }
    $mac = $ipMac[$sip];
    $chave = $mac . '|' . $dip;
    if (isset($vistos[$chave])) {
        continue; // já gravado nesta rodada
    }
    $vistos[$chave] = true;
    $insA->execute([$roteador, $mac, $sip, $dip]);
    $insH->execute([$dip]);
    if (++$n >= 500) {
        break; // teto de segurança por rodada
    }
}

echo 'ok ' . $n;
