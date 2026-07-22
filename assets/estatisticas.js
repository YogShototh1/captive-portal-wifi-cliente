/* Aba Estatísticas: gráfico de linhas (SVG puro) com filtros hoje/semana/mês/ano
   e tooltip no hover mostrando o horário/data e os valores do ponto.
   Config vem do #estatisticas-box (data-endpoint). */
(function () {
    var box = document.getElementById('estatisticas-box');
    if (!box) return;
    var EP      = box.getAttribute('data-endpoint');
    var wrap    = document.getElementById('est-wrap');
    var tooltip = document.getElementById('est-tooltip');
    var legenda = document.getElementById('est-legenda');
    var filtros = box.querySelectorAll('.est-filtro');
    if (!wrap) return;

    var W = 720, H = 260, PL = 38, PR = 12, PT = 12, PB = 26; // viewBox e margens
    var dados = null;

    // Escala Y "bonita": teto múltiplo de 1/2/5 * 10^k, 5 faixas.
    function tetoBonito(max) {
        if (max <= 5) return 5;
        var bruto = max / 5, pot = Math.pow(10, Math.floor(Math.log(bruto) / Math.LN10)), n = bruto / pot;
        var passo = (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * pot;
        return Math.ceil(max / passo) * passo;
    }

    // Caminho suave (Catmull-Rom -> Bézier). Os pontos de controle são
    // CLAMPADOS ao intervalo vertical do gráfico [yMin, yMax]: sem isso a curva
    // "passa do zero" (overshoot) entre um pico e um vizinho zerado.
    function caminho(pts, yMin, yMax) {
        if (pts.length < 2) return '';
        function cl(y) { return Math.min(yMax, Math.max(yMin, y)); }
        var d = 'M' + pts[0][0].toFixed(1) + ' ' + pts[0][1].toFixed(1);
        for (var i = 0; i < pts.length - 1; i++) {
            var p0 = pts[Math.max(0, i - 1)], p1 = pts[i], p2 = pts[i + 1], p3 = pts[Math.min(pts.length - 1, i + 2)];
            var c1x = p1[0] + (p2[0] - p0[0]) / 6, c1y = cl(p1[1] + (p2[1] - p0[1]) / 6);
            var c2x = p2[0] - (p3[0] - p1[0]) / 6, c2y = cl(p2[1] - (p3[1] - p1[1]) / 6);
            d += 'C' + c1x.toFixed(1) + ' ' + c1y.toFixed(1) + ',' + c2x.toFixed(1) + ' ' + c2y.toFixed(1) + ',' + p2[0].toFixed(1) + ' ' + p2[1].toFixed(1);
        }
        return d;
    }

    function pontos(valores, teto) {
        var n = valores.length, innerW = W - PL - PR, innerH = H - PT - PB, pts = [];
        for (var i = 0; i < n; i++) {
            pts.push([PL + (n > 1 ? i * innerW / (n - 1) : innerW / 2), PT + innerH - (valores[i] / teto) * innerH]);
        }
        return pts;
    }

    function render(d) {
        dados = d;
        var teto = tetoBonito(Math.max.apply(null, d.conectados.concat(d.novos, [1])));
        var innerH = H - PT - PB, innerW = W - PL - PR;
        var s = '<svg class="est-svg" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="xMidYMid meet">';
        // grade + rótulos Y (5 faixas)
        for (var g = 0; g <= 5; g++) {
            var y = PT + innerH - g * innerH / 5;
            s += '<line class="est-grid" x1="' + PL + '" y1="' + y + '" x2="' + (W - PR) + '" y2="' + y + '"/>';
            s += '<text class="est-txt" x="' + (PL - 6) + '" y="' + (y + 3.5) + '" text-anchor="end">' + Math.round(teto * g / 5) + '</text>';
        }
        // rótulos X (pula alguns para não amontoar)
        var n = d.labels.length, pulo = Math.ceil(n / 10);
        for (var i = 0; i < n; i++) {
            if (i % pulo !== 0 && i !== n - 1) continue;
            var x = PL + (n > 1 ? i * innerW / (n - 1) : innerW / 2);
            s += '<text class="est-txt" x="' + x + '" y="' + (H - 8) + '" text-anchor="middle">' + d.labels[i] + '</text>';
        }
        // séries: área + linha (conectados = azul, novos = branco)
        var pc = pontos(d.conectados, teto), pn = pontos(d.novos, teto);
        var base = PT + innerH;
        s += '<path class="est-area-con" d="' + caminho(pc, PT, base) + ' L' + (W - PR) + ' ' + base + ' L' + PL + ' ' + base + ' Z"/>';
        s += '<path class="est-area-nov" d="' + caminho(pn, PT, base) + ' L' + (W - PR) + ' ' + base + ' L' + PL + ' ' + base + ' Z"/>';
        s += '<path class="est-linha-con" d="' + caminho(pc, PT, base) + '"/>';
        s += '<path class="est-linha-nov" d="' + caminho(pn, PT, base) + '"/>';
        // marcadores do hover (escondidos até passar o mouse)
        s += '<line id="est-cursor" class="est-cursor" y1="' + PT + '" y2="' + base + '" style="display:none"/>';
        s += '<circle id="est-dot-con" class="est-dot est-dot-con" r="4" style="display:none"/>';
        s += '<circle id="est-dot-nov" class="est-dot est-dot-nov" r="4" style="display:none"/>';
        s += '</svg>';
        wrap.innerHTML = s;
        wrap.appendChild(tooltip);
        if (legenda) {
            legenda.innerHTML = '<span class="est-leg est-leg-con">Conectados: <b>' + d.total_conectados + '</b></span>' +
                                '<span class="est-leg est-leg-nov">Novos clientes: <b>' + d.total_novos + '</b></span>';
        }
        ligarHover(pc, pn, teto);
    }

    function ligarHover(pc, pn) {
        var svg = wrap.querySelector('svg');
        var cursor = document.getElementById('est-cursor');
        var dotC = document.getElementById('est-dot-con');
        var dotN = document.getElementById('est-dot-nov');
        function mover(e) {
            if (!dados) return;
            var r = svg.getBoundingClientRect();
            var mx = (e.clientX - r.left) * (W / r.width); // px -> coordenada do viewBox
            var n = dados.labels.length, innerW = W - PL - PR;
            var idx = Math.round((mx - PL) / (n > 1 ? innerW / (n - 1) : innerW));
            if (idx < 0) idx = 0;
            if (idx > n - 1) idx = n - 1;
            var x = pc[idx][0];
            cursor.setAttribute('x1', x); cursor.setAttribute('x2', x); cursor.style.display = '';
            dotC.setAttribute('cx', x); dotC.setAttribute('cy', pc[idx][1]); dotC.style.display = '';
            dotN.setAttribute('cx', x); dotN.setAttribute('cy', pn[idx][1]); dotN.style.display = '';
            tooltip.innerHTML = '<b>' + dados.labels[idx] + '</b>' +
                '<span class="est-tt-con">Conectados: ' + dados.conectados[idx] + '</span>' +
                '<span class="est-tt-nov">Novos clientes: ' + dados.novos[idx] + '</span>';
            tooltip.style.display = 'block';
            // posiciona ao lado do ponto, sem sair do gráfico
            var px = x * (r.width / W), py = pc[idx][1] * (r.height / H);
            var tw = tooltip.offsetWidth;
            tooltip.style.left = Math.min(Math.max(px + 12, 0), r.width - tw - 4) + 'px';
            tooltip.style.top = Math.max(py - 10, 0) + 'px';
        }
        function sair() {
            tooltip.style.display = 'none';
            cursor.style.display = 'none'; dotC.style.display = 'none'; dotN.style.display = 'none';
        }
        svg.addEventListener('mousemove', mover);
        svg.addEventListener('mouseleave', sair);
        svg.addEventListener('touchmove', function (e) { if (e.touches[0]) mover(e.touches[0]); }, { passive: true });
        svg.addEventListener('touchend', sair);
    }

    function carregar(filtro) {
        for (var i = 0; i < filtros.length; i++) {
            filtros[i].classList.toggle('atual', filtros[i].getAttribute('data-filtro') === filtro);
        }
        wrap.innerHTML = '<p class="pc-anuncio-desc">Carregando…</p>';
        fetch(EP + (EP.indexOf('?') >= 0 ? '&' : '?') + 'filtro=' + encodeURIComponent(filtro),
              { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { wrap.innerHTML = '<p class="pc-anuncio-desc">Erro ao carregar as estatísticas.</p>'; return; }
                render(d);
            })
            .catch(function () { wrap.innerHTML = '<p class="pc-anuncio-desc">Erro ao carregar as estatísticas.</p>'; });
    }

    for (var i = 0; i < filtros.length; i++) {
        filtros[i].addEventListener('click', function () { carregar(this.getAttribute('data-filtro')); });
    }
    carregar('hoje');
})();
