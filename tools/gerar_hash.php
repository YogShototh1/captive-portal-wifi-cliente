<?php
// Gera um hash bcrypt de uma senha, para inserir um comprador manualmente pelo
// phpMyAdmin (quando você não tem acesso ao terminal).
//
// Navegador:  /tools/gerar_hash.php?token=SEU_ADMIN_TOKEN&senha=SUASENHA
//             (token = admin_token do inc/config.php)
// CLI:        php tools/gerar_hash.php SUASENHA
//
// >>> APAGUE este arquivo depois de usar. <<<

require_once __DIR__ . '/../inc/db.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    // Sem o token do config.php, ninguém usa a ferramenta pela web.
    // hash_equals: comparação em tempo constante (não vaza o token por timing).
    if (!hash_equals((string) config()['admin_token'], (string) ($_GET['token'] ?? ''))) {
        http_response_code(403);
        exit("token invalido\n");
    }
}

$senha = (PHP_SAPI === 'cli') ? ($argv[1] ?? '') : ($_GET['senha'] ?? '');
if ($senha === '') {
    exit("Passe a senha: ?token=...&senha=... (navegador) ou como argumento (CLI)\n");
}

echo password_hash($senha, PASSWORD_BCRYPT) . "\n";
