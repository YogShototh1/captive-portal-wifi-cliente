<?php
// Entregue ao MikroTik, não ao navegador. Canal de manutenção do próprio script.
//   sem &f : versão que o roteador deve estar rodando (curta)
//   com &f : o leadsync.rsc, com o token real injetado
//
// Por que existe: o script vivia só na flash do roteador, e trocá-lo exigia ir
// até o equipamento — que fica atrás de CGNAT, sem acesso remoto. Com isto, o
// BLOCO 0 do leadsync baixa a versão nova por cima de flash/leadsync.rsc e o
// scheduler passa a executá-la na volta seguinte. É o que torna possível
// corrigir, configurar VPN ou atualizar o RouterOS sem visita.
//
// O arquivo no repositório tem SEU_ADMIN_TOKEN_AQUI no lugar do token; o valor
// real entra aqui, na entrega, lido do config.php. Assim o token continua fora
// do versionamento.
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/util.php';

$cfg   = config();
$token = (string) ($_REQUEST['token'] ?? '');
if (!hash_equals((string) $cfg['admin_token'], $token)) {
    http_response_code(403);
    exit('');
}

$roteador = trim((string) ($_REQUEST['roteador'] ?? ''));
if ($roteador === '') {
    http_response_code(400);
    exit('');
}

mikrotik_tocar($roteador);
header('X-Content-Type-Options: nosniff');
header('Content-Type: text/plain; charset=utf-8');

// Qual versao entregar. A troca do script e feita em etapas de proposito: a
// primeira leva SO o BLOCO 0 sobre o script que ja roda (30 linhas a mais, nada
// removido). Se um erro de sintaxe derrubasse o /import, o roteador pararia de
// executar tudo — inclusive o proprio BLOCO 0 — e o canal se fecharia sem
// segunda chance. Com o canal provado, a etapa 2 leva o resto com rede.
$etapas = __DIR__ . '/../mikrotik/etapas';
// &app=1 -> o arquivo de trabalho; sem isso -> o canal (o que o scheduler importa)
$controle = !empty($_REQUEST['app']) ? '/ATUAL_APP' : '/ATUAL';
$qual     = trim((string) @file_get_contents($etapas . $controle));
$arquivo = ($qual !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $qual) && is_file($etapas . '/' . $qual))
    ? $etapas . '/' . $qual
    : __DIR__ . '/../mikrotik/leadsync.rsc';
if (!is_file($arquivo)) {
    http_response_code(404);
    exit('');
}

// A versão é o conteúdo: mudou o script, muda a versão, o roteador rebaixa.
$conteudo = (string) file_get_contents($arquivo);
$versao   = substr(sha1($conteudo), 0, 12);

if (!isset($_REQUEST['f'])) {
    exit($versao);
}

// Token real só na saída, nunca no arquivo em disco.
echo str_replace('SEU_ADMIN_TOKEN_AQUI', (string) $cfg['admin_token'], $conteudo);
