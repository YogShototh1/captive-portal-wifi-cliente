<?php
// Dashboard geral (todos os leads): recorrência da semana atual.
//   revisitaram    = clientes antigos (1ª conexão antes da semana) que voltaram nesta semana
//   nao_revisitaram= clientes antigos que ainda não voltaram nesta semana
//   novos          = clientes cuja 1ª conexão foi nesta semana
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
    exit(json_encode(['ok' => true, 'total' => 0, 'revisitaram' => 0, 'nao_revisitaram' => 0, 'novos' => 0, 'pct' => 0]));
}

try {
    $agora = db_now();
    // Semana começa na segunda-feira 00:00.
    $iniSem  = date('Y-m-d 00:00:00', strtotime('monday this week', strtotime($agora)));
    $iniPass = date('Y-m-d 00:00:00', strtotime($iniSem . ' -7 day'));
    // Mesmo trecho da semana passada: até "agora - 7 dias".
    $fimPass = date('Y-m-d H:i:s', strtotime($agora . ' -7 day'));

    $ph = implode(',', array_fill(0, count($lista), '?'));
    // 1ª conexão do lead: coluna primeira_conexao; leads antigos (pré-migração)
    // caem no MIN(conexoes) e, sem histórico, no conectado_em.
    $primeira = 'COALESCE(l.primeira_conexao, (SELECT MIN(c2.conectado_em) FROM conexoes c2 WHERE c2.lead_id = l.id), l.conectado_em)';

    $qN = db()->prepare("SELECT COUNT(*) FROM leads l WHERE l.roteador IN ($ph) AND $primeira >= ?");
    $qN->execute(array_merge($lista, [$iniSem]));
    $novos = (int) $qN->fetchColumn();

    $qA = db()->prepare("SELECT COUNT(*) FROM leads l WHERE l.roteador IN ($ph) AND $primeira < ?");
    $qA->execute(array_merge($lista, [$iniSem]));
    $antigos = (int) $qA->fetchColumn();

    $qR = db()->prepare(
        "SELECT COUNT(DISTINCT l.id) FROM leads l
           JOIN conexoes c ON c.lead_id = l.id AND c.conectado_em >= ?
          WHERE l.roteador IN ($ph) AND $primeira < ?"
    );
    $qR->execute(array_merge([$iniSem], $lista, [$iniSem]));
    $revisitaram = (int) $qR->fetchColumn();

    // Visitas = conexões no período.
    $qV = db()->prepare(
        "SELECT
            SUM(c.conectado_em >= ?) AS atual,
            SUM(c.conectado_em >= ? AND c.conectado_em < ?) AS passada
           FROM conexoes c JOIN leads l ON l.id = c.lead_id
          WHERE l.roteador IN ($ph)"
    );
    $qV->execute(array_merge([$iniSem, $iniPass, $fimPass], $lista));
    $v = $qV->fetch();
    $atual   = (int) ($v['atual'] ?? 0);
    $passada = (int) ($v['passada'] ?? 0);
    $pct = $passada > 0
        ? round(($atual - $passada) * 100 / $passada, 1)
        : ($atual > 0 ? 100.0 : 0.0);

    echo json_encode([
        'ok'              => true,
        'total'           => $antigos + $novos,
        'revisitaram'     => $revisitaram,
        'nao_revisitaram' => max(0, $antigos - $revisitaram),
        'novos'           => $novos,
        'visitas_semana'  => $atual,
        'visitas_passada' => $passada,
        'pct'             => $pct,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Falha ao consultar o dashboard.']);
}
