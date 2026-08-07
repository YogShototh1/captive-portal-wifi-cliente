/* Aba Estatísticas: curva de intensidade (SVG puro) com filtros
   hoje/semana/mês/ano e um botão para alternar entre todas as conexões e só
   as novas. Cada ponto é um balde do eixo; o valor é a intensidade dentro
   dele (pessoas por fatia de 10 min / por hora / por dia), medida de contagem
   real, não de estimativa.

   A curva é a MESMA que as velas desenhavam: cada balde abre no fechamento do
   anterior, então ligar os fechamentos reproduz exatamente o caminho que os
   corpos das velas descreviam. Abertura, máxima, mínima e fechamento seguem
   no tooltip — mudou o desenho, não o que é medido.

   Config vem do #estatisticas-box (data-endpoint). */
(function () {
    var box = document.getElementById('estatisticas-box');
    if (!box) return;
    var EP      = box.getAttribute('data-endpoint');
    var wrap    = document.getElementById('est-wrap');
    var tooltip = document.getElementById('est-tooltip');
    var legenda = document.getElementById('est-legenda');
    var filtros = box.querySelectorAll('.est-filtro');
    var series  = box.querySelectorAll('.est-serie');
    if (!wrap) return;

    var W = 720, H = 300, PL = 10, PR = 46, PT = 14, PB = 26;  // escala Y na direita, como nos home brokers
    var dados = null;
    var serie  = 'conectados';   // ou 'novos'
    var DICA = {
        hoje:   'Cada ponto é uma hora, medida em fatias de 10 minutos',
        semana: 'Cada ponto é um dia, medido hora a hora',
        mes:    'Três pontos por dia: manhã, tarde e noite',
        ano:    'Cada ponto é um mês, medido dia a dia'
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

    function velasDe(d)  { return serie === 'novos' ? d.velas_novos : d.velas_conectados; }
    function totaisDe(d) { return serie === 'novos' ? d.novos : d.conectados; }

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

    function render(d) {
        dados = d;
        var v = velasDe(d), n = v.length, i;
        var pico = 1;
        for (i = 0; i < n; i++) { if (v[i][1] > pico) pico = v[i][1]; }
        var teto = tetoBonito(pico);
        var innerH = H - PT - PB, innerW = W - PL - PR;
        var base = PT + innerH;
        var passo = innerW / n;                     // uma faixa por balde
        var y = function (val) { return PT + innerH - (val / teto) * innerH; };
        var cxDe = function (k) { return PL + k * passo + passo / 2; };

        var s = '<svg class="est-svg" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="xMidYMid meet">';
        // Gradiente do traço (ao longo do tempo), do preenchimento (some para
        // baixo) e o borrão que faz o brilho atrás da linha.
        s += '<defs>' +
             '<linearGradient id="est-g-traco" x1="0" y1="0" x2="1" y2="0">' +
                '<stop offset="0" class="est-c1"/><stop offset=".55" class="est-c2"/><stop offset="1" class="est-c3"/>' +
             '</linearGradient>' +
             '<linearGradient id="est-g-area" x1="0" y1="0" x2="0" y2="1">' +
                '<stop offset="0" class="est-a1"/><stop offset="1" class="est-a2"/>' +
             '</linearGradient>' +
             '<filter id="est-brilho" x="-20%" y="-40%" width="140%" height="180%">' +
                '<feGaussianBlur stdDeviation="7"/>' +
             '</filter>' +
             '</defs>';

        // Barras ao fundo: o TOTAL de cada balde. Escala própria (o total é
        // outra grandeza que a intensidade da curva), por isso ficam apagadas e
        // sem eixo — servem de textura do movimento, e o número exato está no
        // tooltip. Altura máxima de 62% para não competir com a linha.
        var tot = totaisDe(d), picoT = 1;
        for (i = 0; i < n; i++) { if (tot[i] > picoT) { picoT = tot[i]; } }
        var lb = Math.max(1.5, Math.min(9, passo * 0.42));
        s += '<g class="est-barras">';
        for (i = 0; i < n; i++) {
            if (!tot[i]) { continue; }
            var hb = (tot[i] / picoT) * innerH * 0.62;
            s += '<rect x="' + (cxDe(i) - lb / 2).toFixed(1) + '" y="' + (base - hb).toFixed(1) +
                 '" width="' + lb.toFixed(1) + '" height="' + hb.toFixed(1) + '" rx="1"/>';
        }
        s += '</g>';

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
            // Coluna sob cada rótulo: dá a leitura de onde o tempo passa sem
            // pesar. Fica atrás de tudo porque vem antes da curva no SVG.
            s += '<line class="est-grid-v" x1="' + (Math.round(rx) + 0.5) + '" y1="' + PT +
                 '" x2="' + (Math.round(rx) + 0.5) + '" y2="' + base + '"/>';
            s += '<text class="est-txt est-txt-x" x="' + rx.toFixed(1) + '" y="' + (H - 8) + '" text-anchor="middle">' + eixo[i] + '</text>';
        }

        // A curva: fechamento de cada balde. Nenhum é pulado — parar depois de
        // movimento é queda a zero, e a linha tem que descer até lá.
        var pts = [];
        for (i = 0; i < n; i++) { pts.push([cxDe(i), y(v[i][3])]); }
        var dLinha = curva(pts);
        s += '<path class="est-area" d="' + dLinha + 'L' + pts[n - 1][0].toFixed(1) + ' ' + base +
             'L' + pts[0][0].toFixed(1) + ' ' + base + 'Z"/>';
        s += '<path class="est-linha-brilho" d="' + dLinha + '" filter="url(#est-brilho)"/>';
        s += '<path class="est-linha" d="' + dLinha + '"/>';

        // Ponto final marcado, como no painel de indicador. Vai no último balde
        // COM movimento, não no último do eixo: em "hoje" as horas que ainda não
        // chegaram valem zero, e o marcador cairia no rodapé sem dizer nada.
        var ult = -1;
        for (i = n - 1; i >= 0; i--) { if (v[i][3] > 0) { ult = i; break; } }
        if (ult >= 0) {
            var px = pts[ult][0], py = pts[ult][1];
            s += '<circle class="est-ponto-halo" cx="' + px.toFixed(1) + '" cy="' + py.toFixed(1) + '" r="7"/>';
            s += '<circle class="est-ponto" cx="' + px.toFixed(1) + '" cy="' + py.toFixed(1) + '" r="3.2"/>';
            // Encosta na borda direita? O rótulo vira para dentro do gráfico.
            var fim = px > W - PR - 34;
            s += '<text class="est-valor" x="' + (px + (fim ? -10 : 10)).toFixed(1) + '" y="' + (py - 9).toFixed(1) +
                 '" text-anchor="' + (fim ? 'end' : 'start') + '">' + v[ult][3] + '</text>';
        }

        // crosshair + ponto que corre sobre a linha (escondidos até o hover)
        s += '<rect id="est-realce" class="est-realce" y="' + PT + '" height="' + innerH + '" style="display:none"/>';
        s += '<line id="est-cursor" class="est-cursor" y1="' + PT + '" y2="' + base + '" style="display:none"/>';
        s += '<circle id="est-marca" class="est-marca" r="4" style="display:none"/>';
        s += '</svg>';
        wrap.innerHTML = s;
        wrap.appendChild(tooltip);
        // O hover precisa da posição de cada ponto para colar a marca na linha.
        wrap._pts = pts;

        if (legenda) {
            var tot = serie === 'novos' ? d.total_novos : d.total_conectados;
            legenda.innerHTML = '<span class="est-leg">' +
                (serie === 'novos' ? 'Novos clientes' : 'Pessoas conectadas') + ' no período: <b>' + tot + '</b></span>' +
                '<span class="est-leg est-leg-dica">' + DICA[filtro] + '</span>';
        }
        ligarHover(passo);
    }

    function ligarHover(passo) {
        var svg = wrap.querySelector('svg');
        var cursor = document.getElementById('est-cursor');
        var realce = document.getElementById('est-realce');
        var marca  = document.getElementById('est-marca');
        function mover(e) {
            if (!dados) return;
            var v = velasDe(dados), n = v.length;
            var r = svg.getBoundingClientRect();
            var mx = (e.clientX - r.left) * (W / r.width);     // px -> viewBox
            var idx = Math.floor((mx - PL) / passo);
            if (idx < 0) idx = 0;
            if (idx > n - 1) idx = n - 1;
            var cx = PL + idx * passo + passo / 2;
            cursor.setAttribute('x1', cx); cursor.setAttribute('x2', cx); cursor.style.display = '';
            realce.setAttribute('x', PL + idx * passo); realce.setAttribute('width', passo); realce.style.display = '';
            var p = wrap._pts && wrap._pts[idx];
            if (marca && p) {
                marca.setAttribute('cx', p[0]); marca.setAttribute('cy', p[1]); marca.style.display = '';
            }
            var o = v[idx][0], hi = v[idx][1], lo = v[idx][2], c = v[idx][3];
            var dif = c - o;
            if (filtro === 'mes') {
                // A vela do mês já é um pedaço do dia: aqui interessa quanto
                // deu naquele turno, não o vaivém dentro dele.
                tooltip.innerHTML = '<b>' + dados.labels[idx] + '</b>' +
                    '<span>' + (serie === 'novos' ? 'Novos clientes' : 'Conexões') +
                    ' <i>' + totaisDe(dados)[idx] + '</i></span>';
            } else {
                // Os mesmos quatro números das velas, com nome de curva: o que
                // era abertura/fechamento agora é por onde a linha entra e sai
                // do ponto. Nada mudou na medição.
                tooltip.innerHTML = '<b>' + dados.labels[idx] + '</b>' +
                    '<span>Entrou em <i>' + o + '</i></span>' +
                    '<span>Pico <i>' + hi + '</i></span>' +
                    '<span>Mínimo <i>' + lo + '</i></span>' +
                    '<span>Saiu em <i>' + c + '</i></span>' +
                    '<span class="est-tt-tot">Total no período <i>' + totaisDe(dados)[idx] + '</i></span>' +
                    '<span class="' + (dif >= 0 ? 'est-tt-alta' : 'est-tt-baixa') + '">' +
                        (dif >= 0 ? '▲ +' : '▼ ') + dif + '</span>';
            }
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
            if (marca) { marca.style.display = 'none'; }
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
    // Trocar de série não refaz a consulta: os dois conjuntos já vieram juntos.
    for (i = 0; i < series.length; i++) {
        series[i].addEventListener('click', function () {
            serie = this.getAttribute('data-serie');
            for (var j = 0; j < series.length; j++) {
                series[j].classList.toggle('atual', series[j] === this);
            }
            if (dados) { render(dados); }
        });
    }
    carregar('hoje');
})();
