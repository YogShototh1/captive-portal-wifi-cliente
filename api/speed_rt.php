<?php
// Teste de velocidade DO ROTEADOR: mede a internet que chega no MikroTik do
// cliente, sem ninguém ir até a loja.
//
// Este arquivo NÃO serve o download nem cronometra nada. Quem faz as duas
// coisas é o próprio MikroTik, baixando de speed.cloudflare.com — um endpoint
// público de teste de velocidade, servido pelo PoP mais próximo. Duas tentativas
// anteriores mostraram por que não pode ser daqui:
//   - servindo da hospedagem, o teto medido era o da banda de saída dela, que é
//     compartilhada, e não o do link da loja;
//   - cronometrando deste lado, o Cloudflare que está na frente da hospedagem
//     engolia a resposta inteira depressa e o tempo medido virava o do trecho
//     até ele.
//
// Aqui só se guarda o pedido, se recebe o resultado e se faz a conta.
//
//   ?f=req    o roteador pergunta se há teste pedido (e o pedido é consumido)
//   ?f=res    o roteador reporta bytes, duração, custo de setup e ping
//
// Auth: token = admin_token (igual ao status.php/tema.php).
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
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

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

// --- O roteador conta o resto --------------------------------------------
if ($f === 'res') {
    header('Content-Type: text/plain; charset=utf-8');
    $extra = [];

    // A VELOCIDADE vem daqui: o /tool fetch devolve quantos bytes baixou e em
    // quanto tempo, medidos no próprio roteador contra o speed.cloudflare.com.
    // É o único número que reflete o que chega na loja (ver o cabeçalho).
    $bytes = (float) ($_REQUEST['bytes'] ?? 0);
    $dur   = speed_dur_seg((string) ($_REQUEST['dur'] ?? ''));
    // Custo de abrir a conexão (DNS + TCP + TLS), medido pelo roteador com um
    // download de tamanho zero. Descontar isso é o que faz um link rápido
    // aparecer como rápido: sem o desconto, o setup domina o tempo total e o
    // resultado empaca perto de 16 Mbps, seja qual for o link.
    $over  = speed_dur_seg((string) ($_REQUEST['over'] ?? ''));

    // Mesma conta para os dois sentidos: bits / segundo líquido. O upload usa o
    // MESMO $over porque bate no mesmo host (speed.cloudflare.com), então o
    // custo de DNS + TCP + TLS é o mesmo.
    $liquido = function (?float $seg) use ($over): ?float {
        if ($seg === null || $seg <= 0) { return null; }
        // Só desconta se sobrar tempo de verdade: overhead maior que a
        // transferência significa medição estranha, e aí o número cheio é
        // menos errado.
        return ($over !== null && $over > 0 && $over < $seg * 0.8) ? $seg - $over : $seg;
    };

    $liq = $liquido($dur);
    if ($bytes > 0 && $liq !== null) {
        $extra['down']  = round($bytes * 8 / $liq / 1e6, 2);
        $extra['bytes'] = (int) $bytes;
        $extra['seg']   = round($liq, 3);
        if ($over !== null) { $extra['setup'] = round($over, 3); }
        // Quantos fluxos rodaram em paralelo. Importa na leitura: um fluxo só
        // mede a janela TCP dividida pelo RTT, não o link. 1 = o roteador é
        // RouterOS 6 (sem :execute) ou o paralelo falhou e caiu no fallback.
        $conex = (int) ($_REQUEST['conex'] ?? 0);
        if ($conex > 0) { $extra['conex'] = $conex; }

        // Pico de CPU do roteador durante o download. É o que separa "o link é
        // lento" de "o teste chegou ao teto do aparelho": saturou, o link é
        // mais rápido do que este número consegue mostrar.
        $cpu = (int) ($_REQUEST['cpu'] ?? -1);
        if ($cpu >= 0 && $cpu <= 100) { $extra['cpu'] = $cpu; }
        // Pico do núcleo MAIS carregado. É este que decide: num MT7621 (2
        // núcleos x 2 threads) a média pode marcar 50% com duas threads no
        // talo — saturado no único lugar que importa.
        $cpu1 = (int) ($_REQUEST['cpu1'] ?? -1);
        if ($cpu1 >= 0 && $cpu1 <= 100) { $extra['cpu1'] = $cpu1; }
    }

    // Upload: o roteador manda um payload de tamanho conhecido para o /__up da
    // Cloudflare e cronometra. Mesma conta do download.
    $ubytes = (float) ($_REQUEST['ubytes'] ?? 0);
    $uliq   = $liquido(speed_dur_seg((string) ($_REQUEST['udur'] ?? '')));
    // Abaixo de 256 KB o número não significa nada: a 30 Mbps isso passa em
    // 0,07 s, dentro do próprio ruído do setup da conexão. Nesse caso o
    // tamanho vira diagnóstico (onde o /tool fetch corta) em vez de virar
    // uma velocidade inventada.
    if ($ubytes >= 262144 && $uliq !== null) {
        $extra['up']     = round($ubytes * 8 / $uliq / 1e6, 2);
        $extra['ubytes'] = (int) $ubytes;
        $extra['useg']   = round($uliq, 3);
    } elseif ($ubytes > 0) {
        $extra['uerro'] = 'só aceitou ' . (int) $ubytes . ' bytes';
    } elseif (($_REQUEST['uerro'] ?? '') !== '') {
        // Em que passo o upload morreu ("montagem" = a string do payload não
        // foi montada; "envio" = o /tool fetch recusou). Sem isto a falha
        // sumia dentro do on-error e o painel só mostrava um traço.
        $extra['uerro'] = mb_substr((string) $_REQUEST['uerro'], 0, 20);
    }

    // O ping vem como os tempos de cada pacote separados por vírgula, do jeito
    // que o /ping do RouterOS os escreve: "00:00:00.021866,00:00:00.015775,".
    // Média simples dos que derem para ler.
    $ping = trim((string) ($_REQUEST['ping'] ?? ''));
    if ($ping !== '') {
        $vals = [];
        foreach (explode(',', $ping) as $p) {
            $ms = speed_rtt_ms(trim($p));
            // Acima de 5 s não é ping de rede que funciona.
            if ($ms !== null && $ms > 0 && $ms < 5000) { $vals[] = $ms; }
        }
        if ($vals) { $extra['ping'] = round(array_sum($vals) / count($vals), 1); }
    }
    if (isset($_REQUEST['erro']) && $_REQUEST['erro'] !== '') {
        $extra['erro'] = mb_substr((string) $_REQUEST['erro'], 0, 80);
    }
    // Grava mesmo vazio: o "acabou sem número" precisa aparecer na tela, senão
    // o painel fica girando até desistir sozinho, sem dizer o que houve.
    if (!isset($extra['down']) && !isset($extra['erro'])) {
        $extra['erro'] = 'sem medida de velocidade';
    }
    speed_gravar($roteador, $extra);
    exit('ok');
}

http_response_code(400);
exit('');
