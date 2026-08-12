<?php
// "Apagar cookies" de um número: marca os MACs dele como esquecidos, para o
// macs.php parar de listá-los -> no próximo login o aparelho volta a pedir o
// número (o macs.js do MikroTik regenera do banco a cada rodada). Ao registrar
// de novo (lead.php), o MAC sai da lista de esquecidos e volta a ser reconhecido.
// Autenticado por sessão; isolamento igual ao lead_excluir.php.
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';

header('Content-Type: application/json; charset=utf-8');

$comprador = comprador_logado();
if (!$comprador) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'erro' => 'nao autenticado']));
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}
if (!csrf_valido($in['csrf'] ?? '')) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'erro' => 'csrf']));
}

$id = (int) ($in['id'] ?? 0);

// `ids` chega quando a linha da tabela é uma pessoa mesclada de vários
// MikroTiks: esquecer vale nos dois, senão ela entraria direto pelo outro.
$ids = leads_permitidos($in['ids'] ?? $id, $comprador);
if (!$ids) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'erro' => 'Lead não encontrado.']));
}

try {
    db()->exec(
        'CREATE TABLE IF NOT EXISTS macs_esquecidos (
            roteador VARCHAR(120) NOT NULL,
            mac      VARCHAR(20)  NOT NULL,
            PRIMARY KEY (roteador, mac)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // MACs distintos deste número, cada um com o roteador do SEU cadastro —
    // macs_esquecidos é (roteador, mac), então o par tem de vir junto.
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $qm = db()->prepare(
        "SELECT DISTINCT l.roteador, c.mac
           FROM conexoes c JOIN leads l ON l.id = c.lead_id
          WHERE c.lead_id IN ($ph) AND c.mac IS NOT NULL AND c.mac <> ''"
    );
    $qm->execute($ids);
    $ins = db()->prepare('INSERT IGNORE INTO macs_esquecidos (roteador, mac) VALUES (?, ?)');
    $n = 0;
    foreach ($qm->fetchAll() as $par) {
        $ins->execute([$par['roteador'], $par['mac']]);
        $n++;
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'erro' => 'Falha ao apagar cookies.']));
}

echo json_encode(['ok' => true, 'id' => $id, 'macs' => $n]);
