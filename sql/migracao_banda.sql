-- Migração: limite de banda por usuário (Mbps). Rode UMA vez no phpMyAdmin
-- (aba SQL) se o banco já existia antes desta função. Bancos novos já vêm
-- com a coluna pelo schema.sql.
ALTER TABLE leads ADD COLUMN banda_limite INT NULL AFTER tempo_limite_min;
