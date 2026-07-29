<?php
// Botao "avancado" + modal de cores com esboco clicavel do login do hotspot.
// Incluido por painel.php e admin_leads.php DENTRO do card "Cores da tela de
// login". Espera no escopo:
//   $cores  (array cores_get do roteador ativo)
//   $csrf   (token)
//   $rotAtivo (identity do roteador)
//   $coresClienteId (int|null) — so no admin, vira <input cliente_id>
$rotulos = [
    'bg'      => 'Fundo da tela',
    'surface' => 'Cartão',
    'primary' => 'Cor principal (degradê, links, foco)',
    'accent'  => 'Cor de destaque (degradê)',
    'fg'      => 'Título / texto principal',
    'fg2'     => 'Texto secundário',
    'field'   => 'Campo do número (fundo)',
    'border'  => 'Borda dos campos',
    'btnfg'   => 'Texto do botão',
];
$cid = isset($coresClienteId) ? (int) $coresClienteId : null;
// Variaveis CSS do esboco, alimentadas pelos <input>.
$styleVars = '';
foreach (cores_padrao() as $k => $_) {
    $styleVars .= '--m-' . $k . ':' . h($cores[$k]) . ';';
}
?>
<div class="pc-modal pc-cores-modal" id="cores-modal" aria-hidden="true">
    <div class="pc-modal-backdrop" data-close></div>
    <div class="pc-modal-card glow-card cm-card-wrap">
        <span class="glow-fx" aria-hidden="true"></span>
        <div class="glow-body">
            <div class="pc-modal-head">
                <h2 class="pc-modal-title">Cores da tela de login</h2>
                <button type="button" class="pc-modal-x" data-close aria-label="Fechar">&times;</button>
            </div>

            <form method="post" action="api/set_cores.php" class="cm-form pc-modal-body">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <?php if ($cid !== null): ?><input type="hidden" name="cliente_id" value="<?= $cid ?>"><?php endif; ?>
                <input type="hidden" name="roteador" value="<?= h((string) $rotAtivo) ?>">

                <!-- ESBOCO: mini tela de login. Cada zona tem data-cor; clicar
                     abre o seletor da cor correspondente. Cores ao vivo via --m-*. -->
                <div class="cm-scene" id="cm-scene" data-cor="bg" style="<?= $styleVars ?>">
                    <p class="cm-hint">Clique num elemento para mudar a cor</p>
                    <div class="cm-card" data-cor="surface">
                        <div class="cm-logo" data-cor="primary" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h.01"/><path d="M2 8.82a15 15 0 0 1 20 0"/><path d="M5 12.86a10 10 0 0 1 14 0"/><path d="M8.5 16.43a5 5 0 0 1 7 0"/></svg>
                        </div>
                        <div class="cm-title" data-cor="fg">Bem-vindo</div>
                        <div class="cm-sub" data-cor="fg2">Conecte-se ao Wi-Fi grátis</div>
                        <div class="cm-fieldwrap" data-cor="border">
                            <div class="cm-field" data-cor="field">(11) 90000-0000</div>
                        </div>
                        <div class="cm-btn" data-cor="accent">
                            <span class="cm-btn-tx" data-cor="btnfg">Liberar WiFi</span>
                        </div>
                    </div>
                </div>

                <!-- LISTA: todas as cores, cada uma editavel -->
                <div class="cm-list">
                    <?php foreach ($rotulos as $ck => $cl): ?>
                    <label class="pc-cor-row cm-row" data-row="<?= $ck ?>">
                        <span class="pc-cor-nome"><?= h($cl) ?></span>
                        <span class="pc-cor-ctrls">
                            <input type="color" class="pc-cor-input cm-color" name="<?= $ck ?>" value="<?= h($cores[$ck]) ?>" data-cor="<?= $ck ?>" data-hex="cmhex-<?= $ck ?>" aria-label="<?= h($cl) ?>">
                            <input type="text" class="pc-cor-hex" id="cmhex-<?= $ck ?>" value="<?= h($cores[$ck]) ?>" maxlength="7" spellcheck="false" data-color="<?= $ck ?>">
                        </span>
                    </label>
                    <?php endforeach; ?>
                    <button type="submit" class="pc-btn-primary cm-salvar">Salvar cores</button>
                </div>
            </form>
        </div>
    </div>
</div>
