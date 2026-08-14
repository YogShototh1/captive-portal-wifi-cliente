// Painel de HOSPEDAGEM: cadastro, edição e exclusão de hóspedes.
//
// Espelha o leads-live.js na interação (botão direito abre o menu, segurar o
// dedo no celular, modal de edição), mas sem polling: hóspede não muda sozinho
// — quem muda é a recepção, e depois de salvar a página recarrega.
(function () {
    var root = document.getElementById('hospedes-live');
    if (!root) return;

    var EP_SALVAR  = root.getAttribute('data-salvar-endpoint');
    var EP_EXCLUIR = root.getAttribute('data-excluir-endpoint');
    var CSRF       = root.getAttribute('data-csrf');
    var ROT_ATIVO  = root.getAttribute('data-roteador') || '';
    // Quando quem edita e o admin pela tela do cliente, o dono do hospede
    // e a conta aberta — nao a do admin, que nem tem roteador.
    var CLIENTE    = parseInt(root.getAttribute('data-cliente') || '0', 10) || 0;
    var tbody      = root.querySelector('tbody');

    var modal   = document.getElementById('hospede-modal');
    var elNome  = document.getElementById('hsp-nome');
    var elQto   = document.getElementById('hsp-quarto');
    var elTel   = document.getElementById('hsp-tel');
    var elEnt   = document.getElementById('hsp-entrada');
    var elDias  = document.getElementById('hsp-dias');
    var elHora  = document.getElementById('hsp-hora');
    var elRot   = document.getElementById('hsp-roteador');
    var elErro  = document.getElementById('hsp-erro');
    var elPrev  = document.getElementById('hsp-previa');
    var elTit   = document.getElementById('hsp-titulo');
    var btnSalv = document.getElementById('hsp-salvar');
    var editId  = null;   // null = cadastro novo

    function msg(m) {
        if (!elErro) return;
        if (m) { elErro.textContent = m; elErro.style.display = ''; }
        else { elErro.style.display = 'none'; }
    }
    function hoje() {
        var d = new Date();
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }
    // Mesma conta do servidor (hospede_saida): entrada + diárias, na hora do
    // check-out. Aqui é só prévia — quem grava a data de saída é o PHP.
    function previa() {
        if (!elPrev) return;
        var ent = (elEnt && elEnt.value) || '';
        var dias = parseInt((elDias && elDias.value) || '0', 10);
        var hora = (elHora && elHora.value) || '12:00';
        if (!ent || !(dias > 0)) { elPrev.textContent = ''; return; }
        var p = ent.split('-');
        var d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
        d.setDate(d.getDate() + dias);
        var br = ('0' + d.getDate()).slice(-2) + '/' + ('0' + (d.getMonth() + 1)).slice(-2) + '/' + d.getFullYear();
        elPrev.textContent = 'O Wi-Fi deste hóspede para de funcionar em ' + br + ' às ' + hora + '.';
    }
    [elEnt, elDias, elHora].forEach(function (el) {
        if (el) el.addEventListener('input', previa);
    });

    function abrir(tr) {
        if (!modal) return;
        editId = tr ? tr.getAttribute('data-id') : null;
        if (elTit) elTit.textContent = tr ? 'Editar hóspede' : 'Cadastrar hóspede';
        if (elNome) elNome.value = tr ? (tr.getAttribute('data-nome') || '') : '';
        if (elQto)  elQto.value  = tr ? (tr.getAttribute('data-quarto') || '') : '';
        if (elTel)  elTel.value  = tr ? (tr.getAttribute('data-tel') || '') : '';
        if (elEnt)  elEnt.value  = tr ? (tr.getAttribute('data-entrada') || hoje()) : hoje();
        if (elDias) elDias.value = tr ? (tr.getAttribute('data-dias') || '1') : '1';
        if (elHora) elHora.value = tr ? (tr.getAttribute('data-hora') || '12:00') : '12:00';
        if (elRot)  elRot.value  = (tr ? tr.getAttribute('data-roteador') : ROT_ATIVO) || elRot.value;
        msg(null);
        previa();
        modal.classList.add('aberto');
        modal.setAttribute('aria-hidden', 'false');
        if (elNome) elNome.focus();
    }
    function fechar() {
        if (modal) { modal.classList.remove('aberto'); modal.setAttribute('aria-hidden', 'true'); }
        editId = null;
    }
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-close')) fechar();
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fechar(); });
    }
    var btnNovo = document.getElementById('hsp-novo');
    if (btnNovo) btnNovo.addEventListener('click', function () { abrir(null); });

    if (btnSalv) {
        btnSalv.addEventListener('click', function () {
            if (!EP_SALVAR) return;
            btnSalv.disabled = true;
            msg(null);
            fetch(EP_SALVAR, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf: CSRF,
                    cliente_id: CLIENTE,
                    id: editId ? parseInt(editId, 10) : 0,
                    nome: elNome ? elNome.value.trim() : '',
                    quarto: elQto ? elQto.value.trim() : '',
                    telefone: elTel ? elTel.value.trim() : '',
                    entrada: elEnt ? elEnt.value : '',
                    dias: elDias ? elDias.value : '',
                    hora: elHora ? elHora.value : '',
                    roteador: elRot ? elRot.value : ROT_ATIVO
                })
            }).then(function (r) { return r.json(); }).then(function (d) {
                btnSalv.disabled = false;
                if (!d || !d.ok) { msg((d && d.erro) || 'Erro ao salvar.'); return; }
                window.location.reload();
            }).catch(function () { btnSalv.disabled = false; msg('Erro ao salvar. Tente de novo.'); });
        });
    }

    // --- Menu de contexto na linha (botão direito / segurar no celular) ---
    var ctx = null, ctxTr = null;
    function ctxFechar() { if (ctx) ctx.classList.remove('aberto'); ctxTr = null; }
    function ctxAbrir(tr, x, y) {
        if (!ctx) {
            ctx = document.createElement('div');
            ctx.className = 'ctx-menu';
            ctx.innerHTML = '<div class="rt-menu-title">Hóspede</div>' +
                '<button type="button" class="rt-item" data-acao="editar"><span>Editar</span></button>' +
                '<button type="button" class="rt-item" data-acao="renovar"><span>+1 diária</span></button>' +
                '<button type="button" class="rt-item perigo" data-acao="excluir"><span>Apagar</span></button>';
            document.body.appendChild(ctx);
            ctx.addEventListener('click', function (e) {
                var b = e.target.closest ? e.target.closest('[data-acao]') : null;
                if (!b || !ctxTr) return;
                var alvo = ctxTr;
                var acao = b.getAttribute('data-acao');
                ctxFechar();
                if (acao === 'editar') { abrir(alvo); }
                else if (acao === 'renovar') { renovar(alvo); }
                else if (acao === 'excluir') { excluir(alvo); }
            });
        }
        ctxTr = tr;
        var tit = ctx.querySelector('.rt-menu-title');
        if (tit) tit.textContent = tr.getAttribute('data-nome') || 'Hóspede';
        ctx.classList.add('aberto');
        ctx.style.left = Math.max(8, Math.min(x, window.innerWidth - ctx.offsetWidth - 8)) + 'px';
        ctx.style.top  = Math.max(8, Math.min(y, window.innerHeight - ctx.offsetHeight - 8)) + 'px';
    }
    if (tbody) {
        tbody.addEventListener('contextmenu', function (e) {
            var tr = e.target.closest ? e.target.closest('tr[data-id]') : null;
            if (!tr) return;
            e.preventDefault();
            ctxAbrir(tr, e.clientX, e.clientY);
        });
        // Celular: segurar o dedo na linha (~0,5s) abre o menu.
        var lp = null;
        tbody.addEventListener('touchstart', function (e) {
            var tr = e.target.closest ? e.target.closest('tr[data-id]') : null;
            if (!tr || e.touches.length !== 1) return;
            var t = e.touches[0], tx = t.clientX, ty = t.clientY;
            lp = setTimeout(function () { lp = null; ctxAbrir(tr, tx, ty); }, 550);
        }, { passive: true });
        ['touchmove', 'touchend', 'touchcancel'].forEach(function (ev) {
            tbody.addEventListener(ev, function () { if (lp) { clearTimeout(lp); lp = null; } }, { passive: true });
        });
        // Duplo clique também edita — atalho de quem usa mouse o dia inteiro.
        tbody.addEventListener('dblclick', function (e) {
            var tr = e.target.closest ? e.target.closest('tr[data-id]') : null;
            if (tr) abrir(tr);
        });
    }
    document.addEventListener('click', function (e) {
        if (ctx && ctx.classList.contains('aberto') && !(e.target.closest && e.target.closest('.ctx-menu'))) ctxFechar();
    });
    document.addEventListener('scroll', ctxFechar, true);

    // Hóspede esticou a estadia: soma uma diária e pronto. Manda os mesmos
    // campos do modal (todos estão nos data-* da linha) para o MESMO endpoint,
    // que já recalcula a data de saída — nenhuma conta de data vive aqui.
    function renovar(tr) {
        if (!EP_SALVAR) return;
        var dias = parseInt(tr.getAttribute('data-dias') || '0', 10) + 1;
        var rotulo = tr.getAttribute('data-nome') || tr.getAttribute('data-tel') || '';
        if (!window.confirm('Somar uma diária para ' + rotulo + '? Passa a ' + dias + '.')) return;
        fetch(EP_SALVAR, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf: CSRF,
                cliente_id: CLIENTE,
                id: parseInt(tr.getAttribute('data-id'), 10),
                nome: tr.getAttribute('data-nome') || '',
                quarto: tr.getAttribute('data-quarto') || '',
                telefone: tr.getAttribute('data-tel') || '',
                entrada: tr.getAttribute('data-entrada') || '',
                dias: dias,
                hora: tr.getAttribute('data-hora') || '12:00',
                roteador: tr.getAttribute('data-roteador') || ROT_ATIVO
            })
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.ok) { window.alert((d && d.erro) || 'Erro ao renovar.'); return; }
            window.location.reload();
        }).catch(function () { window.alert('Erro ao renovar. Tente de novo.'); });
    }

    function excluir(tr) {
        if (!EP_EXCLUIR) return;
        var rotulo = tr.getAttribute('data-nome') || tr.getAttribute('data-tel') || '';
        if (!window.confirm('Apagar o hóspede ' + rotulo + '? O Wi-Fi dele para de funcionar na hora.')) return;
        fetch(EP_EXCLUIR, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf: CSRF, cliente_id: CLIENTE, id: parseInt(tr.getAttribute('data-id'), 10) })
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d && d.ok) { tr.remove(); }
            else { window.alert((d && d.erro) || 'Erro ao apagar.'); }
        }).catch(function () { window.alert('Erro ao apagar. Tente de novo.'); });
    }
})();
