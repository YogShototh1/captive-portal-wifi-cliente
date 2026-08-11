<?php
// Trava da marca: a palavra "MikroTik" nao pode aparecer em texto que o cliente le.
//
// Ela continua no CODIGO de proposito — nomes de funcao (mikrotiks_online), o id
// do elemento de status, a chave JSON que o leads-live.js consome, o nome do
// arquivo api/mikrotik_status.php, a constante do timeout e os caminhos
// mikrotik/etapas. Trocar qualquer um desses quebra o painel; o que sai e so o
// texto da tela.
//
// Rode depois de mexer no painel ou na landing:  php tools/teste_marca.php
$raiz = dirname(__DIR__);

// Onde o cliente le. Nao entra api/ inteiro: la a palavra so aparece em
// comentario e em identificador, e as poucas mensagens visiveis estao aqui.
$arquivos = [
    'painel.php', 'admin_leads.php', 'admin_form.php', 'index.html',
    'inc/speedtest_tela.php', 'inc/portal_hist_tela.php',
    'api/alertas.php', 'api/speed_loja.php', 'api/upload_portal.php',
    'assets/leads-live.js', 'assets/speedtest.js',
];

// Identificadores: a palavra faz parte do NOME, nao do texto. Casados antes do
// resto para nao contarem como sobra.
$identificadores = '/\b(?:mikrotiks?_[a-z_]+|mikrotik-status|MIKROTIK_TIMEOUT_SEG|mikrotik\/etapas|\'mikrotik\'|"mikrotik")/i';

$sobras = [];
foreach ($arquivos as $rel) {
    $p = $raiz . '/' . $rel;
    if (!is_file($p)) { $sobras[] = "$rel: nao existe"; continue; }

    foreach (file($p, FILE_IGNORE_NEW_LINES) as $i => $linha) {
        if (stripos($linha, 'mikrotik') === false) { continue; }
        $l = preg_replace($identificadores, '', $linha);
        // Comentario de codigo: o cliente nunca ve. Comentario HTML (<!-- -->)
        // tambem nao aparece na tela.
        $semComentario = preg_replace('#(//|\#(?!\[)|/\*|\*|<!--).*$#', '', ltrim($l));
        if (stripos($semComentario, 'mikrotik') === false) { continue; }
        $sobras[] = $rel . ':' . ($i + 1) . '  ' . trim($linha);
    }
}

if ($sobras) {
    echo "FALHOU — \"MikroTik\" ainda visivel:\n  " . implode("\n  ", $sobras) . "\n";
    exit(1);
}
echo 'ok — ' . count($arquivos) . " arquivos, nenhuma ocorrencia visivel\n";
