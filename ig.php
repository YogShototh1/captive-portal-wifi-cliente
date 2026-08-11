<?php
// Página-ponte para o Instagram. Destino pós-anúncio do hotspot.
//
// Por que ela existe: o CNA do iPhone bloqueia o redirect instagram:// que o
// site do Instagram força quando não há cookies. Mandar o cliente direto para
// instagram.com dá "Erro ao Abrir a Página". Aqui mostramos um cartão próprio
// com um botão: no CNA abre a versão web; fora dele (Safari/app) abre o
// aplicativo. (Sem embed do Instagram: o embed de PERFIL foi descontinuado e
// renderiza em branco sem login — cartão próprio é determinístico.)
//
// Três modos de chamada:
//   ?r=<hash>   página do comprador, com as cores/textos que ele escolheu no
//               painel. O hash é o mesmo que nomeia os arquivos de ads/, então
//               o identity do MikroTik não aparece na URL.
//   ?u=<perfil> ponte genérica (destinos antigos, salvos antes disto existir).
//   &previa=1   idem ?r=, mas escutando postMessage: é o que o modal do painel
//               mostra dentro da moldura de iPhone, atualizando ao vivo.
ini_set('display_errors', '0');

require_once __DIR__ . '/inc/util.php';

$hash   = (string) ($_GET['r'] ?? '');
$previa = !empty($_GET['previa']);
// &local=1: a versão que vive NA FLASH do roteador (api/tema.php a entrega e o
// leadsync a grava como flash/hostsv7/ig.html). A única diferença é a logo, que
// aponta para o arquivo local em vez do painel — assim a página abre inteira
// sem tocar na internet, que é o ponto de servi-la do roteador.
$local  = !empty($_GET['local']);

if (preg_match('/^[0-9a-f]{40}$/', $hash)) {
    $cfg  = ig_get_hash($hash);
    $logo = ig_logo_hash($hash);
} else {
    // Modo antigo: ?u=perfil. Mantido para não quebrar destino já salvo.
    $u = (string) ($_GET['u'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._]{1,30}$/', $u) || $u === '.' || $u === '..') {
        header('Location: https://www.google.com');
        exit;
    }
    // Cliente com pasta própria feita à mão (/maniasdosul/) continua indo para ela.
    if (is_dir(__DIR__ . '/' . $u)) {
        header('Location: /' . $u . '/');
        exit;
    }
    $cfg = ig_padrao();
    $cfg['perfil'] = $u;
    $cfg['titulo'] = 'Siga @' . $u;
    $logo = null;
    $hash = '';
}

// Sem perfil não há para onde mandar o cliente. Só acontece se o comprador
// aplicou a página e depois apagou o @ — o painel não deixa aplicar sem ele.
if ($cfg['perfil'] === '' && !$previa) {
    header('Location: https://www.google.com');
    exit;
}

$c   = $cfg['cores'];
$st  = $cfg['estilo'];
$p   = $cfg['perfil'];
$temLogo = !empty($st['logo']) && $logo !== null;

// utm_source=igweb: faz o Instagram servir a página web em vez de forçar o
// redirect instagram:// que o CNA bloqueia. O ig_mid imita o cookie que o
// Safari teria — sem ele o Instagram insiste no app.
$hex = strtoupper(bin2hex(random_bytes(16)));
$mid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
     . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
$destino = $p !== ''
    ? 'https://www.instagram.com/' . $p . '/?ig_mid=' . $mid . '&utm_source=igweb'
    : '#';

// Cada flag DESLIGADA vira uma classe cd-no-<flag> no <html> — a mesma
// convenção do laço da prévia (o postMessage lá embaixo faz exatamente isto).
// Faltava aqui: sem estas classes, degradê, sombra, cantos retos e "sem cartão"
// só funcionavam dentro do modal, e a página que o cliente recebia saía sempre
// com o padrão do CSS. A prévia é para mostrar a página real; então tem que ser
// o mesmo estado nos dois lados.
$clsHtml = [];
foreach ($st as $fk => $fv) {
    if (empty($fv)) { $clsHtml[] = 'cd-no-' . $fk; }
}
if (!empty($st['grad']))   { $clsHtml[] = 'cd-grad'; }
if (!empty($st['sombra'])) { $clsHtml[] = 'cd-sombra'; }

$IG_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/></svg>';
?>
<!doctype html>
<html lang="pt-br" class="<?= h(implode(' ', $clsHtml)) ?>"<?= $previa ? ' data-previa="1"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title><?= h($p !== '' ? '@' . $p . ' no Instagram' : 'Instagram') ?></title>
<style>
/* As cores entram como variáveis: é o que deixa a prévia do painel trocar tudo
   ao vivo, sem recarregar a página, mexendo só no style do <html>. */
:root{
    --bg:<?= h($c['bg']) ?>;
    --card:<?= h($c['card']) ?>;
    --fg:<?= h($c['fg']) ?>;
    --fg2:<?= h($c['fg2']) ?>;
    --btn:<?= h($c['btn']) ?>;
    --btn2:<?= h($c['btn2']) ?>;
    --btnfg:<?= h($c['btnfg']) ?>;
    --border:<?= h($c['border']) ?>;
    --raio:18px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{
    min-height:100dvh;display:flex;flex-direction:column;align-items:center;justify-content:center;
    padding:26px 20px 18px;text-align:center;
    font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
    background:var(--bg);color:var(--fg);
}
/* Manchas de luz: duas de propósito, nas cores do botão, para o fundo não
   ficar chapado sem custar imagem nenhuma. */
.cd-manchas body, body.cd-manchas{
    background:
        radial-gradient(60% 50% at 12% 0%,color-mix(in srgb,var(--btn) 16%,transparent),transparent 70%),
        radial-gradient(55% 45% at 92% 6%,color-mix(in srgb,var(--btn2) 16%,transparent),transparent 70%),
        var(--bg);
}
.logo{width:112px;height:112px;object-fit:contain;margin-bottom:12px}
h1{font-size:22px;letter-spacing:-.02em;margin-bottom:5px;line-height:1.25}
.sub{font-size:13.5px;color:var(--fg2);margin-bottom:18px;line-height:1.5;max-width:340px}
.card{
    width:100%;max-width:380px;background:var(--card);
    border:1px solid var(--border);border-radius:var(--raio);
    padding:26px 18px;margin-bottom:16px;
    display:flex;flex-direction:column;align-items:center;gap:10px;
}
.ig-ic{width:60px;height:60px;color:var(--btn)}
.arroba{font-size:18px;font-weight:800;letter-spacing:-.01em;word-break:break-all}
.chamada{font-size:12.5px;color:var(--fg2);line-height:1.55;max-width:280px}
.btn{
    display:flex;align-items:center;justify-content:center;gap:9px;
    width:100%;max-width:380px;min-height:52px;padding:14px 20px;
    border-radius:var(--raio);text-decoration:none;cursor:pointer;
    background:var(--btn);color:var(--btnfg);font-weight:700;font-size:15px;font-family:inherit;border:none;
}
.btn svg{width:19px;height:19px;flex:0 0 auto}
.btn-2{
    display:flex;align-items:center;justify-content:center;gap:8px;
    width:100%;max-width:380px;min-height:44px;padding:11px 20px;margin-top:9px;
    border-radius:var(--raio);cursor:pointer;
    background:var(--card);border:1.5px solid var(--border);color:var(--btn);
    font-weight:700;font-size:14px;font-family:inherit;
}
.btn-2 svg{width:16px;height:16px}
.dica{font-size:12px;color:var(--fg2);opacity:.85;margin-top:12px;line-height:1.5;max-width:330px}
.rodape{margin-top:22px;font-size:11.5px;letter-spacing:.16em;text-transform:uppercase;color:var(--fg2)}

/* --- Efeitos ligáveis. Cada flag desligada vira uma classe cd-no-* no <html>,
       igual ao que a tela de login já faz. --- */
.cd-grad .btn{background:linear-gradient(100deg,var(--btn),var(--btn2))}
.cd-sombra .card{box-shadow:0 22px 46px -20px color-mix(in srgb,var(--fg) 45%,transparent)}
.cd-sombra .btn{box-shadow:0 14px 26px -12px color-mix(in srgb,var(--btn) 65%,transparent)}
html.cd-no-redondo{--raio:4px}
html.cd-no-cartao .card{display:none}
/* Na prévia estes dois estão sempre no HTML e é a classe que os liga e desliga
   (ver o comentário no <body>); na página real o PHP nem os monta. */
html.cd-no-logo #cd-logo{display:none}
html.cd-no-copiar #copiar,html.cd-no-copiar .dica{display:none}
/* Logo redonda. Escrita no negativo (:not) porque a convenção só emite a classe
   da flag DESLIGADA — e esta nasce desligada, ao contrário das outras.
   object-fit:cover corta no meio; com contain a logo boiaria dentro do círculo. */
html:not(.cd-no-logoredonda) #cd-logo{border-radius:50%;object-fit:cover}
</style>
</head>
<body class="<?= !empty($st['manchas']) ? 'cd-manchas' : '' ?>">
    <?php /* Na PRÉVIA os três blocos condicionais abaixo (logo, "copiar @" e
             rodapé) são sempre montados, e quem os esconde é o CSS pelas
             classes cd-no-*. Enquanto o PHP decidia se eles existiam, marcar a
             opção no painel não fazia nada: o elemento não estava no HTML e
             não havia o que mostrar — só aparecia depois de salvar, quando a
             página era montada de novo. Na página real nada muda: o que está
             desligado continua não indo para o HTML do cliente. */ ?>
    <?php if ($temLogo || ($previa && $logo !== null)): ?>
    <img class="logo" id="cd-logo" src="<?= $local ? 'logo.img' : 'logo.php?h=' . h($hash) ?>" alt="">
    <?php endif; ?>

    <h1 id="cd-titulo"><?= h($cfg['titulo']) ?></h1>
    <p class="sub" id="cd-sub"><?= h($cfg['sub']) ?></p>

    <div class="card" id="cd-card">
        <span class="ig-ic"><?= $IG_SVG ?></span>
        <div class="arroba" id="cd-arroba">@<?= h($p) ?></div>
        <p class="chamada" id="cd-chamada"><?= h($cfg['chamada']) ?></p>
    </div>

    <a class="btn" id="cd-btn" href="<?= h($destino) ?>">
        <?= $IG_SVG ?>
        <span id="cd-btn-tx"><?= h($cfg['botao']) ?></span>
    </a>

    <?php if (!empty($st['copiar']) || $previa): ?>
    <button type="button" class="btn-2" id="copiar" data-perfil="<?= h($p) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
        Copiar @<span id="cd-copiar-tx"><?= h($p) ?></span>
    </button>
    <p class="dica">No iPhone: toque em <strong>Concluído</strong> no canto da tela, abra o Instagram e cole o perfil na busca.</p>
    <?php endif; ?>

    <?php if (trim($cfg['rodape']) !== '' || $previa): ?>
    <footer class="rodape" id="cd-rodape"<?= trim($cfg['rodape']) === '' ? ' style="display:none"' : '' ?>><?= h($cfg['rodape']) ?></footer>
    <?php endif; ?>

<script>
// Copia o @ para a área de transferência (clipboard API + fallback antigo).
// Serve quem não conseguiu abrir o Instagram pelo botão — no CNA acontece.
(function () {
    var b = document.getElementById('copiar');
    if (!b) return;
    b.addEventListener('click', function () {
        var p = '@' + b.getAttribute('data-perfil');
        function feito() { b.textContent = 'Copiado!'; }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(p).then(feito).catch(fallback);
        } else { fallback(); }
        function fallback() {
            var t = document.createElement('textarea');
            t.value = p; document.body.appendChild(t); t.select();
            try { document.execCommand('copy'); feito(); } catch (e) {}
            document.body.removeChild(t);
        }
    });
})();
</script>

<?php if ($previa): ?>
<script>
/* Só na prévia do painel: escuta o modal e repinta ao vivo. A página é a MESMA
   que o cliente recebe — o que o comprador vê aqui é o que vai ao ar. */
(function () {
    var raiz = document.documentElement;
    window.addEventListener('message', function (e) {
        // Iframe e painel sao sempre da mesma origem (src relativo). Mensagem
        // de qualquer outra origem nao tem o que fazer aqui.
        if (e.origin !== location.origin) return;
        var d = e.data;
        if (!d || d.tipo !== 'ig') return;

        for (var k in d.cores) { raiz.style.setProperty('--' + k, d.cores[k]); }

        // Flag desligada vira classe cd-no-<flag>, como na tela de login.
        for (var f in d.estilo) { raiz.classList.toggle('cd-no-' + f, !d.estilo[f]); }
        raiz.classList.toggle('cd-grad',   !!d.estilo.grad);
        raiz.classList.toggle('cd-sombra', !!d.estilo.sombra);
        document.body.classList.toggle('cd-manchas', !!d.estilo.manchas);
        // logo e "copiar @" saem pelo cd-no-* do laço acima, como os demais.

        function txt(id, v) { var el = document.getElementById(id); if (el) el.textContent = v; }
        txt('cd-titulo',  d.titulo);
        txt('cd-sub',     d.sub);
        txt('cd-chamada', d.chamada);
        txt('cd-btn-tx',  d.botao);
        txt('cd-arroba',  '@' + (d.perfil || 'seuperfil'));
        txt('cd-copiar-tx', d.perfil || 'seuperfil');
        var rod = document.getElementById('cd-rodape');
        if (rod) { rod.textContent = d.rodape; rod.style.display = d.rodape ? '' : 'none'; }

        // Realce do elemento que o mouse está mexendo, para achar o que é o quê.
        raiz.classList.toggle('cd-realce', !!d.destaque);
    });
    // Avisa o painel que já pode mandar o primeiro estado.
    if (window.parent !== window) { window.parent.postMessage({ tipo: 'ig-pronto' }, location.origin); }
})();
</script>
<style>
/* Clicar na prévia abre a cor do elemento — o mesmo gesto da tela de login. */
html[data-previa] #cd-titulo, html[data-previa] #cd-sub, html[data-previa] .card,
html[data-previa] #cd-btn, html[data-previa] body { cursor: pointer; }
</style>
<?php endif; ?>
</body>
</html>
