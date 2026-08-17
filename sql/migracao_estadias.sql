-- Migração: histórico de estadias (uma linha por visita do hóspede).
-- Rode UMA vez no phpMyAdmin, ou chame tools/migrar_estadias.php (idempotente).
--
-- Por que uma tabela nova: `hospedes` tem UNIQUE (roteador, telefone) porque o
-- portal do hotspot precisa de UMA resposta por número — hóspede que volta
-- ATUALIZA a linha, e a visita anterior sumia. Aqui cada check-in fica gravado.
--
-- A chave é (hospede_id, entrada_em): editar o cadastro ou somar uma diária
-- mexe na estadia daquela data; mudar a data de entrada abre uma visita nova.

CREATE TABLE IF NOT EXISTS estadias (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  hospede_id BIGINT       NOT NULL,   -- linha em `hospedes` (cadastro vivo)
  roteador   VARCHAR(120) NOT NULL,   -- copiado: o log sobrevive ao cadastro
  telefone   VARCHAR(20)  NOT NULL,
  nome       VARCHAR(120) NOT NULL,
  quarto     VARCHAR(20)  NOT NULL,   -- o quarto DAQUELA visita
  entrada_em DATE         NOT NULL,
  dias       INT          NOT NULL,
  saida_em   DATETIME     NOT NULL,
  criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hosp_entrada (hospede_id, entrada_em),
  INDEX idx_rot_tel (roteador, telefone),
  INDEX idx_entrada (entrada_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: a estadia que cada hóspede já cadastrado está vivendo agora.
INSERT IGNORE INTO estadias (hospede_id, roteador, telefone, nome, quarto, entrada_em, dias, saida_em)
SELECT id, roteador, telefone, nome, quarto, entrada_em, dias, saida_em FROM hospedes;
