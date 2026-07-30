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
        "SELECT COUNT(*) FROM leads WHERE roteador IN ($ph) AND online = 1
           AND visto_em IS NOT NULL AND visto_em >= (NOW() - INTERVAL " . MIKROTIK_TIMEOUT_SEG . ' SECOND)'
    );
    $qOnline->execute($lista);
    $qHoje = db()->prepare("SELECT COUNT(*) FROM leads WHERE roteador IN ($ph) AND DATE(conectado_em) = CURRENT_DATE");
    $qHoje->execute($lista);
    // cadastrados hoje = números cuja PRIMEIRA conexão foi hoje (leads novos).
    $qCad = db()->prepare("SELECT COUNT(*) FROM leads WHERE roteador IN ($ph) AND primeira_conexao IS NOT NULL AND DATE(primeira_conexao) = CURRENT_DATE");
    $qCad->execute($lista);
    $qTotal = db()->prepare("SELECT COUNT(*) FROM leads WHERE roteador IN ($ph)");
    $qTotal->execute($lista);
    return [
        'online'      => (int) $qOnline->fetchColumn(),
        'hoje'        => (int) $qHoje->fetchColumn(),
        'cadastrados' => (int) $qCad->fetchColumn(),
        'total'       => (int) $qTotal->fetchColumn(),
    ];
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
// por api/portal.php. O fetch do RouterOS recria as subpastas em flash/hostsv7.
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

// Versão do conjunto (muda quando qualquer arquivo muda). O MikroTik compara com a
// última aplicada e só rebaixa quando difere — poupa a flash. Usa mtime+size (stat,
// sem ler os arquivos) porque o roteador consulta isso a cada minuto.
function portal_versao(string $roteador): string
{
    $base = portal_dir($roteador);
    $sig = '';
    foreach (portal_files($roteador) as $f) {
        $p = $base . '/' . portal_encode($f);
        $sig .= $f . ':' . (int) @filemtime($p) . ':' . (int) @filesize($p) . '|';
    }
    return $sig === '' ? '0' : substr(sha1($sig), 0, 16);
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
    $iv = [];
    foreach ($sessoes as $s) {
        $ini = max((int) $s[0], $lim[0]);
        $fim = min((int) $s[1], $lim[7]);
        if ($fim > $ini) { $iv[] = [$ini, $fim]; }
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
    foreach ($juntos as $x) {
        for ($k = 0; $k < 7; $k++) {
            $a = max($x[0], $lim[$k]);
            $b = min($x[1], $lim[$k + 1]);
            if ($b > $a) { $dias[$k] += $b - $a; }
        }
    }
    return $dias;
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
        'mikrotik_offline' => ['MikroTik fora do ar', 'O roteador parou de reportar para o painel.', 'ruim', false],
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
