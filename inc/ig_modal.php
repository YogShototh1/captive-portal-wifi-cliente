<?php
// Modal "URL de Instagram": monta a página-ponte que vira o destino pós-anúncio.
//
// A prévia é a PÁGINA REAL (ig.php?previa=1 num iframe): o que o comprador vê
// aqui dentro da moldura de iPhone é exatamente o que o cliente dele recebe.
// Mesmo desenho do inc/cores_avancado.php — quem já mexeu na tela de login
// reconhece o gesto.
//
// Incluído por painel.php e admin_leads.php. Espera no escopo:
//   $ig (ig_get)   $csrf   $rotAtivo   $igClienteId (int|null) — só no admin
$igCores = [
    'bg'     => 'Fundo da tela',
    'card'   => 'Cartão do perfil',
    'btn'    => 'Botão (cor principal)',
    'btn2'   => 'Botão (segunda cor do degradê)',
    'btnfg'  => 'Texto do botão',
    'fg'     => 'Título',
    'fg2'    => 'Textos secundários',
    'border' => 'Bordas',
];
$igFlags = [
    'logo'    => ['Mostrar a logo',        'A mesma logo que você enviou para a tela de login.'],
    'cartao'  => ['Cartão do perfil',      'Bloco com o ícone e o @. Desligado, fica só o título e o botão.'],
    'sombra'  => ['Sombra',                'Sombra projetada sob o cartão e o botão.'],
    'manchas' => ['Manchas de luz',        'Dois borrões coloridos no fundo, nas cores do botão.'],
    'grad'    => ['Degradê no botão',      'Desligado, o botão usa a cor principal chapada.'],
    'redondo' => ['Cantos arredondados',   'Desligado, tudo fica com canto reto.'],
    'copiar'  => ['Botão "copiar @"',      'Salva quem não conseguir abrir o Instagram pelo botão.'],
];
$igCid  = isset($igClienteId) ? (int) $igClienteId : null;
$igHash = ig_hash((string) $rotAtivo);
?>
<div class="pc-modal pc-cores-modal" id="ig-modal" aria-hidden="true">
    <div class="pc-modal-backdrop" data-close></div>
    <div class="pc-modal-card glow-card cm-card-wrap">
        <span class="glow-fx" aria-hidden="true"></span>
        <div class="glow-body">
            <div class="pc-modal-head">
                <h2 class="pc-modal-title">Página de Instagram</h2>
                <button type="button" class="pc-modal-x" data-close aria-label="Fechar">&times;</button>
            </div>

            <form method="post" action="api/set_ig.php" class="cm-form pc-modal-body" id="ig-form">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <?php if ($igCid !== null): ?><input type="hidden" name="cliente_id" value="<?= $igCid ?>"><?php endif; ?>
                <input type="hidden" name="roteador" value="<?= h((string) $rotAtivo) ?>">
                <!-- Sentinela: diz ao servidor que as flags vieram neste POST
                     (checkbox desmarcado não é enviado). -->
                <input type="hidden" name="tem_flags" value="1">

                <div class="cm-previa">
                    <div class="cm-palco" id="ig-palco">
                        <div class="cm-fone" id="ig-fone">
                            <div class="cm-btn-lat cm-btn-sil" aria-hidden="true"></div>
                            <div class="cm-btn-lat cm-btn-vol1" aria-hidden="true"></div>
                            <div class="cm-btn-lat cm-btn-vol2" aria-hidden="true"></div>
                            <div class="cm-btn-lat cm-btn-pwr" aria-hidden="true"></div>
                            <div class="cm-tela">
                                <iframe id="ig-frame" src="ig.php?previa=1&amp;r=<?= h($igHash) ?>" title="Prévia da página de Instagram"></iframe>
                                <div class="cm-ilha" aria-hidden="true"></div>
                                <div class="cm-home" aria-hidden="true"></div>
                            </div>
                        </div>
                    </div>
                    <p class="cm-dica">É esta a página que o cliente vê ao sair do anúncio</p>
                </div>

                <div class="cm-lado">
                    <div class="cm-abas" role="tablist">
                        <button type="button" class="cm-aba atual" data-painel="conteudo">Conteúdo</button>
                        <button type="button" class="cm-aba" data-painel="cores">Cores</button>
                        <button type="button" class="cm-aba" data-painel="efeitos">Efeitos</button>
                    </div>

                    <div class="cm-painel atual" data-painel="conteudo">
                        <label class="ig-campo">
                            <span class="ig-campo-nome">Perfil do Instagram</span>
                            <input type="text" name="perfil" id="ig-perfil" class="pc-dst-input" spellcheck="false"
                                   placeholder="seuperfil" value="<?= h($ig['perfil']) ?>" maxlength="120" required>
                            <span class="ig-campo-dica">Pode colar o link inteiro — a gente tira o @ dele.</span>
                        </label>
                        <?php foreach ([
                            ['titulo',  'Título',            'Siga a gente no Instagram', 60],
                            ['sub',     'Frase de abertura', 'Wi-Fi liberado! Aproveite…', 160],
                            ['chamada', 'Texto do cartão',   'Novidades, promoções…',      160],
                            ['botao',   'Texto do botão',    'Abrir no Instagram',         40],
                            ['rodape',  'Rodapé (opcional)', 'Nome da sua loja',           60],
                        ] as [$ck, $cl, $cp, $cm]): ?>
                        <label class="ig-campo">
                            <span class="ig-campo-nome"><?= h($cl) ?></span>
                            <input type="text" name="<?= $ck ?>" class="pc-dst-input ig-txt" data-campo="<?= $ck ?>"
                                   placeholder="<?= h($cp) ?>" value="<?= h($ig[$ck]) ?>" maxlength="<?= $cm ?>">
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="cm-painel" data-painel="cores">
                        <?php foreach ($igCores as $ck => $cl): ?>
                        <label class="pc-cor-row cm-row" data-row="<?= $ck ?>">
                            <span class="pc-cor-nome"><?= h($cl) ?></span>
                            <span class="pc-cor-ctrls">
                                <input type="color" class="pc-cor-input ig-color" name="cor_<?= $ck ?>" value="<?= h($ig['cores'][$ck]) ?>" data-cor="<?= $ck ?>" data-hex="ighex-<?= $ck ?>" aria-label="<?= h($cl) ?>">
                                <input type="text" class="pc-cor-hex" id="ighex-<?= $ck ?>" value="<?= h($ig['cores'][$ck]) ?>" maxlength="7" spellcheck="false" data-color="<?= $ck ?>">
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="cm-painel" data-painel="efeitos">
                        <?php foreach ($igFlags as $fk => [$fl, $fd]): ?>
                        <label class="cm-flag">
                            <input type="checkbox" class="ig-check" name="est_<?= $fk ?>" value="1" data-flag="<?= $fk ?>" <?= !empty($ig['estilo'][$fk]) ? 'checked' : '' ?>>
                            <span class="cm-flag-tx">
                                <span class="cm-flag-nome"><?= h($fl) ?></span>
                                <span class="cm-flag-desc"><?= h($fd) ?></span>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <p class="ig-aviso">Ao salvar, esta página vira o destino do seu Wi-Fi.</p>
                    <button type="submit" class="pc-btn-primary cm-salvar">Salvar e usar como destino</button>
                </div>
            </form>
        </div>
    </div>
</div>
