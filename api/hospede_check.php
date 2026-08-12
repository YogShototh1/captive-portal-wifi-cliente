<?php
// Endpoint PÚBLICO: o portal do hotspot pergunta se um número pode entrar.
//
// Só vale para roteador de conta de HOSPEDAGEM. Responde JS (não JSON) porque é
// carregado por <script src=...>, o único jeito que funciona no navegador do
// captive portal do iPhone (CNA), onde fetch/XHR são bloqueados — mesmo esquema
// do dst.php. O domínio já está no Walled Garden.
//
//   /api/hospede_check.php?r=<identity>&tel=<numero>&cb=<funcao>
//   -> cb({ok:true, nome:"Maria", quarto:"12"})   liberado
//   -> cb({ok:false})                             não cadastrado ou já saiu
//
// Nunca diz POR QUE recusou e nunca devolve dado de quem não passou: o portal é
// público e qualquer um digita números nele.
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/util.php';
require_once __DIR__ . '/../inc/validacao.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, max-age=0');

// Nome do callback vem da URL: só letras/números/_ , para não virar injeção.
$cb = (string) ($_GET['cb'] ?? '');
if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,40}$/', $cb)) {
    $cb = 'cdHospede';
}
function responder(string $cb, array $r): void
{
    echo $cb . '(' . json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');';
    exit;
}

$roteador = trim((string) ($_GET['r'] ?? ''));
$telefone = sanitiza_telefone((string) ($_GET['tel'] ?? ''));
if ($roteador === '' || strlen($roteador) > 120 || $telefone === null) {
    responder($cb, ['ok' => false]);
}

// Heartbeat: se chegou pergunta do portal, o roteador está de pé.
mikrotik_tocar($roteador);

try {
    // Freio de força bruta por IP: o portal é público e alguém pode varrer
    // números atrás de um que abra o WiFi. 30 perguntas por minuto por IP já é
    // muito mais do que um hóspede digitando errado precisa.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip !== '') {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS hospede_check_log (
                ip     VARCHAR(45) NOT NULL,
                em     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ip_em (ip, em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $q = db()->prepare('SELECT COUNT(*) FROM hospede_check_log WHERE ip = ? AND em > (NOW() - INTERVAL 60 SECOND)');
        $q->execute([$ip]);
        if ((int) $q->fetchColumn() >= 30) {
            responder($cb, ['ok' => false]);
        }
        db()->prepare('INSERT INTO hospede_check_log (ip) VALUES (?)')->execute([$ip]);
        // Faxina barata: 1 em cada 50 chamadas limpa o que passou de 1 dia.
        if (random_int(1, 50) === 1) {
            db()->exec('DELETE FROM hospede_check_log WHERE em < (NOW() - INTERVAL 1 DAY)');
        }
    }

    $q = db()->prepare(
        'SELECT nome, quarto FROM hospedes
          WHERE roteador = ? AND telefone = ? AND saida_em > NOW() LIMIT 1'
    );
    $q->execute([$roteador, $telefone]);
    $h = $q->fetch();
} catch (Throwable $e) {
    // Banco fora do ar: o portal trata "erro" diferente de "recusado" — ver o
    // comentário em login.html. Aqui a resposta não sai, e é isso mesmo.
    http_response_code(500);
    exit;
}

if (!$h) {
    responder($cb, ['ok' => false]);
}
responder($cb, ['ok' => true, 'nome' => (string) $h['nome'], 'quarto' => (string) $h['quarto']]);
