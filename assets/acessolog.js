/* Aba "Log de acessos" (só admin): log geral por horário (mais recente em cima).
   Colunas: hora / nome ou telefone / ip / destino (host||ip) / aparelho.
   Config vem do #acessolog-box (data-endpoint + data-resolver). */
(function () {
    var box = document.getElementById('acessolog-box');
    if (!box) return;
    var EP  = box.getAttribute('data-endpoint');
    var RES = box.getAttribute('data-resolver');
    var out = document.getElementById('acessolog-lista');
    var nav = document.getElementById('acessolog-nav');
    if (!out) return;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    function fmtData(s) {
        var m = String(s == null ? '' : s).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        return m ? m[3] + '/' + m[2] + '/' + m[1] + ' - ' + m[4] + ':' + m[5] : esc(s);
    }
    function waLabel(tel, nome) {
        return esc((nome != null && nome !== '') ? nome : (tel || '—'));
    }
    var COPIA_BTN = '<button type="button" class="pc-copia" title="Copiar destino" aria-label="Copiar destino">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>';
    function copiarTexto(txt, btn) {
        function ok() { if (btn) { btn.classList.add('copiado'); setTimeout(function () { btn.classList.remove('copiado'); }, 1200); } }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(txt).then(ok, function () { fb(txt); ok(); });
        } else { fb(txt); ok(); }
        function fb(t) {
            var ta = document.createElement('textarea');
            ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
        }
    }
    out.addEventListener('click', function (e) {
        var c = e.target.closest ? e.target.closest('.pc-copia') : null;
        if (!c) return;
        var d = c.parentElement.querySelector('.pc-acesso-dst');
        if (d) copiarTexto(d.textContent, c);
    });

    // --- Recorte por data ---------------------------------------------------
    // Ao abrir: "de" na data do registro mais antigo e "até" hoje, ou seja, o
    // período inteiro já preenchido. O servidor manda a data mais antiga junto
    // com a primeira página (campo `primeira`), porque só ele sabe qual é.
    var elDe  = document.getElementById('log-de');
    var elAte = document.getElementById('log-ate');
    var elFil = document.getElementById('log-filtrar');
    var elTudo = document.getElementById('log-tudo');
    var elExp = document.getElementById('log-exportar');
    var primeira = null;

    function hoje() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    function periodo() {
        var q = '';
        if (elDe && elDe.value)  { q += '&de='  + encodeURIComponent(elDe.value); }
        if (elAte && elAte.value) { q += '&ate=' + encodeURIComponent(elAte.value); }
        return q;
    }

    function carregar(pagina) {
        out.innerHTML = '<p class="pc-anuncio-desc">Carregando…</p>';
        if (nav) nav.innerHTML = '';
        fetch(EP + (EP.indexOf('?') >= 0 ? '&' : '?') + 'pagina=' + (pagina || 1) + '&por_pagina=50' + periodo(),
              { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { out.innerHTML = '<p class="pc-anuncio-desc">Erro ao carregar o log.</p>'; return; }

                // Primeira resposta manda a data mais antiga: preenche os campos
                // só desta vez, para não desfazer o que o admin escolher depois.
                if (primeira === null && d.primeira) {
                    primeira = d.primeira;
                    if (elDe  && !elDe.value)  { elDe.value  = d.primeira; }
                    if (elAte && !elAte.value) { elAte.value = hoje(); }
                    if (elDe)  { elDe.min  = d.primeira; }
                    if (elAte) { elAte.max = hoje(); }
                }

                if (!d.log || !d.log.length) {
                    out.innerHTML = '<p class="pc-anuncio-desc">' +
                        (periodo() ? 'Nenhum acesso neste período.'
                                   : 'Nenhum acesso registrado ainda. (o roteador precisa estar com o leadsync novo e ter tráfego)') +
                        '</p>';
                    if (elExp) { elExp.disabled = true; }
                    return;
                }
                if (elExp) { elExp.disabled = false; }

                var html = '<div class="pc-log-head"><span>Data e hora</span><span>Cliente</span><span>IP</span><span>Destino</span><span>Serviço</span><span>Aparelho</span></div><ul class="pc-log-list">';
                d.log.forEach(function (a) {
                    var destino = (a.host && a.host !== '') ? esc(a.host) : esc(a.ip_destino);
                    html += '<li class="pc-log-row"><span class="pc-conex-data">' + fmtData(a.visto_em) + '</span>' +
                            '<span class="pc-log-cli">' + waLabel(a.telefone, a.nome) + '</span>' +
                            '<span class="pc-log-ip">' + esc(a.ip_cliente || '—') + '</span>' +
                            '<span class="pc-acesso-dst-cel"><span class="pc-acesso-dst" title="' + esc(a.ip_destino) + '">' + destino + '</span>' + COPIA_BTN + '</span>' +
                            '<span class="pc-acesso-org">' + esc(a.org || '—') + '</span>' +
                            '<span class="pc-log-ap">' + esc(a.dispositivo || '—') + '</span></li>';
                });
                out.innerHTML = html + '</ul>';
                if (nav && d.paginas > 1) {
                    nav.innerHTML = '<div class="pc-conex-nav">' +
                        (d.pagina > 1 ? '<button type="button" class="pc-conex-seta" data-pag="' + (d.pagina - 1) + '">&lsaquo;</button>' : '') +
                        '<span class="pc-pag-gap">' + d.pagina + '/' + d.paginas + '</span>' +
                        (d.pagina < d.paginas ? '<button type="button" class="pc-conex-seta" data-pag="' + (d.pagina + 1) + '">&rsaquo;</button>' : '') +
                        '</div>';
                }
            })
            .catch(function () { out.innerHTML = '<p class="pc-anuncio-desc">Erro ao carregar o log.</p>'; });
    }

    if (elFil)  { elFil.addEventListener('click', function () { carregar(1); }); }
    if (elTudo) {
        elTudo.addEventListener('click', function () {
            if (elDe)  { elDe.value  = primeira || ''; }
            if (elAte) { elAte.value = hoje(); }
            carregar(1);
        });
    }
    if (elExp) {
        // Baixa o MESMO recorte que está na tela, sem paginação. Navegação
        // direta em vez de fetch: assim o navegador cuida do download e do nome
        // do arquivo, que é o que o Content-Disposition manda.
        elExp.addEventListener('click', function () {
            location.href = EP + (EP.indexOf('?') >= 0 ? '&' : '?') + 'f=csv' + periodo();
        });
    }

    if (nav) {
        nav.addEventListener('click', function (e) {
            var s = e.target.closest ? e.target.closest('.pc-conex-seta') : null;
            if (s) carregar(parseInt(s.getAttribute('data-pag'), 10) || 1);
        });
    }

    // Resolve alguns hosts (reverse-DNS) e então carrega. Carrega na 1ª vez que a
    // aba Log é aberta (e recarrega a cada clique nela).
    function abrir() {
        if (RES) { fetch(RES, { credentials: 'same-origin' }).then(function () { carregar(1); }).catch(function () { carregar(1); }); }
        else carregar(1);
    }
    var item = document.querySelector('.pc-side-item[data-aba="log"]');
    if (item) item.addEventListener('click', abrir);
    // Se a página já abriu direto na aba Log (via #log), carrega.
    if ((location.hash || '').replace('#', '') === 'log') abrir();
})();
