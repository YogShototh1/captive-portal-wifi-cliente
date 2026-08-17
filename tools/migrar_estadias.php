<?php
// Migração: histórico de estadias (ver sql/migracao_estadias.sql).
// Idempotente — pode rodar mais de uma vez.
//
// Via web exige o admin_token, como as demais tools:
//   /tools/migrar_estadias.php?token=SEU_ADMIN_TOKEN
require_once __DIR__ . '/../inc/db.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals((string) config()['admin_token'], (string) ($_REQUEST['token'] ?? ''))) {
        http_response_code(403);
        exit("token invalido\n");
    }
}

db()->exec(
    'CREATE TABLE IF NOT EXISTS estadias (
        id         BIGINT AUTO_INCREMENT PRIMARY KEY,
        hospede_id BIGINT       NOT NULL,
        roteador   VARCHAR(120) NOT NULL,
        telefone   VARCHAR(20)  NOT NULL,
        nome       VARCHAR(120) NOT NULL,
        quarto     VARCHAR(20)  NOT NULL,
        entrada_em DATE         NOT NULL,
        dias       INT          NOT NULL,
        saida_em   DATETIME     NOT NULL,
        criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_hosp_entrada (hospede_id, entrada_em),
        INDEX idx_rot_tel (roteador, telefone),
        INDEX idx_entrada (entrada_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
echo "ok: tabela `estadias` pronta\n";

// Backfill da estadia atual de quem já está cadastrado. INSERT IGNORE:
// rodar de novo não duplica (a UNIQUE segura).
$n = db()->exec(
    'INSERT IGNORE INTO estadias (hospede_id, roteador, telefone, nome, quarto, entrada_em, dias, saida_em)
     SELECT id, roteador, telefone, nome, quarto, entrada_em, dias, saida_em FROM hospedes'
);
echo 'ok: ' . (int) $n . " estadia(s) importada(s) do cadastro atual\n";
echo "pronto: cada novo check-in vira uma linha em `estadias`\n";
