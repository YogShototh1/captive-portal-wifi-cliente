<?php
// Admin vê os leads de um cliente específico.
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/util.php';

$admin = exigir_admin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$q = db()->prepare('SELECT nome, email FROM compradores WHERE id = ?');
$q->execute([$id]);
$cliente = $q->fetch();
if (!$cliente) {
    header('Location: admin.php');
    exit;
}

// Roteadores do cliente (multi-MikroTik): ?r= escolhe um (validado contra a
// lista do cliente); vazio numa conta multi = todos juntos.
$rotLista = roteadores_conta($id);
$rotAtivo = roteador_escolhido($rotLista, $_GET['r'] ?? null); // null = todos
$rotQuery = $rotAtivo !== null ? [$rotAtivo] : $rotLista;
$multi    = count($rotLista) > 1;

// Contadores dos 4 cartões de resumo (online / conectados hoje / cadastrados hoje / total).
$resumo = resumo_leads($rotQuery);

// Filtro dos cartões (?f=): a tabela mostra só o grupo do cartão clicado.
// A paginação usa o contador do grupo filtrado (mesmos critérios do cartão).
$filtro    = filtro_leads($_GET['f'] ?? '');
$totalBase = $resumo[$filtro === '' ? 'total' : $filtro];

// Tabela paginada: 50 leads mais recentes por página (?pagina=N).
$POR_PAG      = 50;
$totalPaginas = max(1, (int) ceil($totalBase / $POR_PAG));
$pagina       = min($totalPaginas, max(1, (int) ($_GET['pagina'] ?? 1)));
$leads = [];
if ($rotQuery) {
    $ph = implode(',', array_fill(0, count($rotQuery), '?'));
    $ql = db()->prepare(
        "SELECT id, telefone, nome, ip, dispositivo, conectado_em, online, segundos_conectado, visto_em, tempo_limite_min, banda_limite, total_conexoes,
                (SELECT COALESCE(SUM(c.bytes), 0) FROM conexoes c WHERE c.lead_id = leads.id) AS bytes_total
           FROM leads WHERE roteador IN ($ph)" . filtro_leads_sql($filtro) . '
          ORDER BY conectado_em DESC
          LIMIT ' . $POR_PAG . ' OFFSET ' . (($pagina - 1) * $POR_PAG)
    );
    $ql->execute($rotQuery);
    $leads = $ql->fetchAll();
}

$dbNow = db_now();
$nowTs = strtotime($dbNow);
$csrf  = csrf_token();

// Mensagens de retorno do upload de anúncio (via query string).
$anuncioOk   = isset($_GET['anuncio_ok'])   ? (string) $_GET['anuncio_ok']   : '';
$anuncioErro = isset($_GET['anuncio_erro']) ? (string) $_GET['anuncio_erro'] : '';
$temAnuncio  = $rotAtivo !== null && anuncio_atual($rotAtivo) !== null;

// Site de destino pós-anúncio do cliente aberto.
$dstOk    = isset($_GET['dst_ok'])   ? (string) $_GET['dst_ok']   : '';
$dstErro  = isset($_GET['dst_erro']) ? (string) $_GET['dst_erro'] : '';
$dstAtual = $rotAtivo !== null ? dst_atual($rotAtivo) : null;

// Status do MikroTik deste cliente. O JS reatualiza a cada poll de leads_online.php.
$mkOnline = mikrotiks_online($rotQuery);

// Limites-padrão do roteador (aplicados aos novos usuários) + mensagens de retorno.
$tlPadrao    = $rotAtivo !== null ? roteador_cfg_get($rotAtivo, 'tlimit') : null;
$bandaPadrao = $rotAtivo !== null ? roteador_cfg_get($rotAtivo, 'banda') : null;
$tlimOk   = isset($_GET['tlim_ok'])   ? (string) $_GET['tlim_ok']   : '';
$tlimErro = isset($_GET['tlim_erro']) ? (string) $_GET['tlim_erro'] : '';
$bandaOk   = isset($_GET['banda_ok'])   ? (string) $_GET['banda_ok']   : '';
$bandaErro = isset($_GET['banda_erro']) ? (string) $_GET['banda_erro'] : '';

// Página de login do hotspot (o admin sempre pode editar).
$portalOk    = isset($_GET['portal_ok'])   ? (string) $_GET['portal_ok']   : '';
$portalErro  = isset($_GET['portal_erro']) ? (string) $_GET['portal_erro'] : '';
$portalFiles = $rotAtivo !== null ? portal_files($rotAtivo) : [];

// Aviso mostrado nas abas de configuração quando a conta multi está em "todos".
$avisoRoteador = '<section class="glow-card pc-dst-card"><span class="glow-fx" aria-hidden="true"></span><div class="glow-body"><div class="pc-dst">'
    . '<h2 class="pc-anuncio-title">Selecione um MikroTik</h2>'
    . '<p class="pc-anuncio-desc">Esta configuração é feita por roteador. Escolha um MikroTik no seletor do topo da página para editar.</p>'
    . '</div></div></section>';
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <script>/* Aberto fora da casca? Manda para /painel (a URL fica sempre em /painel). */ if (top === self) location.replace('/painel');</script>
    <script>(function(){try{var t=localStorage.getItem('cd-tema');document.documentElement.setAttribute('data-tema',t==='escuro'?'escuro':'claro');}catch(e){document.documentElement.setAttribute('data-tema','claro');}})();</script>
    <meta name="format-detection" content="telephone=no">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leads — <?= h($cliente['nome'] ?: $cliente['email']) ?></title>
    <link rel="stylesheet" href="assets/style.css?v=48">
</head>
<body class="painel-cliente">
    <!-- Camadas de fundo (decorativas) -->
    <div class="pc-bg-gradient"></div>
    <div class="pc-bg-noise"></div>
    <div class="pc-glow pc-glow-top"></div>
    <div class="pc-glow pc-glow-bottom"></div>

    <div class="pc-layout">
        <aside class="pc-sidebar" id="pc-sidebar">
            <div class="pc-side-brand">
                <img src="assets/logo.png?v=3" alt="">
                <span>Captive Data</span>
            </div>
            <nav class="pc-side-nav" aria-label="Seções do painel">
                <button type="button" class="pc-side-item" data-aba="painel">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Painel
                </button>
                <button type="button" class="pc-side-item" data-aba="dashboard">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                    Dashboard
                </button>
                <button type="button" class="pc-side-item" data-aba="relatorios">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M7 16v-5"/><path d="M12 16V8"/><path d="M17 16v-8"/></svg>
                    Relatórios
                </button>
                <button type="button" class="pc-side-item" data-aba="anuncio">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                    Anúncio
                </button>
                <button type="button" class="pc-side-item" data-aba="url">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    Url do site
                </button>
                <button type="button" class="pc-side-item" data-aba="limites">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/></svg>
                    Limites
                </button>
                <button type="button" class="pc-side-item" data-aba="html">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                    HTML Mikrotik
                </button>
                <button type="button" class="pc-side-item" data-aba="estatisticas">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                    Estatísticas
                </button>
            </nav>
        </aside>
        <div class="pc-side-backdrop" aria-hidden="true"></div>

        <div class="pc-shell">
            <div id="mikrotik-status" class="mk-status <?= $mkOnline ? 'mk-on' : 'mk-off' ?>"
                 data-endpoint="api/mikrotik_status.php?<?= $rotAtivo !== null ? 'roteador=' . urlencode($rotAtivo) : 'cliente_id=' . (int) $id ?>">
                <span class="mk-led"></span>
                <span class="mk-text">MikroTik <?= $mkOnline ? 'online' : 'offline' ?></span>
            </div>

            <header class="pc-topbar">
                <div class="pc-topbar-left">
                    <button type="button" class="pc-menu-btn" id="pc-menu-btn" aria-label="Abrir/fechar menu">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
                    </button>
                    <div>
                        <h1 class="pc-brand">Leads do cliente</h1>
                        <p class="pc-sub"><?= h($cliente['nome'] ?: $cliente['email']) ?>
                            — <?= $multi ? '<strong>' . count($rotLista) . ' MikroTiks</strong>' : 'roteador <strong>' . h((string) ($rotLista[0] ?? '—')) . '</strong>' ?></p>
                    </div>
                </div>
                <div class="pc-topbar-right">
                    <?php if ($multi): ?>
                    <details class="rt-sel">
                        <summary>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h.01"/><path d="M2 8.82a15 15 0 0 1 20 0"/><path d="M5 12.86a10 10 0 0 1 14 0"/><path d="M8.5 16.43a5 5 0 0 1 7 0"/></svg>
                            <span><?= $rotAtivo !== null ? h($rotAtivo) : 'Todos os MikroTiks' ?></span>
                            <svg class="rt-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        </summary>
                        <div class="rt-menu">
                            <div class="rt-menu-title">MikroTik</div>
                            <?php foreach ($rotLista as $rt): ?>
                            <a class="rt-item<?= $rotAtivo === $rt ? ' atual' : '' ?>" href="?id=<?= (int) $id ?>&amp;r=<?= urlencode($rt) ?>">
                                <span><?= h($rt) ?></span>
                                <?php if ($rotAtivo === $rt): ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg><?php endif; ?>
                            </a>
                            <?php endforeach; ?>
                            <a class="rt-item rt-item-all<?= $rotAtivo === null ? ' atual' : '' ?>" href="?id=<?= (int) $id ?>&amp;r=">
                                <span>Todos (misturado)</span>
                                <?php if ($rotAtivo === null): ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg><?php endif; ?>
                            </a>
                        </div>
                    </details>
                    <?php endif; ?>
                    <a class="pc-btn" href="admin.php">&larr; Voltar</a>
                </div>
            </header>

            <!-- ============ ABA: PAINEL (métricas + leads) ============ -->
            <section class="pc-tela" data-tela="painel">
                <?php $fBase = '?id=' . (int) $id . '&amp;r=' . urlencode((string) $rotAtivo) . '&amp;f='; ?>
                <div class="pc-summary">
                    <a class="glow-card pc-metric<?= $filtro === 'online' ? ' atual' : '' ?>" href="<?= $fBase ?>online" title="Filtrar: online agora">
                        <span class="glow-fx" aria-hidden="true"></span>
                        <div class="glow-body">
                            <span class="pc-metric-num" id="metric-online"><?= $resumo['online'] ?></span>
                            <span class="pc-metric-label">online agora</span>
                        </div>
                    </a>
                    <a class="glow-card pc-metric<?= $filtro === 'hoje' ? ' atual' : '' ?>" href="<?= $fBase ?>hoje" title="Filtrar: conectados hoje">
                        <span class="glow-fx" aria-hidden="true"></span>
                        <div class="glow-body">
                            <span class="pc-metric-num" id="metric-hoje"><?= $resumo['hoje'] ?></span>
                            <span class="pc-metric-label">conectados hoje</span>
                        </div>
                    </a>
                    <a class="glow-card pc-metric<?= $filtro === 'cadastrados' ? ' atual' : '' ?>" href="<?= $fBase ?>cadastrados" title="Filtrar: cadastrados hoje">
                        <span class="glow-fx" aria-hidden="true"></span>
                        <div class="glow-body">
                            <span class="pc-metric-num" id="metric-cadastrados"><?= $resumo['cadastrados'] ?></span>
                            <span class="pc-metric-label">cadastrados hoje</span>
                        </div>
                    </a>
                    <a class="glow-card pc-metric<?= $filtro === '' ? ' atual' : '' ?>" href="<?= $fBase ?>" title="Todos os leads">
                        <span class="glow-fx" aria-hidden="true"></span>
                        <div class="glow-body">
                            <span class="pc-metric-num" id="metric-total"><?= $resumo['total'] ?></span>
                            <span class="pc-metric-label">total de leads</span>
                        </div>
                    </a>
                </div>

                <div class="glow-card pc-table-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <div class="pc-table-wrap" id="leads-live"
                             data-endpoint="api/leads_online.php?<?= $rotAtivo !== null ? 'roteador=' . urlencode($rotAtivo) : 'cliente_id=' . (int) $id ?>&amp;filtro=<?= urlencode($filtro) ?>"
                             data-limite-endpoint="api/set_limite.php"
                             data-banda-endpoint="api/set_banda.php"
                             data-conexoes-endpoint="api/conexoes.php"
                             data-editar-endpoint="api/lead_editar.php"
                             data-excluir-endpoint="api/lead_excluir.php"
                             data-pagina="<?= $pagina ?>"
                             data-por-pagina="<?= $POR_PAG ?>"
                             data-csrf="<?= h($csrf) ?>">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Número</th>
                                        <th>IP</th>
                                        <th>Aparelho</th>
                                        <th>Data da conexão</th>
                                        <th>Tempo conectado</th>
                                        <th>Consumo</th>
                                        <th>Banda <span class="pc-th-hint">(clique p/ editar)</span></th>
                                        <th>Tempo limite <span class="pc-th-hint">(clique p/ editar)</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$leads): ?>
                                        <tr class="pc-empty-row"><td colspan="8" class="pc-vazio">Nenhum lead ainda para este cliente.</td></tr>
                                    <?php else: foreach ($leads as $l):
                                        $st      = lead_estado($l, $nowTs);
                                        $online  = $st['online'];
                                        $segF    = $st['seg'];
                                        $elapsed = $st['elapsed'];
                                        $lim     = $l['tempo_limite_min'];
                                        $banda   = $l['banda_limite'];
                                        $total   = (int) $l['total_conexoes'];
                                        if ($online === 1)      { $tempoTxt = fmt_tempo($elapsed); }
                                        elseif ($segF !== null) { $tempoTxt = fmt_tempo($segF); }
                                        else                    { $tempoTxt = '—'; }
                                    ?>
                                    <tr data-id="<?= (int) $l['id'] ?>" data-online="<?= $online ?>" data-elapsed="<?= $elapsed ?>" data-limite="<?= $lim === null ? '' : (int) $lim ?>" data-banda="<?= $banda === null ? '' : (int) $banda ?>" data-total="<?= $total ?>" data-tel="<?= h($l['telefone']) ?>" data-nome="<?= h((string) ($l['nome'] ?? '')) ?>">
                                        <td><?= h(($l['nome'] !== null && $l['nome'] !== '') ? $l['nome'] : $l['telefone']) ?></td>
                                        <td><?= h($l['ip'] ?? '—') ?></td>
                                        <td class="pc-aparelho"><?= h($l['dispositivo'] ?? '—') ?></td>
                                        <td>
                                            <?php $dh = explode(' - ', fmt_data($l['conectado_em'])); ?>
                                            <div class="pc-conex-cel">
                                                <button type="button" class="pc-ver-conexoes" data-lead="<?= (int) $l['id'] ?>" aria-label="Ver conexões">
                                                    <svg class="pc-conex-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
                                                    <span class="pc-total"><?= $total ?></span>
                                                </button>
                                                <div class="pc-dh"><span class="pc-data"><?= h($dh[0]) ?></span><span class="pc-hora"><?= h($dh[1] ?? '') ?></span></div>
                                            </div>
                                        </td>
                                        <td><span class="pc-dot"></span><span class="pc-tempo"><?= h($tempoTxt) ?></span></td>
                                        <td class="pc-uso"><?= h(fmt_bytes($l['bytes_total'] ?? 0)) ?></td>
                                        <td class="pc-banda"><?= $banda === null ? 'sem limite' : (int) $banda . ' Mbps' ?></td>
                                        <td class="pc-limite"><?= $lim === null ? 'sem limite' : (int) $lim . ' min' ?></td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($totalPaginas > 1): ?>
                        <nav class="pc-pag" aria-label="Páginas de leads">
                            <?php foreach (paginacao_paginas($pagina, $totalPaginas) as $p): ?>
                                <?php if ($p === '...'): ?><span class="pc-pag-gap">…</span>
                                <?php elseif ($p === $pagina): ?><span class="pc-pag-btn atual" aria-current="page"><?= $p ?></span>
                                <?php else: ?><a class="pc-pag-btn" href="?id=<?= (int) $id ?>&amp;r=<?= urlencode((string) $rotAtivo) ?>&amp;f=<?= urlencode($filtro) ?>&amp;pagina=<?= $p ?>"><?= $p ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- ============ ABA: DASHBOARD (hábitos de um lead) ============ -->
            <section class="pc-tela" data-tela="dashboard">
                <div class="glow-card pc-dst-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <div class="pc-dst" id="dashboard-box" data-endpoint="api/dashboard.php?<?= $rotAtivo !== null ? 'roteador=' . urlencode($rotAtivo) : 'cliente_id=' . (int) $id ?>">
                            <h2 class="pc-anuncio-title">Dashboard do lead</h2>
                            <p class="pc-anuncio-desc">Digite o número de um lead para ver os hábitos de visita dele — ou clique com o botão direito num lead na aba Painel e escolha "Informações".</p>
                            <div class="pc-dst-form">
                                <input type="tel" id="dash-tel" class="pc-dst-input" inputmode="numeric" placeholder="48999999999" aria-label="Número do lead">
                                <button type="button" class="pc-btn-primary" id="dash-consultar">Consultar</button>
                            </div>
                            <p class="pc-anuncio-msg err" id="dash-erro" style="display:none"></p>
                            <div id="dash-resultado"></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============ ABA: RELATÓRIOS ============ -->
            <section class="pc-tela" data-tela="relatorios">
                <div class="glow-card pc-dst-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <div class="pc-dst" id="relatorio-box" data-endpoint="api/relatorio.php?<?= $rotAtivo !== null ? 'roteador=' . urlencode($rotAtivo) : 'cliente_id=' . (int) $id ?>">
                            <h2 class="pc-anuncio-title">Relatórios</h2>
                            <p class="pc-anuncio-desc">Escolha o modelo do relatório, o período (data inicial e final) e clique em gerar. Os acessos contados são as conexões dos clientes no Wi-Fi.</p>
                            <div class="rel-controles">
                                <details class="rt-sel" id="rel-sel">
                                    <summary>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M7 16v-5"/><path d="M12 16V8"/><path d="M17 16v-8"/></svg>
                                        <span id="rel-sel-label">Escolher modelo…</span>
                                        <svg class="rt-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                                    </summary>
                                    <div class="rt-menu">
                                        <div class="rt-menu-title">Modelo do relatório</div>
                                        <button type="button" class="rt-item rel-item" data-tipo="semana"><span>Acessos por dia da semana</span></button>
                                        <button type="button" class="rt-item rel-item" data-tipo="hora"><span>Acessos por horário</span></button>
                                        <button type="button" class="rt-item rel-item" data-tipo="clientes_dias"><span>Clientes - Dias</span></button>
                                        <button type="button" class="rt-item rel-item" data-tipo="clientes_tempo"><span>Clientes - Tempo</span></button>
                                    </div>
                                </details>
                                <input type="text" id="rel-inicio" class="pc-dst-input rel-data" readonly inputmode="none" placeholder="dd/mm/aaaa"
                                       value="<?= date('d/m/Y', strtotime('-6 days')) ?>" data-iso="<?= date('Y-m-d', strtotime('-6 days')) ?>" aria-label="Data inicial">
                                <input type="text" id="rel-fim" class="pc-dst-input rel-data" readonly inputmode="none" placeholder="dd/mm/aaaa"
                                       value="<?= date('d/m/Y') ?>" data-iso="<?= date('Y-m-d') ?>" aria-label="Data final">
                                <button type="button" class="pc-btn-primary" id="rel-gerar">Gerar relatório</button>
                            </div>
                            <p class="pc-anuncio-msg err" id="rel-erro" style="display:none"></p>
                            <div class="rel-grafico" id="rel-grafico"></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============ ABA: ANÚNCIO ============ -->
            <section class="pc-tela" data-tela="anuncio">
                <?php if ($rotAtivo !== null): ?>
                <div class="glow-card pc-anuncio-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <div class="pc-anuncio">
                            <div class="pc-anuncio-preview">
                                <img src="ad.php?r=<?= urlencode($rotAtivo) ?>&t=<?= time() ?>" alt="Anúncio atual"
                                     <?= $temAnuncio ? '' : 'style="display:none"' ?>
                                     onerror="this.style.display='none';var v=this.nextElementSibling;if(v)v.style.display='flex';">
                                <div class="pc-anuncio-vazio" <?= $temAnuncio ? 'style="display:none"' : '' ?>>Sem anúncio<br>enviado</div>
                            </div>
                            <div class="pc-anuncio-form">
                                <h2 class="pc-anuncio-title">Anúncio do captive portal</h2>
                                <div class="pc-anuncio-target">Enviando para: <strong><?= h($cliente['nome'] ?: $cliente['email']) ?></strong> — roteador <strong><?= h((string) $rotAtivo) ?></strong></div>
                                <p class="pc-anuncio-desc">Imagem mostrada nos 10 segundos antes de liberar o Wi-Fi. Envie JPG, JPEG ou PNG (até 3 MB). Substitui apenas o anúncio deste cliente.</p>
                                <?php if ($anuncioOk): ?><p class="pc-anuncio-msg ok"><?= h($anuncioOk) ?></p><?php endif; ?>
                                <?php if ($anuncioErro): ?><p class="pc-anuncio-msg err"><?= h($anuncioErro) ?></p><?php endif; ?>
                                <form class="pc-anuncio-envio" method="post" action="api/upload_anuncio.php" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="cliente_id" value="<?= (int) $id ?>">
                                    <input type="hidden" name="roteador" value="<?= h((string) $rotAtivo) ?>">
                                    <label class="pc-file">
                                        <input type="file" name="anuncio" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>
                                        <span class="pc-file-label">Escolher imagem…</span>
                                    </label>
                                    <button type="submit" class="pc-btn-primary">Enviar anúncio</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: echo $avisoRoteador; endif; ?>
            </section>

            <!-- ============ ABA: URL DO SITE ============ -->
            <section class="pc-tela" data-tela="url">
                <?php if ($rotAtivo !== null): ?>
                <div class="glow-card pc-dst-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <div class="pc-dst">
                            <h2 class="pc-anuncio-title">Site de destino após o anúncio</h2>
                            <div class="pc-anuncio-target">Configurando: <strong><?= h($cliente['nome'] ?: $cliente['email']) ?></strong> — roteador <strong><?= h((string) $rotAtivo) ?></strong></div>
                            <p class="pc-anuncio-desc">Ao terminar o anúncio, o cliente deste roteador é redirecionado para este site. Padrão: Google. Pode colar o link do perfil do Instagram — convertemos automaticamente para o formato que abre a página do perfil sem erro no iPhone.</p>
                            <p class="pc-dst-atual">Atual: <strong><?= $dstAtual ? h($dstAtual) : 'https://www.google.com (padrão)' ?></strong></p>
                            <?php if ($dstOk): ?><p class="pc-anuncio-msg ok"><?= h($dstOk) ?></p><?php endif; ?>
                            <?php if ($dstErro): ?><p class="pc-anuncio-msg err"><?= h($dstErro) ?></p><?php endif; ?>
                            <form class="pc-dst-form" method="post" action="api/set_dst.php">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="cliente_id" value="<?= (int) $id ?>">
                                <input type="hidden" name="roteador" value="<?= h((string) $rotAtivo) ?>">
                                <input type="text" inputmode="url" name="dst" class="pc-dst-input" placeholder="https://seusite.com.br"
                                       value="<?= h($dstAtual ?? '') ?>" required>
                                <button type="submit" class="pc-btn-primary">Adicionar</button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php else: echo $avisoRoteador; endif; ?>
            </section>

            <!-- ============ ABA: LIMITES ============ -->
            <section class="pc-tela" data-tela="limites">
                <?php if ($rotAtivo !== null): ?>
                <div class="glow-card pc-dst-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <div class="pc-dst">
                            <h2 class="pc-anuncio-title">Aplicar limites a todos os usuários</h2>
                            <div class="pc-anuncio-target">Roteador <strong><?= h((string) $rotAtivo) ?></strong></div>
                            <p class="pc-anuncio-desc">Define o limite para quem já está na tabela e para os próximos que conectarem. Deixe vazio para "sem limite". Para mudar um usuário específico, use a própria tabela na aba Painel (clique no valor).</p>
                            <?php if ($tlimOk): ?><p class="pc-anuncio-msg ok"><?= h($tlimOk) ?></p><?php endif; ?>
                            <?php if ($tlimErro): ?><p class="pc-anuncio-msg err"><?= h($tlimErro) ?></p><?php endif; ?>
                            <form class="pc-dst-form" method="post" action="api/set_padrao.php">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="cliente_id" value="<?= (int) $id ?>">
                                <input type="hidden" name="roteador" value="<?= h((string) $rotAtivo) ?>">
                                <input type="number" min="0" inputmode="numeric" name="tlimite" class="pc-dst-input"
                                       placeholder="Tempo limite (min) — vazio = sem limite" value="<?= $tlPadrao === null ? '' : (int) $tlPadrao ?>">
                                <button type="submit" class="pc-btn-primary">Aplicar tempo</button>
                            </form>
                            <?php if ($bandaOk): ?><p class="pc-anuncio-msg ok"><?= h($bandaOk) ?></p><?php endif; ?>
                            <?php if ($bandaErro): ?><p class="pc-anuncio-msg err"><?= h($bandaErro) ?></p><?php endif; ?>
                            <form class="pc-dst-form" method="post" action="api/set_padrao.php">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="cliente_id" value="<?= (int) $id ?>">
                                <input type="hidden" name="roteador" value="<?= h((string) $rotAtivo) ?>">
                                <input type="number" min="0" inputmode="numeric" name="banda" class="pc-dst-input"
                                       placeholder="Banda máx. (Mbps) — vazio = ilimitado" value="<?= $bandaPadrao === null ? '' : (int) $bandaPadrao ?>">
                                <button type="submit" class="pc-btn-primary">Aplicar banda</button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php else: echo $avisoRoteador; endif; ?>
            </section>

            <!-- ============ ABA: HTML MIKROTIK (admin sempre vê) ============ -->
            <section class="pc-tela" data-tela="html">
                <?php if ($rotAtivo !== null): ?>
                <div class="glow-card pc-dst-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <div class="pc-dst">
                            <h2 class="pc-anuncio-title">Página de login do hotspot (MikroTik)</h2>
                            <div class="pc-anuncio-target">Roteador <strong><?= h((string) $rotAtivo) ?></strong></div>
                            <p class="pc-anuncio-desc">Envie um <strong>.zip</strong> do template (com <code>login.html</code>, <code>css/</code>, <code>img/</code>, <code>xml/</code>…): extraímos e o MikroTik substitui os de <code>flash/hostsv7</code> em até ~1 min (as subpastas são criadas sozinhas). Ou envie um arquivo avulso para trocar só ele. Até 2 MB por arquivo.</p>
                            <?php if ($portalFiles): ?>
                                <p class="pc-dst-atual">No servidor (<?= count($portalFiles) ?>): <strong><?= h(implode(', ', $portalFiles)) ?></strong></p>
                                <p class="pc-dst-atual"><a class="pc-btn" href="api/download_portal.php?cliente_id=<?= (int) $id ?>&amp;roteador=<?= urlencode((string) $rotAtivo) ?>">Baixar cópia atual (.zip)</a> — os mesmos arquivos aplicados em <code>flash/hostsv7</code>; nada é apagado.</p>
                            <?php else: ?>
                                <p class="pc-dst-atual">Nenhum arquivo enviado ainda.</p>
                            <?php endif; ?>
                            <?php if ($portalOk): ?><p class="pc-anuncio-msg ok"><?= h($portalOk) ?></p><?php endif; ?>
                            <?php if ($portalErro): ?><p class="pc-anuncio-msg err"><?= h($portalErro) ?></p><?php endif; ?>
                            <form class="pc-anuncio-envio" method="post" action="api/upload_portal.php" enctype="multipart/form-data">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="cliente_id" value="<?= (int) $id ?>">
                                <input type="hidden" name="roteador" value="<?= h((string) $rotAtivo) ?>">
                                <label class="pc-file">
                                    <input type="file" name="arquivo" accept=".zip,.html,.htm,.css,.js,.svg,.png,.jpg,.jpeg,.gif,.ico,.json,.txt,.xml,.xsd" required>
                                    <span class="pc-file-label">Escolher .zip ou arquivo…</span>
                                </label>
                                <button type="submit" class="pc-btn-primary">Enviar ao MikroTik</button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php else: echo $avisoRoteador; endif; ?>
            </section>

            <!-- ============ ABA: ESTATÍSTICAS ============ -->
            <section class="pc-tela" data-tela="estatisticas">
                <div class="glow-card pc-dst-card">
                    <span class="glow-fx" aria-hidden="true"></span>
                    <div class="glow-body">
                        <div class="pc-dst" id="estatisticas-box" data-endpoint="api/estatisticas.php?<?= $rotAtivo !== null ? 'roteador=' . urlencode($rotAtivo) : 'cliente_id=' . (int) $id ?>">
                            <h2 class="pc-anuncio-title">Estatísticas</h2>
                            <p class="pc-anuncio-desc">Pessoas conectadas no Wi-Fi (cada número conta uma vez por ponto do gráfico) e novos clientes ao longo do tempo. Passe o mouse sobre o gráfico para ver os valores de cada ponto.</p>
                            <div class="est-filtros">
                                <button type="button" class="est-filtro atual" data-filtro="hoje">Hoje</button>
                                <button type="button" class="est-filtro" data-filtro="semana">Semana</button>
                                <button type="button" class="est-filtro" data-filtro="mes">Mês</button>
                                <button type="button" class="est-filtro" data-filtro="ano">Ano</button>
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

    <!-- Pop-up: editar lead (nome de identificação + número) -->
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
                    <p class="pc-anuncio-msg err" id="ed-erro" style="display:none"></p>
                    <button type="button" class="pc-btn-primary" id="ed-salvar">Salvar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Pop-up: histórico de conexões de um número -->
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
            </div>
        </div>
    </div>

    <script src="assets/abas.js?v=2"></script>
    <script src="assets/relatorio.js?v=7"></script>
    <script src="assets/dashboard.js?v=8"></script>
    <script src="assets/estatisticas.js?v=2"></script>
    <script src="assets/leads-live.js?v=21"></script>
    <?php require __DIR__ . '/inc/tema.php'; ?>
</body>
</html>
