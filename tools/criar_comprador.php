<?php
// Cria um usuário. Use para criar o PRIMEIRO ADMIN (depois use a tela de admin).
//
// CLI (cPanel Terminal/SSH):
//   php tools/criar_comprador.php "Nome" email@ex.com senha123 ROTEADOR_ID        (cliente)
//   php tools/criar_comprador.php "Admin"  voce@ex.com  senha123 -           admin  (admin)
//
// Navegador (precisa do admin_token do config.php):
//   /tools/criar_comprador.php?token=SEU_TOKEN&nome=..&email=..&senha=..&roteador_id=..[&is_admin=1]
//
// ROTEADOR_ID deve ser IGUAL ao $(identity) do MikroTik do cliente. Para admin,
// deixe "-" (CLI) ou vazio (web) e marque is_admin.

require_once __DIR__ . '/../inc/db.php';

$isCli = (PHP_SAPI === 'cli');

if ($isCli) {
    $nome    = $argv[1] ?? null;
    $email   = $argv[2] ?? null;
    $senha   = $argv[3] ?? null;
    $rot     = $argv[4] ?? null;
    $isAdmin = (isset($argv[5]) && in_array(strtolower($argv[5]), ['admin', '1', 'sim'], true)) ? 1 : 0;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    // hash_equals: comparação em tempo constante (não vaza o token por timing).
    if (!hash_equals((string) config()['admin_token'], (string) ($_REQUEST['token'] ?? ''))) {
        http_response_code(403);
        exit("token invalido\n");
    }
    $nome    = $_REQUEST['nome'] ?? null;
    $email   = $_REQUEST['email'] ?? null;
    $senha   = $_REQUEST['senha'] ?? null;
    $rot     = $_REQUEST['roteador_id'] ?? null;
    $isAdmin = !empty($_REQUEST['is_admin']) ? 1 : 0;
}

// Normaliza roteador: "-" ou vazio => NULL (usado pelos admins).
if ($rot === '-' || $rot === '') {
    $rot = null;
}

if (!$email || !$senha || (!$isAdmin && !$rot)) {
    exit("Faltam dados.\n" .
         "CLI cliente: php criar_comprador.php \"Nome\" email senha ROTEADOR_ID\n" .
         "CLI admin:   php criar_comprador.php \"Nome\" email senha - admin\n");
}

$hash = password_hash($senha, PASSWORD_BCRYPT);

try {
    $stmt = db()->prepare(
        'INSERT INTO compradores (nome, email, senha_hash, is_admin) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$nome, $email, $hash, $isAdmin]);
    // Vínculo conta -> roteador na tabela nova (multi-MikroTik). Mais roteadores:
    // adicione pela tela de administração (um por linha).
    if ($rot) {
        db()->prepare('INSERT INTO roteadores (comprador_id, identity) VALUES (?, ?)')
            ->execute([(int) db()->lastInsertId(), $rot]);
    }
    $tipo = $isAdmin ? 'ADMIN' : 'cliente';
    echo "OK: {$tipo} criado -> {$email}" . ($rot ? " (roteador={$rot})" : "") . "\n";
} catch (PDOException $e) {
    echo ($e->getCode() === '23000') ? "Erro: e-mail ou roteador ja existe.\n" : "Erro ao inserir.\n";
}
