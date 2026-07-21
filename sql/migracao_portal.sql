-- Migração: habilita, por usuário, o bloco de upload da página de login do hotspot.
-- Rode UMA vez no phpMyAdmin (aba SQL) se o banco já existia antes desta função.
-- Bancos novos já vêm com a coluna pelo schema.sql.
ALTER TABLE compradores ADD COLUMN portal_habilitado TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin;
