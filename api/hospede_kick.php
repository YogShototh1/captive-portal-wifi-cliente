<?php
// Entregue ao MikroTik (leadsync-app.rsc), não ao navegador.
//
// O roteador manda os MACs que estão conectados AGORA; a resposta é a lista dos
// que devem cair — hóspede cujo horário de saída passou, ou que a recepção
// apagou do painel. Sem isto, quem já fez check-out continuaria navegando até
// desligar o Wi-Fi do celular: barrar o login novo não derruba a sessão aberta.
//
// É o roteador que pergunta (pull), como todo o resto: a hospedagem compartilhada
// não alcança o MikroTik, e assim não precisa de túnel.
//
//   POST token=<admin_token>&roteador=<identity>&m=AA:BB:...;CC:DD:...;
//   -> "AA:BB:CC:DD:EE:FF,11:22:33:44:55:66"   (vazio = ninguém cai)
//
// Só derruba MAC que dá para LIGAR a um hóspede que não está mais valendo. MAC
// desconhecido fica — pode ser aparelho da recepção, câmera, bypass do admin.
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/util.php';
require_once __DIR__ . '/../inc/validacao.php';

$cfg = config();
if (!hash_equals((string) $cfg['admin_token'], (string) ($_REQUEST['token'] ?? ''))) {
    http_response_code(403);
    exit('');
}

$roteador = trim((string) ($_REQUEST['roteador'] ?? ''));
if ($roteador === '' || strlen($roteador) > 120) {
    http_response_code(400);
    exit('');
}

mikrotik_tocar($roteador);

header('X-Content-Type-Options: nosniff');
header('Content-Type: text/plain; charset=utf-8');

// Roteador de varejo não tem hóspede: nada a derrubar, e responder cedo evita
// consulta à toa a cada minuto em todos os MikroTiks que não são pousada.
if (roteador_modo($roteador) !== 'hospedagem') {
    exit('');
}

$macs = [];
foreach (explode(';', (string) ($_REQUEST['m'] ?? '')) as $m) {
    $m = sanitiza_mac(trim($m));
    if ($m !== null && $m !== false && $m !== '' && !in_array($m, $macs, true)) {
        $macs[] = $m;
    }
    if (count($macs) >= 200) { // teto: hotspot de pousada não passa disso
        break;
    }
}
if (!$macs) {
    exit('');
}

try {
    $ph = implode(',', array_fill(0, count($macs), '?'));
    // Para cada MAC conectado: existe hóspede VALENDO com o telefone do lead
    // daquele MAC? O vínculo MAC -> telefone vem do histórico de conexões.
    $q = db()->prepare(
        "SELECT DISTINCT c.mac
           FROM conexoes c
           JOIN leads l ON l.id = c.lead_id
          WHERE l.roteador = ? AND c.mac IN ($ph)
            AND NOT EXISTS (
                SELECT 1 FROM hospedes hp
                 WHERE hp.roteador = l.roteador AND hp.telefone = l.telefone
                   AND hp.saida_em > NOW()
            )"
    );
    $q->execute(array_merge([$roteador], $macs));
    $fora = $q->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    exit(''); // banco fora: melhor não derrubar ninguém do que derrubar errado
}

// O RouterOS lê a resposta com :toarray, que separa por vírgula.
echo implode(',', $fora);
