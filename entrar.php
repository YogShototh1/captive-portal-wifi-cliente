<?php
// Tela de login do comprador.
require_once __DIR__ . '/inc/auth.php';

if (comprador_logado()) {
    header('Location: painel.php');
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ipLogin = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!csrf_valido($_POST['csrf'] ?? '')) {
        $erro = 'Sessão expirada. Tente novamente.';
    } elseif (login_bloqueado($ipLogin)) {
        // Força bruta: muitas falhas deste IP na última janela.
        $erro = 'Muitas tentativas. Aguarde alguns minutos e tente de novo.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $senha = (string) ($_POST['senha'] ?? '');
        if (tentar_login($email, $senha)) {
            login_limpar_falhas($ipLogin);
            header('Location: ' . (is_admin() ? 'admin.php' : 'painel.php'));
            exit;
        }
        login_registrar_falha($ipLogin);
        $erro = 'E-mail ou senha inválidos.';
    }
}
$csrf = csrf_token();
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <script>/* Aberto fora da casca? Manda para /painel (a URL fica sempre em /painel). */ if (top === self) location.replace('/painel');</script>
    <script>(function(){try{var t=localStorage.getItem('cd-tema');document.documentElement.setAttribute('data-tema',t==='escuro'?'escuro':'claro');}catch(e){document.documentElement.setAttribute('data-tema','claro');}})();</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <title>Painel — Acesso</title>
    <link rel="stylesheet" href="assets/style.css?v=52">
</head>
<body class="login-screen">
    <!-- Camadas de fundo (decorativas) -->
    <div class="lp-bg-gradient"></div>
    <div class="lp-bg-noise"></div>
    <div class="lp-glow lp-glow-top"></div>
    <div class="lp-glow lp-glow-bottom"></div>

    <main class="lp-card-wrap">
        <div class="lp-card">
            <!-- Feixes de luz percorrendo a borda -->
            <div class="lp-beams" aria-hidden="true">
                <span class="lp-beam lp-beam-top"></span>
                <span class="lp-beam lp-beam-right"></span>
                <span class="lp-beam lp-beam-bottom"></span>
                <span class="lp-beam lp-beam-left"></span>
            </div>

            <div class="lp-card-inner">
                <div class="lp-header">
                    <div class="lp-logo" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h.01"/><path d="M2 8.82a15 15 0 0 1 20 0"/><path d="M5 12.859a10 10 0 0 1 14 0"/><path d="M8.5 16.429a5 5 0 0 1 7 0"/></svg>
                    </div>
                    <h1 class="lp-title">Painel de Leads</h1>
                    <p class="lp-subtitle">Entre para acessar seus leads</p>
                </div>

                <?php if ($erro): ?>
                    <p class="lp-alerta"><?= htmlspecialchars($erro) ?></p>
                <?php endif; ?>

                <form method="post" autocomplete="off" class="lp-form">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

                    <div class="lp-field">
                        <svg class="lp-field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                        <input type="email" name="email" required placeholder="E-mail" aria-label="E-mail">
                    </div>

                    <div class="lp-field">
                        <svg class="lp-field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" name="senha" required placeholder="Senha" aria-label="Senha">
                    </div>

                    <button type="submit" class="lp-submit">
                        <span>Entrar</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </button>
                </form>
            </div>
        </div>
    </main>

    <script>
    // Foco no e-mail SEM rolar: o atributo autofocus fazia o navegador rolar o
    // documento (dentro do iframe da casca) antes da centralização assentar, e
    // o cartão aparecia deslocado para cima. preventScroll evita; e zeramos
    // qualquer rolagem residual do carregamento.
    (function () {
        window.scrollTo(0, 0);
        var email = document.querySelector('input[name="email"]');
        if (email) { try { email.focus({ preventScroll: true }); } catch (e) {} }
    })();
    </script>
    <script>
    // Inclinação 3D do cartão seguindo o mouse (como no exemplo de referência).
    (function () {
        var wrap = document.querySelector('.login-screen .lp-card-wrap');
        var card = document.querySelector('.login-screen .lp-card');
        if (!wrap || !card) return;
        var MAX = 12;
        wrap.style.perspective = '1500px';
        card.style.transformStyle = 'preserve-3d';
        card.style.transition = 'transform .18s ease-out';
        card.style.willChange = 'transform';
        document.addEventListener('mousemove', function (e) {
            var r = card.getBoundingClientRect();
            var near = e.clientX > r.left - 60 && e.clientX < r.right + 60 &&
                       e.clientY > r.top - 60 && e.clientY < r.bottom + 60;
            if (!near) { card.style.transform = 'rotateX(0deg) rotateY(0deg)'; return; }
            var mx = e.clientX - r.left - r.width / 2;
            var my = e.clientY - r.top - r.height / 2;
            var rx = Math.max(-MAX, Math.min(MAX, (my / 300) * -MAX));
            var ry = Math.max(-MAX, Math.min(MAX, (mx / 300) * MAX));
            card.style.transform = 'rotateX(' + rx.toFixed(2) + 'deg) rotateY(' + ry.toFixed(2) + 'deg)';
        }, { passive: true });
    })();
    </script>
    <?php require __DIR__ . '/inc/tema.php'; ?>
</body>
</html>
