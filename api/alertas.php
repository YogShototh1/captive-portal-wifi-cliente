<?php
// Alertas do painel (JSON): calcula so os avisos que o comprador marcou.
// Autenticado por sessao; mesmo isolamento do relatorio.php —
//   cliente: ?roteador= vazio -> TODOS os da conta; identity da conta -> so ele.
//   admin:   ?roteador=X -> so ele; ?cliente_id=N -> todos os do cliente.
//
// ?detalhe=<id> devolve a lista de clientes de um aviso; sem isso, devolve os
// avisos marcados com a contagem de cada um.
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
    $conta = $cid > 0 ? $cid : (int) $comprador['id'];
} else {
    $lista = roteadores_conta((int) $comprador['id']);
    if ($pedido !== '' && in_array($pedido, $lista, true)) {
        $lista = [$pedido];
    }
    $conta = (int) $comprador['id'];
}

$marcadas = alertas_get($conta);
$cat      = alertas_catalogo();
$ph       = $lista ? implode(',', array_fill(0, count($lista), '?')) : '';
$LIMITE   = 300;   // teto da lista que volta para a tela

// Base comum: um registro por cliente com a primeira/ultima visita, em quantos
// dias distintos veio e em quantas semanas distintas. Tudo por CONEXAO, que e
// onde ficam as visitas de verdade.
//
// No $having so entram agregados (MAX/COUNT) ou os apelidos do SELECT (pri,
// ult, dias, semanas, ult_antes). Coluna crua de `leads` ali da erro: o MySQL
// nao resolve no HAVING o que nao esta no GROUP BY nem agregado.
function base_clientes(string $ph, array $lista, string $having, array $extra = []): array
{
    if (!$lista) {
        return [];
    }
    $sql =
        "SELECT l.id, l.telefone, l.nome,
                COALESCE(l.primeira_conexao, MIN(c.conectado_em)) AS pri,
                MAX(c.conectado_em) AS ult,
                COUNT(DISTINCT DATE(c.conectado_em)) AS dias,
                COUNT(DISTINCT YEARWEEK(c.conectado_em, 1)) AS semanas,
                MAX(CASE WHEN c.conectado_em < (NOW() - INTERVAL 7 DAY) THEN c.conectado_em END) AS ult_antes
           FROM leads l JOIN conexoes c ON c.lead_id = l.id
          WHERE l.roteador IN ($ph)
          GROUP BY l.id, l.telefone, l.nome
         HAVING $having";
    $q = db()->prepare($sql);
    $q->execute(array_merge($lista, $extra));
    return $q->fetchAll();
}

// Dias distintos de visita por cliente nos ultimos N dias (p/ achar sequencia).
function dias_por_cliente(string $ph, array $lista, int $janela): array
{
    if (!$lista) {
        return [];
    }
    $q = db()->prepare(
        "SELECT l.id, l.telefone, l.nome, DATE(c.conectado_em) AS d
           FROM leads l JOIN conexoes c ON c.lead_id = l.id
          WHERE l.roteador IN ($ph) AND c.conectado_em >= (NOW() - INTERVAL $janela DAY)
          GROUP BY l.id, l.telefone, l.nome, d
          ORDER BY l.id, d"
    );
    $q->execute($lista);
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $id = (int) $r['id'];
        if (!isset($out[$id])) {
            $out[$id] = ['telefone' => $r['telefone'], 'nome' => $r['nome'], 'dias' => []];
        }
        $out[$id]['dias'][] = (string) $r['d'];
    }
    return $out;
}

// Maior sequencia de dias consecutivos numa lista de datas ja ordenada.
function maior_sequencia(array $datas): int
{
    $melhor = 0;
    $atual  = 0;
    $ant    = null;
    foreach ($datas as $d) {
        $ts = strtotime($d);
        $atual = ($ant !== null && ($ts - $ant) === 86400) ? $atual + 1 : 1;
        if ($atual > $melhor) { $melhor = $atual; }
        $ant = $ts;
    }
    return $melhor;
}

function cliente_item(array $r, ?string $extra = null): array
{
    return [
        'telefone' => (string) $r['telefone'],
        'nome'     => ($r['nome'] !== null && $r['nome'] !== '') ? (string) $r['nome'] : null,
        'detalhe'  => $extra,
    ];
}

// Contagem de acessos (conexoes) numa janela de 7 dias que termina ha $desde dias.
function acessos_janela(string $ph, array $lista, int $desde): int
{
    if (!$lista) {
        return 0;
    }
    $q = db()->prepare(
        "SELECT COUNT(*) FROM conexoes c JOIN leads l ON l.id = c.lead_id
          WHERE l.roteador IN ($ph)
            AND c.conectado_em >= (NOW() - INTERVAL " . ($desde + 7) . " DAY)
            AND c.conectado_em <  (NOW() - INTERVAL $desde DAY)"
    );
    $q->execute($lista);
    return (int) $q->fetchColumn();
}

// Devolve o JSON tolerando texto que nao seja UTF-8 valido: sem o
// INVALID_UTF8_SUBSTITUTE, um unico nome gravado em latin-1 faz o json_encode
// devolver false e o painel recebe corpo vazio.
function responder(array $dados): void
{
    $j = json_encode($dados, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($j === false) {
        error_log('alertas: json_encode falhou — ' . json_last_error_msg());
        http_response_code(500);
        $j = json_encode(['ok' => false, 'erro' => 'falha ao montar a resposta']);
    }
    echo $j;
}

// Calcula UM aviso. Devolve ['n' => int, 'lista' => [], 'texto' => string].
function calcular(string $id, string $ph, array $lista): array
{
    switch ($id) {
        // Veio alguma vez e sumiu ha 7+ dias (o teto de 90 dias evita arrastar
        // para sempre quem passou uma vez no ano passado).
        case 'sem_vir_semana':
            $rs = base_clientes($ph, $lista,
                'MAX(c.conectado_em) <  (NOW() - INTERVAL 7 DAY)
             AND MAX(c.conectado_em) >= (NOW() - INTERVAL 90 DAY)');
            usort($rs, function ($a, $b) { return strcmp($b['ult'], $a['ult']); });
            $out = [];
            foreach ($rs as $r) {
                $d = (int) floor((time() - strtotime($r['ult'])) / 86400);
                $out[] = cliente_item($r, $d . ' dias sem vir');
            }
            return ['n' => count($out), 'lista' => $out,
                    'texto' => '{n} sem vir há uma semana ou mais'];

        // Vinham toda semana (4+ semanas distintas) e sumiram ha 7+ dias.
        case 'fieis_sumidos':
            $rs = base_clientes($ph, $lista,
                'COUNT(DISTINCT YEARWEEK(c.conectado_em, 1)) >= 4
             AND MAX(c.conectado_em) < (NOW() - INTERVAL 7 DAY)');
            usort($rs, function ($a, $b) { return strcmp($b['ult'], $a['ult']); });
            $out = [];
            foreach ($rs as $r) {
                $d = (int) floor((time() - strtotime($r['ult'])) / 86400);
                $out[] = cliente_item($r, $r['semanas'] . ' semanas de casa · ' . $d . ' dias sem vir');
            }
            return ['n' => count($out), 'lista' => $out,
                    'texto' => '{n} que vinham sempre e sumiram'];

        // Conheceu o Wi-Fi e nunca mais voltou.
        case 'visita_unica':
            $rs = base_clientes($ph, $lista,
                'COUNT(DISTINCT DATE(c.conectado_em)) = 1
             AND MAX(c.conectado_em) <  (NOW() - INTERVAL 7 DAY)
             AND MAX(c.conectado_em) >= (NOW() - INTERVAL 90 DAY)');
            usort($rs, function ($a, $b) { return strcmp($b['ult'], $a['ult']); });
            $out = [];
            foreach ($rs as $r) {
                $out[] = cliente_item($r, 'única visita em ' . date('d/m/Y', strtotime($r['ult'])));
            }
            return ['n' => count($out), 'lista' => $out,
                    'texto' => '{n} vieram uma vez e não voltaram'];

        // 5 dias seguidos ou mais no ultimo mes.
        case 'forte_recorrencia':
            $out = [];
            foreach (dias_por_cliente($ph, $lista, 30) as $c) {
                $seq = maior_sequencia($c['dias']);
                if ($seq >= 5) {
                    $out[] = cliente_item($c, $seq . ' dias seguidos');
                }
            }
            usort($out, function ($a, $b) { return (int) $b['detalhe'] <=> (int) $a['detalhe']; });
            return ['n' => count($out), 'lista' => $out,
                    'texto' => '{n} vieram 5 dias seguidos ou mais'];

        case 'novos_semana':
            // Primeira visita: a data gravada no lead quando existe. So cair no MIN
            // das conexoes ignoraria quem teve conexao antiga apagada por retencao.
            // No HAVING vai o APELIDO `pri`, nao a expressao: o MySQL so resolve
            // ali colunas do GROUP BY, agregados e apelidos do SELECT — repetir
            // l.primeira_conexao dava "Unknown column ... in 'having clause'".
            $rs = base_clientes($ph, $lista, 'pri >= (NOW() - INTERVAL 7 DAY)');
            usort($rs, function ($a, $b) { return strcmp($b['pri'], $a['pri']); });
            $out = [];
            foreach ($rs as $r) {
                $out[] = cliente_item($r, 'chegou em ' . date('d/m', strtotime($r['pri'])));
            }
            return ['n' => count($out), 'lista' => $out,
                    'texto' => '{n} apareceram pela primeira vez'];

        // Voltou nesta semana depois de 30+ dias sumido.
        case 'reativados':
            $rs = base_clientes($ph, $lista,
                'MAX(c.conectado_em) >= (NOW() - INTERVAL 7 DAY)
             AND MAX(CASE WHEN c.conectado_em < (NOW() - INTERVAL 7 DAY) THEN c.conectado_em END)
                 <= (NOW() - INTERVAL 37 DAY)');
            usort($rs, function ($a, $b) { return strcmp($b['ult'], $a['ult']); });
            $out = [];
            foreach ($rs as $r) {
                $p = (int) floor((strtotime($r['ult']) - strtotime($r['ult_antes'])) / 86400);
                $out[] = cliente_item($r, 'voltou depois de ' . $p . ' dias');
            }
            return ['n' => count($out), 'lista' => $out,
                    'texto' => '{n} voltaram depois de sumir'];

        case 'queda_semana':
        case 'alta_semana':
            $agora = acessos_janela($ph, $lista, 0);
            $antes = acessos_janela($ph, $lista, 7);
            if ($antes <= 0) {
                return ['n' => 0, 'lista' => [], 'texto' => ''];
            }
            $var = ($agora - $antes) * 100 / $antes;
            if ($id === 'queda_semana' && $var > -20) { return ['n' => 0, 'lista' => [], 'texto' => '']; }
            if ($id === 'alta_semana'  && $var <  20) { return ['n' => 0, 'lista' => [], 'texto' => '']; }
            return ['n' => (int) round(abs($var)), 'lista' => [],
                    'texto' => 'Acessos ' . ($var < 0 ? 'caíram' : 'subiram') . ' {n}% ante a semana passada'
                               . ' (' . $antes . ' → ' . $agora . ')'];

        case 'mikrotik_offline':
            $off = [];
            foreach ($lista as $r) {
                if (!mikrotik_online($r)) { $off[] = $r; }
            }
            if (!$off) {
                return ['n' => 0, 'lista' => [], 'texto' => ''];
            }
            return ['n' => count($off), 'lista' => [],
                    'texto' => count($off) === 1
                        ? 'O MikroTik ' . $off[0] . ' parou de reportar'
                        : '{n} MikroTiks pararam de reportar'];
    }
    return ['n' => 0, 'lista' => [], 'texto' => ''];
}

try {
    $detalhe = (string) ($_GET['detalhe'] ?? '');
    if ($detalhe !== '') {
        if (!isset($cat[$detalhe]) || empty($marcadas[$detalhe])) {
            http_response_code(404);
            exit(json_encode(['ok' => false, 'erro' => 'aviso nao disponivel']));
        }
        $r = calcular($detalhe, $ph, $lista);
        responder([
            'ok'     => true,
            'id'     => $detalhe,
            'titulo' => $cat[$detalhe][0],
            'total'  => $r['n'],
            'lista'  => array_slice($r['lista'], 0, $LIMITE),
        ]);
        exit;
    }

    $avisos = [];
    foreach ($cat as $id => $meta) {
        if (empty($marcadas[$id])) {
            continue;
        }
        $r = calcular($id, $ph, $lista);
        if ($r['n'] <= 0) {
            continue;   // sem ocorrencia = sem aviso na tela
        }
        $avisos[] = [
            'id'     => $id,
            'titulo' => $meta[0],
            'tom'    => $meta[2],
            'lista'  => (bool) $meta[3],
            'n'      => $r['n'],
            'texto'  => $r['texto'],
        ];
    }
    responder(['ok' => true, 'avisos' => $avisos, 'marcados' => array_sum($marcadas)]);
} catch (Throwable $e) {
    // Sem esta linha o erro some e a tela so diz "nao deu para carregar".
    error_log('alertas: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'falha ao calcular os alertas']);
}
