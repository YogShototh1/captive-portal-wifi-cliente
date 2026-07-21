-- ============================================================
--  MIGRAÇÃO (rode UMA vez no phpMyAdmin, aba SQL, do seu banco).
--  Faz: 1 linha por número + histórico de conexões + coluna de aparelho.
--  Seguro rodar mesmo com dados existentes.
-- ============================================================

-- 1) Tabela de histórico de conexões
CREATE TABLE IF NOT EXISTS conexoes (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  lead_id      BIGINT NOT NULL,
  conectado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  mac          VARCHAR(20),
  ip           VARCHAR(45),
  dispositivo  VARCHAR(60),
  INDEX idx_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Novas colunas em leads
ALTER TABLE leads
  ADD COLUMN dispositivo VARCHAR(60) NULL AFTER ip,
  ADD COLUMN primeira_conexao TIMESTAMP NULL AFTER conectado_em,
  ADD COLUMN total_conexoes INT NOT NULL DEFAULT 1 AFTER primeira_conexao;

-- 3) Cada lead atual vira uma conexão no histórico, já apontando para o lead
--    "mantido" (o mais recente de cada roteador+telefone).
INSERT INTO conexoes (lead_id, conectado_em, mac, ip, dispositivo)
SELECT
  (SELECT l2.id FROM leads l2
     WHERE l2.roteador = l.roteador AND l2.telefone = l.telefone
     ORDER BY l2.conectado_em DESC, l2.id DESC LIMIT 1),
  l.conectado_em, l.mac, l.ip, l.dispositivo
FROM leads l;

-- 4) Apaga os duplicados, mantendo só o mais recente de cada número.
DELETE l FROM leads l
JOIN leads l2
  ON l.roteador = l2.roteador AND l.telefone = l2.telefone
  AND (l.conectado_em < l2.conectado_em
       OR (l.conectado_em = l2.conectado_em AND l.id < l2.id));

-- 5) Atualiza total de conexões e a primeira conexão de cada número.
UPDATE leads l SET
  total_conexoes   = GREATEST(1, (SELECT COUNT(*) FROM conexoes c WHERE c.lead_id = l.id)),
  primeira_conexao = (SELECT MIN(c.conectado_em) FROM conexoes c WHERE c.lead_id = l.id);

-- 6) Garante 1 número por roteador daqui pra frente.
ALTER TABLE leads ADD UNIQUE KEY uq_roteador_telefone (roteador, telefone);
