<?php
// Criar / editar usuário (comprador ou admin).
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/util.php';

$admin = exigir_admin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editando = $id > 0;
$erro = '';
$dados = ['nome' => '', 'email' => '', 'roteadores' => '', 'is_admin' => 0, 'portal_habilitado' => 0];

// Modo edição: carrega os dados atuais (exceto quando acabou de enviar o form).
if ($editando && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $q = db()->prepare('SELECT nome, email, is_admin, portal_habilitado FROM compradores WHERE id = ?');
    $q->execute([$id]);
    $row = $q->fetch();
    if (!$row) {
        header('Location: admin.php');
        exit;
    }
    $dados = $row;
    $dados['roteadores'] = implode("\n", roteadores_conta($id));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? '')) {
        $erro = 'Sessão expirada. Tente novamente.';
    } else {
        $nome      = trim($_POST['nome'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $rotsTxt   = (string) ($_POST['roteadores'] ?? '');
        $senha     = (string) ($_POST['senha'] ?? '');
        $isAdmin   = !empty($_POST['is_admin']) ? 1 : 0;
        $portalHab = !empty($_POST['portal_habilitado']) ? 1 : 0;
        // Um identity por linha -> lista limpa (sem vazios/duplicados).
        $rots = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $rotsTxt)))));
        $dados = ['nome' => $nome, 'email' => $email, 'roteadores' => implode("\n", $rots), 'is_admin' => $isAdmin, 'portal_habilitado' => $portalHab];

        $rotLongo = array_filter($rots, function ($r) { return strlen($r) > 120; });
        if ($email === '') {
            $erro = 'Informe o e-mail.';
        } elseif (!$editando && $senha === '') {
            $erro = 'Informe uma senha para o novo usuário.';
        } elseif (!$isAdmin && !$rots) {
            $erro = 'Informe pelo menos um roteador (identity) do cliente.';
        } elseif ($rotLongo) {
            $erro = 'Identity muito longo (máx. 120 caracteres).';
        } else {
            try {
                db()->beginTransaction();
                if ($editando) {
                    if ($senha !== '') {
                        $q = db()->prepare('UPDATE compradores SET nome=?, email=?, is_admin=?, portal_habilitado=?, senha_hash=? WHERE id=?');
                        $q->execute([$nome, $email, $isAdmin, $portalHab, password_hash($senha, PASSWORD_BCRYPT), $id]);
                    } else {
                        $q = db()->prepare('UPDATE compradores SET nome=?, email=?, is_admin=?, portal_habilitado=? WHERE id=?');
                        $q->execute([$nome, $email, $isAdmin, $portalHab, $id]);
                    }
                    $uid = $id;
                } else {
                    $q = db()->prepare('INSERT INTO compradores (nome, email, senha_hash, is_admin, portal_habilitado) VALUES (?,?,?,?,?)');
                    $q->execute([$nome, $email, password_hash($senha, PASSWORD_BCRYPT), $isAdmin, $portalHab]);
                    $uid = (int) db()->lastInsertId();
                }
                // Sincroniza os vínculos: a lista do formulário é a verdade.
                db()->prepare('DELETE FROM roteadores WHERE comprador_id = ?')->execute([$uid]);
                $ins = db()->prepare('INSERT INTO roteadores (comprador_id, identity) VALUES (?, ?)');
                foreach ($rots as $rt) {
                    $ins->execute([$uid, $rt]);
                }
                db()->commit();
                header('Location: admin.php');
                exit;
            } catch (PDOException $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                $erro = ($e->getCode() === '23000')
                    ? 'E-mail já cadastrado ou roteador vinculado a outra conta.'
                    : 'Erro ao salvar.';
            }
        }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $editando ? 'Editar usuário' : 'Novo usuário' ?></title>
    <link rel="stylesheet" href="assets/style.css?v=60">
</head>
<body class="login-screen">
    <div class="lp-bg-gradient"></div>
    <div class="lp-bg-noise"></div>
    <div class="lp-glow lp-glow-top"></div>
    <div class="lp-glow lp-glow-bottom"></div>

    <main class="lp-card-wrap af-wide">
        <div class="lp-card">
            <div class="lp-beams" aria-hidden="true">
                <span class="lp-beam lp-beam-top"></span>
                <span class="lp-beam lp-beam-right"></span>
                <span class="lp-beam lp-beam-bottom"></span>
                <span class="lp-beam lp-beam-left"></span>
            </div>

            <div class="lp-card-inner">
                <div class="lp-header">
                    <div class="lp-logo" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <h1 class="lp-title"><?= $editando ? 'Editar usuário' : 'Novo usuário' ?></h1>
                    <p class="lp-subtitle"><?= $editando ? 'Atualize os dados da conta' : 'Cadastre uma nova conta' ?></p>
                </div>

                <?php if ($erro): ?><p class="lp-alerta"><?= h($erro) ?></p><?php endif; ?>

                <form method="post" autocomplete="off" class="af-form">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

                    <div class="af-field">
                        <label>Nome</label>
                        <input type="text" name="nome" value="<?= h($dados['nome']) ?>" placeholder="Nome">
                    </div>
                    <div class="af-field">
                        <label>E-mail</label>
                        <input type="email" name="email" value="<?= h($dados['email']) ?>" required placeholder="email@exemplo.com">
                    </div>
                    <div class="af-field">
                        <label>Roteadores (identity do MikroTik — um por linha)</label>
                        <textarea name="roteadores" rows="3" placeholder="ex.:&#10;PRIMIX-LOJA-01&#10;PRIMIX-LOJA-02"><?= h($dados['roteadores']) ?></textarea>
                    </div>
                    <div class="af-field">
                        <label>Senha <?= $editando ? '(deixe em branco para manter)' : '' ?></label>
                        <input type="password" name="senha" <?= $editando ? '' : 'required' ?> placeholder="••••••••">
                    </div>

                    <label class="af-check">
                        <input type="checkbox" name="is_admin" value="1" <?= ((int) $dados['is_admin'] === 1) ? 'checked' : '' ?>>
                        É administrador (sem roteador; acesso total)
                    </label>

                    <label class="af-check">
                        <input type="checkbox" name="portal_habilitado" value="1" <?= ((int) $dados['portal_habilitado'] === 1) ? 'checked' : '' ?>>
                        Liberar upload da página de login do hotspot (o cliente edita pelo painel dele; você edita sempre)
                    </label>

                    <div class="af-actions">
                        <button type="submit" class="af-btn af-btn-primary">Salvar</button>
                        <a class="af-btn af-btn-secondary" href="admin.php">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
    // Inclinação 3D do cartão seguindo o mouse (igual à tela de login).
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
