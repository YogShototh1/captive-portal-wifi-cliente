-- Migração: tipo de painel (varejo | hospedagem) + cadastro de hóspedes.
-- Rode UMA vez no phpMyAdmin, ou chame tools/migrar_hospedagem.php (idempotente).
--
-- O tipo fica na CONTA, como pedido no cadastro do cliente. O roteador herda:
-- se qualquer conta dona dele for de hospedagem, o portal daquele roteador passa
-- a pedir número de hóspede (ver roteador_modo() em inc/util.php) — um roteador
-- pode estar em várias contas desde a migração migrar_roteador_compartilhado.

ALTER TABLE compradores
  ADD COLUMN painel VARCHAR(20) NOT NULL DEFAULT 'varejo';

-- Um hóspede por (roteador, telefone): o número é a chave que o portal do
-- hotspot valida. Repetir o mesmo número no mesmo roteador seria ambíguo — na
-- prática é o mesmo hóspede voltando, e aí o cadastro é ATUALIZADO.
CREATE TABLE IF NOT EXISTS hospedes (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  roteador   VARCHAR(120) NOT NULL,   -- = $(identity); liga ao dono da pousada
  nome       VARCHAR(120) NOT NULL,
  quarto     VARCHAR(20)  NOT NULL,
  telefone   VARCHAR(20)  NOT NULL,
  entrada_em DATE         NOT NULL,   -- check-in
  dias       INT          NOT NULL,   -- diárias contratadas
  saida_em   DATETIME     NOT NULL,   -- entrada + dias, na hora do check-out
  criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rot_tel (roteador, telefone),
  INDEX idx_roteador (roteador),
  INDEX idx_saida (saida_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
