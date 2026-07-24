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

$q = db()->prepare('SELECT roteador FROM leads WHERE id = ?');
$q->execute([$id]);
$row = $q->fetch();
if (!$row) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'erro' => 'Lead não encontrado.']));
}
$isAdmin = (int) $comprador['is_admin'] === 1;
if (!$isAdmin && !in_array($row['roteador'], roteadores_conta((int) $comprador['id']), true)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'erro' => 'Sem permissão.']));
}

try {
    db()->exec(
        'CREATE TABLE IF NOT EXISTS macs_esquecidos (
            roteador VARCHAR(120) NOT NULL,
            mac      VARCHAR(20)  NOT NULL,
            PRIMARY KEY (roteador, mac)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // MACs distintos deste número (do histórico de conexões).
    $qm = db()->prepare('SELECT DISTINCT mac FROM conexoes WHERE lead_id = ? AND mac IS NOT NULL AND mac <> ""');
    $qm->execute([$id]);
    $ins = db()->prepare('INSERT IGNORE INTO macs_esquecidos (roteador, mac) VALUES (?, ?)');
    $n = 0;
    foreach ($qm->fetchAll(PDO::FETCH_COLUMN) as $mac) {
        $ins->execute([$row['roteador'], $mac]);
        $n++;
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'erro' => 'Falha ao apagar cookies.']));
}

echo json_encode(['ok' => true, 'id' => $id, 'macs' => $n]);
