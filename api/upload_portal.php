<?php
// Recebe a página de login do hotspot e a guarda por roteador. Aceita:
//   - um .zip (template completo, com subpastas css/img/xml) -> extrai e SUBSTITUI tudo;
//   - um arquivo avulso (html/css/js/img...) -> grava na raiz (troca só ele).
// O MikroTik baixa depois (leadsync.rsc) para flash/hostsv7, criando as subpastas.
// Isolamento: admin edita o de qualquer cliente (cliente_id); o cliente só o próprio,
// e só se a conta tiver portal_habilitado (a flag NÃO limita o admin).
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/util.php';

$c = exigir_login();

// Resolve o alvo (roteador + se o recurso está liberado) SEMPRE no servidor.
// O roteador do POST é VALIDADO contra a lista da conta/cliente.
if ((int) $c['is_admin'] === 1) {
    $cid      = (int) ($_POST['cliente_id'] ?? 0);
    $roteador = (string) roteador_escolhido(roteadores_conta($cid), $_POST['roteador'] ?? null);
    $habil    = true; // admin sempre pode
    $voltar   = '../admin_leads.php?id=' . $cid . ($roteador !== '' ? '&r=' . rawurlencode($roteador) : '');
} else {
    $q = db()->prepare('SELECT portal_habilitado FROM compradores WHERE id = ?');
    $q->execute([(int) $c['id']]);
    $roteador = (string) roteador_escolhido(roteadores_conta((int) $c['id']), $_POST['roteador'] ?? null);
    $habil    = ((int) $q->fetchColumn() === 1);
    $voltar   = '../painel.php' . ($roteador !== '' ? '?r=' . rawurlencode($roteador) : '');
}

function voltar_msg(string $to, string $key, string $msg): void
{
    $sep = (strpos($to, '?') === false) ? '?' : '&';
    header('Location: ' . $to . $sep . $key . '=' . rawurlencode($msg));
    exit;
}

// Apaga uma árvore de diretórios (usado para o "substituir tudo" do zip).
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

// Retorna quantos caracteres remover do início de cada caminho quando TODO o zip
// está embrulhado numa única pasta-raiz (ex.: "hostsv7/", de quem zipou a PASTA em
// vez do conteúdo). 0 = não há embrulho. Ignora lixo do macOS/ocultos ao decidir.
function zip_prefixo_comum(ZipArchive $zip): int
{
    $top = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nm = str_replace('\\', '/', (string) $zip->getNameIndex($i));
        if ($nm === '' || substr($nm, -1) === '/') {
            continue; // diretórios
        }
        if ($nm[0] === '.' || strpos($nm, '__MACOSX/') === 0) {
            continue; // lixo de zip do macOS/ocultos não conta
        }
        $bar = strpos($nm, '/');
        if ($bar === false) {
            return 0; // há arquivo na raiz -> não existe pasta-embrulho
        }
        $seg = substr($nm, 0, $bar);
        if ($top === null) {
            $top = $seg;
        } elseif ($top !== $seg) {
            return 0; // mais de uma pasta na raiz -> não é embrulho único
        }
    }
    return $top === null ? 0 : strlen($top) + 1;
}

// Extrai o zip em $destDir, só arquivos permitidos, com tetos anti zip bomb.
// Retorna [gravados, mensagem_de_erro, falhas_ao_gravar]. "falhas" conta arquivos
// que PASSARAM na validação mas não puderam ser gravados (ex.: subpasta sem
// permissão) — útil pra diagnosticar quando faltam arquivos no servidor.
function extrair_zip(string $tmp, string $destDir): array
{
    if (!class_exists('ZipArchive')) {
        return [0, 'O servidor não tem suporte a ZIP (extensão ZipArchive).', 0];
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        return [0, 'ZIP inválido ou corrompido.', 0];
    }
    $POR_ARQ = 2 * 1024 * 1024;   // 2 MB por arquivo
    $TOTAL   = 20 * 1024 * 1024;  // 20 MB somados (descompactados)
    $MAX_QTD = 300;               // no máximo 300 arquivos
    // Remove a pasta-embrulho única, se houver (ex.: "hostsv7/login.html" -> "login.html").
    $strip = zip_prefixo_comum($zip);

    $n = 0;
    $bytes = 0;
    $falhas = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) {
            continue;
        }
        $name = str_replace('\\', '/', $name);
        if (substr($name, -1) === '/') {
            continue; // diretório: criado sob demanda ao gravar os arquivos
        }
        $rel = $strip > 0 ? substr($name, $strip) : $name;
        if ($rel === '' || !portal_path_ok($rel)) {
            continue; // ignora .php, ocultos, traversal, tipos não permitidos
        }
        $stat = $zip->statIndex($i);
        $tam  = $stat ? (int) $stat['size'] : 0;
        if ($tam > $POR_ARQ) {
            continue;
        }
        $bytes += $tam;
        if ($bytes > $TOTAL || $n >= $MAX_QTD) {
            $zip->close();
            return [0, 'ZIP grande demais (limite 20 MB / 300 arquivos).', 0];
        }
        // Guarda PLANO (css/style.css -> css~style.css): não cria subpasta em ads/,
        // que a hospedagem nem sempre permite. O roteador recria as subpastas.
        // Lê pelo ÍNDICE, nunca pelo nome: zips feitos no Windows guardam
        // "css\style.css" e a busca pelo nome já convertido ("css/style.css")
        // não encontra a entrada — era isso que derrubava os arquivos de subpasta.
        $dest = $destDir . '/' . portal_encode($rel);
        $data = $zip->getFromIndex($i);
        if ($data !== false && @file_put_contents($dest, $data) !== false) {
            @chmod($dest, 0644);
            $n++;
        } else {
            $falhas++;
        }
    }
    $zip->close();
    return [$n, '', $falhas];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    voltar_msg($voltar, 'portal_erro', 'Requisição inválida.');
}
if (!csrf_valido($_POST['csrf'] ?? '')) {
    voltar_msg($voltar, 'portal_erro', 'Sessão expirada. Recarregue e tente de novo.');
}
if ($roteador === '') {
    voltar_msg($voltar, 'portal_erro', 'Este cliente não tem roteador vinculado.');
}
if (!$habil) {
    voltar_msg($voltar, 'portal_erro', 'Este recurso não está liberado para este usuário.');
}

$f = $_FILES['arquivo'] ?? null;
$err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
if (!$f || $err === UPLOAD_ERR_NO_FILE) {
    voltar_msg($voltar, 'portal_erro', 'Selecione um arquivo (.zip ou um arquivo avulso).');
}
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
    voltar_msg($voltar, 'portal_erro', 'Arquivo muito grande para o servidor.');
}
if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
    voltar_msg($voltar, 'portal_erro', 'Falha no upload. Tente novamente.');
}

$nome = basename((string) $f['name']);
$ext  = strtolower((string) pathinfo($nome, PATHINFO_EXTENSION));
$base = portal_dir($roteador);

if ($ext === 'zip') {
    // Substitui tudo: extrai num diretório novo e só troca se der certo (atômico).
    $tmpDir = $base . '.new';
    rrmdir($tmpDir);
    if (!@mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
        voltar_msg($voltar, 'portal_erro', 'Não foi possível preparar a extração.');
    }
    [$qtd, $erro, $falhas] = extrair_zip((string) $f['tmp_name'], $tmpDir);
    if ($erro !== '') {
        rrmdir($tmpDir);
        voltar_msg($voltar, 'portal_erro', $erro);
    }
    if ($qtd === 0) {
        rrmdir($tmpDir);
        voltar_msg($voltar, 'portal_erro', 'Nenhum arquivo válido no ZIP (só HTML/CSS/JS/imagens, etc.).');
    }
    rrmdir($base);
    if (!@rename($tmpDir, $base)) {
        rrmdir($tmpDir);
        voltar_msg($voltar, 'portal_erro', 'Não foi possível salvar os arquivos.');
    }
    $msg = "$qtd arquivo(s) do template aplicados";
    if ($falhas > 0) {
        $msg .= " — atenção: $falhas não puderam ser gravados (falha de leitura/gravação no servidor)";
    }
    $msg .= ". O MikroTik atualiza em até ~1 min.";
    voltar_msg($voltar, 'portal_ok', $msg);
}

// Arquivo avulso: valida e grava na raiz (troca só ele).
if (($f['size'] ?? 0) > 2 * 1024 * 1024) {
    voltar_msg($voltar, 'portal_erro', "\"$nome\" passou de 2 MB.");
}
if (!portal_path_ok($nome)) {
    voltar_msg($voltar, 'portal_erro', "\"$nome\": envie um .zip ou um arquivo de página válido.");
}
if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
    voltar_msg($voltar, 'portal_erro', 'Não foi possível criar a pasta do portal.');
}
if (!move_uploaded_file((string) $f['tmp_name'], $base . '/' . $nome)) {
    voltar_msg($voltar, 'portal_erro', "Não foi possível salvar \"$nome\".");
}
@chmod($base . '/' . $nome, 0644);
voltar_msg($voltar, 'portal_ok', "\"$nome\" atualizado. O MikroTik aplica em até ~1 min.");
