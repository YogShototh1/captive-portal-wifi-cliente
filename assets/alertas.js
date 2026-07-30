/* Aba Alertas: pinta as notificacoes dos avisos que o comprador marcou e abre
   a lista de clientes de cada um. O que aparece vem do servidor — aqui so se
   monta a tela.
     api/alertas.php              -> avisos marcados, com a contagem
     api/alertas.php?detalhe=<id> -> a lista de clientes daquele aviso
     api/set_alertas.php          -> salva as flags (modal de urgencias) */
(function () {
    var box = document.getElementById('alertas-box');
    if (!box) return;
    var EP    = box.getAttribute('data-endpoint');
    var lista = document.getElementById('al-lista');

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    // Mesmo rotulo da tabela de leads: nome quando existe, senao o numero,
    // sempre abrindo o WhatsApp do numero.
    function waLink(tel, nome) {
        var label = esc((nome != null && nome !== '') ? nome : tel);
        var d = String(tel || '').replace(/\D/g, '');
        if (d && d.length <= 11) d = '55' + d;
        if (!d) return label;
        return '<a class="rel-wa" href="https://wa.me/' + d + '" target="_blank" rel="noopener">' + label + '</a>';
    }

    // Modais nascem dentro da aba (.pc-tela), que tem transform na animacao de
    // entrada — e transform prende position:fixed no ancestral. No body volta a
    // ancorar na viewport.
    ['alertas-modal', 'alerta-lista-modal'].forEach(function (id) {
        var m = document.getElementById(id);
        if (m && m.parentNode !== document.body) document.body.appendChild(m);
    });
    function abrir(m) {
        m.classList.add('aberto');
        m.setAttribute('aria-hidden', 'false');
        document.documentElement.style.overflow = 'hidden';
    }
    function fechar(m) {
        m.classList.remove('aberto');
        m.setAttribute('aria-hidden', 'true');
        document.documentElement.style.overflow = '';
    }
    ['alertas-modal', 'alerta-lista-modal'].forEach(function (id) {
        var m = document.getElementById(id);
        if (!m) return;
        m.addEventListener('click', function (e) {
            if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-close')) fechar(m);
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        ['alerta-lista-modal', 'alertas-modal'].forEach(function (id) {
            var m = document.getElementById(id);
            if (m && m.classList.contains('aberto')) fechar(m);
        });
    });

    // --- notificacoes ---
    var carregado = false;
    function carregar() {
        lista.innerHTML = '<p class="pc-anuncio-desc">Verificando…</p>';
        // Mostra o motivo quando o servidor manda um: "nao deu" sozinho nao
        // ajuda ninguem a entender o que aconteceu.
        var falhou = function (msg) {
            lista.innerHTML = '<p class="pc-anuncio-msg err">' + esc(msg || 'Não deu para carregar os alertas.') + '</p>';
        };
        fetch(EP, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.text(); })
            .then(function (t) {
                var d = null;
                try { d = JSON.parse(t); } catch (e) { throw new Error('resposta inesperada do servidor'); }
                if (!d || !d.ok) { falhou(d && d.erro); return; }
                if (!d.avisos || !d.avisos.length) {
                    lista.innerHTML = d.marcados
                        ? '<p class="al-vazio">Nada a relatar por aqui — nenhum dos avisos que você marcou aconteceu.</p>'
                        : '<p class="al-vazio">Você ainda não escolheu o que quer acompanhar. Use <b>Selecionar urgências</b>, ali no canto.</p>';
                    return;
                }
                var html = '';
                for (var i = 0; i < d.avisos.length; i++) {
                    var a = d.avisos[i];
                    // O texto vem com {n} no lugar do numero: so ele vira botao.
                    var num = a.lista
                        ? '<button type="button" class="al-num" data-id="' + esc(a.id) + '">' + a.n + '</button>'
                        : '<b class="al-num-fixo">' + a.n + '</b>';
                    html += '<article class="al-card al-' + esc(a.tom) + '">' +
                        '<span class="al-ico" aria-hidden="true"></span>' +
                        '<div class="al-tx"><span class="al-tit">' + esc(a.titulo) + '</span>' +
                        '<p class="al-msg">' + esc(a.texto).replace('{n}', num) + '</p></div>' +
                        '</article>';
                }
                lista.innerHTML = html;
            })
            .catch(function (e) { falhou(e && e.message); });
    }

    lista.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.al-num') : null;
        if (!b) return;
        var id = b.getAttribute('data-id');
        var m  = document.getElementById('alerta-lista-modal');
        var corpo = document.getElementById('alerta-lista-corpo');
        document.getElementById('alerta-lista-titulo').textContent = 'Carregando…';
        corpo.innerHTML = '<p class="pc-anuncio-desc">Buscando os clientes…</p>';
        abrir(m);
        // O endpoint ja vem com ?roteador=, mas nao custa nao depender disso.
        var url = EP + (EP.indexOf('?') >= 0 ? '&' : '?') + 'detalhe=' + encodeURIComponent(id);
        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { corpo.innerHTML = '<p class="pc-anuncio-msg err">Não deu para carregar a lista.</p>'; return; }
                document.getElementById('alerta-lista-titulo').textContent = d.titulo + ' (' + d.total + ')';
                if (!d.lista.length) { corpo.innerHTML = '<p class="al-vazio">Ninguém nesta lista agora.</p>'; return; }
                var html = '<ul class="al-clientes">';
                for (var i = 0; i < d.lista.length; i++) {
                    var c = d.lista[i];
                    html += '<li><span class="al-cli">' + waLink(c.telefone, c.nome) + '</span>' +
                            (c.detalhe ? '<span class="al-det">' + esc(c.detalhe) + '</span>' : '') + '</li>';
                }
                html += '</ul>';
                if (d.total > d.lista.length) {
                    html += '<p class="al-corte">Mostrando os ' + d.lista.length + ' primeiros de ' + d.total + '.</p>';
                }
                corpo.innerHTML = html;
            })
            .catch(function () { corpo.innerHTML = '<p class="pc-anuncio-msg err">Não deu para carregar a lista.</p>'; });
    });

    // --- modal das flags ---
    var abrirCfg = document.getElementById('alertas-config');
    var form     = document.getElementById('alertas-form');
    if (abrirCfg) {
        abrirCfg.addEventListener('click', function () { abrir(document.getElementById('alertas-modal')); });
    }
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var msg = document.getElementById('alertas-msg');
            var bt  = form.querySelector('.am-salvar');
            msg.textContent = ''; msg.className = 'am-msg';
            bt.disabled = true;
            fetch(form.getAttribute('action'), {
                method: 'POST', credentials: 'same-origin', body: new FormData(form)
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.ok) { msg.textContent = (d && d.erro) || 'Não deu para salvar.'; msg.className = 'am-msg err'; return; }
                    fechar(document.getElementById('alertas-modal'));
                    carregar();   // a tela ja reflete a escolha nova
                })
                .catch(function () { msg.textContent = 'Não deu para salvar. Tente de novo.'; msg.className = 'am-msg err'; })
                .then(function () { bt.disabled = false; });
        });
    }

    // Carrega quando a aba aparece (e nao no load da pagina, que somaria
    // consultas pesadas a toda visita ao painel).
    function talvezCarregar() {
        var tela = document.querySelector('.pc-tela[data-tela="alertas"]');
        if (!carregado && tela && tela.classList.contains('atual')) { carregado = true; carregar(); }
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-aba="alertas"]') : null;
        if (b) setTimeout(talvezCarregar, 60);
    });
    talvezCarregar();
})();
