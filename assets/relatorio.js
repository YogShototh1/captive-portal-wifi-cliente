/* Aba Relatórios: escolhe o modelo, o período, busca api/relatorio.php e
   desenha o gráfico de barras (HTML/CSS puro, no tema do site).
   Config vem do #relatorio-box (data-endpoint). */
(function () {
    var box = document.getElementById('relatorio-box');
    if (!box) return;
    var EP = box.getAttribute('data-endpoint');

    var sel      = document.getElementById('rel-sel');
    var selLabel = document.getElementById('rel-sel-label');
    var inpIni   = document.getElementById('rel-inicio');
    var inpFim   = document.getElementById('rel-fim');
    var btn      = document.getElementById('rel-gerar');
    var erroEl   = document.getElementById('rel-erro');
    var grafico  = document.getElementById('rel-grafico');
    if (!sel || !btn || !grafico) return;

    var NOMES = {
        semana: 'Acessos por dia da semana',
        hora: 'Acessos por horário',
        clientes_dias: 'Clientes - Dias',
        clientes_tempo: 'Clientes - Tempo',
        sumidos: 'Clientes sumidos',
        ranking: 'Ranking de fidelidade',
        mapa: 'Mapa semana × hora',
        aniversario: 'Aniversários de cliente',
        intervalo: 'Intervalo de retorno'
    };
    var DIAS  = { 1: 'Dom', 2: 'Seg', 3: 'Ter', 4: 'Qua', 5: 'Qui', 6: 'Sex', 7: 'Sáb' }; // DAYOFWEEK do MySQL
    var tipo  = null;

    // Campos que cada relatório usa. Ausente do mapa = usa o par de datas.
    var CAMPOS = { sumidos: ['dias', 'visitas'], aniversario: ['proximos'], intervalo: [] };
    function usaDatas(t) { return !(t in CAMPOS); }
    function mostrarCampos() {
        var comDatas = tipo === null || usaDatas(tipo);
        if (inpIni) inpIni.style.display = comDatas ? '' : 'none';
        if (inpFim) inpFim.style.display = comDatas ? '' : 'none';
        var extras = box.querySelectorAll('.rel-extra');
        for (var i = 0; i < extras.length; i++) {
            var quer = !comDatas && CAMPOS[tipo].indexOf(extras[i].getAttribute('data-campo')) !== -1;
            extras[i].style.display = quer ? '' : 'none';
        }
    }

    function erro(msg) {
        if (!erroEl) return;
        if (msg) { erroEl.textContent = msg; erroEl.style.display = ''; }
        else { erroEl.style.display = 'none'; }
    }

    // Seleção do modelo no menu (mesmo dropdown do seletor de MikroTik).
    var itens = sel.querySelectorAll('.rel-item');
    for (var i = 0; i < itens.length; i++) {
        itens[i].addEventListener('click', function () {
            tipo = this.getAttribute('data-tipo');
            if (selLabel) selLabel.textContent = NOMES[tipo] || tipo;
            for (var j = 0; j < itens.length; j++) itens[j].classList.toggle('atual', itens[j] === this);
            sel.removeAttribute('open');
            mostrarCampos();
            erro(null);
        });
    }

    function fmtNum(n) { return Number(n).toLocaleString('pt-BR'); }

    // Reparte as % (fatia do total, 1 casa) de forma que a soma feche 100,0 —
    // maior resto (Hare): arredonda pra baixo e distribui os décimos que faltam
    // pros de maior resto. Retorna array de % alinhado com vals.
    function pcts1(vals) {
        var tot = 0, i;
        for (i = 0; i < vals.length; i++) tot += vals[i];
        if (!tot) return vals.map(function () { return 0; });
        var dec = vals.map(function (v) { return v * 1000 / tot; }); // em décimos de %
        var base = dec.map(function (x) { return Math.floor(x); });
        var usado = base.reduce(function (a, b) { return a + b; }, 0);
        var falta = 1000 - usado;
        var ordem = dec.map(function (x, idx) { return { i: idx, r: x - Math.floor(x) }; })
            .sort(function (a, b) { return b.r - a.r; });
        for (i = 0; i < falta; i++) base[ordem[i].i]++;
        return base.map(function (t) { return t / 10; });
    }
    // "100%" (sem casa quando inteiro), "54,5%" senão.
    function fmtPct(p) {
        return (p % 1 === 0 ? String(p) : p.toFixed(1)).replace('.', ',') + '%';
    }

    function resumoHTML(d, meio) {
        return '<p class="rel-resumo">' + (NOMES[d.tipo] || '') + ' — ' + meio + ' de ' +
            d.inicio.split('-').reverse().join('/') + ' a ' + d.fim.split('-').reverse().join('/') + '</p>';
    }
    // Resumo sem período (relatórios que não usam datas).
    function resumo2(d, meio) {
        return '<p class="rel-resumo">' + (NOMES[d.tipo] || '') + ' — ' + meio + '</p>';
    }
    // Uma linha do funil: rótulo + (valor real, %) em cima, barra fina embaixo.
    function linhaHTML(rotulo, valor, pctStr, w, isTel) {
        return '<div class="rel-linha">' +
            '<div class="rel-topo">' +
                '<span class="rel-rotulo' + (isTel ? ' rel-rotulo-tel' : '') + '">' + rotulo + '</span>' +
                '<span class="rel-nums"><b class="rel-valor">' + valor + '</b>' +
                '<span class="rel-pct">' + pctStr + '</span></span>' +
            '</div>' +
            '<span class="rel-barra-wrap"><span class="rel-barra" style="width:' + w.toFixed(1) + '%"></span></span>' +
            '</div>';
    }

    function fmtTempo(seg) {
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return p(Math.floor(seg / 3600)) + ':' + p(Math.floor((seg % 3600) / 60)) + ':' + p(seg % 60);
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    // Rótulo do cliente: NOME (quando existir) ou número, sempre como link que
    // abre o WhatsApp do NÚMERO (mesmo comportamento da tabela de leads).
    function waLink(tel, nome) {
        var label = esc((nome != null && nome !== '') ? nome : tel);
        var d = String(tel || '').replace(/\D/g, '');
        if (d && d.length <= 11) d = '55' + d;
        if (!d) return label;
        return '<a class="rel-wa" href="https://wa.me/' + d + '" target="_blank" rel="noopener" title="WhatsApp de ' + esc(tel) + '">' + label + '</a>';
    }

    // Relatórios por cliente (lista: telefone + valor + barra proporcional).
    function renderLista(d) {
        if (!d.lista || !d.lista.length) {
            grafico.innerHTML = '<p class="pc-anuncio-desc">Nenhum acesso no período selecionado.</p>';
            return;
        }
        var vals = d.lista.map(function (x) { return x.valor; });
        var max = Math.max.apply(null, vals);
        var ps = pcts1(vals); // % do total (soma dos valores), fecha 100
        var html = resumoHTML(d, d.total + ' cliente(s)');
        for (var i = 0; i < d.lista.length; i++) {
            var v = vals[i];
            if (!v) continue; // oculta clientes com valor 0
            var w = max ? (v * 100 / max) : 0;
            var txt = d.tipo === 'clientes_tempo' ? fmtTempo(v) : v + (v === 1 ? ' dia' : ' dias');
            html += linhaHTML(waLink(d.lista[i].telefone, d.lista[i].nome), txt, fmtPct(ps[i]), w, true);
        }
        grafico.innerHTML = html;
    }

    function fmtD(iso) { return String(iso || '').split('-').reverse().join('/'); }
    function vazio() { grafico.innerHTML = '<p class="pc-anuncio-desc">Nenhum resultado no período selecionado.</p>'; }

    // Clientes sumidos: barra = dias sem vir; direita = visitas e última visita.
    function renderSumidos(d) {
        if (!d.lista || !d.lista.length) return vazio();
        var max = 0, i;
        for (i = 0; i < d.lista.length; i++) max = Math.max(max, d.lista[i].dias);
        var html = resumo2(d, d.total + ' cliente(s) sem vir há ' + d.dias + '+ dias (mín. ' + d.visitas + ' visitas)');
        for (i = 0; i < d.lista.length; i++) {
            var it = d.lista[i];
            html += linhaHTML(waLink(it.telefone, it.nome),
                it.dias + (it.dias === 1 ? ' dia sem vir' : ' dias sem vir'),
                it.visitas + ' visitas · últ. ' + fmtD(it.ultima),
                max ? (it.dias * 100 / max) : 0, true);
        }
        grafico.innerHTML = html;
    }

    // Ranking de fidelidade: top 20 por acessos no período.
    function renderRanking(d) {
        if (!d.lista || !d.lista.length) return vazio();
        var max = d.lista[0].valor || 1;
        var html = resumoHTML(d, 'top ' + d.total + ' cliente(s)');
        for (var i = 0; i < d.lista.length; i++) {
            var it = d.lista[i];
            html += linhaHTML((i + 1) + 'º ' + waLink(it.telefone, it.nome),
                it.valor + (it.valor === 1 ? ' acesso' : ' acessos'),
                'últ. ' + fmtD(it.ultima),
                it.valor * 100 / max, true);
        }
        grafico.innerHTML = html;
    }

    // Aniversários: marcos de 3/6/12 meses da 1ª conexão dentro do período.
    function renderAniversario(d) {
        if (!d.lista || !d.lista.length) return vazio();
        var html = resumo2(d, d.total + ' marco(s) nos próximos ' + d.proximos + ' dias');
        for (var i = 0; i < d.lista.length; i++) {
            var it = d.lista[i];
            html += linhaHTML(waLink(it.telefone, it.nome),
                it.meses + ' meses de cliente',
                'em ' + fmtD(it.data),
                it.meses * 100 / 12, true);
        }
        grafico.innerHTML = html;
    }

    // Intervalo de retorno: faixas do intervalo médio entre visitas + mediana.
    function renderIntervalo(d) {
        if (!d.total) return vazio();
        var LBL = ['volta em 1-2 dias', '3-4 dias', '5-7 dias', '8-14 dias', '15-30 dias', '31+ dias', 'sem retorno'];
        var vals = d.faixas || [];
        var max = Math.max.apply(null, vals.concat([1]));
        var ps = pcts1(vals);
        var html = resumo2(d, d.total + ' cliente(s) — histórico completo');
        html += '<p class="rel-resumo">Cliente típico volta a cada <b>' +
            String(d.mediana).replace('.', ',') + ' dia(s)</b> (mediana).</p>';
        for (var i = 0; i < vals.length; i++) {
            if (!vals[i]) continue;
            // Linha com "+" antes do rótulo: expande a lista de clientes da faixa.
            var cli = (d.clientes && d.clientes[i]) || [];
            var sub = '';
            for (var c = 0; c < cli.length; c++) {
                var det = cli[c].media != null
                    ? 'volta a cada ' + String(cli[c].media).replace('.', ',') + ' dia(s)'
                    : 'veio 1 vez em ' + fmtD(cli[c].data);
                sub += '<div class="rel-sub-item">' + waLink(cli[c].telefone, cli[c].nome) +
                    '<span>' + det + '</span></div>';
            }
            html += '<div class="rel-linha">' +
                '<div class="rel-topo">' +
                    '<span class="rel-rotulo"><button type="button" class="rel-mais" data-fx="' + i + '" aria-label="Ver clientes da faixa">+</button>' + LBL[i] + '</span>' +
                    '<span class="rel-nums"><b class="rel-valor">' + fmtNum(vals[i]) + ' cliente(s)</b>' +
                    '<span class="rel-pct">' + fmtPct(ps[i]) + '</span></span>' +
                '</div>' +
                '<span class="rel-barra-wrap"><span class="rel-barra" style="width:' + (vals[i] * 100 / max).toFixed(1) + '%"></span></span>' +
                '<div class="rel-sub" data-sub="' + i + '" style="display:none">' + sub + '</div>' +
                '</div>';
        }
        grafico.innerHTML = html;
    }

    // Expandir/recolher a lista de clientes de uma faixa (delegação: o gráfico
    // é re-renderizado a cada geração, o container não).
    grafico.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.rel-mais') : null;
        if (!b) return;
        var sub = grafico.querySelector('.rel-sub[data-sub="' + b.getAttribute('data-fx') + '"]');
        if (!sub) return;
        var aberto = sub.style.display !== 'none';
        sub.style.display = aberto ? 'none' : '';
        b.textContent = aberto ? '+' : '−';
    });

    // Mapa semana × hora: grade de calor (intensidade = acessos).
    function renderMapa(d) {
        if (!d.total) return vazio();
        var DIAS_MAPA = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb']; // DAYOFWEEK 1..7
        var max = 0, k;
        for (k in d.grade) { if (d.grade[k] > max) max = d.grade[k]; }
        var html = resumoHTML(d, d.total + ' acesso(s)') + '<div class="rel-mapa-wrap"><div class="rel-mapa">';
        html += '<span class="rm-rot"></span>';
        for (var h = 0; h < 24; h++) html += '<span class="rm-hora">' + (h % 3 === 0 ? h : '') + '</span>';
        for (var dw = 1; dw <= 7; dw++) {
            html += '<span class="rm-rot">' + DIAS_MAPA[dw - 1] + '</span>';
            for (h = 0; h < 24; h++) {
                var n = d.grade[dw + '-' + h] || 0;
                var a = n ? (0.15 + 0.85 * n / max) : 0;
                html += '<span class="rm-cel"' +
                    (n ? ' style="background:rgba(34,211,238,' + a.toFixed(2) + ')"' : '') +
                    ' title="' + DIAS_MAPA[dw - 1] + ' ' + (h < 10 ? '0' : '') + h + 'h — ' + n + ' acesso(s)"></span>';
            }
        }
        grafico.innerHTML = html + '</div></div>';
    }

    function render(d) {
        if (d.tipo === 'sumidos')     return renderSumidos(d);
        if (d.tipo === 'ranking')     return renderRanking(d);
        if (d.tipo === 'aniversario') return renderAniversario(d);
        if (d.tipo === 'intervalo')   return renderIntervalo(d);
        if (d.tipo === 'mapa')        return renderMapa(d);
        if (d.lista) return renderLista(d); // relatórios por cliente
        // Linhas fixas: 7 dias ou 24 horas — bucket ausente = 0 acessos.
        var chaves = [], rotulo;
        if (d.tipo === 'hora') {
            for (var h = 0; h < 24; h++) chaves.push(h);
            rotulo = function (k) { return (k < 10 ? '0' : '') + k + 'h'; };
        } else {
            chaves = [1, 2, 3, 4, 5, 6, 7];
            rotulo = function (k) { return DIAS[k]; };
        }
        if (!d.total) {
            grafico.innerHTML = '<p class="pc-anuncio-desc">Nenhum acesso no período selecionado.</p>';
            return;
        }
        var vals = chaves.map(function (k) { return d.buckets[k] || 0; });
        var max = Math.max.apply(null, vals);
        var ps = pcts1(vals); // % do total, soma 100 (zeros dão 0%)
        var html = resumoHTML(d, d.total + ' acesso(s)');
        for (var k = 0; k < chaves.length; k++) {
            var n = vals[k];
            if (!n) continue; // oculta horários/dias zerados
            var w = max ? (n * 100 / max) : 0;
            html += linhaHTML(rotulo(chaves[k]), fmtNum(n), fmtPct(ps[k]), w);
        }
        grafico.innerHTML = html;
    }

    // ===== Calendário próprio em PT-BR (o nativo segue o idioma do navegador
    // e mostra mês/dia/ano) — exibe dd/mm/aaaa e guarda o ISO em data-iso. =====
    var CAL_MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
                     'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    var CAL_DOW = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    var calAberto = null; // popup aberto no momento (um por vez)

    function calFechar() {
        if (calAberto && calAberto.parentNode) calAberto.parentNode.removeChild(calAberto);
        calAberto = null;
    }

    function calendario(inp) {
        if (!inp) return;
        inp.addEventListener('click', function () {
            if (calAberto && calAberto._dono === inp) { calFechar(); return; }
            calFechar();
            var pop = document.createElement('div');
            pop.className = 'cal-pop';
            pop._dono = inp;
            var pai = inp.parentNode; // .rel-controles (position:relative)
            pai.appendChild(pop);
            pop.style.left = inp.offsetLeft + 'px';
            pop.style.top = (inp.offsetTop + inp.offsetHeight + 6) + 'px';
            calAberto = pop;

            var iso = inp.getAttribute('data-iso') || '';
            var hoje = new Date();
            var sel = /^\d{4}-\d{2}-\d{2}$/.test(iso)
                ? new Date(+iso.slice(0, 4), +iso.slice(5, 7) - 1, +iso.slice(8, 10))
                : hoje;
            var ano = sel.getFullYear(), mes = sel.getMonth();

            function p2(n) { return (n < 10 ? '0' : '') + n; }
            function render() {
                var nomeMes = CAL_MESES[mes].charAt(0).toUpperCase() + CAL_MESES[mes].slice(1);
                var html = '<div class="cal-cab">' +
                    '<button type="button" class="cal-nav" data-d="-1" aria-label="Mês anterior">&lsaquo;</button>' +
                    '<b>' + nomeMes + ' de ' + ano + '</b>' +
                    '<button type="button" class="cal-nav" data-d="1" aria-label="Próximo mês">&rsaquo;</button>' +
                    '</div><div class="cal-grade">';
                for (var w = 0; w < 7; w++) html += '<span class="cal-dow">' + CAL_DOW[w] + '</span>';
                var primeiro = new Date(ano, mes, 1).getDay();      // 0 = domingo
                var dias = new Date(ano, mes + 1, 0).getDate();
                for (var v = 0; v < primeiro; v++) html += '<span class="cal-vazio"></span>';
                for (var d = 1; d <= dias; d++) {
                    var diso = ano + '-' + p2(mes + 1) + '-' + p2(d);
                    var cls = 'cal-dia' + (diso === iso ? ' sel' : '') +
                        (d === hoje.getDate() && mes === hoje.getMonth() && ano === hoje.getFullYear() ? ' hoje' : '');
                    html += '<button type="button" class="' + cls + '" data-iso="' + diso + '">' + d + '</button>';
                }
                pop.innerHTML = html + '</div>';
            }
            render();

            pop.addEventListener('click', function (e) {
                var nav = e.target.closest ? e.target.closest('.cal-nav') : null;
                if (nav) {
                    mes += parseInt(nav.getAttribute('data-d'), 10);
                    if (mes < 0) { mes = 11; ano--; }
                    if (mes > 11) { mes = 0; ano++; }
                    render();
                    return;
                }
                var dia = e.target.closest ? e.target.closest('.cal-dia') : null;
                if (dia) {
                    var diso = dia.getAttribute('data-iso');
                    inp.setAttribute('data-iso', diso);
                    inp.value = diso.split('-').reverse().join('/');
                    calFechar();
                    erro(null);
                }
            });
        });
    }
    calendario(inpIni);
    calendario(inpFim);
    // Clique fora fecha o calendário.
    document.addEventListener('click', function (e) {
        if (!calAberto) return;
        if (e.target === inpIni || e.target === inpFim) return;
        if (calAberto.contains && calAberto.contains(e.target)) return;
        calFechar();
    });

    btn.addEventListener('click', function () {
        if (!tipo) { erro('Escolha o modelo do relatório.'); return; }
        var extra = '';
        if (usaDatas(tipo)) {
            var ini = inpIni ? inpIni.getAttribute('data-iso') : '';
            var fim = inpFim ? inpFim.getAttribute('data-iso') : '';
            if (!ini || !fim) { erro('Informe a data inicial e a final.'); return; }
            extra = '&inicio=' + encodeURIComponent(ini) + '&fim=' + encodeURIComponent(fim);
        } else {
            // Só os campos deste relatório (sumido há / mín. visitas / próximos dias).
            var mapa = { dias: 'rel-dias', visitas: 'rel-visitas', proximos: 'rel-proximos' };
            for (var c = 0; c < CAMPOS[tipo].length; c++) {
                var campo = CAMPOS[tipo][c];
                var el = document.getElementById(mapa[campo]);
                var v = el ? parseInt(el.value, 10) : 0;
                if (!v || v < 1) { erro('Preencha os campos do relatório (números maiores que zero).'); return; }
                extra += '&' + campo + '=' + v;
            }
        }
        erro(null);
        btn.disabled = true;
        var txt = btn.textContent;
        btn.textContent = 'Gerando…';
        grafico.innerHTML = '';
        fetch(EP + (EP.indexOf('?') >= 0 ? '&' : '?') + 'tipo=' + encodeURIComponent(tipo) + extra,
              { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { erro((d && d.erro) || 'Erro ao gerar o relatório.'); return; }
                render(d);
            })
            .catch(function () { erro('Erro ao gerar o relatório. Tente de novo.'); })
            .then(function () { btn.disabled = false; btn.textContent = txt; });
    });
})();
