/* Pop-up "ver conexões" (paginado, setas ‹ ›).
   Mora fora do leads-live.js porque não é só da tabela de leads: a aba Ocupação
   da pousada abre o mesmo pop-up pelo mesmo botão. Liga no MODAL, não na
   tabela, e escuta o clique no documento — quem tiver um .pc-ver-conexoes com
   data-lead na página ganha o pop-up de graça.

   O endpoint vem do data-conexoes-endpoint do próprio #conexoes-modal. */
(function () {
    var modal = document.getElementById('conexoes-modal');
    if (!modal) return;
    var EP = modal.getAttribute('data-conexoes-endpoint');
    if (!EP) return;

    var modalTel   = document.getElementById('conexoes-tel');
    var modalLista = document.getElementById('conexoes-lista');
    var modalNav   = document.getElementById('conexoes-nav'); // setas FORA da área que corta
    if (!modalLista) return;
    var modalLead  = null; // lead aberto no modal (para as setas refazerem o fetch)
    var porPag     = 10;   // recalculado a cada abertura conforme a altura da tela

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function fmt(seg) {
        if (seg == null || seg < 0) return '—';
        seg = Math.floor(seg);
        return pad(Math.floor(seg / 3600)) + ':' + pad(Math.floor((seg % 3600) / 60)) + ':' + pad(seg % 60);
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    // "2026-07-06 14:05:56" -> "06/07/2026 - 14:05" (string pura, sem fuso do navegador).
    function fmtData(s) {
        var m = String(s == null ? '' : s).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        return m ? m[3] + '/' + m[2] + '/' + m[1] + ' - ' + m[4] + ':' + m[5] : String(s == null ? '' : s);
    }
    function fmtBytes(b) {
        b = parseInt(b, 10) || 0;
        if (b <= 0) return '—';
        var mb = b / 1048576;
        if (mb >= 1024) return (mb / 1024).toFixed(2).replace('.', ',') + ' GB';
        return (mb >= 100 ? Math.round(mb) : mb.toFixed(1).replace('.', ',')) + ' MB';
    }

    // Quantas linhas cabem SEM rolar. O modal (.pc-modal-body) não tem scroll:
    // a lista é paginada pra caber. Estimativa CONSERVADORA — parte fixa ~170px
    // (cabeçalho + header da lista + setas + margem). Linha: 46px no desktop;
    // no celular 62px, porque "120 MB" etc. quebram em 2 linhas na coluna estreita.
    function conexoesPorPagina() {
        var linha = window.innerWidth <= 600 ? 62 : 46;
        var util = Math.min(window.innerHeight * 0.8, window.innerHeight - 40) - 170;
        return Math.max(3, Math.min(15, Math.floor(util / linha)));
    }
    function fecharModal() {
        modal.classList.remove('aberto');
        modal.setAttribute('aria-hidden', 'true');
    }
    function abrirConexoes(leadId, pagina) {
        modalLead = leadId;
        modalLista.innerHTML = '<p class="pc-modal-info">Carregando…</p>';
        if (modalNav) modalNav.innerHTML = '';
        modal.classList.add('aberto');
        modal.setAttribute('aria-hidden', 'false');
        fetch(EP + '?lead_id=' + encodeURIComponent(leadId) + '&pagina=' + (pagina || 1) + '&por_pagina=' + porPag, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { modalLista.innerHTML = '<p class="pc-modal-info">' + esc((d && d.erro) || 'Erro ao carregar.') + '</p>'; return; }
                if (modalTel) modalTel.textContent = d.telefone || '';
                if (!d.conexoes || !d.conexoes.length) { modalLista.innerHTML = '<p class="pc-modal-info">Nenhuma conexão registrada.</p>'; return; }
                var html = '<div class="pc-conex-head"><span>Data e hora</span><span>Tempo online</span><span>Consumo</span><span>Dispositivo</span></div><ul class="pc-conex-list">';
                d.conexoes.forEach(function (c) {
                    html += '<li><span class="pc-conex-data">' + esc(fmtData(c.conectado_em)) + '</span>' +
                            '<span class="pc-conex-tempo">' + (c.segundos == null ? '—' : fmt(c.segundos)) + '</span>' +
                            '<span class="pc-conex-uso">' + fmtBytes(c.bytes) + '</span>' +
                            '<span class="pc-conex-ap">' + esc(c.dispositivo || '—') + '</span></li>';
                });
                html += '</ul>';
                modalLista.innerHTML = html;
                // Setas no container FIXO (fora do corpo com overflow): sempre visíveis,
                // mesmo se a lista estourar a estimativa e for cortada.
                if (modalNav) {
                    modalNav.innerHTML = d.paginas > 1
                        ? '<div class="pc-conex-nav">' +
                          (d.pagina > 1 ? '<button type="button" class="pc-conex-seta" data-pag="' + (d.pagina - 1) + '" aria-label="Página anterior">&lsaquo;</button>' : '') +
                          (d.pagina < d.paginas ? '<button type="button" class="pc-conex-seta" data-pag="' + (d.pagina + 1) + '" aria-label="Próxima página">&rsaquo;</button>' : '') +
                          '</div>'
                        : '';
                }
            }).catch(function () { modalLista.innerHTML = '<p class="pc-modal-info">Erro ao carregar.</p>'; });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.pc-ver-conexoes') : null;
        if (!btn) return;
        e.preventDefault();
        porPag = conexoesPorPagina(); // mede a tela na abertura; as setas reutilizam
        abrirConexoes(btn.getAttribute('data-lead'), 1);
    });
    if (modalNav) {
        modalNav.addEventListener('click', function (e) {
            var seta = e.target.closest ? e.target.closest('.pc-conex-seta') : null;
            if (seta && modalLead != null) abrirConexoes(modalLead, parseInt(seta.getAttribute('data-pag'), 10) || 1);
        });
    }
    modal.addEventListener('click', function (e) {
        if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-close')) fecharModal();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fecharModal(); });
})();
