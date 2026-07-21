<?php
// Sessão + autenticação do comprador (login por e-mail/senha, bcrypt).
require_once __DIR__ . '/db.php';

function sessao_iniciar(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // Endurecimento: só aceita ID de sessão criado pelo servidor e só via
        // cookie (bloqueia fixação de sessão e ID de sessão na URL).
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'httponly' => true,
            'secure'   => $https,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

// Retorna o comprador logado (array) ou null.
function comprador_logado(): ?array
{
    sessao_iniciar();
    if (empty($_SESSION['comprador_id'])) {
        return null;
    }
    static $c = null;
    if ($c === null) {
        // roteador_id (legado) fora do SELECT de propósito: os roteadores da
        // conta agora vêm da tabela `roteadores` (helper roteadores_conta).
        $q = db()->prepare('SELECT id, nome, email, is_admin FROM compradores WHERE id = ?');
        $q->execute([$_SESSION['comprador_id']]);
        $c = $q->fetch() ?: null;
    }
    return $c;
}

// True se o usuário logado for administrador.
function is_admin(): bool
{
    $c = comprador_logado();
    return $c && (int) $c['is_admin'] === 1;
}

// Exige que o logado seja admin; senão manda para o painel comum.
function exigir_admin(): array
{
    $c = exigir_login();
    if ((int) $c['is_admin'] !== 1) {
        header('Location: painel.php');
        exit;
    }
    return $c;
}

// Exige login; se não houver, redireciona para a tela de login.
function exigir_login(): array
{
    $c = comprador_logado();
    if (!$c) {
        header('Location: entrar.php'); // tela de login (dentro da casca)
        exit;
    }
    return $c;
}

function tentar_login(string $email, string $senha): bool
{
    $q = db()->prepare('SELECT id, senha_hash FROM compradores WHERE email = ?');
    $q->execute([$email]);
    $row = $q->fetch();
    // Sempre verifica um hash (dummy quando o e-mail não existe): o tempo de
    // resposta fica igual e não dá para descobrir quais e-mails têm conta.
    $hash = $row ? (string) $row['senha_hash']
                 : '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    $ok = password_verify($senha, $hash);
    if (!$row || !$ok) {
        return false;
    }
    sessao_iniciar();
    session_regenerate_id(true);
    unset($_SESSION['csrf']); // novo token CSRF para a sessão autenticada
    $_SESSION['comprador_id'] = (int) $row['id'];
    return true;
}

function logout(): void
{
    sessao_iniciar();
    $_SESSION = [];
    // Invalida também o cookie no navegador (não só a sessão no servidor).
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

// --- CSRF ---
function csrf_token(): string
{
    sessao_iniciar();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valido(?string $t): bool
{
    sessao_iniciar();
    return !empty($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

// --- Proteção contra força bruta no login (por IP) ---
// Após LOGIN_MAX_FALHAS erros dentro da janela, o IP espera a janela passar.
// A tabela é criada automaticamente na primeira falha — não precisa migrar nada.
const LOGIN_MAX_FALHAS = 8;
const LOGIN_JANELA_SEG = 900; // 15 minutos

function login_tentativas_tabela(): void
{
    static $ok = false;
    if ($ok) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS login_tentativas (
            ip         VARCHAR(45) NOT NULL PRIMARY KEY,
            tentativas INT NOT NULL DEFAULT 0,
            ultima     TIMESTAMP NULL
        )'
    );
    $ok = true;
}

// True se este IP estourou o limite de falhas dentro da janela.
function login_bloqueado(string $ip): bool
{
    if ($ip === '') {
        return false;
    }
    try {
        login_tentativas_tabela();
        $q = db()->prepare('SELECT tentativas, ultima FROM login_tentativas WHERE ip = ?');
        $q->execute([$ip]);
        $r = $q->fetch();
        if (!$r || $r['ultima'] === null) {
            return false;
        }
        $idade = strtotime(db_now()) - strtotime((string) $r['ultima']);
        if ($idade > LOGIN_JANELA_SEG) {
            return false; // janela expirou; o contador reinicia na próxima falha
        }
        return (int) $r['tentativas'] >= LOGIN_MAX_FALHAS;
    } catch (Throwable $e) {
        return false; // o controle nunca pode derrubar o login por erro interno
    }
}

function login_registrar_falha(string $ip): void
{
    if ($ip === '') {
        return;
    }
    try {
        login_tentativas_tabela();
        $agora = db_now();
        $q = db()->prepare('SELECT tentativas, ultima FROM login_tentativas WHERE ip = ?');
        $q->execute([$ip]);
        $r = $q->fetch();
        if (!$r) {
            db()->prepare('INSERT INTO login_tentativas (ip, tentativas, ultima) VALUES (?, 1, ?)')
                ->execute([$ip, $agora]);
            return;
        }
        $idade = strtotime($agora) - strtotime((string) $r['ultima']);
        $n = ($idade > LOGIN_JANELA_SEG) ? 1 : ((int) $r['tentativas'] + 1);
        db()->prepare('UPDATE login_tentativas SET tentativas = ?, ultima = ? WHERE ip = ?')
            ->execute([$n, $agora, $ip]);
    } catch (Throwable $e) {
        // silencioso: nunca interrompe o fluxo de login
    }
}

function login_limpar_falhas(string $ip): void
{
    if ($ip === '') {
        return;
    }
    try {
        login_tentativas_tabela();
        db()->prepare('DELETE FROM login_tentativas WHERE ip = ?')->execute([$ip]);
    } catch (Throwable $e) {
        // silencioso
    }
}
