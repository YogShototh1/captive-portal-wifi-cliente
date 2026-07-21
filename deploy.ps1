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

# Lote do sftp: cria as pastas (o "-" na frente ignora "ja existe") e sobe tudo.
$lote = New-TemporaryFile
$linhas = @("cd $remoto")
$dirs = $todos | ForEach-Object {
    $rel = $_.FullName.Substring($projeto.Length + 1) -replace '\\', '/'
    if ($rel.Contains('/')) { $rel.Substring(0, $rel.LastIndexOf('/')) }
} | Sort-Object -Unique
foreach ($d in $dirs) { $linhas += "-mkdir $d" }
foreach ($f in $todos) {
    $rel = $f.FullName.Substring($projeto.Length + 1) -replace '\\', '/'
    $linhas += ('put "' + $f.FullName + '" "' + $rel + '"')
}
Set-Content -Path $lote -Value $linhas -Encoding ascii

Write-Host ("Enviando {0} arquivo(s) para {1}:{2}/{3} ..." -f $todos.Count, $cfg['SSH_HOST'], $port, $remoto)
& sftp.exe -P $port -i $cfg['SSH_KEY'] -o BatchMode=yes -o StrictHostKeyChecking=accept-new -b $lote "$($cfg['SSH_USER'])@$($cfg['SSH_HOST'])"
$codigo = $LASTEXITCODE
Remove-Item $lote -Force

if ($codigo -eq 0) { Write-Host "Deploy completo, sem falhas." -ForegroundColor Green }
else { Write-Host "Deploy falhou (codigo $codigo). Veja a saida acima." -ForegroundColor Red; exit 1 }
