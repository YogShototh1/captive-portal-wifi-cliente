-- Migração: tempo online por conexão (rode UMA vez no phpMyAdmin).
-- Adiciona a duração de cada sessão ao histórico de conexões — preenchida pela
-- sincronização do MikroTik (api/status.php) quando a sessão termina.
-- Bancos novos já vêm com a coluna pelo schema.sql.

ALTER TABLE conexoes ADD COLUMN segundos INT NULL;
