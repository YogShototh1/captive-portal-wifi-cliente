<?php
// Página-ponte para Instagram (?u=perfil). Usada como destino pós-anúncio:
// o CNA do iPhone bloqueia o redirecionamento instagram:// que o site do
// Instagram força — aqui mostramos um cartão próprio com o perfil e um botão:
// no CNA abre a versão web; fora dele (Safari/app) abre o aplicativo.
// (Sem iframe/embed do Instagram: o embed de PERFIL foi descontinuado e
// renderiza em branco sem login — cartão próprio é determinístico.)
ini_set('display_errors', '0');

$u = (string) ($_GET['u'] ?? '');
if (!preg_match('/^[A-Za-z0-9._]{1,30}$/', $u) || $u === '.' || $u === '..') {
    header('Location: https://www.google.com');
    exit;
}

// Cliente com página PERSONALIZADA (pasta /<perfil>/ na raiz)? Vai para ela —
// assim destinos antigos salvos como ig.php?u=X já caem na página nova.
if (is_dir(__DIR__ . '/' . $u)) {
    header('Location: /' . $u . '/');
    exit;
}

$perfil = htmlspecialchars($u, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>@<?= $perfil ?> no Instagram</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{
    min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:18px;
    font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
    background:
        radial-gradient(60% 50% at 12% 0%,rgba(124,58,237,.13),transparent 70%),
        radial-gradient(55% 45% at 92% 6%,rgba(236,72,153,.13),transparent 70%),
        #faf5ff;
    color:#0f172a;
}
.card{
    width:100%;max-width:420px;background:#fff;border:1px solid #ece3fb;border-radius:22px;
    box-shadow:0 30px 60px -24px rgba(109,40,217,.35);padding:22px;text-align:center;
}
h1{font-size:20px;letter-spacing:-.02em;margin-bottom:4px}
.sub{font-size:13.5px;color:#64748b;margin-bottom:18px}
/* Cartão de perfil próprio (sem embed do Instagram) */
.perfil{
    display:flex;flex-direction:column;align-items:center;gap:10px;
    border:1px solid #ece3fb;border-radius:14px;background:#faf5ff;
    padding:26px 16px;margin-bottom:16px;
}
.avatar{
    width:92px;height:92px;border-radius:50%;padding:4px;
    background:linear-gradient(45deg,#f9ce34,#ee2a7b,#6228d7);
}
.avatar-in{
    width:100%;height:100%;border-radius:50%;background:#fff;
    display:flex;align-items:center;justify-content:center;color:#ee2a7b;
}
.avatar-in svg{width:44px;height:44px}
.arroba{font-size:18px;font-weight:800;letter-spacing:-.01em;word-break:break-all}
.chamada{font-size:13px;color:#64748b;line-height:1.5;max-width:280px}
.btn{
    display:flex;align-items:center;justify-content:center;gap:9px;width:100%;
    min-height:50px;padding:13px 20px;border-radius:14px;text-decoration:none;
    background:linear-gradient(100deg,#7c3aed,#ec4899);color:#fff;font-weight:700;font-size:15px;
}
.btn svg{width:19px;height:19px}
.btn-2{
    display:flex;align-items:center;justify-content:center;gap:8px;width:100%;
    min-height:44px;padding:11px 20px;border-radius:14px;margin-top:8px;cursor:pointer;
    background:#fff;border:1.5px solid #ece3fb;color:#7c3aed;font-weight:700;font-size:14px;font-family:inherit;
}
.btn-2 svg{width:16px;height:16px}
.dica{font-size:12px;color:#94a3b8;margin-top:10px;line-height:1.5}
</style>
</head>
<body>
    <main class="card">
        <h1>Siga @<?= $perfil ?></h1>
        <p class="sub">Wi-Fi liberado! Aproveite e siga a gente no Instagram.</p>
        <div class="perfil">
            <div class="avatar"><div class="avatar-in">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/></svg>
            </div></div>
            <div class="arroba">@<?= $perfil ?></div>
            <p class="chamada">Novidades, promoções e bastidores — siga o perfil e fique por dentro.</p>
        </div>
        <?php
        // utm_source=igweb: faz o Instagram servir a página web (sem forçar o
        // redirect instagram:// que o CNA do iPhone bloqueia).
        $hex = strtoupper(bin2hex(random_bytes(16)));
        $mid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
             . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
        ?>
        <a class="btn" href="https://www.instagram.com/<?= $perfil ?>/?ig_mid=<?= $mid ?>&amp;utm_source=igweb">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/></svg>
            Abrir no Instagram
        </a>
        <button type="button" class="btn-2" id="copiar" data-perfil="<?= $perfil ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
            Copiar @<?= $perfil ?>
        </button>
        <p class="dica">No iPhone: toque em <strong>Concluído</strong> no canto da tela, abra o Instagram e cole o perfil na busca.</p>
    </main>
    <script>
    // Copia o @ para a área de transferência (clipboard API + fallback antigo).
    (function () {
        var b = document.getElementById('copiar');
        if (!b) return;
        b.addEventListener('click', function () {
            var p = '@' + b.getAttribute('data-perfil');
            function feito() { b.innerHTML = 'Copiado!'; }
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
</body>
</html>
