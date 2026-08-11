<?php
// Aba "Teste de velocidade": mede a internet que chega no MikroTik do cliente,
// sem ninguém ir até a loja.
//
// O roteador baixa do nosso servidor e QUEM CRONOMETRA É O SERVIDOR — assim não
// se depende do relógio do RouterOS e nada é gravado na flash dele. O ping vem
// do roteador, medido contra um destino externo.
//
// Incluída pelo painel do cliente e pela tela do admin. Espera no escopo:
//   $rotAtivo   $csrf   $avisoRoteador   $spClienteId (int|null) — só no admin
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
                <h2 class="pc-anuncio-title">Teste de velocidade</h2>
                <p class="pc-anuncio-desc">Mede a internet que chega no <strong>roteador <?= h((string) $rotAtivo) ?></strong>, sem precisar ir até a loja. O próprio roteador baixa de um servidor de teste da Cloudflare, então o número é o do link da loja — não o da nossa hospedagem. O teste roda na próxima rodada e o resultado leva cerca de um minuto para aparecer aqui.</p>

                <div class="sp-topo">
                    <span class="sp-kpi sp-kpi-down">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v8"/><path d="m8 12 4 4 4-4"/></svg>
                        <b id="rt-down">—</b> <i>DOWNLOAD MB/s</i>
                    </span>
                    <span class="sp-kpi sp-kpi-up">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16V8"/><path d="m8 12 4-4 4 4"/></svg>
                        <b id="rt-up">—</b> <i>UPLOAD MB/s</i>
                    </span>
                    <span class="sp-kpi sp-kpi-ping">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6v6l4 2"/><circle cx="12" cy="12" r="10"/></svg>
                        <b id="rt-ping">—</b> <i>PING ms</i>
                    </span>
                </div>

                <div class="sp-medidor">
                    <svg viewBox="0 0 320 250" class="sp-svg" role="img" aria-label="Velocímetro">
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
                        <text id="rt-unidade" class="sp-unidade" x="160" y="228" text-anchor="middle">MB/s ↓</text>
                    </svg>
                </div>

                <!-- O ponteiro marca o que o cliente vê baixando (MB/s); a banda
                     contratada é o mesmo número em bits, que é como a operadora
                     vende. Um é oito vezes o outro, e trocar os dois de lugar é
                     o erro clássico dessa tela. -->
                <p class="sp-banda" id="rt-banda"></p>

                <p class="sp-fase" id="rt-fase">Toque em testar para medir</p>
                <button type="button" class="sp-btn" id="rt-iniciar">Testar agora</button>

                <details class="pc-hist sp-hist" id="rt-hist-wrap" style="display:none">
                    <summary class="pc-hist-btn">Medições anteriores (<span id="rt-hist-n">0</span>)</summary>
                    <ul class="pc-hist-lista" id="rt-hist"></ul>
                </details>
            </div>
        </div>
    </div>
    <?php else: echo $avisoRoteador; endif; ?>
</section>
