<?php
// O roteador manda o que o CACHE DE DNS dele resolveu: pares nome|IP.
//
// Por que isto existe: o log de acessos guarda IP de destino, e devolver o site
// a partir do IP não funciona mais. Metade da internet mora atrás de CDN, onde
// um IP atende centenas de milhares de sites — o reverse-DNS (api/resolver.php)
// devolve "cloudflare" e o painel escreve "site oculto". A lista de DNS
// resolvido que se baixa na internet não resolve isso: invertida, ela devolve
// TODOS os domínios daquele IP, não o que o cliente pediu.
//
// Quem sabe o nome exato é o próprio roteador, no instante em que o cliente
// perguntou — desde que o MikroTik seja o servidor DNS dos clientes
// (/ip dhcp-server network, dns-server = o IP do próprio MikroTik).
//
// Auth: token = admin_token (igual ao acesso.php/status.php).
//
// Formato do corpo:  d=nome1|ip1;nome2|ip2;...
// ';' e '|' são seguros: nome de host é [a-z0-9.-] e IP é dígito e ponto, então
// nada aqui precisa de escape.
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/util.php';

$cfg   = config();
$token = (string) ($_REQUEST['token'] ?? '');
if (!hash_equals((string) $cfg['admin_token'], $token)) {
    http_response_code(403);
    exit('');
}
$roteador = trim((string) ($_REQUEST['roteador'] ?? ''));
if ($roteador === '') {
    http_response_code(400);
    exit('');
}

mikrotik_tocar($roteador);
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// Auto-heal, no mesmo estilo do acesso.php: a coluna nasce aqui para quem já
// tem o host_cache criado de antes.
try { db()->exec('ALTER TABLE host_cache ADD COLUMN dns VARCHAR(190) NULL'); } catch (Throwable $e) {}

// SÓ ATUALIZA LINHA QUE JÁ EXISTE. De propósito: o cache do roteador tem tudo
// que ele resolveu, inclusive nome que ninguém chegou a acessar, e inserir tudo
// encheria o host_cache de IP que nunca vai aparecer no log. Quem cria a linha
// é o api/acesso.php, quando o acesso acontece de verdade.
//
// O nome pode chegar depois do acesso: o roteador manda este lote a cada
// rodada, então o IP que apareceu agora ganha o nome na volta seguinte,
// enquanto a entrada ainda está no cache dele.
$up = null;
$n  = 0;
try {
    $up = db()->prepare('UPDATE host_cache SET dns = ? WHERE ip = ?');
} catch (Throwable $e) {
    exit('0');
}

// Teto de 400 pares por rodada: o /tool fetch corta o http-data em algum ponto
// entre 8 e 64 KB (medido), então o roteador já manda pouco. Este teto é só
// para uma requisição forjada não virar trabalho infinito.
$pares = explode(';', (string) ($_REQUEST['d'] ?? ''));
foreach (array_slice($pares, 0, 400) as $par) {
    $bar = strpos($par, '|');
    if ($bar === false) {
        continue;
    }
    $nome = strtolower(trim(substr($par, 0, $bar)));
    $ip   = trim(substr($par, $bar + 1));

    // Nome de host de verdade: precisa de ponto e nada além do alfabeto de DNS.
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        continue;
    }
    if (strpos($nome, '.') === false || !preg_match('/^[a-z0-9][a-z0-9._-]{1,188}$/', $nome)) {
        continue;
    }
    try {
        $up->execute([$nome, $ip]);
        $n++;
    } catch (Throwable $e) {
        // Uma linha ruim não derruba o lote.
    }
}

echo (string) $n;
