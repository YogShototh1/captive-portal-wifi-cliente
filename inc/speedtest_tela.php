<?php
// Aba "Teste de velocidade". Incluída pelo painel do cliente e pela tela do
// admin — o teste mede a conexão de QUEM ESTÁ OLHANDO até o servidor, então
// não depende de roteador escolhido.
?>
<section class="pc-tela" data-tela="velocidade">
    <div class="glow-card pc-dst-card">
        <span class="glow-fx" aria-hidden="true"></span>
        <div class="glow-body">
            <div class="pc-dst" id="speed-box" data-endpoint="api/speed.php">
                <h2 class="pc-anuncio-title">Teste de velocidade</h2>
                <p class="pc-anuncio-desc">Mede a conexão <strong>deste aparelho até o nosso servidor</strong>. Serve para comparar (o Wi-Fi está pior que ontem?); para conferir a banda contratada, use o Speedtest, que escolhe um servidor perto de você.</p>

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
