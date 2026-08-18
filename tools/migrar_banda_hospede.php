<?php
// Migração: banda máxima por hóspede (ver sql/migracao_banda_hospede.sql).
// Idempotente — pode rodar mais de uma vez.
//
// Via web exige o admin_token, como as demais tools:
//   /tools/migrar_banda_hospede.php?token=SEU_ADMIN_TOKEN
require_once __DIR__ . '/../inc/db.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals((string) config()['admin_token'], (string) ($_REQUEST['token'] ?? ''))) {
        http_response_code(403);
        exit("token invalido\n");
    }
}

// MySQL não tem "ADD COLUMN IF NOT EXISTS" em toda versão: pergunta antes.
$tem = db()->query("SHOW COLUMNS FROM hospedes LIKE 'banda_limite'")->fetch();
if ($tem) {
    echo "ok: coluna `banda_limite` ja existe\n";
} else {
    db()->exec('ALTER TABLE hospedes ADD COLUMN banda_limite INT NULL');
    echo "ok: coluna `banda_limite` criada\n";
}

// Backfill: quem já teve o teto ajustado pela tabela de leads não perde o
// ajuste. Só preenche o que está vazio, então rodar de novo não sobrescreve.
$n = db()->exec(
    'UPDATE hospedes h
        SET h.banda_limite = (SELECT MAX(l.banda_limite) FROM leads l
                               WHERE l.roteador = h.roteador AND l.telefone = h.telefone)
      WHERE h.banda_limite IS NULL'
);
echo 'ok: ' . (int) $n . " hospede(s) herdaram o limite que ja estava nos leads\n";
echo "pronto: o limite pode ser definido antes da primeira conexao\n";
