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

    // Pizza (cheia) + legenda de um período.
    // As TRÊS fatias de quem esteve no período (revisitaram / reativados /
    // novos) ficam juntas, centradas no eixo do número, e somam exatamente o
    // número mostrado à direita — que é o mesmo da comparação embaixo.
    // "Não revisitaram" (esteve na janela anterior e não voltou) fica sempre do
    // lado ESQUERDO e fora dessa soma.
    function donutPeriodo(titulo, d, rotuloVar) {
        // As cores vivem no CSS (.dg-c-*), nao aqui: "Novos" usa a cor de texto
        // do tema, entao fica preto no claro e branco no escuro — se fosse um
        // preto fixo, sumiria no fundo escuro do cartao.
        var itens = [
            { k: 'rev', nome: 'Revisitaram',      n: d.revisitaram || 0,     cls: 'dg-c-rev' },
            { k: 'rea', nome: 'Reativados',       n: d.reativados || 0,      cls: 'dg-c-rea' },
            { k: 'nov', nome: 'Novos',            n: d.novos || 0,           cls: 'dg-c-nov' },
            { k: 'nao', nome: 'Não revisitaram', n: d.nao_revisitaram || 0, cls: 'dg-c-nao' }
        ];
        var comDado = itens.filter(function (f) { return f.n > 0; });
        var corpo;
        if (!d.total || !comDado.length) {
            corpo = '<p class="pc-anuncio-desc">Sem dados no período.</p>';
        } else {
            var cx = 75, cy = 75, r = 70;
            function pt(rr, a) { return (cx + rr * Math.cos(a)).toFixed(2) + ' ' + (cy + rr * Math.sin(a)).toFixed(2); }
            var presentes = [itens[0], itens[1], itens[2]];   // rev, reativados, novos
            var naoN = itens[3].n;
            var soma = presentes[0].n + presentes[1].n + presentes[2].n;
            var total = soma + naoN;
            var TAU = 2 * Math.PI;
            // Arco do grupo "esteve no período", centrado no eixo leste (o do
            // número): comeca em -P/2 e termina em +P/2. O "não revisitaram"
            // ocupa o resto, sempre do lado esquerdo.
            var P = total ? soma / total * TAU : 0;
            var s = '<svg class="dash-pie" viewBox="0 0 265 150">';
            if (comDado.length === 1) {
                s += '<circle class="' + comDado[0].cls + '" cx="' + cx + '" cy="' + cy + '" r="' + r + '"/>';
            } else {
                var ang = -P / 2, i, a0, a1, laf;
                var ordem = presentes.filter(function (f) { return f.n > 0; });
                if (naoN > 0) ordem = ordem.concat([itens[3]]);
                for (i = 0; i < ordem.length; i++) {
                    a0 = ang; a1 = ang + ordem[i].n / total * TAU;
                    laf = (a1 - a0) > Math.PI ? 1 : 0;
                    s += '<path class="' + ordem[i].cls + '" d="M' + cx + ' ' + cy + ' L' + pt(r, a0) + ' A' + r + ' ' + r + ' 0 ' + laf + ' 1 ' + pt(r, a1) + ' Z"/>';
                    ang = a1;
                }
            }
            // Linhas-guia: das duas pontas do grupo até o número, como uma
            // chave. Simetricas por construcao (±g); limitadas a 45 graus para
            // nao atravessarem a pizza quando o grupo e quase o circulo todo.
            var alvoX = 190, alvoY = 75;
            if (soma > 0) {
                var g = Math.min(P / 2, Math.PI / 4);
                [-g, g].forEach(function (a) {
                    s += '<line class="dg-guia" x1="' + pt(r * 0.62, a).replace(' ', '" y1="') + '" x2="' + alvoX + '" y2="' + alvoY + '"/>';
                });
            }
            // Número = revisitaram + reativados + novos = clientes do período.
            s += '<text class="dash-donut-centro" x="222" y="79" text-anchor="middle">' + soma + '</text>';
            s += '<text class="dash-donut-sub" x="222" y="96" text-anchor="middle">cliente' + (soma === 1 ? '' : 's') + '</text>';
            s += '</svg>';

            var ps = pctsInt(itens.map(function (f) { return f.n; }));
            var leg = '<ul class="dash-legenda">';
            for (var j = 0; j < itens.length; j++) {
                // Quantidade real ao lado da % — zerado mostra só "0%".
                var qtd = itens[j].n > 0 ? ' <small>(' + itens[j].n + ')</small>' : '';
                leg += '<li><span class="leg-cor ' + itens[j].cls + '"></span>' +
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
