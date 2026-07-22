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
    $agora = db_now();
    // Períodos: mês (dia 1), semana (segunda-feira) e dia (hoje), sempre 00:00.
    $iniMes = date('Y-m-01 00:00:00', strtotime($agora));
    $iniSem = date('Y-m-d 00:00:00', strtotime('monday this week', strtotime($agora)));
    $iniDia = date('Y-m-d 00:00:00', strtotime($agora));

    $ph = implode(',', array_fill(0, count($lista), '?'));
    // 1ª conexão do lead: coluna primeira_conexao; leads antigos (pré-migração)
    // caem no MIN(conexoes) e, sem histórico, no conectado_em.
    $primeira = 'COALESCE(l.primeira_conexao, (SELECT MIN(c2.conectado_em) FROM conexoes c2 WHERE c2.lead_id = l.id), l.conectado_em)';

    // Contagens de um período que começa em $ini.
    $recorrencia = function (string $ini) use ($lista, $ph, $primeira): array {
        $qN = db()->prepare("SELECT COUNT(*) FROM leads l WHERE l.roteador IN ($ph) AND $primeira >= ?");
        $qN->execute(array_merge($lista, [$ini]));
        $novos = (int) $qN->fetchColumn();

        $qA = db()->prepare("SELECT COUNT(*) FROM leads l WHERE l.roteador IN ($ph) AND $primeira < ?");
        $qA->execute(array_merge($lista, [$ini]));
        $antigos = (int) $qA->fetchColumn();

        $qR = db()->prepare(
            "SELECT COUNT(DISTINCT l.id) FROM leads l
               JOIN conexoes c ON c.lead_id = l.id AND c.conectado_em >= ?
              WHERE l.roteador IN ($ph) AND $primeira < ?"
        );
        $qR->execute(array_merge([$ini], $lista, [$ini]));
        $rev = (int) $qR->fetchColumn();

        return [
            'total'           => $antigos + $novos,
            'revisitaram'     => $rev,
            'nao_revisitaram' => max(0, $antigos - $rev),
            'novos'           => $novos,
        ];
    };

    // Variação de visitas (conexões): janelas de DIAS COMPLETOS, estáveis o dia
    // inteiro (só mudam à meia-noite) — hoje (parcial) fica de fora dos dois lados.
    //   mês:    últimos 30 dias vs os 30 anteriores
    //   semana: últimos 7 dias  vs os 7 anteriores
    //   dia:    ontem           vs anteontem
    $hoje00   = date('Y-m-d 00:00:00', strtotime($agora));
    $diasAtras = function (int $n) use ($hoje00): string {
        return date('Y-m-d 00:00:00', strtotime($hoje00 . " -$n day"));
    };
    $variacao = function (string $iniAnt, string $meio, string $fim) use ($lista, $ph): float {
        // anterior = [iniAnt, meio)   atual = [meio, fim)
        $q = db()->prepare(
            "SELECT
                SUM(c.conectado_em >= ? AND c.conectado_em < ?) AS atual,
                SUM(c.conectado_em >= ? AND c.conectado_em < ?) AS passada
               FROM conexoes c JOIN leads l ON l.id = c.lead_id
              WHERE l.roteador IN ($ph)"
        );
        $q->execute(array_merge([$meio, $fim, $iniAnt, $meio], $lista));
        $v = $q->fetch();
        $atual   = (int) ($v['atual'] ?? 0);
        $passada = (int) ($v['passada'] ?? 0);
        return $passada > 0
            ? round(($atual - $passada) * 100 / $passada, 1)
            : ($atual > 0 ? 100.0 : 0.0);
    };

    echo json_encode([
        'ok'     => true,
        'mes'    => $recorrencia($iniMes) + ['pct' => $variacao($diasAtras(60), $diasAtras(30), $hoje00)],
        'semana' => $recorrencia($iniSem) + ['pct' => $variacao($diasAtras(14), $diasAtras(7), $hoje00)],
        'dia'    => $recorrencia($iniDia) + ['pct' => $variacao($diasAtras(2), $diasAtras(1), $hoje00)],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Falha ao consultar o dashboard.']);
}
