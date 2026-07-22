<?php
// Edita um lead (nome de identificação e/ou número). Autenticado por sessão.
// Isolamento igual ao set_limite.php: cliente só edita lead dos roteadores
// dele; admin edita qualquer um.
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';
require_once __DIR__ . '/../inc/validacao.php';

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

// Nome: opcional; vazio = remove (volta a mostrar o número). Máx. 60.
$nome = trim((string) ($in['nome'] ?? ''));
if (mb_strlen($nome) > 60) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'erro' => 'Nome muito longo (máx. 60).']));
}
if ($nome === '') {
    $nome = null;
}

// Telefone: obrigatório e válido (mesmas regras do portal).
$telefone = sanitiza_telefone((string) ($in['telefone'] ?? ''));
if ($telefone === null) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'erro' => 'Número inválido.']));
}

// Tempo limite (min) e banda (Mbps): mesmas regras de set_limite.php / set_banda.php.
// Vazio/null = sem limite. Banda 0 = sem limite; teto 10000.
$limRaw = $in['limite'] ?? '';
$limite = ($limRaw === '' || $limRaw === null) ? null : max(0, (int) $limRaw);
$bRaw   = $in['banda'] ?? '';
$banda  = ($bRaw === '' || $bRaw === null) ? null : max(0, (int) $bRaw);
if ($banda === 0) {
    $banda = null;
}
if ($banda !== null) {
    $banda = min($banda, 10000);
}

// Confirma que o lead pertence ao comprador (admin pode qualquer um).
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

// Número já pertence a OUTRO lead deste roteador? Oferece/executa a MESCLA:
// as conexões e contagens dos dois viram um lead só (sobrevive o dono do número).
$q2 = db()->prepare('SELECT id FROM leads WHERE roteador = ? AND telefone = ? AND id != ? LIMIT 1');
$q2->execute([$row['roteador'], $telefone, $id]);
$outroId = $q2->fetchColumn();

if ($outroId !== false) {
    if (empty($in['mesclar'])) {
        // 1ª tentativa: o painel confirma com o usuário e reenvia com mesclar=1.
        http_response_code(409);
        exit(json_encode(['ok' => false, 'mesclar' => true, 'erro' => 'Já existe um lead com esse número. Mesclar os dois?']));
    }
    $alvo = (int) $outroId;
    $qq = db()->prepare('SELECT * FROM leads WHERE id IN (?, ?)');
    $qq->execute([$id, $alvo]);
    $mapa = [];
    foreach ($qq->fetchAll() as $r2) {
        $mapa[(int) $r2['id']] = $r2;
    }
    $a = $mapa[$id];
    $b = $mapa[$alvo];
    // Estado atual (mac/ip/online/tempos) = o do lead com conexão mais recente.
    $rec = (strtotime((string) $a['conectado_em']) > strtotime((string) $b['conectado_em'])) ? $a : $b;
    // Primeira conexão = a mais antiga entre os dois.
    $primeira = null;
    foreach ([$a['primeira_conexao'], $b['primeira_conexao'], $a['conectado_em'], $b['conectado_em']] as $t) {
        if ($t !== null && ($primeira === null || $t < $primeira)) {
            $primeira = $t;
        }
    }
    // Nome: o digitado agora; vazio -> mantém o que algum dos dois já tinha.
    $nomeFinal = $nome !== null ? $nome : (($b['nome'] ?? null) !== null && $b['nome'] !== '' ? $b['nome'] : ($a['nome'] ?? null));
    try {
        db()->beginTransaction();
        db()->prepare('UPDATE conexoes SET lead_id = ? WHERE lead_id = ?')->execute([$alvo, $id]);
        db()->prepare(
            'UPDATE leads SET nome = ?, mac = ?, ip = ?, dispositivo = ?, conectado_em = ?, online = ?,
                    visto_em = ?, desconectado_em = ?, segundos_conectado = ?, total_conexoes = ?, primeira_conexao = ?,
                    tempo_limite_min = ?, banda_limite = ?
              WHERE id = ?'
        )->execute([
            $nomeFinal, $rec['mac'], $rec['ip'], $rec['dispositivo'], $rec['conectado_em'], (int) $rec['online'],
            $rec['visto_em'], $rec['desconectado_em'], $rec['segundos_conectado'],
            (int) $a['total_conexoes'] + (int) $b['total_conexoes'], $primeira, $limite, $banda, $alvo,
        ]);
        db()->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        http_response_code(500);
        exit(json_encode(['ok' => false, 'erro' => 'Falha ao mesclar. Tente de novo.']));
    }
    exit(json_encode(['ok' => true, 'mesclado' => true, 'id' => $alvo, 'id_removido' => $id, 'nome' => $nomeFinal, 'telefone' => $telefone, 'limite' => $limite, 'banda' => $banda]));
}

try {
    $u = db()->prepare('UPDATE leads SET nome = ?, telefone = ?, tempo_limite_min = ?, banda_limite = ? WHERE id = ?');
    $u->execute([$nome, $telefone, $limite, $banda, $id]);
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'erro' => 'Falha ao salvar. Banco atualizado? Rode sql/migracao_nome.sql.']));
}

echo json_encode(['ok' => true, 'id' => $id, 'nome' => $nome, 'telefone' => $telefone, 'limite' => $limite, 'banda' => $banda]);
