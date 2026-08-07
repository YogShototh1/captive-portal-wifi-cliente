/* Aba "Teste de velocidade" — duas medições, cada uma respondendo uma pergunta:

   1) INTERNET DA LOJA (#speedrt-box): o MikroTik baixa do nosso servidor e o
      SERVIDOR cronometra. É a velocidade que chega no roteador do cliente,
      medida sem ninguém ir até lá. O painel só pede e fica esperando o
      resultado aparecer — quem executa é o leadsync, na rodada seguinte.

   2) ESTE APARELHO (#speed-box): o navegador baixa do nosso servidor. Serve
      para separar "o link da loja está ruim" de "a minha conexão está ruim".

   O mostrador é o mesmo nos dois, por isso vive numa função só. */
(function () {

    // ===== Mostrador (escala + ponteiro), reaproveitado pelos dois testes =====
    //
    // A escala NÃO é linear: cada intervalo entre marcas ocupa a mesma fatia do
    // arco. É o que dá espaço de sobra ao trecho de 0 a 100, onde quase todo
    // mundo cai, e amontoa os 250-1000 no fim — igual ao mostrador do Speedtest.
    var MARCAS = [0, 5, 10, 50, 100, 250, 500, 750, 1000];
    var A0 = 145, VARRE = 250, CX = 160, CY = 150, R = 118;

    function fracao(mbps) {
        if (mbps <= 0) return 0;
        if (mbps >= MARCAS[MARCAS.length - 1]) return 1;
        for (var i = 0; i < MARCAS.length - 1; i++) {
            if (mbps <= MARCAS[i + 1]) {
                return (i + (mbps - MARCAS[i]) / (MARCAS[i + 1] - MARCAS[i])) / (MARCAS.length - 1);
            }
        }
        return 1;
    }
    function ponto(r, grau) {
        var rad = grau * Math.PI / 180;
        return [CX + r * Math.cos(rad), CY + r * Math.sin(rad)];
    }
    function arcoPath(r, g1, g2) {
        var a = ponto(r, g1), b = ponto(r, g2);
        return 'M' + a[0].toFixed(1) + ' ' + a[1].toFixed(1) +
               'A' + r + ' ' + r + ' 0 ' + ((g2 - g1) > 180 ? 1 : 0) + ' 1 ' +
               b[0].toFixed(1) + ' ' + b[1].toFixed(1);
    }

    function criarMedidor(pre) {
        var arco  = document.getElementById(pre + '-arco');
        var ponte = document.getElementById(pre + '-ponteiro');
        var vNum  = document.getElementById(pre + '-valor');
        var fundo = document.getElementById(pre + '-fundo');
        var gMarc = document.getElementById(pre + '-marcas');
        if (!arco || !ponte || !vNum) return null;

        if (fundo) fundo.setAttribute('d', arcoPath(R, A0, A0 + VARRE));
        if (gMarc) {
            var s = '';
            for (var i = 0; i < MARCAS.length; i++) {
                var gr = A0 + VARRE * (i / (MARCAS.length - 1));
                var p1 = ponto(R - 16, gr), p2 = ponto(R - 8, gr), pt = ponto(R - 32, gr);
                s += '<line class="sp-tick" x1="' + p1[0].toFixed(1) + '" y1="' + p1[1].toFixed(1) +
                     '" x2="' + p2[0].toFixed(1) + '" y2="' + p2[1].toFixed(1) + '"/>' +
                     '<text class="sp-tick-tx" x="' + pt[0].toFixed(1) + '" y="' + (pt[1] + 4).toFixed(1) +
                     '" text-anchor="middle">' + MARCAS[i] + '</text>';
            }
            gMarc.innerHTML = s;
        }

        var atual = 0, alvo = 0, animando = false;
        function pintar(v) {
            var gr = A0 + VARRE * fracao(v);
            arco.setAttribute('d', arcoPath(R, A0, Math.max(A0 + 0.01, gr)));
            var p = ponto(R - 12, gr);
            ponte.setAttribute('x2', p[0].toFixed(1));
            ponte.setAttribute('y2', p[1].toFixed(1));
            vNum.textContent = v >= 100 ? v.toFixed(0) : v.toFixed(2);
        }
        pintar(0);
        return {
            irPara: function (v) {
                alvo = v;
                if (animando) return;
                animando = true;
                (function passo() {
                    // O ponteiro persegue o alvo em vez de saltar.
                    atual += (alvo - atual) * 0.18;
                    if (Math.abs(alvo - atual) < 0.05) { atual = alvo; animando = false; }
                    pintar(atual);
                    if (animando) requestAnimationFrame(passo);
                })();
            },
            zerar: function () { atual = 0; alvo = 0; pintar(0); }
        };
    }

    function fmt(v) { return v >= 100 ? v.toFixed(0) : v.toFixed(2); }
    function qs(base, q) { return base + (base.indexOf('?') >= 0 ? '&' : '?') + q; }

    // ===== 1) Internet da LOJA =====
    (function () {
        var box = document.getElementById('speedrt-box');
        if (!box) return;
        var EP  = box.getAttribute('data-endpoint');
        var ROT = box.getAttribute('data-roteador');
        var CID = box.getAttribute('data-cliente');
        var CSRF = box.getAttribute('data-csrf');
        var btn   = document.getElementById('rt-iniciar');
        var elFase = document.getElementById('rt-fase');
        var elD  = document.getElementById('rt-down');
        var elP  = document.getElementById('rt-ping');
        var histW = document.getElementById('rt-hist-wrap');
        var histL = document.getElementById('rt-hist');
        var histN = document.getElementById('rt-hist-n');
        var med = criarMedidor('rt');
        if (!btn || !med) return;

        var chave = 'roteador=' + encodeURIComponent(ROT) + (CID ? '&cliente_id=' + CID : '');
        var vigiando = null, tentativas = 0, marcaAnterior = null;

        function mostrar(d) {
            var m = (d.medicoes && d.medicoes[0]) || null;
            if (m) {
                elD.textContent = m.down != null ? fmt(m.down) : '—';
                elP.textContent = m.ping != null ? Math.round(m.ping) : '—';
                med.irPara(m.down != null ? m.down : 0);
            }
            var n = (d.medicoes || []).length;
            if (histW) {
                histW.style.display = n > 1 ? '' : 'none';
                histN.textContent = n;
                histL.innerHTML = (d.medicoes || []).map(function (x) {
                    return '<li class="pc-hist-item"><span class="pc-hist-nome">' +
                        (x.down != null ? fmt(x.down) + ' Mbps' : 'sem resultado') +
                        '</span><span class="pc-hist-meta">' +
                        (x.ping != null ? Math.round(x.ping) + ' ms · ' : '') +
                        (x.em || '').replace(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}).*$/, '$3/$2 $4') +
                        '</span></li>';
                }).join('');
            }
        }

        // Enquanto o teste não volta, o painel pergunta de tempos em tempos.
        // O leadsync roda a cada ~1 min, então 5s é o bastante para pegar o
        // resultado logo que ele chega, sem martelar o servidor.
        function vigiar() {
            tentativas = 0;
            if (vigiando) clearInterval(vigiando);
            vigiando = setInterval(function () {
                tentativas++;
                fetch(qs(EP, chave), { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.ok) return;
                        var m = (d.medicoes && d.medicoes[0]) || null;
                        var marca = m ? m.em : null;
                        // Chegou medição nova (carimbo diferente do de antes)?
                        if (marca && marca !== marcaAnterior) {
                            parar();
                            mostrar(d);
                            fase(m.erro ? 'O roteador não conseguiu completar o teste.' : 'Pronto');
                            return;
                        }
                        if (!d.pendente && tentativas > 3) {
                            // O pedido já foi consumido e nada novo apareceu.
                            parar();
                            fase('O roteador pegou o teste, mas não reportou o resultado.');
                            return;
                        }
                        if (tentativas > 36) {   // 3 minutos
                            parar();
                            fase('Sem resposta do roteador. Tente de novo.');
                        }
                    })
                    .catch(function () {});
            }, 5000);
        }
        function parar() {
            if (vigiando) clearInterval(vigiando);
            vigiando = null;
            btn.disabled = false;
            btn.textContent = 'Testar de novo';
            box.classList.remove('rodando');
        }
        function fase(t) { if (elFase) elFase.textContent = t; }

        // Estado inicial: mostra a última medição, se houver.
        fetch(qs(EP, chave), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) return;
                mostrar(d);
                marcaAnterior = (d.medicoes && d.medicoes[0] && d.medicoes[0].em) || null;
                if (d.medicoes && d.medicoes.length) {
                    fase('Última medição: ' + (d.medicoes[0].em || '').slice(0, 16));
                } else if (!d.online) {
                    fase('O MikroTik está fora do ar.');
                }
                if (d.pendente) {   // já havia um pedido em aberto
                    btn.disabled = true; box.classList.add('rodando');
                    fase('Aguardando o roteador…');
                    vigiar();
                }
            })
            .catch(function () {});

        btn.addEventListener('click', function () {
            btn.disabled = true;
            box.classList.add('rodando');
            med.zerar();
            elD.textContent = '—'; elP.textContent = '—';
            fase('Pedindo o teste ao roteador…');

            var corpo = new FormData();
            corpo.append('csrf', CSRF);
            fetch(qs(EP, chave), { method: 'POST', body: corpo, credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.ok) { fase(d.erro || 'Não foi possível pedir o teste.'); parar(); return; }
                    marcaAnterior = (d.medicoes && d.medicoes[0] && d.medicoes[0].em) || null;
                    fase('Aguardando o roteador… (leva cerca de um minuto)');
                    vigiar();
                })
                .catch(function () { fase('Não foi possível pedir o teste.'); parar(); });
        });
    })();

    // ===== 2) Este aparelho =====
    (function () {
        var box = document.getElementById('speed-box');
        if (!box) return;
        var EP = box.getAttribute('data-endpoint');
        var btn = document.getElementById('sp-iniciar');
        var vUni = document.getElementById('sp-unidade');
        var elD = document.getElementById('sp-down');
        var elU = document.getElementById('sp-up');
        var elP = document.getElementById('sp-ping');
        var elF = document.getElementById('sp-fase');
        var med = criarMedidor('sp');
        if (!btn || !med) return;

        function fase(t) { if (elF) elF.textContent = t; }
        function url(q) { return qs(EP, q + '&t=' + Date.now() + Math.random()); }

        function medirPing() {
            var a = [];
            function uma() {
                var t0 = performance.now();
                return fetch(url('f=ping'), { cache: 'no-store', credentials: 'same-origin' })
                    .then(function (r) { return r.text(); })
                    .then(function () { a.push(performance.now() - t0); });
            }
            var p = Promise.resolve();
            for (var i = 0; i < 5; i++) p = p.then(uma);
            // Mediana: um pico solto não contamina.
            return p.then(function () { a.sort(function (x, y) { return x - y; }); return a[Math.floor(a.length / 2)]; });
        }

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
                            if (res.done) return lidos * 8 / ((performance.now() - t0) / 1000) / 1e6;
                            lidos += res.value.length;
                            var seg = (performance.now() - t0) / 1000;
                            if (seg > 0.25) med.irPara(lidos * 8 / seg / 1e6);
                            return ler();
                        });
                    })();
                });
        }

        function medirUpload(mb) {
            var pedaco = new Uint8Array(1024 * 1024);
            for (var i = 0; i < pedaco.length; i += 4096) pedaco[i] = i & 255;
            var partes = [];
            for (var k = 0; k < mb; k++) partes.push(pedaco);
            var corpo = new Blob(partes, { type: 'application/octet-stream' });
            var t0 = performance.now();
            return fetch(url('f=up'), { method: 'POST', body: corpo, cache: 'no-store', credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function () { return mb * 1024 * 1024 * 8 / ((performance.now() - t0) / 1000) / 1e6; });
        }

        var rodando = false;
        btn.addEventListener('click', function () {
            if (rodando) return;
            rodando = true;
            box.classList.add('rodando');
            btn.disabled = true;
            elD.textContent = '—'; elU.textContent = '—'; elP.textContent = '—';
            med.zerar();

            fase('Medindo a latência…');
            medirPing().then(function (ms) {
                elP.textContent = Math.round(ms);
                fase('Medindo o download…');
                if (vUni) vUni.textContent = 'Mbps ↓';
                return medirDownload(8);
            }).then(function (mbps) {
                elD.textContent = fmt(mbps);
                med.irPara(mbps);
                fase('Medindo o upload…');
                return new Promise(function (r) { setTimeout(r, 700); }).then(function () {
                    med.zerar();
                    if (vUni) vUni.textContent = 'Mbps ↑';
                    return medirUpload(4);
                });
            }).then(function (mbps) {
                elU.textContent = fmt(mbps);
                med.irPara(mbps);
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
})();
