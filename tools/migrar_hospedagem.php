<?php
// Migração: tipo de painel na conta + tabela de hóspedes (ver
// sql/migracao_hospedagem.sql). Idempotente — pode rodar mais de uma vez.
//
// Via web exige o admin_token, como as demais tools:
//   /tools/migrar_hospedagem.php?token=SEU_ADMIN_TOKEN
require_once __DIR__ . '/../inc/db.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals((string) config()['admin_token'], (string) ($_REQUEST['token'] ?? ''))) {
        http_response_code(403);
        exit("token invalido\n");
    }
}

if (db()->query("SHOW COLUMNS FROM compradores LIKE 'painel'")->fetch()) {
    echo "ok: coluna `painel` ja existe\n";
} else {
    db()->exec("ALTER TABLE compradores ADD COLUMN painel VARCHAR(20) NOT NULL DEFAULT 'varejo'");
    echo "ok: coluna `painel` criada (todas as contas ficam em 'varejo')\n";
}

db()->exec(
    'CREATE TABLE IF NOT EXISTS hospedes (
        id         BIGINT AUTO_INCREMENT PRIMARY KEY,
        roteador   VARCHAR(120) NOT NULL,
        nome       VARCHAR(120) NOT NULL,
        quarto     VARCHAR(20)  NOT NULL,
        telefone   VARCHAR(20)  NOT NULL,
        entrada_em DATE         NOT NULL,
        dias       INT          NOT NULL,
        saida_em   DATETIME     NOT NULL,
        criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_rot_tel (roteador, telefone),
        INDEX idx_roteador (roteador),
        INDEX idx_saida (saida_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
echo "ok: tabela `hospedes` pronta\n";
echo "pronto: contas de hospedagem ja podem ser marcadas no cadastro\n";
