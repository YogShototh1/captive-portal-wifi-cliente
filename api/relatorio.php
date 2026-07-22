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
if (!in_array($tipo, ['semana', 'hora', 'clientes_dias', 'clientes_tempo',
                      'sumidos', 'ranking', 'mapa', 'aniversario', 'intervalo'], true)) {
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

$ph  = $lista ? implode(',', array_fill(0, count($lista), '?')) : '';
$sai = function (array $extra) use ($tipo, $inicio, $fim) {
    exit(json_encode(['ok' => true, 'tipo' => $tipo, 'inicio' => $inicio, 'fim' => $fim] + $extra));
};

// --- Clientes sumidos: sem datas — "sumido há N+ dias" e "mínimo de M visitas".
//     valor = dias sem vir (até hoje). ---
if ($tipo === 'sumidos') {
    $diasMin = max(1, (int) ($_GET['dias'] ?? 7));
    $visMin  = max(1, (int) ($_GET['visitas'] ?? 3));
    $itens = [];
    if ($lista) {
        try {
            $q = db()->prepare(
                "SELECT l.telefone, l.nome, COUNT(c.id) AS visitas, MAX(c.conectado_em) AS ultima
                   FROM leads l JOIN conexoes c ON c.lead_id = l.id
                  WHERE l.roteador IN ($ph)
                  GROUP BY l.id, l.telefone, l.nome
                 HAVING COUNT(c.id) >= ?
                    AND MAX(c.conectado_em) < DATE_SUB(NOW(), INTERVAL ? DAY)
                  ORDER BY ultima ASC
                  LIMIT 500"
            );
            $q->execute(array_merge($lista, [$visMin, $diasMin]));
            foreach ($q->fetchAll() as $r) {
                $itens[] = [
                    'telefone' => (string) $r['telefone'],
                    'nome'     => ($r['nome'] !== null && $r['nome'] !== '') ? (string) $r['nome'] : null,
                    'visitas'  => (int) $r['visitas'],
                    'ultima'   => substr((string) $r['ultima'], 0, 10),
                    'dias'     => max(0, (int) floor((strtotime($hoje) - strtotime(substr((string) $r['ultima'], 0, 10))) / 86400)),
                ];
            }
        } catch (Throwable $e) {
            http_response_code(500);
            exit(json_encode(['ok' => false, 'erro' => 'falha ao gerar o relatorio']));
        }
    }
    $sai(['total' => count($itens), 'lista' => $itens, 'dias' => $diasMin, 'visitas' => $visMin]);
}

// --- Ranking de fidelidade: top 20 por acessos (conexões) no período. ---
if ($tipo === 'ranking') {
    $itens = [];
    if ($lista) {
        try {
            $q = db()->prepare(
                "SELECT l.telefone, l.nome, COUNT(*) AS v, MAX(c.conectado_em) AS ultima
                   FROM conexoes c JOIN leads l ON l.id = c.lead_id
                  WHERE l.roteador IN ($ph)
                    AND c.conectado_em >= ? AND c.conectado_em < DATE_ADD(?, INTERVAL 1 DAY)
                  GROUP BY c.lead_id, l.telefone, l.nome
                  ORDER BY v DESC, l.telefone
                  LIMIT 20"
            );
            $q->execute(array_merge($lista, [$inicio, $fim]));
            foreach ($q->fetchAll() as $r) {
                $itens[] = [
                    'telefone' => (string) $r['telefone'],
                    'nome'     => ($r['nome'] !== null && $r['nome'] !== '') ? (string) $r['nome'] : null,
                    'valor'    => (int) $r['v'],
                    'ultima'   => substr((string) $r['ultima'], 0, 10),
                ];
            }
        } catch (Throwable $e) {
            http_response_code(500);
            exit(json_encode(['ok' => false, 'erro' => 'falha ao gerar o relatorio']));
        }
    }
    $sai(['total' => count($itens), 'lista' => $itens]);
}

// --- Mapa semana × hora: conexões do período por (dia da semana, hora). ---
if ($tipo === 'mapa') {
    $grade = []; // "d-h" => n (d = DAYOFWEEK 1..7, 1=domingo; h = 0..23)
    $total = 0;
    if ($lista) {
        try {
            $q = db()->prepare(
                "SELECT DAYOFWEEK(c.conectado_em) AS d, HOUR(c.conectado_em) AS h, COUNT(*) AS n
                   FROM conexoes c JOIN leads l ON l.id = c.lead_id
                  WHERE l.roteador IN ($ph)
                    AND c.conectado_em >= ? AND c.conectado_em < DATE_ADD(?, INTERVAL 1 DAY)
                  GROUP BY d, h"
            );
            $q->execute(array_merge($lista, [$inicio, $fim]));
            foreach ($q->fetchAll() as $r) {
                $grade[$r['d'] . '-' . $r['h']] = (int) $r['n'];
                $total += (int) $r['n'];
            }
        } catch (Throwable $e) {
            http_response_code(500);
            exit(json_encode(['ok' => false, 'erro' => 'falha ao gerar o relatorio']));
        }
    }
    $sai(['total' => $total, 'grade' => $grade]);
}

// --- Aniversários: marcos de 3/6/12 meses da 1ª conexão nos PRÓXIMOS N dias
//     (sem datas — o útil é saber quem está fazendo "aniversário" agora/em breve). ---
if ($tipo === 'aniversario') {
    $prox   = max(1, (int) ($_GET['proximos'] ?? 30));
    $inicio = $hoje;
    $fim    = date('Y-m-d', strtotime($hoje . " +$prox day"));
    $itens = [];
    if ($lista) {
        try {
            $q = db()->prepare(
                "SELECT l.telefone, l.nome,
                        COALESCE(l.primeira_conexao, (SELECT MIN(c2.conectado_em) FROM conexoes c2 WHERE c2.lead_id = l.id), l.conectado_em) AS p
                   FROM leads l WHERE l.roteador IN ($ph)"
            );
            $q->execute($lista);
            foreach ($q->fetchAll() as $r) {
                if ($r['p'] === null) { continue; }
                $p = substr((string) $r['p'], 0, 10);
                foreach ([3, 6, 12] as $m) {
                    $marco = date('Y-m-d', strtotime($p . " +$m month"));
                    if ($marco >= $inicio && $marco <= $fim) {
                        $itens[] = [
                            'telefone' => (string) $r['telefone'],
                            'nome'     => ($r['nome'] !== null && $r['nome'] !== '') ? (string) $r['nome'] : null,
                            'meses'    => $m,
                            'data'     => $marco,
                        ];
                    }
                }
            }
            usort($itens, function ($a, $b) { return strcmp($a['data'], $b['data']); });
            $itens = array_slice($itens, 0, 500);
        } catch (Throwable $e) {
            http_response_code(500);
            exit(json_encode(['ok' => false, 'erro' => 'falha ao gerar o relatorio']));
        }
    }
    $sai(['total' => count($itens), 'lista' => $itens, 'proximos' => $prox]);
}

// --- Intervalo de retorno: SEM inputs — histórico completo (do 1º lead até hoje).
//     Por cliente com >=2 dias de visita: média de dias entre visitas
//     consecutivas; distribuição em faixas + mediana. ---
if ($tipo === 'intervalo') {
    $faixas   = [0, 0, 0, 0, 0, 0, 0]; // 1-2 / 3-4 / 5-7 / 8-14 / 15-30 / 31+ / sem retorno (1 visita só)
    $clientes = [[], [], [], [], [], [], []]; // quem caiu em cada faixa (p/ expandir no painel)
    $medias   = [];
    if ($lista) {
        try {
            $q = db()->prepare(
                "SELECT c.lead_id, l.telefone, l.nome, DATE(c.conectado_em) AS d
                   FROM conexoes c JOIN leads l ON l.id = c.lead_id
                  WHERE l.roteador IN ($ph)
                  GROUP BY c.lead_id, l.telefone, l.nome, d
                  ORDER BY c.lead_id, d"
            );
            $q->execute($lista);
            $porLead = [];
            foreach ($q->fetchAll() as $r) {
                $id = (int) $r['lead_id'];
                if (!isset($porLead[$id])) {
                    $porLead[$id] = [
                        'telefone' => (string) $r['telefone'],
                        'nome'     => ($r['nome'] !== null && $r['nome'] !== '') ? (string) $r['nome'] : null,
                        'dias'     => [],
                    ];
                }
                $porLead[$id]['dias'][] = strtotime((string) $r['d']);
            }
            foreach ($porLead as $le) {
                $dias = $le['dias'];
                if (count($dias) < 2) {
                    // Sem retorno: veio 1 dia só e nunca voltou.
                    $faixas[6]++;
                    if (count($clientes[6]) < 200) {
                        $clientes[6][] = [
                            'telefone' => $le['telefone'],
                            'nome'     => $le['nome'],
                            'media'    => null,
                            'data'     => date('Y-m-d', $dias[0]),
                        ];
                    }
                    continue;
                }
                $soma = 0;
                for ($i = 1; $i < count($dias); $i++) {
                    $soma += ($dias[$i] - $dias[$i - 1]) / 86400;
                }
                $media = $soma / (count($dias) - 1);
                $medias[] = $media;
                if     ($media <= 2)  { $fx = 0; }
                elseif ($media <= 4)  { $fx = 1; }
                elseif ($media <= 7)  { $fx = 2; }
                elseif ($media <= 14) { $fx = 3; }
                elseif ($media <= 30) { $fx = 4; }
                else                  { $fx = 5; }
                $faixas[$fx]++;
                if (count($clientes[$fx]) < 200) {
                    $clientes[$fx][] = [
                        'telefone' => $le['telefone'],
                        'nome'     => $le['nome'],
                        'media'    => round($media, 1),
                    ];
                }
            }
            foreach ($clientes as $fx => &$cf) {
                if ($fx === 6) {
                    // Sem retorno: visita única mais recente primeiro.
                    usort($cf, function ($a, $b) { return strcmp($b['data'], $a['data']); });
                } else {
                    usort($cf, function ($a, $b) { return $a['media'] <=> $b['media']; });
                }
            }
            unset($cf);
        } catch (Throwable $e) {
            http_response_code(500);
            exit(json_encode(['ok' => false, 'erro' => 'falha ao gerar o relatorio']));
        }
    }
    $mediana = 0;
    if ($medias) {
        sort($medias);
        $n = count($medias);
        $mediana = round($n % 2 ? $medias[intdiv($n, 2)] : ($medias[$n / 2 - 1] + $medias[$n / 2]) / 2, 1);
    }
    $sai(['total' => count($medias) + $faixas[6], 'faixas' => $faixas, 'clientes' => $clientes, 'mediana' => $mediana]);
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
