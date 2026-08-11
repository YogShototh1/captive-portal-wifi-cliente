<?php
// Trava do login por e-mail OU nome.
//
// O nome NAO e unico na tabela (so o e-mail e), entao a consulta pode devolver
// mais de uma conta. Quem decide quem entra e o escolher_conta(): testa a senha
// em cada candidata, na ordem em que vieram. Este teste protege o que nao pode
// mudar — que a senha errada nao abre NENHUMA das contas, e que a ordem manda
// no desempate.
//
// Rodar:  php tools/teste_login.php
// Teste de linha de comando, nunca pela web. O tools/.htaccess ja bloqueia a
// pasta; esta guarda existe para o bloqueio nao depender de o servidor honrar
// aquele arquivo.
if (PHP_SAPI !== "cli") { http_response_code(404); exit; }

// So a funcao pura: o resto do auth.php abre sessao e fala com o banco.
$src = (string) file_get_contents(__DIR__ . '/../inc/auth.php');
$ini = strpos($src, 'function escolher_conta');
$fim = strpos($src, '// Login por e-mail OU nome.');
eval(substr($src, $ini, $fim - $ini));

$falhas = 0;
$ok = function ($cond, $nome, $viu = '') use (&$falhas) {
    if ($cond) { echo "  ok   $nome\n"; }
    else { $falhas++; echo "  FALHOU  $nome  $viu\n"; }
};

// Custo 4 so para o teste nao demorar; em producao vale o padrao do PHP.
$h = function (string $s): string { return password_hash($s, PASSWORD_BCRYPT, ['cost' => 4]); };

$dono   = ['id' => 1, 'senha_hash' => $h('senha-do-dono')];
$outro  = ['id' => 2, 'senha_hash' => $h('senha-do-outro')];

echo "uma candidata so\n";
$ok(escolher_conta([$dono], 'senha-do-dono')['id'] === 1, 'senha certa entra');
$ok(escolher_conta([$dono], 'senha-errada') === null, 'senha errada nao entra');
$ok(escolher_conta([$dono], '') === null, 'senha vazia nao entra');

echo "\nduas contas com o mesmo nome\n";
$ok(escolher_conta([$dono, $outro], 'senha-do-outro')['id'] === 2,
    'a senha e que escolhe a conta, nao a posicao na lista');
$ok(escolher_conta([$dono, $outro], 'senha-do-dono')['id'] === 1,
    'e a outra senha abre a outra conta');
$ok(escolher_conta([$dono, $outro], 'nenhuma-das-duas') === null,
    'senha de ninguem nao abre nenhuma das duas');

// Empate real (mesmo nome E mesma senha): nao ha resposta certa, mas nao pode
// ser aleatorio. Vale a ordem da consulta — dono do e-mail primeiro.
$g1 = ['id' => 7, 'senha_hash' => $h('igual')];
$g2 = ['id' => 8, 'senha_hash' => $h('igual')];
$ok(escolher_conta([$g1, $g2], 'igual')['id'] === 7, 'empate cai na primeira da consulta (determinista)');
$ok(escolher_conta([$g2, $g1], 'igual')['id'] === 8, 'e acompanha a ordem, nao o id');

echo "\nlista vazia ou torta\n";
$ok(escolher_conta([], 'qualquer') === null, 'sem candidata, ninguem entra');
$ok(escolher_conta([['id' => 3]], 'qualquer') === null, 'linha sem senha_hash nao entra');
$ok(escolher_conta([['id' => 4, 'senha_hash' => '']], 'qualquer') === null, 'hash vazio nao entra');
$ok(escolher_conta([['id' => 5, 'senha_hash' => 'nao-e-hash']], 'nao-e-hash') === null,
    'hash invalido nao vira senha em texto puro');

// ---------------------------------------------------------------
// A tela tambem faz parte: type="email" faria o navegador recusar um nome sem
// "@" antes de o formulario chegar ao PHP.
echo "\ntela de login\n";
$tela = (string) file_get_contents(__DIR__ . '/../entrar.php');
$ok(strpos($tela, 'type="text" name="usuario"') !== false, 'o campo aceita texto, nao so e-mail');
$ok(strpos($tela, 'type="email"') === false, 'nenhum type="email" sobrou barrando o nome');
$ok(strpos($tela, 'E-mail ou nome') !== false, 'o rotulo diz que aceita os dois');
$ok(strpos($tela, "input[name=\"usuario\"]") !== false, 'o foco do JS aponta para o campo novo');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
