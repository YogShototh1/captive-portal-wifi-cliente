<?php
// Dashboard de um lead (?telefone=): hábitos de visita calculados do histórico
// de conexões. Autenticado por sessão; isolamento igual ao leads_online.php:
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

// Pousada ou loja. Vem do ROTEADOR, não da conta: o admin abre esta tela com
// ?roteador= e sem cliente_id, e roteador_modo() já resolve o caso do MikroTik
// compartilhado entre contas. Na pousada a grade lista HÓSPEDES (é lá que está
// o nome) e o resultado ganha o log de estadias no lugar dos dois donuts.
$hosp = false;
foreach ($lista as $r) {
    if (roteador_modo((string) $r) === 'hospedagem') { $hosp = true; break; }
}

// --- Lista de leads para a grade de escolha (?f=lista) -------------------
//
// Mesma autenticação e o mesmo isolamento do resto do arquivo — por isso mora
// aqui em vez de num endpoint novo: o bloco acima é tudo de que a lista
// precisa.
//
// Agrupado por TELEFONE: o mesmo número pode ter um lead por roteador numa
// conta com vários, e na tela é uma pessoa só (é assim que a consulta abaixo
// também trata). 30 por página, que é o que a grade mostra.
if ((string) ($_GET['f'] ?? '') === 'lista') {
    if (!$lista) {
        exit(json_encode(['ok' => true, 'leads' => [], 'pagina' => 1, 'paginas' => 1, 'total' => 0]));
    }
    $POR_PAG = 30;
    $pagina  = max(1, (int) ($_GET['pagina'] ?? 1));
    $ph      = implode(',', array_fill(0, count($lista), '?'));

    // Busca por número OU nome. Escapa % e _ para o que o admin digita não
    // virar curinga sem ele pedir.
    $q    = trim((string) ($_GET['q'] ?? ''));
    $cond = '';
    $args = $lista;
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_substr($q, 0, 40)) . '%';
        $cond = ' AND (telefone LIKE ? OR nome LIKE ?)';
        $args = array_merge($args, [$like, $like]);
    }

    // Pousada: a grade sai de `hospedes` (quem a recepção cadastrou), loja: de
    // `leads`. Os dois nomes são literais nossos, não entram por parâmetro.
    $tab  = $hosp ? 'hospedes' : 'leads';
    $data = $hosp ? 'entrada_em' : 'conectado_em';

    try {
        $qt = db()->prepare("SELECT COUNT(DISTINCT telefone) FROM $tab WHERE roteador IN ($ph)$cond");
        $qt->execute($args);
        $total   = (int) $qt->fetchColumn();
        $paginas = max(1, (int) ceil($total / $POR_PAG));
        $pagina  = min($paginas, $pagina);

        // MAX(nome): numa conta com vários roteadores só um dos registros costuma
        // ter o apelido; MAX ignora NULL e traz o que existir.
        $ql = db()->prepare(
            "SELECT telefone, MAX(nome) AS nome, MAX($data) AS ultima
               FROM $tab WHERE roteador IN ($ph)$cond
              GROUP BY telefone
              ORDER BY ultima DESC
              LIMIT $POR_PAG OFFSET " . (($pagina - 1) * $POR_PAG)
        );
        $ql->execute($args);
        $leads = $ql->fetchAll();
    } catch (Throwable $e) {
        exit(json_encode(['ok' => true, 'leads' => [], 'pagina' => 1, 'paginas' => 1, 'total' => 0]));
    }
    exit(json_encode([
        'ok' => true, 'leads' => $leads,
        'pagina' => $pagina, 'paginas' => $paginas, 'total' => $total,
    ]));
}

$tel = preg_replace('/\D+/', '', (string) ($_GET['telefone'] ?? ''));
if (!$lista || strlen($tel) < 10) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'erro' => 'Digite o número completo (com DDD).']));
}

try {
    // Leads deste número na conta (pode haver um por roteador — agrega todos).
    $ph = implode(',', array_fill(0, count($lista), '?'));
    $q = db()->prepare("SELECT id, nome FROM leads WHERE roteador IN ($ph) AND telefone = ?");
    $q->execute(array_merge($lista, [$tel]));
    $leadsDoTel = $q->fetchAll();
    if (!$leadsDoTel && !$hosp) {
        http_response_code(404);
        exit(json_encode(['ok' => false, 'erro' => 'Nenhum lead com esse número.']));
    }
    $ids  = array_map(function ($r) { return (int) $r['id']; }, $leadsDoTel);
    $nome = null;
    foreach ($leadsDoTel as $r) {
        if ($r['nome'] !== null && $r['nome'] !== '') { $nome = (string) $r['nome']; break; }
    }
    // Hóspede cadastrado que ainda não conectou não tem lead: a tela abre do
    // mesmo jeito, com o histórico de estadias e o resto zerado. O id 0 não
    // existe em `leads`, então as consultas de conexão devolvem vazio sozinhas.
    if ($hosp) {
        if (!$ids) { $ids = [0]; }
        if ($nome === null) {
            $qh = db()->prepare("SELECT nome FROM hospedes WHERE roteador IN ($ph) AND telefone = ? LIMIT 1");
            $qh->execute(array_merge($lista, [$tel]));
            $n = $qh->fetchColumn();
            if ($n !== false && $n !== '') { $nome = (string) $n; }
        }
    }
    $phi = implode(',', array_fill(0, count($ids), '?'));

    // Dias DISTINTOS de visita (mais recente primeiro) — base de tudo.
    $qd = db()->prepare("SELECT DISTINCT DATE(conectado_em) AS d FROM conexoes WHERE lead_id IN ($phi) ORDER BY d DESC");
    $qd->execute($ids);
    $datas = $qd->fetchAll(PDO::FETCH_COLUMN);

    // Tempo total conectado (sessões já encerradas).
    $qt = db()->prepare("SELECT COALESCE(SUM(segundos), 0) FROM conexoes WHERE lead_id IN ($phi)");
    $qt->execute($ids);
    $tempoTotal = (int) $qt->fetchColumn();

    // Dia da semana com mais VISITAS (dias distintos, não conexões repetidas).
    $NOMES_DOW = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
    $porDow = array_fill(0, 7, 0);
    foreach ($datas as $d) {
        $porDow[(int) date('w', strtotime((string) $d))]++;
    }
    $diaSemana = null;
    if ($datas) {
        $max = max($porDow);
        $diaSemana = $NOMES_DOW[array_search($max, $porDow, true)];
    }

    // Faixas de HORÁRIO: cada conexão vale para a faixa de 1h em que ficou
    // conectada por MAIS tempo (ex.: 15:55-16:30 -> faixa 16:00). Conta DIAS
    // distintos por faixa. Sessão sem duração gravada usa a hora do início.
    $qc = db()->prepare("SELECT conectado_em, segundos FROM conexoes WHERE lead_id IN ($phi)");
    $qc->execute($ids);
    $diasPorHora = [];
    foreach ($qc->fetchAll() as $cx) {
        $ini = strtotime((string) $cx['conectado_em']);
        $dur = $cx['segundos'] !== null ? max(0, (int) $cx['segundos']) : 0;
        if ($dur <= 0) {
            $h = (int) date('G', $ini);
        } else {
            $h = null;
            $melhor = -1;
            $t = $ini;
            $fim = $ini + $dur;
            while ($t < $fim) {
                $fimBanda = strtotime(date('Y-m-d H:00:00', $t)) + 3600;
                $ov = min($fim, $fimBanda) - $t;
                if ($ov > $melhor) { $melhor = $ov; $h = (int) date('G', $t); }
                $t = $fimBanda;
            }
        }
        $diasPorHora[$h][date('Y-m-d', $ini)] = true;
    }
    $faixasHora = array_fill(0, 24, 0);
    foreach ($diasPorHora as $h => $set) {
        $faixasHora[$h] = count($set);
    }
    $horaTop = null;
    if (array_sum($faixasHora) > 0) {
        $horaTop = sprintf('%02d:00', array_search(max($faixasHora), $faixasHora, true));
    }

    // Recorrência: veio hoje -> conta a sequência de dias SEGUIDOS terminando
    // hoje; não veio hoje -> há quantos dias está sem vir.
    $recorrencia = null;
    if ($datas) {
        $hoje = substr(db_now(), 0, 10);
        $gap  = (int) round((strtotime($hoje) - strtotime((string) $datas[0])) / 86400);
        if ($gap <= 0) {
            $seq = 1;
            for ($i = 1; $i < count($datas); $i++) {
                $dif = (int) round((strtotime((string) $datas[$i - 1]) - strtotime((string) $datas[$i])) / 86400);
                if ($dif === 1) { $seq++; } else { break; }
            }
            $recorrencia = ['tipo' => 'seguidos', 'dias' => $seq];
        } else {
            $recorrencia = ['tipo' => 'sem_vir', 'dias' => $gap];
        }
    }

    echo json_encode([
        'ok'            => true,
        'modo'          => $hosp ? 'hospedagem' : 'varejo',
        // Só na pousada: entrou, saiu, quarto e diárias de cada visita.
        'estadias'      => $hosp ? estadias_lista($lista, $tel) : [],
        'telefone'      => $tel,
        'nome'          => $nome,
        'total_dias'    => count($datas),
        'datas'         => array_values($datas), // Y-m-d de cada dia visitado (p/ o calendário)
        'tempo_total'   => $tempoTotal,
        'dia_semana'    => $diaSemana,
        'visitas_por_dia' => $porDow, // índice 0=domingo..6=sábado (dias distintos)
        'faixas_hora'     => $faixasHora, // índice 0..23 (dias distintos por faixa dominante)
        'hora_top'        => $horaTop,
        'ultima_visita' => $datas ? (string) $datas[0] : null,
        'recorrencia'   => $recorrencia,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Falha ao consultar o dashboard.']);
}
