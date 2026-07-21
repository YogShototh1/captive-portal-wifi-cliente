<?php
// Copie este arquivo para config.php e preencha com os seus dados.
// NUNCA versione o config.php real (ele tem a senha do banco).

return [
    // Banco de dados — crie no cPanel > "Bancos de Dados MySQL".
    // Na HostGator o nome costuma vir com prefixo, ex.: "cpaneluser_captive".
    'db_host'   => 'localhost',
    'db_name'   => 'SEU_BANCO',
    'db_user'   => 'SEU_USUARIO_DB',
    'db_pass'   => 'SUA_SENHA_DB',
    'db_charset'=> 'utf8mb4',

    // Origens permitidas no CORS do /api/lead. '*' funciona (endpoint público);
    // se quiser, restrinja ao host do seu hotspot.
    'cors_origin' => '*',

    // Limite simples do /api/lead por IP numa janela de 60s (0 = desliga).
    // Obs.: atrás do MikroTik, os clientes de um mesmo local compartilham o IP
    // público — por isso o limite é generoso.
    'lead_rate_limit' => 120,

    // Token para rodar as ferramentas em /tools (troque por algo aleatório e longo).
    'admin_token' => 'TROQUE_ESTE_TOKEN_POR_ALGO_ALEATORIO',
];
