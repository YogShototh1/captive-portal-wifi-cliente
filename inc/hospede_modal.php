<?php
// Pop-up de cadastro/edição de hóspede. Mesma casca dos outros modais do painel
// (editar-modal, conexoes-modal): backdrop + glow-card + head/body.
//
// Espera vindo de quem inclui: $rotLista, $rotAtivo, $hospedes.
?>
<div class="pc-modal" id="hospede-modal" aria-hidden="true">
    <div class="pc-modal-backdrop" data-close></div>
    <div class="pc-modal-card glow-card">
        <span class="glow-fx" aria-hidden="true"></span>
        <div class="glow-body">
            <div class="pc-modal-head">
                <h3 class="pc-modal-title" id="hsp-titulo">Cadastrar hóspede</h3>
                <button type="button" class="pc-modal-x" data-close aria-label="Fechar">&times;</button>
            </div>
            <div class="pc-modal-body pc-editar-body">
                <label class="pc-ed-label">Nome do hóspede
                    <input type="text" id="hsp-nome" class="pc-dst-input" maxlength="120" placeholder="ex.: Maria Silva">
                </label>
                <div class="pc-ed-row">
                    <label class="pc-ed-label">Quarto
                        <input type="text" id="hsp-quarto" class="pc-dst-input" maxlength="20" placeholder="ex.: 12">
                    </label>
                    <label class="pc-ed-label">Número de celular
                        <input type="tel" id="hsp-tel" class="pc-dst-input" inputmode="numeric" placeholder="48999999999">
                    </label>
                </div>
                <div class="pc-ed-row">
                    <label class="pc-ed-label">Entrada (check-in)
                        <input type="date" id="hsp-entrada" class="pc-dst-input">
                    </label>
                    <label class="pc-ed-label">Diárias
                        <input type="number" id="hsp-dias" class="pc-dst-input" min="1" max="365" inputmode="numeric" value="1">
                    </label>
                    <label class="pc-ed-label">Hora da saída
                        <input type="time" id="hsp-hora" class="pc-dst-input" value="12:00">
                    </label>
                </div>
                <?php if (count($rotLista) > 1): ?>
                <label class="pc-ed-label">Roteador
                    <select id="hsp-roteador" class="pc-dst-input">
                        <?php foreach ($rotLista as $rt): ?>
                        <option value="<?= h($rt) ?>"<?= $rotAtivo === $rt ? ' selected' : '' ?>><?= h($rt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>
                <p class="hsp-saida-previa" id="hsp-previa"></p>
                <p class="pc-anuncio-msg err" id="hsp-erro" style="display:none"></p>
                <button type="button" class="pc-btn-primary" id="hsp-salvar">Salvar</button>
            </div>
        </div>
    </div>
</div>

<?php
// Pop-up "Hóspede já cadastrado": escolher quem já esteve aqui e abrir o
// cadastro com nome e número prontos.
//
// A lista sai de $hospedes, que a tela já carregou — sem endpoint novo e sem
// consulta extra (ver hospedes_unicos() em inc/util.php).
$hspEscolha = hospedes_unicos($hospedes);
?>
<div class="pc-modal" id="hsp-lista-modal" aria-hidden="true">
    <div class="pc-modal-backdrop" data-close></div>
    <div class="pc-modal-card glow-card">
        <span class="glow-fx" aria-hidden="true"></span>
        <div class="glow-body">
            <div class="pc-modal-head">
                <h3 class="pc-modal-title">Hóspede já cadastrado</h3>
                <button type="button" class="pc-modal-x" data-close aria-label="Fechar">&times;</button>
            </div>
            <div class="pc-modal-body">
                <input type="text" id="hsp-busca" class="pc-dst-input" placeholder="Filtrar por nome ou número" aria-label="Filtrar hóspedes">
                <div class="hsp-lista" id="hsp-lista">
                    <?php if (!$hspEscolha): ?>
                    <p class="pc-anuncio-desc">Ninguém cadastrado ainda. Use “Cadastrar hóspede” na primeira vez.</p>
                    <?php else: foreach ($hspEscolha as $g): ?>
                    <button type="button" class="hsp-item"
                            data-nome="<?= h($g['nome']) ?>"
                            data-tel="<?= h($g['telefone']) ?>"
                            data-busca="<?= h(mb_strtolower($g['nome'] . ' ' . $g['telefone'])) ?>">
                        <span class="hsp-item-nome"><?= h($g['nome']) ?></span>
                        <span class="hsp-item-tel"><?= h($g['telefone']) ?></span>
                        <span class="hsp-item-ult">última entrada <?= h(date('d/m/Y', strtotime((string) $g['entrada_em']))) ?></span>
                    </button>
                    <?php endforeach; endif; ?>
                </div>
                <p class="pc-anuncio-desc hsp-lista-vazia" id="hsp-lista-vazia" style="display:none">Nenhum hóspede com esse nome ou número.</p>
            </div>
        </div>
    </div>
</div>
