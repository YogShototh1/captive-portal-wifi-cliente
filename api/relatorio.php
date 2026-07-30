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
                      'sumidos', 'ranking', 'mapa', 'aniversario', 'intervalo',
                      'grade_semana'], true)) {
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

// --- Tempo por cliente na semana: grade cliente x dia da semana com o tempo
//     conectado. Nao usa o par de datas — anda de semana em semana pelo
//     parametro `semana` (0 = semana atual, 1 = a anterior, ...). A semana vai
//     de domingo a sabado, igual ao DAYOFWEEK do MySQL e ao mapa de calor. ---
if ($tipo === 'grade_semana') {
    $off = max(0, (int) ($_GET['semana'] ?? 0));
    $dom = date('Y-m-d', strtotime($hoje . ' -' . ((int) date('w') + 7 * $off) . ' day'));
    $sab = date('Y-m-d', strtotime($dom . ' +6 day'));

    // Limites da semana em timestamp; o fim e exclusivo (domingo 00:00 seguinte).
    $semIni = strtotime($dom . ' 00:00:00');
    $semFim = strtotime($dom . ' +7 day 00:00:00');
    $agora  = strtotime(db_now());
    // Comeco de cada um dos 7 dias (+ o limite final) — por data, nao somando
    // 86400, p/ nao errar se algum dia da semana nao tiver 24h.
    $lim = [];
    for ($k = 0; $k <= 7; $k++) { $lim[$k] = strtotime($dom . ' +' . $k . ' day 00:00:00'); }

    $itens = [];
    $primeira = null;
    if ($lista) {
        try {
            // Sessoes que ENCOSTAM na semana (nao so as que comecam nela): uma
            // sessao de segunda a quarta precisa entrar na conta dos tres dias.
            //
            // O fim e o instante gravado (conectado_em + segundos) ou, se a
            // sessao segue aberta, o ultimo instante CONFIRMADO pelo roteador
            // (visto_em). Nunca NOW(): o lead.php abre a conexao com segundos e
            // visto_em nulos e o status.php so fecha as que ja foram vistas no
            // polling, entao conexao que nunca apareceu em /ip/hotspot/active
            // fica orfa para sempre — com NOW() cada orfa virava "conectado
            // desde o login ate agora" e enchia a semana inteira de 24h.
            // Sem os dois campos a duracao e desconhecida: o proprio WHERE a
            // descarta, porque comparacao com NULL nao e verdadeira.
            $sqlFim = "COALESCE(DATE_ADD(c.conectado_em, INTERVAL c.segundos SECOND), c.visto_em)";
            $sel = "SELECT c.lead_id, l.telefone, l.nome, c.conectado_em, $sqlFim AS fim
                      FROM conexoes c JOIN leads l ON l.id = c.lead_id
                     WHERE l.roteador IN ($ph)
                       AND c.conectado_em < ? AND $sqlFim >= ?";
            try {
                $q = db()->prepare($sel);
                $q->execute(array_merge($lista, [date('Y-m-d H:i:s', $semFim), date('Y-m-d H:i:s', $semIni)]));
            } catch (Throwable $e) {
                // Banco antigo, sem conexoes.visto_em (o status.php cria na 1a
                // rodada): so as sessoes ja fechadas entram.
                $q = db()->prepare(str_replace(', c.visto_em', '', $sel));
                $q->execute(array_merge($lista, [date('Y-m-d H:i:s', $semFim), date('Y-m-d H:i:s', $semIni)]));
            }

            // 1) Recorta cada sessao na semana e guarda os intervalos por cliente.
            $porLead = [];
            foreach ($q->fetchAll() as $r) {
                // conexao_intervalo devolve null quando a duracao e desconhecida
                // (conexao que o polling nunca viu) — ver inc/util.php.
                $iv = conexao_intervalo($r['conectado_em'], $r['fim'], $agora);
                if ($iv === null) { continue; }
                $ini = max($iv[0], $semIni);
                $fim = min($iv[1], $semFim);
                if ($fim <= $ini) { continue; }
                $id = (int) $r['lead_id'];
                if (!isset($porLead[$id])) {
                    $porLead[$id] = [
                        'telefone' => (string) $r['telefone'],
                        'nome'     => ($r['nome'] !== null && $r['nome'] !== '') ? (string) $r['nome'] : null,
                        'dias'     => [0, 0, 0, 0, 0, 0, 0],   // indice 0 = domingo
                        'total'    => 0,
                        'iv'       => [],
                    ];
                }
                $porLead[$id]['iv'][] = [$ini, $fim];
            }

            // 2) Junta o que se sobrepoe e reparte pelos dias (semana_reparte,
            //    em inc/util.php — testada em tools/teste_semana.php).
            foreach ($porLead as &$le) {
                $le['dias']  = semana_reparte($le['iv'], $lim);
                $le['total'] = array_sum($le['dias']);
                unset($le['iv']);
            }
            unset($le);
            // Quem passou mais tempo conectado primeiro.
            usort($porLead, function ($a, $b) { return $b['total'] <=> $a['total']; });
            $itens = array_slice($porLead, 0, 500);

            // Ate onde a seta de voltar pode ir.
            $q2 = db()->prepare(
                "SELECT MIN(c.conectado_em) AS p FROM conexoes c JOIN leads l ON l.id = c.lead_id
                  WHERE l.roteador IN ($ph)"
            );
            $q2->execute($lista);
            $p = $q2->fetchColumn();
            if ($p) { $primeira = substr((string) $p, 0, 10); }
        } catch (Throwable $e) {
            http_response_code(500);
            exit(json_encode(['ok' => false, 'erro' => 'falha ao gerar o relatorio']));
        }
    }
    $tot = 0;
    foreach ($itens as $it) { $tot += $it['total']; }
    exit(json_encode([
        'ok'       => true,
        'tipo'     => $tipo,
        'inicio'   => $dom,
        'fim'      => $sab,
        'semana'   => $off,
        'total'    => $tot,          // segundos somados na semana
        'clientes' => count($itens),
        'lista'    => array_values($itens),
        'primeira' => $primeira,     // 1a conexao do roteador (null = sem dados)
    ]));
}

// --- Clientes sem retorno: sem datas — "sem vir há N+ dias" e "mínimo de M visitas".
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

// --- Clientes mais frequentes: top 20 por acessos (conexões) no período. ---
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

// --- Movimento por dia e hora: conexões do período por (dia da semana, hora). ---
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

// --- Marcos de relacionamento: 3/6/12 meses da 1ª conexão nos PRÓXIMOS N dias
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

// --- Intervalo entre visitas: SEM inputs — histórico completo (do 1º lead até hoje).
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
