<?php
// Configuração + conexão PDO (MySQL). Reutilizável em todo o app.

// Na web, nunca exibe erros na tela: mensagens de exceção podem vazar
// caminhos do servidor e detalhes internos. Os erros continuam indo pro log.
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
}

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/config.php';
        if (!is_file($path)) {
            http_response_code(500);
            exit('Config ausente: copie inc/config.example.php para inc/config.php');
        }
        $cfg = require $path;
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config();
        // 'dsn' permite apontar para outro banco (ex.: sqlite em teste). Em produção
        // fica vazio e usa MySQL montado a partir dos campos db_*.
        $dsn = !empty($c['dsn'])
            ? $c['dsn']
            : "mysql:host={$c['db_host']};dbname={$c['db_name']};charset={$c['db_charset']}";
        $pdo = new PDO($dsn, $c['db_user'] ?? null, $c['db_pass'] ?? null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// Hora atual do BANCO (mesma referência dos TIMESTAMP gravados). Portável MySQL/SQLite.
// Comparar sempre contra esta hora evita problemas de fuso entre PHP e o banco.
function db_now(): string
{
    $drv = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sql = ($drv === 'sqlite') ? "SELECT datetime('now')" : 'SELECT NOW()';
    return (string) db()->query($sql)->fetchColumn();
}
