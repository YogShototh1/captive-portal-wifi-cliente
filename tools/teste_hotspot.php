<?php
// Teste do liga/desliga do hotspot (estado reportado -> ordem -> consumo).
// Rodar:  php tools/teste_hotspot.php
require_once __DIR__ . '/../inc/util.php';

$falhas = 0;
$ok = function ($cond, $nome, $viu = '') use (&$falhas) {
    if ($cond) { echo "  ok   $nome\n"; }
    else { $falhas++; echo "  FALHOU  $nome  $viu\n"; }
};

$R = '__teste_hotspot__';
$limpar = function () use ($R) {
    @unlink(hotspot_estado_file($R));
    @unlink(hotspot_ordem_file($R));
};
$limpar();

// ---------------------------------------------------------------
echo "estado reportado pelo roteador\n";
$ok(hotspot_estado_get($R) === null, 'sem relato, o painel nao inventa estado');

hotspot_estado_set($R, 'hotspot1:1,');
$e = hotspot_estado_get($R);
$ok($e !== null && count($e['servidores']) === 1, 'um servidor lido', json_encode($e));
$ok($e['servidores'][0]['nome'] === 'hotspot1', 'nome preservado');
$ok($e['ligado'] === true, 'ligado');

hotspot_estado_set($R, 'hotspot1:0,');
$ok(hotspot_estado_get($R)['ligado'] === false, 'desligado');

// Dois servidores por engano: o painel precisa listar os dois para o admin
// apagar o que sobra. "Ligado" e ter ALGUM ligado.
hotspot_estado_set($R, 'hotspot1:0,hotspot2:1,');
$e = hotspot_estado_get($R);
$ok(count($e['servidores']) === 2, 'dois servidores aparecem', json_encode($e['servidores']));
$ok($e['ligado'] === true, 'um ligado entre dois ja conta como ligado');

// Roteador sem hotspot nenhum: e um estado REAL, nao "desconhecido".
hotspot_estado_set($R, '');
$e = hotspot_estado_get($R);
$ok($e !== null && $e['servidores'] === [], 'roteador sem hotspot: lista vazia, mas conhecido');
$ok($e['ligado'] === false, 'sem servidor, nada ligado');

// Entrada de rede: o que nao casa com o formato e descartado, nao corrigido.
hotspot_estado_set($R, 'bom:1,sem-flag,ruim:2,esp aco:1,' . str_repeat('x', 40) . ':1,');
$e = hotspot_estado_get($R);
$ok(count($e['servidores']) === 1 && $e['servidores'][0]['nome'] === 'bom',
    'lixo descartado, so o valido entra', json_encode($e['servidores']));

// ---------------------------------------------------------------
echo "\nordem do painel\n";
$limpar();
$ok(hotspot_ordem_pendente($R) === null, 'nada pedido');
$ok(hotspot_ordem_ler($R) === null, 'o roteador nao recebe ordem que ninguem deu');

hotspot_ordem_pedir($R, false);
$ok(hotspot_ordem_pendente($R) === false, 'desligar fica pendente');
$ok(hotspot_ordem_ler($R) === false, 'o roteador recebe "desligar"');
$ok(hotspot_ordem_pendente($R) === null, 'e a ordem e CONSUMIDA na leitura');
$ok(hotspot_ordem_ler($R) === null, 'nao repete a ordem na rodada seguinte');

hotspot_ordem_pedir($R, true);
$ok(hotspot_ordem_ler($R) === true, 'ligar tambem chega');

// Ordem velha: roteador que ficou fora e voltou horas depois nao deve acordar
// executando o que ninguem lembra mais de ter pedido.
hotspot_ordem_pedir($R, true);
@touch(hotspot_ordem_file($R), time() - 700);
$ok(hotspot_ordem_pendente($R) === null, 'ordem com mais de 10 min nao vale');
$ok(hotspot_ordem_ler($R) === null, 'e nao e executada');
$ok(!is_file(hotspot_ordem_file($R)), 'ordem vencida sai do disco');

$limpar();
echo "\n" . ($falhas === 0 ? "tudo ok\n" : "$falhas falha(s)\n");
exit($falhas === 0 ? 0 : 1);
