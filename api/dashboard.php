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
    if (!$leadsDoTel) {
        http_response_code(404);
        exit(json_encode(['ok' => false, 'erro' => 'Nenhum lead com esse número.']));
    }
    $ids  = array_map(function ($r) { return (int) $r['id']; }, $leadsDoTel);
    $nome = null;
    foreach ($leadsDoTel as $r) {
        if ($r['nome'] !== null && $r['nome'] !== '') { $nome = (string) $r['nome']; break; }
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
