<?php
// Aba "Ocupação" das contas de HOSPEDAGEM: o dia da pousada em números.
//
// Sai da mesma lista que a aba Painel já carregou ($hospedes) — nenhuma consulta
// a mais. Compartilhada entre painel.php e admin_leads.php, como as outras telas
// de inc/.
$ocp = hospedes_ocupacao($hospedes);
?>
<section class="pc-tela" data-tela="ocupacao">
    <div class="pc-summary">
        <div class="glow-card pc-metric">
            <span class="glow-fx" aria-hidden="true"></span>
            <div class="glow-body">
                <span class="pc-metric-num"><?= (int) $ocp['quartos'] ?></span>
                <span class="pc-metric-label">quartos ocupados</span>
            </div>
        </div>
        <div class="glow-card pc-metric">
            <span class="glow-fx" aria-hidden="true"></span>
            <div class="glow-body">
                <span class="pc-metric-num"><?= (int) $ocp['entram_hoje'] ?></span>
                <span class="pc-metric-label">check-ins de hoje</span>
            </div>
        </div>
        <div class="glow-card pc-metric">
            <span class="glow-fx" aria-hidden="true"></span>
            <div class="glow-body">
                <span class="pc-metric-num"><?= (int) $ocp['saem_hoje'] ?></span>
                <span class="pc-metric-label">saem hoje</span>
            </div>
        </div>
        <div class="glow-card pc-metric">
            <span class="glow-fx" aria-hidden="true"></span>
            <div class="glow-body">
                <span class="pc-metric-num"><?= (int) $ocp['saem_amanha'] ?></span>
                <span class="pc-metric-label">saem amanhã</span>
            </div>
        </div>
        <div class="glow-card pc-metric">
            <span class="glow-fx" aria-hidden="true"></span>
            <div class="glow-body">
                <span class="pc-metric-num"><?= (int) $ocp['conectados'] ?></span>
                <span class="pc-metric-label">no Wi-Fi agora</span>
            </div>
        </div>
    </div>

    <div class="glow-card pc-dst-card">
        <span class="glow-fx" aria-hidden="true"></span>
        <div class="glow-body">
            <div class="pc-dst">
                <h2 class="pc-anuncio-title">Estadia vencida, ainda no Wi-Fi</h2>
                <p class="pc-anuncio-desc">Quem já passou da hora de saída e continua conectado. O roteador derruba sozinho na rodada seguinte (até 1 min) — esta lista existe para quando o mesmo nome aparece todo dia, que aí não é atraso, é cadastro errado.</p>
                <?php if (!$ocp['vencidos']): ?>
                    <p class="pc-vazio ocp-vazio">Ninguém. Todo mundo no Wi-Fi está dentro da diária.</p>
                <?php else: ?>
                <div class="pc-table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Hóspede</th>
                                <th>Quarto</th>
                                <th>Número</th>
                                <th>Saiu em</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ocp['vencidos'] as $v): ?>
                            <tr>
                                <td><span class="pc-lead-nome"><?= h($v['nome']) ?></span></td>
                                <td class="hsp-quarto"><?= h($v['quarto']) ?></td>
                                <td><?= h($v['telefone']) ?></td>
                                <td>
                                    <div class="pc-dh">
                                        <span class="pc-data"><?= h(date('d/m/Y', strtotime((string) $v['saida_em']))) ?></span>
                                        <span class="pc-hora"><?= h(substr((string) $v['saida_em'], 11, 5)) ?></span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
