# Deploy via SFTP (porta 22) para a HostGator — FTP (porta 21) é bloqueado
# pela rede local, e o domínio atrás do Cloudflare não passa FTP de qualquer jeito.
# Autenticação por CHAVE SSH (sem senha em lugar nenhum).
#
# Config: C:\Users\Note-Silveira\Desktop\captivedata-deploy.config
#   SSH_HOST=br1014.hostgator.com.br
#   SSH_PORT=22
#   SSH_USER=usuario_do_cpanel
#   SSH_KEY=C:\Users\Note-Silveira\.ssh\captivedata_deploy
#   REMOTE_DIR=public_html
#
# O script envia SOMENTE código. Nunca envia (regra do projeto):
#   ads/* (estado de produção) — só o ads/.htaccess
#   inc/config.php, error_log, node_modules, package*.json, *.zip,
#   default.html, tools/gerar_hash.php e os próprios arquivos de deploy.

$ErrorActionPreference = 'Stop'
$projeto = $PSScriptRoot
$cfgPath = Join-Path (Split-Path $projeto -Parent) 'captivedata-deploy.config'

if (-not (Test-Path $cfgPath)) {
    Write-Host "Config nao encontrado: $cfgPath" -ForegroundColor Red
    exit 1
}
$cfg = @{}
Get-Content $cfgPath | ForEach-Object {
    if ($_ -match '^\s*([A-Z_]+)\s*=\s*(.+?)\s*$') { $cfg[$Matches[1]] = $Matches[2] }
}
foreach ($k in 'SSH_HOST', 'SSH_USER', 'SSH_KEY') {
    if (-not $cfg[$k]) { Write-Host "Falta $k no config." -ForegroundColor Red; exit 1 }
}
$port   = if ($cfg['SSH_PORT']) { $cfg['SSH_PORT'] } else { '22' }
$remoto = if ($cfg['REMOTE_DIR']) { $cfg['REMOTE_DIR'] } else { 'public_html' }

# Arquivos a enviar: tudo do projeto, menos as exclusoes.
$excluirDir = @('node_modules', '.claude')
$excluirArq = @('deploy.ps1', 'default.html', 'package.json', 'package-lock.json')
$todos = Get-ChildItem $projeto -Recurse -File | Where-Object {
    $rel = $_.FullName.Substring($projeto.Length + 1) -replace '\\', '/'
    $topo = $rel.Split('/')[0]
    if ($excluirDir -contains $topo) { return $false }
    if ($excluirArq -contains $rel) { return $false }
    if ($rel -eq 'inc/config.php') { return $false }
    if ($rel -eq 'tools/gerar_hash.php') { return $false }
    if ($_.Name -eq 'error_log') { return $false }
    if ($_.Extension -eq '.zip') { return $false }
    if ($topo -eq 'ads' -and $rel -ne 'ads/.htaccess') { return $false }
    return $true
}

# Duas passadas: este sftp (OpenSSH do Windows) aborta o lote no 1o erro e NAO
# honra o "-" de "-mkdir" em batch — entao um "mkdir pasta ja existe" mataria os
# puts. Passada 1 so cria pastas (exit ignorado); passada 2 sobe os arquivos.
$sftpArgs = @('-P', $port, '-i', $cfg['SSH_KEY'], '-o', 'BatchMode=yes',
    '-o', 'StrictHostKeyChecking=accept-new')
$destino = "$($cfg['SSH_USER'])@$($cfg['SSH_HOST'])"

$dirs = $todos | ForEach-Object {
    $rel = $_.FullName.Substring($projeto.Length + 1) -replace '\\', '/'
    if ($rel.Contains('/')) { $rel.Substring(0, $rel.LastIndexOf('/')) }
} | Sort-Object -Unique
if ($dirs) {
    # ponytail: o lote aborta no 1o dir ja-existente (este sftp nao honra "-mkdir"),
    # entao dirs NOVOS aninhados depois de um existente nao sao criados. OK porque a
    # estrutura de pastas do projeto e estavel; se um dia adicionar subpasta nova e o
    # put dela falhar, criar a pasta uma vez na mao (ou trocar por ssh "mkdir -p").
    $loteD = New-TemporaryFile
    Set-Content -Path $loteD -Value (@("cd $remoto") + ($dirs | ForEach-Object { "-mkdir $_" })) -Encoding ascii
    # O sftp joga "mkdir ja existe" no stderr; sob $ErrorActionPreference='Stop' isso
    # terminaria o script. Baixa a preferencia e descarta o stderr so nesta passada.
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'SilentlyContinue'
    & sftp.exe @sftpArgs -b $loteD $destino 2>$null | Out-Null
    $ErrorActionPreference = $prev
    Remove-Item $loteD -Force
}

$lote = New-TemporaryFile
$linhas = @("cd $remoto")
foreach ($f in $todos) {
    $rel = $f.FullName.Substring($projeto.Length + 1) -replace '\\', '/'
    $linhas += ('put "' + $f.FullName + '" "' + $rel + '"')
}
Set-Content -Path $lote -Value $linhas -Encoding ascii

Write-Host ("Enviando {0} arquivo(s) para {1}:{2}/{3} ..." -f $todos.Count, $cfg['SSH_HOST'], $port, $remoto)
& sftp.exe @sftpArgs -b $lote $destino
$codigo = $LASTEXITCODE
Remove-Item $lote -Force

if ($codigo -eq 0) { Write-Host "Deploy completo, sem falhas." -ForegroundColor Green }
else { Write-Host "Deploy falhou (codigo $codigo). Veja a saida acima." -ForegroundColor Red; exit 1 }
