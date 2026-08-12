<?php
// Aba "Painel" das contas de HOSPEDAGEM: cartões + lista de hóspedes.
//
// Entra no lugar da tabela de leads (inc/hospedes_tela.php vs. o bloco de leads
// do painel.php). Usa as MESMAS classes da tabela de leads de propósito: quem
// abre os dois painéis tem de ver a mesma tela, e assim o CSS não duplica.
//
// Espera vindo de quem inclui: $hospedes, $hResumo, $rotLista, $rotAtivo, $csrf
// e $hspCliente (id da conta dona; 0 no painel do proprio comprador).
$hoje = date('Y-m-d');
?>
<div class="pc-summary">
    <div class="glow-card pc-metric">
        <span class="glow-fx" aria-hidden="true"></span>
        <div class="glow-body">
            <span class="pc-metric-num"><?= (int) $hResumo['hospedados'] ?></span>
            <span class="pc-metric-label">hospedados agora</span>
        </div>
    </div>
    <div class="glow-card pc-metric">
        <span class="glow-fx" aria-hidden="true"></span>
        <div class="glow-body">
            <span class="pc-metric-num"><?= (int) $hResumo['saem_hoje'] ?></span>
            <span class="pc-metric-label">saem hoje</span>
        </div>
    </div>
    <div class="glow-card pc-metric">
        <span class="glow-fx" aria-hidden="true"></span>
        <div class="glow-body">
            <span class="pc-metric-num"><?= (int) $hResumo['total'] ?></span>
            <span class="pc-metric-label">total cadastrados</span>
        </div>
    </div>
</div>

<div class="glow-card pc-table-card">
    <span class="glow-fx" aria-hidden="true"></span>
    <div class="glow-body">
        <div class="hsp-topo">
            <button type="button" class="pc-btn-primary hsp-novo" id="hsp-novo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                Cadastrar hóspede
            </button>
            <p class="hsp-dica">Clique com o botão direito num hóspede para editar ou apagar.</p>
        </div>
        <div class="pc-table-wrap" id="hospedes-live"
             data-salvar-endpoint="api/hospede_salvar.php"
             data-excluir-endpoint="api/hospede_excluir.php"
             data-roteador="<?= h((string) $rotAtivo) ?>"
             data-cliente="<?= (int) ($hspCliente ?? 0) ?>"
             data-csrf="<?= h($csrf) ?>">
            <table>
                <thead>
                    <tr>
                        <th>Hóspede</th>
                        <th>Quarto</th>
                        <th>Número</th>
                        <th>Diárias</th>
                        <th>Saída</th>
                        <th>Situação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$hospedes): ?>
                        <tr class="pc-empty-row"><td colspan="6" class="pc-vazio">Nenhum hóspede cadastrado. Clique em “Cadastrar hóspede” para liberar o Wi-Fi de quem fez check-in.</td></tr>
                    <?php else: foreach ($hospedes as $g):
                        $ativo  = (int) $g['hospedado'] === 1;
                        $saiHj  = $ativo && substr((string) $g['saida_em'], 0, 10) === $hoje;
                        $dhS    = explode(' ', (string) $g['saida_em']);
                        $dataS  = date('d/m/Y', strtotime((string) $g['saida_em']));
                        $horaS  = substr($dhS[1] ?? '', 0, 5);
                    ?>
                    <tr data-id="<?= (int) $g['id'] ?>"
                        data-nome="<?= h($g['nome']) ?>"
                        data-quarto="<?= h($g['quarto']) ?>"
                        data-tel="<?= h($g['telefone']) ?>"
                        data-entrada="<?= h($g['entrada_em']) ?>"
                        data-dias="<?= (int) $g['dias'] ?>"
                        data-hora="<?= h($horaS) ?>"
                        data-roteador="<?= h($g['roteador']) ?>">
                        <td><span class="pc-lead-nome"><?= h($g['nome']) ?></span></td>
                        <td class="hsp-quarto"><?= h($g['quarto']) ?></td>
                        <td><?= h($g['telefone']) ?></td>
                        <td><?= (int) $g['dias'] ?> <?= (int) $g['dias'] === 1 ? 'diária' : 'diárias' ?></td>
                        <td>
                            <div class="pc-dh"><span class="pc-data"><?= h($dataS) ?></span><span class="pc-hora"><?= h($horaS) ?></span></div>
                        </td>
                        <td>
                            <span class="pc-dot<?= $ativo ? ' on' : '' ?>"></span>
                            <span class="hsp-sit"><?= $ativo ? ($saiHj ? 'sai hoje' : 'hospedado') : 'saiu' ?></span>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
