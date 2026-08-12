# ============================================================
#  Captive Data - configuracao inicial de MikroTik  [VAREJO (loja, cafeteria, restaurante)]
#  Testado no hEX RB750Gr3 / RouterOS 7. Roda num aparelho RESETADO e o
#  deixa pronto: LAN 192.168.1.0/24 com DHCP, NAT, hotspot com a tela de
#  login do painel, e o leadsync no scheduler.
#
#  COMO USAR
#    1) Reset:  /system reset-configuration no-defaults=yes skip-backup=yes
#    2) Troque o NOME DO ROTEADOR abaixo (e a unica coisa que muda por cliente).
#    3) Winbox -> Files -> arraste este arquivo.
#    4) Terminal:  /import setup-varejo.rsc
#
#  ATENCAO: a rede muda para 192.168.1.x no meio da execucao. Ligado por cabo
#  na LAN, o Winbox cai por alguns segundos e volta em 192.168.1.1 (ou
#  reconecte por MAC). Ligado pela ether1 (WAN) nao cai.
#
#  IDEMPOTENTE: apaga o que existe antes de criar. Rodar duas vezes nao
#  duplica bridge, pool, DHCP nem hotspot.
# ============================================================


# ############################################################
# ###                                                      ###
# ###          M U D E   A P E N A S   E S T A   L I N H A ###
# ###                                                      ###
# ############################################################
#
#  NOME DO ROTEADOR  ->  e a CHAVE que liga este aparelho ao painel.
#  Tem que ser IDENTICO ao que esta cadastrado em "Roteadores", na conta do
#  cliente (maiusculas/minusculas incluidas). Errou aqui: o hotspot sobe, mas
#  o painel nao recebe nada deste roteador.
#
:local ident "TROQUE-PELO-NOME-DO-ROTEADOR"
#
# ############################################################
# ###          F I M   D O   Q U E   S E   M U D A         ###
# ############################################################


# Daqui para baixo nao precisa mexer.
# admin_token da VPS (inc/config.php). So serve para baixar o leadsync a
# primeira vez; dali em diante o proprio leadsync se atualiza sozinho.
:local token "SEU_ADMIN_TOKEN_AQUI"

:local wan "ether1"
:local lan "192.168.1.1"
:local rede "192.168.1.0/24"
:local faixa "192.168.1.10-192.168.1.254"

:put "== Captive Data :: setup VAREJO (loja, cafeteria, restaurante) =="

# Duas travas antes de mexer em qualquer coisa: um roteador meio configurado e
# pior do que um roteador intacto.
:if ($token = "SEU_ADMIN_TOKEN_AQUI") do={
  :put "!! Token nao preenchido. Use a copia gerada com o token. Abortado."
  :error "token nao configurado"
}
:if ($ident = "TROQUE-PELO-NOME-DO-ROTEADOR") do={
  :put "!! Voce nao trocou o NOME DO ROTEADOR no topo do arquivo."
  :put "!! Ele tem que ser igual ao cadastrado no painel. Abortado."
  :error "nome do roteador nao configurado"
}

# ============================================================
#  1) Identidade
# ============================================================
/system identity set name=$ident
:put ("1/9  identidade: " . $ident)

# ============================================================
#  2) Limpeza do que veio de fabrica
#  A ordem importa: o hotspot depende do servidor DHCP, que depende do pool,
#  que depende do endereco. Removendo de tras para a frente nada fica preso.
# ============================================================
:do { /ip hotspot remove [find] } on-error={}
:do { /ip hotspot profile remove [find where name!="default"] } on-error={}
:do { /ip hotspot user remove [find] } on-error={}
:do { /ip hotspot walled-garden remove [find] } on-error={}
:do { /ip dhcp-server remove [find] } on-error={}
:do { /ip dhcp-server network remove [find] } on-error={}
:do { /ip pool remove [find] } on-error={}
:do { /ip dhcp-client remove [find] } on-error={}
:do { /ip address remove [find] } on-error={}
:do { /interface bridge remove [find] } on-error={}
:do { /queue simple remove [find] } on-error={}
:put "2/9  configuracao antiga removida"

# ============================================================
#  3) Bridge com as portas da LAN (tudo menos a ether1)
#  Descobre as portas sozinho: serve no hEX (5 portas) e em qualquer outro
#  modelo, com ou sem wireless.
# ============================================================
/interface bridge add name=bridge protocol-mode=rstp comment="captivedata"
:foreach i in=[/interface ethernet find] do={
  :local n [/interface ethernet get $i name]
  :if ($n != $wan) do={
    :do { /interface bridge port add bridge=bridge interface=$n } on-error={}
  }
}
# Wireless, quando o modelo tiver. O hEX nao tem — o on-error cobre.
:do {
  :foreach i in=[/interface wireless find] do={
    /interface bridge port add bridge=bridge interface=[/interface wireless get $i name]
  }
} on-error={}
:put "3/9  bridge criada com as portas da LAN"

# ============================================================
#  4) WAN, endereco da LAN e NAT
# ============================================================
/ip dhcp-client add interface=$wan disabled=no use-peer-dns=no comment="captivedata"
/ip address add address=($lan . "/24") interface=bridge comment="captivedata"
/ip firewall nat add chain=srcnat out-interface=$wan action=masquerade comment="captivedata"
:put ("4/9  WAN em " . $wan . " (DHCP) e LAN em " . $lan)

# ============================================================
#  5) DHCP para os clientes
#  O dns-server aponta para o PROPRIO roteador de proposito: e o que enche o
#  cache de DNS que o leadsync le para dizer no painel QUAL site o cliente
#  acessou. Entregando o DNS do provedor, a consulta nao passa por aqui e a
#  coluna de destino do log fica so com IP.
# ============================================================
/ip pool add name=cd-pool ranges=$faixa
/ip dhcp-server add name=cd-dhcp interface=bridge address-pool=cd-pool lease-time=2h disabled=no comment="captivedata"
/ip dhcp-server network add address=$rede gateway=$lan dns-server=$lan comment="captivedata"
/ip dns set servers=1.1.1.1,8.8.8.8 allow-remote-requests=yes
:put ("5/9  DHCP entregando " . $faixa)

# ============================================================
#  6) Relogio
#  O scheduler e os logs dependem da hora certa, e um MikroTik resetado
#  acorda em 1970.
# ============================================================
/system clock set time-zone-name=America/Sao_Paulo
:do { /system ntp client set enabled=yes servers=pool.ntp.br } on-error={
  :do { /system ntp client set enabled=yes primary-ntp=200.160.7.186 } on-error={}
}
:put "6/9  fuso e NTP"

# ============================================================
#  7) Firewall minimo
#  Um roteador resetado com no-defaults fica com a porta aberta para a
#  internet. Estas quatro regras nao sao extra: sem elas o Winbox fica
#  exposto na WAN.
# ============================================================
/ip firewall filter add chain=input connection-state=established,related action=accept comment="captivedata"
/ip firewall filter add chain=input protocol=icmp action=accept comment="captivedata"
/ip firewall filter add chain=input in-interface=bridge action=accept comment="captivedata"
/ip firewall filter add chain=input in-interface=$wan action=drop comment="captivedata"
:do { /ip service disable telnet,ftp,api,api-ssl } on-error={}
:put "7/9  firewall fechado na WAN"

# ============================================================
#  8) Hotspot
#
#  login-by=trial: e assim que a tela do painel autentica — ela chama
#  $(link-login-only) com username=T-<mac>, sem senha. Tirar o trial do
#  login-by faz o botao "Liberar WiFi" parar de funcionar.
#
#  html-directory=hostsv7: e a pasta que o leadsync alimenta (login.html,
#  css, fontes, tema, anuncio). Mudar o nome aqui exige mudar no leadsync.
#
#  trial-uptime-limit=12h / reset=1d: o cliente de loja fica algumas horas
#  e no dia seguinte o contador zera, entao ele ve o anuncio de novo. O
#  corte do dia a dia NAO e este — quem manda e a aba Limites do painel,
#  que derruba pelo leadsync. Aqui e so o teto de seguranca do roteador.
# ============================================================
/ip hotspot profile add name=cd-perfil hotspot-address=$lan dns-name=wifi.local \
    html-directory=hostsv7 login-by=trial trial-uptime-limit=12:00:00 \
    trial-uptime-reset=1d00:00:00 trial-user-profile=default use-radius=no
/ip hotspot add name=cd-hotspot interface=bridge address-pool=cd-pool profile=cd-perfil \
    idle-timeout=10m keepalive-timeout=2m addresses-per-mac=2 disabled=no

# Walled Garden: sem isto o cliente NAO AUTENTICADO nao alcanca a API, e a
# tela de login fica sem tema, sem anuncio e — na pousada — sem conseguir
# validar o numero do hospede.
/ip hotspot walled-garden add dst-host=captivedata.com.br action=allow comment="captivedata"
/ip hotspot walled-garden add dst-host="*.captivedata.com.br" action=allow comment="captivedata"
:put "8/9  hotspot no ar (walled garden liberado)"

# ============================================================
#  9) leadsync
#  Espera a internet subir antes de baixar: logo depois do /ip dhcp-client add
#  o roteador ainda nao tem rota, e o fetch falharia sem motivo.
# ============================================================
:local pronto 0
:for i from=1 to=30 do={
  :if ($pronto = 0) do={
    :do {
      :resolve captivedata.com.br
      :set pronto 1
    } on-error={ :delay 2s }
  }
}

:if ($pronto = 0) do={
  :put "!! Sem internet na ether1. O hotspot ja esta de pe; o leadsync NAO foi"
  :put "   instalado. Resolva a WAN e rode este arquivo de novo."
} else={
  :do {
    /tool fetch url=("https://captivedata.com.br/api/leadsync.php?token=$token&roteador=$ident&f=1") \
        check-certificate=no dst-path="flash/leadsync.rsc"
    :do { /system scheduler remove [find name="leadsync"] } on-error={}
    /system scheduler add name=leadsync interval=1m start-time=startup \
        on-event="/import flash/leadsync.rsc" comment="captivedata"
    # Roda ja: e esta primeira volta que traz login.html, css, fontes e tema.
    :delay 2s
    :do { /import flash/leadsync.rsc } on-error={}
    :put "9/9  leadsync instalado, rodando a cada 1 min"
  } on-error={
    :put "!! Falhou baixar o leadsync. Token errado ou VPS fora do ar."
  }
}

# md5.js: o RouterOS traz esse arquivo na pasta padrao "hotspot". Como usamos
# hostsv7, ele nao e servido, e a tela perde SO o atalho de reconhecer um
# aparelho ja cadastrado (o login.html testa antes de usar, entao nada quebra).
# Se o /file copy existir nesta versao, resolve sozinho; senao, arraste uma vez
# pelo Winbox.
:do { /file copy [find name="hotspot/md5.js"] destination="hostsv7/md5.js" } on-error={}

:put ""
:put "== Pronto =="
:put ("roteador ...... " . $ident)
:put "LAN ........... 192.168.1.1/24, DHCP de .10 a .254"
:put "modo .......... VAREJO (loja, cafeteria, restaurante)"
:put "proximo ....... confira o mesmo nome no painel (tipo: Varejista)"
:put ""
:put "FALTA VOCE FAZER: senha do admin (o reset deixa em branco)"
:put "   /user set admin password=UMA-SENHA-BOA"
