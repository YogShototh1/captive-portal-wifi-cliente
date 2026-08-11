<?php
// Teste do historico de logos/anuncios (guardar, listar, repor).
// Grava num roteador ficticio e limpa no fim.
// Rodar:  php tools/teste_midia_hist.php
// Teste de linha de comando, nunca pela web. O tools/.htaccess ja bloqueia a
// pasta; esta guarda existe para o bloqueio nao depender de o servidor honrar
// aquele arquivo. Vale a pena: estes testes rodam sem autenticacao nenhuma, e
// alguns gravam e apagam arquivos em ads/.
if (PHP_SAPI !== "cli") { http_response_code(404); exit; }

require_once __DIR__ . '/../inc/util.php';

$falhas = 0;
$ok = function ($cond, $nome, $viu = '') use (&$falhas) {
    if ($cond) { echo "  ok   $nome\n"; }
    else { $falhas++; echo "  FALHOU  $nome  $viu\n"; }
};

$R = '__teste_midia__';
$tmp = sys_get_temp_dir() . '/midia_teste';
@mkdir($tmp, 0777, true);

// Limpa qualquer resto de execucao anterior.
$limpar = function () use ($R, $tmp) {
    foreach (midia_tipos() as $t) {
        foreach (midia_hist($R, $t) as $x) {
            @unlink(midia_hist_img($R, $t, (string) $x['id'], (string) $x['ext']));
        }
        @unlink(midia_hist_file($R, $t));
        foreach (['jpg', 'png'] as $e) {
            @unlink(midia_base($R, $t) . '.' . $e);
            @unlink(midia_base($R, $t) . '.flash.' . $e);
        }
    }
    array_map('unlink', glob("$tmp/*") ?: []);
};
$limpar();

// Gera uma imagem de cor solida (cada cor -> conteudo diferente -> id diferente).
$fazer = function (string $arq, int $r, int $g, int $b) {
    $im = imagecreatetruecolor(200, 200);
    imagefilledrectangle($im, 0, 0, 199, 199, imagecolorallocate($im, $r, $g, $b));
    imagejpeg($im, $arq, 90);
    imagedestroy($im);
};

if (!function_exists('imagecreatetruecolor')) {
    echo "  (pulado: PHP sem GD)\n"; exit(0);
}

// ---------------------------------------------------------------
echo "vazio\n";
$ok(midia_hist($R, 'logo') === [], 'sem envio, historico vazio');
$ok(midia_hist_ativo($R, 'logo') === null, 'sem imagem ativa');
$ok(midia_hist_arquivo($R, 'logo', 'abc') === null, 'id inexistente devolve null');
$ok(midia_hist_usar($R, 'logo', 'abc') === false, 'nao da para usar id inexistente');

// ---------------------------------------------------------------
echo "\nguardar\n";
$ids = [];
foreach ([[255,0,0,'vermelha.jpg'], [0,255,0,'verde.jpg'], [0,0,255,'azul.jpg']] as [$r,$g,$b,$nome]) {
    $fazer("$tmp/$nome", $r, $g, $b);
    copy("$tmp/$nome", midia_base($R, 'logo') . '.jpg');       // simula o upload
    midia_hist_add($R, 'logo', midia_base($R, 'logo') . '.jpg', $nome);
    $ids[$nome] = substr(sha1_file("$tmp/$nome"), 0, 16);
}
$h = midia_hist($R, 'logo');
$ok(count($h) === 3, 'tres envios guardados', count($h));
$ok($h[0]['nome'] === 'azul.jpg', 'mais recente primeiro', $h[0]['nome']);
$ok($h[2]['nome'] === 'vermelha.jpg', 'mais antigo por ultimo', $h[2]['nome']);
$ok(midia_hist_ativo($R, 'logo') === $ids['azul.jpg'], 'a ultima enviada e a ativa');

// Reenviar a MESMA imagem nao duplica — so volta ao topo.
copy("$tmp/vermelha.jpg", midia_base($R, 'logo') . '.jpg');
midia_hist_add($R, 'logo', midia_base($R, 'logo') . '.jpg', 'vermelha-de-novo.jpg');
$h = midia_hist($R, 'logo');
$ok(count($h) === 3, 'reenviar a mesma imagem nao cria item repetido', count($h));
$ok($h[0]['id'] === $ids['vermelha.jpg'], 'ela volta para o topo');

// ---------------------------------------------------------------
echo "\nrepor uma anterior\n";
$ok(midia_hist_usar($R, 'logo', $ids['verde.jpg']) === true, 'repor a verde funciona');
$ok(midia_hist_ativo($R, 'logo') === $ids['verde.jpg'], 'a verde passou a ser a ativa');
$ok(count(midia_hist($R, 'logo')) === 3, 'repor NAO altera o historico', count(midia_hist($R, 'logo')));
$ok(midia_hist_arquivo($R, 'logo', $ids['azul.jpg']) !== null, 'a que saiu do ar continua guardada');

// O derivado da flash tem que sumir, senao o roteador levaria a imagem antiga.
file_put_contents(midia_base($R, 'logo') . '.flash.jpg', 'derivado velho');
midia_hist_usar($R, 'logo', $ids['azul.jpg']);
$ok(!is_file(midia_base($R, 'logo') . '.flash.jpg'), 'o derivado para a flash e descartado na troca');

// ---------------------------------------------------------------
// O fluxo REAL do upload: a que sai e guardada antes de o arquivo ser trocado.
// Sem isso, a imagem anterior sumia justamente quando passaria a fazer falta.
echo "\na imagem que SAI nao se perde\n";
$limpar();
$fazer("$tmp/velha.jpg", 11, 22, 33);
copy("$tmp/velha.jpg", midia_base($R, 'logo') . '.jpg');      // ja estava no ar,
$idVelha = substr(sha1_file("$tmp/velha.jpg"), 0, 16);        // sem historico nenhum
$ok(midia_hist($R, 'logo') === [], 'ponto de partida: imagem no ar, historico vazio');

// upload novo: guarda a que sai, apaga, grava a que entra, guarda a que entra
$anterior = midia_atual($R, 'logo');
midia_hist_add($R, 'logo', $anterior, 'logo anterior');
@unlink(midia_base($R, 'logo') . '.jpg');
$fazer("$tmp/nova.jpg", 99, 88, 77);
copy("$tmp/nova.jpg", midia_base($R, 'logo') . '.jpg');
midia_hist_add($R, 'logo', midia_base($R, 'logo') . '.jpg', 'nova.jpg');

$h = midia_hist($R, 'logo');
$ok(count($h) === 2, 'depois de UM upload ja ha duas imagens guardadas', count($h));
$ok(midia_hist_arquivo($R, 'logo', $idVelha) !== null, 'a que saiu continua recuperavel');
$ok(midia_hist_ativo($R, 'logo') === substr(sha1_file("$tmp/nova.jpg"), 0, 16), 'a nova esta no ar');
$ok(midia_hist_usar($R, 'logo', $idVelha) === true, 'da para voltar para a antiga');
$ok(midia_hist_ativo($R, 'logo') === $idVelha, 'e ela volta a ser a ativa');

// ---------------------------------------------------------------
echo "\nteto de " . MIDIA_HIST_MAX . " itens\n";
$limpar();
$sumido = null;
for ($i = 0; $i < MIDIA_HIST_MAX + 3; $i++) {
    $fazer("$tmp/n$i.jpg", $i * 7, 100, 200);
    copy("$tmp/n$i.jpg", midia_base($R, 'logo') . '.jpg');
    midia_hist_add($R, 'logo', midia_base($R, 'logo') . '.jpg', "n$i.jpg");
    if ($i === 0) { $sumido = substr(sha1_file("$tmp/n0.jpg"), 0, 16); }
}
$h = midia_hist($R, 'logo');
$ok(count($h) === MIDIA_HIST_MAX, 'a lista para no teto', count($h));
$ok($h[0]['nome'] === 'n' . (MIDIA_HIST_MAX + 2) . '.jpg', 'o ultimo envio sobrevive', $h[0]['nome']);
$ok(midia_hist_arquivo($R, 'logo', $sumido) === null, 'o mais antigo saiu do indice');
$ok(count(glob(anuncio_base($R) . '.hist-logo-*')) === MIDIA_HIST_MAX,
    'e o ARQUIVO dele foi apagado (nao fica lixo na hospedagem)',
    count(glob(anuncio_base($R) . '.hist-logo-*')));

// ---------------------------------------------------------------
echo "\nlogo e anuncio nao se misturam\n";
$limpar();
$fazer("$tmp/l.jpg", 10, 20, 30);
copy("$tmp/l.jpg", midia_base($R, 'logo') . '.jpg');
midia_hist_add($R, 'logo', midia_base($R, 'logo') . '.jpg', 'l.jpg');
$fazer("$tmp/a.jpg", 200, 100, 50);
copy("$tmp/a.jpg", midia_base($R, 'anuncio') . '.jpg');
midia_hist_add($R, 'anuncio', midia_base($R, 'anuncio') . '.jpg', 'a.jpg');
$ok(count(midia_hist($R, 'logo')) === 1 && count(midia_hist($R, 'anuncio')) === 1, 'um item em cada tipo');
$ok(midia_hist($R, 'logo')[0]['nome'] === 'l.jpg', 'a logo e a logo');
$ok(midia_hist($R, 'anuncio')[0]['nome'] === 'a.jpg', 'o anuncio e o anuncio');
$ok(midia_hist_arquivo($R, 'anuncio', midia_hist($R, 'logo')[0]['id']) === null,
    'id de logo nao alcanca arquivo de anuncio');

// ---------------------------------------------------------------
echo "\ntipo invalido nao passa\n";
$ok(midia_hist($R, 'qualquer') === [], 'tipo fora da lista devolve vazio');
midia_hist_add($R, 'qualquer', "$tmp/l.jpg", 'x.jpg');
$ok(!is_file(anuncio_base($R) . '.hist-qualquer.json'), 'e nao grava nada');

$limpar();
@rmdir($tmp);
echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
