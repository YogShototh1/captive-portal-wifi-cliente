-- Nome de identificação do lead (opcional): quando preenchido, o painel mostra
-- o nome no lugar do número (o clique continua abrindo o WhatsApp do número).
-- Rode UMA vez no phpMyAdmin — ou use tools/migrar_nome.php (com admin_token).
ALTER TABLE leads ADD COLUMN nome VARCHAR(60) NULL AFTER telefone;
