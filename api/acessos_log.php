<?php
// Log GERAL de acessos (aba lateral). SOMENTE ADMIN. Ordenado do mais recente
// para o mais antigo: nome/telefone / ip / ip destino (+ host) / aparelho.
//   ?roteador=  filtra o cliente aberto no admin_leads
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

$roteador = trim((string) ($_GET['roteador'] ?? ''));
$csv      = (string) ($_GET['f'] ?? '') === 'csv';

// log_data() e log_periodo_sql() vivem no inc/util.php: o log de um lead (o do
// botão "!") monta o mesmo recorte, e a leitura de data tem que ser uma só.
$de  = log_data($_GET['de']  ?? null);
$ate = log_data($_GET['ate'] ?? null);

$cond = [];
$args = [];
if ($roteador !== '') { $cond[] = 'a.roteador = ?'; $args[] = $roteador; }
[$condData, $argsData] = log_periodo_sql($de, $ate);
$cond  = array_merge($cond, $condData);
$args  = array_merge($args, $argsData);
$where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';

// A consulta é a mesma da tela e da planilha; só muda o LIMIT.
$SELECT =
    "SELECT a.visto_em, a.ip_cliente, a.ip_destino, a.mac, hc.host, hc.org,
            (SELECT l.telefone FROM conexoes cx JOIN leads l ON l.id = cx.lead_id
               WHERE cx.mac = a.mac AND l.roteador = a.roteador ORDER BY cx.id DESC LIMIT 1) AS telefone,
            (SELECT l.nome FROM conexoes cx JOIN leads l ON l.id = cx.lead_id
               WHERE cx.mac = a.mac AND l.roteador = a.roteador ORDER BY cx.id DESC LIMIT 1) AS nome,
            (SELECT cx.dispositivo FROM conexoes cx WHERE cx.mac = a.mac ORDER BY cx.id DESC LIMIT 1) AS dispositivo
       FROM acessos a
       LEFT JOIN host_cache hc ON hc.ip = a.ip_destino
       $where
      ORDER BY a.visto_em DESC";

// ---------------------------------------------------------------- planilha
if ($csv) {
    // CSV, não .xlsx: o Excel abre os dois, e gerar xlsx exigiria uma
    // biblioteca só para isto. Separador ';' e BOM porque é assim que o Excel
    // em português reconhece as colunas e os acentos sem ninguém configurar
    // nada na abertura.
    // ponytail: teto de 20 mil linhas — é o que a hospedagem compartilhada
    // aguenta montar de uma vez. Se um dia precisar de mais, exportar por mês.
    try {
        $q = db()->prepare($SELECT . ' LIMIT 20000');
        $q->execute($args);
        $linhas = $q->fetchAll();
    } catch (Throwable $e) {
        $linhas = [];
    }
    $nome = 'log-acessos' . ($roteador !== '' ? '-' . preg_replace('/[^A-Za-z0-9._-]/', '', $roteador) : '')
          . ($de !== null || $ate !== null ? '-' . ($de ?? 'inicio') . '_a_' . ($ate ?? 'hoje') : '') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nome . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM: sem ele o Excel come os acentos
    fputcsv($out, ['Data e hora', 'Cliente', 'IP', 'Destino', 'Serviço', 'Aparelho'], ';');
    foreach ($linhas as $a) {
        fputcsv($out, [
            date('d/m/Y H:i', strtotime((string) $a['visto_em'])),
            (string) ($a['nome'] !== null && $a['nome'] !== '' ? $a['nome'] : ($a['telefone'] ?? '')),
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

$POR_PAG = max(5, min(100, (int) ($_GET['por_pagina'] ?? 50)));
$pagina  = max(1, (int) ($_GET['pagina'] ?? 1));

try {
    $qt = db()->prepare("SELECT COUNT(*) FROM acessos a $where");
    $qt->execute($args);
    $total   = (int) $qt->fetchColumn();
    $paginas = max(1, (int) ceil($total / $POR_PAG));
    $pagina  = min($paginas, $pagina);

    $c = db()->prepare($SELECT . " LIMIT $POR_PAG OFFSET " . (($pagina - 1) * $POR_PAG));
    $c->execute($args);
    $log = $c->fetchAll();

    // Data do registro mais antigo, IGNORANDO o recorte de data — é ela que o
    // painel usa como "início" ao abrir. Com o recorte aplicado, o campo
    // passaria a apontar para si mesmo a cada filtro.
    $qp = db()->prepare('SELECT MIN(visto_em) FROM acessos a'
        . ($roteador !== '' ? ' WHERE a.roteador = ?' : ''));
    $qp->execute($roteador !== '' ? [$roteador] : []);
    $pri = $qp->fetchColumn();
    $primeira = $pri ? date('Y-m-d', strtotime((string) $pri)) : null;
} catch (Throwable $e) {
    exit(json_encode(['ok' => true, 'log' => [], 'pagina' => 1, 'paginas' => 1, 'total' => 0, 'primeira' => null]));
}

echo json_encode([
    'ok'       => true,
    'log'      => $log,
    'pagina'   => $pagina,
    'paginas'  => $paginas,
    'total'    => $total,
    'primeira' => $primeira,
]);
