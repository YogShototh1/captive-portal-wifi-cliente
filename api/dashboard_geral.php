<?php
// Dashboard geral (todos os leads): recorrência por período (mês, semana, dia).
// Comparação justa: período atual até AGORA vs o período anterior truncado no
// mesmo ponto decorrido (mês passado até o mesmo dia/hora; semana passada de
// domingo até o mesmo ponto; ontem até a mesma hora).
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
    $agora   = db_now();
    $agoraTs = strtotime($agora);
    $hoje00  = date('Y-m-d 00:00:00', $agoraTs);

    // Comparação JUSTA: período atual até agora vs o período anterior truncado
    // no MESMO ponto decorrido.
    //   mês:    dia 1 -> agora           vs mês passado dia 1 -> mesmo dia/hora
    //   semana: DOMINGO -> agora         vs semana passada domingo -> mesmo ponto
    //   dia:    hoje 00:00 -> agora      vs ontem 00:00 -> mesma hora
    $iniMes = date('Y-m-01 00:00:00', $agoraTs);
    $iniSem = date('Y-m-d 00:00:00', strtotime($hoje00 . ' -' . (int) date('w', $agoraTs) . ' day')); // domingo
    $iniDia = $hoje00;
    $iniMesAnt = date('Y-m-01 00:00:00', strtotime($iniMes . ' -1 day')); // dia 1 do mês passado
    $iniSemAnt = date('Y-m-d H:i:s', strtotime($iniSem . ' -7 day'));
    $iniDiaAnt = date('Y-m-d H:i:s', strtotime($iniDia . ' -1 day'));
    // Fim da janela anterior = início dela + tempo decorrido do período atual.
    // (No dia 31, o "mesmo dia" de um mês de 30 transborda 1 dia — caso raro, aceito.)
    $fimAnt = function (string $ini, string $iniAnt) use ($agoraTs): string {
        return date('Y-m-d H:i:s', strtotime($iniAnt) + ($agoraTs - strtotime($ini)));
    };

    $ph = implode(',', array_fill(0, count($lista), '?'));
    // 1ª conexão do lead: coluna primeira_conexao; leads antigos (pré-migração)
    // caem no MIN(conexoes) e, sem histórico, no conectado_em.
    $primeira = 'COALESCE(l.primeira_conexao, (SELECT MIN(c2.conectado_em) FROM conexoes c2 WHERE c2.lead_id = l.id), l.conectado_em)';

    // Janela ATUAL [iniAtual, agora] vs janela ANTERIOR truncada [iniAnt, fimAnt).
    //   revisitaram     = visitaram na anterior E voltaram na atual
    //   nao_revisitaram = visitaram na anterior e (ainda) não voltaram na atual
    //   novos           = 1ª conexão dentro da janela atual
    //   pct             = conexões da atual vs conexões da anterior
    $periodo = function (string $iniAnt, string $fimAntStr, string $iniAtual) use ($lista, $ph, $primeira): array {
        $qB = db()->prepare(
            "SELECT COUNT(DISTINCT l.id) FROM leads l
               JOIN conexoes c ON c.lead_id = l.id AND c.conectado_em >= ? AND c.conectado_em < ?
              WHERE l.roteador IN ($ph)"
        );
        $qB->execute(array_merge([$iniAnt, $fimAntStr], $lista));
        $anteriores = (int) $qB->fetchColumn();

        $qR = db()->prepare(
            "SELECT COUNT(DISTINCT l.id) FROM leads l
               JOIN conexoes ca ON ca.lead_id = l.id AND ca.conectado_em >= ?
               JOIN conexoes cb ON cb.lead_id = l.id AND cb.conectado_em >= ? AND cb.conectado_em < ?
              WHERE l.roteador IN ($ph)"
        );
        $qR->execute(array_merge([$iniAtual, $iniAnt, $fimAntStr], $lista));
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
        $qV->execute(array_merge([$iniAtual, $iniAnt, $fimAntStr], $lista));
        $v = $qV->fetch();
        $atual   = (int) ($v['atual'] ?? 0);
        $passada = (int) ($v['passada'] ?? 0);

        return [
            'total'           => $anteriores + $novos,
            'revisitaram'     => $rev,
            'nao_revisitaram' => max(0, $anteriores - $rev),
            'novos'           => $novos,
            'visitas'         => $atual,
            'visitas_ant'     => $passada,
            'pct'             => $passada > 0
                ? round(($atual - $passada) * 100 / $passada, 1)
                : ($atual > 0 ? 100.0 : 0.0),
        ];
    };

    echo json_encode([
        'ok'     => true,
        'mes'    => $periodo($iniMesAnt, $fimAnt($iniMes, $iniMesAnt), $iniMes),
        'semana' => $periodo($iniSemAnt, $fimAnt($iniSem, $iniSemAnt), $iniSem),
        'dia'    => $periodo($iniDiaAnt, $fimAnt($iniDia, $iniDiaAnt), $iniDia),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Falha ao consultar o dashboard.']);
}
