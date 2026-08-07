<?php
// Histórico de logos ou anúncios enviados, com um botão para repor cada um.
//
// Antes, mandar uma imagem nova apagava a anterior: voltar atrás (fim de uma
// promoção, por exemplo) exigia achar o arquivo original no computador. Aqui os
// últimos envios ficam guardados e trocar é um clique.
//
// Incluído pelo painel do cliente e pela tela do admin. Espera no escopo:
//   $mhTipo ('logo'|'anuncio')   $rotAtivo   $csrf
//   $mhClienteId (int|null) — só no admin
$mhLista = midia_hist((string) $rotAtivo, $mhTipo);
if (count($mhLista) < 2) {
    // Com um envio só (ou nenhum) não há o que escolher — a lista seria uma
    // cópia do que já está na tela.
    return;
}
$mhAtivo = midia_hist_ativo((string) $rotAtivo, $mhTipo);
$mhCid   = isset($mhClienteId) ? (int) $mhClienteId : null;
$mhQuery = 'roteador=' . urlencode((string) $rotAtivo) . '&tipo=' . $mhTipo
         . ($mhCid !== null ? '&cliente_id=' . $mhCid : '');
?>
<details class="pc-hist pc-mhist">
    <summary class="pc-hist-btn">Imagens anteriores (<?= count($mhLista) ?>)</summary>
    <div class="mh-grade">
        <?php foreach ($mhLista as $it): $emUso = ($mhAtivo !== null && $it['id'] === $mhAtivo); ?>
        <div class="mh-item<?= $emUso ? ' em-uso' : '' ?>">
            <img class="mh-thumb" loading="lazy"
                 src="api/hist_img.php?<?= $mhQuery ?>&amp;id=<?= h((string) $it['id']) ?>" alt="">
            <div class="mh-meta">
                <span class="mh-nome" title="<?= h((string) $it['nome']) ?>"><?= h((string) $it['nome']) ?></span>
                <span class="mh-data"><?= h(fmt_data((string) $it['em'])) ?></span>
            </div>
            <?php if ($emUso): ?>
                <span class="mh-uso">Em uso</span>
            <?php else: ?>
                <form method="post" action="api/usar_midia.php">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="roteador" value="<?= h((string) $rotAtivo) ?>">
                    <?php if ($mhCid !== null): ?><input type="hidden" name="cliente_id" value="<?= $mhCid ?>"><?php endif; ?>
                    <input type="hidden" name="tipo" value="<?= h($mhTipo) ?>">
                    <input type="hidden" name="id" value="<?= h((string) $it['id']) ?>">
                    <button type="submit" class="mh-usar">Usar esta</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="mh-nota">Ficam guardados os <?= MIDIA_HIST_MAX ?> últimos envios.</p>
</details>
