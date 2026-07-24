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

    var carregou = false;
    function carregar(pagina) {
        out.innerHTML = '<p class="pc-anuncio-desc">Carregando…</p>';
        if (nav) nav.innerHTML = '';
        fetch(EP + (EP.indexOf('?') >= 0 ? '&' : '?') + 'pagina=' + (pagina || 1) + '&por_pagina=50',
              { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { out.innerHTML = '<p class="pc-anuncio-desc">Erro ao carregar o log.</p>'; return; }
                if (!d.log || !d.log.length) { out.innerHTML = '<p class="pc-anuncio-desc">Nenhum acesso registrado ainda. (o roteador precisa estar com o leadsync novo e ter tráfego)</p>'; return; }
                var html = '<div class="pc-conex-head pc-log-head"><span>Data e hora</span><span>Cliente</span><span>IP</span><span>Destino</span><span>Aparelho</span></div><ul class="pc-conex-list">';
                d.log.forEach(function (a) {
                    var destino = (a.host && a.host !== '') ? esc(a.host) : esc(a.ip_destino);
                    html += '<li class="pc-log-row"><span class="pc-conex-data">' + fmtData(a.visto_em) + '</span>' +
                            '<span class="pc-log-cli">' + waLabel(a.telefone, a.nome) + '</span>' +
                            '<span class="pc-conex-ap">' + esc(a.ip_cliente || '—') + '</span>' +
                            '<span class="pc-acesso-dst-cel"><span class="pc-acesso-dst" title="' + esc(a.ip_destino) + '">' + destino + '</span>' + COPIA_BTN + '</span>' +
                            '<span class="pc-conex-ap">' + esc(a.dispositivo || '—') + '</span></li>';
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
        carregou = true;
    }
    var item = document.querySelector('.pc-side-item[data-aba="log"]');
    if (item) item.addEventListener('click', abrir);
    // Se a página já abriu direto na aba Log (via #log), carrega.
    if ((location.hash || '').replace('#', '') === 'log') abrir();
})();
