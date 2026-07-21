/* Menu lateral + abas do painel (painel.php e admin_leads.php).
   Desktop: sidebar começa aberta (preferência fica em localStorage 'cd-menu').
   Celular (<=900px): drawer fechado por padrão; backdrop fecha.
   Abas: uma tela por vez (.pc-tela[data-tela]); a aba inicial vem da mensagem
   de retorno dos formulários (query string) ou do #hash — assim, depois de um
   envio, o usuário volta para a MESMA aba onde estava. */
(function () {
    var body = document.body;
    if (!document.querySelector('.pc-layout')) return;

    function isMobile() { return window.innerWidth <= 900; }

    // --- Sidebar aberta/fechada ---
    try {
        if (!isMobile() && localStorage.getItem('cd-menu') === 'fechado') {
            body.classList.add('pc-menu-fechado');
        }
    } catch (e) {}

    var btn = document.getElementById('pc-menu-btn');
    if (btn) {
        btn.addEventListener('click', function () {
            if (isMobile()) {
                body.classList.toggle('pc-menu-aberto');
            } else {
                body.classList.toggle('pc-menu-fechado');
                try {
                    localStorage.setItem('cd-menu', body.classList.contains('pc-menu-fechado') ? 'fechado' : 'aberto');
                } catch (e) {}
            }
        });
    }
    var backdrop = document.querySelector('.pc-side-backdrop');
    if (backdrop) {
        backdrop.addEventListener('click', function () { body.classList.remove('pc-menu-aberto'); });
    }

    // --- Abas ---
    var itens = document.querySelectorAll('.pc-side-item[data-aba]');
    var telas = document.querySelectorAll('.pc-tela[data-tela]');

    function ativar(aba) {
        var achou = false;
        for (var i = 0; i < telas.length; i++) {
            var hit = telas[i].getAttribute('data-tela') === aba;
            telas[i].classList.toggle('atual', hit);
            if (hit) achou = true;
        }
        if (!achou && aba !== 'painel') return ativar('painel'); // aba inexistente/sem permissão
        for (var j = 0; j < itens.length; j++) {
            itens[j].classList.toggle('atual', itens[j].getAttribute('data-aba') === aba);
        }
        if (isMobile()) body.classList.remove('pc-menu-aberto');
    }

    for (var k = 0; k < itens.length; k++) {
        itens[k].addEventListener('click', function () {
            var aba = this.getAttribute('data-aba');
            ativar(aba);
            try { history.replaceState(null, '', '#' + aba); } catch (e) {}
        });
    }

    // Aba inicial: mensagem de retorno na query string > #hash > painel.
    var qs = location.search;
    var mapa = [
        ['anuncio_', 'anuncio'],
        ['dst_', 'url'],
        ['tlim_', 'limites'],
        ['banda_', 'limites'],
        ['portal_', 'html']
    ];
    var inicial = 'painel';
    for (var m = 0; m < mapa.length; m++) {
        if (qs.indexOf(mapa[m][0]) !== -1) { inicial = mapa[m][1]; break; }
    }
    if (inicial === 'painel') {
        var h = (location.hash || '').replace('#', '');
        if (h) inicial = h;
    }
    ativar(inicial);

    // Troca de aba programática (usada pela opção "Informações" da tabela).
    window.cdAbrirAba = function (aba) {
        ativar(aba);
        try { history.replaceState(null, '', '#' + aba); } catch (e) {}
    };

    // Trocar de MikroTik (links ?r=) mantém a aba atual: anexa o #hash ao link.
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a.rt-item') : null;
        if (a && location.hash && a.href.indexOf('#') === -1) {
            a.href += location.hash;
        }
    }, true);
})();
