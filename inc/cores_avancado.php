<?php
// Botao "avancado" + modal de personalizacao da tela de login.
// A previa e a PAGINA REAL do hotspot (assets/portal_preview.html num iframe,
// usando o mesmo assets/portal.css que vai no roteador): o que aparece aqui e
// o que o cliente ve. Clicar num elemento abre a cor dele; as flags ligam e
// desligam os efeitos.
// Incluido por painel.php e admin_leads.php. Espera no escopo:
//   $cores  (cores_get)   $estilo (estilo_get)   $csrf   $rotAtivo
//   $coresClienteId (int|null) — so no admin
$rotulos = [
    'bg'      => 'Fundo da tela',
    'surface' => 'Cartão',
    'primary' => 'Cor principal (degradê, links, foco)',
    'accent'  => 'Cor de destaque (degradê)',
    'fg'      => 'Título',
    'fg2'     => 'Textos secundários',
    'field'   => 'Campo do número',
    'border'  => 'Bordas',
    'btnfg'   => 'Texto do botão',
];
$flagsRotulos = [
    'vidro'   => ['Efeito vidro no cartão',   'Desfoque translúcido atrás do bloco principal.'],
    'brilho'  => ['Brilho atrás do cartão',   'Halo colorido difuso em volta do bloco.'],
    'manchas' => ['Manchas de luz no fundo',  'Dois borrões coloridos no topo da tela.'],
    'grade'   => ['Grade quadriculada',       'Malha fina de linhas ao fundo.'],
    'sombra'  => ['Sombra do cartão',         'Sombra projetada sob o bloco principal.'],
    'anim'    => ['Animação de entrada',      'O cartão surge deslizando ao abrir.'],
    'grad'    => ['Degradê no botão e logo',  'Desligado, usam a cor principal chapada.'],
];
$cid = isset($coresClienteId) ? (int) $coresClienteId : null;
?>
<div class="pc-modal pc-cores-modal" id="cores-modal" aria-hidden="true">
    <div class="pc-modal-backdrop" data-close></div>
    <div class="pc-modal-card glow-card cm-card-wrap">
        <span class="glow-fx" aria-hidden="true"></span>
        <div class="glow-body">
            <div class="pc-modal-head">
                <h2 class="pc-modal-title">Tela de login do Wi-Fi</h2>
                <button type="button" class="pc-modal-x" data-close aria-label="Fechar">&times;</button>
            </div>

            <form method="post" action="api/set_cores.php" class="cm-form pc-modal-body">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <?php if ($cid !== null): ?><input type="hidden" name="cliente_id" value="<?= $cid ?>"><?php endif; ?>
                <input type="hidden" name="roteador" value="<?= h((string) $rotAtivo) ?>">
                <!-- Sentinela: diz ao servidor que as flags vieram neste POST
                     (checkbox desmarcado nao e enviado). -->
                <input type="hidden" name="tem_flags" value="1">

                <!-- PREVIA: a pagina real do hotspot, isolada num iframe -->
                <div class="cm-previa">
                    <iframe id="cm-frame" src="assets/portal_preview.html?v=2" title="Prévia da tela de login" loading="lazy"></iframe>
                </div>

                <div class="cm-lado">
                    <div class="cm-abas" role="tablist">
                        <button type="button" class="cm-aba atual" data-painel="cores">Cores</button>
                        <button type="button" class="cm-aba" data-painel="efeitos">Efeitos</button>
                    </div>

                    <div class="cm-painel atual" data-painel="cores">
                        <?php foreach ($rotulos as $ck => $cl): ?>
                        <label class="pc-cor-row cm-row" data-row="<?= $ck ?>">
                            <span class="pc-cor-nome"><?= h($cl) ?></span>
                            <span class="pc-cor-ctrls">
                                <input type="color" class="pc-cor-input cm-color" name="<?= $ck ?>" value="<?= h($cores[$ck]) ?>" data-cor="<?= $ck ?>" data-hex="cmhex-<?= $ck ?>" aria-label="<?= h($cl) ?>">
                                <input type="text" class="pc-cor-hex" id="cmhex-<?= $ck ?>" value="<?= h($cores[$ck]) ?>" maxlength="7" spellcheck="false" data-color="<?= $ck ?>">
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="cm-painel" data-painel="efeitos">
                        <?php foreach ($flagsRotulos as $fk => [$fl, $fd]): ?>
                        <label class="cm-flag">
                            <input type="checkbox" class="cm-check" name="<?= $fk ?>" value="1" data-flag="<?= $fk ?>" <?= !empty($estilo[$fk]) ? 'checked' : '' ?>>
                            <span class="cm-flag-tx">
                                <span class="cm-flag-nome"><?= h($fl) ?></span>
                                <span class="cm-flag-desc"><?= h($fd) ?></span>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="pc-btn-primary cm-salvar">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>
