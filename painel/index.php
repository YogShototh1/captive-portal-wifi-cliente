<?php
// Casca do painel: a URL do navegador fica em "captivedata.com.br/painel".
// Todo o app (login, painel, admin) roda dentro do iframe abaixo — os nomes
// das páginas e a tecnologia (PHP) nunca aparecem na barra de endereço.
// Não há lógica aqui; sessão, login e permissões continuam nas páginas internas (na raiz).
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <script>(function(){try{var t=localStorage.getItem('cd-tema');document.documentElement.setAttribute('data-tema',t==='escuro'?'escuro':'claro');}catch(e){document.documentElement.setAttribute('data-tema','claro');}})();</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="/assets/logo.png?v=3" type="image/png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <title>Captive Data</title>
    <style>
        html, body { margin: 0; padding: 0; height: 100%; background: #000; overflow: hidden; overscroll-behavior: none; }
        html[data-tema="claro"], html[data-tema="claro"] body { background: #faf5ff; }
        iframe { position: fixed; top: 0; left: 0; width: 100%; height: 100%; height: 100dvh; border: 0; }
    </style>
</head>
<body>
    <iframe src="/entrar.php" title="Captive Data"></iframe>
</body>
</html>
