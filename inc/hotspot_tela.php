<?php
// Bloco "liga/desliga do hotspot" da aba HTML do roteador. Compartilhado entre
// o painel e a tela do admin (mesmo padrão do inc/portal_hist_tela.php).
//
// Espera $rotAtivo (identity) e $csrf. Usa $hsClienteId quando o admin está
// dentro da conta de um cliente — no painel próprio fica vazio.
//
// SÓ ADMIN: quem inclui este arquivo decide isso (o api/hotspot_cmd.php confere
// de novo do lado do servidor, que é onde a decisão vale).
//
// O estado sai do último relato do roteador (ads/<hash>.hs.json), gravado a cada
// rodada do leadsync. Não se fala com o roteador daqui: ele está atrás de CGNAT.
$hsClienteId = $hsClienteId ?? 0;
?>
<div class="pc-hs" id="hs-box"
     data-endpoint="api/hotspot_cmd.php"
     data-roteador="<?= h((string) $rotAtivo) ?>"
     data-cliente="<?= (int) $hsClienteId ?>"
     data-csrf="<?= h($csrf) ?>">
    <div class="pc-hs-linha">
        <span class="pc-hs-luz" id="hs-luz" aria-hidden="true"></span>
        <span class="pc-hs-estado" id="hs-estado">Hotspot: consultando…</span>
        <button type="button" class="pc-btn" id="hs-btn" hidden></button>
    </div>
    <p class="pc-hs-srv" id="hs-srv"></p>
    <p class="pc-hs-srv" id="hs-prof"></p>
    <p class="pc-anuncio-msg" id="hs-msg" hidden></p>
</div>
<script>
(function () {
    var box = document.getElementById('hs-box');
    if (!box || box.dataset.pronto) { return; }
    box.dataset.pronto = '1';

    var luz = document.getElementById('hs-luz'),
        est = document.getElementById('hs-estado'),
        btn = document.getElementById('hs-btn'),
        srv = document.getElementById('hs-srv'),
        prof = document.getElementById('hs-prof'),
        msg = document.getElementById('hs-msg');

    function qs() {
        var q = 'roteador=' + encodeURIComponent(box.dataset.roteador);
        if (+box.dataset.cliente > 0) { q += '&cliente_id=' + box.dataset.cliente; }
        return q;
    }

    function aviso(txt, erro) {
        msg.hidden = !txt;
        msg.textContent = txt || '';
        msg.className = 'pc-anuncio-msg' + (txt ? (erro ? ' err' : ' ok') : '');
    }

    function pintar(d) {
        if (!d.ok) { est.textContent = 'Hotspot: ' + (d.erro || 'erro'); return; }

        // Ordem pedida e ainda não buscada pelo roteador. Enquanto isso o estado
        // na tela é o ANTIGO — dizer "aplicando" evita o admin achar que o
        // clique não pegou e ficar clicando.
        if (d.pendente !== null) {
            luz.className = 'pc-hs-luz aguardando';
            est.textContent = 'Hotspot: ' + (d.pendente ? 'ligando' : 'desligando') + '… (o roteador aplica em até ~1 min)';
            btn.hidden = true;
        } else if (!d.conhecido) {
            luz.className = 'pc-hs-luz desconhecido';
            est.textContent = 'Hotspot: o roteador ainda não reportou o estado';
            btn.hidden = true;
        } else {
            luz.className = 'pc-hs-luz ' + (d.ligado ? 'on' : 'off');
            est.textContent = 'Hotspot: ' + (d.ligado ? 'ligado' : 'desligado');
            btn.hidden = false;
            btn.textContent = d.ligado ? 'Desligar' : 'Ligar';
            btn.dataset.acao = d.ligado ? 'desligar' : 'ligar';
            btn.disabled = !d.online;
            btn.title = d.online ? '' : 'O roteador está fora do ar.';
        }

        if (d.servidores && d.servidores.length) {
            // Cruza o perfil DO SERVIDOR com a lista de perfis. Ter um perfil
            // com trial no roteador não basta: o portal autentica por trial, e
            // quem vale é o perfil que este servidor usa. Sem trial ali,
            // nenhum cliente entra — e o portal volta pro começo do fluxo.
            var porNome = {};
            (d.perfis || []).forEach(function (p) { porNome[p.nome] = p; });
            srv.innerHTML = 'Servidores no roteador (' + d.servidores.length + '): '
                + d.servidores.map(function (s) {
                    var t = s.nome + ' — ' + (s.ligado ? 'ligado' : 'desligado');
                    if (!s.perfil) { return t; }
                    t += ' · perfil <strong>' + s.perfil + '</strong>';
                    var p = porNome[s.perfil];
                    if (p && !p.trial) {
                        t += ' <strong class="pc-hs-alerta">← este perfil não tem trial:'
                           + ' nenhum cliente consegue entrar</strong>';
                    }
                    return t;
                }).join('<br>');
        } else if (d.conhecido) {
            // Nenhum servidor de hotspot: não é o mesmo que "desligado", e a
            // diferença importa (portal nunca vai subir neste roteador).
            srv.textContent = 'Nenhum servidor de hotspot criado neste roteador.';
        } else {
            srv.textContent = '';
        }

        // Perfil do hotspot: é ele que decide se o cliente CONSEGUE entrar. O
        // portal autentica por trial; sem "trial" no login-by o roteador recusa
        // todo login e o cliente volta pro começo do fluxo — o ciclo infinito.
        // Com trial ligado, o limite diário por aparelho é o outro suspeito.
        if (d.perfis && d.perfis.length) {
            prof.innerHTML = d.perfis.map(function (p) {
                return 'Perfil <strong>' + p.nome + '</strong>: login por <code>' + (p.login || '?') + '</code>'
                     + (p.trial
                         ? ' · trial de <strong>' + (p.limite || '?') + '</strong> por aparelho, zera a cada <strong>' + (p.reset || '?') + '</strong>'
                         : ' · <strong class="pc-hs-alerta">sem trial — o portal não consegue autenticar ninguém</strong>');
            }).join('<br>');
        } else {
            prof.textContent = '';
        }
    }

    function ler() {
        fetch(box.dataset.endpoint + '?' + qs(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); }).then(pintar)
            .catch(function () { est.textContent = 'Hotspot: falha ao consultar'; });
    }

    btn.addEventListener('click', function () {
        var acao = btn.dataset.acao;
        if (acao === 'desligar' && !window.confirm(
            'Desligar o hotspot abre o Wi-Fi da loja SEM a tela de login: para de entrar lead e o anúncio deixa de aparecer.\n\nDesligar mesmo assim?')) {
            return;
        }
        btn.disabled = true;
        aviso('');
        var b = new FormData();
        b.append('csrf', box.dataset.csrf);
        b.append('acao', acao);
        b.append('roteador', box.dataset.roteador);
        if (+box.dataset.cliente > 0) { b.append('cliente_id', box.dataset.cliente); }
        fetch(box.dataset.endpoint, { method: 'POST', credentials: 'same-origin', body: b })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { aviso(d.erro || 'não deu para enviar a ordem', true); btn.disabled = false; return; }
                aviso('Ordem enviada. O roteador aplica em até ~1 min.');
                pintar(d);
            })
            .catch(function () { aviso('falha de rede', true); btn.disabled = false; });
    });

    // ponytail: só relê quando a aba abre e a cada 30s com ela visível. Um
    // websocket para um botão que se usa uma vez por mês seria exagero.
    ler();
    setInterval(function () { if (!document.hidden) { ler(); } }, 30000);
})();
</script>
