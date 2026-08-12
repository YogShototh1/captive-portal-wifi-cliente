<?php
// Os dados inventados do painel de demonstração (demo.php).
//
// UMA fonte de verdade: cada lead ganha um histórico de visitas, e TODA aba sai
// dele — os cartões, a recorrência da semana, as estatísticas, os relatórios e a
// tela de informações. É o que faz os números baterem entre si quando alguém
// confere: se a tela diz que o cliente veio 6 dias, o relatório de dias por
// cliente diz 6, e o calendário da aba Informações marca os mesmos 6 dias.
//
// Nada aqui toca no banco. Tudo é derivado de time().

// Gera os leads e o histórico. $agora vem de fora só para a página inteira
// enxergar o mesmo instante.
function demo_leads(int $agora): array
{
    // [nome, telefone, aparelho, minutos desde que conectou, minutos de sessão
    //  (null = ainda online), MB, banda, tempo limite]
    $base = [
        ['Marina Duarte',   '48991820477', 'iPhone 13',            8,    null, 210,  null, null],
        ['',                '48988431290', 'Samsung Galaxy S23',   17,   null, 486,  null, 60],
        ['Rafael Bittencourt', '48996077315', 'Xiaomi Redmi Note 12', 24, null, 1180, 10,   null],
        ['',                '48984552108', 'iPhone 11',            41,   null, 92,   null, null],
        ['Ana Beatriz',     '48991336742', 'Motorola Moto G84',    56,   null, 2310, null, 120],
        ['',                '48997260183', 'iPhone 15 Pro',        95,   62,   1540, null, null],
        ['Carlos Menezes',  '48988014926', 'Samsung Galaxy A54',   134,  47,   730,  5,    null],
        ['',                '48992745630', 'Android',              181,  28,   315,  null, 60],
        ['Juliana Prado',   '48996518874', 'iPhone 12',            240,  118,  4120, null, null],
        ['',                '48985109362', 'Xiaomi Poco X5',       302,  15,   96,   null, null],
        ['Eduardo Lima',    '48991447028', 'iPhone 14',            366,  74,   1980, null, 90],
        ['',                '48997832451', 'Samsung Galaxy M34',   428,  9,    42,   null, null],
        ['Patrícia Souza',  '48988670135', 'Motorola Edge 40',     512,  156,  6240, null, null],
        // Daqui para baixo são os SUMIDOS: última visita de 12 a 68 dias atrás.
        // Sem eles, "clientes sem retorno" e "vieram uma vez e não voltaram"
        // ficavam zerados e a aba Alertas mostrava um aviso só.
        ['',                '48993204587', 'iPhone SE',            17280, 33,  204,  null, null],
        ['Bruno Carvalho',  '48996741320', 'Samsung Galaxy S21',   30240, 91,  2870, 20,   null],
        ['',                '48984023918', 'Android',              48960, 6,   18,   null, null],
        ['Letícia Amorim',  '48991658274', 'iPhone 13 mini',       74880, 143, 5310, null, null],
        ['',                '48997410265', 'Xiaomi Redmi 13C',     97920, 21,  127,  null, null],
    ];

    $leads = [];
    foreach ($base as $i => [$nome, $tel, $apar, $atras, $durMin, $mb, $banda, $lim]) {
        $conTs  = $agora - $atras * 60;
        $online = $durMin === null ? 1 : 0;

        // --- Histórico de visitas ------------------------------------------
        // Perfis diferentes de propósito: uns são fiéis (voltam a cada 3 dias),
        // outros vieram uma vez só. Sem essa variedade, "clientes sumidos" e
        // "clientes mais frequentes" sairiam com a mesma lista.
        $passo   = 3 + ($i % 5);                 // dias entre uma visita e outra
        // Os dois "1" no fim são de propósito: cliente que veio uma vez e nunca
        // mais, que é o público do aviso "vieram uma vez e não voltaram".
        $quantas = [1, 9, 6, 2, 12, 4, 7, 1, 11, 3, 8, 1, 14, 2, 5, 1, 10, 1][$i];
        $visitas = [];
        for ($k = 0; $k < $quantas; $k++) {
            $ts = $conTs - $k * $passo * 86400;
            if ($ts < $agora - 90 * 86400) { break; }   // janela de 90 dias
            // A hora vem do próprio índice: cada cliente tem o seu horário de
            // costume, que é o que a aba Informações mostra como "horário
            // preferido". Só a visita mais recente usa a hora real da tabela.
            $h = $k === 0 ? (int) date('G', $ts) : (9 + ($i * 3 + $k) % 13);
            $ini = strtotime(date('Y-m-d', $ts) . ' ' . sprintf('%02d:%02d:00', $h, ($i * 7 + $k * 11) % 60));
            // Sessão: a mais recente é a da tabela; as antigas variam de 8 min
            // a ~2 h, e o consumo acompanha (≈ 0,6 MB por minuto).
            $seg = ($k === 0 && $online) ? max(60, $agora - $conTs)
                 : ($k === 0 && !$online ? $durMin * 60 : (8 + ($i * 13 + $k * 29) % 112) * 60);
            $visitas[] = [
                'ts'      => $ini,
                'data'    => date('Y-m-d', $ini),
                'hora'    => (int) date('G', $ini),
                'seg'     => $seg,
                'bytes'   => (int) round($seg / 60 * 0.6 * 1048576),
                'aberta'  => ($k === 0 && $online),
            ];
        }
        // Mais antiga primeiro é o que as contas de recorrência esperam ler.
        usort($visitas, function ($a, $b) { return $a['ts'] <=> $b['ts']; });

        $leads[] = [
            'id'                 => 1000 + $i,
            'telefone'           => $tel,
            'nome'               => $nome !== '' ? $nome : null,
            'ip'                 => '10.5.50.' . (12 + $i),
            'dispositivo'        => $apar,
            'conectado_em'       => date('Y-m-d H:i:s', $conTs),
            // Online = visto AGORA. O lead_estado congela a sessão quando o
            // último sinal do roteador passa de MIKROTIK_TIMEOUT_SEG (15 s).
            'visto_em'           => date('Y-m-d H:i:s', $online ? $agora : $conTs + (int) $durMin * 60),
            'online'             => $online,
            'segundos_conectado' => $online ? null : $durMin * 60,
            'tempo_limite_min'   => $lim,
            'banda_limite'       => $banda,
            'total_conexoes'     => count($visitas),
            'bytes_total'        => $mb * 1048576,
            'primeira'           => $visitas[0]['data'],
            'visitas'            => $visitas,
        ];
    }
    return $leads;
}

// Cartões do topo. Contados da MESMA lista da tabela — nada de número solto que
// não bate com o que está logo abaixo dele.
function demo_resumo(array $leads, int $agora): array
{
    $hoje = strtotime('today', $agora);
    $r = ['online' => 0, 'hoje' => 0, 'cadastrados' => 0, 'total' => count($leads)];
    foreach ($leads as $l) {
        if ((int) $l['online'] === 1) { $r['online']++; }
        if (strtotime($l['conectado_em']) >= $hoje) {
            $r['hoje']++;
            // "Cadastrado hoje" é quem apareceu pela PRIMEIRA vez hoje. Deixar
            // igual a "conectados hoje" entregaria que o número é decorativo.
            if ($l['primeira'] === date('Y-m-d', $agora)) { $r['cadastrados']++; }
        }
    }
    return $r;
}

// --- Recorrência (aba Dashboard) -----------------------------------------
// Mesmos campos do api/dashboard_geral.php, para o assets/dashgeral.js não
// saber a diferença. Um período por vez: dia, semana e mês.
function demo_periodo(array $leads, int $ini, int $iniAnt, int $fimAnt): array
{
    $atual = 0; $anteriores = 0; $rev = 0; $novos = 0; $reativados = 0;
    foreach ($leads as $l) {
        $noAtual = false; $noAnt = false; $antesDoAtual = false;
        foreach ($l['visitas'] as $v) {
            if ($v['ts'] >= $ini)                                { $noAtual = true; }
            if ($v['ts'] >= $iniAnt && $v['ts'] < $fimAnt)        { $noAnt = true; }
            if ($v['ts'] < $ini)                                  { $antesDoAtual = true; }
        }
        if ($noAtual)  { $atual++; }
        if ($noAnt)    { $anteriores++; }
        if ($noAtual && $noAnt)        { $rev++; }
        if ($noAtual && !$antesDoAtual) { $novos++; }
        // Reativado: voltou agora depois de sumir o período anterior inteiro.
        if ($noAtual && !$noAnt && $antesDoAtual) { $reativados++; }
    }
    return [
        'total'           => $atual + $anteriores,
        'revisitaram'     => $rev,
        'reativados'      => $reativados,
        'nao_revisitaram' => max(0, $anteriores - $rev),
        'novos'           => $novos,
        'clientes'        => $atual,
        'clientes_ant'    => $anteriores,
        'pct'             => $anteriores > 0
            ? round(($atual - $anteriores) * 100 / $anteriores, 1)
            : ($atual > 0 ? 100.0 : 0.0),
    ];
}

// --- Estatísticas: uma série por filtro da barra --------------------------
//
// Os quatro filtros da tela (hoje / semana / mês / ano) mudam o passo do eixo,
// e o assets/estatisticas.js escreve na legenda o que cada ponto significa.
// Devolver sempre a mesma série faria a legenda dizer "cada ponto é uma hora"
// embaixo de um gráfico de 30 dias — foi o que aconteceu na primeira versão.
function demo_estatisticas(array $leads, int $agora): array
{
    // [quantos pontos, segundos por ponto, formato da chave, formato do rótulo]
    // "mes" tem TRÊS pontos por dia (manhã/tarde/noite) porque é isso que a
    // legenda do estatisticas.js afirma; com um ponto por dia ela mentia.
    // A chave é uma função: "mes" precisa de três faixas por dia
    // (manhã/tarde/noite), que nenhum formato de date() escreve sozinho.
    $terco = function (int $ts): string {
        $h = (int) date('G', $ts);
        return date('Y-m-d', $ts) . '-' . ($h < 12 ? 'm' : ($h < 18 ? 't' : 'n'));
    };
    // Cada modo devolve a LISTA de instantes de referência, um por ponto. Antes
    // isto era um passo fixo em segundos, e o "mes" quebrava: andando de 8 em 8
    // horas a partir de agora, as três faixas do dia não saíam uma de cada —
    // uma repetia e outra nunca aparecia, e "novos clientes" dava sempre 0.
    $modos = [
        'hoje'   => [function ($t) { return date('Y-m-d H', $t); }, 'H\h', function () use ($agora) {
            $o = []; for ($i = 23; $i >= 0; $i--) { $o[] = $agora - $i * 3600; } return $o;
        }],
        'semana' => [function ($t) { return date('Y-m-d', $t); }, 'd/m', function () use ($agora) {
            $o = []; for ($i = 6; $i >= 0; $i--) { $o[] = $agora - $i * 86400; } return $o;
        }],
        'mes'    => [$terco, 'd/m', function () use ($agora) {
            $o = [];
            for ($i = 29; $i >= 0; $i--) {
                $d = date('Y-m-d', $agora - $i * 86400);
                foreach (['08:00:00', '14:00:00', '20:00:00'] as $h) { $o[] = strtotime("$d $h"); }
            }
            return $o;
        }],
        'ano'    => [function ($t) { return date('Y-m', $t); }, 'm/Y', function () use ($agora) {
            $o = []; for ($i = 11; $i >= 0; $i--) { $o[] = strtotime("-$i month", $agora); } return $o;
        }],
    ];
    $out = [];
    foreach ($modos as $filtro => [$chave, $fmtR, $pontos]) {
        $labels = []; $eixo = []; $con = []; $nov = [];
        $lista = $pontos();
        $n = count($lista);
        foreach ($lista as $idx => $ts) {
            $k = $chave($ts);
            $labels[] = $k;
            // Só alguns pontos ganham rótulo: com 90 datas seguidas o eixo vira
            // uma parede ilegível.
            $eixo[] = ($n <= 12 || $idx % (int) max(1, round($n / 8)) === 0) ? date($fmtR, $ts) : '';
            $c = 0; $x = 0;
            foreach ($leads as $l) {
                foreach ($l['visitas'] as $v) {
                    if ($chave($v['ts']) === $k) { $c++; }
                }
                if ($chave(strtotime($l['primeira'] . ' 12:00:00')) === $k) { $x++; }
            }
            $con[] = $c;
            $nov[] = $x;
        }
        $out[$filtro] = [
            'ok' => true, 'filtro' => $filtro,
            'labels' => $labels, 'eixo' => $eixo,
            'conectados' => $con, 'novos' => $nov,
            'total_conectados' => array_sum($con),
            'total_novos'      => array_sum($nov),
        ];
    }
    return $out;
}

// --- Avisos (aba Alertas) ------------------------------------------------
// Mesmo formato do api/alertas.php: id, título, texto com {n}, tom e se o
// número abre a lista. Os números saem das visitas, não são chutados.
function demo_avisos(array $leads, int $agora): array
{
    $cat  = alertas_catalogo();
    $sete = $agora - 7 * 86400;

    $sumidos = 0; $unicos = 0; $novosSem = 0; $sequencia = 0;
    foreach ($leads as $l) {
        $ultima = end($l['visitas'])['ts'];
        $qtd    = count($l['visitas']);
        if ($ultima < $sete)                      { $sumidos++; }
        if ($qtd === 1 && $ultima < $sete)        { $unicos++; }
        if (strtotime($l['primeira']) >= $sete)   { $novosSem++; }
        // Dias seguidos: passo de 1 dia entre visitas consecutivas.
        $seq = 1; $melhor = 1;
        for ($k = 1; $k < $qtd; $k++) {
            $dif = (int) round(($l['visitas'][$k]['ts'] - $l['visitas'][$k - 1]['ts']) / 86400);
            $seq = ($dif === 1) ? $seq + 1 : 1;
            if ($seq > $melhor) { $melhor = $seq; }
        }
        if ($melhor >= 5) { $sequencia++; }
    }

    $mapa = [
        'sem_vir_semana' => $sumidos,
        'visita_unica'   => $unicos,
        'novos_semana'   => $novosSem,
        'forte_recorrencia' => $sequencia,
    ];
    $avisos = [];
    foreach ($mapa as $id => $n) {
        if ($n <= 0 || !isset($cat[$id])) { continue; }
        [$titulo, $texto, $tom, $temLista] = $cat[$id];
        $avisos[] = [
            'id' => $id, 'titulo' => $titulo, 'tom' => $tom,
            'n' => $n, 'lista' => $temLista,
            // O {n} vira o botão que abre a lista; o texto do catálogo é a
            // explicação, então o aviso precisa de uma frase com o número.
            'texto' => '{n} cliente(s). ' . $texto,
        ];
    }
    return $avisos;
}

// Lista de clientes por trás do número de um aviso.
function demo_alerta_lista(array $leads, string $id, int $agora): array
{
    $sete = $agora - 7 * 86400;
    $out  = [];
    foreach ($leads as $l) {
        $ultima = end($l['visitas'])['ts'];
        $qtd    = count($l['visitas']);
        $entra  = false;
        if ($id === 'sem_vir_semana')    { $entra = $ultima < $sete; }
        elseif ($id === 'visita_unica')  { $entra = $qtd === 1 && $ultima < $sete; }
        elseif ($id === 'novos_semana')  { $entra = strtotime($l['primeira']) >= $sete; }
        elseif ($id === 'forte_recorrencia') {
            $seq = 1; $melhor = 1;
            for ($k = 1; $k < $qtd; $k++) {
                $dif = (int) round(($l['visitas'][$k]['ts'] - $l['visitas'][$k - 1]['ts']) / 86400);
                $seq = ($dif === 1) ? $seq + 1 : 1;
                if ($seq > $melhor) { $melhor = $seq; }
            }
            $entra = $melhor >= 5;
        }
        if ($entra) {
            $out[] = ['telefone' => $l['telefone'], 'nome' => $l['nome'], 'dias' => $qtd,
                      'ultima' => date('Y-m-d', $ultima)];
        }
    }
    return $out;
}

// --- Informações de um cliente (mesmos campos do api/dashboard.php) -------
function demo_info(array $l, int $agora): array
{
    $datas = [];
    foreach ($l['visitas'] as $v) { $datas[$v['data']] = true; }
    $datas = array_keys($datas);
    rsort($datas);

    $NOMES = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
    $porDow = array_fill(0, 7, 0);
    foreach ($datas as $d) { $porDow[(int) date('w', strtotime($d))]++; }

    $faixas = array_fill(0, 24, 0);
    $tempo  = 0;
    foreach ($l['visitas'] as $v) { $faixas[$v['hora']]++; $tempo += $v['seg']; }

    $hoje = date('Y-m-d', $agora);
    $gap  = (int) round((strtotime($hoje) - strtotime($datas[0])) / 86400);
    if ($gap <= 0) {
        $seq = 1;
        for ($i = 1; $i < count($datas); $i++) {
            $dif = (int) round((strtotime($datas[$i - 1]) - strtotime($datas[$i])) / 86400);
            if ($dif === 1) { $seq++; } else { break; }
        }
        $rec = ['tipo' => 'seguidos', 'dias' => $seq];
    } else {
        $rec = ['tipo' => 'sem_vir', 'dias' => $gap];
    }

    return [
        'ok' => true,
        'telefone' => $l['telefone'],
        'nome'     => $l['nome'],
        'total_dias' => count($datas),
        'datas'      => array_values($datas),
        'tempo_total' => $tempo,
        'dia_semana'  => $datas ? $NOMES[array_search(max($porDow), $porDow, true)] : null,
        'visitas_por_dia' => $porDow,
        'faixas_hora'     => $faixas,
        'hora_top'        => array_sum($faixas) > 0 ? sprintf('%02d:00', array_search(max($faixas), $faixas, true)) : null,
        'ultima_visita'   => $datas[0] ?? null,
        'recorrencia'     => $rec,
    ];
}
