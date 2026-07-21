<?php
require_once __DIR__ . '/inc/auth.php';
logout();
header('Location: entrar.php'); // tela de login (dentro da casca)
exit;
