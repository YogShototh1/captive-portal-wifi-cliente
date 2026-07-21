<?php
// Relatórios de acessos (JSON): agrega as CONEXÕES do período por dia da
// semana (tipo=semana) ou por hora do dia (tipo=hora).
// Autenticado por sessão; isolamento igual ao leads_online.php:
//   cliente: ?roteador= vazio -> TODOS os da conta; identity da conta -> só ele.
//   admin:   ?roteador=X -> só ele; ?cliente_id=N -> todos os do cliente.
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$comprador = comprador_logado();
if (!$comprador) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'erro' => 'nao autenticado']));
}

$isAdmin = (int) $comprador['is_admin'] === 1;
$pedido  = trim((string) ($_GET['roteador'] ?? ''));

if ($isAdmin) {
    $cid   = (int) ($_GET['cliente_id'] ?? 0);
    $lista = $cid > 0 ? roteadores_conta($cid) : ($pedido !== '' ? [$pedido] : []);
} else {
    $lista = roteadores_conta((int) $comprador['id']);
    if ($pedido !== '' && in_array($pedido, $lista, true)) {
        $lista = [$pedido];
    }
}

$tipo = (string) ($_GET['tipo'] ?? 'semana');
if (!in_array($tipo, ['semana', 'hora', 'clientes_dias', 'clientes_tempo'], true)) {
    $tipo = 'semana';
}

// Datas no formato YYYY-MM-DD; padrão = últimos 7 dias. Início > fim? Inverte.
$hoje   = date('Y-m-d');
$inicio = (string) ($_GET['inicio'] ?? '');
$fim    = (string) ($_GET['fim'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)) { $inicio = date('Y-m-d', strtotime('-6 days')); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim))    { $fim = $hoje; }
if ($inicio > $fim) { $t = $inicio; $inicio = $fim; $fim = $t; }

// Relatórios por CLIENTE (lista, não gráfico de baldes): um item por lead com
//   clientes_dias  -> em quantos dias distintos do período o número conectou
//   clientes_tempo -> soma do tempo (segundos) de todas as sessões do período
if ($tipo === 'clientes_dias' || $tipo === 'clientes_tempo') {
    $itens = [];
    if ($lista) {
        try {
            $agg = $tipo === 'clientes_dias'
                ? 'COUNT(DISTINCT DATE(c.conectado_em))'
                : 'COALESCE(SUM(c.segundos), 0)';
            $ph = implode(',', array_fill(0, count($lista), '?'));
            $q = db()->prepare(
                "SELECT l.telefone, l.nome, $agg AS v
                   FROM conexoes c JOIN leads l ON l.id = c.lead_id
                  WHERE l.roteador IN ($ph)
                    AND c.conectado_em >= ? AND c.conectado_em < DATE_ADD(?, INTERVAL 1 DAY)
                  GROUP BY c.lead_id, l.telefone, l.nome
                  ORDER BY v DESC, l.telefone
                  LIMIT 500"
            );
            $q->execute(array_merge($lista, [$inicio, $fim]));
            foreach ($q->fetchAll() as $r) {
                $itens[] = [
                    'telefone' => (string) $r['telefone'],
                    'nome'     => ($r['nome'] !== null && $r['nome'] !== '') ? (string) $r['nome'] : null,
                    'valor'    => (int) $r['v'],
                ];
            }
        } catch (Throwable $e) {
            http_response_code(500);
            exit(json_encode(['ok' => false, 'erro' => 'falha ao gerar o relatorio']));
        }
    }
    exit(json_encode([
        'ok'     => true,
        'tipo'   => $tipo,
        'inicio' => $inicio,
        'fim'    => $fim,
        'total'  => count($itens),
        'lista'  => $itens,
    ]));
}

$buckets = [];
$total   = 0;
if ($lista) {
    try {
        $expr = $tipo === 'hora' ? 'HOUR(c.conectado_em)' : 'DAYOFWEEK(c.conectado_em)';
        $ph   = implode(',', array_fill(0, count($lista), '?'));
        $q = db()->prepare(
            "SELECT $expr AS b, COUNT(*) AS n
               FROM conexoes c JOIN leads l ON l.id = c.lead_id
              WHERE l.roteador IN ($ph)
                AND c.conectado_em >= ? AND c.conectado_em < DATE_ADD(?, INTERVAL 1 DAY)
              GROUP BY b"
        );
        $q->execute(array_merge($lista, [$inicio, $fim]));
        foreach ($q->fetchAll() as $r) {
            $buckets[(int) $r['b']] = (int) $r['n'];
            $total += (int) $r['n'];
        }
    } catch (Throwable $e) {
        http_response_code(500);
        exit(json_encode(['ok' => false, 'erro' => 'falha ao gerar o relatorio']));
    }
}

// buckets: semana = chaves 1..7 (1=domingo, padrão do MySQL); hora = 0..23.
echo json_encode([
    'ok'      => true,
    'tipo'    => $tipo,
    'inicio'  => $inicio,
    'fim'     => $fim,
    'total'   => $total,
    'buckets' => $buckets,
]);
