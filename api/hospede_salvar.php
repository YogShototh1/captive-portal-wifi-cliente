<?php
// Cadastra ou edita um hóspede. Autenticado por sessão.
// Isolamento igual ao resto do painel: o roteador tem que ser da conta aberta
// (admin pode qualquer um dos roteadores do cliente que estiver vendo).
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

$id       = (int) ($in['id'] ?? 0);
$nome     = trim((string) ($in['nome'] ?? ''));
$quarto   = trim((string) ($in['quarto'] ?? ''));
$roteador = trim((string) ($in['roteador'] ?? ''));
$entrada  = trim((string) ($in['entrada'] ?? ''));
$hora     = trim((string) ($in['hora'] ?? '12:00'));
$dias     = (int) ($in['dias'] ?? 0);

// O número é a chave que o portal do hotspot valida: mesmas regras do lead.
$telefone = sanitiza_telefone((string) ($in['telefone'] ?? ''));
if ($telefone === null) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'erro' => 'Número de celular inválido.']));
}
if ($nome === '' || mb_strlen($nome) > 120) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'erro' => 'Informe o nome do hóspede (máx. 120).']));
}
if ($quarto === '' || mb_strlen($quarto) > 20) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'erro' => 'Informe o quarto (máx. 20).']));
}
if ($dias < 1 || $dias > 365) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'erro' => 'Diárias: de 1 a 365.']));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entrada) || strtotime($entrada) === false) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'erro' => 'Data de entrada inválida.']));
}
if (!preg_match('/^\d{2}:\d{2}$/', $hora)) {
    $hora = '12:00';
}

// Roteador: só os da conta. Conta com um roteador só não precisa mandar.
$isAdmin = (int) $comprador['is_admin'] === 1;
$cid     = $isAdmin ? (int) ($in['cliente_id'] ?? 0) : (int) $comprador['id'];
$lista   = roteadores_conta($cid > 0 ? $cid : (int) $comprador['id']);
if ($roteador === '' && count($lista) === 1) {
    $roteador = $lista[0];
}
if (!in_array($roteador, $lista, true)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'erro' => 'Escolha um roteador da sua conta.']));
}

$saida = hospede_saida($entrada, $dias, $hora);

try {
    if ($id > 0) {
        // Confirma que o hóspede é de um roteador da conta antes de mexer.
        $q = db()->prepare('SELECT roteador FROM hospedes WHERE id = ?');
        $q->execute([$id]);
        $atual = $q->fetchColumn();
        if ($atual === false || !in_array($atual, $lista, true)) {
            http_response_code(404);
            exit(json_encode(['ok' => false, 'erro' => 'Hóspede não encontrado.']));
        }
        $u = db()->prepare(
            'UPDATE hospedes SET roteador = ?, nome = ?, quarto = ?, telefone = ?,
                    entrada_em = ?, dias = ?, saida_em = ? WHERE id = ?'
        );
        $u->execute([$roteador, $nome, $quarto, $telefone, $entrada, $dias, $saida, $id]);
        exit(json_encode(['ok' => true, 'id' => $id]));
    }

    // Número repetido no mesmo roteador é o mesmo hóspede voltando: atualiza o
    // cadastro em vez de recusar (a UNIQUE (roteador, telefone) existe para
    // isso — o portal precisa de UMA resposta para cada número).
    $ins = db()->prepare(
        'INSERT INTO hospedes (roteador, nome, quarto, telefone, entrada_em, dias, saida_em)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE nome = VALUES(nome), quarto = VALUES(quarto),
                 entrada_em = VALUES(entrada_em), dias = VALUES(dias), saida_em = VALUES(saida_em)'
    );
    $ins->execute([$roteador, $nome, $quarto, $telefone, $entrada, $dias, $saida]);
    echo json_encode(['ok' => true, 'id' => (int) db()->lastInsertId()]);
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'erro' => 'Falha ao salvar. Banco atualizado? Rode tools/migrar_hospedagem.php.']));
}
