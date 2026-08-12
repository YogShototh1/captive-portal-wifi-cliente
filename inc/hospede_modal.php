<?php
// Pop-up de cadastro/edição de hóspede. Mesma casca dos outros modais do painel
// (editar-modal, conexoes-modal): backdrop + glow-card + head/body.
//
// Espera vindo de quem inclui: $rotLista, $rotAtivo.
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
            <div class="pc-modal-body pc-editar-body hsp-body">
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
