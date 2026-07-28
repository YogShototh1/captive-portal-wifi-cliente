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
    $zero = ['total' => 0, 'revisitaram' => 0, 'reativados' => 0, 'nao_revisitaram' => 0, 'novos' => 0,
             'clientes' => 0, 'clientes_ant' => 0, 'pct' => 0];
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
    //
    // Quem esteve na janela ATUAL e classificado em TRES baldes exclusivos, a
    // partir do MESMO conjunto (leads com conexao na janela atual). Assim
    //     novos + revisitaram + reativados == clientes da janela atual
    // sempre fecha com o numero da comparacao mostrada embaixo do grafico:
    //   novos       = 1a conexao dentro da janela atual
    //   revisitaram = ja vinha de antes E tambem esteve na janela anterior
    //   reativados  = ja vinha de antes, FALTOU a janela anterior inteira e
    //                 voltou agora  (antes esse grupo nao entrava em lugar
    //                 nenhum e o grafico ficava menor que a comparacao)
    //
    // Do lado da janela ANTERIOR continua valendo:
    //     revisitaram + nao_revisitaram == clientes da janela anterior
    $periodo = function (string $iniAnt, string $fimAntStr, string $iniAtual) use ($lista, $ph, $primeira): array {
        // Clientes distintos da janela anterior (= "clientes_ant" da comparacao).
        $qB = db()->prepare(
            "SELECT COUNT(DISTINCT l.id) FROM leads l
               JOIN conexoes c ON c.lead_id = l.id AND c.conectado_em >= ? AND c.conectado_em < ?
              WHERE l.roteador IN ($ph)"
        );
        $qB->execute(array_merge([$iniAnt, $fimAntStr], $lista));
        $anteriores = (int) $qB->fetchColumn();

        // Uma passada so: pega quem tem conexao na janela atual (HAVING) e
        // marca se tambem apareceu na anterior (veio_ant).
        $qA = db()->prepare(
            "SELECT
                SUM(CASE WHEN t.primeira >= ? THEN 1 ELSE 0 END)                        AS novos,
                SUM(CASE WHEN t.primeira <  ? AND t.veio_ant = 1 THEN 1 ELSE 0 END)     AS revisitaram,
                SUM(CASE WHEN t.primeira <  ? AND t.veio_ant = 0 THEN 1 ELSE 0 END)     AS reativados,
                COUNT(*)                                                                AS atual
               FROM (
                    SELECT l.id,
                           $primeira AS primeira,
                           MAX(CASE WHEN c.conectado_em >= ? AND c.conectado_em < ? THEN 1 ELSE 0 END) AS veio_ant
                      FROM leads l
                      JOIN conexoes c ON c.lead_id = l.id
                     WHERE l.roteador IN ($ph)
                     GROUP BY l.id
                    HAVING MAX(CASE WHEN c.conectado_em >= ? THEN 1 ELSE 0 END) = 1
               ) t"
        );
        $qA->execute(array_merge(
            [$iniAtual, $iniAtual, $iniAtual, $iniAnt, $fimAntStr],
            $lista,
            [$iniAtual]
        ));
        $a = $qA->fetch();
        $novos      = (int) ($a['novos'] ?? 0);
        $rev        = (int) ($a['revisitaram'] ?? 0);
        $reativados = (int) ($a['reativados'] ?? 0);
        $atual      = (int) ($a['atual'] ?? 0);

        return [
            'total'           => $atual + $anteriores,
            'revisitaram'     => $rev,
            'reativados'      => $reativados,
            'nao_revisitaram' => max(0, $anteriores - $rev),
            'novos'           => $novos,
            'clientes'        => $atual,
            'clientes_ant'    => $anteriores,
            'pct'             => $anteriores > 0
                ? round(($atual - $anteriores) * 100 / $anteriores, 1)
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
