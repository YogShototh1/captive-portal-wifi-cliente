/* Modal "avancado" das cores do login: abre/fecha, esboco ao vivo e clique na
   zona do esboco abrindo o seletor da cor. A sincronia seletor<->#hex ja vem do
   abas.js (por classe); aqui so refletimos a cor no esboco (--m-<chave>). */
(function () {
    var modal = document.getElementById('cores-modal');
    var abrir = document.getElementById('cores-avancado-abrir');
    if (!modal || !abrir) return;
    // O modal e renderizado dentro da aba (.pc-tela), que tem transform na
    // animacao de entrada — e transform prende position:fixed no ancestral em
    // vez da viewport. Movendo o modal p/ o body, o fixed volta a ancorar na
    // tela. (Padrao dos demais modais do painel.)
    if (modal.parentNode !== document.body) document.body.appendChild(modal);
    var scene = document.getElementById('cm-scene');

    function pintar(cor, valor) {
        if (scene && /^#[0-9a-fA-F]{6}$/.test(valor)) scene.style.setProperty('--m-' + cor, valor);
    }
    // Estado inicial do esboco a partir dos <input>.
    modal.querySelectorAll('.cm-color').forEach(function (i) { pintar(i.getAttribute('data-cor'), i.value); });

    function fechar() {
        modal.classList.remove('aberto');
        modal.setAttribute('aria-hidden', 'true');
        document.documentElement.style.overflow = '';
    }
    abrir.addEventListener('click', function () {
        modal.classList.add('aberto');
        modal.setAttribute('aria-hidden', 'false');
        document.documentElement.style.overflow = 'hidden';
    });
    modal.addEventListener('click', function (e) {
        if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-close')) fechar();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('aberto')) fechar();
    });

    // Cor mudou (seletor OU campo #hex): atualiza o esboco ao vivo.
    modal.addEventListener('input', function (e) {
        var t = e.target;
        if (!t.classList) return;
        if (t.classList.contains('cm-color')) {
            pintar(t.getAttribute('data-cor'), t.value);
        } else if (t.classList.contains('pc-cor-hex')) {
            pintar(t.getAttribute('data-color'), t.value.trim());
        }
    });

    // Clique numa zona do esboco -> abre o seletor daquela cor. e.target e o
    // elemento mais interno, entao "field" (dentro de "border") e "btnfg"
    // (dentro de "accent") ganham prioridade sem stopPropagation.
    if (scene) {
        scene.addEventListener('click', function (e) {
            var zona = e.target.closest ? e.target.closest('[data-cor]') : null;
            if (!zona) return;
            var cor = zona.getAttribute('data-cor');
            var input = modal.querySelector('.cm-color[data-cor="' + cor + '"]');
            var row = modal.querySelector('.cm-row[data-row="' + cor + '"]');
            if (row) {
                modal.querySelectorAll('.cm-row.alvo').forEach(function (r) { r.classList.remove('alvo'); });
                row.classList.add('alvo');
                row.scrollIntoView({ block: 'nearest' });
            }
            if (input) { try { input.showPicker(); } catch (err) { input.click(); } }
        });
        // Realce reciproco: passar o mouse na zona destaca a linha e vice-versa.
        function realce(cor, on) {
            var row = modal.querySelector('.cm-row[data-row="' + cor + '"]');
            var zonas = scene.querySelectorAll('[data-cor="' + cor + '"]');
            if (row) row.classList.toggle('realce', on);
            zonas.forEach(function (z) { z.classList.toggle('realce', on); });
        }
        scene.addEventListener('mouseover', function (e) {
            var z = e.target.closest ? e.target.closest('[data-cor]') : null;
            if (z) realce(z.getAttribute('data-cor'), true);
        });
        scene.addEventListener('mouseout', function (e) {
            var z = e.target.closest ? e.target.closest('[data-cor]') : null;
            if (z) realce(z.getAttribute('data-cor'), false);
        });
        modal.querySelectorAll('.cm-row').forEach(function (row) {
            var cor = row.getAttribute('data-row');
            row.addEventListener('mouseenter', function () { realce(cor, true); });
            row.addEventListener('mouseleave', function () { realce(cor, false); });
        });
    }
})();
