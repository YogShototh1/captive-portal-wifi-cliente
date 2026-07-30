<?php
// Dois modais da aba Alertas:
//  1) "Selecionar urgências" — as flags que o comprador quer acompanhar.
//  2) A lista de clientes de um aviso (preenchida pelo JS).
// Incluido FORA de qualquer <form>, por painel.php e admin_leads.php.
$aCat = alertas_catalogo();
$aCid = isset($alertasClienteId) ? (int) $alertasClienteId : null;
?>
<div class="pc-modal pc-alertas-modal" id="alertas-modal" aria-hidden="true">
    <div class="pc-modal-backdrop" data-close></div>
    <div class="pc-modal-card glow-card">
        <span class="glow-fx" aria-hidden="true"></span>
        <div class="glow-body">
            <div class="pc-modal-head">
                <h2 class="pc-modal-title">Selecionar urgências</h2>
                <button type="button" class="pc-modal-x" data-close aria-label="Fechar">&times;</button>
            </div>
            <form method="post" action="api/set_alertas.php" class="pc-modal-body am-form" id="alertas-form">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <?php if ($aCid !== null): ?><input type="hidden" name="cliente_id" value="<?= $aCid ?>"><?php endif; ?>
                <p class="am-intro">Marque o que você quer ver na aba Alertas. Fica guardado na sua conta.</p>
                <div class="am-grupos">
                    <?php foreach (['ruim' => 'Precisa de atenção', 'bom' => 'Boas notícias'] as $tom => $titulo): ?>
                    <div class="am-grupo">
                        <span class="am-grupo-tit am-<?= $tom ?>"><?= h($titulo) ?></span>
                        <?php foreach ($aCat as $k => $m): if ($m[2] !== $tom) { continue; } ?>
                        <label class="am-flag">
                            <input type="checkbox" name="<?= $k ?>" value="1" <?= !empty($alertasMarcados[$k]) ? 'checked' : '' ?>>
                            <span class="am-tx">
                                <span class="am-nome"><?= h($m[0]) ?></span>
                                <span class="am-desc"><?= h($m[1]) ?></span>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="am-msg" id="alertas-msg" role="status"></p>
                <button type="submit" class="pc-btn-primary am-salvar">Salvar</button>
            </form>
        </div>
    </div>
</div>

<div class="pc-modal pc-alertas-lista" id="alerta-lista-modal" aria-hidden="true">
    <div class="pc-modal-backdrop" data-close></div>
    <div class="pc-modal-card glow-card">
        <span class="glow-fx" aria-hidden="true"></span>
        <div class="glow-body">
            <div class="pc-modal-head">
                <h2 class="pc-modal-title" id="alerta-lista-titulo">Clientes</h2>
                <button type="button" class="pc-modal-x" data-close aria-label="Fechar">&times;</button>
            </div>
            <div class="pc-modal-body" id="alerta-lista-corpo"></div>
        </div>
    </div>
</div>
