<?php
// Trava do nome do destino no log de acessos.
//
// O que isto protege: a ORDEM de preferência. O nome que o cliente resolveu
// (dns, vindo do cache do roteador) tem que ganhar do reverse-DNS (host) —
// senão um IP de CDN volta a aparecer como "cloudflare" na tela, que era o
// problema todo. A ordem vive em três lugares (a tela, as duas planilhas) e
// desandaria sozinha; por isso o destino_nome() e este teste.
//
// Rodar:  php tools/teste_dns_nomes.php
// Teste de linha de comando, nunca pela web. O tools/.htaccess ja bloqueia a
// pasta; esta guarda existe para o bloqueio nao depender de o servidor honrar
// aquele arquivo.
if (PHP_SAPI !== "cli") { http_response_code(404); exit; }

require_once __DIR__ . '/../inc/util.php';

$falhas = 0;
$ok = function ($cond, $nome, $viu = '') use (&$falhas) {
    if ($cond) { echo "  ok   $nome\n"; }
    else { $falhas++; echo "  FALHOU  $nome  $viu\n"; }
};

echo "ordem de preferencia do nome do destino\n";
$ok(destino_nome(['dns' => 'scontent.cdninstagram.com', 'host' => 'cloudflare.com'])
    === 'scontent.cdninstagram.com', 'o nome que o cliente resolveu ganha do PTR');
$ok(destino_nome(['dns' => null, 'host' => 'lb-140-82-121-4.github.com'])
    === 'lb-140-82-121-4.github.com', 'sem cache de DNS, vale o PTR');
$ok(destino_nome(['dns' => '', 'host' => 'x.com']) === 'x.com', 'dns vazio nao mascara o PTR');
$ok(destino_nome(['dns' => 'a.com', 'host' => '']) === 'a.com', 'PTR vazio nao atrapalha o dns');
$ok(destino_nome(['dns' => null, 'host' => null]) === null, 'sem nenhum dos dois, null (quem chama mostra o IP)');
$ok(destino_nome(['dns' => '   ', 'host' => '  ']) === null, 'so espaco nao conta como nome');
$ok(destino_nome([]) === null, 'linha antiga, sem as colunas, nao quebra');

// ---------------------------------------------------------------
// A validacao do endpoint, copiada do api/dns_nomes.php. O que ela impede:
// um POST forjado gravar lixo (ou HTML) no lugar do nome do site.
echo "\nvalidacao dos pares nome|ip\n";
$aceita = function (string $nome, string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { return false; }
    $nome = strtolower(trim($nome));
    return strpos($nome, '.') !== false && (bool) preg_match('/^[a-z0-9][a-z0-9._-]{1,188}$/', $nome);
};
$ok($aceita('scontent.cdninstagram.com', '31.13.85.4'), 'par normal passa');
$ok($aceita('WWW.Google.COM', '142.250.79.4'), 'maiuscula passa (vira minuscula)');
$ok($aceita('gw-01.loja.com.br', '200.1.2.3'), 'hifen e subdominio passam');
$ok(!$aceita('semponto', '1.2.3.4'), 'nome sem ponto nao e host');
$ok(!$aceita('<script>x</script>.com', '1.2.3.4'), 'HTML nao entra');
$ok(!$aceita("a.com', 'b", '1.2.3.4'), 'aspas nao entram');
$ok(!$aceita('site.com', 'nao-e-ip'), 'IP invalido derruba o par');
$ok(!$aceita('site.com', '2001:db8::1'), 'IPv6 fica de fora (o log guarda IPv4)');
$ok(!$aceita('.com', '1.2.3.4'), 'nome comecando com ponto nao passa');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
