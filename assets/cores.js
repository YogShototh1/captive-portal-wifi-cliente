/* Modal "avancado" da tela de login. A previa e a PAGINA REAL do hotspot num
   iframe (assets/portal_preview.html + assets/portal.css, os mesmos que vao no
   roteador). A conversa e por postMessage:
     painel -> iframe : {tipo:'cd-preview', cores, flags, destaque}
     iframe -> painel : {tipo:'cd-clique'|'cd-hover'|'cd-pronto', cor}
   A sincronia seletor<->#hex ja vem do abas.js (por classe). */
(function () {
    var modal = document.getElementById('cores-modal');
    var abrir = document.getElementById('cores-avancado-abrir');
    if (!modal || !abrir) return;

    // O modal nasce dentro da aba (.pc-tela), que tem transform na animacao de
    // entrada — e transform prende position:fixed no ancestral em vez da
    // viewport. Movendo p/ o body, o fixed volta a ancorar na tela.
    if (modal.parentNode !== document.body) document.body.appendChild(modal);

    var frame = document.getElementById('cm-frame');
    var pronto = false;

    function estado(destaque) {
        var cores = {}, flags = {};
        modal.querySelectorAll('.cm-color').forEach(function (i) { cores[i.getAttribute('data-cor')] = i.value; });
        modal.querySelectorAll('.cm-check').forEach(function (c) { flags[c.getAttribute('data-flag')] = c.checked ? 1 : 0; });
        var msg = { tipo: 'cd-preview', cores: cores, flags: flags };
        if (destaque !== undefined) msg.destaque = destaque;
        return msg;
    }
    function enviar(destaque) {
        if (!pronto || !frame.contentWindow) return;
        frame.contentWindow.postMessage(estado(destaque), '*');
    }

    // --- abrir / fechar ---
    function fechar() {
        modal.classList.remove('aberto');
        modal.setAttribute('aria-hidden', 'true');
        document.documentElement.style.overflow = '';
    }
    abrir.addEventListener('click', function () {
        modal.classList.add('aberto');
        modal.setAttribute('aria-hidden', 'false');
        document.documentElement.style.overflow = 'hidden';
        enviar(null);
    });
    modal.addEventListener('click', function (e) {
        if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-close')) fechar();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('aberto')) fechar();
    });

    // --- abas Cores / Efeitos ---
    modal.querySelectorAll('.cm-aba').forEach(function (b) {
        b.addEventListener('click', function () {
            var alvo = b.getAttribute('data-painel');
            modal.querySelectorAll('.cm-aba').forEach(function (x) { x.classList.toggle('atual', x === b); });
            modal.querySelectorAll('.cm-painel').forEach(function (p) {
                p.classList.toggle('atual', p.getAttribute('data-painel') === alvo);
            });
        });
    });

    // --- qualquer mudanca repinta a previa ---
    modal.addEventListener('input', function (e) {
        var t = e.target;
        if (!t.classList) return;
        if (t.classList.contains('pc-cor-hex')) {
            // Sincroniza aqui mesmo em vez de contar com o abas.js ter rodado
            // antes: enviar() le do input[type=color], que ficaria atrasado.
            var v = t.value.trim();
            if (!/^#[0-9a-fA-F]{6}$/.test(v)) return;
            var ci = modal.querySelector('.cm-color[data-cor="' + t.getAttribute('data-color') + '"]');
            if (ci) ci.value = v;
            enviar();
        } else if (t.classList.contains('cm-color')) {
            enviar();
        }
    });
    modal.addEventListener('change', function (e) {
        if (e.target.classList && e.target.classList.contains('cm-check')) enviar();
    });

    // --- realce reciproco lista <-> previa ---
    function realceLinha(cor, on) {
        var row = cor && modal.querySelector('.cm-row[data-row="' + cor + '"]');
        if (!on) { modal.querySelectorAll('.cm-row.realce').forEach(function (r) { r.classList.remove('realce'); }); return; }
        if (row) { modal.querySelectorAll('.cm-row.realce').forEach(function (r) { r.classList.remove('realce'); }); row.classList.add('realce'); }
    }
    modal.querySelectorAll('.cm-row').forEach(function (row) {
        var cor = row.getAttribute('data-row');
        row.addEventListener('mouseenter', function () { enviar(cor); });
        row.addEventListener('mouseleave', function () { enviar(null); });
    });

    addEventListener('message', function (e) {
        var d = e.data;
        if (!d || !d.tipo) return;
        if (d.tipo === 'cd-pronto') { pronto = true; enviar(null); return; }
        if (d.tipo === 'cd-hover') { realceLinha(d.cor, !!d.cor); return; }
        if (d.tipo === 'cd-clique') {
            // Clique na previa: vai p/ a aba Cores, marca a linha e abre o seletor.
            var aba = modal.querySelector('.cm-aba[data-painel="cores"]');
            if (aba && !aba.classList.contains('atual')) aba.click();
            var row = modal.querySelector('.cm-row[data-row="' + d.cor + '"]');
            var input = modal.querySelector('.cm-color[data-cor="' + d.cor + '"]');
            if (row) {
                modal.querySelectorAll('.cm-row.alvo').forEach(function (r) { r.classList.remove('alvo'); });
                row.classList.add('alvo');
                row.scrollIntoView({ block: 'nearest' });
            }
            if (input) { try { input.showPicker(); } catch (err) { input.click(); } }
        }
    });
})();
