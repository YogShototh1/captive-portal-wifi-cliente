<?php
// Histórico de conexões de um número (para o pop-up "ver conexões"). Sessão + dono.
// Paginado: ?pagina=N e ?por_pagina=M (o painel manda quantas linhas cabem na
// tela); responde também pagina/paginas.
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

// ?lead_id= aceita uma lista ("12,34") quando a linha da tabela é uma pessoa
// mesclada de vários MikroTiks: o histórico é dela, não de um cadastro — e o
// número do balãozinho (soma das conexões) tem de bater com o que abre aqui.
$ids = leads_permitidos(explode(',', (string) ($_GET['lead_id'] ?? '')), $comprador);
if (!$ids) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'erro' => 'lead nao encontrado']));
}
$ph = implode(',', array_fill(0, count($ids), '?'));

$q = db()->prepare("SELECT telefone FROM leads WHERE id IN ($ph) ORDER BY id LIMIT 1");
$q->execute($ids);
$lead = $q->fetch();

// Tamanho da página vem do painel (quantas linhas cabem na tela SEM rolar),
// clampado aqui — a fronteira é o servidor, não o JS.
$POR_PAG = max(3, min(30, (int) ($_GET['por_pagina'] ?? 10)));
$qt = db()->prepare("SELECT COUNT(*) FROM conexoes WHERE lead_id IN ($ph)");
$qt->execute($ids);
$paginas = max(1, (int) ceil(((int) $qt->fetchColumn()) / $POR_PAG));
$pagina  = min($paginas, max(1, (int) ($_GET['pagina'] ?? 1)));

$sel = "SELECT conectado_em, dispositivo, ip, segundos, seg_ac, bytes, visto_em FROM conexoes WHERE lead_id IN ($ph)
          ORDER BY conectado_em DESC, id DESC
          LIMIT " . $POR_PAG . ' OFFSET ' . (($pagina - 1) * $POR_PAG);
try {
    $c = db()->prepare($sel);
    $c->execute($ids);
    $conexoes = $c->fetchAll();
} catch (Throwable $e) {
    try {
        // Banco que ainda nao recebeu o `seg_ac` (quem cria a coluna e a
        // primeira rodada do api/status.php). Sem ela so da para mostrar as
        // sessoes ja fechadas — melhor que devolver erro e sumir com a lista.
        $c = db()->prepare(str_replace(', seg_ac', '', $sel));
        $c->execute($ids);
        $conexoes = $c->fetchAll();
    } catch (Throwable $e2) {
        // Causa clássica: banco antigo, sem a coluna `segundos` (tempo por conexão).
        http_response_code(500);
        exit(json_encode(['ok' => false, 'erro' => 'Banco desatualizado: rode sql/migracao_conexoes.sql no phpMyAdmin.']));
    }
}

// Sessão em andamento POR APARELHO: cada conexão aberta (segundos NULL) que
// ainda está online (visto_em recente) mostra o tempo correndo dela — assim,
// com 2 aparelhos no mesmo número, os DOIS aparecem com o tempo certo (antes só
// o mais recente; o outro ficava "—").
//
// O tempo é o CONFIRMADO (seg_ac, somado pelo api/status.php) mais o pedaço
// desde a última confirmação. Nunca "agora menos a hora do login": o login é
// gravado antes do anúncio de 10s, e numa linha esquecida aberta essa conta
// devolvia dias de internet que não existiram. Aberta e nunca confirmada = "—".
$nowTs = strtotime(db_now());
foreach ($conexoes as &$cx) {
    $cx['bytes'] = $cx['bytes'] === null ? null : (int) $cx['bytes'];
    if ($cx['segundos'] !== null) {
        $cx['segundos'] = (int) $cx['segundos'];
    } else {
        $vt = ($cx['visto_em'] === null || $cx['visto_em'] === '') ? null : strtotime((string) $cx['visto_em']);
        if ($vt === null) {
            $cx['segundos'] = null;
        } elseif (($nowTs - $vt) <= MIKROTIK_TIMEOUT_SEG) {
            $cx['segundos'] = max(0, (int) ($cx['seg_ac'] ?? 0) + min($nowTs - $vt, SESSAO_PASSO_MAX_SEG));
        } else {
            $cx['segundos'] = max(0, (int) ($cx['seg_ac'] ?? 0));
        }
    }
    unset($cx['visto_em'], $cx['seg_ac']); // não vão para o cliente
}
unset($cx);

echo json_encode([
    'ok'       => true,
    'telefone' => $lead ? $lead['telefone'] : '',
    'conexoes' => $conexoes,
    'pagina'   => $pagina,
    'paginas'  => $paginas,
]);
