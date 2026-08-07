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

// Dias de visita por cliente: em quantos dias distintos do período o número
// conectou. Um item por lead.
if ($tipo === 'clientes_dias') {
    $itens = [];
    if ($lista) {
        try {
            $ph = implode(',', array_fill(0, count($lista), '?'));
            $q = db()->prepare(
                "SELECT l.telefone, l.nome, COUNT(DISTINCT DATE(c.conectado_em)) AS v
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

// Tempo de conexão por cliente: segundos conectados DENTRO do período.
//
// Antes era SUM(c.segundos) filtrando por conectado_em, e errava dos dois
// lados: `segundos` fica NULL enquanto a sessão está aberta (só o status.php
// preenche, ao fechar) e SUM descarta NULL, então quem está conectado agora
// aparecia com zero; e a sessão que começa 23h do último dia entrava INTEIRA,
// jogando para dentro do período horas que aconteceram fora dele.
// Agora usa o mesmo recorte da grade semanal: pega as sessões que encostam no
// período, corta nas bordas e funde as sobreposições.
if ($tipo === 'clientes_tempo') {
    $pIni  = strtotime($inicio . ' 00:00:00');
    $pFim  = strtotime($fim . ' +1 day 00:00:00');
    $agora = strtotime(db_now());
    $itens = [];
    if ($lista) {
        try {
            $porLead = [];
            foreach (sessoes_janela($lista, $pIni, $pFim) as $r) {
                $iv = conexao_intervalo($r['conectado_em'], $r['fim'], $agora);
                if ($iv === null) { continue; }   // duração desconhecida: fora
                $id = (int) $r['lead_id'];
                if (!isset($porLead[$id])) {
                    $porLead[$id] = [
                        'telefone' => (string) $r['telefone'],
                        'nome'     => ($r['nome'] !== null && $r['nome'] !== '') ? (string) $r['nome'] : null,
                        'valor'    => 0,
                        'iv'       => [],
                    ];
                }
                $porLead[$id]['iv'][] = $iv;
            }
            foreach ($porLead as &$le) {
                $le['valor'] = intervalos_total($le['iv'], $pIni, $pFim);
                unset($le['iv']);
            }
            unset($le);
            // Zero fora: a sessão pode encostar na borda do período sem ter um
            // segundo dentro dele, e o painel já esconde a linha — só o total
            // ficaria contando um cliente que ninguém vê.
            $porLead = array_filter($porLead, function ($x) { return $x['valor'] > 0; });
            usort($porLead, function ($a, $b) { return $b['valor'] <=> $a['valor']; });
            $itens = array_slice($porLead, 0, 500);
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
        'lista'  => array_values($itens),
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
            // 1) Recorta cada sessao na semana e guarda os intervalos por cliente.
            //    sessoes_janela (inc/util.php) traz as que ENCOSTAM na semana,
            //    nao so as que comecam nela.
            $porLead = [];
            foreach (sessoes_janela($lista, $semIni, $semFim) as $r) {
                // conexao_intervalo devolve null quando a duracao e desconhecida
                // (conexao que o polling nunca viu) — ver inc/util.php.
                $iv = conexao_intervalo($r['conectado_em'], $r['fim'], $agora);
                if ($iv === null) { continue; }
                // Nomes proprios: $fim aqui e a data do periodo global, e
                // sobrescreve-la deixava uma armadilha para quem mexesse depois.
                $ivIni = max($iv[0], $semIni);
                $ivFim = min($iv[1], $semFim);
                if ($ivFim <= $ivIni) { continue; }
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
                $porLead[$id]['iv'][] = [$ivIni, $ivFim];
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
//     valor = dias sem vir (até hoje).
//
//     VISITA = dia distinto, não linha de `conexoes`. Com COUNT(c.id) uma única
//     tarde em que o cliente reconectou 3x (sessão expirada, troca de aparelho,
//     sinal oscilando) passava no filtro "mínimo de 3 visitas" — a lista de
//     reconquista enchia de quem nunca foi recorrente. É também o critério que o
//     api/alertas.php já usava, então os dois discordavam sobre os mesmos dados. ---
if ($tipo === 'sumidos') {
    $diasMin = max(1, (int) ($_GET['dias'] ?? 7));
    $visMin  = max(1, (int) ($_GET['visitas'] ?? 3));
    $itens = [];
    if ($lista) {
        try {
            $q = db()->prepare(
                "SELECT l.telefone, l.nome,
                        COUNT(DISTINCT DATE(c.conectado_em)) AS visitas,
                        MAX(c.conectado_em) AS ultima
                   FROM leads l JOIN conexoes c ON c.lead_id = l.id
                  WHERE l.roteador IN ($ph)
                  GROUP BY l.id, l.telefone, l.nome
                 HAVING COUNT(DISTINCT DATE(c.conectado_em)) >= ?
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

// --- Clientes mais frequentes: top 20 por DIAS de visita no período.
//     Contava COUNT(*) de conexões, e quem tinha aparelho de sinal instável
//     subia no ranking só por reconectar — frequência é voltar mais vezes, não
//     reconectar mais vezes. Mesmo critério de "Dias de visita por cliente",
//     para os dois relatórios concordarem. ---
if ($tipo === 'ranking') {
    $itens = [];
    if ($lista) {
        try {
            $q = db()->prepare(
                "SELECT l.telefone, l.nome,
                        COUNT(DISTINCT DATE(c.conectado_em)) AS v,
                        MAX(c.conectado_em) AS ultima
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
    // ocorrencias: quantas vezes cada dia da semana caiu no período. Sem isso a
    // grade mente quando o período não é múltiplo de 7 — num período de 8 dias
    // a linha de segunda soma duas segundas contra uma dos outros dias, e sai
    // com o dobro de calor sem que o movimento tenha mudado. Quem divide é o
    // relatorio.js, que assim mostra a média por dia e mantém o total no
    // tooltip.
    $sai(['total' => $total, 'grade' => $grade, 'ocorrencias' => ocorrencias_dow($inicio, $fim)]);
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
                    // marco_mes, e nao strtotime("+N month"): o PHP transborda
                    // quando o dia nao existe no mes de destino (31/08 +3m
                    // virava 01/12 em vez de 30/11) — ver inc/util.php.
                    $marco = marco_mes($p, $m);
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
                    $clientes[6][] = [
                        'telefone' => $le['telefone'],
                        'nome'     => $le['nome'],
                        'media'    => null,
                        'data'     => date('Y-m-d', $dias[0]),
                    ];
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
                $clientes[$fx][] = [
                    'telefone' => $le['telefone'],
                    'nome'     => $le['nome'],
                    'media'    => round($media, 1),
                ];
            }
            // Ordena PRIMEIRO, corta depois. Ao contrário — como era — os 200
            // guardados eram os de menor lead_id (os mais antigos cadastrados),
            // uma amostra arbitrária que a ordenação só arrumava entre si.
            foreach ($clientes as $fx => &$cf) {
                if ($fx === 6) {
                    // Sem retorno: visita única mais recente primeiro.
                    usort($cf, function ($a, $b) { return strcmp($b['data'], $a['data']); });
                } else {
                    usort($cf, function ($a, $b) { return $a['media'] <=> $b['media']; });
                }
                $cf = array_slice($cf, 0, 200);
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
//
// ocorrencias só no relatório por dia da semana: cada hora do dia acontece uma
// vez por dia em qualquer período, então "hora" não tem viés a corrigir — já os
// dias da semana só se repetem por igual quando o período é múltiplo de 7.
$saida = [
    'ok'      => true,
    'tipo'    => $tipo,
    'inicio'  => $inicio,
    'fim'     => $fim,
    'total'   => $total,
    'buckets' => $buckets,
];
if ($tipo === 'semana') {
    $saida['ocorrencias'] = ocorrencias_dow($inicio, $fim);
}
echo json_encode($saida);
