<?php
// Painel de DEMONSTRAÇÃO da landing page. Nada aqui é real.
//
// Por que é um arquivo separado, e não o painel.php com uma flag "demo":
// o painel.php é a tela autenticada. Ensinar ele a renderizar sem sessão seria
// criar um caminho que desvia do login — e um dia alguém erra a condição. Aqui
// não há sessão, não há banco e não há chamada de API que saia da página.
//
// COMO AS ABAS FUNCIONAM DE VERDADE: em vez de reescrever cada tela, esta
// página carrega os MESMOS scripts do painel real (dashgeral.js, estatisticas.js,
// relatorio.js, alertas.js, dashboard.js, leads-live.js) e troca o window.fetch
// por um que responde com os dados inventados, no formato exato de cada
// endpoint. O resultado é renderizado pelo código de produção — a tela sai
// idêntica e continua idêntica quando o painel mudar.
//
// Os dados saem todos do inc/demo_dados.php, de um histórico de visitas por
// lead. Por isso os números batem entre as abas.
require_once __DIR__ . '/inc/util.php';
require_once __DIR__ . '/inc/demo_dados.php';

$agora  = time();
$leads  = demo_leads($agora);
$resumo = demo_resumo($leads, $agora);

// --- Variáveis que as telas copiadas do painel.php esperam ---------------
$rotAtivo    = 'HOTSPOT-DEMO';
$multi       = false;
$csrf        = 'demo';
$temAnuncio  = false;
$temLogo     = false;
$logoForma   = 'quadrado';
$dstAtual    = 'https://www.instagram.com/sualoja';
$tlPadrao    = 120;
$bandaPadrao = 20;
// O inc/alertas_tela.php lê isto para marcar as caixas de "selecionar
// urgências". No painel real vem do banco (alertas_get); aqui, tudo marcado.
$alertasMarcados = array_keys(alertas_catalogo());
$ig          = ig_padrao();
$ig['perfil'] = 'sualoja';
$ig['rodape'] = 'CAFETERIA MODELO';

// --- Payloads que o window.fetch falso devolve ---------------------------
$semana = strtotime('monday this week', $agora);
$dia    = strtotime('today', $agora);
$mes    = strtotime(date('Y-m-01', $agora));

// Leads no formato do api/leads_online.php (o leads-live.js relê a tabela).
$leadsJs = [];
foreach ($leads as $l) {
    $st = lead_estado($l, $agora);
    $leadsJs[] = [
        'id' => $l['id'], 'telefone' => $l['telefone'], 'nome' => $l['nome'],
        'ip' => $l['ip'], 'dispositivo' => $l['dispositivo'],
        'conectado_em' => $l['conectado_em'], 'online' => $st['online'],
        'total_conexoes' => $l['total_conexoes'], 'segundos_conectado' => $st['seg'],
        'tempo_limite_min' => $l['tempo_limite_min'], 'banda_limite' => $l['banda_limite'],
        'elapsed' => $st['elapsed'], 'bytes_total' => $l['bytes_total'],
    ];
}

// Relatórios: período dos últimos 7 dias, que é o que o formulário já vem
// preenchido. Com 7 dias cada dia da semana acontece uma vez só, então
// 'ocorrencias' é 1 em todos — é assim que o relatorio.js espera.
$pIni = $agora - 6 * 86400;
$bSemana = array_fill(1, 7, 0);      // 1 = domingo (mesma convenção do DAYOFWEEK)
$bHora   = array_fill(0, 24, 0);
$porCliente = [];
foreach ($leads as $l) {
    $dias = []; $seg = 0;
    foreach ($l['visitas'] as $v) {
        if ($v['ts'] < $pIni) { continue; }
        $bSemana[(int) date('w', $v['ts']) + 1]++;
        $bHora[$v['hora']]++;
        $dias[$v['data']] = true;
        $seg += $v['seg'];
    }
    if ($dias) {
        $porCliente[] = ['telefone' => $l['telefone'], 'nome' => $l['nome'],
                         'dias' => count($dias), 'seg' => $seg];
    }
}
$lista = function (string $campo) use ($porCliente) {
    $out = [];
    foreach ($porCliente as $c) {
        $out[] = ['telefone' => $c['telefone'], 'nome' => $c['nome'], 'valor' => $c[$campo]];
    }
    usort($out, function ($a, $b) { return $b['valor'] <=> $a['valor']; });
    return $out;
};
$periodo = ['inicio' => date('Y-m-d', $pIni), 'fim' => date('Y-m-d', $agora)];

// Informações: a grade de contatos e o painel de um contato.
$infoLista = [];
$infoPorTel = [];
foreach ($leads as $l) {
    $infoLista[] = ['telefone' => $l['telefone'], 'nome' => $l['nome'],
                    'ultima' => date('Y-m-d H:i:s', end($l['visitas'])['ts'])];
    $infoPorTel[$l['telefone']] = demo_info($l, $agora);
}
usort($infoLista, function ($a, $b) { return strcmp($b['ultima'], $a['ultima']); });

// Conexões de cada lead (o pop-up da bolinha na coluna da data).
$conexoes = [];
foreach ($leads as $l) {
    $itens = [];
    foreach (array_reverse($l['visitas']) as $v) {
        $itens[] = ['conectado_em' => date('Y-m-d H:i:s', $v['ts']),
                    'segundos' => $v['aberta'] ? $agora - $v['ts'] : $v['seg'],
                    'bytes' => $v['bytes'], 'dispositivo' => $l['dispositivo']];
    }
    $conexoes[(string) $l['id']] = ['ok' => true, 'telefone' => $l['telefone'],
                                    'conexoes' => $itens, 'pagina' => 1, 'paginas' => 1];
}

$D = [
    'now'    => date('Y-m-d H:i:s', $agora),
    'leads'  => $leadsJs,
    'resumo' => $resumo,
    'dg'     => [
        'ok'     => true,
        'dia'    => demo_periodo($leads, $dia,    $dia - 86400,          $dia),
        'semana' => demo_periodo($leads, $semana, $semana - 7 * 86400,   $semana),
        'mes'    => demo_periodo($leads, $mes,    strtotime('-1 month', $mes), $mes),
    ],
    'est'      => demo_estatisticas($leads, $agora),
    'avisos'   => demo_avisos($leads, $agora),
    'alerta'   => (function () use ($leads, $agora) {
        $o = [];
        foreach (['sem_vir_semana', 'visita_unica', 'novos_semana', 'forte_recorrencia'] as $id) {
            $l = demo_alerta_lista($leads, $id, $agora);
            $cat = alertas_catalogo();
            $o[$id] = ['ok' => true, 'titulo' => $cat[$id][0],
                       'total' => count($l), 'lista' => array_slice($l, 0, 30)];
        }
        return $o;
    })(),
    'info'     => ['lista' => $infoLista, 'por_tel' => $infoPorTel],
    'conexoes' => $conexoes,
    'rel'      => [
        'semana' => ['ok' => true, 'tipo' => 'semana', 'total' => array_sum($bSemana),
                     'buckets' => $bSemana, 'ocorrencias' => array_fill(1, 7, 1)] + $periodo,
        'hora'   => ['ok' => true, 'tipo' => 'hora', 'total' => array_sum($bHora),
                     'buckets' => $bHora, 'ocorrencias' => array_fill(0, 24, 1)] + $periodo,
        'clientes_dias'  => ['ok' => true, 'tipo' => 'clientes_dias',
                             'total' => count($porCliente), 'lista' => $lista('dias')] + $periodo,
        'clientes_tempo' => ['ok' => true, 'tipo' => 'clientes_tempo',
                             'total' => count($porCliente), 'lista' => $lista('seg')] + $periodo,
    ],
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
    <link rel="stylesheet" href="assets/style.css?v=134">
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
                <button type="button" class="pc-side-item" data-aba="dashboard">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                    Dashboard
                </button>
                <button type="button" class="pc-side-item" data-aba="alertas">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/></svg>
                    Alertas
                </button>
                <button type="button" class="pc-side-item" data-aba="informacoes">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    Informações
                </button>
                <button type="button" class="pc-side-item" data-aba="relatorios">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>
                    Relatórios
                </button>
                <button type="button" class="pc-side-item" data-aba="anuncio">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18.37 2.63 14 7l-1.59-1.59a2 2 0 0 0-2.82 0L8 7l9 9 1.59-1.59a2 2 0 0 0 0-2.82L17 10l4.37-4.37a2.12 2.12 0 1 0-3-3Z"/><path d="M9 8c-2 3-4 3.5-7 4l8 10c2-1 6-5 6-7"/><path d="M14.5 17.5 4.5 15"/></svg>
                    Personalizar
                </button>
                <button type="button" class="pc-side-item" data-aba="url">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    Url do site
                </button>
                <button type="button" class="pc-side-item" data-aba="limites">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/></svg>
                    Limites
                </button>
                <button type="button" class="pc-side-item" data-aba="estatisticas">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                    Estatísticas
                </button>
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

            <div id="mikrotik-status" class="mk-status mk-on" data-endpoint="api/mikrotik_status.php?roteador=DEMO">
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

            <!-- ============ ABA: PAINEL ============ -->
            <section class="pc-tela atual" data-tela="painel">
                <div class="pc-summary">
                    <?php foreach ([
                        ['online', 'metric-online', 'online agora'],
                        ['hoje', 'metric-hoje', 'conectados hoje'],
                        ['cadastrados', 'metric-cadastrados', 'cadastrados hoje'],
                        ['total', 'metric-total', 'total de leads'],
                    ] as [$mk, $mid, $ml]): ?>
                    <span class="glow-card pc-metric<?= $mk === 'total' ? ' atual' : '' ?>">
                        <span class="glow-fx" aria-hidden="true"></span>
                        <div class="glow-body">
                            <span class="pc-metric-num" id="<?= $mid ?>"><?= $resumo[$mk] ?></span>
                            <span class="pc-metric-label"><?= $ml ?></span>
                        </div>
                    </span>
                    <?php endforeach; ?>
                </div>

                <div class="glow-card pc-table-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <?php /* Os mesmos data-* do painel real: é o leads-live.js
                                 de produção que redesenha esta tabela, lendo o
                                 fetch falso. */ ?>
                        <div class="pc-table-wrap" id="leads-live"
                             data-endpoint="api/leads_online.php?roteador=DEMO"
                             data-limite-endpoint="api/set_limite.php"
                             data-banda-endpoint="api/set_banda.php"
                             data-conexoes-endpoint="api/conexoes.php"
                             data-editar-endpoint="api/lead_editar.php"
                             data-excluir-endpoint="api/lead_excluir.php"
                             data-esquecer-endpoint="api/lead_esquecer.php"
                             data-pagina="1" data-por-pagina="50" data-csrf="demo">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Número</th><th>IP</th><th>Aparelho</th>
                                        <th>Data da conexão</th><th>Tempo conectado</th><th>Consumo</th>
                                        <th>Banda <span class="pc-th-hint">(clique p/ editar)</span></th>
                                        <th>Tempo limite <span class="pc-th-hint">(clique p/ editar)</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($leads as $l):
                                        $st = lead_estado($l, $agora);
                                        $dh = explode(' - ', fmt_data($l['conectado_em']));
                                    ?>
                                    <tr data-id="<?= $l['id'] ?>" data-online="<?= $st['online'] ?>" data-elapsed="<?= $st['elapsed'] ?>"
                                        data-limite="<?= $l['tempo_limite_min'] === null ? '' : (int) $l['tempo_limite_min'] ?>"
                                        data-banda="<?= $l['banda_limite'] === null ? '' : (int) $l['banda_limite'] ?>"
                                        data-total="<?= (int) $l['total_conexoes'] ?>" data-tel="<?= h($l['telefone']) ?>"
                                        data-nome="<?= h((string) $l['nome']) ?>">
                                        <td><?= h($l['nome'] !== null ? $l['nome'] : $l['telefone']) ?></td>
                                        <td><?= h($l['ip']) ?></td>
                                        <td class="pc-aparelho"><?= h($l['dispositivo']) ?></td>
                                        <td>
                                            <div class="pc-conex-cel">
                                                <button type="button" class="pc-ver-conexoes" data-lead="<?= $l['id'] ?>" aria-label="Ver conexões">
                                                    <svg class="pc-conex-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
                                                    <span class="pc-total"><?= (int) $l['total_conexoes'] ?></span>
                                                </button>
                                                <div class="pc-dh"><span class="pc-data"><?= h($dh[0]) ?></span><span class="pc-hora"><?= h($dh[1] ?? '') ?></span></div>
                                            </div>
                                        </td>
                                        <td><span class="pc-dot"></span><span class="pc-tempo"><?= h(fmt_tempo($st['elapsed'])) ?></span></td>
                                        <td class="pc-uso"><?= h(fmt_bytes($l['bytes_total'])) ?></td>
                                        <td class="pc-banda"><?= $l['banda_limite'] === null ? 'sem limite' : (int) $l['banda_limite'] . ' Mbps' ?></td>
                                        <td class="pc-limite"><?= $l['tempo_limite_min'] === null ? 'sem limite' : (int) $l['tempo_limite_min'] . ' min' ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============ ABA: DASHBOARD ============ -->
            <section class="pc-tela" data-tela="dashboard">
                <div class="glow-card pc-dst-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <div class="pc-dst" id="dashgeral-box" data-endpoint="api/dashboard_geral.php?roteador=DEMO">
                            <h2 class="pc-anuncio-title">Recorrência da semana</h2>
                            <p class="pc-anuncio-desc">Todos os leads: quem revisitou o estabelecimento nesta semana, quem ainda não voltou e quem apareceu pela primeira vez.</p>
                            <div id="dg-conteudo"></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============ ABA: ALERTAS ============ -->
            <?php $alertasEp = 'api/alertas.php?roteador=DEMO'; require __DIR__ . '/inc/alertas_tela.php'; ?>

            <!-- ============ ABA: INFORMAÇÕES ============ -->
            <section class="pc-tela" data-tela="informacoes">
                <div class="glow-card pc-dst-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <?php $infoEndpoint = 'api/dashboard.php?roteador=DEMO'; require __DIR__ . '/inc/info_tela.php'; ?>
                    </div>
                </div>
            </section>

            <!-- ============ ABA: RELATÓRIOS ============ -->
            <section class="pc-tela" data-tela="relatorios">
                <div class="glow-card pc-dst-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <div class="pc-dst" id="relatorio-box" data-endpoint="api/relatorio.php?roteador=DEMO">
                            <h2 class="pc-anuncio-title">Relatórios</h2>
                            <p class="pc-anuncio-desc">Escolha o modelo, o período e clique em gerar. Na demonstração há dados para os quatro primeiros modelos; os outros usam o histórico completo da loja.</p>
                            <div class="rel-controles">
                                <details class="rt-sel" id="rel-sel">
                                    <summary>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M7 16v-5"/><path d="M12 16V8"/><path d="M17 16v-8"/></svg>
                                        <span id="rel-sel-label">Escolher modelo</span>
                                        <svg class="rt-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                                    </summary>
                                    <div class="rt-menu">
                                        <div class="rt-menu-title">Modelo do relatório</div>
                                        <button type="button" class="rt-item rel-item" data-tipo="semana"><span>Acessos por dia da semana</span></button>
                                        <button type="button" class="rt-item rel-item" data-tipo="hora"><span>Acessos por horário</span></button>
                                        <button type="button" class="rt-item rel-item" data-tipo="clientes_dias"><span>Dias de visita por cliente</span></button>
                                        <button type="button" class="rt-item rel-item" data-tipo="clientes_tempo"><span>Tempo de conexão por cliente</span></button>
                                        <button type="button" class="rt-item rel-item" data-tipo="grade_semana"><span>Tempo por cliente na semana</span></button>
                                        <button type="button" class="rt-item rel-item" data-tipo="sumidos"><span>Clientes sem retorno</span></button>
                                        <button type="button" class="rt-item rel-item" data-tipo="ranking"><span>Clientes mais frequentes</span></button>
                                        <button type="button" class="rt-item rel-item" data-tipo="mapa"><span>Movimento por dia e hora</span></button>
                                        <button type="button" class="rt-item rel-item" data-tipo="aniversario"><span>Marcos de relacionamento</span></button>
                                        <button type="button" class="rt-item rel-item" data-tipo="intervalo"><span>Intervalo entre visitas</span></button>
                                    </div>
                                </details>
                                <input type="text" id="rel-inicio" class="pc-dst-input rel-data" readonly inputmode="none" placeholder="dd/mm/aaaa"
                                       value="<?= date('d/m/Y', $pIni) ?>" data-iso="<?= date('Y-m-d', $pIni) ?>" aria-label="Data inicial">
                                <input type="text" id="rel-fim" class="pc-dst-input rel-data" readonly inputmode="none" placeholder="dd/mm/aaaa"
                                       value="<?= date('d/m/Y', $agora) ?>" data-iso="<?= date('Y-m-d', $agora) ?>" aria-label="Data final">
                                <label class="rel-extra" data-campo="dias" style="display:none">sem vir há (dias)
                                    <input type="number" id="rel-dias" class="pc-dst-input rel-num" min="1" value="7">
                                </label>
                                <label class="rel-extra" data-campo="visitas" style="display:none">mínimo de visitas
                                    <input type="number" id="rel-visitas" class="pc-dst-input rel-num" min="1" value="3">
                                </label>
                                <label class="rel-extra" data-campo="proximos" style="display:none">nos próximos (dias)
                                    <input type="number" id="rel-proximos" class="pc-dst-input rel-num" min="1" value="30">
                                </label>
                                <button type="button" class="pc-btn-primary" id="rel-gerar">Gerar relatório</button>
                            </div>
                            <p class="pc-anuncio-msg err" id="rel-erro" style="display:none"></p>
                            <div class="rel-grafico" id="rel-grafico"></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============ ABA: PERSONALIZAR ============ -->
            <section class="pc-tela" data-tela="anuncio">
                <div class="glow-card pc-anuncio-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <div class="pc-anuncio">
                            <div class="pc-anuncio-preview">
                                <div class="pc-anuncio-vazio">Sem anúncio<br>enviado</div>
                            </div>
                            <div class="pc-anuncio-form">
                                <h2 class="pc-anuncio-title">Anúncio do captive portal</h2>
                                <p class="pc-anuncio-desc">Imagem mostrada nos 10 segundos antes de liberar o Wi-Fi. Envie JPG, JPEG ou PNG (até 3 MB). Substitui a atual na hora — vale para as próximas conexões.</p>
                                <form class="pc-anuncio-envio demo-form">
                                    <label class="pc-file">
                                        <input type="file" accept=".jpg,.jpeg,.png">
                                        <span class="pc-file-label">Escolher imagem…</span>
                                    </label>
                                    <button type="submit" class="pc-btn-primary">Enviar anúncio</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="glow-card pc-anuncio-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <div class="pc-anuncio">
                            <div class="pc-anuncio-preview">
                                <div class="pc-anuncio-vazio">Sem logo<br>enviada</div>
                            </div>
                            <div class="pc-anuncio-form">
                                <h2 class="pc-anuncio-title">Logo da tela de login</h2>
                                <p class="pc-anuncio-desc">Aparece no topo da tela onde o cliente digita o número. Envie PNG (com fundo transparente, recomendado), JPG ou JPEG (até 2 MB). Troca na hora.</p>
                                <form class="pc-anuncio-envio demo-form">
                                    <label class="pc-file">
                                        <input type="file" accept=".jpg,.jpeg,.png">
                                        <span class="pc-file-label">Escolher imagem…</span>
                                    </label>
                                    <button type="submit" class="pc-btn-primary">Enviar logo</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============ ABA: URL DO SITE ============ -->
            <section class="pc-tela" data-tela="url">
                <div class="glow-card pc-dst-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <div class="pc-dst">
                            <h2 class="pc-anuncio-title">Site de destino após o anúncio</h2>
                            <p class="pc-anuncio-desc">Quando o cliente termina o anúncio, ele é redirecionado para este site. Pode colar o link do seu perfil do Instagram — convertemos automaticamente para o formato que abre a página do perfil sem erro no iPhone.</p>
                            <p class="pc-dst-atual">Atual: <strong><?= h($dstAtual) ?></strong></p>
                            <form class="pc-dst-form demo-form">
                                <input type="text" inputmode="url" class="pc-dst-input" placeholder="https://seusite.com.br" value="<?= h($dstAtual) ?>">
                                <button type="submit" class="pc-btn-primary">Adicionar</button>
                            </form>
                            <div class="pc-dst-ig">
                                <button type="button" class="pc-btn-ig" id="ig-abrir">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/></svg>
                                    URL de Instagram
                                </button>
                                <span class="pc-dst-ig-dica">Monte uma página com a sua cara para receber quem sai do anúncio e mandar para o seu perfil.</span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php require __DIR__ . '/inc/ig_modal.php'; ?>
            </section>

            <!-- ============ ABA: LIMITES ============ -->
            <section class="pc-tela" data-tela="limites">
                <div class="glow-card pc-dst-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <div class="pc-dst">
                            <h2 class="pc-anuncio-title">Aplicar limites a todos os usuários</h2>
                            <p class="pc-anuncio-desc">Define o limite para quem já está na tabela e para os próximos que conectarem. Deixe vazio para "sem limite". Para mudar um usuário específico, use a própria tabela na aba Painel (clique no valor).</p>
                            <form class="pc-dst-form demo-form">
                                <input type="number" min="0" inputmode="numeric" class="pc-dst-input"
                                       placeholder="Tempo limite (min) — vazio = sem limite" value="<?= (int) $tlPadrao ?>">
                                <button type="submit" class="pc-btn-primary">Aplicar tempo</button>
                            </form>
                            <form class="pc-dst-form demo-form">
                                <input type="number" min="0" inputmode="numeric" class="pc-dst-input"
                                       placeholder="Banda máx. (Mbps) — vazio = ilimitado" value="<?= (int) $bandaPadrao ?>">
                                <button type="submit" class="pc-btn-primary">Aplicar banda</button>
                            </form>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============ ABA: ESTATÍSTICAS ============ -->
            <section class="pc-tela" data-tela="estatisticas">
                <div class="glow-card pc-dst-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <?php /* Estrutura idêntica à do painel.php: o
                                 assets/estatisticas.js procura estes ids exatos
                                 (est-wrap, est-tooltip, est-legenda) — inventar
                                 um "est-conteudo" deixava a aba vazia. */ ?>
                        <div class="pc-dst" id="estatisticas-box" data-endpoint="api/estatisticas.php?roteador=DEMO">
                            <h2 class="pc-anuncio-title">Estatísticas</h2>
                            <p class="pc-anuncio-desc">Duas curvas no mesmo período: em laranja o total de conexões, em azul quantas eram de clientes novos. Passe o mouse para ver os números de cada ponto e o quanto mudou em relação ao anterior.</p>
                            <div class="est-barra">
                                <div class="est-filtros">
                                    <button type="button" class="est-filtro atual" data-filtro="hoje">Hoje</button>
                                    <button type="button" class="est-filtro" data-filtro="semana">Semana</button>
                                    <button type="button" class="est-filtro" data-filtro="mes">Mês</button>
                                    <button type="button" class="est-filtro" data-filtro="ano">Ano</button>
                                </div>
                            </div>
                            <div class="est-wrap" id="est-wrap"></div>
                            <div class="est-tooltip" id="est-tooltip"></div>
                            <div class="est-legenda" id="est-legenda"></div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <?php /* Os pop-ups moram FORA das abas, como no painel.php: o de editar
             lead, o de conexões de um número e o das listas de alerta. Sem
             eles, clicar no número de um aviso ou na bolinha de conexões
             encontrava um elemento inexistente e o JS parava calado. */ ?>
    <div class="pc-modal" id="editar-modal" aria-hidden="true">
        <div class="pc-modal-backdrop" data-close></div>
        <div class="pc-modal-card glow-card">
            <span class="glow-fx" aria-hidden="true"></span>
            <div class="glow-body">
                <div class="pc-modal-head">
                    <h3 class="pc-modal-title">Editar lead</h3>
                    <button type="button" class="pc-modal-x" data-close aria-label="Fechar">&times;</button>
                </div>
                <div class="pc-modal-body pc-editar-body">
                    <label class="pc-ed-label">Nome (identificação — opcional)
                        <input type="text" id="ed-nome" class="pc-dst-input" maxlength="60" placeholder="ex.: João da padaria">
                    </label>
                    <label class="pc-ed-label">Número
                        <input type="tel" id="ed-tel" class="pc-dst-input" inputmode="numeric" placeholder="48999999999">
                    </label>
                    <div class="pc-ed-row">
                        <label class="pc-ed-label">Tempo limite (min)
                            <input type="number" id="ed-limite" class="pc-dst-input" min="0" inputmode="numeric" placeholder="sem limite">
                        </label>
                        <label class="pc-ed-label">Banda (Mbps)
                            <input type="number" id="ed-banda" class="pc-dst-input" min="0" inputmode="numeric" placeholder="sem limite">
                        </label>
                    </div>
                    <p class="pc-anuncio-msg err" id="ed-erro" style="display:none"></p>
                    <button type="button" class="pc-btn-primary" id="ed-salvar">Salvar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="pc-modal" id="conexoes-modal" aria-hidden="true">
        <div class="pc-modal-backdrop" data-close></div>
        <div class="pc-modal-card glow-card">
            <span class="glow-fx" aria-hidden="true"></span>
            <div class="glow-body">
                <div class="pc-modal-head">
                    <h3 class="pc-modal-title">Conexões de <span id="conexoes-tel"></span></h3>
                    <button type="button" class="pc-modal-x" data-close aria-label="Fechar">&times;</button>
                </div>
                <div class="pc-modal-body" id="conexoes-lista"></div>
                <div id="conexoes-nav"></div>
            </div>
        </div>
    </div>

    <?php require __DIR__ . '/inc/alertas_modal.php'; ?>

<script>
/* ============================================================
   O window.fetch falso.

   Precisa rodar ANTES dos scripts do painel: vários buscam os dados assim que
   carregam. Daí este bloco ficar aqui, e não no fim da página.

   Cada rota devolve exatamente o formato do endpoint que ela imita — é o
   código de produção que renderiza tudo, então qualquer campo faltando
   apareceria como tela quebrada, não como tela diferente.
   ============================================================ */
window.CD_DEMO = <?= json_encode($D, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
(function () {
    var D = window.CD_DEMO;
    var original = window.fetch;

    function json(o) {
        return Promise.resolve(new Response(JSON.stringify(o), {
            status: 200, headers: { 'Content-Type': 'application/json' }
        }));
    }
    function param(u, k) {
        var m = new RegExp('[?&]' + k + '=([^&]*)').exec(u);
        return m ? decodeURIComponent(m[1]) : null;
    }

    window.fetch = function (url, opt) {
        var u = String(url && url.url ? url.url : url);
        var metodo = ((opt && opt.method) || 'GET').toUpperCase();

        // Nada é salvo aqui. Qualquer POST (mudar banda, editar lead, escolher
        // urgências) responde com um "não dá" em vez de sumir em silêncio.
        if (metodo === 'POST') {
            return json({ ok: false, erro: 'Isto é uma demonstração — nada é salvo. No painel real esta ação funciona.' });
        }

        if (u.indexOf('mikrotik_status') >= 0) { return json({ ok: true, online: true }); }
        if (u.indexOf('leads_online') >= 0) {
            return json({ ok: true, now: D.now, leads: D.leads, resumo: D.resumo, mikrotik: true });
        }
        if (u.indexOf('dashboard_geral') >= 0) { return json(D.dg); }
        if (u.indexOf('estatisticas') >= 0) {
            // Uma série por filtro da barra (hoje / semana / mês / ano): o
            // estatisticas.js escreve na legenda o que cada ponto significa,
            // e devolver sempre a mesma série faria a legenda mentir.
            return json(D.est[param(u, 'filtro') || 'hoje'] || D.est.hoje);
        }
        if (u.indexOf('conexoes.php') >= 0) {
            // O parâmetro é "lead_id" — com "lead" o pop-up abria dizendo
            // "lead nao encontrado".
            var id = param(u, 'lead_id');
            return json(D.conexoes[id] || { ok: false, erro: 'lead nao encontrado' });
        }
        if (u.indexOf('alertas.php') >= 0) {
            // O parâmetro é "detalhe", não "aviso" — com o nome errado a lista
            // ficava presa em "Buscando os clientes…".
            var det = param(u, 'detalhe');
            if (det) { return json(D.alerta[det] || { ok: true, titulo: '', total: 0, lista: [] }); }
            return json({ ok: true, avisos: D.avisos, escolhidos: true });
        }
        // dashboard.php serve dois pedidos: a grade de contatos (f=lista) e os
        // hábitos de um contato (telefone=).
        if (u.indexOf('dashboard.php') >= 0) {
            if (param(u, 'f') === 'lista') {
                var q = (param(u, 'q') || '').toLowerCase();
                var lista = D.info.lista.filter(function (x) {
                    return !q || x.telefone.indexOf(q) >= 0 ||
                           (x.nome || '').toLowerCase().indexOf(q) >= 0;
                });
                return json({ ok: true, leads: lista, pagina: 1, paginas: 1, total: lista.length });
            }
            var tel = (param(u, 'telefone') || '').replace(/\D/g, '');
            var d = D.info.por_tel[tel];
            return d ? json(d) : json({ ok: false, erro: 'Nenhum lead com esse número.' });
        }
        if (u.indexOf('relatorio.php') >= 0) {
            var tipo = param(u, 'tipo');
            if (D.rel[tipo]) { return json(D.rel[tipo]); }
            return json({ ok: false, erro: 'Este modelo não entra na demonstração — no painel real ele lê o histórico completo da sua loja.' });
        }

        return original.apply(this, arguments);
    };

    // Formulários da demonstração não enviam nada.
    document.addEventListener('submit', function (e) {
        if (e.target.classList.contains('demo-form')) {
            e.preventDefault();
            alert('Isto é uma demonstração — nada é salvo. No painel real esta ação funciona.');
        }
    });
})();
</script>

    <script src="assets/abas.js?v=4"></script>
    <script src="assets/ig.js?v=3"></script>
    <script src="assets/relatorio.js?v=17"></script>
    <script src="assets/alertas.js?v=3"></script>
    <script src="assets/dashboard.js?v=11"></script>
    <script src="assets/dashgeral.js?v=14"></script>
    <script src="assets/estatisticas.js?v=8"></script>
    <script src="assets/leads-live.js?v=33"></script>
    <?php require __DIR__ . '/inc/tema.php'; ?>
</body>
</html>
