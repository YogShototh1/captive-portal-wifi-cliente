<?php
// Medidor de velocidade da aba "Teste de velocidade".
//
// O que ele mede, exatamente: a velocidade entre o aparelho onde o painel está
// aberto e ESTE servidor. Não é o Speedtest — lá a medição usa um servidor
// próximo e várias conexões em paralelo. Aqui o servidor é sempre o mesmo (a
// hospedagem), então o número serve para comparar (o Wi-Fi da loja está pior
// que ontem?) mais do que para cravar a banda contratada.
//
//   ?f=ping   resposta mínima, para medir a latência
//   ?f=down&mb=N   N MB de dados incompressíveis
//   POST f=up      recebe o corpo e responde o tamanho lido
//
// Autenticado por sessão: sem isso o endereço viraria um gerador de tráfego
// aberto na conta de hospedagem.
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/auth.php';

if (!comprador_logado()) {
    http_response_code(401);
    exit('');
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

$f = (string) ($_GET['f'] ?? 'ping');

if ($f === 'ping') {
    header('Content-Type: text/plain');
    exit('.');
}

if ($f === 'up') {
    // Só interessa quanto chegou: o corpo é lido e descartado.
    $n = 0;
    $in = fopen('php://input', 'rb');
    if ($in) {
        while (!feof($in)) {
            $b = fread($in, 65536);
            if ($b === false) { break; }
            $n += strlen($b);
        }
        fclose($in);
    }
    header('Content-Type: text/plain');
    exit((string) $n);
}

if ($f === 'down') {
    // Teto de 24 MB: o suficiente para medir link rápido sem virar um jeito de
    // torrar a franquia de tráfego da hospedagem.
    $mb = max(1, min(24, (int) ($_GET['mb'] ?? 8)));

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . ($mb * 1024 * 1024));
    header('Content-Encoding: identity');   // nada de gzip por cima

    // Bloco aleatório: se fossem zeros, qualquer compressão no caminho
    // (proxy, CDN) inflaria o resultado — mediria compressão, não banda.
    $bloco = random_bytes(65536);
    $vezes = $mb * 16;                       // 16 blocos de 64 KB = 1 MB
    while (@ob_get_level() > 0) { @ob_end_clean(); }
    for ($i = 0; $i < $vezes; $i++) {
        echo $bloco;
        if ($i % 16 === 0) { flush(); }      // manda a cada MB
        if (connection_aborted()) { break; } // o navegador desistiu: para de gerar
    }
    exit;
}

http_response_code(400);
exit('');
