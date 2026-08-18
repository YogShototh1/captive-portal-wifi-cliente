-- Banda máxima por HÓSPEDE (ver tools/migrar_banda_hospede.php).
--
-- Antes o teto morava só em `leads.banda_limite`, e lead só existe depois da
-- primeira conexão: não dava para deixar o limite pronto no check-in. Agora o
-- cadastro guarda o dele, e api/lead.php o copia para o lead que nascer.
ALTER TABLE hospedes ADD COLUMN banda_limite INT NULL;

-- Quem já teve o limite ajustado pela tabela de leads não perde o ajuste.
UPDATE hospedes h
   SET h.banda_limite = (SELECT MAX(l.banda_limite) FROM leads l
                          WHERE l.roteador = h.roteador AND l.telefone = h.telefone)
 WHERE h.banda_limite IS NULL;
