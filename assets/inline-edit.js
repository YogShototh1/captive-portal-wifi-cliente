/* Edição inline do limite de tempo e da banda: clique na célula, digite, Enter.
   Delegado no document porque as duas tabelas que a usam não compartilham
   script — a de leads (varejo, leads-live.js) e a de hóspedes (hospedagem,
   sem polling). Endpoint e csrf vêm do contêiner mais próximo que os declara
   (#leads-live / #hospedes-live), então cada tela liga o que oferece: sem
   data-banda-endpoint, a célula simplesmente não abre.

   Os ids são SEMPRE os de data-ids, nunca o data-id da linha: na hospedagem o
   data-id é do hóspede, e mandá-lo como id de lead gravaria banda na pessoa
   errada. Linha sem data-ids (hóspede que ainda não conectou) não edita. */
(function () {
    var CFG = {
        banda:  { attr: 'data-banda',  ep: 'data-banda-endpoint',  key: 'banda',  ph: 'Mbps', un: ' Mbps' },
        limite: { attr: 'data-limite', ep: 'data-limite-endpoint', key: 'limite', ph: 'min',  un: ' min' }
    };
    function texto(cfg, v) { return (v === '' || v == null) ? 'sem limite' : (v + cfg.un); }

    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) return;
        var cell = e.target.closest('.pc-limite, .pc-banda');
        if (!cell || cell.classList.contains('editing')) return;
        var cfg = cell.classList.contains('pc-banda') ? CFG.banda : CFG.limite;
        var box = cell.closest('[' + cfg.ep + ']');
        if (!box) return;
        var EP = box.getAttribute(cfg.ep);
        var CSRF = box.getAttribute('data-csrf');
        var tr = cell.closest('tr');
        var ids = String((tr && tr.getAttribute('data-ids')) || '')
            .split(',').map(function (v) { return parseInt(v, 10); })
            .filter(function (v) { return v > 0; });
        if (!EP || !ids.length) return;

        var atual = tr.getAttribute(cfg.attr) || '';
        cell.classList.add('editing');
        cell.innerHTML = '';
        var inp = document.createElement('input');
        inp.type = 'number'; inp.min = '0'; inp.placeholder = cfg.ph; inp.value = atual;
        cell.appendChild(inp);
        inp.focus(); inp.select();

        var done = false;
        function fim(txt) { done = true; cell.classList.remove('editing'); cell.textContent = txt; }
        function salvar() {
            if (done) return;
            var val = inp.value.trim();
            var valor = (val === '') ? null : Math.max(0, parseInt(val, 10) || 0);
            done = true;
            var body = { csrf: CSRF, id: ids[0], ids: ids };
            body[cfg.key] = valor;
            fetch(EP, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            }).then(function (r) { return r.json(); }).then(function (d) {
                var novo = (d && d.ok) ? d[cfg.key] : valor;
                var v = (novo == null ? '' : novo);
                tr.setAttribute(cfg.attr, v);
                cell.classList.remove('editing');
                cell.textContent = texto(cfg, v);
            }).catch(function () {
                cell.classList.remove('editing');
                cell.textContent = texto(cfg, atual);
            });
        }
        inp.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); salvar(); }
            else if (ev.key === 'Escape') { ev.preventDefault(); fim(texto(cfg, atual)); }
        });
        inp.addEventListener('blur', salvar);
    });
})();
