# ============================================================
#  leadsync — CANAL (arquivo estavel; o scheduler importa este)
#  Proposito unico: manter contato com o painel e poder trocar o resto.
#  Mude este arquivo o minimo possivel: um erro de sintaxe AQUI derruba o
#  /import inteiro e nao ha como corrigir sem ir ao equipamento.
#  Todo o trabalho (hotspot, leads, portal, tema) vive em leadsync-app.rsc,
#  importado no fim dentro de :do{} on-error={} — se o app quebrar, este
#  arquivo continua rodando e recebe a correcao.
# ============================================================

:local token "SEU_ADMIN_TOKEN_AQUI"
:local ident [/system identity get name]

# ============================================================
#  NAO baixe este arquivo por cima de si mesmo.
#  O /import esta LENDO este arquivo enquanto ele roda; um /tool fetch com
#  dst-path apontando para ca trunca o arquivo em uso e o script morre — sem
#  deixar nada de pe para receber a correcao. Foi o que derrubou o roteador em
#  05/08/2026, as 15:08.
#  Trocar o canal e operacao manual (empurrao pelo portal). Aqui so se troca o
#  APP, que e outro arquivo e nao esta em execucao no momento do download.
# ============================================================

# Versao do RouterOS e modelo, para o painel saber com o que lida sem ninguem
# ir ate o equipamento (decide, por exemplo, se da para usar WireGuard).
:do {
  /tool fetch url="https://captivedata.com.br/api/status.php" http-method=post check-certificate=no       http-header-field="Content-Type: application/x-www-form-urlencoded"       http-data=("token=$token&roteador=$ident&macs=&uso=&rosver=" . [/system resource get version] .                  "&board=" . [/system resource get board-name]) output=none
} on-error={}

# --- app: baixa a versao nova quando muda e executa ---
:global cdAppVer
:if ([:typeof $cdAppVer] = "nothing") do={ :set cdAppVer "" }
:do {
  :local av ""
  :do {
    :local avr [/tool fetch url=("https://captivedata.com.br/api/leadsync.php?token=$token&roteador=$ident&app=1")         check-certificate=no output=user as-value]
    :set av ($avr->"data")
  } on-error={ :set av "" }
  :if ([:len $av] > 0 && [:len $av] < 20 && $av != $cdAppVer) do={
    :do {
      /tool fetch url=("https://captivedata.com.br/api/leadsync.php?token=$token&roteador=$ident&app=1&f=1")           check-certificate=no dst-path="flash/leadsync-app.rsc"
      :set cdAppVer $av
    } on-error={}
  }
} on-error={}

# Se o app tiver erro, o on-error segura aqui e o canal sobrevive.
:do { /import flash/leadsync-app.rsc } on-error={}
