<?php
// Migração: adiciona a coluna `bytes` em `conexoes` (idempotente).
// Via web exige o admin_token: /tools/migrar_bytes.php?token=SEU_ADMIN_TOKEN
require_once __DIR__ . '/../inc/db.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals((string) config()['admin_token'], (string) ($_REQUEST['token'] ?? ''))) {
        http_response_code(403);
        exit("token invalido\n");
    }
}

$tem = db()->query("SHOW COLUMNS FROM conexoes LIKE 'bytes'")->fetch();
if ($tem) {
    echo "ok: coluna `bytes` ja existe\n";
    exit;
}
db()->exec('ALTER TABLE conexoes ADD COLUMN bytes BIGINT NULL AFTER segundos');
echo "ok: coluna `bytes` criada\n";
