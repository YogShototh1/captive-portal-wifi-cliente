<?php
// Helpers compartilhados.

// Escapa texto para HTML.
function h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// Formata segundos como HH:MM:SS (ou '—' se ainda não medido).
function fmt_tempo(?int $seg): string
{
    if ($seg === null) {
        return '—';
    }
    $hh = intdiv($seg, 3600);
    $mm = intdiv($seg % 3600, 60);
    $ss = $seg % 60;
    return sprintf('%02d:%02d:%02d', $hh, $mm, $ss);
}

// Formata bytes como MB/GB (ex.: "12,3 MB", "1,05 GB"); nulo/zero = '—'.
function fmt_bytes($b): string
{
    $b = (int) ($b ?? 0);
    if ($b <= 0) {
        return '—';
    }
    $mb = $b / 1048576;
    if ($mb >= 1024) {
        return number_format($mb / 1024, 2, ',', '.') . ' GB';
    }
    return number_format($mb, $mb >= 100 ? 0 : 1, ',', '.') . ' MB';
}

// Formata data/hora do banco ("2026-07-06 14:05:56") como "06/07/2026 - 14:05".
function fmt_data(?string $ts): string
{
    $t = $ts ? strtotime($ts) : false;
    return $t === false ? '—' : date('d/m/Y - H:i', $t);
}

// Números da barra de paginação: primeira, última e atual±2, com '...' nos saltos.
// Ex.: (6, 40) -> [1, '...', 4, 5, 6, 7, 8, '...', 40].
function paginacao_paginas(int $atual, int $total): array
{
    $out  = [];
    $prev = 0;
    for ($p = 1; $p <= $total; $p++) {
        if ($p === 1 || $p === $total || abs($p - $atual) <= 2) {
            if ($prev && $p > $prev + 1) {
                $out[] = '...';
            }
            $out[] = $p;
            $prev  = $p;
        }
    }
    return $out;
}

// Identifica o aparelho/SO a partir da User-Agent (ex.: "iOS 18", "Android 14").
function detecta_dispositivo(?string $ua): ?string
{
    $ua = trim((string) $ua);
    if ($ua === '') {
        return null;
    }
    if (preg_match('/iPhone OS (\d+)[_\.]/', $ua, $m))          { return 'iOS ' . $m[1]; }
    if (preg_match('/iPad;[^)]*OS (\d+)[_\.]/', $ua, $m))       { return 'iPadOS ' . $m[1]; }
    if (preg_match('/Android (\d+)/', $ua, $m))                 { return 'Android ' . $m[1]; }
    if (stripos($ua, 'iPhone') !== false)                       { return 'iOS'; }
    if (stripos($ua, 'iPad') !== false)                         { return 'iPadOS'; }
    if (stripos($ua, 'Android') !== false)                      { return 'Android'; }
    if (stripos($ua, 'Windows NT') !== false)                   { return 'Windows'; }
    if (stripos($ua, 'Mac OS X') !== false)                     { return 'macOS'; }
    if (stripos($ua, 'Linux') !== false)                        { return 'Linux'; }
    return 'Outro';
}

// --- Roteadores por conta (multi-MikroTik) ---

// Identities dos MikroTiks vinculados a uma conta (tabela `roteadores`).
function roteadores_conta(int $compradorId): array
{
    $q = db()->prepare('SELECT identity FROM roteadores WHERE comprador_id = ? ORDER BY identity');
    $q->execute([$compradorId]);
    return $q->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

// Resolve o roteador pedido contra a lista da conta: pedido válido -> ele;
// conta com um só roteador -> ele; senão null (= "todos" / precisa escolher).
function roteador_escolhido(array $lista, ?string $pedido): ?string
{
    $pedido = trim((string) $pedido);
    if ($pedido !== '' && in_array($pedido, $lista, true)) {
        return $pedido;
    }
    return count($lista) === 1 ? (string) $lista[0] : null;
}

// True se TODOS os roteadores da lista estão online.
// ponytail: o painel tem um LED só; verde = frota inteira saudável. Se um dia
// precisar de detalhe por roteador, a UI vira lista — não este helper.
function mikrotiks_online(array $roteadores): bool
{
    if (!$roteadores) {
        return false;
    }
    foreach ($roteadores as $r) {
        if (!mikrotik_online((string) $r)) {
            return false;
        }
    }
    return true;
}

// --- Anúncio do captive portal (imagem por roteador) ---

// Pasta onde ficam as imagens de anúncio (fora do controle de nome do cliente).
function ads_dir(): string
{
    return __DIR__ . '/../ads';
}

// Caminho-base (sem extensão) do anúncio de um roteador. Usa hash do identity:
// evita path traversal e não vaza o nome do roteador no nome do arquivo.
function anuncio_base(string $roteador): string
{
    return ads_dir() . '/' . sha1(trim($roteador));
}

// Retorna o caminho do arquivo de anúncio existente do roteador, ou null.
function anuncio_atual(string $roteador): ?string
{
    foreach (['jpg', 'png'] as $ext) {
        $p = anuncio_base($roteador) . '.' . $ext;
        if (is_file($p)) {
            return $p;
        }
    }
    return null;
}

// Logo da tela de login, por roteador (mesma pasta/ideia do anúncio; prefixo
// distinto pra não colidir com o arquivo do anúncio).
function logo_base(string $roteador): string
{
    return ads_dir() . '/logo_' . sha1(trim($roteador));
}
function logo_atual(string $roteador): ?string
{
    foreach (['png', 'jpg'] as $ext) {
        $p = logo_base($roteador) . '.' . $ext;
        if (is_file($p)) {
            return $p;
        }
    }
    return null;
}

// Forma da logo na tela de login: 'quadrado' (padrão) | 'arredondado' | 'redondo'.
function logo_formas(): array { return ['quadrado', 'arredondado', 'redondo']; }
function logo_forma(string $roteador): string
{
    $p = ads_dir() . '/logoforma_' . sha1(trim($roteador)) . '.txt';
    $v = is_file($p) ? trim((string) @file_get_contents($p)) : '';
    return in_array($v, logo_formas(), true) ? $v : 'quadrado';
}
function logo_forma_set(string $roteador, string $forma): void
{
    if (!in_array($forma, logo_formas(), true)) {
        $forma = 'quadrado';
    }
    $dir = ads_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($dir . '/logoforma_' . sha1(trim($roteador)) . '.txt', $forma);
}

// Cores da tela de login, por roteador. Cada chave casa com uma variavel CSS
// que o login.html do hotspot aplica (via window.CORES do dst.php).
// As 4 primeiras sao as antigas (compatibilidade); as demais entraram com o
// modo "avancado" — o form simples so mexe nas 4 e cores_set preserva o resto.
function cores_padrao(): array
{
    return [
        'bg'      => '#e6ecf5',  // fundo da tela
        'surface' => '#ffffff',  // cartao central
        'primary' => '#0891b2',  // cor principal (degrade 1, links, foco)
        'accent'  => '#22d3ee',  // cor de destaque (degrade 2)
        'fg'      => '#1e293b',  // titulo / texto principal
        'fg2'     => '#475569',  // texto secundario (subtitulo, avisos)
        'field'   => '#eef2f8',  // fundo do campo do numero
        'border'  => '#d9e0ec',  // borda dos campos
        'btnfg'   => '#ffffff',  // cor da letra do botao
    ];
}
function cores_get(string $roteador): array
{
    $def = cores_padrao();
    $p = ads_dir() . '/cores_' . sha1(trim($roteador)) . '.json';
    if (!is_file($p)) {
        return $def;
    }
    $j = json_decode((string) @file_get_contents($p), true);
    if (!is_array($j)) {
        return $def;
    }
    $out = $def;
    foreach ($def as $k => $v) {
        if (isset($j[$k]) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $j[$k])) {
            $out[$k] = strtolower((string) $j[$k]);
        }
    }
    return $out;
}
function cores_set(string $roteador, array $cores): void
{
    // Base = o que ja esta salvo (nao o default): assim salvar so parte das
    // chaves — o form simples manda 4 — nao zera as outras.
    $atual = cores_get($roteador);
    $out = [];
    foreach (cores_padrao() as $k => $v) {
        $c = isset($cores[$k]) ? (string) $cores[$k] : $atual[$k];
        $out[$k] = preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : $atual[$k];
    }
    $dir = ads_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($dir . '/cores_' . sha1(trim($roteador)) . '.json', json_encode($out));
}

// --- Efeitos (flags) da tela de login, por roteador ---
// 1 = efeito ligado (visual padrao). Cada flag desligada vira a classe
// cd-no-<chave> no <html> do login (e da previa do painel).
function estilo_padrao(): array
{
    return [
        'vidro'   => 1,  // desfoque de vidro no cartao
        'brilho'  => 1,  // brilho colorido atras do cartao
        'manchas' => 1,  // manchas de luz no fundo
        'grade'   => 1,  // grade quadriculada no fundo
        'sombra'  => 1,  // sombra sob o cartao
        'anim'    => 1,  // animacao de entrada do cartao
        'grad'    => 1,  // degrade no botao/logo (0 = cor principal chapada)
    ];
}
function estilo_get(string $roteador): array
{
    $def = estilo_padrao();
    $p = ads_dir() . '/estilo_' . sha1(trim($roteador)) . '.json';
    if (!is_file($p)) {
        return $def;
    }
    $j = json_decode((string) @file_get_contents($p), true);
    if (!is_array($j)) {
        return $def;
    }
    $out = $def;
    foreach ($def as $k => $v) {
        if (array_key_exists($k, $j)) {
            $out[$k] = empty($j[$k]) ? 0 : 1;
        }
    }
    return $out;
}
// $flags = so as chaves marcadas (checkbox nao marcado nao e enviado), por isso
// o form manda tambem um campo-sentinela indicando que as flags vieram.
function estilo_set(string $roteador, array $marcadas): void
{
    $out = [];
    foreach (estilo_padrao() as $k => $v) {
        $out[$k] = !empty($marcadas[$k]) ? 1 : 0;
    }
    $dir = ads_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($dir . '/estilo_' . sha1(trim($roteador)) . '.json', json_encode($out));
}

// --- Recorte por data do log de acessos ---
//
// Valida o que vem do <input type="date">. Usada pelo log geral e pelo log de
// um lead (o do botão "!"): os dois endpoints montam a MESMA condição de data,
// então a leitura mora num lugar só.
//
// Devolve 'YYYY-MM-DD' ou null. Nada de aceitar o que vier — o valor entra na
// consulta.
function log_data($v): ?string
{
    $v = trim((string) $v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}

// Condição SQL do recorte, já com os valores. Comparação por faixa, não
// DATE(visto_em): assim o índice de visto_em continua servindo. O fim é
// exclusivo no dia seguinte, que é como se inclui o dia inteiro.
// Devolve [pedaços de WHERE, valores].
function log_periodo_sql(?string $de, ?string $ate, string $col = 'a.visto_em'): array
{
    // Invertidas (fim antes do início) devolveriam lista vazia sem explicação.
    if ($de !== null && $ate !== null && $de > $ate) { [$de, $ate] = [$ate, $de]; }
    $cond = [];
    $args = [];
    if ($de !== null)  { $cond[] = "$col >= ?"; $args[] = $de . ' 00:00:00'; }
    if ($ate !== null) { $cond[] = "$col < ?";  $args[] = date('Y-m-d', strtotime($ate . ' +1 day')) . ' 00:00:00'; }
    return [$cond, $args];
}

// --- Liga/desliga do hotspot, à distância ---
//
// Quando o portal quebra, a saída é desligar o hotspot: o Wi-Fi da loja volta a
// funcionar sem a tela de login. Só que isso hoje exige Winbox e alguém com
// acesso ao roteador — foi o que aconteceu na Primix. Aqui o painel manda a
// ordem e o leadsync executa na rodada seguinte.
//
// Dois arquivos em ads/, no mesmo esquema do teste de velocidade: um com o
// último estado reportado pelo roteador, outro com a ordem em aberto.
function hotspot_estado_file(string $roteador): string { return anuncio_base($roteador) . '.hs.json'; }
function hotspot_ordem_file(string $roteador): string  { return anuncio_base($roteador) . '.hsreq'; }

// O roteador manda "<nome>~<0|1>~<perfil>|..." (1 = ligado).
//
// O PERFIL é o campo que importa para o diagnóstico: o portal autentica por
// trial, e ter um perfil com trial no roteador não basta — vale o perfil que
// ESTE servidor usa. Foi o que faltou na primeira versão desta telemetria.
//
// Formato antigo ("<nome>:<0|1>,...") ainda é aceito: o roteador baixa o app
// novo em ~1 min, e nesse intervalo a tela não deve ficar sem os servidores.
// Isto é entrada de rede: o que não casa com o formato é descartado.
function hotspot_estado_set(string $roteador, string $bruto, string $prof = ''): void
{
    $perfis = hotspot_perfis_parse($prof);
    $srv = [];
    $novo = strpos($bruto, '~') !== false;
    foreach (explode($novo ? '|' : ',', $bruto) as $par) {
        $par = trim($par);
        if ($par === '' || count($srv) >= 10) { continue; }
        if ($novo) {
            $c = explode('~', $par);
            if (count($c) < 3) { continue; }
            [$nome, $flag, $perfil] = $c;
            $perfil = substr(preg_replace('/[^A-Za-z0-9._-]/', '', $perfil), 0, 32);
        } else {
            $i = strrpos($par, ':');
            if ($i === false) { continue; }
            $nome   = substr($par, 0, $i);
            $flag   = substr($par, $i + 1);
            $perfil = '';
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $nome) || ($flag !== '0' && $flag !== '1')) { continue; }
        $srv[] = ['nome' => $nome, 'ligado' => $flag === '1', 'perfil' => $perfil];
    }
    $dir = ads_dir();
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    // Preserva os perfis já conhecidos: o roteador manda os dois juntos, mas se
    // a coleta do perfil falhar lá (trial-uptime-* nem sempre existe) o que já
    // se sabia não deve sumir da tela.
    $antes = json_decode((string) @file_get_contents(hotspot_estado_file($roteador)), true);
    @file_put_contents(hotspot_estado_file($roteador), json_encode([
        'servidores' => $srv,
        'perfis'     => $perfis !== [] ? $perfis : (is_array($antes['perfis'] ?? null) ? $antes['perfis'] : []),
        'em'         => date('c'),
    ]));
}

// Perfis do hotspot, como o roteador os manda: "<nome>~<login-by>~<limite>~<reset>|"
//
// É aqui que se vê POR QUE um cliente não consegue entrar. O portal autentica
// por trial; sem "trial" no login-by, ou com o trial-uptime-limit do dia já
// gasto pelo MAC, o roteador recusa e devolve a própria tela de login — e o
// cliente volta pro começo do fluxo.
function hotspot_perfis_parse(string $bruto): array
{
    $out = [];
    foreach (explode('|', $bruto) as $p) {
        $p = trim($p);
        if ($p === '' || count($out) >= 10) { continue; }
        $c = explode('~', $p);
        if (count($c) < 4) { continue; }
        // O :tostr do RouterOS separa lista com ";" — vira vírgula, que é como
        // o login-by aparece no Winbox.
        $login = strtr(trim($c[1]), [';' => ',']);
        $lim   = trim($c[2]);
        $out[] = [
            'nome'   => substr(preg_replace('/[^A-Za-z0-9._-]/', '', $c[0]), 0, 32),
            'login'  => substr(preg_replace('/[^a-z0-9,_-]/', '', strtolower($login)), 0, 64),
            'trial'  => strpos($login, 'trial') !== false,
            // O trial sozinho não entra: o pedido do portal chega por HTTP e o
            // RouterOS responde "configuration error (HTTP/HTTPS login is not
            // allowed)" se nenhum método HTTP estiver ligado. O sintoma engana —
            // o anúncio roda inteiro e o erro só aparece depois.
            'http'   => strpos($login, 'http-pap') !== false || strpos($login, 'http-chap') !== false,
            'limite' => substr(preg_replace('/[^0-9a-z:.]/', '', strtolower($lim)), 0, 16),
            'reset'  => substr(preg_replace('/[^0-9a-z:.]/', '', strtolower(trim($c[3]))), 0, 16),
        ];
    }
    return $out;
}

// Último estado conhecido, ou null se o roteador nunca reportou.
//   servidores : [['nome'=>'hotspot1','ligado'=>true], ...]
//   ligado     : algum servidor ligado? (é o que a tela mostra)
//   em / idade : quando foi reportado, e há quantos segundos
function hotspot_estado_get(string $roteador): ?array
{
    $j = json_decode((string) @file_get_contents(hotspot_estado_file($roteador)), true);
    if (!is_array($j) || !isset($j['servidores']) || !is_array($j['servidores'])) { return null; }
    $ligado = false;
    foreach ($j['servidores'] as $s) {
        if (!empty($s['ligado'])) { $ligado = true; }
    }
    $ts = strtotime((string) ($j['em'] ?? '')) ?: 0;
    return [
        'servidores' => array_values($j['servidores']),
        'perfis'     => is_array($j['perfis'] ?? null) ? array_values($j['perfis']) : [],
        'ligado'     => $ligado,
        'em'         => (string) ($j['em'] ?? ''),
        'idade'      => $ts ? max(0, time() - $ts) : null,
    ];
}

// Ordem em aberto: true = ligar, false = desligar, null = nada pedido.
// Vale 10 min, como o pedido do teste de velocidade: roteador que ficou fora e
// voltou horas depois não deve acordar executando uma ordem que ninguém lembra.
function hotspot_ordem_pedir(string $roteador, bool $ligar): bool
{
    $dir = ads_dir();
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    return @file_put_contents(hotspot_ordem_file($roteador), $ligar ? 'on' : 'off') !== false;
}

function hotspot_ordem_pendente(string $roteador): ?bool
{
    $f = hotspot_ordem_file($roteador);
    if (!is_file($f) || (time() - (int) @filemtime($f)) > 600) { return null; }
    return trim((string) @file_get_contents($f)) === 'on';
}

// Lê e CONSOME a ordem. Consumir aqui é o que impede o roteador de reaplicar a
// mesma ordem a cada minuto: se o admin desligar pelo Winbox depois, o painel
// não desfaz sozinho.
function hotspot_ordem_ler(string $roteador): ?bool
{
    $o = hotspot_ordem_pendente($roteador);
    @unlink(hotspot_ordem_file($roteador));
    return $o;
}

// --- Teste de velocidade DO ROTEADOR ---
//
// O comprador pede pelo painel, o MikroTik executa na rodada seguinte do
// leadsync e o resultado volta para cá. Serve para responder "a internet da
// loja está boa?" sem ninguém ir até lá.
//
// Guardado em ads/, como o resto do estado: um arquivo com o pedido em aberto
// e outro com as últimas medições.
const SPEED_HIST_MAX = 20;

function speed_pedido_file(string $roteador): string { return anuncio_base($roteador) . '.speedreq'; }
function speed_hist_file(string $roteador): string   { return anuncio_base($roteador) . '.speed.json'; }

// Pede um teste. $mb é o tamanho do download; o teto existe para um clique
// não torrar a franquia da loja.
//
// 10 MB é o padrão: com 6 MB, um link de 100 Mbps termina o download em meio
// segundo e o custo de abrir a conexão passa a pesar mais que o download em si.
function speed_pedir(string $roteador, int $mb = 10): bool
{
    $dir = ads_dir();
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    return @file_put_contents(speed_pedido_file($roteador), (string) max(1, min(24, $mb))) !== false;
}

// Há teste pedido? Devolve os MB e CONSOME o pedido (ver o porquê no
// api/speed_rt.php). Pedido velho é ignorado: se o roteador estava fora e
// voltou horas depois, ninguém está mais olhando o resultado.
function speed_pedido_ler(string $roteador): int
{
    $f = speed_pedido_file($roteador);
    if (!is_file($f)) { return 0; }
    $mb = (int) trim((string) @file_get_contents($f));
    $velho = (time() - (int) @filemtime($f)) > 600;   // 10 min
    @unlink($f);
    return $velho ? 0 : max(1, min(24, $mb));
}

// Um teste está em andamento (pedido feito e ainda não consumido)?
function speed_pendente(string $roteador): bool
{
    $f = speed_pedido_file($roteador);
    return is_file($f) && (time() - (int) @filemtime($f)) <= 600;
}

function speed_hist(string $roteador): array
{
    $j = json_decode((string) @file_get_contents(speed_hist_file($roteador)), true);
    return is_array($j) ? $j : [];
}

// Grava os pedaços conforme chegam: o download vem do próprio servidor, o ping
// vem depois, do roteador. Os dois entram na MESMA medição enquanto ela for
// recente — senão o ping de um teste cairia no resultado do anterior.
function speed_gravar(string $roteador, array $dados): void
{
    $h = speed_hist($roteador);
    try { $agora = db_now(); } catch (Throwable $e) { $agora = date('Y-m-d H:i:s'); }

    $recente = $h && (time() - strtotime((string) ($h[0]['em'] ?? '')) ) < 120;
    if ($recente) {
        $h[0] = array_merge($h[0], $dados, ['em' => $agora]);
    } else {
        array_unshift($h, array_merge(['down' => null, 'up' => null, 'ping' => null], $dados, ['em' => $agora]));
    }

    $dir = ads_dir();
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents(speed_hist_file($roteador),
        json_encode(array_slice($h, 0, SPEED_HIST_MAX), JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// Nome do destino de um acesso, na ordem em que se confia nele:
//   dns  = o que o CLIENTE resolveu, lido do cache de DNS do roteador. É o
//          único que acerta atrás de CDN, onde um IP atende milhares de sites.
//   host = reverse-DNS (PTR) do IP. Num IP de CDN devolve "cloudflare".
//   null = nem um nem outro; quem chama mostra o IP cru.
// Vive aqui porque a tela (assets/acessolog.js) e as duas planilhas precisam da
// MESMA ordem — em três lugares diferentes ela desandaria sozinha.
function destino_nome(array $a): ?string
{
    foreach (['dns', 'host'] as $k) {
        if (isset($a[$k]) && trim((string) $a[$k]) !== '') { return (string) $a[$k]; }
    }
    return null;
}

// A duração que o /tool fetch devolve, em segundos.
// O RouterOS escreve tempo de dois jeitos: "6s500ms" / "1m2s" (o formato de
// duração) ou "00:00:06.500000" (o de relógio). Cobre os dois; null se não der.
function speed_dur_seg(string $v): ?float
{
    $v = strtolower(trim($v));
    if ($v === '') { return null; }
    if (is_numeric($v)) { return (float) $v > 0 ? (float) $v : null; }

    // Formato de relógio: hh:mm:ss(.frac), com um "Nd" opcional na frente
    // (é assim que o RouterOS escreve diferença de :timestamp).
    if (preg_match('/^(?:(\d+)d\s*)?(\d+):(\d+):(\d+(?:\.\d+)?)$/', $v, $m)) {
        $s = ((int) ($m[1] ?: 0)) * 86400 + ((int) $m[2]) * 3600 + ((int) $m[3]) * 60 + (float) $m[4];
        return $s > 0 ? round($s, 4) : null;
    }

    // Formato de duração: 1d2h3m4s500ms600us
    $mult = ['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1, 'ms' => 0.001, 'us' => 0.000001];
    $s = 0.0;
    $achou = false;
    // ms e us antes de m e s, senão o sufixo curto casaria primeiro.
    if (preg_match_all('/(\d+(?:\.\d+)?)\s*(ms|us|[dhms])/', $v, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $x) {
            $s += ((float) $x[1]) * $mult[$x[2]];
            $achou = true;
        }
    }
    return ($achou && $s > 0) ? round($s, 4) : null;
}

// O avg-rtt do RouterOS não vem em milissegundos puros: pode ser "12ms300us",
// "1s200ms" ou já um número. Converte tudo para ms, ou null se não der.
function speed_rtt_ms(string $v): ?float
{
    $v = strtolower(trim($v));
    if ($v === '') { return null; }
    if (is_numeric($v)) { return round((float) $v, 1); }

    $ms = 0.0;
    $achou = false;
    // Ordem importa: "ms" antes de "s", senão o "s" de "ms" casaria sozinho.
    foreach ([['/(\d+(?:\.\d+)?)\s*us/', 0.001], ['/(\d+(?:\.\d+)?)\s*ms/', 1],
              ['/(?<![m u])(\d+(?:\.\d+)?)\s*s(?![a-z])/', 1000]] as [$re, $mult]) {
        if (preg_match($re, $v, $m)) { $ms += ((float) $m[1]) * $mult; $achou = true; }
    }
    if ($achou) { return round($ms, 1); }

    // Formato de relógio ("00:00:00.021866"): é assim que o /ping do RouterOS
    // escreve o time de cada pacote, e foi o que ficou meses sem aparecer no
    // painel — o roteador sempre mandou, o servidor é que não sabia ler.
    $s = speed_dur_seg($v);
    return $s === null ? null : round($s * 1000, 1);
}

// --- Imagem enxuta para o roteador guardar na flash ---
//
// O hEX Gr3 tem 16 MB de flash NO TOTAL, e o RouterOS 7 já come quase tudo. Um
// anúncio de 2,5 MB — tamanho normal para uma foto de celular — sozinho ocupa
// 15% do disco do equipamento. E não adianta: a imagem é exibida na tela de um
// telefone, onde 1080px de largura já é mais do que se enxerga.
//
// Reduz uma vez e guarda ao lado do original (<base>.flash.<ext>), refazendo só
// quando o original muda. Devolve o caminho do arquivo pronto — ou o próprio
// original, se não der para converter (sem GD, formato estranho, imagem que já
// é pequena). Nunca falha de um jeito que impeça a entrega.
// $comAlpha: só a LOGO precisa de fundo transparente. O anúncio ocupa a tela
// inteira, então alpha ali não serve para nada — e manter um PNG fotográfico
// como PNG é o que fazia um anúncio de 1024x1536 pesar 2,5 MB.
//
// Sobre os limites, que NAO sao chutados: o que manda e quantos pixels o
// cliente enxerga no celular. Um iPhone tem 3 pixels fisicos por pixel de CSS,
// entao a logo (max-width 220px no style.css) precisa de 660px, e o anuncio,
// que ocupa a tela toda de um Pro Max (430pt), precisa de 1290px de largura.
// Limites menores que isso deixam a imagem visivelmente borrada no aparelho do
// cliente — economia que sai caro. Ver quem chama esta funcao.
// $tetoKB: limite duro do que pode ir para a flash. Sem ele, um PNG grande que
// o JPEG comprime mal escapava inteiro — e a flash do roteador nao tem folga
// para isso.
function imagem_flash(string $origem, int $maxLado, bool $comAlpha = false, int $qualidade = 90, int $tetoKB = 700): string
{
    if (!is_file($origem) || !function_exists('imagecreatefromstring')) {
        return $origem;
    }
    $tam = @getimagesize($origem);
    if ($tam === false) {
        return $origem;
    }
    $temAlpha = $comAlpha && ($tam[2] === IMAGETYPE_PNG);
    $destino  = preg_replace('/\.[^.]+$/', '', $origem) . '.flash.' . ($temAlpha ? 'png' : 'jpg');

    // Já convertida e ainda válida? Devolve o cache.
    if (is_file($destino) && @filemtime($destino) >= @filemtime($origem)) {
        return $destino;
    }
    // Já é pequena o bastante e não vale mexer.
    if ($tam[0] <= $maxLado && $tam[1] <= $maxLado && @filesize($origem) <= 120 * 1024) {
        return $origem;
    }

    $img = @imagecreatefromstring((string) @file_get_contents($origem));
    if ($img === false) {
        return $origem;
    }
    $l = imagesx($img);
    $a = imagesy($img);

    // Tenta na medida cheia; se o arquivo passar do teto, aperta a qualidade e,
    // se ainda assim não couber, encolhe a imagem. O teto existe porque a flash
    // do roteador tem 16 MB NO TOTAL: sem ele, uma arte exportada sem compressão
    // (que o PNG guarda mal e o JPEG comprime pior ainda) iria inteira para lá.
    $tentativas = [
        [$maxLado, $qualidade], [$maxLado, 80], [$maxLado, 70],
        [(int) round($maxLado * 0.75), 80], [(int) round($maxLado * 0.6), 75],
    ];
    $ok = false;
    foreach ($tentativas as [$lado, $q]) {
        $escala = min(1, $lado / max($l, $a));
        $nl = max(1, (int) round($l * $escala));
        $na = max(1, (int) round($a * $escala));

        $novo = imagecreatetruecolor($nl, $na);
        if ($temAlpha) {
            imagealphablending($novo, false);
            imagesavealpha($novo, true);
        } else {
            // PNG achatado em JPEG: o que era transparente viraria preto, então
            // pinta o fundo de branco antes de copiar.
            imagefilledrectangle($novo, 0, 0, $nl, $na, imagecolorallocate($novo, 255, 255, 255));
        }
        imagecopyresampled($novo, $img, 0, 0, 0, 0, $nl, $na, $l, $a);
        $ok = $temAlpha ? @imagepng($novo, $destino, 8) : @imagejpeg($novo, $destino, $q);
        imagedestroy($novo);

        if ($ok && is_file($destino) && filesize($destino) <= $tetoKB * 1024) {
            break;   // coube
        }
    }
    imagedestroy($img);

    if (!$ok || !is_file($destino)) {
        return $origem;
    }
    @chmod($destino, 0644);
    // Converteu e ficou MAIOR que o original? Acontece com imagem já otimizada.
    // Fica o original — MAS só se ele mesmo couber no teto; senão vai o
    // convertido, porque o que não pode é a flash do roteador levar um arquivo
    // gigante.
    if (@filesize($destino) >= @filesize($origem) && @filesize($origem) <= $tetoKB * 1024) {
        return $origem;
    }
    return $destino;
}

// --- Histórico de logos e anúncios enviados, por roteador ---
//
// Antes, enviar uma logo nova APAGAVA a anterior. Trocar o anúncio de volta
// (fim de uma promoção, por exemplo) exigia achar o arquivo original no
// computador — e quem não achava, perdia. Aqui cada envio fica guardado e o
// comprador escolhe qual está no ar, sem reenviar nada.
//
// Arquivos planos em ads/, sem subpasta: a hospedagem compartilhada nem sempre
// deixa criar diretório, e é a mesma razão pela qual o portal guarda
// "css~style.css" em vez de "css/style.css".
//   ads/<hash>.hist-<tipo>.json      índice
//   ads/<hash>.hist-<tipo>-<id>.<e>  os arquivos
const MIDIA_HIST_MAX = 8;

function midia_tipos(): array { return ['logo', 'anuncio']; }

// Onde vive o arquivo EM USO de cada tipo (o que o roteador baixa).
function midia_base(string $roteador, string $tipo): string
{
    return $tipo === 'logo' ? logo_base($roteador) : anuncio_base($roteador);
}
function midia_atual(string $roteador, string $tipo): ?string
{
    return $tipo === 'logo' ? logo_atual($roteador) : anuncio_atual($roteador);
}

function midia_hist_file(string $roteador, string $tipo): string
{
    return anuncio_base($roteador) . '.hist-' . $tipo . '.json';
}
function midia_hist_img(string $roteador, string $tipo, string $id, string $ext): string
{
    return anuncio_base($roteador) . '.hist-' . $tipo . '-' . $id . '.' . $ext;
}

// Mais recente primeiro.
function midia_hist(string $roteador, string $tipo): array
{
    if (trim($roteador) === '' || !in_array($tipo, midia_tipos(), true)) { return []; }
    $j = json_decode((string) @file_get_contents(midia_hist_file($roteador, $tipo)), true);
    return is_array($j) ? $j : [];
}

// Guarda uma cópia do arquivo que acabou de virar o ativo.
// O id é o hash do CONTEÚDO: reenviar a mesma imagem não cria item repetido,
// só a traz de volta para o topo da lista.
function midia_hist_add(string $roteador, string $tipo, string $arquivo, string $nomeOriginal): void
{
    if (!is_file($arquivo) || !in_array($tipo, midia_tipos(), true)) { return; }
    $id  = substr(sha1_file($arquivo) ?: '', 0, 16);
    $ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
    if ($id === '' || !in_array($ext, ['jpg', 'png'], true)) { return; }

    $h = midia_hist($roteador, $tipo);
    // Já existe? Tira da posição antiga para reentrar no topo.
    $h = array_values(array_filter($h, function ($x) use ($id) { return ($x['id'] ?? '') !== $id; }));

    $copia = midia_hist_img($roteador, $tipo, $id, $ext);
    if (!is_file($copia) && !@copy($arquivo, $copia)) { return; }
    @chmod($copia, 0644);

    try { $agora = db_now(); } catch (Throwable $e) { $agora = date('Y-m-d H:i:s'); }
    array_unshift($h, [
        'id'   => $id,
        'ext'  => $ext,
        'nome' => mb_substr(trim($nomeOriginal), 0, 80),
        'em'   => $agora,
    ]);

    // Passou do teto: os mais antigos saem, e o arquivo deles some junto —
    // senão a hospedagem acumularia imagem que ninguém mais alcança.
    foreach (array_slice($h, MIDIA_HIST_MAX) as $velho) {
        @unlink(midia_hist_img($roteador, $tipo, (string) $velho['id'], (string) $velho['ext']));
    }
    $h = array_slice($h, 0, MIDIA_HIST_MAX);

    @file_put_contents(midia_hist_file($roteador, $tipo), json_encode($h, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// Caminho do arquivo guardado, ou null se o id não estiver no histórico.
// Nunca monta caminho a partir do que veio do cliente: o id é procurado no
// índice, e só o que está lá pode ser lido.
function midia_hist_arquivo(string $roteador, string $tipo, string $id): ?string
{
    foreach (midia_hist($roteador, $tipo) as $x) {
        if ((string) ($x['id'] ?? '') === $id) {
            $p = midia_hist_img($roteador, $tipo, $id, (string) $x['ext']);
            return is_file($p) ? $p : null;
        }
    }
    return null;
}

// Repõe um item do histórico como o que está no ar.
function midia_hist_usar(string $roteador, string $tipo, string $id): bool
{
    $origem = midia_hist_arquivo($roteador, $tipo, $id);
    if ($origem === null) { return false; }
    $ext = strtolower(pathinfo($origem, PATHINFO_EXTENSION));

    // Tira o ativo atual (qualquer extensão) e põe este no lugar. A cópia do
    // histórico permanece: é ela que permite voltar depois.
    foreach (['jpg', 'png'] as $e) {
        @unlink(midia_base($roteador, $tipo) . '.' . $e);
        // O derivado para a flash também sai: a versão reduzida é do arquivo
        // antigo e ficaria valendo por ser mais nova que o novo ativo.
        @unlink(midia_base($roteador, $tipo) . '.flash.' . $e);
    }
    if (!@copy($origem, midia_base($roteador, $tipo) . '.' . $ext)) { return false; }
    @chmod(midia_base($roteador, $tipo) . '.' . $ext, 0644);
    return true;
}

// Qual item do histórico está no ar agora (pelo conteúdo, não pelo nome).
function midia_hist_ativo(string $roteador, string $tipo): ?string
{
    $atual = midia_atual($roteador, $tipo);
    return $atual !== null ? substr(sha1_file($atual) ?: '', 0, 16) : null;
}

// --- Página-ponte do Instagram, personalizada por roteador ---
//
// Por que a ponte existe: destino direto no instagram.com NÃO funciona no CNA
// do iPhone. Sem cookies, o Instagram força o redirect instagram:// e o CNA
// bloqueia com "Erro ao Abrir a Página". A ponte é uma página nossa, que sempre
// carrega, com um botão que o cliente toca para ir ao perfil.
//
// Até aqui a ponte era uma só (ig.php?u=perfil, cara do Captive Data) e quem
// quisesse a identidade da loja ganhava uma pasta feita à mão — foi o caso do
// /maniasdosul/. Isto põe a mesma personalização na mão do comprador: cores,
// textos e efeitos por roteador, no molde do que já existe para a tela de
// login (cores_get/estilo_get).
function ig_padrao(): array
{
    return [
        'perfil'  => '',
        'titulo'  => 'Siga a gente no Instagram',
        'sub'     => 'Wi-Fi liberado! Aproveite e siga o nosso perfil.',
        'chamada' => 'Novidades, promoções e bastidores — siga o perfil e fique por dentro.',
        'botao'   => 'Abrir no Instagram',
        'rodape'  => '',
        'cores'   => [
            'bg'     => '#faf5ff',   // fundo da tela
            'card'   => '#ffffff',   // cartão do perfil
            'fg'     => '#0f172a',   // título
            'fg2'    => '#64748b',   // textos secundários
            'btn'    => '#7c3aed',   // botão (degradê 1)
            'btn2'   => '#ec4899',   // botão (degradê 2)
            'btnfg'  => '#ffffff',   // texto do botão
            'border' => '#ece3fb',   // bordas
        ],
        'estilo'  => [
            'logo'    => 1,  // mostra a logo enviada no painel
            // Nasce DESLIGADA: ligar por padrão recortaria em círculo a logo de
            // quem já tem a página no ar, sem ele pedir.
            'logoredonda' => 0,  // recorta a logo em círculo (foto de perfil)
            'cartao'  => 1,  // bloco do perfil (desligado = só título e botão)
            'sombra'  => 1,  // sombra sob o cartão
            'manchas' => 1,  // manchas de luz no fundo
            'grad'    => 1,  // degradê no botão (0 = cor chapada)
            'redondo' => 1,  // cantos arredondados
            'copiar'  => 1,  // botão "copiar @" (salva quem não consegue abrir)
        ],
    ];
}

// Os arquivos de ads/ ja sao nomeados pelo sha1 do identity, entao a pagina
// publica e servida a partir do HASH — o identity do MikroTik nunca vai para a
// URL, e o ig.php nao precisa de banco para descobrir de quem e a pagina.
function ig_hash(string $roteador): string { return sha1(trim($roteador)); }
function ig_file(string $roteador): string { return ig_file_hash(ig_hash($roteador)); }
function ig_file_hash(string $hash): string { return ads_dir() . '/' . $hash . '.ig.json'; }

// Logo do comprador (a mesma da tela de login) a partir do hash.
function ig_logo_hash(string $hash): ?string
{
    foreach (['png', 'jpg'] as $ext) {
        $p = ads_dir() . '/logo_' . $hash . '.' . $ext;
        if (is_file($p)) { return $p; }
    }
    return null;
}

// Config da ponte do roteador, já com os defaults preenchidos.
function ig_get(string $roteador): array
{
    return ig_get_hash(trim($roteador) === '' ? '' : ig_hash($roteador));
}

// Idem, direto pelo hash (é o que a página pública usa).
function ig_get_hash(string $hash): array
{
    $def = ig_padrao();
    if ($hash === '' || !preg_match('/^[0-9a-f]{40}$/', $hash)) { return $def; }
    $j = json_decode((string) @file_get_contents(ig_file_hash($hash)), true);
    if (!is_array($j)) { return $def; }

    $out = $def;
    // Perfil do Instagram: as mesmas regras do proprio Instagram.
    if (isset($j['perfil']) && preg_match('/^[A-Za-z0-9._]{1,30}$/', (string) $j['perfil'])) {
        $out['perfil'] = (string) $j['perfil'];
    }
    foreach (['titulo', 'sub', 'chamada', 'botao', 'rodape'] as $k) {
        if (isset($j[$k]) && is_string($j[$k])) {
            $out[$k] = mb_substr(trim($j[$k]), 0, 160);
        }
    }
    foreach ($def['cores'] as $k => $v) {
        if (isset($j['cores'][$k]) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $j['cores'][$k])) {
            $out['cores'][$k] = strtolower((string) $j['cores'][$k]);
        }
    }
    foreach ($def['estilo'] as $k => $v) {
        if (isset($j['estilo']) && array_key_exists($k, $j['estilo'])) {
            $out['estilo'][$k] = empty($j['estilo'][$k]) ? 0 : 1;
        }
    }
    return $out;
}

// Grava o que veio validado. Base = o que ja esta salvo, nao o default: salvar
// so uma parte (ex.: so as cores) nao zera o resto.
function ig_set(string $roteador, array $cfg): bool
{
    if (trim($roteador) === '') { return false; }
    $atual = ig_get($roteador);
    $novo  = $atual;

    if (isset($cfg['perfil'])) {
        $p = ltrim(trim((string) $cfg['perfil']), '@');
        // Aceita o link colado inteiro, nao so o @: e o que a pessoa tem em maos.
        if (preg_match('~instagram\.com/([A-Za-z0-9._]{1,30})~i', $p, $m)) { $p = $m[1]; }
        $novo['perfil'] = preg_match('/^[A-Za-z0-9._]{1,30}$/', $p) ? $p : '';
    }
    foreach (['titulo', 'sub', 'chamada', 'botao', 'rodape'] as $k) {
        if (isset($cfg[$k])) { $novo[$k] = mb_substr(trim((string) $cfg[$k]), 0, 160); }
    }
    foreach (ig_padrao()['cores'] as $k => $v) {
        if (isset($cfg['cores'][$k]) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $cfg['cores'][$k])) {
            $novo['cores'][$k] = strtolower((string) $cfg['cores'][$k]);
        }
    }
    // Flags so sao lidas quando o form avisa que mandou o bloco delas (checkbox
    // desmarcado nao viaja no POST) — mesmo sentinela do estilo da tela de login.
    if (!empty($cfg['tem_flags'])) {
        foreach (ig_padrao()['estilo'] as $k => $v) {
            $novo['estilo'][$k] = !empty($cfg['estilo'][$k]) ? 1 : 0;
        }
    }

    $dir = ads_dir();
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    return @file_put_contents(ig_file($roteador), json_encode($novo, JSON_UNESCAPED_UNICODE)) !== false;
}

// Endereço público da ponte do roteador. O hash é o mesmo que nomeia os
// arquivos em ads/ — o identity do MikroTik não vai para a URL.
function ig_url(string $roteador): string
{
    return 'https://captivedata.com.br/ig.php?r=' . ig_hash($roteador);
}

// A ponte só serve de destino depois que o comprador informou o perfil — sem
// ele o botão não teria para onde ir.
function ig_pronta(string $roteador): bool
{
    return ig_get($roteador)['perfil'] !== '';
}

// --- Site de destino pós-anúncio (dst do hotspot), por roteador ---

// Destino padrão quando o comprador ainda não configurou um.
const DST_PADRAO = 'https://www.google.com';

// Arquivo-texto com a URL de destino do roteador (fica junto do anúncio).
function dst_file(string $roteador): string
{
    return anuncio_base($roteador) . '.dst';
}

// URL de destino configurada para o roteador, ou null se não houver.
function dst_atual(string $roteador): ?string
{
    $f = dst_file($roteador);
    if (!is_file($f)) {
        return null;
    }
    $u = trim((string) @file_get_contents($f));
    return $u !== '' ? $u : null;
}

// --- Status do MikroTik (online/offline), por roteador ---
// O MikroTik bate em api/status.php a cada ~1 min (scheduler leadsync.rsc). Cada
// batida "toca" um arquivo .seen; se o último toque for recente, está online.
// A janela = quanto tempo sem batida até declarar offline. Tem que ser MAIOR que
// o intervalo do scheduler (senão dá falso offline por jitter). Com scheduler de
// 5s, 15s tolera 2 batidas perdidas e detecta a queda em ~15–20s.
// ponytail: número fixo; é o único botão de calibração — mantenha ~3x o intervalo
//           do scheduler do MikroTik.
const MIKROTIK_TIMEOUT_SEG = 15;

// Arquivo-marcador do último contato do roteador (fica junto do anúncio/dst).
function mikrotik_seen_file(string $roteador): string
{
    return anuncio_base($roteador) . '.seen';
}

// Registra que o roteador acabou de reportar (chamado pelo status.php).
function mikrotik_tocar(string $roteador): void
{
    if (trim($roteador) !== '') {
        @touch(mikrotik_seen_file($roteador));
    }
}

// True se o roteador reportou dentro da janela (time()/filemtime: mesmo relógio).
function mikrotik_online(string $roteador): bool
{
    if (trim($roteador) === '') {
        return false;
    }
    $f = mikrotik_seen_file($roteador);
    if (!is_file($f)) {
        return false;
    }
    return (time() - (int) @filemtime($f)) <= MIKROTIK_TIMEOUT_SEG;
}

// --- Padrões por roteador (limite de tempo e de banda dos NOVOS usuários) ---
// Guardado em arquivo-texto junto do anúncio/dst (pasta ads/, bloqueada por
// .htaccess). Guarda um inteiro; NULL/ausente = sem limite. Chaves: 'tlimit', 'banda'.
function roteador_cfg_file(string $roteador, string $chave): string
{
    return anuncio_base($roteador) . '.' . $chave;
}

function roteador_cfg_get(string $roteador, string $chave): ?int
{
    if (trim($roteador) === '') {
        return null;
    }
    $f = roteador_cfg_file($roteador, $chave);
    if (!is_file($f)) {
        return null;
    }
    $v = trim((string) @file_get_contents($f));
    return $v === '' ? null : (int) $v;
}

function roteador_cfg_set(string $roteador, string $chave, ?int $val): void
{
    $f = roteador_cfg_file($roteador, $chave);
    if ($val === null) {
        @unlink($f);
        return;
    }
    $dir = ads_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($f, (string) $val);
    @chmod($f, 0644);
}

// Estado real de um lead (online + tempo), contra a hora do banco.
// Correção do "online preso": a flag online=1 só é confiável se o sync confirmou
// há pouco (visto_em recente). Se o MikroTik/sync parou, a flag fica travada e o
// tempo cresceria pra sempre — então tratamos como offline e CONGELAMOS o tempo
// no último instante confirmado (visto_em), em vez de "agora".
// Requer que o SELECT traga: conectado_em, online, segundos_conectado, visto_em.
function lead_estado(array $l, int $nowTs): array
{
    $online = (int) $l['online'];
    $conTs  = strtotime((string) ($l['conectado_em'] ?? ''));
    $segRaw = $l['segundos_conectado'] ?? null;
    $seg    = ($segRaw === null || $segRaw === '') ? null : (int) $segRaw;
    $vRaw   = $l['visto_em'] ?? null;
    $vTs    = ($vRaw === null || $vRaw === '') ? null : strtotime((string) $vRaw);

    // Online travado (sync não confirma dentro da janela) -> vira offline e congela.
    // Só estimamos a duração se houve confirmação (visto_em); sem ela, fica "—".
    if ($online === 1 && ($vTs === null || ($nowTs - $vTs) > MIKROTIK_TIMEOUT_SEG)) {
        $online = 0;
        if ($seg === null && $vTs !== null) {
            $seg = max(0, $vTs - $conTs);
        }
    }

    if ($online === 1) {
        $elapsed = max(0, $nowTs - $conTs);
    } elseif ($seg !== null) {
        $elapsed = max(0, $seg);
    } else {
        $elapsed = max(0, $nowTs - $conTs);
    }
    return ['online' => $online, 'seg' => $seg, 'elapsed' => $elapsed];
}

// Contadores dos cartões de resumo (aceita UM roteador ou uma LISTA deles —
// conta multi-MikroTik soma tudo):
//   online = sessões ativas agora | hoje = números que conectaram hoje
//   total  = todos os números já registrados (sem o teto de 2000 da tabela).
// Usado no painel do cliente e na tela de leads do admin (mesmos números).
//
// Conta DISTINCT telefone, não linhas: `leads` tem 1 linha por (roteador,
// telefone), então a mesma pessoa em dois MikroTiks da conta são duas linhas e
// COUNT(*) a contaria como dois clientes. Com um roteador só dá no mesmo (a
// UNIQUE garante um telefone por roteador) — por isso não há caso especial aqui,
// nem em leads_pagina(), que mescla as mesmas linhas na tabela.
function resumo_leads($roteadores): array
{
    $lista = array_values(array_filter(
        is_array($roteadores) ? $roteadores : [$roteadores],
        function ($v) { return (string) $v !== ''; }
    ));
    if (!$lista) {
        return ['online' => 0, 'hoje' => 0, 'total' => 0];
    }
    $ph = implode(',', array_fill(0, count($lista), '?'));
    // "online agora" = flag online=1 E confirmado pelo sync dentro da janela.
    // (evita contar sessões travadas quando o MikroTik/sync ficou fora do ar)
    $qOnline = db()->prepare(
        "SELECT COUNT(DISTINCT telefone) FROM leads WHERE roteador IN ($ph) AND online = 1
           AND visto_em IS NOT NULL AND visto_em >= (NOW() - INTERVAL " . MIKROTIK_TIMEOUT_SEG . ' SECOND)'
    );
    $qOnline->execute($lista);
    $qHoje = db()->prepare("SELECT COUNT(DISTINCT telefone) FROM leads WHERE roteador IN ($ph) AND DATE(conectado_em) = CURRENT_DATE");
    $qHoje->execute($lista);
    // cadastrados hoje = números cuja PRIMEIRA conexão foi hoje (leads novos).
    $qCad = db()->prepare("SELECT COUNT(DISTINCT telefone) FROM leads WHERE roteador IN ($ph) AND primeira_conexao IS NOT NULL AND DATE(primeira_conexao) = CURRENT_DATE");
    $qCad->execute($lista);
    $qTotal = db()->prepare("SELECT COUNT(DISTINCT telefone) FROM leads WHERE roteador IN ($ph)");
    $qTotal->execute($lista);
    return [
        'online'      => (int) $qOnline->fetchColumn(),
        'hoje'        => (int) $qHoje->fetchColumn(),
        'cadastrados' => (int) $qCad->fetchColumn(),
        'total'       => (int) $qTotal->fetchColumn(),
    ];
}

// Junta as linhas do MESMO telefone numa linha só (a pessoa, não o cadastro).
//
// Principal = a linha vista por último (visto_em, ou a conexão quando o sync
// ainda não confirmou). É ela que carrega o estado de agora: se a pessoa está
// online em algum MikroTik, aquela linha tem visto_em de segundos atrás e ganha
// — o online sai certo sem remendo em cima do lead_estado().
function lead_mesclado(array $linhas): array
{
    // Datas no formato 'Y-m-d H:i:s' comparam certo como texto.
    $quando = function (array $r): string {
        return max((string) ($r['visto_em'] ?? ''), (string) ($r['conectado_em'] ?? ''));
    };
    usort($linhas, function ($a, $b) use ($quando) {
        $c = strcmp($quando($b), $quando($a));
        return $c !== 0 ? $c : ((int) $b['id'] <=> (int) $a['id']);
    });

    $p = $linhas[0];
    // Todos os ids do grupo: o painel manda a lista de volta nas ações, para
    // excluir/limitar/ver conexões valerem para a pessoa, não para um cadastro.
    $p['ids']            = array_map(function ($r) { return (int) $r['id']; }, $linhas);
    $p['total_conexoes'] = array_sum(array_map('intval', array_column($linhas, 'total_conexoes')));
    $p['bytes_total']    = array_sum(array_map('intval', array_column($linhas, 'bytes_total')));
    // Nome: o da linha mais recente que TIVER nome. Se a pessoa foi identificada
    // num roteador e não no outro, ela não pode voltar a aparecer como número.
    if ((string) ($p['nome'] ?? '') === '') {
        foreach ($linhas as $r) {
            if ((string) ($r['nome'] ?? '') !== '') {
                $p['nome'] = $r['nome'];
                break;
            }
        }
    }
    return $p;
}

// Uma página da tabela de leads, já mesclada por telefone (ver lead_mesclado).
// Duas consultas de propósito: a primeira decide QUEM entra na página — o corte
// e a ordem são por pessoa, senão a paginação pularia gente ao juntar linhas.
function leads_pagina(array $roteadores, string $filtro, int $pagina, int $porPag): array
{
    if (!$roteadores) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($roteadores), '?'));

    $q = db()->prepare(
        "SELECT telefone, MAX(conectado_em) AS ult
           FROM leads WHERE roteador IN ($ph)" . filtro_leads_sql($filtro) . '
          GROUP BY telefone
          ORDER BY ult DESC
          LIMIT ' . max(1, $porPag) . ' OFFSET ' . max(0, ($pagina - 1) * $porPag)
    );
    $q->execute($roteadores);
    $tels = array_column($q->fetchAll(), 'telefone');
    if (!$tels) {
        return [];
    }

    // Agora todas as linhas dessas pessoas — inclusive as que o filtro deixou de
    // fora: o total de conexões e o consumo são da pessoa, não do recorte.
    $pt = implode(',', array_fill(0, count($tels), '?'));
    $q2 = db()->prepare(
        "SELECT id, telefone, nome, ip, dispositivo, conectado_em, online, segundos_conectado,
                visto_em, tempo_limite_min, banda_limite, total_conexoes,
                (SELECT COALESCE(SUM(c.bytes), 0) FROM conexoes c WHERE c.lead_id = leads.id) AS bytes_total
           FROM leads WHERE roteador IN ($ph) AND telefone IN ($pt)"
    );
    $q2->execute(array_merge($roteadores, $tels));

    $grupos = [];
    foreach ($q2->fetchAll() as $r) {
        $grupos[(string) $r['telefone']][] = $r;
    }
    $saida = [];
    foreach ($tels as $tel) { // mantém a ordem da primeira consulta
        if (isset($grupos[(string) $tel])) {
            $saida[] = lead_mesclado($grupos[(string) $tel]);
        }
    }
    return $saida;
}

// Ids de lead vindos do painel, filtrados pelo que a conta pode mexer.
// Aceita `ids` (linha mesclada: a mesma pessoa em vários MikroTiks) ou `id`
// sozinho. Id de outra conta é DESCARTADO, não recusado — o painel só manda os
// próprios, e descartar mantém a ação restrita ao dono mesmo se vier lixo.
function leads_permitidos($pedido, array $comprador): array
{
    $ids = [];
    foreach ((is_array($pedido) ? $pedido : [$pedido]) as $v) {
        $n = (int) $v;
        if ($n > 0 && !in_array($n, $ids, true)) {
            $ids[] = $n;
        }
        if (count($ids) >= 50) { // teto: uma pessoa não está em 50 roteadores
            break;
        }
    }
    if (!$ids) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $q  = db()->prepare("SELECT id, roteador FROM leads WHERE id IN ($ph)");
    $q->execute($ids);
    $rows = $q->fetchAll();
    if ((int) $comprador['is_admin'] === 1) {
        return array_map(function ($r) { return (int) $r['id']; }, $rows);
    }
    $meus = roteadores_conta((int) $comprador['id']);
    $ok   = [];
    foreach ($rows as $r) {
        if (in_array($r['roteador'], $meus, true)) {
            $ok[] = (int) $r['id'];
        }
    }
    return $ok;
}

// --- Tipo de painel: varejo (loja/cafeteria) ou hospedagem (pousada/hotel) ---
//
// No varejo o portal CAPTURA o número de quem conecta. Na hospedagem ele
// VALIDA: o hóspede passa o telefone na recepção, entra no painel, e o portal
// só libera quem está cadastrado e ainda não passou da data de saída.
const PAINEL_TIPOS = ['varejo', 'hospedagem'];

function painel_tipo_valido(?string $t): string
{
    return in_array((string) $t, PAINEL_TIPOS, true) ? (string) $t : 'varejo';
}

// Tipo de painel de uma conta. Coluna nova: banco ainda sem ela = varejo.
function painel_tipo(int $compradorId): string
{
    try {
        $q = db()->prepare('SELECT painel FROM compradores WHERE id = ?');
        $q->execute([$compradorId]);
        return painel_tipo_valido((string) $q->fetchColumn());
    } catch (Throwable $e) {
        return 'varejo';
    }
}

// Modo do ROTEADOR, que é o que o portal do hotspot precisa saber.
//
// O tipo é da conta, mas desde a migração migrar_roteador_compartilhado o mesmo
// MikroTik pode estar em várias contas. Se QUALQUER dona for de hospedagem, o
// portal daquele roteador valida hóspede — não dá para o portal ser as duas
// coisas, e liberar só hóspede é o lado seguro (o contrário abriria o WiFi da
// pousada para qualquer número).
function roteador_modo(string $identity): string
{
    if ($identity === '') {
        return 'varejo';
    }
    try {
        $q = db()->prepare(
            "SELECT 1 FROM roteadores r
               JOIN compradores c ON c.id = r.comprador_id
              WHERE r.identity = ? AND c.painel = 'hospedagem' LIMIT 1"
        );
        $q->execute([$identity]);
        return $q->fetchColumn() !== false ? 'hospedagem' : 'varejo';
    } catch (Throwable $e) {
        return 'varejo';
    }
}

// Momento da saída a partir do que a recepção digita: check-in + diárias, na
// hora do check-out. Devolve 'Y-m-d H:i:s'. Fora daqui ninguém calcula isso —
// a data de saída é gravada pronta para o portal comparar com um SELECT só.
function hospede_saida(string $entrada, int $dias, string $hora): string
{
    $ts = strtotime($entrada . ' ' . $hora);
    if ($ts === false) {
        $ts = strtotime(date('Y-m-d') . ' 12:00');
    }
    return date('Y-m-d H:i:s', strtotime('+' . max(1, $dias) . ' day', $ts));
}

// Hóspedes de um ou mais roteadores, os que ainda não saíram primeiro.
function hospedes_lista(array $roteadores): array
{
    if (!$roteadores) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($roteadores), '?'));
    try {
        $q = db()->prepare(
            "SELECT id, roteador, nome, quarto, telefone, entrada_em, dias, saida_em,
                    (saida_em > NOW()) AS hospedado
               FROM hospedes WHERE roteador IN ($ph)
              ORDER BY hospedado DESC, saida_em ASC"
        );
        $q->execute($roteadores);
        return $q->fetchAll();
    } catch (Throwable $e) {
        return []; // banco sem a tabela ainda: a tela mostra a lista vazia
    }
}

// Contadores dos cartões do painel de hospedagem.
function hospedes_resumo(array $roteadores): array
{
    $lista = hospedes_lista($roteadores);
    $r = ['hospedados' => 0, 'saem_hoje' => 0, 'total' => count($lista)];
    $hoje = date('Y-m-d');
    foreach ($lista as $h) {
        if ((int) $h['hospedado'] === 1) {
            $r['hospedados']++;
            if (substr((string) $h['saida_em'], 0, 10) === $hoje) {
                $r['saem_hoje']++;
            }
        }
    }
    return $r;
}

// Filtro dos cartões de resumo ('' = todos | online | hoje | cadastrados).
// Valida o valor vindo da URL e devolve a condição SQL extra da tabela de
// leads — MESMOS critérios dos contadores, para a tabela bater com o cartão.
function filtro_leads(?string $f): string
{
    return in_array((string) $f, ['online', 'hoje', 'cadastrados'], true) ? (string) $f : '';
}

function filtro_leads_sql(string $f): string
{
    switch ($f) {
        case 'online':
            return ' AND online = 1 AND visto_em IS NOT NULL AND visto_em >= (NOW() - INTERVAL ' . MIKROTIK_TIMEOUT_SEG . ' SECOND)';
        case 'hoje':
            return ' AND DATE(conectado_em) = CURRENT_DATE';
        case 'cadastrados':
            return ' AND primeira_conexao IS NOT NULL AND DATE(primeira_conexao) = CURRENT_DATE';
    }
    return '';
}

// --- Página de login do hotspot (arquivos por roteador) ---
// O painel guarda aqui os arquivos (extraídos de um .zip); o MikroTik os BAIXA (pull)
// para flash/hostsv7 via leadsync.rsc — a hospedagem compartilhada não alcança o
// roteador (sem túnel). Ficam dentro de ads/ (já bloqueada por .htaccess); só saem
// por api/portal.php. Quem garante a pasta flash/hostsv7 e as subpastas e o
// proprio roteador, no bloco do portal do leadsync — daqui nao se alcanca ele.
//
// IMPORTANTE: guardamos os arquivos PLANOS (sem subpastas) porque a hospedagem nem
// sempre deixa criar subpastas dentro de ads/. A barra do caminho lógico vira "~" no
// nome do arquivo (ex.: "css/style.css" -> "css~style.css"). Como "~" nunca aparece
// num segmento válido, a conversão é sem ambiguidade. Só o roteador tem subpastas reais.
const PORTAL_EXTS = ['html', 'htm', 'css', 'js', 'svg', 'png', 'jpg', 'jpeg',
                     'gif', 'ico', 'json', 'txt', 'xml', 'xsd', 'woff', 'woff2', 'ttf', 'eot'];

// Pasta-raiz dos arquivos do portal deste roteador (hash do identity, como o anúncio).
function portal_dir(string $roteador): string
{
    return anuncio_base($roteador) . '.portal';
}

// Caminho lógico ("css/style.css") <-> nome do arquivo plano no disco ("css~style.css").
function portal_encode(string $rel): string { return str_replace('/', '~', $rel); }
function portal_decode(string $nome): string { return str_replace('~', '/', $nome); }

// Caminho RELATIVO lógico seguro (com subpastas): sem traversal, cada segmento só com
// letras/números/._-, não começa com ponto, extensão permitida, profundidade <= 4.
// Ex.: "login.html", "css/style.css", "img/user.svg", "xml/WISPAccessGatewayParam.xsd".
function portal_path_ok(string $rel): bool
{
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || $rel[0] === '/' || strpos($rel, '..') !== false) {
        return false;
    }
    $segs = explode('/', $rel);
    if (count($segs) > 4) {
        return false;
    }
    foreach ($segs as $s) {
        if ($s === '' || $s[0] === '.' || !preg_match('/^[A-Za-z0-9._-]+$/', $s)) {
            return false;
        }
    }
    $ext = strtolower((string) pathinfo($rel, PATHINFO_EXTENSION));
    return in_array($ext, PORTAL_EXTS, true);
}

// Lista TODOS os arquivos do portal como caminhos LÓGICOS (decodificados, ordenados).
function portal_files(string $roteador): array
{
    if (trim($roteador) === '') {
        return [];
    }
    $base = portal_dir($roteador);
    if (!is_dir($base)) {
        return [];
    }
    $out = [];
    foreach (scandir($base) ?: [] as $f) {
        if ($f !== '.' && $f !== '..' && is_file($base . '/' . $f)) {
            $out[] = portal_decode($f); // nome plano no disco -> caminho lógico
        }
    }
    sort($out);
    return $out;
}

// --- Página de login PADRÃO (o template do repositório) ---
//
// Roteador recém-configurado não tem página nenhuma: portal_files() só devolve o
// que o COMPRADOR subiu pelo painel, e quem nunca subiu ficava sem login.html —
// o setup só terminava com alguém arrastando arquivo no Winbox. Aqui entra o
// template de mikrotik/portal/, que o roteador baixa igual aos outros.
//
// Também é o que faz uma correção no template (ex.: o passo de validação do
// hóspede) chegar em quem nunca personalizou a tela.
function portal_base_dir(): string
{
    return __DIR__ . '/../mikrotik/portal';
}

// Caminhos LÓGICOS do template padrão, na forma que o roteador grava em
// hostsv7/ (login.html, css/style.css, fonts/*.woff2).
function portal_base_files(): array
{
    $dir = portal_base_dir();
    $out = [];
    if (is_file($dir . '/login.html')) {
        $out[] = 'login.html';
    }
    // O style.css do template mora solto; no roteador ele é css/style.css,
    // que é o caminho que o login.html referencia.
    if (is_file($dir . '/style.css')) {
        $out[] = 'css/style.css';
    }
    foreach (glob($dir . '/fonts/*.woff2') ?: [] as $f) {
        $out[] = 'fonts/' . basename($f);
    }
    return $out;
}

// Caminho em disco de um arquivo do template, ou null se não for do conjunto.
// Só aceita o que portal_base_files() lista — nada de caminho vindo de fora.
function portal_base_path(string $logico): ?string
{
    if (!in_array($logico, portal_base_files(), true)) {
        return null;
    }
    $dir = portal_base_dir();
    $real = $logico === 'css/style.css' ? $dir . '/style.css' : $dir . '/' . $logico;
    return is_file($real) ? $real : null;
}

// O conjunto que o ROTEADOR deve ter: o do comprador quando existe, senão o
// padrão. Separado de portal_files() de propósito — o painel mostra "arquivos
// enviados", e o template não foi enviado por ninguém.
function portal_conjunto(string $roteador): array
{
    $meus = portal_files($roteador);
    return $meus ?: portal_base_files();
}

// Versão do conjunto (muda quando qualquer arquivo muda). O MikroTik compara com a
// última aplicada e só rebaixa quando difere — poupa a flash. Usa mtime+size (stat,
// sem ler os arquivos) porque o roteador consulta isso a cada minuto.
function portal_versao(string $roteador): string
{
    $meus = portal_files($roteador);
    $base = portal_dir($roteador);
    $sig  = '';
    foreach (portal_conjunto($roteador) as $f) {
        $p = $meus ? $base . '/' . portal_encode($f) : (string) portal_base_path($f);
        $sig .= $f . ':' . (int) @filemtime($p) . ':' . (int) @filesize($p) . '|';
    }
    return $sig === '' ? '0' : substr(sha1($sig), 0, 16);
}

// --- Historico de envios da pagina de login do hotspot ---
//
// O disco nao guarda essa informacao: o .zip e desmontado na extracao (so
// sobram os arquivos soltos) e cada envio apaga o anterior. Sem registro
// proprio nao da para saber QUAL template esta no ar nem desde quando — o que
// importa quando o portal quebra e e preciso decidir se foi o ultimo envio.
//
// Uma linha JSON por roteador, em ads/ (pasta fechada por .htaccess), no mesmo
// esquema dos alertas. Nao vai para o banco porque nao e dado de cliente e
// nunca e consultado em conjunto.
const PORTAL_HIST_MAX = 40;

function portal_hist_file(string $roteador): string
{
    return anuncio_base($roteador) . '.hist.json';
}

// Mais recente primeiro.
function portal_hist(string $roteador): array
{
    if (trim($roteador) === '') { return []; }
    $j = @file_get_contents(portal_hist_file($roteador));
    $d = $j === false ? null : json_decode($j, true);
    return is_array($d) ? $d : [];
}

// $qtd: arquivos aplicados (1 num envio avulso). $quem: quem estava logado.
function portal_hist_add(string $roteador, string $nome, int $qtd, string $quem): void
{
    if (trim($roteador) === '') { return; }
    // db_now() e nao date(): e o mesmo relogio das outras datas do painel, entao
    // o horario do envio nao diverge do das conexoes.
    try { $agora = db_now(); } catch (Throwable $e) { $agora = date('Y-m-d H:i:s'); }
    $h = portal_hist($roteador);
    array_unshift($h, ['nome' => $nome, 'qtd' => $qtd, 'quem' => $quem, 'em' => $agora]);
    @file_put_contents(
        portal_hist_file($roteador),
        json_encode(array_slice($h, 0, PORTAL_HIST_MAX), JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

// Reparte sessoes de conexao pelos 7 dias da semana, em segundos.
//
// Somar `conexoes.segundos` no dia em que a sessao COMECOU dava celula de 41h
// num dia de 24h: a sessao que varre segunda -> quarta caia inteira na segunda.
// Aqui cada sessao e cortada na semana, os periodos que se sobrepoem viram um
// so (dois aparelhos no ar ao mesmo tempo = um periodo conectado, nao o dobro)
// e o resultado e repartido pelos dias que ele atravessa.
//
// $sessoes: lista de [inicio, fim] em timestamp.  $lim: os 8 marcos 00:00 da
// semana (indice 0 = domingo, 7 = domingo seguinte).  Devolve 7 inteiros.
function semana_reparte(array $sessoes, array $lim): array
{
    $dias = [0, 0, 0, 0, 0, 0, 0];
    foreach (intervalos_juntar($sessoes, $lim[0], $lim[7]) as $x) {
        for ($k = 0; $k < 7; $k++) {
            $a = max($x[0], $lim[$k]);
            $b = min($x[1], $lim[$k + 1]);
            if ($b > $a) { $dias[$k] += $b - $a; }
        }
    }
    return $dias;
}

// Corta as sessoes na janela [$ini, $fim] e funde as que se sobrepoem, para que
// tempo simultaneo conte uma vez so (dois aparelhos no mesmo numero no ar ao
// mesmo tempo = um periodo conectado, nao o dobro).
// $sessoes: lista de [inicio, fim] em timestamp. Devolve a lista sem sobreposicao.
function intervalos_juntar(array $sessoes, int $ini, int $fim): array
{
    $iv = [];
    foreach ($sessoes as $s) {
        $a = max((int) $s[0], $ini);
        $b = min((int) $s[1], $fim);
        if ($b > $a) { $iv[] = [$a, $b]; }
    }
    usort($iv, function ($a, $b) { return $a[0] <=> $b[0]; });
    $juntos = [];
    foreach ($iv as $x) {
        $n = count($juntos);
        if ($n && $x[0] <= $juntos[$n - 1][1]) {
            if ($x[1] > $juntos[$n - 1][1]) { $juntos[$n - 1][1] = $x[1]; }
        } else {
            $juntos[] = $x;
        }
    }
    return $juntos;
}

// Segundos conectados dentro da janela, ja com as sobreposicoes fundidas.
function intervalos_total(array $sessoes, int $ini, int $fim): int
{
    $t = 0;
    foreach (intervalos_juntar($sessoes, $ini, $fim) as $x) { $t += $x[1] - $x[0]; }
    return $t;
}

// Sessoes de `conexoes` que ENCOSTAM na janela [$iniTs, $fimTs) — nao so as que
// COMECAM nela: uma sessao de segunda a quarta pertence aos tres dias. Quem
// recorta e quem chama (intervalos_juntar / semana_reparte).
//
// O fim de cada linha e o instante gravado (conectado_em + segundos) ou, se a
// sessao segue aberta, o ultimo instante CONFIRMADO pelo roteador (visto_em).
// Nunca NOW(): conexao que o polling jamais viu fica com os dois campos nulos
// para sempre, e trata-la como "ate agora" enchia o relatorio de sessoes
// eternas. Sem os dois campos a duracao e desconhecida e o WHERE ja a descarta,
// porque comparacao com NULL nao e verdadeira.
function sessoes_janela(array $roteadores, int $iniTs, int $fimTs): array
{
    if (!$roteadores) { return []; }
    $ph  = implode(',', array_fill(0, count($roteadores), '?'));
    $sqlFim = 'COALESCE(DATE_ADD(c.conectado_em, INTERVAL c.segundos SECOND), c.visto_em)';
    $sel = "SELECT c.lead_id, l.telefone, l.nome, c.conectado_em, $sqlFim AS fim
              FROM conexoes c JOIN leads l ON l.id = c.lead_id
             WHERE l.roteador IN ($ph)
               AND c.conectado_em < ? AND $sqlFim >= ?";
    $arg = array_merge($roteadores, [date('Y-m-d H:i:s', $fimTs), date('Y-m-d H:i:s', $iniTs)]);
    try {
        $q = db()->prepare($sel);
        $q->execute($arg);
    } catch (Throwable $e) {
        // Banco antigo, sem conexoes.visto_em (o status.php cria na 1a rodada):
        // so as sessoes ja fechadas entram.
        $q = db()->prepare(str_replace(', c.visto_em', '', $sel));
        $q->execute($arg);
    }
    return $q->fetchAll();
}

// Data do marco de N meses, sem escorregar de mes.
//
// strtotime("2025-08-31 +3 month") devolve 2025-12-01, porque novembro nao tem
// dia 31 e o PHP transborda para o mes seguinte. Aqui o mes e avancado a partir
// do dia 1 (que existe sempre) e o dia so entao e grampeado no ultimo dia do
// mes de destino: 31/08 +3m = 30/11, 30/11 +3m = 28/02.
function marco_mes(string $ymd, int $meses): string
{
    $dia  = (int) substr($ymd, 8, 2);
    $alvo = strtotime(substr($ymd, 0, 7) . '-01 +' . $meses . ' month');
    return date('Y-m-', $alvo) . str_pad((string) min($dia, (int) date('t', $alvo)), 2, '0', STR_PAD_LEFT);
}

// Quantas vezes cada dia da semana cai no periodo (chaves 1..7 = DAYOFWEEK do
// MySQL, 1 = domingo).
//
// Sem isso o grafico por dia da semana mente em periodo que nao e multiplo de
// 7: num periodo de 8 dias uma segunda aparece duas vezes e os outros dias uma,
// e a barra de segunda sai com o dobro sem que o movimento tenha mudado.
function ocorrencias_dow(string $inicio, string $fim): array
{
    $occ = array_fill(1, 7, 0);
    $t   = strtotime($inicio . ' 00:00:00');
    $ate = strtotime($fim . ' 00:00:00');
    while ($t <= $ate) {
        $occ[(int) date('w', $t) + 1]++;
        $t = strtotime('+1 day', $t);
    }
    return $occ;
}

// Intervalo [inicio, fim] de UMA linha de `conexoes`, em timestamp, ou null
// quando a duracao e desconhecida.
//
// $fim vem do banco como conectado_em + segundos (sessao fechada) ou visto_em
// (sessao aberta, ultimo instante confirmado pelo roteador). Vazio significa
// conexao que o polling nunca viu: o lead.php abre a linha com segundos e
// visto_em nulos, e o status.php so fecha as que ja foram vistas, entao ela
// fica assim para sempre. Tratar isso como "ate agora" fazia cada cliente
// aparecer conectado a semana inteira.
function conexao_intervalo(?string $inicio, ?string $fim, int $agora): ?array
{
    if ($inicio === null || $inicio === '' || $fim === null || $fim === '') { return null; }
    $a = strtotime($inicio);
    $b = strtotime($fim);
    if ($a === false || $b === false) { return null; }
    if ($b > $agora) { $b = $agora; }      // relogio adiantado nao vira tempo
    return $b > $a ? [$a, $b] : null;
}

// --- Alertas: quais avisos o comprador quer ver, por CONTA ---
// Guardado em ads/ (pasta fechada por .htaccess), uma linha JSON por conta.
// A escolha e do usuario, nao do roteador: quem tem varios MikroTiks ve os
// mesmos avisos em todos.

// Catalogo dos avisos. tom: 'ruim' (vermelho) ou 'bom' (verde).
// 'lista' diz se o numero abre a relacao de clientes.
function alertas_catalogo(): array
{
    return [
        'sem_vir_semana'   => ['Clientes sumidos há uma semana', 'Vieram alguma vez e não aparecem há 7 dias ou mais.', 'ruim', true],
        'fieis_sumidos'    => ['Fiéis que sumiram', 'Vinham em 4 semanas ou mais e estão há 7 dias sem aparecer.', 'ruim', true],
        'visita_unica'     => ['Vieram uma vez e não voltaram', 'Conheceram o Wi-Fi há mais de 7 dias e não deram as caras de novo.', 'ruim', true],
        'queda_semana'     => ['Movimento em queda', 'Os acessos desta semana caíram mais de 20% ante a semana passada.', 'ruim', false],
        'mikrotik_offline' => ['Roteador fora do ar', 'O roteador parou de reportar para o painel.', 'ruim', false],
        'forte_recorrencia'=> ['Clientes em sequência', 'Vieram 5 dias seguidos ou mais no último mês.', 'bom', true],
        'novos_semana'     => ['Clientes novos', 'Apareceram pela primeira vez nos últimos 7 dias.', 'bom', true],
        'reativados'       => ['Clientes que voltaram', 'Sumiram por 30 dias ou mais e reapareceram nesta semana.', 'bom', true],
        'alta_semana'      => ['Movimento em alta', 'Os acessos desta semana subiram mais de 20% ante a semana passada.', 'bom', false],
    ];
}

// Marcados por padrao na primeira vez (os tres do pedido + novos).
function alertas_padrao(): array
{
    $out = [];
    foreach (alertas_catalogo() as $k => $_) {
        $out[$k] = in_array($k, ['sem_vir_semana', 'fieis_sumidos', 'forte_recorrencia', 'novos_semana'], true) ? 1 : 0;
    }
    return $out;
}

function alertas_file(int $compradorId): string
{
    return ads_dir() . '/alertas_' . $compradorId . '.json';
}

function alertas_get(int $compradorId): array
{
    $def = alertas_padrao();
    $j = json_decode((string) @file_get_contents(alertas_file($compradorId)), true);
    if (!is_array($j)) {
        return $def;
    }
    $out = [];
    foreach ($def as $k => $v) {
        // Chave nova (alerta criado depois) segue o padrao dela.
        $out[$k] = array_key_exists($k, $j) ? (!empty($j[$k]) ? 1 : 0) : $v;
    }
    return $out;
}

function alertas_set(int $compradorId, array $marcadas): void
{
    $out = [];
    foreach (alertas_catalogo() as $k => $_) {
        $out[$k] = !empty($marcadas[$k]) ? 1 : 0;
    }
    $dir = ads_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents(alertas_file($compradorId), json_encode($out));
}

// --- O que o roteador informa sobre si mesmo (versao do RouterOS e modelo) ---
// O leadsync manda isso junto do heartbeat. Serve para saber com o que se esta
// lidando sem ir ate o equipamento — decisivo, por exemplo, para responder se
// da para usar WireGuard (so existe do RouterOS 7 em diante).
function mikrotik_info_file(string $roteador): string
{
    return anuncio_base($roteador) . '.info';
}

function mikrotik_info_set(string $roteador, string $versao, string $board): void
{
    $versao = substr(preg_replace('/[^0-9A-Za-z._-]/', '', $versao), 0, 32);
    $board  = substr(preg_replace('/[^0-9A-Za-z._\- ]/', '', $board), 0, 48);
    if ($versao === '' && $board === '') {
        return;
    }
    $dir = ads_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents(mikrotik_info_file($roteador),
        json_encode(['ros' => $versao, 'board' => $board, 'visto' => date('c')]));
}

function mikrotik_info_get(string $roteador): ?array
{
    $j = json_decode((string) @file_get_contents(mikrotik_info_file($roteador)), true);
    return is_array($j) ? $j : null;
}
