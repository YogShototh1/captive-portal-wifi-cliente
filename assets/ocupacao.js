/* Aba Ocupação: clicar num cartão troca a lista mostrada embaixo.
   As cinco listas já vieram no HTML (saem da mesma consulta da aba Painel),
   então trocar é mostrar e esconder — sem fetch, sem reload. */
(function () {
    var cards = document.getElementById('ocp-cards');
    if (!cards) return;
    var paineis = document.querySelectorAll('[data-ocp-painel]');

    cards.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.ocp-card') : null;
        if (!b) return;
        var alvo = b.getAttribute('data-ocp');
        var botoes = cards.querySelectorAll('.ocp-card');
        for (var i = 0; i < botoes.length; i++) {
            var atual = botoes[i] === b;
            botoes[i].classList.toggle('atual', atual);
            botoes[i].setAttribute('aria-pressed', atual ? 'true' : 'false');
        }
        for (var j = 0; j < paineis.length; j++) {
            paineis[j].hidden = paineis[j].getAttribute('data-ocp-painel') !== alvo;
        }
    });
})();
