<?php
// Log de acessos de UM lead (modal do botão "!"). SOMENTE ADMIN.
// Lista por MAC do número: hora / ip cliente / ip destino (+ host) / aparelho.
//   ?lead_id=   o lead aberto
//   ?de=&ate=   recorte por data (YYYY-MM-DD, inclusive nos dois extremos)
//   ?f=csv      baixa o recorte inteiro em planilha, sem paginação
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';

$comprador = comprador_logado();
if (!$comprador || (int) $comprador['is_admin'] !== 1) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['ok' => false, 'erro' => 'sem permissao']));
}

$csv = (string) ($_GET['f'] ?? '') === 'csv';

$leadId = (int) ($_GET['lead_id'] ?? 0);
$q = db()->prepare('SELECT telefone, nome, roteador FROM leads WHERE id = ?');
$q->execute([$leadId]);
$lead = $q->fetch();
if (!$lead) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['ok' => false, 'erro' => 'lead nao encontrado']));
}

$quem = (string) ($lead['nome'] !== null && $lead['nome'] !== '' ? $lead['nome'] : $lead['telefone']);

// Resposta vazia — usada quando o lead não tem MAC nenhum e quando a tabela de
// acessos ainda não existe. Na planilha, um arquivo só com o cabeçalho.
$vazio = function () use ($lead, $csv, $quem) {
    if ($csv) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="log-acessos-' . preg_replace('/[^A-Za-z0-9._-]/', '', $quem) . '.csv"');
        echo "\xEF\xBB\xBF" . "Data e hora;IP cliente;Destino;Serviço;Aparelho\r\n";
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['ok' => true, 'telefone' => $lead['telefone'], 'nome' => $lead['nome'],
                      'acessos' => [], 'pagina' => 1, 'paginas' => 1, 'primeira' => null]));
};

// MACs deste número (todos os aparelhos).
$qm = db()->prepare('SELECT DISTINCT mac FROM conexoes WHERE lead_id = ? AND mac IS NOT NULL AND mac <> ""');
$qm->execute([$leadId]);
$macs = $qm->fetchAll(PDO::FETCH_COLUMN);
if (!$macs) { $vazio(); }
$ph = implode(',', array_fill(0, count($macs), '?'));

// O recorte de data é o mesmo do log geral (inc/util.php).
$de  = log_data($_GET['de']  ?? null);
$ate = log_data($_GET['ate'] ?? null);
[$condData, $argsData] = log_periodo_sql($de, $ate);

$base  = array_merge([$lead['roteador']], $macs);
$where = "WHERE a.roteador = ? AND a.mac IN ($ph)" . ($condData ? ' AND ' . implode(' AND ', $condData) : '');
$args  = array_merge($base, $argsData);

$SELECT =
    "SELECT a.visto_em, a.ip_cliente, a.ip_destino, a.hits, hc.host, hc.org,
            (SELECT cx.dispositivo FROM conexoes cx WHERE cx.mac = a.mac ORDER BY cx.id DESC LIMIT 1) AS dispositivo
       FROM acessos a
       LEFT JOIN host_cache hc ON hc.ip = a.ip_destino
       $where
      ORDER BY a.visto_em DESC";

// ---------------------------------------------------------------- planilha
if ($csv) {
    // CSV com ';' e BOM: é assim que o Excel em português separa as colunas e
    // mostra os acentos sem ninguém configurar nada. Mesma escolha do log
    // geral (api/acessos_log.php), e pelo mesmo motivo.
    try {
        $c = db()->prepare($SELECT . ' LIMIT 20000');
        $c->execute($args);
        $linhas = $c->fetchAll();
    } catch (Throwable $e) {
        $linhas = [];
    }
    $nome = 'log-acessos-' . preg_replace('/[^A-Za-z0-9._-]/', '', $quem)
          . ($de !== null || $ate !== null ? '-' . ($de ?? 'inicio') . '_a_' . ($ate ?? 'hoje') : '') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nome . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Data e hora', 'IP cliente', 'Destino', 'Serviço', 'Aparelho'], ';');
    foreach ($linhas as $a) {
        fputcsv($out, [
            date('d/m/Y H:i', strtotime((string) $a['visto_em'])),
            (string) ($a['ip_cliente'] ?? ''),
            (string) ($a['host'] !== null && $a['host'] !== '' ? $a['host'] : ($a['ip_destino'] ?? '')),
            (string) ($a['org'] ?? ''),
            (string) ($a['dispositivo'] ?? ''),
        ], ';');
    }
    fclose($out);
    exit;
}

// ------------------------------------------------------------------- tela
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$POR_PAG = max(3, min(40, (int) ($_GET['por_pagina'] ?? 12)));
try {
    $qt = db()->prepare("SELECT COUNT(*) FROM acessos a $where");
    $qt->execute($args);
    $total = (int) $qt->fetchColumn();
    $paginas = max(1, (int) ceil($total / $POR_PAG));
    $pagina  = min($paginas, max(1, (int) ($_GET['pagina'] ?? 1)));

    $c = db()->prepare($SELECT . " LIMIT $POR_PAG OFFSET " . (($pagina - 1) * $POR_PAG));
    $c->execute($args);
    $acessos = $c->fetchAll();

    // Data do acesso mais antigo DESTE lead, ignorando o recorte — é ela que o
    // modal usa como "início" ao abrir. Com o recorte aplicado, o campo
    // passaria a apontar para si mesmo a cada consulta.
    $qp = db()->prepare("SELECT MIN(a.visto_em) FROM acessos a WHERE a.roteador = ? AND a.mac IN ($ph)");
    $qp->execute($base);
    $pri = $qp->fetchColumn();
    $primeira = $pri ? date('Y-m-d', strtotime((string) $pri)) : null;
} catch (Throwable $e) {
    // Tabela ainda não existe (nenhum acesso registrado) = lista vazia.
    $vazio();
}

echo json_encode([
    'ok'       => true,
    'telefone' => $lead['telefone'],
    'nome'     => $lead['nome'],
    'acessos'  => $acessos,
    'pagina'   => $pagina,
    'paginas'  => $paginas,
    'total'    => $total,
    'primeira' => $primeira,
]);
