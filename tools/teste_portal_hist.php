<?php
// Teste do historico de envios da pagina de login.
// Grava num roteador ficticio e apaga no fim — nao toca em dado real.
// Rodar:  php tools/teste_portal_hist.php
require_once __DIR__ . '/../inc/util.php';

$falhas = 0;
$ok = function ($cond, $nome, $viu = '') use (&$falhas) {
    if ($cond) { echo "  ok   $nome\n"; }
    else { $falhas++; echo "  FALHOU  $nome  $viu\n"; }
};

$R = '__teste_portal_hist__';
$arq = portal_hist_file($R);
@unlink($arq);

echo "vazio\n";
$ok(portal_hist($R) === [], 'roteador sem envio devolve lista vazia');
$ok(portal_hist('') === [], 'roteador vazio nao explode');

echo "\ngravacao\n";
portal_hist_add($R, 'hotspot-v1.zip', 12, 'Loja Teste');
portal_hist_add($R, 'login.html', 1, 'Loja Teste');
portal_hist_add($R, 'hotspot-v2.zip', 15, 'Suporte (admin)');
$h = portal_hist($R);
$ok(count($h) === 3, 'tres envios gravados', count($h));
$ok($h[0]['nome'] === 'hotspot-v2.zip', 'mais recente primeiro', $h[0]['nome']);
$ok($h[2]['nome'] === 'hotspot-v1.zip', 'mais antigo por ultimo', $h[2]['nome']);
$ok($h[0]['qtd'] === 15, 'quantidade de arquivos guardada', $h[0]['qtd']);
$ok($h[0]['quem'] === 'Suporte (admin)', 'quem enviou guardado', $h[0]['quem']);
$ok(!empty($h[0]['em']) && strtotime($h[0]['em']) !== false, 'data valida', $h[0]['em'] ?? '');

echo "\nteto (nao cresce sem fim)\n";
for ($i = 0; $i < PORTAL_HIST_MAX + 10; $i++) { portal_hist_add($R, "envio-$i.zip", 1, 'x'); }
$h = portal_hist($R);
$ok(count($h) === PORTAL_HIST_MAX, 'lista para em PORTAL_HIST_MAX', count($h));
$ok($h[0]['nome'] === 'envio-' . (PORTAL_HIST_MAX + 9) . '.zip', 'o ultimo envio sobrevive ao corte', $h[0]['nome']);

echo "\nrender do bloco da tela\n";
$portalHist = portal_hist($R);
ob_start();
require __DIR__ . '/../inc/portal_hist_tela.php';
$html = ob_get_clean();
$ok(strpos($html, 'envio-' . (PORTAL_HIST_MAX + 9) . '.zip') !== false, 'ultimo envio aparece na tela');
$ok(substr_count($html, '<li') === PORTAL_HIST_MAX, 'uma linha por envio no historico', substr_count($html, '<li'));
$ok(strpos($html, '<details') !== false, 'historico usa <details> (sem JS)');

// Nome de arquivo com HTML nao pode virar tag na tela.
@unlink($arq);
portal_hist_add($R, '<script>alert(1)</script>.zip', 1, 'x');
$portalHist = portal_hist($R);
ob_start();
require __DIR__ . '/../inc/portal_hist_tela.php';
$html = ob_get_clean();
$ok(strpos($html, '<script>') === false, 'nome de arquivo e escapado (sem XSS)');
$ok(strpos($html, '&lt;script&gt;') !== false, 'escapado como texto');

// Sem historico o bloco nao imprime nada.
$portalHist = [];
$portalFiles = [];
ob_start();
require __DIR__ . '/../inc/portal_hist_tela.php';
$ok(trim(ob_get_clean()) === '', 'sem envio nenhum o bloco fica fora da tela');

// Ja ha arquivos, mas de antes do registro existir: explica em vez de calar.
$portalFiles = ['login.html', 'css/style.css'];
ob_start();
require __DIR__ . '/../inc/portal_hist_tela.php';
$html = ob_get_clean();
$ok(strpos($html, 'antes do') !== false, 'arquivos antigos sem registro ganham explicacao');
$ok(strpos($html, '<details') === false, 'e sem botao de historico vazio');

@unlink($arq);
echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
