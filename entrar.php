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
        // Aceita e-mail OU nome no mesmo campo (ver tentar_login).
        $usuario = trim($_POST['usuario'] ?? $_POST['email'] ?? '');
        $senha   = (string) ($_POST['senha'] ?? '');
        if (tentar_login($usuario, $senha)) {
            login_limpar_falhas($ipLogin);
            header('Location: ' . (is_admin() ? 'admin.php' : 'painel.php'));
            exit;
        }
        login_registrar_falha($ipLogin);
        $erro = 'Usuário ou senha inválidos.';
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" href="assets/logo-icone.png?v=1" type="image/png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="assets/style.css?v=135">
</head>
<body class="login-screen">
    <!-- Camadas de fundo (decorativas) -->
    <div class="lp-bg-gradient"></div>
    <div class="lp-bg-noise"></div>
    <div class="lp-glow lp-glow-top"></div>
    <div class="lp-glow lp-glow-bottom"></div>

    <main class="lp-card-wrap">
        <div class="lp-card">

            <div class="lp-card-inner">
                <div class="lp-header">
                    <div class="lp-logo" aria-hidden="true">
                        <img src="assets/logo-icone.png?v=1" alt="">
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
                        <svg class="lp-field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <?php /* type="text", não "email": o navegador recusaria um
                                 nome sem "@" antes de o formulário sair daqui. */ ?>
                        <input type="text" name="usuario" required placeholder="E-mail ou nome"
                               aria-label="E-mail ou nome" autocomplete="username" spellcheck="false">
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
    // Foco no primeiro campo SEM rolar: o atributo autofocus fazia o navegador
    // rolar o documento (dentro do iframe da casca) antes da centralização
    // assentar, e o cartão aparecia deslocado para cima. preventScroll evita; e
    // zeramos qualquer rolagem residual do carregamento.
    (function () {
        window.scrollTo(0, 0);
        var usuario = document.querySelector('input[name="usuario"]');
        if (usuario) { try { usuario.focus({ preventScroll: true }); } catch (e) {} }
    })();
    </script>
    <?php require __DIR__ . '/inc/tema.php'; ?>
</body>
</html>
