<?php
// Resolve (reverse-DNS + rotulo "org") alguns IPs pendentes do host_cache.
// SOMENTE ADMIN. Chamado pelas telas de acessos ao abrir — preenche aos poucos,
// fora do caminho quente do roteador.
//   host = PTR (''=tentado sem nome).  org = rotulo legivel do destino:
//   servico conhecido (WhatsApp/Google/...), ou dominio registravel, ou
//   organizacao do ASN (p/ IP sem PTR). ''=tentado sem resultado.
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$comprador = comprador_logado();
if (!$comprador || (int) $comprador['is_admin'] !== 1) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'erro' => 'sem permissao']));
}

// PTR -> rotulo. Retorna [rotulo, precisaAsn]. precisaAsn=true quando nao ha PTR
// util (IP pelado) e vale a pena consultar o ASN.
function rotulo_destino($host, $ip)
{
    $h = strtolower(trim((string) $host));
    if ($h === '') {
        return [null, true];
    }
    $map = [
        'whatsapp' => 'WhatsApp',
        'cdninstagram' => 'Instagram', 'instagram' => 'Instagram',
        'fbcdn' => 'Facebook', 'fbsbx' => 'Facebook', 'facebook' => 'Facebook',
        'googlevideo' => 'YouTube', 'ytimg' => 'YouTube', 'youtube' => 'YouTube',
        '1e100.net' => 'Google', 'gstatic' => 'Google', 'googleusercontent' => 'Google',
        'googleapis' => 'Google', 'google' => 'Google',
        'tiktokcdn' => 'TikTok', 'ibytedtos' => 'TikTok', 'ibyteimg' => 'TikTok',
        'byteoversea' => 'TikTok', 'tiktok' => 'TikTok',
        'nflxvideo' => 'Netflix', 'nflximg' => 'Netflix', 'netflix' => 'Netflix',
        'cloudfront' => 'AWS CloudFront (site oculto)',
        'cloudflare' => 'Cloudflare (site oculto)',
        'akadns' => 'Akamai (site oculto)', 'akamai' => 'Akamai (site oculto)',
        'fastly' => 'Fastly (site oculto)',
        'twimg' => 'X (Twitter)', 'twitter' => 'X (Twitter)',
        'telegram' => 'Telegram',
        'spotify' => 'Spotify',
        'aaplimg' => 'Apple', 'icloud' => 'Apple', 'apple' => 'Apple',
        'windowsupdate' => 'Microsoft', 'azureedge' => 'Microsoft', 'msedge' => 'Microsoft',
        'msftncsi' => 'Microsoft', 'microsoft' => 'Microsoft',
        'mlstatic' => 'Mercado Livre', 'mercadolivre' => 'Mercado Livre', 'mercadolibre' => 'Mercado Livre',
    ];
    foreach ($map as $needle => $label) {
        if (strpos($h, $needle) !== false) {
            return [$label, false];
        }
    }
    // Sem servico conhecido: usa o dominio registravel do proprio PTR.
    return [dominio_registravel($h), false];
}

function dominio_registravel($h)
{
    $p = explode('.', $h);
    $n = count($p);
    if ($n < 2) {
        return $h;
    }
    // .com.br, .co.uk, etc: pega 3 rotulos.
    $dois = ['com', 'net', 'org', 'gov', 'edu', 'co', 'mil'];
    if ($n >= 3 && in_array($p[$n - 2], $dois, true) && strlen($p[$n - 1]) === 2) {
        return $p[$n - 3] . '.' . $p[$n - 2] . '.' . $p[$n - 1];
    }
    return $p[$n - 2] . '.' . $p[$n - 1];
}

// Organizacao dona do IP (ASN) via ip-api.com (gratis, http). So p/ IP sem PTR.
function asn_org($ip)
{
    $url  = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,org,isp';
    $body = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_USERAGENT => 'captivedata']);
        $body = (string) curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx  = stream_context_create(['http' => ['timeout' => 3]]);
        $body = (string) @file_get_contents($url, false, $ctx);
    }
    $d = json_decode($body, true);
    if (!is_array($d) || ($d['status'] ?? '') !== 'success') {
        return '';
    }
    $org = trim((string) ($d['org'] ?? '')) ?: trim((string) ($d['isp'] ?? ''));
    return substr($org, 0, 120);
}

$n = 0;
try {
    $rows = db()->query('SELECT ip, host FROM host_cache WHERE host IS NULL OR org IS NULL ORDER BY em DESC LIMIT 12')
        ->fetchAll(PDO::FETCH_ASSOC);
    $upH = db()->prepare('UPDATE host_cache SET host = ? WHERE ip = ?');
    $upO = db()->prepare('UPDATE host_cache SET org = ? WHERE ip = ?');
    foreach ($rows as $r) {
        $ip   = $r['ip'];
        $host = $r['host'];
        if ($host === null) {
            $ptr  = @gethostbyaddr($ip);
            $host = ($ptr && $ptr !== $ip) ? substr($ptr, 0, 190) : '';
            $upH->execute([$host, $ip]);
        }
        list($label, $precisaAsn) = rotulo_destino($host, $ip);
        if ($precisaAsn) {
            $label = asn_org($ip);
        }
        $upO->execute([substr((string) $label, 0, 120), $ip]);
        $n++;
    }
} catch (Throwable $e) {
    // host_cache ainda nao existe: nada a resolver.
}

echo json_encode(['ok' => true, 'resolvidos' => $n]);
