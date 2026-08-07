<?php
// Aba "Teste de velocidade". Incluída pelo painel do cliente e pela tela do
// admin. Espera no escopo: $rotAtivo, $csrf, e $spClienteId (int|null) no admin.
//
// São DUAS medições, que respondem perguntas diferentes:
//   - a da LOJA: o MikroTik baixa do nosso servidor e nós cronometramos. É a
//     internet que chega no roteador do cliente, medida sem ninguém ir lá.
//   - a DESTE APARELHO: o navegador baixa do nosso servidor. Útil para saber se
//     o problema é o link da loja ou a conexão de quem está olhando o painel.
$spCid = isset($spClienteId) ? (int) $spClienteId : null;
?>
<section class="pc-tela" data-tela="velocidade">
    <?php if ($rotAtivo !== null): ?>
    <div class="glow-card pc-dst-card">
        <span class="glow-fx" aria-hidden="true"></span>
        <div class="glow-body">
            <div class="pc-dst" id="speedrt-box"
                 data-endpoint="api/speed_loja.php"
                 data-roteador="<?= h((string) $rotAtivo) ?>"
                 <?php if ($spCid !== null): ?>data-cliente="<?= $spCid ?>"<?php endif; ?>
                 data-csrf="<?= h($csrf) ?>">
                <h2 class="pc-anuncio-title">Internet da loja</h2>
                <p class="pc-anuncio-desc">Mede a velocidade que chega no <strong>MikroTik <?= h((string) $rotAtivo) ?></strong>, sem precisar ir até lá. O roteador faz o teste na próxima rodada — leva cerca de um minuto para o resultado aparecer.</p>

                <div class="sp-topo">
                    <span class="sp-kpi sp-kpi-down">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v8"/><path d="m8 12 4 4 4-4"/></svg>
                        <b id="rt-down">—</b> <i>DOWNLOAD Mbps</i>
                    </span>
                    <span class="sp-kpi sp-kpi-ping">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6v6l4 2"/><circle cx="12" cy="12" r="10"/></svg>
                        <b id="rt-ping">—</b> <i>PING ms</i>
                    </span>
                </div>

                <div class="sp-medidor">
                    <svg viewBox="0 0 320 250" class="sp-svg" role="img" aria-label="Velocímetro da loja">
                        <defs>
                            <linearGradient id="rt-grad" x1="0" y1="1" x2="1" y2="0">
                                <stop offset="0" class="sp-g1"/><stop offset="1" class="sp-g2"/>
                            </linearGradient>
                        </defs>
                        <path id="rt-fundo" class="sp-fundo" fill="none"/>
                        <g id="rt-marcas"></g>
                        <path id="rt-arco" class="sp-arco" fill="none" style="stroke:url(#rt-grad)" d=""/>
                        <line id="rt-ponteiro" class="sp-ponteiro" x1="160" y1="150" x2="160" y2="150"/>
                        <circle class="sp-eixo" cx="160" cy="150" r="6"/>
                        <text id="rt-valor" class="sp-valor" x="160" y="205" text-anchor="middle">0.00</text>
                        <text id="rt-unidade" class="sp-unidade" x="160" y="228" text-anchor="middle">Mbps ↓</text>
                    </svg>
                </div>

                <p class="sp-fase" id="rt-fase">Toque em testar para medir a internet da loja</p>
                <button type="button" class="sp-btn" id="rt-iniciar">Testar agora</button>

                <details class="pc-hist sp-hist" id="rt-hist-wrap" style="display:none">
                    <summary class="pc-hist-btn">Medições anteriores (<span id="rt-hist-n">0</span>)</summary>
                    <ul class="pc-hist-lista" id="rt-hist"></ul>
                </details>
            </div>
        </div>
    </div>
    <?php else: echo $avisoRoteador; endif; ?>

    <div class="glow-card pc-dst-card" style="margin-top:18px">
        <span class="glow-fx" aria-hidden="true"></span>
        <div class="glow-body">
            <div class="pc-dst" id="speed-box" data-endpoint="api/speed.php">
                <h2 class="pc-anuncio-title">Este aparelho</h2>
                <p class="pc-anuncio-desc">Mede a conexão <strong>de onde você está agora</strong> até o nosso servidor. Serve para saber se a lentidão é do link da loja ou da sua própria conexão.</p>

                <div class="sp-topo">
                    <span class="sp-kpi sp-kpi-down">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v8"/><path d="m8 12 4 4 4-4"/></svg>
                        <b id="sp-down">—</b> <i>DOWNLOAD Mbps</i>
                    </span>
                    <span class="sp-kpi sp-kpi-up">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16V8"/><path d="m8 12 4-4 4 4"/></svg>
                        <b id="sp-up">—</b> <i>UPLOAD Mbps</i>
                    </span>
                    <span class="sp-kpi sp-kpi-ping">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6v6l4 2"/><circle cx="12" cy="12" r="10"/></svg>
                        <b id="sp-ping">—</b> <i>PING ms</i>
                    </span>
                </div>

                <div class="sp-medidor">
                    <svg viewBox="0 0 320 250" class="sp-svg" role="img" aria-label="Velocímetro">
                        <defs>
                            <linearGradient id="sp-grad" x1="0" y1="1" x2="1" y2="0">
                                <stop offset="0" class="sp-g1"/><stop offset="1" class="sp-g2"/>
                            </linearGradient>
                        </defs>
                        <path id="sp-fundo" class="sp-fundo" fill="none"/>
                        <g id="sp-marcas"></g>
                        <path id="sp-arco" class="sp-arco" fill="none" d=""/>
                        <line id="sp-ponteiro" class="sp-ponteiro" x1="160" y1="150" x2="160" y2="150"/>
                        <circle class="sp-eixo" cx="160" cy="150" r="6"/>
                        <text id="sp-valor" class="sp-valor" x="160" y="205" text-anchor="middle">0.00</text>
                        <text id="sp-unidade" class="sp-unidade" x="160" y="228" text-anchor="middle">Mbps</text>
                    </svg>
                </div>

                <p class="sp-fase" id="sp-fase">Toque em iniciar para medir</p>
                <button type="button" class="sp-btn" id="sp-iniciar">Iniciar</button>
            </div>
        </div>
    </div>
</section>
