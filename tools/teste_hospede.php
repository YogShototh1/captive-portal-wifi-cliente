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

// ---------------------------------------------------------------
// Ocupação do dia: a mesma lista da aba Painel, contada por outro eixo.
$ini2 = strpos($src, 'function hospedes_ocupacao');
$fim2 = strpos($src, '// Filtro dos cartões de resumo');
eval(substr($src, $ini2, $fim2 - $ini2));

$hosp = function (array $a): array {
    return array_merge(['nome' => 'x', 'quarto' => '', 'telefone' => '', 'entrada_em' => '2026-08-01',
                        'saida_em' => '2026-08-20 12:00:00', 'hospedado' => 1, 'online' => 0], $a);
};
$lista = [
    $hosp(['quarto' => '101', 'entrada_em' => '2026-08-14', 'saida_em' => '2026-08-15 12:00:00', 'online' => 1]),
    $hosp(['quarto' => '101', 'entrada_em' => '2026-08-14', 'saida_em' => '2026-08-15 12:00:00']), // 2o da familia, MESMO quarto
    $hosp(['quarto' => '203', 'saida_em' => '2026-08-14 12:00:00']),                                // sai hoje
    $hosp(['quarto' => '204', 'saida_em' => '2026-08-16 12:00:00']),                                // fica
    $hosp(['quarto' => '305', 'saida_em' => '2026-08-13 12:00:00', 'hospedado' => 0, 'online' => 1]), // venceu e nao saiu
    $hosp(['quarto' => '306', 'saida_em' => '2026-08-10 12:00:00', 'hospedado' => 0]),              // foi embora
];
$o = hospedes_ocupacao($lista, '2026-08-14', '2026-08-15');

echo "\nocupacao do dia\n";
// 101 (dois hospedes), 203 e 204. O 305/306 ja fizeram check-out e nao ocupam.
$ok($o['quartos'] === 3, 'quarto com dois hospedes conta UM, e quem saiu nao ocupa', 'viu ' . $o['quartos']);
$ok($o['entram_hoje'] === 2, 'check-ins de hoje', 'viu ' . $o['entram_hoje']);
$ok($o['saem_hoje'] === 1, 'saem hoje', 'viu ' . $o['saem_hoje']);
$ok($o['saem_amanha'] === 2, 'saem amanha', 'viu ' . $o['saem_amanha']);
// "No Wi-Fi agora" e literal: conta quem esta conectado, tenha ou nao passado
// da hora. O 101 (um dos dois) e o 305, que venceu e nao desconectou.
$ok($o['conectados'] === 2, 'no wifi agora conta TODO mundo conectado', 'viu ' . $o['conectados']);
$ok(count($o['vencidos']) === 1 && $o['vencidos'][0]['quarto'] === '305',
    'vencido E conectado entra no aviso', 'viu ' . count($o['vencidos']));
// Quem foi embora e desligou o Wi-Fi nao e problema de ninguem.
$ok(!in_array('306', array_column($o['vencidos'], 'quarto'), true), 'quem saiu e desconectou fica de fora');

echo "\ncada cartao tem a sua lista, e o numero e o tamanho dela\n";
// A tela mostra a lista do cartao clicado: contador que nao bate com a lista
// embaixo seria pior que nao ter contador.
foreach (['entram_hoje', 'saem_hoje', 'saem_amanha', 'conectados', 'quartos'] as $k) {
    $ok($o[$k] === count($o['listas'][$k]), "o numero de '$k' e o tamanho da lista",
        'viu ' . $o[$k] . ' vs ' . count($o['listas'][$k]));
}
// O quarto vaga quando o ULTIMO hospede dele sai.
$q101 = null;
foreach ($o['listas']['quartos'] as $q) { if ($q['quarto'] === '101') { $q101 = $q; } }
$ok($q101 !== null && $q101['hospedes'] === 2, 'o quarto sabe quantos hospedes tem',
    'viu ' . ($q101 === null ? 'nenhum' : $q101['hospedes']));
$ok(array_column($o['listas']['quartos'], 'quarto') === ['101', '203', '204'],
    'quartos em ordem, sem repetir', implode(',', array_column($o['listas']['quartos'], 'quarto')));
$ok(array_column($o['listas']['saem_hoje'], 'quarto') === ['203'], 'a lista de saem hoje e o 203');
$ok(array_column($o['listas']['saem_amanha'], 'quarto') === ['101', '101'], 'os dois do 101 saem amanha');

// ---------------------------------------------------------------
// Seletor "Hospede ja cadastrado": uma linha por PESSOA.
// (hospedes_unicos entra no mesmo eval do bloco acima.)
echo "\nlista do 'hospede ja cadastrado'\n";
$u = hospedes_unicos([
    $hosp(['nome' => 'Bruno', 'telefone' => '48911111111', 'quarto' => '10', 'entrada_em' => '2026-08-01']),
    $hosp(['nome' => 'Ana',   'telefone' => '48922222222', 'quarto' => '11', 'entrada_em' => '2026-07-01']),
    // Mesma pessoa noutro roteador da conta, e com a estadia mais nova.
    $hosp(['nome' => 'Bruno', 'telefone' => '48911111111', 'quarto' => '20', 'entrada_em' => '2026-08-10']),
    $hosp(['nome' => '',      'telefone' => '',            'quarto' => '99', 'entrada_em' => '2026-08-11']),
]);
$ok(count($u) === 2, 'mesmo numero em dois roteadores vira UMA pessoa', 'viu ' . count($u));
$ok($u[0]['nome'] === 'Ana' && $u[1]['nome'] === 'Bruno', 'ordenado por nome',
    'viu ' . implode(',', array_column($u, 'nome')));
// Vence a entrada mais recente: e dela que sai a data mostrada no item.
$ok($u[1]['entrada_em'] === '2026-08-10', 'fica o cadastro de entrada mais recente', 'viu ' . $u[1]['entrada_em']);
$ok(!in_array('99', array_column($u, 'quarto'), true), 'cadastro sem telefone fica de fora');
$ok(hospedes_unicos([]) === [], 'lista vazia nao quebra');

// ---------------------------------------------------------------
// Coluna "Banda" da tabela de hospedes.
//
// A banda mora nos LEADS do telefone, nao no hospede: a celula so pode abrir
// para edicao quando a linha carrega os ids desses leads. O perigo e o
// data-id da linha ser o do HOSPEDE — mandar esse numero para set_banda.php
// gravaria limite no lead de outra pessoa.
function h(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function fmt_bytes($b): string { return $b ? round($b / 1048576, 1) . ' MB' : '-'; }

$hResumo = ['hospedados' => 1, 'saem_hoje' => 0, 'total' => 3];
$rotAtivo = 'POUSADA'; $csrf = 'x'; $hspCliente = 0;
$linha = function (array $a): array {
    return array_merge(['id' => 0, 'roteador' => 'POUSADA', 'nome' => 'x', 'quarto' => '1',
                        'telefone' => '48999999999', 'entrada_em' => '2026-08-14',
                        'dias' => 1, 'saida_em' => '2026-08-15 12:00:00', 'hospedado' => 1,
                        'bytes' => 0, 'online' => 0, 'lead_ids' => '', 'banda' => null], $a);
};
$hospedes = [
    $linha(['id' => 77, 'nome' => 'No wifi agora',  'lead_ids' => '901,902', 'banda' => 10, 'online' => 1]),
    $linha(['id' => 78, 'nome' => 'Ja conectou',    'lead_ids' => '903']),
    $linha(['id' => 79, 'nome' => 'Nunca conectou']),
];
ob_start();
require __DIR__ . '/../inc/hospedes_tela.php';
$html = (string) ob_get_clean();
preg_match_all('/<tr data-id="(\d+)"\s+data-banda="([^"]*)"/', $html, $m, PREG_SET_ORDER);

echo "\ncoluna Status\n";
// Verde/vermelho/traco. O traco NAO e "offline": e quem ainda nao apareceu.
// Confundir os dois faz a recepcao ligar para um hospede que nunca chegou.
$ok(substr_count($html, 'pc-dot on') === 1, 'so o conectado fica verde',
    'viu ' . substr_count($html, 'pc-dot on'));
$ok(substr_count($html, 'pc-dot off') === 1, 'quem ja conectou e esta fora fica vermelho',
    'viu ' . substr_count($html, 'pc-dot off'));
$ok(substr_count($html, 'hsp-nunca') === 1, 'quem nunca conectou nao ganha bolinha',
    'viu ' . substr_count($html, 'hsp-nunca'));
// As colunas "Wi-Fi" e "Situacao" sairam: Status ocupa o lugar das duas.
$ok(strpos($html, '<th>Status</th>') !== false, 'Status esta no cabecalho');
$ok(strpos($html, '<th>Wi-Fi</th>') === false && strpos($html, '<th>Situação</th>') === false,
    'Wi-Fi e Situacao sairam');
$ok(strpos($html, '<th>Status</th>') < strpos($html, '<th>Hóspede</th>'), 'Status e a primeira coluna');
$ok(substr_count($html, '<th>') === 8, 'oito colunas no cabecalho', 'viu ' . substr_count($html, '<th>'));

echo "\ncoluna da banda\n";
$ok(count($m) === 3, 'as tres linhas saem com data-banda', 'viu ' . count($m));
// O que mudou: o teto agora e do CADASTRO, entao a celula abre mesmo para quem
// nunca conectou — e e assim que a recepcao deixa o limite pronto no check-in.
$ok(substr_count($html, 'class="pc-banda"') === 3, 'todas as linhas abrem para edicao',
    'viu ' . substr_count($html, 'class="pc-banda"'));
$ok(strpos($html, '>10 Mbps<') !== false, 'mostra a banda gravada');
$ok(substr_count($html, '>sem limite<') === 2, 'sem banda gravada mostra "sem limite"',
    'viu ' . substr_count($html, '>sem limite<'));
// O id que vai para api/hospede_banda.php e o do HOSPEDE, e nao pode sair
// junto um data-ids de lead: o endpoint de hospede nem olha para ele.
$ok(($m[0][1] ?? '') === '77', 'a linha leva o id do hospede', $m[0][1] ?? '');
$ok(strpos($html, 'data-ids=') === false, 'linha de hospede nao carrega id de lead');
$ok(strpos($html, 'api/hospede_banda.php') !== false, 'a tabela aponta para o endpoint de hospede');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
