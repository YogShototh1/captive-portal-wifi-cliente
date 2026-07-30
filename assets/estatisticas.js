/* Aba Estatísticas: gráfico de VELAS (candlestick, SVG puro) com filtros
   hoje/semana/mês/ano e um botão para alternar entre todas as conexões e só
   as novas. Cada vela é um balde do eixo; o "preço" é a intensidade dentro
   dele (pessoas por fatia de 10 min / por hora / por dia), então abertura,
   máxima, mínima e fechamento saem de contagem real, não de estimativa.
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
        hoje:   'Cada vela é uma hora, medida em fatias de 10 minutos',
        semana: 'Cada vela é um dia, medido hora a hora',
        mes:    'Três velas por dia: manhã, tarde e noite',
        ano:    'Cada vela é um mês, medido dia a dia'
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

    function render(d) {
        dados = d;
        var v = velasDe(d), n = v.length, i;
        var pico = 1;
        for (i = 0; i < n; i++) { if (v[i][1] > pico) pico = v[i][1]; }
        var teto = tetoBonito(pico);
        var innerH = H - PT - PB, innerW = W - PL - PR;
        var base = PT + innerH;
        var passo = innerW / n;                     // uma faixa por vela
        // Corpo estreito, com folga entre as velas: 16px de largura em escala
        // de 0 a 4 virava bloco, nao vela.
        var larg  = Math.max(1.5, Math.min(7, passo * 0.5));
        var y = function (val) { return PT + innerH - (val / teto) * innerH; };

        var s = '<svg class="est-svg" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="xMidYMid meet">';
        // grade + escala na direita
        for (var g = 0; g <= 4; g++) {
            var gy = Math.round(PT + innerH - g * innerH / 4) + 0.5;
            s += '<line class="est-grid' + (g === 0 ? ' est-base' : '') + '" x1="' + PL + '" y1="' + gy + '" x2="' + (W - PR) + '" y2="' + gy + '"/>';
            s += '<text class="est-txt" x="' + (W - PR + 6) + '" y="' + (gy + 3.5) + '">' + Math.round(teto * g / 4) + '</text>';
        }
        // Rótulos X: no mês só a vela do meio de cada dia tem rótulo, então o
        // "pula alguns" corre sobre os que existem — pular por índice de vela
        // acertaria justamente as posições em branco.
        var eixo = d.eixo || d.labels;
        var comRotulo = [];
        for (i = 0; i < n; i++) { if (eixo[i]) { comRotulo.push(i); } }
        var pulo = Math.ceil(comRotulo.length / 12);
        for (var r = 0; r < comRotulo.length; r++) {
            if (r % pulo !== 0 && r !== comRotulo.length - 1) { continue; }
            i = comRotulo[r];
            s += '<text class="est-txt est-txt-x" x="' + (PL + i * passo + passo / 2).toFixed(1) + '" y="' + (H - 8) + '" text-anchor="middle">' + eixo[i] + '</text>';
        }
        // velas
        // Nenhum balde e pulado: parado depois de movimento e queda a zero, e
        // isso precisa aparecer como vela vermelha.
        for (i = 0; i < n; i++) {
            var o = v[i][0], hi = v[i][1], lo = v[i][2], c = v[i][3];
            if (!hi && !o && !c && !(i > 0 && v[i - 1][3])) { continue; }   // zero sobre zero: nada a mostrar
            var cx = Math.round(PL + i * passo + passo / 2) + 0.5;         // meio pixel = traco nitido
            var cls = (c >= o) ? 'est-alta' : 'est-baixa';
            // pavio: da mínima à máxima
            s += '<line class="est-pavio ' + cls + '" x1="' + cx + '" y1="' + y(hi).toFixed(1) +
                 '" x2="' + cx + '" y2="' + y(lo).toFixed(1) + '"/>';
            // corpo: entre abertura e fechamento (piso de 1,5px p/ o doji aparecer)
            var yo = y(o), yc = y(c);
            s += '<rect class="est-corpo ' + cls + '" x="' + (cx - larg / 2).toFixed(1) + '" y="' + Math.min(yo, yc).toFixed(1) +
                 '" width="' + larg.toFixed(1) + '" height="' + Math.max(1.5, Math.abs(yc - yo)).toFixed(1) + '"/>';
        }
        // crosshair (escondido até o hover)
        s += '<rect id="est-realce" class="est-realce" y="' + PT + '" height="' + innerH + '" style="display:none"/>';
        s += '<line id="est-cursor" class="est-cursor" y1="' + PT + '" y2="' + base + '" style="display:none"/>';
        s += '</svg>';
        wrap.innerHTML = s;
        wrap.appendChild(tooltip);

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
            var o = v[idx][0], hi = v[idx][1], lo = v[idx][2], c = v[idx][3];
            var dif = c - o;
            if (filtro === 'mes') {
                // A vela do mês já é um pedaço do dia: aqui interessa quanto
                // deu naquele turno, não o vaivém dentro dele.
                tooltip.innerHTML = '<b>' + dados.labels[idx] + '</b>' +
                    '<span>' + (serie === 'novos' ? 'Novos clientes' : 'Conexões') +
                    ' <i>' + totaisDe(dados)[idx] + '</i></span>';
            } else {
                tooltip.innerHTML = '<b>' + dados.labels[idx] + '</b>' +
                    '<span>Abertura <i>' + o + '</i></span>' +
                    '<span>Máxima <i>' + hi + '</i></span>' +
                    '<span>Mínima <i>' + lo + '</i></span>' +
                    '<span>Fechamento <i>' + c + '</i></span>' +
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
