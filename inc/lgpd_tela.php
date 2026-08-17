<?php
// Cartão "Termos e Política" da aba Personalizar. Compartilhado entre
// painel.php e admin_leads.php. Espera $rotAtivo, $csrf, $lgpd, $lgpdOk,
// $lgpdErro e $lgpdCliente (id do cliente na tela do admin; 0 no painel).
?>
<div class="glow-card pc-dst-card">
    <span class="glow-fx" aria-hidden="true"></span>
    <div class="glow-body">
        <div class="pc-dst">
            <h2 class="pc-anuncio-title">Termos de Uso e Política de Privacidade</h2>
            <p class="pc-anuncio-desc">São os dois documentos que abrem quando o cliente toca nos links da tela de login. Já vêm preenchidos com o texto padrão do seu tipo de painel — mude o que o seu jurídico exigir. Apague tudo e salve para voltar ao padrão.</p>
            <p class="pc-anuncio-desc pc-lgpd-nota">Texto puro: uma linha em branco separa os blocos, e o título de cada item fica na sua própria linha. Formatação de HTML não é aceita.</p>
            <?php if ($lgpdOk): ?><p class="pc-anuncio-msg ok"><?= h($lgpdOk) ?></p><?php endif; ?>
            <?php if ($lgpdErro): ?><p class="pc-anuncio-msg err"><?= h($lgpdErro) ?></p><?php endif; ?>
            <form method="post" action="api/set_lgpd.php" class="pc-lgpd-form">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="roteador" value="<?= h((string) $rotAtivo) ?>">
                <?php if ((int) ($lgpdCliente ?? 0) > 0): ?>
                <input type="hidden" name="cliente_id" value="<?= (int) $lgpdCliente ?>">
                <?php endif; ?>
                <label class="pc-lgpd-rot" for="lgpd-termos-inp">Termos de Uso</label>
                <textarea id="lgpd-termos-inp" name="lgpd_termos" class="pc-dst-input pc-lgpd-txt" rows="14"
                          maxlength="8000" spellcheck="false"><?= h($lgpd['termos']) ?></textarea>
                <label class="pc-lgpd-rot" for="lgpd-priv-inp">Política de Privacidade</label>
                <textarea id="lgpd-priv-inp" name="lgpd_privacidade" class="pc-dst-input pc-lgpd-txt" rows="16"
                          maxlength="8000" spellcheck="false"><?= h($lgpd['privacidade']) ?></textarea>
                <button type="submit" class="pc-btn-primary">Salvar documentos</button>
            </form>
        </div>
    </div>
</div>
