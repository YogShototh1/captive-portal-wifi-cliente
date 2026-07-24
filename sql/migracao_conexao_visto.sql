-- Rastreio de sessao POR CONEXAO (por aparelho/MAC), nao mais por lead.
-- Com 2 aparelhos no mesmo numero, cada um tem sua duracao/consumo. O status.php
-- ja tenta criar esta coluna sozinho (auto-heal); este arquivo e so documentacao
-- / para rodar na mao no phpMyAdmin se preferir.
ALTER TABLE conexoes ADD COLUMN visto_em TIMESTAMP NULL AFTER bytes;
