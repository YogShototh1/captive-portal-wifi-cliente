<?php
// Teste das contas dos relatorios que estavam erradas.
// Rodar:  php tools/teste_relatorio.php
require_once __DIR__ . '/../inc/util.php';

$falhas = 0;
$ok = function ($cond, $nome, $viu = '') use (&$falhas) {
    if ($cond) { echo "  ok   $nome\n"; }
    else { $falhas++; echo "  FALHOU  $nome  $viu\n"; }
};
$t = function ($s) { return strtotime($s); };
$H = 3600;

// ---------------------------------------------------------------
echo "marco_mes (aniversario nao escorrega de mes)\n";
// O bug: strtotime("2025-08-31 +3 month") = 2025-12-01, porque novembro nao tem
// dia 31 e o PHP transborda.
$ok(marco_mes('2025-08-31', 3)  === '2025-11-30', '31/08 +3m = 30/11 (nov tem 30)', marco_mes('2025-08-31', 3));
$ok(marco_mes('2025-08-31', 6)  === '2026-02-28', '31/08 +6m = 28/02 (fev tem 28)', marco_mes('2025-08-31', 6));
$ok(marco_mes('2025-11-30', 3)  === '2026-02-28', '30/11 +3m = 28/02', marco_mes('2025-11-30', 3));
$ok(marco_mes('2025-12-31', 6)  === '2026-06-30', '31/12 +6m = 30/06', marco_mes('2025-12-31', 6));
$ok(marco_mes('2024-11-30', 3)  === '2025-02-28', '30/11 +3m em ano normal', marco_mes('2024-11-30', 3));
$ok(marco_mes('2023-11-30', 3)  === '2024-02-29', '30/11 +3m cai em fev bissexto = 29', marco_mes('2023-11-30', 3));
// Os que sempre funcionaram continuam iguais.
$ok(marco_mes('2026-01-15', 3)  === '2026-04-15', 'dia que existe no destino nao muda');
$ok(marco_mes('2026-03-31', 12) === '2027-03-31', '+12m mantem o mesmo dia');
$ok(marco_mes('2026-10-20', 3)  === '2027-01-20', 'virada de ano');

// ---------------------------------------------------------------
echo "\nocorrencias_dow (viés do periodo desigual)\n";
// 2026-08-02 e domingo.
$o = ocorrencias_dow('2026-08-02', '2026-08-08');            // 7 dias
$ok(array_sum($o) === 7 && count(array_unique($o)) === 1, 'periodo de 7 dias: 1 de cada', json_encode($o));
$o = ocorrencias_dow('2026-08-02', '2026-08-09');            // 8 dias: 2 domingos
$ok($o[1] === 2 && $o[2] === 1, 'periodo de 8 dias: 2 domingos, 1 segunda', json_encode($o));
$ok(array_sum($o) === 8, 'soma das ocorrencias = dias do periodo');
$o = ocorrencias_dow('2026-08-05', '2026-08-05');            // 1 dia (quarta)
$ok(array_sum($o) === 1 && $o[4] === 1, 'um dia so conta uma quarta', json_encode($o));
$o = ocorrencias_dow('2026-08-02', '2026-08-29');            // 28 dias
$ok(count(array_unique($o)) === 1 && $o[1] === 4, '28 dias: 4 de cada', json_encode($o));

// ---------------------------------------------------------------
echo "\nintervalos_total (tempo por cliente no periodo)\n";
$pIni = $t('2026-08-03 00:00:00');                            // segunda
$pFim = $t('2026-08-04 00:00:00');                            // terca 00:00 (exclusivo)

// O bug antigo: a sessao que comeca 23h do ultimo dia entrava INTEIRA.
$s = [[$t('2026-08-03 23:00'), $t('2026-08-04 04:00')]];      // 5h, so 1h dentro
$ok(intervalos_total($s, $pIni, $pFim) === 1 * $H, 'sessao que atravessa a borda entra so o pedaco de dentro',
    intervalos_total($s, $pIni, $pFim) / $H . 'h');

// Sessao que comecou ANTES do periodo tambem conta o pedaco de dentro (o SQL
// antigo filtrava por conectado_em e perdia essa inteira).
$s = [[$t('2026-08-02 22:00'), $t('2026-08-03 03:00')]];
$ok(intervalos_total($s, $pIni, $pFim) === 3 * $H, 'sessao que comecou antes conta o pedaco de dentro',
    intervalos_total($s, $pIni, $pFim) / $H . 'h');

// Dois aparelhos ao mesmo tempo = um periodo conectado, nao o dobro.
$s = [[$t('2026-08-03 10:00'), $t('2026-08-03 14:00')],
      [$t('2026-08-03 12:00'), $t('2026-08-03 16:00')]];
$ok(intervalos_total($s, $pIni, $pFim) === 6 * $H, 'sobreposicao 10-14 + 12-16 = 6h (nao 8h)',
    intervalos_total($s, $pIni, $pFim) / $H . 'h');

// Sessoes separadas somam normal.
$s = [[$t('2026-08-03 08:00'), $t('2026-08-03 09:00')],
      [$t('2026-08-03 20:00'), $t('2026-08-03 22:00')]];
$ok(intervalos_total($s, $pIni, $pFim) === 3 * $H, 'sessoes separadas somam');

// Nada dentro do periodo = zero.
$s = [[$t('2026-08-01 08:00'), $t('2026-08-01 09:00')]];
$ok(intervalos_total($s, $pIni, $pFim) === 0, 'sessao fora do periodo nao conta');
$ok(intervalos_total([], $pIni, $pFim) === 0, 'sem sessao nenhuma = 0');

// Sessao maior que o periodo inteiro fica limitada ao periodo.
$s = [[$t('2026-08-01 00:00'), $t('2026-08-10 00:00')]];
$ok(intervalos_total($s, $pIni, $pFim) === 24 * $H, 'sessao gigante nao passa do tamanho do periodo',
    intervalos_total($s, $pIni, $pFim) / $H . 'h');

// ---------------------------------------------------------------
// conexao_intervalo devolve null quando a duracao e desconhecida — sem isso o
// relatorio de tempo voltaria a inventar sessoes eternas.
echo "\nconexao_intervalo (sessao que o polling nunca viu)\n";
$agora = $t('2026-08-05 12:00');
$ok(conexao_intervalo('2026-08-05 08:00:00', null, $agora) === null, 'fim nulo = duracao desconhecida');
$ok(conexao_intervalo('2026-08-05 08:00:00', '', $agora) === null, 'fim vazio = duracao desconhecida');
$iv = conexao_intervalo('2026-08-05 08:00:00', '2026-08-05 10:00:00', $agora);
$ok($iv !== null && $iv[1] - $iv[0] === 2 * $H, 'sessao fechada devolve a duracao');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
