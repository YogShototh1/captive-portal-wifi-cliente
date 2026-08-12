<?php
// Trava da conta da data de saida do hospede.
//
// E a unica conta do painel de hospedagem, e e ela que decide a hora em que o
// Wi-Fi para: o portal so compara `saida_em > NOW()`. Errar um dia aqui deixa
// hospede sem internet, ou deixa quem ja saiu navegando de graca.
//
// Rodar:  php tools/teste_hospede.php
// Teste de linha de comando, nunca pela web. O tools/.htaccess ja bloqueia a
// pasta; esta guarda existe para o bloqueio nao depender de o servidor honrar
// aquele arquivo.
if (PHP_SAPI !== "cli") { http_response_code(404); exit; }

// So a funcao pura: o resto do util.php fala com o banco.
$src = (string) file_get_contents(__DIR__ . '/../inc/util.php');
$ini = strpos($src, 'function hospede_saida');
$fim = strpos($src, '// Hóspedes de um ou mais roteadores');
eval(substr($src, $ini, $fim - $ini));

require_once __DIR__ . '/../inc/validacao.php';

$falhas = 0;
$ok = function ($cond, $nome, $viu = '') use (&$falhas) {
    if ($cond) { echo "  ok   $nome\n"; }
    else { $falhas++; echo "  FALHOU  $nome  $viu\n"; }
};

echo "conta da saida\n";
$ok(hospede_saida('2026-08-12', 1, '12:00') === '2026-08-13 12:00:00',
    'uma diaria: sai no dia seguinte ao meio-dia', hospede_saida('2026-08-12', 1, '12:00'));
$ok(hospede_saida('2026-08-12', 3, '10:00') === '2026-08-15 10:00:00',
    'tres diarias somam tres dias', hospede_saida('2026-08-12', 3, '10:00'));
$ok(hospede_saida('2026-08-12', 1, '23:59') === '2026-08-13 23:59:00',
    'a hora do check-out e respeitada');

echo "\nviradas que a soma ingenua erra\n";
// Fim de mes e ano: somar "+1 day" no timestamp resolve; somar 86400 tambem
// erraria no horario de verao. O teste existe para nao voltarem ao 86400.
$ok(hospede_saida('2026-08-31', 1, '12:00') === '2026-09-01 12:00:00', 'vira o mes');
$ok(hospede_saida('2026-12-31', 1, '12:00') === '2027-01-01 12:00:00', 'vira o ano');
$ok(hospede_saida('2028-02-28', 1, '12:00') === '2028-02-29 12:00:00', 'ano bissexto');
$ok(hospede_saida('2026-08-12', 30, '12:00') === '2026-09-11 12:00:00', 'trinta diarias');

echo "\nentrada torta nao pode virar data invalida\n";
// A tela valida antes, mas o portal compara com NOW(): uma data quebrada aqui
// tem de virar algo utilizavel, nunca string vazia ou 1970.
$r = hospede_saida('nao-e-data', 2, '12:00');
$ok(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $r) === 1, 'sempre devolve datetime valido', $r);
$ok(strtotime($r) > time(), 'e sempre no futuro (cai no padrao de hoje)', $r);
// Zero ou negativo viraria saida no passado = hospede sem Wi-Fi no check-in.
$ok(hospede_saida('2026-08-12', 0, '12:00') === '2026-08-13 12:00:00', 'zero diaria conta como uma');
$ok(hospede_saida('2026-08-12', -5, '12:00') === '2026-08-13 12:00:00', 'negativo tambem');

echo "\no numero do hospede usa as MESMAS regras do lead\n";
// O portal procura o hospede pelo telefone normalizado; se o cadastro guardasse
// num formato e o portal perguntasse noutro, ninguem entrava.
$ok(sanitiza_telefone('48988290878') === '48988290878', 'celular com DDD passa');
$ok(sanitiza_telefone('+55 (48) 98829-0878') === '48988290878', 'formatado vira o mesmo do portal');
$ok(sanitiza_telefone('123') === null, 'numero curto e recusado no cadastro');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
