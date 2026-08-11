<?php
// Teste da pagina-ponte do Instagram (config + render).
// Rodar:  php tools/teste_ig.php
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

$R = '__teste_ig__';
@unlink(ig_file($R));

// ---------------------------------------------------------------
echo "config\n";
$d = ig_get($R);
$ok($d['perfil'] === '', 'sem config o perfil vem vazio');
$ok(count($d['cores']) === 8 && count($d['estilo']) === 7, 'defaults completos');
$ok(ig_pronta($R) === false, 'sem perfil a pagina nao serve de destino');

// O comprador tem o LINK em maos, nao o @ — os dois precisam valer.
foreach ([
    'maniasdosul'                              => 'maniasdosul',
    '@maniasdosul'                             => 'maniasdosul',
    'https://www.instagram.com/maniasdosul/'   => 'maniasdosul',
    'https://instagram.com/manias.do_sul'      => 'manias.do_sul',
    'instagram.com/maniasdosul?igsh=abc'       => 'maniasdosul',
] as $entrada => $esperado) {
    ig_set($R, ['perfil' => $entrada]);
    $ok(ig_get($R)['perfil'] === $esperado, "perfil de \"$entrada\"", ig_get($R)['perfil']);
}
// Lixo nao vira perfil.
foreach (['nao vale', '<script>', '', 'a/b'] as $ruim) {
    ig_set($R, ['perfil' => $ruim]);
    $ok(ig_get($R)['perfil'] === '', 'perfil invalido rejeitado: "' . $ruim . '"', ig_get($R)['perfil']);
}

// ---------------------------------------------------------------
echo "\nsalvar em partes nao apaga o resto\n";
@unlink(ig_file($R));
ig_set($R, ['perfil' => 'maniasdosul', 'titulo' => 'Siga @maniasdosul',
            'tem_flags' => 1, 'estilo' => ['logo' => 1, 'cartao' => 1]]);
ig_set($R, ['cores' => ['bg' => '#BB823F']]);                       // so cores
$g = ig_get($R);
$ok($g['perfil'] === 'maniasdosul', 'perfil sobrevive a salvar so cores');
$ok($g['titulo'] === 'Siga @maniasdosul', 'titulo sobrevive');
$ok($g['cores']['bg'] === '#bb823f', 'cor gravada em minusculas', $g['cores']['bg']);
$ok($g['cores']['fg'] === ig_padrao()['cores']['fg'], 'cor nao enviada fica no padrao');
$ok($g['estilo']['logo'] === 1 && $g['estilo']['sombra'] === 0, 'flags sobrevivem sem tem_flags');

// Sem o sentinela, flag nenhuma e tocada (checkbox desmarcado nao viaja).
ig_set($R, ['estilo' => ['logo' => 0]]);
$ok(ig_get($R)['estilo']['logo'] === 1, 'flag so muda com tem_flags');

// ---------------------------------------------------------------
echo "\nleitura pelo hash (o que a pagina publica usa)\n";
$h = ig_hash($R);
$ok(preg_match('/^[0-9a-f]{40}$/', $h) === 1, 'hash e sha1');
$ok(ig_get_hash($h)['perfil'] === 'maniasdosul', 'config vem pelo hash');
$ok(ig_get_hash('nao-e-hash')['perfil'] === '', 'hash invalido cai no padrao');
$ok(strpos(ig_url($R), $h) !== false, 'a url leva o hash');
$ok(strpos(ig_url($R), $R) === false, 'a url NAO leva o identity do MikroTik');

// ---------------------------------------------------------------
echo "\nrender da pagina\n";
function render(array $get): string
{
    $_GET = $get;
    ob_start();
    include __DIR__ . '/../ig.php';
    return (string) ob_get_clean();
}
ig_set($R, ['perfil' => 'maniasdosul', 'titulo' => 'Siga @maniasdosul', 'rodape' => 'Manias do Sul',
            'cores' => ['bg' => '#bb823f', 'btn' => '#6e1a22'], 'tem_flags' => 1,
            'estilo' => ['cartao' => 1, 'copiar' => 1, 'redondo' => 1, 'sombra' => 1]]);
$html = render(['r' => $h]);
$ok(strpos($html, 'Siga @maniasdosul') !== false, 'titulo sai na pagina');
$ok(strpos($html, '--bg:#bb823f') !== false, 'cor vira variavel CSS');
$ok(preg_match('~instagram\.com/maniasdosul/\?ig_mid=~', $html) === 1, 'link do perfil montado');
$ok(strpos($html, 'utm_source=igweb') !== false, 'utm_source=igweb (o que evita o erro no CNA)');
$ok(strpos($html, 'Manias do Sul') !== false, 'rodape sai');
$ok(strpos($html, 'id="copiar"') !== false, 'botao copiar sai com a flag ligada');
$ok(strpos($html, 'ig-pronto') === false, 'pagina do cliente NAO carrega o script de previa');
$ok(strpos($html, $R) === false, 'o identity do MikroTik nao vaza no HTML');

// Flags desligadas somem da pagina.
ig_set($R, ['tem_flags' => 1, 'estilo' => ['cartao' => 0, 'copiar' => 0]]);
$html = render(['r' => $h]);
$ok(strpos($html, 'id="copiar"') === false, 'sem a flag, o botao copiar nao sai');
$ok(strpos($html, 'cd-no-cartao') !== false || strpos($html, 'class="card"') === false,
    'cartao desligado nao aparece');

// Texto do comprador e escapado (ele digita, entao e entrada nao confiavel).
ig_set($R, ['titulo' => '<script>alert(1)</script>']);
$html = render(['r' => $h]);
$ok(strpos($html, '<script>alert(1)') === false, 'titulo com HTML e escapado');
$ok(strpos($html, '&lt;script&gt;') !== false, 'escapado como texto');

// Previa: mesma pagina, com o listener.
$html = render(['r' => $h, 'previa' => '1']);
$ok(strpos($html, 'ig-pronto') !== false, 'previa carrega o listener de postMessage');
$ok(strpos($html, 'data-previa="1"') !== false, 'previa marca o <html>');

// Modo antigo (?u=perfil) continua de pe, para destino ja salvo.
$html = render(['u' => 'algumperfil']);
$ok(strpos($html, '@algumperfil') !== false, 'modo antigo ?u= ainda renderiza');

@unlink(ig_file($R));
echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
