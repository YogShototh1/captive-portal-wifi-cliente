<?php
// Aba "Alertas": os avisos que o comprador marcou, em forma de notificacao.
// Vermelho = cliente sumindo; verde = coisa boa. Clicar no numero abre a lista.
// O botao de configurar fica discreto no canto, porque so se mexe nele na
// primeira vez.
// Incluido por painel.php e admin_leads.php. Espera no escopo:
//   $csrf   $rotAtivo   $alertasMarcados (alertas_get)
//   $alertasClienteId (int|null) — so no admin
$aCat = alertas_catalogo();
$aCid = isset($alertasClienteId) ? (int) $alertasClienteId : null;
$aEp  = 'api/alertas.php?roteador=' . urlencode((string) $rotAtivo)
      . ($aCid !== null ? '&cliente_id=' . $aCid : '');
?>
<section class="pc-tela" data-tela="alertas">
    <div class="glow-card pc-dst-card">
        <span class="glow-fx" aria-hidden="true"></span>
        <div class="glow-body">
            <div class="pc-dst al-box" id="alertas-box" data-endpoint="<?= h($aEp) ?>">
                <button type="button" class="al-config" id="alertas-config" title="Selecionar urgências" aria-label="Selecionar urgências">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <span>Selecionar urgências</span>
                </button>

                <h2 class="pc-anuncio-title">Alertas</h2>
                <p class="pc-anuncio-desc">O que merece a sua atenção agora. Clique no número para ver quem são.</p>

                <div id="al-lista" class="al-lista" aria-live="polite"></div>
            </div>
        </div>
    </div>
</section>
