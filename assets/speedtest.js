/* Aba "Teste de velocidade": mede a conexão entre este aparelho e o servidor
   do painel, no formato do Speedtest (ponteiro, escala comprimida embaixo).

   O que ele mede — e o que NÃO mede: o servidor é sempre a hospedagem, não um
   próximo de você, e a medição usa uma conexão só. O número serve para
   comparar ("o Wi-Fi da loja está pior que ontem?") mais do que para conferir a
   banda contratada. Isso está dito na tela, não só aqui.

   Config vem do #speed-box (data-endpoint). */
(function () {
    var box = document.getElementById('speed-box');
    if (!box) return;
    var EP = box.getAttribute('data-endpoint');
    var btn   = document.getElementById('sp-iniciar');
    var arco  = document.getElementById('sp-arco');
    var ponte = document.getElementById('sp-ponteiro');
    var vNum  = document.getElementById('sp-valor');
    var vUni  = document.getElementById('sp-unidade');
    var elDown = document.getElementById('sp-down');
    var elUp   = document.getElementById('sp-up');
    var elPing = document.getElementById('sp-ping');
    var elFase = document.getElementById('sp-fase');
    if (!btn || !arco) return;

    // --- Escala: as marcas NÃO são linearmente espaçadas ---
    // Cada intervalo entre marcas ocupa a mesma fatia do arco. É o que deixa o
    // trecho de 0 a 100 (onde quase todo mundo cai) com espaço de sobra, e
    // amontoa os 250-1000 no fim — igual ao mostrador do Speedtest.
    var MARCAS = [0, 5, 10, 50, 100, 250, 500, 750, 1000];
    var A0 = 145, VARRE = 250;          // graus: começa embaixo à esquerda

    function fracao(mbps) {
        if (mbps <= 0) return 0;
        if (mbps >= MARCAS[MARCAS.length - 1]) return 1;
        for (var i = 0; i < MARCAS.length - 1; i++) {
            if (mbps <= MARCAS[i + 1]) {
                var dentro = (mbps - MARCAS[i]) / (MARCAS[i + 1] - MARCAS[i]);
                return (i + dentro) / (MARCAS.length - 1);
            }
        }
        return 1;
    }
    function angulo(mbps) { return A0 + VARRE * fracao(mbps); }
    function ponto(cx, cy, r, grau) {
        var rad = grau * Math.PI / 180;
        return [cx + r * Math.cos(rad), cy + r * Math.sin(rad)];
    }
    // Caminho de arco entre dois ângulos (sempre no sentido horário aqui).
    function arcoPath(cx, cy, r, g1, g2) {
        var a = ponto(cx, cy, r, g1), b = ponto(cx, cy, r, g2);
        var maior = (g2 - g1) > 180 ? 1 : 0;
        return 'M' + a[0].toFixed(1) + ' ' + a[1].toFixed(1) +
               'A' + r + ' ' + r + ' 0 ' + maior + ' 1 ' + b[0].toFixed(1) + ' ' + b[1].toFixed(1);
    }

    var CX = 160, CY = 150, R = 118;

    // Marcas e números, desenhados uma vez.
    (function desenharEscala() {
        var g = document.getElementById('sp-marcas');
        if (!g) return;
        var s = '';
        for (var i = 0; i < MARCAS.length; i++) {
            var gr = A0 + VARRE * (i / (MARCAS.length - 1));
            var p1 = ponto(CX, CY, R - 16, gr), p2 = ponto(CX, CY, R - 8, gr);
            s += '<line class="sp-tick" x1="' + p1[0].toFixed(1) + '" y1="' + p1[1].toFixed(1) +
                 '" x2="' + p2[0].toFixed(1) + '" y2="' + p2[1].toFixed(1) + '"/>';
            var pt = ponto(CX, CY, R - 32, gr);
            s += '<text class="sp-tick-tx" x="' + pt[0].toFixed(1) + '" y="' + (pt[1] + 4).toFixed(1) +
                 '" text-anchor="middle">' + MARCAS[i] + '</text>';
        }
        g.innerHTML = s;
    })();

    var fundo = document.getElementById('sp-fundo');
    if (fundo) fundo.setAttribute('d', arcoPath(CX, CY, R, A0, A0 + VARRE));

    // --- Ponteiro e arco de progresso ---
    var atual = 0, alvo = 0, animando = false;
    function pintar(v) {
        var gr = angulo(v);
        arco.setAttribute('d', arcoPath(CX, CY, R, A0, Math.max(A0 + 0.01, gr)));
        var p = ponto(CX, CY, R - 12, gr);
        ponte.setAttribute('x2', p[0].toFixed(1));
        ponte.setAttribute('y2', p[1].toFixed(1));
        vNum.textContent = v >= 100 ? v.toFixed(0) : v.toFixed(2);
    }
    function irPara(v) {
        alvo = v;
        if (animando) return;
        animando = true;
        (function passo() {
            // Aproximação suave: o ponteiro persegue o alvo em vez de saltar.
            atual += (alvo - atual) * 0.18;
            if (Math.abs(alvo - atual) < 0.05) { atual = alvo; animando = false; }
            pintar(atual);
            if (animando) requestAnimationFrame(passo);
        })();
    }
    pintar(0);

    function fase(txt) { if (elFase) elFase.textContent = txt; }
    function url(q) { return EP + (EP.indexOf('?') >= 0 ? '&' : '?') + q + '&t=' + Date.now() + Math.random(); }

    // --- Ping: a mediana de 5 idas e voltas ---
    function medirPing() {
        var amostras = [];
        function uma() {
            var t0 = performance.now();
            return fetch(url('f=ping'), { cache: 'no-store', credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function () { amostras.push(performance.now() - t0); });
        }
        var p = Promise.resolve();
        for (var i = 0; i < 5; i++) p = p.then(uma);
        return p.then(function () {
            amostras.sort(function (a, b) { return a - b; });
            return amostras[Math.floor(amostras.length / 2)];   // mediana: ignora um pico solto
        });
    }

    // --- Download: lê em pedaços e vai atualizando o ponteiro ---
    function medirDownload(mb) {
        var t0 = performance.now(), lidos = 0;
        return fetch(url('f=down&mb=' + mb), { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('http ' + r.status);
                if (!r.body || !r.body.getReader) {
                    // Navegador sem streams: mede só no fim, sem ponteiro ao vivo.
                    return r.arrayBuffer().then(function (b) {
                        return b.byteLength * 8 / ((performance.now() - t0) / 1000) / 1e6;
                    });
                }
                var reader = r.body.getReader();
                return (function ler() {
                    return reader.read().then(function (res) {
                        if (res.done) {
                            return lidos * 8 / ((performance.now() - t0) / 1000) / 1e6;
                        }
                        lidos += res.value.length;
                        var seg = (performance.now() - t0) / 1000;
                        if (seg > 0.25) irPara(lidos * 8 / seg / 1e6);
                        return ler();
                    });
                })();
            });
    }

    // --- Upload: manda um bloco e mede o tempo até o servidor confirmar ---
    function medirUpload(mb) {
        var bytes = mb * 1024 * 1024;
        var pedaco = new Uint8Array(1024 * 1024);
        for (var i = 0; i < pedaco.length; i += 4096) pedaco[i] = i & 255;
        var partes = [];
        for (var k = 0; k < mb; k++) partes.push(pedaco);
        var corpo = new Blob(partes, { type: 'application/octet-stream' });

        var t0 = performance.now();
        return fetch(url('f=up'), {
            method: 'POST', body: corpo, cache: 'no-store', credentials: 'same-origin'
        }).then(function (r) { return r.text(); })
          .then(function () {
            return bytes * 8 / ((performance.now() - t0) / 1000) / 1e6;
          });
    }

    function fmt(v) { return v >= 100 ? v.toFixed(0) : v.toFixed(2); }

    var rodando = false;
    btn.addEventListener('click', function () {
        if (rodando) return;
        rodando = true;
        box.classList.add('rodando');
        btn.disabled = true;
        elDown.textContent = '—'; elUp.textContent = '—'; elPing.textContent = '—';
        atual = 0; irPara(0);

        fase('Medindo a latência…');
        medirPing().then(function (ms) {
            elPing.textContent = Math.round(ms);
            fase('Medindo o download…');
            vUni.textContent = 'Mbps ↓';
            // 8 MB dá uma janela boa sem pesar na franquia da hospedagem.
            return medirDownload(8);
        }).then(function (mbps) {
            elDown.textContent = fmt(mbps);
            irPara(mbps);
            fase('Medindo o upload…');
            return new Promise(function (r) { setTimeout(r, 700); }).then(function () {
                irPara(0);
                vUni.textContent = 'Mbps ↑';
                return medirUpload(4);
            });
        }).then(function (mbps) {
            elUp.textContent = fmt(mbps);
            irPara(mbps);
            fase('Pronto');
        }).catch(function () {
            fase('Não foi possível concluir o teste. Tente de novo.');
        }).then(function () {
            rodando = false;
            box.classList.remove('rodando');
            btn.disabled = false;
            btn.textContent = 'Testar de novo';
        });
    });
})();
