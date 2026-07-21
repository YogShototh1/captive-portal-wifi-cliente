<?php
// Botão flutuante que alterna tema claro/escuro.
// A escolha fica em localStorage('cd-tema') e é aplicada cedo, no <head>
// de cada página (evita flash). A landing NÃO inclui este arquivo.
?>
<button type="button" id="btn-tema" class="tema-toggle" aria-label="Alternar tema claro e escuro" title="Alternar tema claro/escuro">
    <svg class="ic-lua" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
    <svg class="ic-sol" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
</button>
<script>
(function () {
    var b = document.getElementById('btn-tema');
    if (!b) return;
    b.addEventListener('click', function () {
        var atual = document.documentElement.getAttribute('data-tema') === 'escuro' ? 'escuro' : 'claro';
        var novo = atual === 'escuro' ? 'claro' : 'escuro';
        document.documentElement.setAttribute('data-tema', novo);
        try { localStorage.setItem('cd-tema', novo); } catch (e) {}
    });
})();
</script>
