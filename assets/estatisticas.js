/* Aba Estatísticas: duas curvas no mesmo gráfico — conexões totais e novos
   clientes — com filtros hoje/semana/mês/ano.

   As duas juntas em vez de um botão que alternava entre elas: a pergunta que
   o gráfico responde é "quanto do movimento é gente nova", e isso só se lê
   comparando as duas linhas ao mesmo tempo. Como todo novo cliente também é
   uma conexão, a linha de novos nunca passa da de totais — as duas dividem a
   mesma escala sem competir.

   Cada ponto é um balde do eixo (hora / dia / turno / mês) e vale a contagem
   real daquele balde: pessoas distintas que conectaram, e quantas delas eram
   a primeira vez. Config vem do #estatisticas-box (data-endpoint). */
(function () {
    var box = document.getElementById('estatisticas-box');
    if (!box) return;
    var EP      = box.getAttribute('data-endpoint');
    var wrap    = document.getElementById('est-wrap');
    var tooltip = document.getElementById('est-tooltip');
    var legenda = document.getElementById('est-legenda');
    var filtros = box.querySelectorAll('.est-filtro');
    if (!wrap) return;

    var W = 720, H = 300, PL = 10, PR = 46, PT = 14, PB = 26;  // escala Y na direita
    var dados = null;
    var DICA = {
        hoje:   'Cada ponto é uma hora do dia de hoje',
        semana: 'Cada ponto é um dia desta semana',
        mes:    'Três pontos por dia: manhã, tarde e noite',
        ano:    'Cada ponto é um mês deste ano'
    };
    var filtro = 'hoje';

    // Teto da escala: precisa ser MULTIPLO DE 4 do passo, senao as 4 linhas da
    // grade caem em numero quebrado (0, 4, 8, 11, 15 em vez de 0, 4, 8, 12, 16).
    function tetoBonito(max) {
        if (max <= 4) { return 4; }
        var pot = Math.pow(10, Math.floor(Math.log(max / 4) / Math.LN10));
        var cands = [1, 2, 2.5, 5, 10];
        for (var i = 0; i < cands.length; i++) {
            var passo = cands[i] * pot;
            // Passo quebrado (2,5 quando a potencia e 1) daria rotulo 2,5/7,5 —
            // arredondados viravam 3 e 8, fora do lugar na grade.
            if (passo % 1 !== 0) { continue; }
            if (passo * 4 >= max) { return passo * 4; }
        }
        return Math.ceil(max / (10 * pot)) * 10 * pot;
    }

    // Catmull-Rom -> cubicas de Bezier: a curva passa POR todos os pontos (nao
    // e aproximacao) e chega suave. O y dos controles fica preso entre os dois
    // pontos do trecho; sem essa trava, uma queda brusca joga a barriga da
    // curva abaixo de zero e o grafico mostra numero negativo que nao existe.
    function curva(pts) {
        if (!pts.length) { return ''; }
        var d = 'M' + pts[0][0].toFixed(1) + ' ' + pts[0][1].toFixed(1);
        if (pts.length === 1) { return d; }
        for (var i = 0; i < pts.length - 1; i++) {
            var p0 = pts[i - 1] || pts[i], p1 = pts[i], p2 = pts[i + 1], p3 = pts[i + 2] || p2;
            var lo = Math.min(p1[1], p2[1]), hi = Math.max(p1[1], p2[1]);
            var c1y = Math.max(lo, Math.min(hi, p1[1] + (p2[1] - p0[1]) / 6));
            var c2y = Math.max(lo, Math.min(hi, p2[1] - (p3[1] - p1[1]) / 6));
            d += 'C' + (p1[0] + (p2[0] - p0[0]) / 6).toFixed(1) + ' ' + c1y.toFixed(1) +
                 ' ' + (p2[0] - (p3[0] - p1[0]) / 6).toFixed(1) + ' ' + c2y.toFixed(1) +
                 ' ' + p2[0].toFixed(1) + ' ' + p2[1].toFixed(1);
        }
        return d;
    }

    // "+3" / "−1" / "=" — o menos e o sinal tipografico, nao o hifen.
    function delta(n) { return n > 0 ? '+' + n : (n < 0 ? '−' + Math.abs(n) : '='); }
    function seta(n)  { return n > 0 ? '▲ ' : (n < 0 ? '▼ ' : ''); }

    function render(d) {
        dados = d;
        var con = d.conectados, nov = d.novos, n = con.length, i;
        // Novo cliente tambem e uma conexao, entao o teto sai dos totais.
        var pico = 1;
        for (i = 0; i < n; i++) { if (con[i] > pico) { pico = con[i]; } }
        var teto = tetoBonito(pico);
        var innerH = H - PT - PB, innerW = W - PL - PR;
        var base = PT + innerH;
        var passo = innerW / n;
        var y = function (val) { return PT + innerH - (val / teto) * innerH; };
        var cxDe = function (k) { return PL + k * passo + passo / 2; };

        var s = '<svg class="est-svg" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="xMidYMid meet">';
        // Um degrade por curva (ao longo do tempo), o preenchimento da curva de
        // totais e o borrao que faz o brilho atras das linhas.
        s += '<defs>' +
             '<linearGradient id="est-g1" x1="0" y1="0" x2="1" y2="0">' +
                '<stop offset="0" class="est-c1a"/><stop offset=".55" class="est-c1b"/><stop offset="1" class="est-c1c"/>' +
             '</linearGradient>' +
             '<linearGradient id="est-g2" x1="0" y1="0" x2="1" y2="0">' +
                '<stop offset="0" class="est-c2a"/><stop offset="1" class="est-c2b"/>' +
             '</linearGradient>' +
             '<linearGradient id="est-g-area" x1="0" y1="0" x2="0" y2="1">' +
                '<stop offset="0" class="est-a1"/><stop offset="1" class="est-a2"/>' +
             '</linearGradient>' +
             '<filter id="est-brilho" x="-20%" y="-40%" width="140%" height="180%">' +
                '<feGaussianBlur stdDeviation="7"/>' +
             '</filter>' +
             '</defs>';

        // grade + escala na direita
        for (var g = 0; g <= 4; g++) {
            var gy = Math.round(PT + innerH - g * innerH / 4) + 0.5;
            s += '<line class="est-grid' + (g === 0 ? ' est-base' : '') + '" x1="' + PL + '" y1="' + gy + '" x2="' + (W - PR) + '" y2="' + gy + '"/>';
            s += '<text class="est-txt" x="' + (W - PR + 6) + '" y="' + (gy + 3.5) + '">' + Math.round(teto * g / 4) + '</text>';
        }
        // Rótulos X: no mês só o balde do meio de cada dia tem rótulo, então o
        // "pula alguns" corre sobre os que existem — pular por índice de balde
        // acertaria justamente as posições em branco.
        var eixo = d.eixo || d.labels;
        var comRotulo = [];
        for (i = 0; i < n; i++) { if (eixo[i]) { comRotulo.push(i); } }
        var pulo = Math.ceil(comRotulo.length / 12);
        for (var r = 0; r < comRotulo.length; r++) {
            if (r % pulo !== 0 && r !== comRotulo.length - 1) { continue; }
            i = comRotulo[r];
            var rx = cxDe(i);
            s += '<line class="est-grid-v" x1="' + (Math.round(rx) + 0.5) + '" y1="' + PT +
                 '" x2="' + (Math.round(rx) + 0.5) + '" y2="' + base + '"/>';
            s += '<text class="est-txt est-txt-x" x="' + rx.toFixed(1) + '" y="' + (H - 8) + '" text-anchor="middle">' + eixo[i] + '</text>';
        }

        // As duas curvas. Nenhum balde e pulado: parar depois de movimento e
        // queda a zero, e a linha tem que descer ate la.
        var p1 = [], p2 = [];
        for (i = 0; i < n; i++) { p1.push([cxDe(i), y(con[i])]); p2.push([cxDe(i), y(nov[i])]); }
        var d1 = curva(p1), d2 = curva(p2);

        // Preenchimento so sob os totais: a de novos fica sempre por dentro, e
        // duas areas empilhadas viravam mancha.
        s += '<path class="est-area" d="' + d1 + 'L' + p1[n - 1][0].toFixed(1) + ' ' + base +
             'L' + p1[0][0].toFixed(1) + ' ' + base + 'Z"/>';
        s += '<path class="est-l1-brilho" d="' + d1 + '" filter="url(#est-brilho)"/>';
        s += '<path class="est-l2-brilho" d="' + d2 + '" filter="url(#est-brilho)"/>';
        s += '<path class="est-l1" d="' + d1 + '"/>';
        s += '<path class="est-l2" d="' + d2 + '"/>';

        // Ponto final de cada curva, com o valor. Vai no ultimo balde COM
        // movimento, nao no ultimo do eixo: em "hoje" as horas que ainda nao
        // chegaram valem zero, e o marcador cairia no rodape sem dizer nada.
        function marcarFim(vals, pts, cls, acima) {
            var k = -1;
            for (var j = vals.length - 1; j >= 0; j--) { if (vals[j] > 0) { k = j; break; } }
            if (k < 0) { return ''; }
            var px = pts[k][0], py = pts[k][1];
            var fim = px > W - PR - 34;
            return '<circle class="est-ponto-halo ' + cls + '" cx="' + px.toFixed(1) + '" cy="' + py.toFixed(1) + '" r="7"/>' +
                   '<circle class="est-ponto ' + cls + '" cx="' + px.toFixed(1) + '" cy="' + py.toFixed(1) + '" r="3.2"/>' +
                   '<text class="est-valor ' + cls + '" x="' + (px + (fim ? -10 : 10)).toFixed(1) +
                   '" y="' + (py + (acima ? -9 : 16)).toFixed(1) + '" text-anchor="' + (fim ? 'end' : 'start') + '">' + vals[k] + '</text>';
        }
        // Totais acima da linha, novos abaixo: quando as duas terminam juntas,
        // os rotulos nao se sobrepoem.
        s += marcarFim(con, p1, 'est-f1', true);
        s += marcarFim(nov, p2, 'est-f2', false);

        // crosshair + marca em cada curva (escondidos até o hover)
        s += '<rect id="est-realce" class="est-realce" y="' + PT + '" height="' + innerH + '" style="display:none"/>';
        s += '<line id="est-cursor" class="est-cursor" y1="' + PT + '" y2="' + base + '" style="display:none"/>';
        s += '<circle id="est-marca1" class="est-marca est-f1" r="4" style="display:none"/>';
        s += '<circle id="est-marca2" class="est-marca est-f2" r="4" style="display:none"/>';
        s += '</svg>';
        wrap.innerHTML = s;
        wrap.appendChild(tooltip);
        wrap._p1 = p1;
        wrap._p2 = p2;

        if (legenda) {
            legenda.innerHTML =
                '<span class="est-leg est-leg-1">Conexões totais: <b>' + d.total_conectados + '</b></span>' +
                '<span class="est-leg est-leg-2">Novos clientes: <b>' + d.total_novos + '</b></span>' +
                '<span class="est-leg est-leg-dica">' + DICA[filtro] + '</span>';
        }
        ligarHover(passo);
    }

    function ligarHover(passo) {
        var svg = wrap.querySelector('svg');
        var cursor = document.getElementById('est-cursor');
        var realce = document.getElementById('est-realce');
        var m1 = document.getElementById('est-marca1');
        var m2 = document.getElementById('est-marca2');
        function mover(e) {
            if (!dados) return;
            var con = dados.conectados, nov = dados.novos, n = con.length;
            var r = svg.getBoundingClientRect();
            var mx = (e.clientX - r.left) * (W / r.width);     // px -> viewBox
            var idx = Math.floor((mx - PL) / passo);
            if (idx < 0) idx = 0;
            if (idx > n - 1) idx = n - 1;
            var cx = PL + idx * passo + passo / 2;
            cursor.setAttribute('x1', cx); cursor.setAttribute('x2', cx); cursor.style.display = '';
            realce.setAttribute('x', PL + idx * passo); realce.setAttribute('width', passo); realce.style.display = '';
            if (wrap._p1 && wrap._p1[idx]) {
                m1.setAttribute('cx', wrap._p1[idx][0]); m1.setAttribute('cy', wrap._p1[idx][1]); m1.style.display = '';
                m2.setAttribute('cx', wrap._p2[idx][0]); m2.setAttribute('cy', wrap._p2[idx][1]); m2.style.display = '';
            }

            var html = '<b>' + dados.labels[idx] + '</b>' +
                '<span class="est-tt-p1">Conexões totais <i>' + con[idx] + '</i></span>' +
                '<span class="est-tt-p2">Novos clientes <i>' + nov[idx] + '</i></span>';
            // Variação contra o ponto à esquerda. No primeiro do eixo não há
            // com o que comparar, então a linha simplesmente não aparece.
            if (idx > 0) {
                var dCon = con[idx] - con[idx - 1], dNov = nov[idx] - nov[idx - 1];
                html += '<span class="est-tt-var">Ante ' + dados.labels[idx - 1] + '<i>' +
                    '<em class="est-tt-p1">' + seta(dCon) + delta(dCon) + '</em>' +
                    '<em class="est-tt-p2">' + seta(dNov) + delta(dNov) + '</em>' +
                    '</i></span>';
            }
            tooltip.innerHTML = html;

            tooltip.style.display = 'block';
            var px = cx * (r.width / W);
            var tw = tooltip.offsetWidth;
            tooltip.style.left = Math.min(Math.max(px + 12, 0), r.width - tw - 4) + 'px';
            tooltip.style.top = '8px';
        }
        function sair() {
            tooltip.style.display = 'none';
            cursor.style.display = 'none';
            realce.style.display = 'none';
            m1.style.display = 'none';
            m2.style.display = 'none';
        }
        svg.addEventListener('mousemove', mover);
        svg.addEventListener('mouseleave', sair);
        svg.addEventListener('touchmove', function (e) { if (e.touches[0]) mover(e.touches[0]); }, { passive: true });
        svg.addEventListener('touchend', sair);
    }

    function carregar(f) {
        filtro = f;
        for (var i = 0; i < filtros.length; i++) {
            filtros[i].classList.toggle('atual', filtros[i].getAttribute('data-filtro') === f);
        }
        wrap.innerHTML = '<p class="pc-anuncio-desc">Carregando…</p>';
        fetch(EP + (EP.indexOf('?') >= 0 ? '&' : '?') + 'filtro=' + encodeURIComponent(f),
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
