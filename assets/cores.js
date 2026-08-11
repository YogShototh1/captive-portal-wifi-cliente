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
    var palco = document.getElementById('cm-palco');
    var fone  = document.getElementById('cm-fone');
    var pronto = false;

    // Corpo do iPhone: 414x868 (tela de 390x844 + 12px de moldura). A tela usa
    // a viewport CSS real do aparelho; aqui so achamos o fator p/ o conjunto
    // caber, sem passar de 1 — ampliar deixaria a previa maior que o real.
    var LARG = 414, ALT = 868;
    function ajustarEscala() {
        if (!palco) return;
        var pai = palco.parentNode, cs = getComputedStyle(pai);
        var caixa = pai.getBoundingClientRect();
        // Desconta o padding real do container (a faixa de baixo e maior, por
        // causa da dica) em vez de assumir um valor fixo.
        var livreL = caixa.width  - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        var livreA = caixa.height - parseFloat(cs.paddingTop)  - parseFloat(cs.paddingBottom);
        if (livreL <= 0 || livreA <= 0) return;
        var e = Math.min(livreL / LARG, livreA / ALT, 1);
        // O PALCO fica com o tamanho ja reduzido (participa do layout e
        // centraliza sozinho); quem encolhe e o APARELHO INTEIRO — moldura,
        // tela e iframe juntos — ancorado no canto. Escalar o palco em si o
        // deixava maior que a moldura e o grid o alinhava ao topo.
        palco.style.width  = (LARG * e).toFixed(2) + 'px';
        palco.style.height = (ALT  * e).toFixed(2) + 'px';
        if (fone) fone.style.transform = 'scale(' + e.toFixed(4) + ')';
    }
    addEventListener('resize', ajustarEscala);
    // ResizeObserver em vez de depender so do rAF ao abrir: dispara assim que a
    // moldura ganha tamanho, inclusive quando o modal passa de oculto p/ visivel.
    if (window.ResizeObserver && palco) new ResizeObserver(ajustarEscala).observe(palco.parentNode);

    // O 'cd-pronto' do iframe pode chegar ANTES deste script rodar (iframe em
    // cache) e se perder — a previa nunca pintaria. O load cobre esse caso; se
    // ja carregou, o load nao dispara mais e o readyState resolve.
    frame.addEventListener('load', function () { pronto = true; enviar(null); });
    try {
        if (frame.contentDocument && frame.contentDocument.readyState === 'complete') pronto = true;
    } catch (err) { /* iframe ainda carregando */ }

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
        frame.contentWindow.postMessage(estado(destaque), location.origin);
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
        // So da p/ medir a moldura depois de o modal virar visivel.
        ajustarEscala();
        requestAnimationFrame(ajustarEscala);
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
        // Iframe e painel sao sempre da mesma origem (src relativo). Mensagem
        // de qualquer outra origem nao tem o que fazer aqui.
        if (e.origin !== location.origin) return;
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
