/* Tabela de leads "ao vivo": relógio do tempo conectado, auto-refresh sem reload
   e edição inline do limite de tempo. Config vem do #leads-live (data-*). */
(function () {
    var root = document.getElementById('leads-live');
    if (!root) return;
    var EP_LEADS    = root.getAttribute('data-endpoint');
    var EP_LIMITE   = root.getAttribute('data-limite-endpoint');
    var EP_BANDA    = root.getAttribute('data-banda-endpoint');
    var EP_CONEXOES = root.getAttribute('data-conexoes-endpoint');
    var CSRF        = root.getAttribute('data-csrf');
    var PAGINA      = parseInt(root.getAttribute('data-pagina') || '1', 10);
    var POR_PAGINA  = parseInt(root.getAttribute('data-por-pagina') || '50', 10);
    var tbody       = root.querySelector('tbody');
    if (!tbody) return;

    var anchorMs = Date.now(); // referência do "agora" do servidor (reancora a cada poll)

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function fmt(seg) {
        if (seg == null || seg < 0) return '—';
        seg = Math.floor(seg);
        return pad(Math.floor(seg / 3600)) + ':' + pad(Math.floor((seg % 3600) / 60)) + ':' + pad(seg % 60);
    }
    // "2026-07-06 14:05:56" -> "06/07/2026 - 14:05" (string pura, sem fuso do navegador).
    function fmtData(s) {
        var m = String(s == null ? '' : s).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        return m ? m[3] + '/' + m[2] + '/' + m[1] + ' - ' + m[4] + ':' + m[5] : String(s == null ? '' : s);
    }
    function limiteTexto(v) { return (v === '' || v == null) ? 'sem limite' : (v + ' min'); }
    // Bytes -> "12,3 MB" / "1,05 GB" (espelho do fmt_bytes do PHP); vazio = '—'.
    function fmtBytes(b) {
        b = parseInt(b, 10) || 0;
        if (b <= 0) return '—';
        var mb = b / 1048576;
        if (mb >= 1024) return (mb / 1024).toFixed(2).replace('.', ',') + ' GB';
        return (mb >= 100 ? Math.round(mb) : mb.toFixed(1).replace('.', ',')) + ' MB';
    }
    function bandaTexto(v)  { return (v === '' || v == null) ? 'sem limite' : (v + ' Mbps'); }
    function setMetric(id, val) { var el = document.getElementById(id); if (el && val != null) el.textContent = val; }

    // Número da tabela vira link do WhatsApp (no celular e no computador).
    var WA_ICON = '<svg class="pc-wa-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>';
    function waNumero(tel) {
        var d = String(tel == null ? '' : tel).replace(/\D/g, '');
        if (d.length > 0 && d.length <= 11) d = '55' + d; // adiciona o código do Brasil se faltar
        return d;
    }
    // 1ª coluna: quadradinho com o ícone do WhatsApp (clicável -> wa.me) +
    // nome/número. Renomeado -> nome em cima e número pequeno embaixo.
    function telHTML(tel, nome) {
        var n = waNumero(tel);
        var icone = n
            ? '<a class="pc-wa-box" href="https://wa.me/' + n + '" target="_blank" rel="noopener" aria-label="WhatsApp">' + WA_ICON + '</a>'
            : '';
        var texto = (nome != null && nome !== '')
            ? '<span class="pc-lead-nome">' + esc(nome) + '</span><span class="pc-lead-tel">' + esc(tel) + '</span>'
            : '<span class="pc-lead-nome">' + esc(tel) + '</span>';
        return '<div class="pc-lead-cel">' + icone + '<span class="pc-lead-txt">' + texto + '</span></div>';
    }
    // Converte as células já renderizadas pelo servidor (data-tel/data-nome).
    var rows0 = tbody.querySelectorAll('tr[data-id]');
    for (var ct = 0; ct < rows0.length; ct++) {
        var td0 = rows0[ct].querySelector('td:first-child');
        if (!td0 || td0.querySelector('.pc-lead-cel')) continue;
        var tel0 = rows0[ct].getAttribute('data-tel') || (td0.textContent || '').trim();
        if (tel0) td0.innerHTML = telHTML(tel0, rows0[ct].getAttribute('data-nome') || '');
    }
    function displayTempo(l) {
        if (l.online) return fmt(l.elapsed);
        if (l.segundos_conectado != null) return fmt(l.segundos_conectado);
        return '—';
    }
    function esc(s) {
        if (s == null) return '';
        return String(s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    // --- Relógio ao vivo (a cada 1s): só corre nas linhas online ---
    setInterval(function () {
        var extra = Math.floor((Date.now() - anchorMs) / 1000);
        var rows = tbody.querySelectorAll('tr[data-online="1"]');
        for (var i = 0; i < rows.length; i++) {
            var cell = rows[i].querySelector('.pc-tempo');
            if (!cell) continue;
            var base = parseInt(rows[i].getAttribute('data-elapsed') || '0', 10);
            cell.textContent = fmt(base + extra);
        }
    }, 1000);

    // --- Edição inline dos limites (tempo e banda, mesma mecânica) ---
    var EDITAVEIS = {
        limite: { sel: 'pc-limite', attr: 'data-limite', ep: EP_LIMITE, key: 'limite', ph: 'min',  texto: limiteTexto },
        banda:  { sel: 'pc-banda',  attr: 'data-banda',  ep: EP_BANDA,  key: 'banda',  ph: 'Mbps', texto: bandaTexto }
    };
    tbody.addEventListener('click', function (e) {
        if (!e.target.closest) return;
        var cell = e.target.closest('.pc-limite, .pc-banda');
        if (!cell || cell.classList.contains('editing')) return;
        var cfg = cell.classList.contains('pc-banda') ? EDITAVEIS.banda : EDITAVEIS.limite;
        if (!cfg.ep) return;
        var tr = cell.closest('tr');
        var id = tr.getAttribute('data-id');
        var atual = tr.getAttribute(cfg.attr) || '';
        cell.classList.add('editing');
        cell.innerHTML = '';
        var inp = document.createElement('input');
        inp.type = 'number'; inp.min = '0'; inp.placeholder = cfg.ph; inp.value = atual;
        cell.appendChild(inp);
        inp.focus(); inp.select();
        var done = false;
        function fim(texto) { done = true; cell.classList.remove('editing'); cell.textContent = texto; }
        function salvar() {
            if (done) return;
            var val = inp.value.trim();
            var valor = (val === '') ? null : Math.max(0, parseInt(val, 10) || 0);
            done = true;
            var body = { csrf: CSRF, id: parseInt(id, 10) };
            body[cfg.key] = valor;
            fetch(cfg.ep, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            }).then(function (r) { return r.json(); }).then(function (d) {
                var novo = (d && d.ok) ? d[cfg.key] : valor;
                var v = (novo == null ? '' : novo);
                tr.setAttribute(cfg.attr, v);
                cell.classList.remove('editing');
                cell.textContent = cfg.texto(v);
            }).catch(function () {
                cell.classList.remove('editing');
                cell.textContent = cfg.texto(atual);
            });
        }
        inp.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); salvar(); }
            else if (ev.key === 'Escape') { ev.preventDefault(); fim(cfg.texto(atual)); }
        });
        inp.addEventListener('blur', salvar);
    });

    // Célula da conexão: ícone (abre "ver conexões") + data em cima / hora embaixo.
    var GLOBO = '<svg class="pc-conex-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>';
    function conexHTML(l) {
        var dh = fmtData(l.conectado_em).split(' - ');
        return '<div class="pc-conex-cel">' +
            '<button type="button" class="pc-ver-conexoes" data-lead="' + l.id + '" aria-label="Ver conexões">' + GLOBO +
            '<span class="pc-total">' + (l.total_conexoes || 1) + '</span></button>' +
            '<div class="pc-dh"><span class="pc-data">' + esc(dh[0] || '') + '</span><span class="pc-hora">' + esc(dh[1] || '') + '</span></div>' +
            '</div>';
    }

    // --- Constrói uma linha nova (lead que acabou de conectar) ---
    function buildRow(l) {
        var tr = document.createElement('tr');
        tr.setAttribute('data-id', l.id);
        tr.setAttribute('data-online', l.online ? '1' : '0');
        tr.setAttribute('data-elapsed', l.elapsed);
        tr.setAttribute('data-limite', (l.tempo_limite_min == null ? '' : l.tempo_limite_min));
        tr.setAttribute('data-banda', (l.banda_limite == null ? '' : l.banda_limite));
        tr.setAttribute('data-total', l.total_conexoes || 1);
        tr.setAttribute('data-tel', l.telefone || '');
        tr.setAttribute('data-nome', l.nome || '');
        function td(html, cls) { var t = document.createElement('td'); if (cls) t.className = cls; t.innerHTML = html; return t; }
        tr.appendChild(td(telHTML(l.telefone, l.nome)));
        tr.appendChild(td(esc(l.ip) || '—'));
        tr.appendChild(td(esc(l.dispositivo) || '—', 'pc-aparelho'));
        tr.appendChild(td(conexHTML(l)));
        tr.appendChild(td('<span class="pc-dot"></span><span class="pc-tempo">' + displayTempo(l) + '</span>'));
        tr.appendChild(td(fmtBytes(l.bytes_total), 'pc-uso'));
        var tb = document.createElement('td');
        tb.className = 'pc-banda';
        tb.textContent = bandaTexto(l.banda_limite == null ? '' : l.banda_limite);
        tr.appendChild(tb);
        var tl = document.createElement('td');
        tl.className = 'pc-limite';
        tl.textContent = limiteTexto(l.tempo_limite_min == null ? '' : l.tempo_limite_min);
        tr.appendChild(tl);
        return tr;
    }

    // --- Pop-up "ver conexões" (paginado, setas ‹ ›) ---
    var modal      = document.getElementById('conexoes-modal');
    var modalTel   = document.getElementById('conexoes-tel');
    var modalLista = document.getElementById('conexoes-lista');
    var modalLead  = null; // lead aberto no modal (para as setas refazerem o fetch)
    var modalPorPag = 10;  // recalculado a cada abertura conforme a altura da tela
    // Quantas linhas cabem SEM rolar: altura útil do modal (máx. 80vh) menos as
    // partes fixas — cabeçalho (~62px), header da lista (~36px) e setas (~58px).
    // Linha ≈ 42px. O objetivo é a seta de página SEMPRE visível sem arrastar.
    function conexoesPorPagina() {
        var util = Math.min(window.innerHeight * 0.8, window.innerHeight - 40) - 62 - 36 - 58;
        return Math.max(3, Math.min(15, Math.floor(util / 42)));
    }
    function fecharModal() {
        if (modal) { modal.classList.remove('aberto'); modal.setAttribute('aria-hidden', 'true'); }
    }
    function abrirConexoes(leadId, pagina) {
        if (!modal || !EP_CONEXOES) return;
        modalLead = leadId;
        modalLista.innerHTML = '<p class="pc-modal-info">Carregando…</p>';
        modal.classList.add('aberto');
        modal.setAttribute('aria-hidden', 'false');
        fetch(EP_CONEXOES + '?lead_id=' + encodeURIComponent(leadId) + '&pagina=' + (pagina || 1) + '&por_pagina=' + modalPorPag, { credentials: 'same-origin' })
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
                if (d.paginas > 1) {
                    html += '<div class="pc-conex-nav">' +
                        (d.pagina > 1 ? '<button type="button" class="pc-conex-seta" data-pag="' + (d.pagina - 1) + '" aria-label="Página anterior">&lsaquo;</button>' : '') +
                        (d.pagina < d.paginas ? '<button type="button" class="pc-conex-seta" data-pag="' + (d.pagina + 1) + '" aria-label="Próxima página">&rsaquo;</button>' : '') +
                        '</div>';
                }
                modalLista.innerHTML = html;
            }).catch(function () { modalLista.innerHTML = '<p class="pc-modal-info">Erro ao carregar.</p>'; });
    }
    tbody.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.pc-ver-conexoes') : null;
        if (btn) {
            e.preventDefault();
            modalPorPag = conexoesPorPagina(); // mede a tela na abertura; as setas reutilizam
            abrirConexoes(btn.getAttribute('data-lead'), 1);
        }
    });
    if (modalLista) {
        modalLista.addEventListener('click', function (e) {
            var seta = e.target.closest ? e.target.closest('.pc-conex-seta') : null;
            if (seta && modalLead != null) abrirConexoes(modalLead, parseInt(seta.getAttribute('data-pag'), 10) || 1);
        });
    }
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-close')) fecharModal();
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fecharModal(); });
    }

    // --- Menu de contexto na linha (botão direito / segurar no celular) ---
    var EP_EDITAR  = root.getAttribute('data-editar-endpoint');
    var EP_EXCLUIR = root.getAttribute('data-excluir-endpoint');
    var ctx = null, ctxTr = null;
    function ctxFechar() { if (ctx) ctx.classList.remove('aberto'); ctxTr = null; }
    function ctxAbrir(tr, x, y) {
        if (!EP_EDITAR && !EP_EXCLUIR) return;
        if (!ctx) {
            ctx = document.createElement('div');
            ctx.className = 'ctx-menu';
            ctx.innerHTML = '<div class="rt-menu-title">Lead</div>' +
                '<button type="button" class="rt-item" data-acao="info"><span>Informações</span></button>' +
                '<button type="button" class="rt-item" data-acao="editar"><span>Editar</span></button>' +
                '<button type="button" class="rt-item perigo" data-acao="excluir"><span>Excluir</span></button>';
            document.body.appendChild(ctx);
            ctx.addEventListener('click', function (e) {
                var b = e.target.closest ? e.target.closest('[data-acao]') : null;
                if (!b || !ctxTr) return;
                var alvo = ctxTr;
                var acao = b.getAttribute('data-acao');
                ctxFechar();
                if (acao === 'editar') { abrirEditar(alvo); }
                else if (acao === 'excluir') { excluirLead(alvo); }
                else if (acao === 'info') {
                    // Abre a aba Dashboard já consultando este lead.
                    if (window.cdAbrirAba) window.cdAbrirAba('dashboard');
                    if (window.cdDashboardConsultar) window.cdDashboardConsultar(alvo.getAttribute('data-tel') || '');
                }
            });
        }
        ctxTr = tr;
        // Título do menu = nome do lead (se nomeado) ou o número. textContent = seguro.
        var titEl = ctx.querySelector('.rt-menu-title');
        if (titEl) titEl.textContent = tr.getAttribute('data-nome') || tr.getAttribute('data-tel') || 'Lead';
        ctx.classList.add('aberto');
        ctx.style.left = Math.max(8, Math.min(x, window.innerWidth - ctx.offsetWidth - 8)) + 'px';
        ctx.style.top  = Math.max(8, Math.min(y, window.innerHeight - ctx.offsetHeight - 8)) + 'px';
    }
    tbody.addEventListener('contextmenu', function (e) {
        var tr = e.target.closest ? e.target.closest('tr[data-id]') : null;
        if (!tr) return;
        e.preventDefault();
        ctxAbrir(tr, e.clientX, e.clientY);
    });
    // Celular: segurar o dedo na linha (~0,5s) abre o menu.
    var lpTimer = null;
    tbody.addEventListener('touchstart', function (e) {
        var tr = e.target.closest ? e.target.closest('tr[data-id]') : null;
        if (!tr || e.touches.length !== 1) return;
        var t = e.touches[0], tx = t.clientX, ty = t.clientY;
        lpTimer = setTimeout(function () { lpTimer = null; ctxAbrir(tr, tx, ty); }, 550);
    }, { passive: true });
    ['touchmove', 'touchend', 'touchcancel'].forEach(function (ev) {
        tbody.addEventListener(ev, function () { if (lpTimer) { clearTimeout(lpTimer); lpTimer = null; } }, { passive: true });
    });
    document.addEventListener('click', function (e) {
        if (ctx && ctx.classList.contains('aberto') && !(e.target.closest && e.target.closest('.ctx-menu'))) ctxFechar();
    });
    document.addEventListener('scroll', ctxFechar, true);

    // --- Pop-up "Editar lead" (nome de identificação + número) ---
    var edModal  = document.getElementById('editar-modal');
    var edNome   = document.getElementById('ed-nome');
    var edTel    = document.getElementById('ed-tel');
    var edErro   = document.getElementById('ed-erro');
    var edSalvar = document.getElementById('ed-salvar');
    var edTr = null;
    function edMsg(m) { if (!edErro) return; if (m) { edErro.textContent = m; edErro.style.display = ''; } else { edErro.style.display = 'none'; } }
    function abrirEditar(tr) {
        if (!edModal) return;
        edTr = tr;
        if (edNome) edNome.value = tr.getAttribute('data-nome') || '';
        if (edTel) edTel.value = tr.getAttribute('data-tel') || '';
        edMsg(null);
        edModal.classList.add('aberto');
        edModal.setAttribute('aria-hidden', 'false');
    }
    function fecharEditar() {
        if (edModal) { edModal.classList.remove('aberto'); edModal.setAttribute('aria-hidden', 'true'); }
        edTr = null;
    }
    if (edModal) {
        edModal.addEventListener('click', function (e) {
            if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-close')) fecharEditar();
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { fecharEditar(); ctxFechar(); } });
    }
    if (edSalvar) {
        var edEnviar = function (tr, mesclar) {
            edSalvar.disabled = true;
            fetch(EP_EDITAR, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf: CSRF, id: parseInt(tr.getAttribute('data-id'), 10), nome: edNome ? edNome.value.trim() : '', telefone: edTel ? edTel.value.trim() : '', mesclar: mesclar ? 1 : 0 })
            }).then(function (r) { return r.json(); }).then(function (d) {
                edSalvar.disabled = false;
                if (!d || !d.ok) {
                    // Número já é de outro lead: confirma e reenvia pedindo a mescla.
                    if (d && d.mesclar) {
                        if (window.confirm(d.erro + ' As conexões e contagens dos dois serão somadas.')) edEnviar(tr, true);
                        return;
                    }
                    edMsg((d && d.erro) || 'Erro ao salvar.');
                    return;
                }
                if (d.mesclado) {
                    // Os dois viraram um: some a linha editada e atualiza a sobrevivente.
                    var alvoTr = tbody.querySelector('tr[data-id="' + d.id + '"]');
                    if (alvoTr) {
                        alvoTr.setAttribute('data-tel', d.telefone);
                        alvoTr.setAttribute('data-nome', d.nome || '');
                        var ca = alvoTr.querySelector('td:first-child');
                        if (ca) ca.innerHTML = telHTML(d.telefone, d.nome);
                    }
                    tr.remove();
                    fecharEditar();
                    return;
                }
                tr.setAttribute('data-tel', d.telefone);
                tr.setAttribute('data-nome', d.nome || '');
                var c0 = tr.querySelector('td:first-child');
                if (c0) c0.innerHTML = telHTML(d.telefone, d.nome);
                fecharEditar();
            }).catch(function () { edSalvar.disabled = false; edMsg('Erro ao salvar. Tente de novo.'); });
        };
        edSalvar.addEventListener('click', function () {
            if (!edTr || !EP_EDITAR) return;
            edEnviar(edTr, false);
        });
    }

    // --- Excluir lead (com confirmação) ---
    function excluirLead(tr) {
        if (!EP_EXCLUIR) return;
        var rotulo = tr.getAttribute('data-nome') || tr.getAttribute('data-tel') || '';
        if (!window.confirm('Excluir o lead ' + rotulo + '? O histórico de conexões dele também será apagado.')) return;
        fetch(EP_EXCLUIR, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf: CSRF, id: parseInt(tr.getAttribute('data-id'), 10) })
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d && d.ok) { tr.remove(); }
            else { window.alert((d && d.erro) || 'Erro ao excluir.'); }
        }).catch(function () { window.alert('Erro ao excluir. Tente de novo.'); });
    }

    // --- Auto-refresh (a cada 20s): pede a mesma página que está na tela ---
    function poll() {
        fetch(EP_LEADS + (EP_LEADS.indexOf('?') >= 0 ? '&' : '?') + 'pagina=' + PAGINA, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) return;
                anchorMs = Date.now();
                var byId = {};
                d.leads.forEach(function (l) { byId[String(l.id)] = l; });
                var rows = tbody.querySelectorAll('tr[data-id]');
                for (var i = 0; i < rows.length; i++) {
                    var tr = rows[i];
                    var id = tr.getAttribute('data-id');
                    var l = byId[id];
                    if (!l) continue;
                    tr.setAttribute('data-online', l.online ? '1' : '0');
                    tr.setAttribute('data-elapsed', l.elapsed);
                    tr.setAttribute('data-total', l.total_conexoes || 1);
                    var tc = tr.querySelector('.pc-tempo');
                    if (tc) tc.textContent = displayTempo(l);
                    var uc = tr.querySelector('.pc-uso');
                    if (uc) uc.textContent = fmtBytes(l.bytes_total);
                    var ap = tr.querySelector('.pc-aparelho');
                    if (ap) ap.textContent = l.dispositivo || '—';
                    var dh2 = fmtData(l.conectado_em).split(' - ');
                    var dtc = tr.querySelector('.pc-data');
                    if (dtc) dtc.textContent = dh2[0] || '';
                    var hrc = tr.querySelector('.pc-hora');
                    if (hrc) hrc.textContent = dh2[1] || '';
                    var tot = tr.querySelector('.pc-total');
                    if (tot) tot.textContent = (l.total_conexoes || 1);
                    var lc = tr.querySelector('.pc-limite');
                    if (lc && !lc.classList.contains('editing')) {
                        var v = (l.tempo_limite_min == null ? '' : l.tempo_limite_min);
                        tr.setAttribute('data-limite', v);
                        lc.textContent = limiteTexto(v);
                    }
                    var bc = tr.querySelector('.pc-banda');
                    if (bc && !bc.classList.contains('editing')) {
                        var bv = (l.banda_limite == null ? '' : l.banda_limite);
                        tr.setAttribute('data-banda', bv);
                        bc.textContent = bandaTexto(bv);
                    }
                    // Nome/número mudaram (edição em outra aba/aparelho)? Re-renderiza a célula.
                    var telNovo = l.telefone || '', nomeNovo = l.nome || '';
                    if ((tr.getAttribute('data-tel') || '') !== telNovo || (tr.getAttribute('data-nome') || '') !== nomeNovo) {
                        tr.setAttribute('data-tel', telNovo);
                        tr.setAttribute('data-nome', nomeNovo);
                        var c0 = tr.querySelector('td:first-child');
                        if (c0) c0.innerHTML = telHTML(telNovo, nomeNovo);
                    }
                    delete byId[id];
                }
                // Leads novos entram no topo SÓ na página 1 (nas outras, a ordenação
                // do servidor manda); a página nunca passa do tamanho configurado.
                var novos = Object.keys(byId).sort(function (a, b) { return parseInt(b, 10) - parseInt(a, 10); });
                if (novos.length && PAGINA === 1) {
                    var vazio = tbody.querySelector('.pc-empty-row');
                    if (vazio) vazio.remove();
                    novos.forEach(function (idStr) { tbody.insertBefore(buildRow(byId[idStr]), tbody.firstChild); });
                    var sobra = tbody.querySelectorAll('tr[data-id]');
                    for (var s = POR_PAGINA; s < sobra.length; s++) sobra[s].remove();
                }
                // Cartões de resumo (online agora / conectados hoje / total) ao vivo.
                if (d.resumo) {
                    setMetric('metric-online',      d.resumo.online);
                    setMetric('metric-hoje',        d.resumo.hoje);
                    setMetric('metric-cadastrados', d.resumo.cadastrados);
                    setMetric('metric-total',       d.resumo.total);
                }
            }).catch(function () {});
    }
    setInterval(poll, 20000);
})();

/* Fecha o seletor de MikroTik ao clicar fora dele (details não faz sozinho). */
(function () {
    document.addEventListener('click', function (e) {
        var aberto = document.querySelector('details.rt-sel[open]');
        if (aberto && !(e.target.closest && e.target.closest('details.rt-sel'))) {
            aberto.removeAttribute('open');
        }
    });
})();

/* Mostra o nome do arquivo escolhido no botão de upload do anúncio. */
(function () {
    var inputs = document.querySelectorAll('.pc-file input[type="file"]');
    for (var i = 0; i < inputs.length; i++) {
        inputs[i].addEventListener('change', function () {
            var label = this.parentNode.querySelector('.pc-file-label');
            if (label) label.textContent = this.files && this.files.length ? this.files[0].name : 'Escolher imagem…';
        });
    }
})();

/* Status do MikroTik: verifica constantemente e AVISA na transição (online<->offline).
   Poller proprio (independente da tabela) + recheca ao voltar/focar a aba — assim
   nao fica "preso online" quando a aba estava em segundo plano. */
(function () {
    var box = document.getElementById('mikrotik-status');
    if (!box) return;
    var EP = box.getAttribute('data-endpoint');
    if (!EP) return;
    var txt = box.querySelector('.mk-text');
    var conhecido = box.classList.contains('mk-on'); // estado inicial vindo do servidor

    function render(online) {
        box.classList.toggle('mk-on', online);
        box.classList.toggle('mk-off', !online);
        if (txt) txt.textContent = 'MikroTik ' + (online ? 'online' : 'offline');
    }

    function toast(online) {
        var t = document.createElement('div');
        t.className = 'mk-toast ' + (online ? 'mk-toast-on' : 'mk-toast-off');
        t.textContent = online ? 'MikroTik voltou a ficar online' : 'MikroTik ficou offline';
        document.body.appendChild(t);
        // forca o reflow p/ a transicao de entrada
        void t.offsetWidth;
        t.classList.add('mostrar');
        setTimeout(function () {
            t.classList.remove('mostrar');
            setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 400);
        }, 5000);
    }

    var checando = false;
    function checar() {
        if (checando) return;
        checando = true;
        fetch(EP, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) return;
                var on = !!d.online;
                if (on !== conhecido) {
                    conhecido = on;
                    render(on);
                    toast(on);
                }
            }).catch(function () {})
            .then(function () { checando = false; });
    }

    setInterval(checar, 5000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) checar(); });
    window.addEventListener('focus', checar);
    checar(); // confere já ao abrir
})();
