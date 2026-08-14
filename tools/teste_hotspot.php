<?php
// Teste do liga/desliga do hotspot (estado reportado -> ordem -> consumo).
// Rodar:  php tools/teste_hotspot.php
// Teste de linha de comando, nunca pela web. O tools/.htaccess ja bloqueia a
// pasta; esta guarda existe para o bloqueio nao depender de o servidor honrar
// aquele arquivo. Vale a pena: estes testes rodam sem autenticacao nenhuma, e
// alguns gravam e apagam arquivos em ads/.
if (PHP_SAPI !== "cli") { http_response_code(404); exit; }

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

hotspot_estado_set($R, 'hotspot1~1~hsprof1|');
$e = hotspot_estado_get($R);
$ok($e !== null && count($e['servidores']) === 1, 'um servidor lido', json_encode($e));
$ok($e['servidores'][0]['nome'] === 'hotspot1', 'nome preservado');
$ok($e['ligado'] === true, 'ligado');

hotspot_estado_set($R, 'hotspot1~0~hsprof1|');
$ok(hotspot_estado_get($R)['ligado'] === false, 'desligado');

// Dois servidores por engano: o painel precisa listar os dois para o admin
// apagar o que sobra. "Ligado" e ter ALGUM ligado.
hotspot_estado_set($R, 'hotspot1~0~default|hotspot2~1~hsprof1|');
$e = hotspot_estado_get($R);
$ok(count($e['servidores']) === 2, 'dois servidores aparecem', json_encode($e['servidores']));
$ok($e['ligado'] === true, 'um ligado entre dois ja conta como ligado');

// Roteador sem hotspot nenhum: e um estado REAL, nao "desconhecido".
hotspot_estado_set($R, '');
$e = hotspot_estado_get($R);
$ok($e !== null && $e['servidores'] === [], 'roteador sem hotspot: lista vazia, mas conhecido');
$ok($e['ligado'] === false, 'sem servidor, nada ligado');

// Entrada de rede: o que nao casa com o formato e descartado, nao corrigido.
hotspot_estado_set($R, 'bom~1~p|sem-flag|ruim~2~p|esp aco~1~p|' . str_repeat('x', 40) . '~1~p|');
$e = hotspot_estado_get($R);
$ok(count($e['servidores']) === 1 && $e['servidores'][0]['nome'] === 'bom',
    'lixo descartado, so o valido entra', json_encode($e['servidores']));

// O PERFIL DO SERVIDOR e o campo decisivo: ter um perfil com trial no roteador
// nao basta, vale o que ESTE servidor usa. Foi o que faltou na primeira versao
// desta telemetria — a tela mostrava os perfis e mesmo assim nao dava para
// dizer qual estava valendo.
hotspot_estado_set($R, 'hotspot-lan~1~default|');
$e = hotspot_estado_get($R);
$ok(($e['servidores'][0]['perfil'] ?? '') === 'default', 'o perfil do servidor chega ao painel',
    json_encode($e['servidores']));

// Formato antigo ("<nome>:<0|1>,"): o roteador leva ~1 min para baixar o app
// novo, e nesse intervalo a tela nao pode ficar sem os servidores.
hotspot_estado_set($R, 'hotspot1:1,');
$e = hotspot_estado_get($R);
$ok(count($e['servidores']) === 1 && $e['ligado'] === true, 'formato antigo ainda e lido');
$ok(($e['servidores'][0]['perfil'] ?? null) === '', 'so que sem saber o perfil');

// ---------------------------------------------------------------
// O perfil e o que diz se o cliente CONSEGUE entrar: o portal autentica por
// trial, entao sem "trial" no login-by o roteador recusa todo login e o
// cliente volta pro comeco do fluxo.
echo "\nperfil do hotspot\n";
$p = hotspot_perfis_parse('hsprof1~http-chap;trial~30m~1d|');
$ok(count($p) === 1, 'um perfil lido', json_encode($p));
$ok(($p[0]['nome'] ?? '') === 'hsprof1', 'nome');
$ok(($p[0]['login'] ?? '') === 'http-chap,trial', 'o ";" do :tostr vira virgula', $p[0]['login'] ?? '');
$ok(($p[0]['trial'] ?? null) === true, 'trial reconhecido');
$ok(($p[0]['limite'] ?? '') === '30m' && ($p[0]['reset'] ?? '') === '1d', 'limite e reset');

$ok(($p[0]['http'] ?? null) === true, 'metodo HTTP reconhecido');

$p = hotspot_perfis_parse('hsprof1~http-chap;http-pap~none~none|');
$ok(($p[0]['trial'] ?? null) === false, 'sem trial e detectado — e a causa de ninguem entrar');

// Trial sozinho: o roteador recusa com "HTTP/HTTPS login is not allowed", e o
// pior e que so recusa DEPOIS do anuncio inteiro. Aconteceu em campo.
$p = hotspot_perfis_parse('cd-perfil~trial~8w4d~8w5d|');
$ok(($p[0]['trial'] ?? null) === true, 'trial ligado');
$ok(($p[0]['http'] ?? null) === false, 'trial sem metodo HTTP e detectado');

$p = hotspot_perfis_parse('a~trial~30m~1d|b~http-chap~none~none|');
$ok(count($p) === 2, 'dois perfis', count($p));

$ok(hotspot_perfis_parse('') === [], 'vazio nao vira perfil fantasma');
$ok(hotspot_perfis_parse('faltando~campos|') === [], 'registro incompleto e descartado');

// O perfil sobrevive a uma rodada em que o roteador nao conseguiu coleta-lo.
hotspot_estado_set($R, 'hotspot1~1~hsprof1|', 'hsprof1~trial~30m~1d|');
hotspot_estado_set($R, 'hotspot1~1~hsprof1|', '');
$e = hotspot_estado_get($R);
$ok(count($e['perfis']) === 1, 'perfil conhecido nao some quando a coleta falha', json_encode($e['perfis']));

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
