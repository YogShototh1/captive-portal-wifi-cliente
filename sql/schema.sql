-- Esquema do banco (MySQL 8) — Captive Portal (HostGator compartilhado)
-- Na HostGator o banco é criado pelo cPanel (MySQL Databases). Depois importe
-- estas tabelas pelo phpMyAdmin (aba Importar) OU cole na aba SQL.

CREATE TABLE IF NOT EXISTS compradores (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  nome         VARCHAR(120),
  email        VARCHAR(190) UNIQUE,
  senha_hash   VARCHAR(255),
  roteador_id  VARCHAR(120) UNIQUE,   -- LEGADO: substituída pela tabela `roteadores` (mantida p/ rollback)
  is_admin     TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = administrador (você)
  portal_habilitado TINYINT(1) NOT NULL DEFAULT 0, -- 1 = pode enviar a página de login do hotspot
  criado_em    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Roteadores (MikroTiks) vinculados a cada conta — uma conta pode ter vários.
CREATE TABLE IF NOT EXISTS roteadores (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  comprador_id INT NOT NULL,
  identity     VARCHAR(120) NOT NULL UNIQUE,  -- = $(identity) do MikroTik; único no sistema todo
  INDEX idx_comprador (comprador_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1 linha por número (roteador+telefone). Guarda a info da ÚLTIMA conexão.
CREATE TABLE IF NOT EXISTS leads (
  id                 BIGINT AUTO_INCREMENT PRIMARY KEY,
  roteador           VARCHAR(120),         -- = $(identity); liga ao comprador
  telefone           VARCHAR(20),
  nome               VARCHAR(60) NULL,     -- identificação opcional (painel mostra no lugar do número)
  mac                VARCHAR(20),
  ip                 VARCHAR(45),
  dispositivo        VARCHAR(60),          -- ex.: "iOS 18", "Android 14"
  conectado_em       TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- início da última conexão
  primeira_conexao   TIMESTAMP NULL,       -- primeira vez que este número conectou
  total_conexoes     INT NOT NULL DEFAULT 1,
  desconectado_em    TIMESTAMP NULL,       -- preenchido pela sincronização do MikroTik
  segundos_conectado INT NULL,             -- tempo final quando sai (offline)
  tempo_limite_min   INT NULL,             -- limite definido pelo cliente (min); NULL = sem limite
  banda_limite       INT NULL,             -- limite de banda por usuário (Mbps); NULL = ilimitado
  online             TINYINT(1) NOT NULL DEFAULT 0, -- 1 = sessão ativa agora
  visto_em           TIMESTAMP NULL,       -- última vez que o MikroTik reportou online
  consentimento      BOOLEAN DEFAULT FALSE, -- LGPD
  UNIQUE KEY uq_roteador_telefone (roteador, telefone),
  INDEX idx_roteador (roteador),
  INDEX idx_mac (mac)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico: cada vez que um número conecta, entra uma linha aqui.
CREATE TABLE IF NOT EXISTS conexoes (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  lead_id      BIGINT NOT NULL,
  conectado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  mac          VARCHAR(20),
  ip           VARCHAR(45),
  dispositivo  VARCHAR(60),
  segundos     INT NULL,             -- duração da sessão; preenchida quando ela termina (status.php)
  bytes        BIGINT NULL,          -- consumo da sessão (bytes-in+out, reportado pelo MikroTik)
  INDEX idx_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Controle de força bruta do login do painel. O código cria esta tabela
-- sozinho na primeira falha de login — está aqui só como documentação.
CREATE TABLE IF NOT EXISTS login_tentativas (
  ip         VARCHAR(45) NOT NULL PRIMARY KEY,
  tentativas INT NOT NULL DEFAULT 0,
  ultima     TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Banco JÁ existente? Rode o sql/migracao_dedup.sql (phpMyAdmin) UMA vez.
