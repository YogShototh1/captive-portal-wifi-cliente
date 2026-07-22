<?php
// Dashboard geral (todos os leads): recorrência por período (mês, semana, dia).
// Para cada período:
//   revisitaram    = clientes antigos (1ª conexão antes do período) que voltaram nele
//   nao_revisitaram= clientes antigos que ainda não voltaram nele
//   novos          = clientes cuja 1ª conexão foi no período
// Visitas (conexões) desta semana vs o MESMO trecho da semana passada -> % de variação
// (comparar com a semana passada inteira faria toda segunda começar "no vermelho").
// Auth/isolamento igual ao dashboard.php.
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
if (!$lista) {
    $zero = ['total' => 0, 'revisitaram' => 0, 'nao_revisitaram' => 0, 'novos' => 0, 'pct' => 0];
    exit(json_encode(['ok' => true, 'mes' => $zero, 'semana' => $zero, 'dia' => $zero]));
}

try {
    $agora  = db_now();
    $hoje00 = date('Y-m-d 00:00:00', strtotime($agora));
    $diasAtras = function (int $n) use ($hoje00): string {
        return date('Y-m-d 00:00:00', strtotime($hoje00 . " -$n day"));
    };

    $ph = implode(',', array_fill(0, count($lista), '?'));
    // 1ª conexão do lead: coluna primeira_conexao; leads antigos (pré-migração)
    // caem no MIN(conexoes) e, sem histórico, no conectado_em.
    $primeira = 'COALESCE(l.primeira_conexao, (SELECT MIN(c2.conectado_em) FROM conexoes c2 WHERE c2.lead_id = l.id), l.conectado_em)';

    // Um período = janela ATUAL [iniAtual, agora] vs janela ANTERIOR [iniAnt, iniAtual).
    //   revisitaram     = visitaram na anterior E voltaram na atual
    //   nao_revisitaram = visitaram na anterior e (ainda) não voltaram na atual
    //   novos           = 1ª conexão dentro da janela atual
    //   pct             = conexões da atual vs conexões da anterior
    $periodo = function (string $iniAnt, string $iniAtual) use ($lista, $ph, $primeira): array {
        $qB = db()->prepare(
            "SELECT COUNT(DISTINCT l.id) FROM leads l
               JOIN conexoes c ON c.lead_id = l.id AND c.conectado_em >= ? AND c.conectado_em < ?
              WHERE l.roteador IN ($ph)"
        );
        $qB->execute(array_merge([$iniAnt, $iniAtual], $lista));
        $anteriores = (int) $qB->fetchColumn();

        $qR = db()->prepare(
            "SELECT COUNT(DISTINCT l.id) FROM leads l
               JOIN conexoes ca ON ca.lead_id = l.id AND ca.conectado_em >= ?
               JOIN conexoes cb ON cb.lead_id = l.id AND cb.conectado_em >= ? AND cb.conectado_em < ?
              WHERE l.roteador IN ($ph)"
        );
        $qR->execute(array_merge([$iniAtual, $iniAnt, $iniAtual], $lista));
        $rev = (int) $qR->fetchColumn();

        $qN = db()->prepare("SELECT COUNT(*) FROM leads l WHERE l.roteador IN ($ph) AND $primeira >= ?");
        $qN->execute(array_merge($lista, [$iniAtual]));
        $novos = (int) $qN->fetchColumn();

        $qV = db()->prepare(
            "SELECT
                SUM(c.conectado_em >= ?) AS atual,
                SUM(c.conectado_em >= ? AND c.conectado_em < ?) AS passada
               FROM conexoes c JOIN leads l ON l.id = c.lead_id
              WHERE l.roteador IN ($ph)"
        );
        $qV->execute(array_merge([$iniAtual, $iniAnt, $iniAtual], $lista));
        $v = $qV->fetch();
        $atual   = (int) ($v['atual'] ?? 0);
        $passada = (int) ($v['passada'] ?? 0);

        return [
            'total'           => $anteriores + $novos,
            'revisitaram'     => $rev,
            'nao_revisitaram' => max(0, $anteriores - $rev),
            'novos'           => $novos,
            'pct'             => $passada > 0
                ? round(($atual - $passada) * 100 / $passada, 1)
                : ($atual > 0 ? 100.0 : 0.0),
        ];
    };

    echo json_encode([
        'ok'     => true,
        // mês: últimos 30 dias (contando hoje) vs os 30 anteriores
        'mes'    => $periodo($diasAtras(59), $diasAtras(29)),
        // semana: últimos 7 dias (contando hoje) vs os 7 anteriores
        'semana' => $periodo($diasAtras(13), $diasAtras(6)),
        // dia: hoje vs ontem
        'dia'    => $periodo($diasAtras(1), $hoje00),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Falha ao consultar o dashboard.']);
}
