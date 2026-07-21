-- Migração: multi-MikroTik por conta (rode UMA vez no phpMyAdmin).
-- Cria a tabela de vínculo conta -> roteadores e importa o roteador que cada
-- conta já tinha em compradores.roteador_id. Idempotente (IGNORE pula repetidos).
-- A coluna compradores.roteador_id vira legado: o código não a lê nem escreve
-- mais (fica só como rollback; pode ser removida no futuro).

CREATE TABLE IF NOT EXISTS roteadores (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  comprador_id INT NOT NULL,
  identity     VARCHAR(120) NOT NULL UNIQUE,  -- = $(identity) do MikroTik; único no sistema todo
  INDEX idx_comprador (comprador_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roteadores (comprador_id, identity)
  SELECT id, roteador_id FROM compradores
   WHERE roteador_id IS NOT NULL AND roteador_id <> '';
