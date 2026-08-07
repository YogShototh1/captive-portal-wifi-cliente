<?php
// Teste de velocidade DO ROTEADOR: mede a internet que chega no MikroTik do
// cliente, sem ninguém ir até a loja.
//
// Quem mede é o SERVIDOR, não o RouterOS. O roteador só pede o download; este
// arquivo cronometra quanto tempo levou para empurrar os bytes até ele e faz a
// conta. Três motivos:
//   - não depende do relógio do RouterOS nem de ele saber reportar a duração;
//   - não grava nada na flash do roteador (a do hEX Gr3 tem 16 MB e vive cheia);
//   - o resultado chega ao painel mesmo se o roteador cair no meio do teste.
//
// O ping é a única parte que o servidor não consegue medir sozinho: esse o
// roteador manda depois, junto com o "acabou".
//
//   ?f=req    o roteador pergunta se há teste pedido (e o pedido é consumido)
//   ?f=down   o roteador baixa; aqui se mede o tempo e se grava o resultado
//   ?f=res    o roteador reporta o ping e o que o RouterOS achou da duração
//
// Auth: token = admin_token (igual ao status.php/tema.php).
ini_set('display_errors', '0');
set_time_limit(120);

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
header('X-Content-Type-Options: nosniff');
// no-transform: o Cloudflare está na frente e, sem isto, ele comprime a
// resposta e troca o Content-Length por Transfer-Encoding: chunked. Os dois
// atrapalham — o /tool fetch do RouterOS abortava o download, e o gzip sobre
// bytes aleatórios ainda INFLA o corpo (mediria compressão, não banda).
header('Cache-Control: no-store, no-transform');

$f = (string) ($_REQUEST['f'] ?? '');

// --- O roteador pergunta se tem teste para rodar -------------------------
// O pedido é consumido aqui: se o teste falhar no meio, o comprador pede de
// novo. Melhor que um pedido preso repetindo o download a cada rodada e
// comendo a franquia da loja.
if ($f === 'req') {
    header('Content-Type: text/plain; charset=utf-8');
    $mb = speed_pedido_ler($roteador);
    exit($mb > 0 ? (string) $mb : '0');
}

// --- O download cronometrado --------------------------------------------
if ($f === 'down') {
    $mb = max(1, min(24, (int) ($_REQUEST['mb'] ?? 6)));

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . ($mb * 1024 * 1024));
    // Nada de Content-Encoding aqui: declarar "identity" fazia o Cloudflare
    // acrescentar um segundo cabeçalho ("gzip") e a resposta chegava com dois,
    // que é inválido. Quem resolve isso é o no-transform, lá em cima.

    // Bytes aleatórios: com zeros, qualquer compressão no caminho inflaria o
    // número — mediria compressão, não banda.
    $bloco = random_bytes(65536);
    while (@ob_get_level() > 0) { @ob_end_clean(); }

    // O cronômetro começa DEPOIS do primeiro bloco: o primeiro carrega o
    // aperto de mão TLS e o tempo de resposta, que não são velocidade.
    echo $bloco;
    flush();
    $t0 = microtime(true);
    $enviados = 0;
    $vezes = $mb * 16 - 1;                       // 16 blocos de 64 KB = 1 MB
    for ($i = 0; $i < $vezes; $i++) {
        echo $bloco;
        $enviados += 65536;
        if ($i % 8 === 0) { flush(); }
        if (connection_aborted()) { break; }     // o roteador desistiu
    }
    flush();
    // NÃO se calcula velocidade aqui. Há um Cloudflare na frente da hospedagem:
    // ele engole a resposta inteira depressa e só então a repassa ao roteador,
    // então o que se cronometra deste lado é o trecho servidor->Cloudflare, e
    // não o que chega na loja. Quem mede é o RouterOS, que reporta no f=res.
    // O tempo daqui fica só como registro, para comparar se um dia divergir.
    speed_gravar($roteador, ['mb' => $mb, 'srv' => round(microtime(true) - $t0, 3)]);
    exit;
}

// --- O roteador conta o resto --------------------------------------------
if ($f === 'res') {
    header('Content-Type: text/plain; charset=utf-8');
    $extra = [];

    // A VELOCIDADE vem daqui: o /tool fetch devolve quantos bytes baixou e em
    // quanto tempo, medidos no próprio roteador. É o único número que reflete o
    // que chega na loja — ver o comentário do f=down sobre o Cloudflare.
    $bytes = (float) ($_REQUEST['bytes'] ?? 0);
    $dur   = speed_dur_seg((string) ($_REQUEST['dur'] ?? ''));
    if ($bytes > 0 && $dur !== null && $dur > 0) {
        $extra['down']  = round($bytes * 8 / $dur / 1e6, 2);
        $extra['bytes'] = (int) $bytes;
        $extra['seg']   = $dur;
    }

    // avg-rtt do RouterOS: vem como "12ms300us", "1s200ms" ou já um número.
    $ping = trim((string) ($_REQUEST['ping'] ?? ''));
    if ($ping !== '') {
        $ms = speed_rtt_ms($ping);
        if ($ms !== null) { $extra['ping'] = $ms; }
    }
    if (isset($_REQUEST['erro']) && $_REQUEST['erro'] !== '') {
        $extra['erro'] = mb_substr((string) $_REQUEST['erro'], 0, 80);
    }
    // Grava mesmo vazio: o "acabou sem número" precisa aparecer na tela, senão
    // o painel fica girando até desistir sozinho, sem dizer o que houve.
    if (!$extra) { $extra['erro'] = 'o roteador nao mediu a velocidade'; }
    speed_gravar($roteador, $extra);
    exit('ok');
}

http_response_code(400);
exit('');
