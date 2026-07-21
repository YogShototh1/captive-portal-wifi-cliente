/* Aba Dashboard: consulta os hábitos de visita de um lead pelo número.
   Config vem do #dashboard-box (data-endpoint). A opção "Informações" do menu
   da tabela de leads chama window.cdDashboardConsultar(tel) direto. */
(function () {
    var box = document.getElementById('dashboard-box');
    if (!box) return;
    var EP     = box.getAttribute('data-endpoint');
    var inp    = document.getElementById('dash-tel');
    var btn    = document.getElementById('dash-consultar');
    var erroEl = document.getElementById('dash-erro');
    var res    = document.getElementById('dash-resultado');
    if (!inp || !btn || !res) return;

    function erro(m) {
        if (!erroEl) return;
        if (m) { erroEl.textContent = m; erroEl.style.display = ''; }
        else { erroEl.style.display = 'none'; }
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    function fmtTempo(seg) {
        function p(n) { return (n < 10 ? '0' : '') + n; }
        seg = parseInt(seg, 10) || 0;
        return p(Math.floor(seg / 3600)) + ':' + p(Math.floor((seg % 3600) / 60)) + ':' + p(seg % 60);
    }
    function fmtData(s) {
        var m = String(s == null ? '' : s).match(/^(\d{4})-(\d{2})-(\d{2})/);
        return m ? m[3] + '/' + m[2] + '/' + m[1] : '';
    }

    // ===== Gráfico "pizza oca" (donut) dos dias da semana =====
    var DIAS_NOMES = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
    var DIAS_CORES = ['#f472b6', '#a855f7', '#ec4899', '#8b5cf6', '#d946ef', '#7c3aed', '#c084fc'];
    var MESES_NOMES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
                       'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    var DOW_LETRAS = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'];

    // ===== Calendário de visitas (abre no mês atual a cada consulta) =====
    var calAno = 0, calMes = 0, visitasSet = {};

    function desenharCal() {
        var el = document.getElementById('dash-cal');
        if (!el) return;
        function p2(n) { return (n < 10 ? '0' : '') + n; }
        function iso(a, m, d) { return a + '-' + p2(m + 1) + '-' + p2(d); }
        var hoje = new Date();
        var hojeIso = iso(hoje.getFullYear(), hoje.getMonth(), hoje.getDate());
        var html = '<div class="dash-cal-bar">' +
            '<button type="button" class="cal-nav dash-cal-nav" data-d="-1" aria-label="Mês anterior">&lsaquo;</button>' +
            '<b>' + MESES_NOMES[calMes] + ' de ' + calAno + '</b>' +
            '<button type="button" class="cal-nav dash-cal-nav" data-d="1" aria-label="Próximo mês">&rsaquo;</button>' +
            '</div><div class="dash-cal-grade">';
        for (var w = 0; w < 7; w++) html += '<span class="dash-cal-dow">' + DOW_LETRAS[w] + '</span>';
        var primeiro = new Date(calAno, calMes, 1).getDay();
        var dias = new Date(calAno, calMes + 1, 0).getDate();
        // Sem células vazias (elas degeneravam a altura da 1ª semana): o dia 1
        // só começa na coluna certa via grid-column-start.
        for (var d = 1; d <= dias; d++) {
            var di = iso(calAno, calMes, d);
            var st = d === 1 && primeiro > 0 ? ' style="grid-column-start:' + (primeiro + 1) + '"' : '';
            html += '<span class="dash-dia' + (visitasSet[di] ? ' visitado' : '') + (di === hojeIso ? ' hoje' : '') + '"' + st + '>' + d + '</span>';
        }
        el.innerHTML = html + '</div>';
    }

    // Navegação ‹ › do calendário (delegação: o resultado é re-renderizado).
    res.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.dash-cal-nav') : null;
        if (!b) return;
        calMes += parseInt(b.getAttribute('data-d'), 10);
        if (calMes < 0) { calMes = 11; calAno--; }
        if (calMes > 11) { calMes = 0; calAno++; }
        desenharCal();
    });

    // Setor anelar (fatia oca) de a0 a a1 (radianos).
    function fatiaPath(cx, cy, rO, rI, a0, a1) {
        function pt(r, a) { return (cx + r * Math.cos(a)).toFixed(2) + ' ' + (cy + r * Math.sin(a)).toFixed(2); }
        var laf = (a1 - a0) > Math.PI ? 1 : 0;
        return 'M' + pt(rO, a0) + ' A' + rO + ' ' + rO + ' 0 ' + laf + ' 1 ' + pt(rO, a1) +
               ' L' + pt(rI, a1) + ' A' + rI + ' ' + rI + ' 0 ' + laf + ' 0 ' + pt(rI, a0) + ' Z';
    }

    // Donut genérico. fatias = [{cor, l (título do tooltip), v (linha 2), n (peso)}];
    // c1/c2 = texto do centro (linha grande / linha pequena opcional).
    function donutSVG(fatias, c1, c2) {
        var total = 0, i;
        for (i = 0; i < fatias.length; i++) total += fatias[i].n;
        if (!total) return '<b>—</b>';
        var cx = 75, cy = 75, rO = 70, rI = 52;
        var s = '<svg class="dash-donut" viewBox="0 0 150 150">';
        function attrs(f) { return ' data-l="' + esc(f.l) + '" data-v="' + esc(f.v) + '"'; }
        if (fatias.length === 1) {
            // Uma fatia só = anel completo (o path de 360° degenera).
            s += '<circle class="dash-fatia" cx="' + cx + '" cy="' + cy + '" r="' + ((rO + rI) / 2) + '" fill="none" stroke="' + fatias[0].cor + '" stroke-width="' + (rO - rI) + '"' + attrs(fatias[0]) + '/>';
        } else {
            var GAP = 0.035; // respiro entre as fatias (rad)
            var ang = -Math.PI / 2;
            for (i = 0; i < fatias.length; i++) {
                var frac = fatias[i].n / total;
                var a0 = ang + GAP / 2, a1 = ang + frac * 2 * Math.PI - GAP / 2;
                if (a1 <= a0) a1 = a0 + 0.02; // fatia mínima visível
                s += '<path class="dash-fatia" d="' + fatiaPath(cx, cy, rO, rI, a0, a1) + '" fill="' + fatias[i].cor + '"' + attrs(fatias[i]) + '/>';
                ang += frac * 2 * Math.PI;
            }
        }
        s += '<text class="dash-donut-centro" x="' + cx + '" y="' + (cy + (c2 ? -2 : 6)) + '" text-anchor="middle">' + esc(c1) + '</text>';
        if (c2) s += '<text class="dash-donut-sub" x="' + cx + '" y="' + (cy + 15) + '" text-anchor="middle">' + esc(c2) + '</text>';
        s += '</svg>';
        return '<div class="dash-donut-wrap">' + s + '<div class="est-tooltip dash-tt"></div></div>';
    }

    // Donut dos dias da semana (visitas por dia).
    function donutSemana(porDow, topNome) {
        var fatias = [];
        for (var i = 0; i < 7; i++) {
            var n = parseInt(porDow && porDow[i], 10) || 0;
            if (n > 0) fatias.push({ cor: DIAS_CORES[i], l: DIAS_NOMES[i], v: n + (n === 1 ? ' vez' : ' vezes'), n: n });
        }
        var partes = String(topNome || '').split('-');
        var l1 = partes[0] ? partes[0].charAt(0).toUpperCase() + partes[0].slice(1) : '—';
        return donutSVG(fatias, l1, partes[1] ? '-' + partes[1] : '');
    }

    // Donut das faixas de horário (dias por faixa de 1h dominante da conexão).
    function donutHoras(faixas, horaTop) {
        var comDado = [], h;
        for (h = 0; h < 24; h++) {
            var n = parseInt(faixas && faixas[h], 10) || 0;
            if (n > 0) comDado.push({ h: h, n: n });
        }
        var fatias = [];
        for (var i = 0; i < comDado.length; i++) {
            var hue = 250 + 80 * (comDado.length > 1 ? i / (comDado.length - 1) : 0); // violeta -> rosa
            fatias.push({
                cor: 'hsl(' + Math.round(hue) + ', 75%, 62%)',
                l: (comDado[i].h < 10 ? '0' : '') + comDado[i].h + ':00',
                v: comDado[i].n + (comDado[i].n === 1 ? ' dia' : ' dias'),
                n: comDado[i].n
            });
        }
        return donutSVG(fatias, horaTop || '—', '');
    }

    function ligarDonuts() {
        var wraps = res.querySelectorAll('.dash-donut-wrap');
        for (var i = 0; i < wraps.length; i++) {
            (function (wrap) {
                var tt = wrap.querySelector('.dash-tt');
                wrap.addEventListener('mousemove', function (e) {
                    var f = e.target.closest ? e.target.closest('.dash-fatia') : null;
                    if (!f) { tt.style.display = 'none'; return; }
                    tt.innerHTML = '<b>' + f.getAttribute('data-l') + '</b><span>' + f.getAttribute('data-v') + '</span>';
                    tt.style.display = 'block';
                    var r = wrap.getBoundingClientRect();
                    tt.style.left = Math.min(e.clientX - r.left + 12, r.width - tt.offsetWidth - 4) + 'px';
                    tt.style.top = Math.max(e.clientY - r.top - 10, 0) + 'px';
                });
                wrap.addEventListener('mouseleave', function () { tt.style.display = 'none'; });
            })(wraps[i]);
        }
    }

    function render(d) {
        var titular = d.nome ? esc(d.nome) + ' <small>(' + esc(d.telefone) + ')</small>' : esc(d.telefone);
        var rec = '—', recCls = '';
        if (d.recorrencia) {
            var n = d.recorrencia.dias;
            if (d.recorrencia.tipo === 'seguidos') {
                rec = n + (n === 1 ? ' dia' : ' dias seguidos') + ' vindo';
                recCls = ' dash-bom';
            } else {
                rec = n + (n === 1 ? ' dia' : ' dias') + ' sem vir';
                recCls = ' dash-ruim';
            }
        }
        // Calendário: guarda as datas visitadas e SEMPRE reabre no mês atual.
        visitasSet = {};
        for (var i = 0; i < (d.datas || []).length; i++) visitasSet[d.datas[i]] = true;
        var hoje = new Date();
        calAno = hoje.getFullYear();
        calMes = hoje.getMonth();

        res.innerHTML =
            '<p class="dash-titular">' + titular +
            (d.ultima_visita ? ' <small>— última visita ' + fmtData(d.ultima_visita) + '</small>' : '') + '</p>' +
            '<div class="dash-top">' +
                '<div class="dash-cal-card"><div id="dash-cal"></div></div>' +
                '<div class="dash-donuts-col">' +
                    '<div class="dash-card dash-card-donut"><span>dia da semana que mais frequenta</span>' + donutSemana(d.visitas_por_dia, d.dia_semana) + '</div>' +
                    '<div class="dash-card dash-card-donut"><span>horário que mais frequenta</span>' + donutHoras(d.faixas_hora, d.hora_top) + '</div>' +
                '</div>' +
            '</div>' +
            '<div class="dash-grid">' +
            '<div class="dash-card"><span>tempo médio por dia</span><b>' + fmtTempo(d.total_dias > 0 ? Math.round(d.tempo_total / d.total_dias) : 0) + '</b></div>' +
            '<div class="dash-card' + recCls + '"><span>recorrência atual</span><b>' + rec + '</b></div>' +
            '<div class="dash-card"><span>dias no estabelecimento</span><b>' + d.total_dias + (d.total_dias === 1 ? ' dia' : ' dias') + '</b></div>' +
            '</div>';
        desenharCal();
        ligarDonuts();
    }

    function consultar() {
        var tel = (inp.value || '').replace(/\D/g, '');
        if (tel.length < 10) { erro('Digite o número completo (com DDD).'); return; }
        erro(null);
        btn.disabled = true;
        var txt = btn.textContent;
        btn.textContent = 'Consultando…';
        res.innerHTML = '';
        fetch(EP + (EP.indexOf('?') >= 0 ? '&' : '?') + 'telefone=' + encodeURIComponent(tel),
              { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { erro((d && d.erro) || 'Erro ao consultar.'); return; }
                render(d);
            })
            .catch(function () { erro('Erro ao consultar. Tente de novo.'); })
            .then(function () { btn.disabled = false; btn.textContent = txt; });
    }

    btn.addEventListener('click', consultar);
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); consultar(); } });

    // Chamado pela opção "Informações" do menu de contexto da tabela de leads.
    window.cdDashboardConsultar = function (tel) {
        inp.value = tel || '';
        consultar();
    };
})();
