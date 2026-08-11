<?php
// Bloco do último envio + histórico da página de login do hotspot.
// Compartilhado entre o painel do cliente e a tela do admin (mesmo padrão do
// inc/alertas_tela.php). Espera $portalHist já carregado com portal_hist().
//
// O histórico é <details> nativo: abre e fecha sem JS, sem endpoint e sem
// modal — os dados já estão em mãos quando a página é montada.
if (empty($portalHist)) {
    // Já há arquivos no servidor, mas de antes deste registro existir. Sem esta
    // linha a tela parece dizer que nunca ninguém enviou nada.
    if (!empty($portalFiles)) {
        echo '<p class="pc-dst-atual">Os arquivos atuais foram enviados antes do'
           . ' registro de histórico. O próximo envio já aparece aqui.</p>';
    }
    return;
}
$ultimo = $portalHist[0];
?>
<p class="pc-dst-atual pc-hist-ult">Último envio ao roteador:
    <strong><?= h((string) ($ultimo['nome'] ?? '?')) ?></strong>
    — <?= h(fmt_data((string) ($ultimo['em'] ?? ''))) ?>
</p>
<details class="pc-hist">
    <summary class="pc-hist-btn">Histórico de envios (<?= count($portalHist) ?>)</summary>
    <ul class="pc-hist-lista">
        <?php foreach ($portalHist as $it): ?>
        <li class="pc-hist-item">
            <span class="pc-hist-nome"><?= h((string) ($it['nome'] ?? '?')) ?></span>
            <span class="pc-hist-meta">
                <?= h(fmt_data((string) ($it['em'] ?? ''))) ?>
                <?php if ((int) ($it['qtd'] ?? 0) > 1): ?>
                    · <?= (int) $it['qtd'] ?> arquivos
                <?php endif; ?>
                <?php if (!empty($it['quem'])): ?>
                    · <?= h((string) $it['quem']) ?>
                <?php endif; ?>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>
</details>
