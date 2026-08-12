<?php
// Painel de DEMONSTRAÇÃO da landing page. Nada aqui é real.
//
// Por que é um arquivo separado, e não o painel.php com uma flag "demo":
// o painel.php é a tela autenticada. Ensinar ele a renderizar sem sessão seria
// criar um caminho que desvia do login — e um dia alguém erra a condição. Aqui
// não há sessão, não há banco e não há chamada de API: os leads são inventados
// neste próprio arquivo. O que se reaproveita é o que não tem risco: o CSS
// (assets/style.css), o JS das abas (assets/abas.js) e os formatadores puros do
// inc/util.php. É por isso que a tela sai idêntica à de verdade.
//
// Também NÃO tem o script que joga a página para dentro da casca (/painel):
// esta abre como página normal, e o botão do rodapé volta para a landing.
require_once __DIR__ . '/inc/util.php';

$agora = time();
$hoje  = strtotime('today', $agora);

// --- Leads inventados, mas coerentes -------------------------------------
//
// Cada linha respeita as mesmas regras que o painel real espera:
//   online=1  -> visto_em recente (senão o lead_estado congela e vira offline)
//                e o tempo conectado é contado a partir de conectado_em;
//   online=0  -> segundos_conectado preenchido, e conectado_em mais antigo.
// O consumo acompanha a duração: quem ficou 2 h gastou mais que quem ficou 6
// min. Sem isso a tabela até aparece, mas não convence ninguém que olha.
//
// [nome, telefone, aparelho, minutos atrás que conectou, minutos conectado
//  (null = ainda online), MB, banda, limite]
$base = [
    ['Marina Duarte',  '48991820477', 'iPhone 13',            8,   null, 210,  null, null],
    ['',               '48988431290', 'Samsung Galaxy S23',   17,  null, 486,  null, 60],
    ['Rafael Bittenc.', '48996077315', 'Xiaomi Redmi Note 12', 24,  null, 1180, 10,   null],
    ['',               '48984552108', 'iPhone 11',            41,  null, 92,   null, null],
    ['Ana Beatriz',    '48991336742', 'Motorola Moto G84',    56,  null, 2310, null, 120],
    ['',               '48997260183', 'iPhone 15 Pro',        95,  62,   1540, null, null],
    ['Carlos Menezes', '48988014926', 'Samsung Galaxy A54',   134, 47,   730,  5,    null],
    ['',               '48992745630', 'Android',              181, 28,   315,  null, 60],
    ['Juliana Prado',  '48996518874', 'iPhone 12',            240, 118,  4120, null, null],
    ['',               '48985109362', 'Xiaomi Poco X5',       302, 15,   96,   null, null],
    ['Eduardo Lima',   '48991447028', 'iPhone 14',            366, 74,   1980, null, 90],
    ['',               '48997832451', 'Samsung Galaxy M34',   428, 9,    42,   null, null],
    ['Patrícia Souza', '48988670135', 'Motorola Edge 40',     512, 156,  6240, null, null],
    ['',               '48993204587', 'iPhone SE',            1490, 33,  204,  null, null],
    ['Bruno Carvalho', '48996741320', 'Samsung Galaxy S21',   1655, 91,  2870, 20,   null],
    ['',               '48984023918', 'Android',              1802, 6,   18,   null, null],
    ['Letícia Amorim', '48991658274', 'iPhone 13 mini',       2940, 143, 5310, null, null],
    ['',               '48997410265', 'Xiaomi Redmi 13C',     3115, 21,  127,  null, null],
];

$leads = [];
foreach ($base as $i => [$nome, $tel, $apar, $atras, $durMin, $mb, $banda, $lim]) {
    $conTs  = $agora - $atras * 60;
    $online = $durMin === null ? 1 : 0;
    $leads[] = [
        'id'                 => 1000 + $i,
        'telefone'           => $tel,
        'nome'               => $nome !== '' ? $nome : null,
        'ip'                 => '10.5.50.' . (12 + $i),
        'dispositivo'        => $apar,
        'conectado_em'       => date('Y-m-d H:i:s', $conTs),
        // Online = visto AGORA. O lead_estado congela a sessão quando o último
        // sinal do roteador passa de MIKROTIK_TIMEOUT_SEG (15 s) — com 20 s
        // aqui, os cinco "online" saíam da tela como offline.
        'visto_em'           => date('Y-m-d H:i:s', $online ? $agora : $conTs + $durMin * 60),
        'online'             => $online,
        'segundos_conectado' => $online ? null : $durMin * 60,
        'tempo_limite_min'   => $lim,
        'banda_limite'       => $banda,
        // Mais visitas para quem já apareceu mais vezes; o número da bolinha
        // de conexões precisa fazer sentido com a data da primeira visita.
        'total_conexoes'     => 1 + intdiv($atras, 380),
        'bytes_total'        => $mb * 1048576,
    ];
}

// Os cartões saem da MESMA lista da tabela — nada de número solto que não
// bate com o que está logo abaixo dele.
$resumo = ['online' => 0, 'hoje' => 0, 'cadastrados' => 0, 'total' => count($leads)];
foreach ($leads as $l) {
    $conTs = strtotime($l['conectado_em']);
    if ((int) $l['online'] === 1) { $resumo['online']++; }
    if ($conTs >= $hoje) {
        $resumo['hoje']++;
        // "Cadastrado hoje" é quem apareceu pela PRIMEIRA vez hoje — quem já
        // tinha visita anterior conta só em "conectados". Deixar os dois
        // iguais entregaria na hora que o número é decorativo.
        if ((int) $l['total_conexoes'] === 1) { $resumo['cadastrados']++; }
    }
}

$rotAtivo = 'HOTSPOT-DEMO';

// Abas que existem no painel real mas que a demonstração não simula. Em vez de
// deixar o clique sem resposta, cada uma mostra o mesmo cartão com um convite.
$abasVitrine = [
    'dashboard'    => ['Dashboard',       'A visão da semana: quantos conectaram por dia, horários de pico e quem voltou.'],
    'informacoes'  => ['Informações',     'Os hábitos de um cliente: dias que visitou, horário preferido e há quanto tempo não aparece.'],
    'alertas'      => ['Alertas',         'Avisos por e-mail quando o roteador cai ou o movimento foge do normal.'],
    'relatorios'   => ['Relatórios',      'Planilha dos leads e dos acessos, por período, pronta para o Excel.'],
    'anuncio'      => ['Personalizar',    'Sua logo, seu anúncio e as cores da tela que o cliente vê ao conectar.'],
    'url'          => ['Url do site',     'Para onde o cliente vai depois do anúncio — inclusive uma página de Instagram montada por você.'],
    'limites'      => ['Limites',         'Tempo de sessão e banda por cliente, para um não levar o Wi-Fi da loja inteira.'],
    'estatisticas' => ['Estatísticas',    'Aparelhos, operadoras e a curva de conexões ao longo do mês.'],
];
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <script>(function(){try{var t=localStorage.getItem('cd-tema');document.documentElement.setAttribute('data-tema',t==='escuro'?'escuro':'claro');}catch(e){document.documentElement.setAttribute('data-tema','claro');}})();</script>
    <meta name="format-detection" content="telephone=no">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Captive Data — Painel de demonstração</title>
    <link rel="icon" href="assets/logo-icone.png?v=1" type="image/png">
    <link rel="stylesheet" href="assets/style.css?v=129">
    <style>
        /* Tarja da demonstração: ninguém pode confundir esta tela com a real. */
        .demo-tarja {
            display: flex; align-items: center; justify-content: center; gap: 10px;
            flex-wrap: wrap; text-align: center;
            margin-bottom: 14px; border-radius: 14px;
            padding: 9px 16px; font-size: 13px; font-weight: 600; line-height: 1.4;
            background: linear-gradient(90deg, #7c3aed, #ec4899); color: #fff;
        }
        .demo-tarja a { color: #fff; text-decoration: underline; text-underline-offset: 2px; }
        .demo-vitrine { text-align: center; padding: 26px 20px 30px; }
        .demo-vitrine .pc-anuncio-desc { max-width: 46ch; margin: 0 auto 18px; }
    </style>
</head>
<body class="painel-cliente">
    <div class="pc-bg-gradient"></div>
    <div class="pc-bg-noise"></div>
    <div class="pc-glow pc-glow-top"></div>
    <div class="pc-glow pc-glow-bottom"></div>

    <div class="pc-layout">
        <aside class="pc-sidebar" id="pc-sidebar">
            <div class="pc-side-brand">
                <img src="assets/logo-icone.png?v=1" alt="">
                <span>Captive Data</span>
            </div>
            <nav class="pc-side-nav" aria-label="Seções do painel">
                <button type="button" class="pc-side-item atual" data-aba="painel">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Painel
                </button>
                <?php foreach ($abasVitrine as $ak => [$al, $ad]): ?>
                <button type="button" class="pc-side-item" data-aba="<?= h($ak) ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                    <?= h($al) ?>
                </button>
                <?php endforeach; ?>
            </nav>
            <div class="pc-side-foot">
                <?php /* No painel real este é o "Sair". Aqui volta para a landing. */ ?>
                <a class="pc-side-sair" href="/">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
                    Voltar para o início
                </a>
            </div>
        </aside>
        <div class="pc-side-backdrop" aria-hidden="true"></div>

        <div class="pc-shell">
            <?php /* Dentro da .pc-shell, não acima da .pc-layout: o layout é um
                     flex de altura 100dvh, e uma faixa por fora dele empurraria
                     tudo para baixo e criaria rolagem na página inteira. */ ?>
            <div class="demo-tarja">
                <span>Você está numa <strong>demonstração</strong>. Todos os leads, números e aparelhos desta tela são inventados.</span>
                <a href="/">voltar para o site</a>
            </div>

            <div class="mk-status mk-on">
                <span class="mk-led"></span>
                <span class="mk-text">Roteador online</span>
            </div>

            <header class="pc-topbar">
                <div class="pc-topbar-left">
                    <button type="button" class="pc-menu-btn" id="pc-menu-btn" aria-label="Abrir/fechar menu">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
                    </button>
                    <div>
                        <h1 class="pc-brand">Painel de Leads</h1>
                        <p class="pc-sub">Olá, Cafeteria Modelo — roteador <?= h($rotAtivo) ?></p>
                    </div>
                </div>
            </header>

            <!-- ============ ABA: PAINEL (métricas + leads) ============ -->
            <section class="pc-tela atual" data-tela="painel">
                <div class="pc-summary">
                    <?php foreach ([
                        ['online',      'online agora'],
                        ['hoje',        'conectados hoje'],
                        ['cadastrados', 'cadastrados hoje'],
                        ['total',       'total de leads'],
                    ] as [$mk, $ml]): ?>
                    <span class="glow-card pc-metric<?= $mk === 'total' ? ' atual' : '' ?>">
                        <span class="glow-fx" aria-hidden="true"></span>
                        <div class="glow-body">
                            <span class="pc-metric-num"><?= $resumo[$mk] ?></span>
                            <span class="pc-metric-label"><?= $ml ?></span>
                        </div>
                    </span>
                    <?php endforeach; ?>
                </div>

                <div class="glow-card pc-table-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <div class="pc-table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Número</th>
                                        <th>IP</th>
                                        <th>Aparelho</th>
                                        <th>Data da conexão</th>
                                        <th>Tempo conectado</th>
                                        <th>Consumo</th>
                                        <th>Banda</th>
                                        <th>Tempo limite</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($leads as $l):
                                        $st      = lead_estado($l, $agora);
                                        $online  = $st['online'];
                                        $elapsed = $st['elapsed'];
                                        $lim     = $l['tempo_limite_min'];
                                        $banda   = $l['banda_limite'];
                                        $dh      = explode(' - ', fmt_data($l['conectado_em']));
                                    ?>
                                    <tr data-online="<?= $online ?>" data-elapsed="<?= $elapsed ?>">
                                        <td><?= h($l['nome'] !== null ? $l['nome'] : $l['telefone']) ?></td>
                                        <td><?= h($l['ip']) ?></td>
                                        <td class="pc-aparelho"><?= h($l['dispositivo']) ?></td>
                                        <td>
                                            <div class="pc-conex-cel">
                                                <span class="pc-ver-conexoes">
                                                    <svg class="pc-conex-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
                                                    <span class="pc-total"><?= (int) $l['total_conexoes'] ?></span>
                                                </span>
                                                <div class="pc-dh"><span class="pc-data"><?= h($dh[0]) ?></span><span class="pc-hora"><?= h($dh[1] ?? '') ?></span></div>
                                            </div>
                                        </td>
                                        <td><span class="pc-dot"></span><span class="pc-tempo"><?= h(fmt_tempo($elapsed)) ?></span></td>
                                        <td class="pc-uso"><?= h(fmt_bytes($l['bytes_total'])) ?></td>
                                        <td class="pc-banda"><?= $banda === null ? 'sem limite' : (int) $banda . ' Mbps' ?></td>
                                        <td class="pc-limite"><?= $lim === null ? 'sem limite' : (int) $lim . ' min' ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <?php foreach ($abasVitrine as $ak => [$al, $ad]): ?>
            <section class="pc-tela" data-tela="<?= h($ak) ?>">
                <div class="glow-card pc-dst-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body demo-vitrine">
                        <h2 class="pc-anuncio-title"><?= h($al) ?></h2>
                        <p class="pc-anuncio-desc"><?= h($ad) ?></p>
                        <p class="pc-anuncio-desc">Esta aba funciona no painel de verdade, com os dados da sua loja.</p>
                        <a class="pc-btn-primary" href="https://wa.me/5548988290878" target="_blank" rel="noopener">Quero o meu painel</a>
                    </div>
                </div>
            </section>
            <?php endforeach; ?>
        </div>
    </div>

    <script src="assets/abas.js?v=4"></script>
    <script>
    // O cronômetro de quem está online anda, como no painel real. É o detalhe
    // que faz a tela parecer viva em vez de uma captura de tela.
    (function () {
        var linhas = document.querySelectorAll('tr[data-online="1"]');
        if (!linhas.length) return;
        function dois(n) { return (n < 10 ? '0' : '') + n; }
        setInterval(function () {
            for (var i = 0; i < linhas.length; i++) {
                var s = parseInt(linhas[i].getAttribute('data-elapsed'), 10) + 1;
                linhas[i].setAttribute('data-elapsed', s);
                var el = linhas[i].querySelector('.pc-tempo');
                if (el) {
                    el.textContent = dois(Math.floor(s / 3600)) + ':' +
                                     dois(Math.floor((s % 3600) / 60)) + ':' + dois(s % 60);
                }
            }
        }, 1000);
    })();
    </script>
    <?php require __DIR__ . '/inc/tema.php'; ?>
</body>
</html>
