/* Aba Dashboard (geral): donut de recorrência da semana (revisitaram /
   não revisitaram / novos) + variação de visitas vs semana passada.
   Config vem do #dashgeral-box (data-endpoint). Carrega ao abrir a página. */
(function () {
    var box = document.getElementById('dashgeral-box');
    if (!box) return;
    var EP  = box.getAttribute('data-endpoint');
    var out = document.getElementById('dg-conteudo');
    if (!out) return;

    // Setor anelar (mesma geometria do dashboard do lead).
    function fatiaPath(cx, cy, rO, rI, a0, a1) {
        function pt(r, a) { return (cx + r * Math.cos(a)).toFixed(2) + ' ' + (cy + r * Math.sin(a)).toFixed(2); }
        var laf = (a1 - a0) > Math.PI ? 1 : 0;
        return 'M' + pt(rO, a0) + ' A' + rO + ' ' + rO + ' 0 ' + laf + ' 1 ' + pt(rO, a1) +
               ' L' + pt(rI, a1) + ' A' + rI + ' ' + rI + ' 0 ' + laf + ' 0 ' + pt(rI, a0) + ' Z';
    }

    // % inteira fechando 100 (maior resto) — igual à legenda do dashboard do lead.
    function pctsInt(vals) {
        var tot = 0, i;
        for (i = 0; i < vals.length; i++) tot += vals[i];
        if (!tot) return vals.map(function () { return 0; });
        var raw = vals.map(function (v) { return v * 100 / tot; });
        var base = raw.map(function (x) { return Math.floor(x); });
        var falta = 100 - base.reduce(function (a, b) { return a + b; }, 0);
        var ordem = raw.map(function (x, idx) { return { i: idx, r: x - Math.floor(x) }; })
            .sort(function (a, b) { return b.r - a.r; });
        for (i = 0; i < falta; i++) base[ordem[i].i]++;
        return base;
    }

    // Linha de variação de visitas: % + números reais (atual vs anterior), em
    // frase curta pra caber numa linha só. Ex.: "↑ 100% visitas vs ontem (12 vs 6)".
    function linhaVar(d, rotulo) {
        var pct = Number(d.pct) || 0;
        var abs = Math.abs(pct).toLocaleString('pt-BR');
        var par = ' (' + (d.visitas || 0) + ' vs ' + (d.visitas_ant || 0) + ')';
        if (pct > 0) return '<p class="dg-var dg-var-bom">&uarr; ' + abs + '% visitas vs ' + rotulo + par + '</p>';
        if (pct < 0) return '<p class="dg-var dg-var-ruim">&darr; ' + abs + '% visitas vs ' + rotulo + par + '</p>';
        return '<p class="dg-var">visitas iguais vs ' + rotulo + par + '</p>';
    }

    // Donut + legenda de um período (revisitaram / não revisitaram / novos).
    function donutPeriodo(titulo, d, rotuloVar) {
        var itens = [
            { nome: 'Revisitaram',      n: d.revisitaram,     cor: '#06b6d4' },
            { nome: 'Não revisitaram', n: d.nao_revisitaram, cor: '#8b5cf6' },
            { nome: 'Novos',            n: d.novos,           cor: '#ec4899' }
        ];
        var comDado = itens.filter(function (f) { return f.n > 0; });
        var corpo;
        if (!d.total || !comDado.length) {
            corpo = '<p class="pc-anuncio-desc">Sem dados no período.</p>';
        } else {
            var cx = 75, cy = 75, rO = 70, rI = 52;
            var s = '<svg class="dash-donut" viewBox="0 0 150 150">';
            if (comDado.length === 1) {
                s += '<circle cx="' + cx + '" cy="' + cy + '" r="' + ((rO + rI) / 2) + '" fill="none" stroke="' + comDado[0].cor + '" stroke-width="' + (rO - rI) + '"/>';
            } else {
                var total = 0, i;
                for (i = 0; i < comDado.length; i++) total += comDado[i].n;
                var GAP = 0.035, ang = -Math.PI / 2;
                for (i = 0; i < comDado.length; i++) {
                    var frac = comDado[i].n / total;
                    var a0 = ang + GAP / 2, a1 = ang + frac * 2 * Math.PI - GAP / 2;
                    if (a1 <= a0) a1 = a0 + 0.02;
                    s += '<path d="' + fatiaPath(cx, cy, rO, rI, a0, a1) + '" fill="' + comDado[i].cor + '"/>';
                    ang += frac * 2 * Math.PI;
                }
            }
            s += '<text class="dash-donut-centro" x="' + cx + '" y="' + (cy - 2) + '" text-anchor="middle">' + d.total + '</text>';
            s += '<text class="dash-donut-sub" x="' + cx + '" y="' + (cy + 15) + '" text-anchor="middle">cliente' + (d.total === 1 ? '' : 's') + '</text>';
            s += '</svg>';

            var ps = pctsInt(itens.map(function (f) { return f.n; }));
            var leg = '<ul class="dash-legenda">';
            for (var j = 0; j < itens.length; j++) {
                // Quantidade real ao lado da % — zerado mostra só "0%".
                var qtd = itens[j].n > 0 ? ' <small>(' + itens[j].n + ')</small>' : '';
                leg += '<li><span class="leg-cor" style="background:' + itens[j].cor + '"></span>' +
                    '<span class="leg-nome">' + itens[j].nome + '</span>' +
                    '<span class="leg-pct">' + ps[j] + '%' + qtd + '</span></li>';
            }
            leg += '</ul>';
            corpo = '<div class="dash-donut-row">' + s + leg + '</div>';
        }
        return '<div class="dash-card dg-card"><span>' + titulo + '</span>' + corpo + linhaVar(d, rotuloVar) + '</div>';
    }

    function render(d) {
        // Ordem pedida: mês, semana, dia (esquerda -> direita).
        // Janelas justas: período atual até agora vs o anterior até o mesmo ponto
        // (rótulo curto pra caber numa linha; o corte "mesmo dia/hora" segue no cálculo).
        out.innerHTML = '<div class="dg-cards">' +
            donutPeriodo('Recorrência do mês', d.mes, 'mês passado') +
            donutPeriodo('Recorrência da semana', d.semana, 'semana passada') +
            donutPeriodo('Recorrência diária', d.dia, 'ontem') +
            '</div>';
    }

    out.innerHTML = '<p class="pc-anuncio-desc">Carregando…</p>';
    fetch(EP, { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) { out.innerHTML = '<p class="pc-anuncio-desc">Erro ao carregar o dashboard.</p>'; return; }
            render(d);
        })
        .catch(function () { out.innerHTML = '<p class="pc-anuncio-desc">Erro ao carregar o dashboard.</p>'; });
})();
