<?php
// Casca do painel: a URL do navegador fica SEMPRE em "seudominio.com.br".
// Todo o app (login, painel, admin) roda dentro do iframe abaixo — os nomes
// das páginas e a tecnologia (PHP) nunca aparecem na barra de endereço.
// Não há lógica aqui; sessão, login e permissões continuam nas páginas internas.
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="assets/logo.png?v=3" type="image/png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <title>Captive Data</title>
    <style>
        /* Trava o documento da casca: sem rolagem nem "arrasto" que revele
           faixas pretas quando o teclado do celular abre/fecha. */
        html, body { margin: 0; padding: 0; height: 100%; background: #000; overflow: hidden; overscroll-behavior: none; }
        /* dvh acompanha a viewport dinâmica (barras do navegador + teclado):
           o iframe encolhe junto e não sobra área preta arrastável.
           height:100% fica como fallback para navegadores sem dvh. */
        iframe { position: fixed; top: 0; left: 0; width: 100%; height: 100%; height: 100dvh; border: 0; }
    </style>
</head>
<body>
    <iframe src="entrar.php" title="Captive Data"></iframe>
</body>
</html>
