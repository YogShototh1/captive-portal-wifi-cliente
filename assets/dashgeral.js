/* Aba Dashboard (geral): donut de recorrência da semana (revisitaram /
   não revisitaram / novos) + variação de visitas vs semana passada.
   Config vem do #dashgeral-box (data-endpoint). Carrega ao abrir a página. */
(function () {
    var box = document.getElementById('dashgeral-box');
    if (!box) return;
    var EP  = box.getAttribute('data-endpoint');
    var out = document.getElementById('dg-conteudo');
    if (!out) return;

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

    // Linha de variação de CLIENTES DISTINTOS (novos + revisitas) do período
    // vs o anterior. Ex.: "↑ 80% clientes vs mês passado (36 vs 20)".
    function linhaVar(d, rotulo) {
        var pct = Number(d.pct) || 0;
        var abs = Math.abs(pct).toLocaleString('pt-BR');
        var par = ' (' + (d.clientes || 0) + ' vs ' + (d.clientes_ant || 0) + ')';
        if (pct > 0) return '<p class="dg-var dg-var-bom">&uarr; ' + abs + '% clientes vs ' + rotulo + par + '</p>';
        if (pct < 0) return '<p class="dg-var dg-var-ruim">&darr; ' + abs + '% clientes vs ' + rotulo + par + '</p>';
        return '<p class="dg-var">clientes iguais vs ' + rotulo + par + '</p>';
    }

    // Pizza (cheia) + legenda de um período (revisitaram / não revisitaram / novos).
    // À direita da pizza: SOMA de revisitas + novos (clientes que estiveram no
    // período), com linhas-guia saindo do meio dessas duas fatias até o número.
    function donutPeriodo(titulo, d, rotuloVar) {
        // Cores do tema (navy/cyan): quem esteve no período em tons vivos;
        // "não revisitaram" apagado (cinza-ardósia) e sempre do lado ESQUERDO.
        var itens = [
            { k: 'rev', nome: 'Revisitaram',      n: d.revisitaram,     cor: '#22d3ee' },
            { k: 'nao', nome: 'Não revisitaram', n: d.nao_revisitaram, cor: '#64748b' },
            { k: 'nov', nome: 'Novos',            n: d.novos,           cor: '#3b82f6' }
        ];
        var comDado = itens.filter(function (f) { return f.n > 0; });
        var corpo;
        if (!d.total || !comDado.length) {
            corpo = '<p class="pc-anuncio-desc">Sem dados no período.</p>';
        } else {
            var cx = 75, cy = 75, r = 70;
            function pt(rr, a) { return (cx + rr * Math.cos(a)).toFixed(2) + ' ' + (cy + rr * Math.sin(a)).toFixed(2); }
            var soma = (d.revisitaram || 0) + (d.novos || 0);
            var meios = {}; // ângulo do meio das fatias rev/nov (p/ as linhas-guia)
            var s = '<svg class="dash-pie" viewBox="0 0 265 150">';
            if (comDado.length === 1) {
                s += '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="' + comDado[0].cor + '"/>';
                if (comDado[0].k !== 'nao') meios[comDado[0].k] = 0; // círculo inteiro: guia sai da direita
            } else {
                // Desenha rev -> novos -> não. O giro inicial é calculado para os
                // MEIOS das fatias rev e novos caírem simétricos ao eixo do número
                // (±θ do leste): as duas guias ficam do MESMO tamanho, e o "não"
                // sobra sempre do lado esquerdo.
                var ordem = [itens[0], itens[2], itens[1]].filter(function (f) { return f.n > 0; });
                var total = 0, i;
                for (i = 0; i < ordem.length; i++) total += ordem[i].n;
                var R = d.revisitaram / total * 2 * Math.PI; // arco de rev (rad)
                var N = d.novos / total * 2 * Math.PI;       // arco de novos
                var ang;
                if (R > 0 && N > 0)  ang = -(3 * R + N) / 4; // meio_rev = -θ, meio_nov = +θ
                else if (R > 0)      ang = -R / 2;           // só rev: meio no eixo
                else if (N > 0)      ang = -N / 2;           // só novos: meio no eixo
                else                 ang = -Math.PI / 2;     // só "não": tanto faz
                for (i = 0; i < ordem.length; i++) {
                    var a0 = ang, a1 = ang + ordem[i].n / total * 2 * Math.PI;
                    var laf = (a1 - a0) > Math.PI ? 1 : 0;
                    s += '<path d="M' + cx + ' ' + cy + ' L' + pt(r, a0) + ' A' + r + ' ' + r + ' 0 ' + laf + ' 1 ' + pt(r, a1) + ' Z" fill="' + ordem[i].cor + '"/>';
                    if (ordem[i].k !== 'nao') meios[ordem[i].k] = (a0 + a1) / 2;
                    ang = a1;
                }
            }
            // Linhas-guia: do meio das fatias de revisitas/novos até o número.
            var alvoX = 190, alvoY = 75;
            ['rev', 'nov'].forEach(function (k) {
                if (meios[k] === undefined) return;
                s += '<line class="dg-guia" x1="' + pt(r * 0.62, meios[k]).replace(' ', '" y1="') + '" x2="' + alvoX + '" y2="' + alvoY + '"/>';
            });
            // Número = revisitas + novos ("clientes do período"), à direita.
            s += '<text class="dash-donut-centro" x="222" y="79" text-anchor="middle">' + soma + '</text>';
            s += '<text class="dash-donut-sub" x="222" y="96" text-anchor="middle">cliente' + (soma === 1 ? '' : 's') + '</text>';
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
