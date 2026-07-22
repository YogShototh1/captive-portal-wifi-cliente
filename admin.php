<?php
// Painel de administração: lista as contas e dá acesso a criar/editar/excluir e ver leads.
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/util.php';

$admin = exigir_admin();

$rows = db()->query(
    'SELECT c.id, c.nome, c.email, c.is_admin, c.criado_em,
            (SELECT GROUP_CONCAT(r.identity ORDER BY r.identity SEPARATOR \', \')
               FROM roteadores r WHERE r.comprador_id = c.id) AS rots,
            (SELECT COUNT(*) FROM leads l
              WHERE l.roteador IN (SELECT r2.identity FROM roteadores r2 WHERE r2.comprador_id = c.id)) AS total_leads
       FROM compradores c
      ORDER BY c.is_admin DESC, c.criado_em DESC'
)->fetchAll();

$csrf = csrf_token();
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <script>/* Aberto fora da casca? Manda para /painel (a URL fica sempre em /painel). */ if (top === self) location.replace('/painel');</script>
    <script>(function(){try{var t=localStorage.getItem('cd-tema');document.documentElement.setAttribute('data-tema',t==='escuro'?'escuro':'claro');}catch(e){document.documentElement.setAttribute('data-tema','claro');}})();</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administração</title>
    <link rel="stylesheet" href="assets/style.css?v=63">
</head>
<body class="painel-cliente">
    <!-- Camadas de fundo (decorativas) -->
    <div class="pc-bg-gradient"></div>
    <div class="pc-bg-noise"></div>
    <div class="pc-glow pc-glow-top"></div>
    <div class="pc-glow pc-glow-bottom"></div>

    <div class="pc-shell">
        <header class="pc-topbar">
            <div>
                <h1 class="pc-brand">Administração</h1>
                <p class="pc-sub">Olá, <?= h($admin['nome'] ?: $admin['email']) ?> — gerenciamento de contas</p>
            </div>
            <div class="pc-topbar-actions">
                <a class="pc-btn-primary" href="admin_form.php">+ Novo usuário</a>
                <a class="pc-btn" href="sair.php">Sair</a>
            </div>
        </header>

        <section class="glow-card pc-table-card">
            <span class="glow-fx" aria-hidden="true"></span>
            <div class="glow-body">
                <div class="pc-table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>E-mail</th>
                                <th>Roteadores (identity)</th>
                                <th>Leads</th>
                                <th>Tipo</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?= h($r['nome']) ?></td>
                                <td><?= h($r['email']) ?></td>
                                <td><?= $r['rots'] ? h($r['rots']) : '—' ?></td>
                                <td><?= (int) $r['total_leads'] ?></td>
                                <td><?= ((int) $r['is_admin'] === 1) ? '<span class="pc-badge">admin</span>' : '<span class="pc-tipo-cliente">cliente</span>' ?></td>
                                <td class="pc-actions">
                                    <?php if ((int) $r['is_admin'] !== 1): ?>
                                        <a class="pc-link" href="admin_leads.php?id=<?= (int) $r['id'] ?>">Ver leads</a>
                                    <?php endif; ?>
                                    <a class="pc-link" href="admin_form.php?id=<?= (int) $r['id'] ?>">Editar</a>
                                    <?php if ((int) $r['id'] !== (int) $admin['id']): ?>
                                    <form method="post" action="admin_excluir.php" class="pc-inline-form"
                                          onsubmit="return confirm('Excluir <?= h($r['email']) ?>? Esta ação não apaga os leads.');">
                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="pc-link pc-link-danger">Excluir</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <?php require __DIR__ . '/inc/tema.php'; ?>
</body>
</html>
