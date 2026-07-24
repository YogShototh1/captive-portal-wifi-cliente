<?php
// Entregue ao MikroTik (leadsync.rsc), não ao navegador.
// Lista dos aparelhos JÁ CADASTRADOS do roteador, para o login.html pular a
// pergunta do número e ir direto ao anúncio (a lista fica na flash; no connect
// o painel não é consultado).
//   sem &f : versão da lista (curta) — o roteador compara e só rebaixa quando muda.
//   com &f : arquivo JS "window.PORTAL_MACS=[...]" com HASHES dos MACs
//            (md5 truncado — nunca o MAC cru: privacidade; a página hasheia o
//            $(mac) com o md5.js que já existe no hotspot e compara).
// Auth: token = admin_token do config.php (igual ao portal.php).
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

header('X-Content-Type-Options: nosniff');
header('Content-Type: text/plain; charset=utf-8');

// Todos os MACs que já conectaram com cadastro neste roteador (o histórico de
// conexões cobre o cliente que troca de aparelho). Hash curto: 12 hex por MAC.
// ponytail: LIMIT 5000 — acima disso o arquivo passa de ~70KB na flash; se um
// roteador chegar lá, paginar ou reter só os MACs recentes.
try {
    $q = db()->prepare(
        'SELECT DISTINCT c.mac FROM conexoes c
           JOIN leads l ON l.id = c.lead_id
          WHERE l.roteador = ? AND c.mac IS NOT NULL AND c.mac <> ""
          LIMIT 5000'
    );
    $q->execute([$roteador]);
    $hashes = [];
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $hashes[] = substr(md5(strtoupper((string) $m)), 0, 12);
    }
    sort($hashes); // ordem estável -> versão só muda quando a LISTA muda
} catch (Throwable $e) {
    http_response_code(500);
    exit('');
}

if (trim((string) ($_REQUEST['f'] ?? '')) === '') {
    echo substr(md5(implode(',', $hashes)), 0, 8); // versão
    exit;
}

header('Content-Disposition: attachment; filename="macs.js"');
echo 'window.PORTAL_MACS=' . json_encode($hashes) . ';';
