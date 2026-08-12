<?php
// Trava da mescla de leads por telefone.
//
// A tabela `leads` tem 1 linha por (roteador, telefone). Quem conecta em mais de
// um MikroTik da conta tem uma linha em cada, e na visao "todos os roteadores"
// isso aparecia como dois clientes. lead_mesclado() junta as linhas numa so.
// O que nao pode mudar: qual linha manda no estado de agora, e que os numeros
// somados (conexoes, consumo) sejam de fato a soma.
//
// Rodar:  php tools/teste_mescla.php
// Teste de linha de comando, nunca pela web. O tools/.htaccess ja bloqueia a
// pasta; esta guarda existe para o bloqueio nao depender de o servidor honrar
// aquele arquivo.
if (PHP_SAPI !== "cli") { http_response_code(404); exit; }

// So a funcao pura: o resto do util.php fala com o banco.
$src = (string) file_get_contents(__DIR__ . '/../inc/util.php');
$ini = strpos($src, 'function lead_mesclado');
$fim = strpos($src, '// Uma página da tabela de leads');
eval(substr($src, $ini, $fim - $ini));

$falhas = 0;
$ok = function ($cond, $nome, $viu = '') use (&$falhas) {
    if ($cond) { echo "  ok   $nome\n"; }
    else { $falhas++; echo "  FALHOU  $nome  $viu\n"; }
};

// Linha de lead como vem do SELECT (so o que a mescla olha).
$lin = function (array $c): array {
    return $c + [
        'id' => 1, 'telefone' => '48988290878', 'nome' => null, 'ip' => null,
        'dispositivo' => null, 'conectado_em' => '2026-08-01 10:00:00', 'online' => 0,
        'segundos_conectado' => null, 'visto_em' => null, 'tempo_limite_min' => null,
        'banda_limite' => null, 'total_conexoes' => 1, 'bytes_total' => 0,
    ];
};

echo "quem manda no estado de agora\n";
// Conectou no PRIMIX ontem; esta online no TESTE agora (visto_em de segundos atras).
$antigo = $lin(['id' => 1, 'dispositivo' => 'Windows', 'conectado_em' => '2026-08-10 17:27:00',
                'visto_em' => '2026-08-10 17:31:00', 'total_conexoes' => 73, 'bytes_total' => 649]);
$agora  = $lin(['id' => 2, 'dispositivo' => 'iOS 18', 'conectado_em' => '2026-08-11 18:05:00',
                'visto_em' => '2026-08-11 18:05:43', 'online' => 1, 'total_conexoes' => 16, 'bytes_total' => 109]);
$m = lead_mesclado([$antigo, $agora]);
$ok((int) $m['id'] === 2, 'principal e a linha vista por ultimo', 'veio id=' . $m['id']);
$ok((int) $m['online'] === 1, 'online do aparelho que esta conectado agora');
$ok($m['dispositivo'] === 'iOS 18', 'aparelho e o da conexao de agora');
// A ordem em que as linhas chegam do banco nao pode mudar o resultado.
$ok(lead_mesclado([$agora, $antigo])['id'] === $m['id'], 'ordem de entrada nao importa');

echo "\nsomas\n";
$ok((int) $m['total_conexoes'] === 89, 'conexoes somam os dois roteadores (73+16)', 'veio ' . $m['total_conexoes']);
$ok((int) $m['bytes_total'] === 758, 'consumo soma os dois (649+109)', 'veio ' . $m['bytes_total']);
$ok($m['ids'] === [2, 1], 'leva os ids dos dois cadastros, principal primeiro',
    '[' . implode(',', $m['ids']) . ']');

echo "\nnome\n";
// Identificado num roteador e nao no outro: nao pode voltar a ser so um numero.
$semNome = $lin(['id' => 5, 'conectado_em' => '2026-08-11 18:00:00']);
$comNome = $lin(['id' => 6, 'nome' => 'Luiz', 'conectado_em' => '2026-08-01 09:00:00']);
$n = lead_mesclado([$semNome, $comNome]);
$ok((int) $n['id'] === 5, 'principal continua sendo o mais recente');
$ok($n['nome'] === 'Luiz', 'mas o nome vem do cadastro que tem nome');
// Dois nomes: vale o do cadastro mais recente.
$outro = $lin(['id' => 7, 'nome' => 'Luizinho', 'conectado_em' => '2026-08-11 18:00:00']);
$ok(lead_mesclado([$outro, $comNome])['nome'] === 'Luizinho', 'com dois nomes, vale o mais recente');
$ok(lead_mesclado([$semNome])['nome'] === null, 'sem nome nenhum continua sem nome');

echo "\nsem visto_em (sync ainda nao confirmou)\n";
// Sem visto_em, quem decide e a hora da conexao.
$a = $lin(['id' => 8, 'conectado_em' => '2026-08-11 18:00:00']);
$b = $lin(['id' => 9, 'conectado_em' => '2026-08-12 08:00:00']);
$ok((int) lead_mesclado([$a, $b])['id'] === 9, 'sem visto_em vale a conexao mais nova');
// visto_em de um lado, conexao mais nova do outro: o visto_em recente ganha.
$c = $lin(['id' => 10, 'conectado_em' => '2026-08-11 18:00:00', 'visto_em' => '2026-08-12 09:00:00']);
$ok((int) lead_mesclado([$b, $c])['id'] === 10, 'visto_em recente ganha de conexao mais antiga');

echo "\num roteador so (o caso comum)\n";
$um = lead_mesclado([$lin(['id' => 3, 'total_conexoes' => 4, 'bytes_total' => 12, 'nome' => 'Ana'])]);
$ok((int) $um['id'] === 3 && $um['ids'] === [3], 'uma linha sai igual, com o proprio id');
$ok((int) $um['total_conexoes'] === 4 && (int) $um['bytes_total'] === 12, 'e sem mexer nos numeros');
$ok($um['nome'] === 'Ana', 'e sem mexer no nome');

// Empate exato (mesmo instante nos dois roteadores): nao ha resposta certa, mas
// nao pode ser aleatorio — vale o id maior, que e o cadastro mais novo.
echo "\nempate\n";
$e1 = $lin(['id' => 20, 'conectado_em' => '2026-08-12 10:00:00']);
$e2 = $lin(['id' => 21, 'conectado_em' => '2026-08-12 10:00:00']);
$ok((int) lead_mesclado([$e1, $e2])['id'] === 21, 'empate cai no cadastro mais novo');
$ok((int) lead_mesclado([$e2, $e1])['id'] === 21, 'e nao muda com a ordem de entrada');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
