<?php
// Migração: o mesmo roteador pode ficar vinculado a VÁRIAS contas.
//
// Antes: `identity` era UNIQUE sozinho — um roteador pertencia a uma conta só.
// Agora: UNIQUE (comprador_id, identity) — várias contas podem apontar para o
// mesmo MikroTik, e a única coisa barrada é a mesma conta repetir o mesmo
// roteador. Idempotente: pode rodar mais de uma vez.
//
// Via web exige o admin_token, como as demais tools:
//   /tools/migrar_roteador_compartilhado.php?token=SEU_ADMIN_TOKEN
// Autoteste (sem banco):  php tools/migrar_roteador_compartilhado.php --teste
require_once __DIR__ . '/../inc/db.php';

// Do que o SHOW INDEX devolve: quais índices únicos derrubar e se falta criar o
// composto. Pura de propósito — é a única parte com decisão e dá para conferir
// sem banco. O nome do índice antigo não é fixo (o MySQL batiza sozinho quando
// a coluna é declarada UNIQUE), então ele é achado pelas COLUNAS, não pelo nome.
function indices_roteadores(array $linhas): array
{
    $unicos = []; // nome -> [seq => coluna]
    foreach ($linhas as $l) {
        if ((int) ($l['Non_unique'] ?? 1) !== 0) {
            continue; // índice comum não restringe nada
        }
        $unicos[(string) $l['Key_name']][(int) $l['Seq_in_index']] = (string) $l['Column_name'];
    }

    $remover = [];
    $temComposto = false;
    foreach ($unicos as $nome => $cols) {
        ksort($cols); // a ordem das colunas é o que define o índice
        $cols = array_values($cols);
        if ($cols === ['identity']) {
            $remover[] = $nome;
        } elseif ($cols === ['comprador_id', 'identity']) {
            $temComposto = true;
        }
    }
    // PRIMARY (só `id`) não cai em nenhum dos dois: fica de fora, como deve.
    return ['remover' => $remover, 'criar' => !$temComposto];
}

// --- autoteste ---------------------------------------------------------
if (PHP_SAPI === 'cli' && in_array('--teste', $argv ?? [], true)) {
    $ix = function (string $nome, array $cols, int $unico = 0): array {
        $o = [];
        foreach ($cols as $i => $c) {
            $o[] = ['Key_name' => $nome, 'Seq_in_index' => $i + 1, 'Column_name' => $c, 'Non_unique' => $unico];
        }
        return $o;
    };
    $primary = $ix('PRIMARY', ['id']);
    $antigo  = $ix('identity', ['identity']);
    $novo    = $ix('uk_conta_rot', ['comprador_id', 'identity']);
    $comum   = $ix('idx_comprador', ['comprador_id'], 1);

    $r = indices_roteadores(array_merge($primary, $antigo, $comum));
    assert($r['remover'] === ['identity'], 'acha o unique antigo pelas colunas');
    assert($r['criar'] === true, 'e sabe que falta criar o composto');

    $r = indices_roteadores(array_merge($primary, $novo, $comum));
    assert($r['remover'] === [], 'ja migrado: nao derruba nada');
    assert($r['criar'] === false, 'ja migrado: nao recria');

    $r = indices_roteadores(array_merge($primary, $comum));
    assert($r['remover'] === [] && $r['criar'] === true, 'indice comum nao conta como unique');

    // Nome diferente do padrao do MySQL: tem que achar do mesmo jeito.
    $r = indices_roteadores(array_merge($primary, $ix('sei_la', ['identity'])));
    assert($r['remover'] === ['sei_la'], 'acha pelo conteudo, nao pelo nome');

    // Ordem invertida das colunas e outro indice, nao e o composto que queremos.
    $r = indices_roteadores(array_merge($primary, $ix('torto', ['identity', 'comprador_id'])));
    assert($r['remover'] === [] && $r['criar'] === true, 'ordem das colunas importa');

    echo "tudo certo\n";
    exit;
}
// -----------------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals((string) config()['admin_token'], (string) ($_REQUEST['token'] ?? ''))) {
        http_response_code(403);
        exit("token invalido\n");
    }
}

$plano = indices_roteadores(db()->query('SHOW INDEX FROM roteadores')->fetchAll());

foreach ($plano['remover'] as $nome) {
    db()->exec('ALTER TABLE roteadores DROP INDEX `' . str_replace('`', '', $nome) . '`');
    echo "ok: unique global `$nome` removido\n";
}
if ($plano['criar']) {
    // Se sobrou linha repetida dentro da MESMA conta, o ALTER falha e avisa —
    // melhor parar do que apagar vínculo de cliente sem ninguém ver.
    db()->exec('ALTER TABLE roteadores ADD UNIQUE KEY uk_conta_rot (comprador_id, identity)');
    echo "ok: unique (comprador_id, identity) criado\n";
} else {
    echo "ok: unique (comprador_id, identity) ja existia\n";
}
echo "pronto: o mesmo roteador ja pode ficar em varias contas\n";
