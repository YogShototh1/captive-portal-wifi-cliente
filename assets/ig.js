/* Modal "URL de Instagram". A previa e a PAGINA REAL (ig.php?previa=1) num
   iframe: o que o comprador ve dentro da moldura e o que o cliente dele recebe
   ao sair do anuncio.

   Mesmo desenho do assets/cores.js — a diferenca e que aqui vao TEXTOS junto
   com cores e flags, entao a mensagem carrega o estado inteiro da pagina:
     painel -> iframe : {tipo:'ig', perfil, titulo, sub, chamada, botao, rodape, cores, estilo}
     iframe -> painel : {tipo:'ig-pronto'} */
(function () {
    var modal = document.getElementById('ig-modal');
    var abrir = document.getElementById('ig-abrir');
    if (!modal || !abrir) return;

    // O modal nasce dentro da aba (.pc-tela), que tem transform na animacao de
    // entrada — e transform prende position:fixed no ancestral em vez da
    // viewport. Movendo p/ o body, o fixed volta a ancorar na tela.
    if (modal.parentNode !== document.body) document.body.appendChild(modal);

    var frame = document.getElementById('ig-frame');
    var palco = document.getElementById('ig-palco');
    var fone  = document.getElementById('ig-fone');
    var pronto = false;

    // Corpo do iPhone: 414x868 (tela de 390x844 + 12px de moldura). A tela usa
    // a viewport CSS real do aparelho; aqui so achamos o fator p/ o conjunto
    // caber, sem passar de 1 — ampliar deixaria a previa maior que o real.
    var LARG = 414, ALT = 868;
    function ajustarEscala() {
        if (!palco) return;
        var pai = palco.parentNode, cs = getComputedStyle(pai);
        var caixa = pai.getBoundingClientRect();
        var livreL = caixa.width  - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        var livreA = caixa.height - parseFloat(cs.paddingTop)  - parseFloat(cs.paddingBottom);
        if (livreL <= 0 || livreA <= 0) return;
        var e = Math.min(livreL / LARG, livreA / ALT, 1);
        palco.style.width  = (LARG * e).toFixed(2) + 'px';
        palco.style.height = (ALT  * e).toFixed(2) + 'px';
        if (fone) fone.style.transform = 'scale(' + e.toFixed(4) + ')';
    }
    addEventListener('resize', ajustarEscala);
    if (window.ResizeObserver && palco) new ResizeObserver(ajustarEscala).observe(palco.parentNode);

    // O 'ig-pronto' do iframe pode chegar ANTES deste script rodar (iframe em
    // cache) e se perder — a previa nunca pintaria. O load cobre esse caso; se
    // ja carregou, o load nao dispara mais e o readyState resolve.
    frame.addEventListener('load', function () { pronto = true; enviar(); });
    try {
        if (frame.contentDocument && frame.contentDocument.readyState === 'complete') pronto = true;
    } catch (err) { /* iframe ainda carregando */ }

    function val(nome) {
        var el = modal.querySelector('[name="' + nome + '"]');
        return el ? el.value : '';
    }
    function estado() {
        var cores = {}, estilo = {};
        modal.querySelectorAll('.ig-color').forEach(function (i) { cores[i.getAttribute('data-cor')] = i.value; });
        modal.querySelectorAll('.ig-check').forEach(function (c) { estilo[c.getAttribute('data-flag')] = c.checked ? 1 : 0; });
        // O perfil aceita o link colado inteiro; a previa mostra so o @, igual
        // ao que o servidor vai gravar.
        var p = val('perfil').trim().replace(/^@/, '');
        var m = p.match(/instagram\.com\/([A-Za-z0-9._]{1,30})/i);
        if (m) p = m[1];
        return {
            tipo: 'ig', perfil: p,
            titulo: val('titulo'), sub: val('sub'), chamada: val('chamada'),
            botao: val('botao'), rodape: val('rodape'),
            cores: cores, estilo: estilo
        };
    }
    function enviar() {
        if (!pronto || !frame.contentWindow) return;
        frame.contentWindow.postMessage(estado(), '*');
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
        enviar();
    });
    modal.addEventListener('click', function (e) {
        if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-close')) fechar();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('aberto')) fechar();
    });

    // --- abas Conteudo / Cores / Efeitos ---
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
            var ci = modal.querySelector('.ig-color[data-cor="' + t.getAttribute('data-color') + '"]');
            if (ci) ci.value = v;
        }
        enviar();
    });
    modal.addEventListener('change', function (e) {
        if (e.target.classList && e.target.classList.contains('ig-check')) enviar();
    });

    addEventListener('message', function (e) {
        var d = e.data;
        if (d && d.tipo === 'ig-pronto') { pronto = true; enviar(); }
    });
})();
