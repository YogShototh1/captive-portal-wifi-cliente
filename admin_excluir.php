<?php
// Exclui um usuário (não apaga os leads; só remove o acesso).
require_once __DIR__ . '/inc/auth.php';

$admin = exigir_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valido($_POST['csrf'] ?? '')) {
    $id = (int) ($_POST['id'] ?? 0);
    // Não permite excluir a própria conta (evita se trancar pra fora).
    if ($id > 0 && $id !== (int) $admin['id']) {
        db()->prepare('DELETE FROM roteadores WHERE comprador_id = ?')->execute([$id]);
        $q = db()->prepare('DELETE FROM compradores WHERE id = ?');
        $q->execute([$id]);
    }
}

header('Location: admin.php');
exit;
