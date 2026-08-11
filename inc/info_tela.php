<?php
// Aba "Informações": escolher um contato e ver os hábitos de visita dele.
// Compartilhado entre o painel e a tela do admin (mesmo padrão do
// inc/portal_hist_tela.php). Espera $infoEndpoint no escopo.
//
// A tela tem dois estados e um de cada vez: a ESCOLHA (busca + grade de
// contatos) e o RESULTADO (hábitos do contato aberto + botão de voltar). Os ids
// dash-tel / dash-consultar / dash-erro / dash-resultado são os mesmos de antes
// — o assets/dashboard.js continua achando tudo onde procurava.
?>
<div class="pc-dst" id="dashboard-box" data-endpoint="<?= h($infoEndpoint) ?>">
    <h2 class="pc-anuncio-title">Informações do lead</h2>

    <div id="dash-escolha">
        <p class="pc-anuncio-desc pc-info-desc">Clique num contato para ver os hábitos de visita dele. Digite acima para filtrar por número ou nome — ou clique com o botão direito num lead na aba Painel e escolha "Informações".</p>
        <div class="pc-dst-form pc-info-form">
            <input type="tel" id="dash-tel" class="pc-dst-input" inputmode="numeric" placeholder="Filtrar por número ou nome" aria-label="Filtrar contatos">
            <button type="button" class="pc-btn-primary" id="dash-consultar">Consultar</button>
        </div>
        <p class="pc-anuncio-msg err" id="dash-erro" style="display:none"></p>
        <div class="pc-fones" id="dash-grade"></div>
        <div id="dash-nav"></div>
    </div>

    <div id="dash-painel" hidden>
        <button type="button" class="pc-btn pc-info-voltar" id="dash-voltar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
            Voltar aos contatos
        </button>
        <div id="dash-resultado"></div>
    </div>
</div>
