<?php
// Cartão "Textos de LGPD" da aba Personalizar. Compartilhado entre painel.php e
// admin_leads.php. Espera $rotAtivo, $csrf, $lgpd, $lgpdOk, $lgpdErro e
// $lgpdCliente (id do cliente na tela do admin; 0 no painel do comprador).
?>
<div class="glow-card pc-dst-card">
    <span class="glow-fx" aria-hidden="true"></span>
    <div class="glow-body">
        <div class="pc-dst">
            <h2 class="pc-anuncio-title">Textos de LGPD da tela de login</h2>
            <p class="pc-anuncio-desc">O primeiro aparece embaixo do botão, na tela onde o cliente digita o número. O segundo é o item “Para que usamos” da Política de Privacidade. Deixe em branco para voltar ao texto padrão do seu tipo de painel.</p>
            <p class="pc-anuncio-desc pc-lgpd-nota">Descreva o uso real do número. Consentimento colhido com finalidade errada não protege ninguém — nem o cliente, nem você.</p>
            <?php if ($lgpdOk): ?><p class="pc-anuncio-msg ok"><?= h($lgpdOk) ?></p><?php endif; ?>
            <?php if ($lgpdErro): ?><p class="pc-anuncio-msg err"><?= h($lgpdErro) ?></p><?php endif; ?>
            <form method="post" action="api/set_lgpd.php" class="pc-lgpd-form">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="roteador" value="<?= h((string) $rotAtivo) ?>">
                <?php if ((int) ($lgpdCliente ?? 0) > 0): ?>
                <input type="hidden" name="cliente_id" value="<?= (int) $lgpdCliente ?>">
                <?php endif; ?>
                <label class="pc-lgpd-rot" for="lgpd-aviso-inp">Aviso da tela de login</label>
                <textarea id="lgpd-aviso-inp" name="lgpd_aviso" class="pc-dst-input pc-lgpd-txt" rows="3"
                          maxlength="900" placeholder="Ao prosseguir, você concorda que…"><?= h($lgpd['aviso']) ?></textarea>
                <label class="pc-lgpd-rot" for="lgpd-fins-inp">Para que o número é usado (Política de Privacidade)</label>
                <textarea id="lgpd-fins-inp" name="lgpd_finalidades" class="pc-dst-input pc-lgpd-txt" rows="4"
                          maxlength="900" placeholder="(a) liberar o acesso ao Wi-Fi; (b) …"><?= h($lgpd['finalidades']) ?></textarea>
                <button type="submit" class="pc-btn-primary">Salvar textos</button>
            </form>
        </div>
    </div>
</div>
