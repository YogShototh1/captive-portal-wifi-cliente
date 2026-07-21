<?php
// Migração: adiciona a coluna `nome` em `leads` (idempotente — pode rodar
// mais de uma vez). Via web exige o admin_token, como as demais tools:
//   /tools/migrar_nome.php?token=SEU_ADMIN_TOKEN
require_once __DIR__ . '/../inc/db.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals((string) config()['admin_token'], (string) ($_REQUEST['token'] ?? ''))) {
        http_response_code(403);
        exit("token invalido\n");
    }
}

$tem = db()->query("SHOW COLUMNS FROM leads LIKE 'nome'")->fetch();
if ($tem) {
    echo "ok: coluna `nome` ja existe\n";
    exit;
}
db()->exec('ALTER TABLE leads ADD COLUMN nome VARCHAR(60) NULL AFTER telefone');
echo "ok: coluna `nome` criada\n";
