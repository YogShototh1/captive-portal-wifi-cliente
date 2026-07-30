<?php
// Estatísticas (JSON) para o gráfico de velas: série "conectados" (conexões)
// e "novos" (primeira conexão do número) por período:
//   filtro=hoje   -> por hora (00..23) do dia atual
//   filtro=semana -> por dia da semana ATUAL (segunda a domingo)
//   filtro=mes    -> por dia do mês ATUAL
//   filtro=ano    -> por mês do ano ATUAL
// Autenticado por sessão; isolamento igual ao leads_online.php.
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

$filtro = (string) ($_GET['filtro'] ?? 'hoje');
if (!in_array($filtro, ['hoje', 'semana', 'mes', 'ano'], true)) {
    $filtro = 'hoje';
}

// Chaves dos baldes + rótulos do eixo X + expressão/condição SQL por filtro.
// O placeholder {t} é trocado pela coluna de data de cada consulta.
$chaves = [];
$labels = [];
switch ($filtro) {
    case 'semana':
        $seg = strtotime('monday this week');
        for ($i = 0; $i < 7; $i++) {
            $chaves[] = date('Y-m-d', strtotime("+$i day", $seg));
            $labels[] = date('d/m', strtotime("+$i day", $seg));
        }
        $expr = 'DATE({t})';
        $cond = 'YEARWEEK({t}, 1) = YEARWEEK(CURDATE(), 1)';
        $sub  = 'HOUR({t})';
        break;
    case 'mes':
        $dias = (int) date('t');
        for ($d = 1; $d <= $dias; $d++) {
            $chaves[] = $d;
            $labels[] = sprintf('%02d/%s', $d, date('m'));
        }
        $expr = 'DAY({t})';
        $cond = 'YEAR({t}) = YEAR(CURDATE()) AND MONTH({t}) = MONTH(CURDATE())';
        $sub  = 'HOUR({t})';
        break;
    case 'ano':
        $nomes = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        for ($m = 1; $m <= 12; $m++) {
            $chaves[] = $m;
            $labels[] = $nomes[$m - 1];
        }
        $expr = 'MONTH({t})';
        $cond = 'YEAR({t}) = YEAR(CURDATE())';
        $sub  = 'DAY({t})';
        break;
    default: // hoje
        for ($h = 0; $h < 24; $h++) {
            $chaves[] = $h;
            $labels[] = sprintf('%02d:00', $h);
        }
        $expr = 'HOUR({t})';
        $cond = 'DATE({t}) = CURDATE()';
        $sub  = 'FLOOR(MINUTE({t}) / 10)';   // 6 fatias de 10 min dentro da hora
}

// Consulta agrupada -> array alinhado com $chaves (balde ausente = 0).
function est_serie(array $lista, string $sql, array $chaves): array
{
    $mapa = [];
    if ($lista) {
        $q = db()->prepare($sql);
        $q->execute($lista);
        foreach ($q->fetchAll() as $r) {
            $mapa[(string) $r['b']] = (int) $r['n'];
        }
    }
    $out = [];
    foreach ($chaves as $k) {
        $out[] = $mapa[(string) $k] ?? 0;
    }
    return $out;
}

// Velas (OHLC) de cada balde do eixo. O "preco" e a intensidade dentro do
// periodo: quantas pessoas por fatia (10 min dentro da hora, hora dentro do
// dia, dia dentro do mes). Abertura = primeira fatia com movimento, fechamento
// = ultima, maxima e minima = pico e vale entre elas. Balde parado vira vela
// zerada. Nada e inventado: tudo sai da mesma contagem da linha antiga.
function est_velas(array $lista, string $sql, array $chaves): array
{
    $porBalde = [];
    if ($lista) {
        $q = db()->prepare($sql);
        $q->execute($lista);
        foreach ($q->fetchAll() as $r) {
            $porBalde[(string) $r['b']][] = [(int) $r['s'], (int) $r['n']];
        }
    }
    $out = [];
    foreach ($chaves as $k) {
        $fatias = $porBalde[(string) $k] ?? [];
        if (!$fatias) {
            $out[] = [0, 0, 0, 0];
            continue;
        }
        usort($fatias, function ($a, $b) { return $a[0] <=> $b[0]; });   // ordem cronologica
        $vals = array_column($fatias, 1);
        $out[] = [$vals[0], max($vals), min($vals), $vals[count($vals) - 1]];
    }
    return $out;
}

try {
    $ph = $lista ? implode(',', array_fill(0, count($lista), '?')) : '';

    // Conectados: PESSOAS únicas por balde (DISTINCT lead_id) — quem reconecta
    // várias vezes no mesmo dia/hora conta uma vez só naquele ponto do gráfico.
    $sqlCon = "SELECT " . str_replace('{t}', 'c.conectado_em', $expr) . " AS b, COUNT(DISTINCT c.lead_id) AS n
                 FROM conexoes c JOIN leads l ON l.id = c.lead_id
                WHERE l.roteador IN ($ph) AND " . str_replace('{t}', 'c.conectado_em', $cond) . '
                GROUP BY b';
    // Novos clientes: números cuja PRIMEIRA conexão caiu no período.
    $sqlNov = "SELECT " . str_replace('{t}', 'l.primeira_conexao', $expr) . " AS b, COUNT(*) AS n
                 FROM leads l
                WHERE l.roteador IN ($ph) AND l.primeira_conexao IS NOT NULL
                  AND " . str_replace('{t}', 'l.primeira_conexao', $cond) . '
                GROUP BY b';

    // Mesmas contagens, agora quebradas por fatia dentro do balde.
    $sqlConV = "SELECT " . str_replace('{t}', 'c.conectado_em', $expr) . " AS b,
                       " . str_replace('{t}', 'c.conectado_em', $sub) . " AS s,
                       COUNT(DISTINCT c.lead_id) AS n
                  FROM conexoes c JOIN leads l ON l.id = c.lead_id
                 WHERE l.roteador IN ($ph) AND " . str_replace('{t}', 'c.conectado_em', $cond) . '
                 GROUP BY b, s';
    $sqlNovV = "SELECT " . str_replace('{t}', 'l.primeira_conexao', $expr) . " AS b,
                       " . str_replace('{t}', 'l.primeira_conexao', $sub) . " AS s,
                       COUNT(*) AS n
                  FROM leads l
                 WHERE l.roteador IN ($ph) AND l.primeira_conexao IS NOT NULL
                   AND " . str_replace('{t}', 'l.primeira_conexao', $cond) . '
                 GROUP BY b, s';

    $conectados = est_serie($lista, $sqlCon, $chaves);
    $novos      = est_serie($lista, $sqlNov, $chaves);

    echo json_encode([
        'ok'         => true,
        'filtro'     => $filtro,
        'labels'     => $labels,
        'conectados' => $conectados,
        'novos'      => $novos,
        'velas_conectados' => est_velas($lista, $sqlConV, $chaves),
        'velas_novos'      => est_velas($lista, $sqlNovV, $chaves),
        'total_conectados' => array_sum($conectados),
        'total_novos'      => array_sum($novos),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'falha ao gerar as estatisticas']);
}
