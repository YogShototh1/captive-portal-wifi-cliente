-- Consumo de dados por conexão (bytes-in + bytes-out da sessão, reportados
-- pelo MikroTik via leadsync.rsc -> api/status.php). O total do lead é a soma.
-- Rode UMA vez no phpMyAdmin — ou use tools/migrar_bytes.php (com admin_token).
ALTER TABLE conexoes ADD COLUMN bytes BIGINT NULL AFTER segundos;
