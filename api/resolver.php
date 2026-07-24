<?php
// Resolve (reverse-DNS) alguns IPs pendentes do host_cache. SOMENTE ADMIN.
// Chamado pelas telas de acessos ao abrir — preenche os nomes aos poucos, fora
// do caminho quente do roteador. host='' = tentado e sem nome (mostra o IP).
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$comprador = comprador_logado();
if (!$comprador || (int) $comprador['is_admin'] !== 1) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'erro' => 'sem permissao']));
}

$n = 0;
try {
    $q = db()->query('SELECT ip FROM host_cache WHERE host IS NULL ORDER BY em DESC LIMIT 12');
    $up = db()->prepare('UPDATE host_cache SET host = ? WHERE ip = ?');
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $ip) {
        $host = @gethostbyaddr($ip);
        // Sem PTR (ou igual ao IP) -> '' (tentado, sem nome).
        $up->execute([($host && $host !== $ip) ? substr($host, 0, 190) : '', $ip]);
        $n++;
    }
} catch (Throwable $e) {
    // host_cache ainda não existe: nada a resolver.
}

echo json_encode(['ok' => true, 'resolvidos' => $n]);
