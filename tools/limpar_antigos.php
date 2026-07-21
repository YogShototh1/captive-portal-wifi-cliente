<?php
// Retenção LGPD: apaga leads mais antigos que N meses.
// Agende no cPanel > "Cron Jobs" (ex.: 1x por dia):
//   php /home/USUARIO/public_html/tools/limpar_antigos.php
// Ou via navegador (com token):
//   /tools/limpar_antigos.php?token=SEU_TOKEN

require_once __DIR__ . '/../inc/db.php';

$MESES = 6; // ajuste a retenção aqui

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    // hash_equals: comparação em tempo constante (não vaza o token por timing).
    if (!hash_equals((string) config()['admin_token'], (string) ($_REQUEST['token'] ?? ''))) {
        http_response_code(403);
        exit("token invalido\n");
    }
}

$stmt = db()->prepare(
    'DELETE FROM leads WHERE conectado_em < (NOW() - INTERVAL ' . (int) $MESES . ' MONTH)'
);
$stmt->execute();

echo 'Leads removidos: ' . $stmt->rowCount() . "\n";
