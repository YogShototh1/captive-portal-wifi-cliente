<?php
// Aba "Ocupação" das contas de HOSPEDAGEM: o dia da pousada em números, e a
// lista por trás de cada número.
//
// Funciona como os cartões de resumo do painel de leads: clicar num cartão
// troca o que aparece embaixo. Aqui a troca é na própria página (nada de ?f= e
// reload) porque as cinco listas saem da MESMA lista que a aba Painel já
// carregou ($hospedes) — nenhuma consulta a mais, nenhum endpoint novo.
//
// Compartilhada entre painel.php e admin_leads.php, como as outras telas de
// inc/. Espera $hospedes e $nowTs no escopo.
$ocp = hospedes_ocupacao($hospedes);

// Quem está no Wi-Fi com a estadia vencida: marcado na lista do Wi-Fi.
$ocpVencidos = [];
foreach ($ocp['vencidos'] as $v) {
    $ocpVencidos[(int) $v['id']] = true;
}

$ocpCartoes = [
    'quartos'     => ['n' => $ocp['quartos'],     'rot' => 'quartos ocupados',
                      'tit' => 'Quartos ocupados',
                      'desc' => 'Quem está com quarto agora e quando ele vaga. Quarto com mais de um hóspede conta uma vez — vaga quando o último sai.',
                      'vazio' => 'Nenhum quarto ocupado agora.'],
    'entram_hoje' => ['n' => $ocp['entram_hoje'], 'rot' => 'check-ins de hoje',
                      'tit' => 'Check-ins de hoje',
                      'desc' => 'Cadastros com entrada na data de hoje.',
                      'vazio' => 'Nenhum check-in hoje.'],
    'saem_hoje'   => ['n' => $ocp['saem_hoje'],   'rot' => 'saem hoje',
                      'tit' => 'Saem hoje',
                      'desc' => 'O Wi-Fi destes hóspedes para no horário de saída de hoje.',
                      'vazio' => 'Ninguém sai hoje.'],
    'saem_amanha' => ['n' => $ocp['saem_amanha'], 'rot' => 'saem amanhã',
                      'tit' => 'Saem amanhã',
                      'desc' => 'Para adiantar a arrumação e as renovações de diária.',
                      'vazio' => 'Ninguém sai amanhã.'],
    'conectados'  => ['n' => $ocp['conectados'],  'rot' => 'no Wi-Fi agora',
                      'tit' => 'No Wi-Fi agora',
                      'desc' => 'Quem está conectado neste momento, o tempo desde que entrou no Wi-Fi e o consumo desta estadia (estadias anteriores não entram).',
                      'vazio' => 'Ninguém conectado agora.'],
];
?>
<section class="pc-tela" data-tela="ocupacao">
    <div class="pc-summary ocp-cards" id="ocp-cards">
        <?php foreach ($ocpCartoes as $chave => $c): ?>
        <button type="button" class="glow-card pc-metric ocp-card<?= $chave === 'quartos' ? ' atual' : '' ?>"
                data-ocp="<?= h($chave) ?>" aria-pressed="<?= $chave === 'quartos' ? 'true' : 'false' ?>">
            <span class="glow-fx" aria-hidden="true"></span>
            <span class="glow-body">
                <span class="pc-metric-num"><?= (int) $c['n'] ?></span>
                <span class="pc-metric-label"><?= h($c['rot']) ?></span>
            </span>
        </button>
        <?php endforeach; ?>
    </div>

    <div class="glow-card pc-dst-card">
        <span class="glow-fx" aria-hidden="true"></span>
        <div class="glow-body">
            <div class="pc-dst">
                <?php foreach ($ocpCartoes as $chave => $c): $linhas = $ocp['listas'][$chave]; ?>
                <div class="ocp-painel" data-ocp-painel="<?= h($chave) ?>"<?= $chave === 'quartos' ? '' : ' hidden' ?>>
                    <h2 class="pc-anuncio-title"><?= h($c['tit']) ?></h2>
                    <p class="pc-anuncio-desc"><?= h($c['desc']) ?></p>
                    <?php if (!$linhas): ?>
                        <p class="pc-vazio ocp-vazio"><?= h($c['vazio']) ?></p>
                    <?php elseif ($chave === 'quartos'): ?>
                    <div class="pc-table-wrap">
                        <table>
                            <thead><tr><th>Quarto</th><th>Hóspedes</th><th>Desocupa em</th></tr></thead>
                            <tbody>
                                <?php foreach ($linhas as $q): ?>
                                <tr>
                                    <td class="hsp-quarto"><?= h($q['quarto']) ?></td>
                                    <td><?= (int) $q['hospedes'] ?></td>
                                    <td>
                                        <div class="pc-dh">
                                            <span class="pc-data"><?= h(date('d/m/Y', strtotime($q['saida_em']))) ?></span>
                                            <span class="pc-hora"><?= h(substr($q['saida_em'], 11, 5)) ?></span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php elseif ($chave === 'conectados'): ?>
                    <div class="pc-table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Hóspede</th><th>Quarto</th><th>Número</th>
                                    <th>Conectado há</th><th>Consumo da estadia</th><th>Conexões</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($linhas as $g):
                                    // Mesmo relógio da tabela de leads: agora do BANCO menos a
                                    // hora em que o primeiro aparelho entrou no Wi-Fi.
                                    $desde = strtotime((string) $g['online_desde']);
                                    $venc  = isset($ocpVencidos[(int) $g['id']]);
                                ?>
                                <tr>
                                    <td>
                                        <span class="pc-lead-nome"><?= h($g['nome']) ?></span>
                                        <?php if ($venc): ?><span class="ocp-tag-venc" title="Já passou da hora de saída e continua conectado. O roteador derruba na próxima rodada.">estadia vencida</span><?php endif; ?>
                                    </td>
                                    <td class="hsp-quarto"><?= h($g['quarto']) ?></td>
                                    <td><?= h($g['telefone']) ?></td>
                                    <td><span class="pc-dot on"></span><span class="pc-tempo"><?= h(fmt_tempo(max(0, $nowTs - $desde))) ?></span></td>
                                    <td class="hsp-consumo"><?= h(fmt_bytes($g['bytes'] ?? 0)) ?></td>
                                    <td>
                                        <?php if (!empty($g['lead_ids'])): ?>
                                        <button type="button" class="pc-ver-conexoes" data-lead="<?= h($g['lead_ids']) ?>" aria-label="Ver conexões de <?= h($g['nome']) ?>">
                                            <svg class="pc-conex-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
                                            <span>ver</span>
                                        </button>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: /* check-ins de hoje, saem hoje, saem amanhã: mesma tabela */ ?>
                    <div class="pc-table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Hóspede</th><th>Quarto</th><th>Número</th>
                                    <th><?= $chave === 'entram_hoje' ? 'Entrou em' : 'Sai em' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($linhas as $g):
                                    $dt = $chave === 'entram_hoje' ? (string) $g['entrada_em'] : (string) $g['saida_em'];
                                ?>
                                <tr>
                                    <td><span class="pc-lead-nome"><?= h($g['nome']) ?></span></td>
                                    <td class="hsp-quarto"><?= h($g['quarto']) ?></td>
                                    <td><?= h($g['telefone']) ?></td>
                                    <td>
                                        <div class="pc-dh">
                                            <span class="pc-data"><?= h(date('d/m/Y', strtotime($dt))) ?></span>
                                            <?php if ($chave !== 'entram_hoje'): ?><span class="pc-hora"><?= h(substr($dt, 11, 5)) ?></span><?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
