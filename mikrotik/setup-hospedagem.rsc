# ============================================================
#  Captive Data - configuracao inicial de MikroTik  [HOSPEDAGEM (pousada, hotel)]
#  Testado no hEX RB750Gr3 / RouterOS 7. Roda num aparelho RESETADO e o
#  deixa pronto: LAN 192.168.1.0/24 com DHCP, NAT, hotspot com a tela de
#  login do painel, e o leadsync no scheduler.
#
#  COMO USAR
#    1) Reset:  /system reset-configuration no-defaults=yes skip-backup=yes
#    2) Ajuste o bloco do topo: nome do roteador + portas (internet / rede).
#    3) Winbox -> Files -> arraste este arquivo.
#    4) Terminal:  /import setup-hospedagem.rsc
#       Ele confere tudo, se agenda para 20s depois e devolve o terminal.
#    5) Espere ~1 min (a sessao cai no meio, e esperado). Reconecte em
#       192.168.1.1 (admin, senha nova) e leia o que aconteceu:
#
#         /log print where message~"cdsetup"
#
#  POR QUE ELE SE AGENDA
#  A etapa 2 apaga o endereco de IP e a bridge: a sua sessao cai junto. Tudo
#  que roda DENTRO da sessao morre nessa hora — o /import direto morreu no
#  1/9, e o :execute morreu no 2/9, porque job de terminal e filho do
#  terminal. Tarefa do scheduler pertence ao sistema: a sessao cai, ela segue.
#  Por isso a primeira passada so agenda a segunda, que faz o trabalho.
#
#  IDEMPOTENTE: apaga o que existe antes de criar. Rodar duas vezes nao
#  duplica bridge, pool, DHCP nem hotspot.
#
#  A senha do admin ja vai definida aqui dentro: o reset deixa em branco e
#  um roteador sem senha exposto na internet nao pode sair da bancada.
# ============================================================


# ############################################################
# ###                                                      ###
# ###        M U D E   S O   E S T E   B L O C O          ###
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
#  PORTA DA INTERNET  ->  onde entra o cabo do provedor/modem. Leva o NAT.
#
:local wan "ether2"

#
#  PORTAS DA REDE DO CLIENTE  ->  entram na bridge e recebem o hotspot.
#  Separe por virgula: "ether3,ether4,ether5". Wi-Fi integrado (quando o
#  modelo tiver) entra pelo nome tambem: "ether3,wlan1".
#
#  NAO liste aqui a porta da internet.
#
#  Porta que sobra fora da bridge nao tem IP nem DHCP: o AP ligado nela deixa
#  o celular conectar no Wi-Fi e nao entregar nada. Por isso o padrao poe as
#  tres portas livres, e o AP funciona em qualquer uma. A ether1 fica de fora
#  porque a do aparelho de bancada esta com defeito.
#
:local portas "ether3,ether4,ether5"
#
# ############################################################
# ###          F I M   D O   Q U E   S E   M U D A         ###
# ############################################################


# Daqui para baixo nao precisa mexer.
# admin_token da VPS (inc/config.php). So serve para baixar o leadsync a
# primeira vez; dali em diante o proprio leadsync se atualiza sozinho.
:local token "SEU_ADMIN_TOKEN_AQUI"

# Senha do usuario admin do roteador. O reset deixa em branco, e um MikroTik
# sem senha na internet e questao de horas. Definida na etapa 1, ANTES de a
# WAN subir: se algo falhar do meio para a frente, o aparelho ja fica fechado.
:local senha "SENHA_DO_ADMIN_AQUI"

:local lan "192.168.1.1"
:local rede "192.168.1.0/24"
:local faixa "192.168.1.10-192.168.1.254"

:put "== Captive Data :: setup HOSPEDAGEM (pousada, hotel) =="

# Travas antes de mexer em qualquer coisa: um roteador meio configurado e pior
# do que um roteador intacto.
:if ($token = "SEU_ADMIN_TOKEN_AQUI") do={
  :put "!! Token nao preenchido. Use a copia gerada com o token. Abortado."
  :log warning "cdsetup ABORTOU: token nao configurado"
  :error "token nao configurado"
}
:if ($ident = "TROQUE-PELO-NOME-DO-ROTEADOR") do={
  :put "!! Voce nao trocou o NOME DO ROTEADOR no topo do arquivo."
  :put "!! Ele tem que ser igual ao cadastrado no painel. Abortado."
  :log warning "cdsetup ABORTOU: nome do roteador nao configurado"
  :error "nome do roteador nao configurado"
}
:if ($senha = "SENHA_DO_ADMIN_AQUI") do={
  :put "!! Senha nao preenchida. Use a copia gerada. Abortado."
  :log warning "cdsetup ABORTOU: senha nao configurada"
  :error "senha nao configurada"
}

# Nome de porta errado e o erro mais caro deste script: o roteador fica sem
# internet, o leadsync nao instala, e a porta onde esta o AP fica fora da rede.
# Conferir custa nada, e o aviso ja diz quais portas existem.
:local faltando ""
:foreach n in=[:toarray ($portas . "," . $wan)] do={
  :if ([:len [/interface find where name=$n]] = 0) do={ :set faltando ($faltando . $n . " ") }
}
:if ([:len $faltando] > 0) do={
  :put ("!! Estas portas nao existem neste roteador: " . $faltando)
  :put "!! Interfaces disponiveis:"
  :foreach i in=[/interface find] do={ :put ("   " . [/interface get $i name]) }
  :log warning "cdsetup ABORTOU: porta inexistente"
  :error "porta inexistente"
}
:foreach n in=[:toarray $portas] do={
  :if ($n = $wan) do={
    :put ("!! " . $wan . " esta na lista da rede do cliente E como internet.")
    :put "!! Uma porta so pode ser uma coisa. Abortado."
    :log warning "cdsetup ABORTOU: wan repetida na bridge"
    :error "wan repetida na bridge"
  }
}

# ============================================================
#  0) Sai da sessao
#  Primeira passada (terminal): agenda e para aqui. Segunda passada (scheduler,
#  20s depois): apaga a marca e o agendamento — para rodar UMA vez, mesmo que
#  aborte no meio — e segue o script inteiro, ja fora da sessao.
#
#  Quem diz em qual passada estamos e a marca cdfase, posta pelo proprio
#  agendamento. Olhar se o scheduler existe nao servia: agendamento que falha
#  ao iniciar fica la, ligado, e a passada do terminal se acharia a segunda.
# ============================================================
:global cdfase
:if ([:tostr $cdfase] != "2") do={
  :do { /system scheduler remove [find name="cdsetup"] } on-error={}

  # O caminho vem de /file, nao do palpite: neste hEX o arquivo mora em
  # flash/, e "/import setup-x.rsc" — que abre no terminal — deu "file does
  # not exist" quando o scheduler tentou.
  :local arq ""
  :foreach f in=[/file find where name~"setup-hospedagem.rsc"] do={ :set arq [/file get $f name] }
  :if ([:len $arq] = 0) do={
    :put "!! Nao achei o setup-hospedagem.rsc em /file. Reenvie pelo Winbox (Files) e rode de novo."
    :log warning "cdsetup ABORTOU: setup-hospedagem.rsc nao esta em /file"
    :error "arquivo nao encontrado"
  }

  /system scheduler add name=cdsetup interval=20s comment="captivedata" \
      on-event=(":global cdfase; :set cdfase 2; /import " . $arq)
  :log info "cdsetup agendado (roda daqui a 20s)"
  :put ""
  :put "Tudo conferido. O roteador vai se configurar sozinho em 20 segundos."
  :put "A sua sessao VAI CAIR no meio disso — e normal, ele continua."
  :put ""
  :put "Espere 1 minuto, reconecte em 192.168.1.1 (admin, senha nova) e veja:"
  :put "   /log print where message~\"cdsetup\""
  :put ""
  :put "(o 'failure: agendado' na linha de baixo e so o fim desta parte)"
  :error "agendado"
}
# Marca consumida na hora: se este job morrer no meio, a proxima vez que
# alguem rodar pelo terminal tem que ser a primeira passada de novo.
:set cdfase 0
:do { /system scheduler set [find name="cdsetup"] disabled=yes interval=0 } on-error={}

# ============================================================
#  1) Identidade e senha do admin
#  Antes de qualquer outra coisa: e a etapa que nao pode ficar pela metade.
# ============================================================
/system identity set name=$ident
:local senhaok 0
:do {
  /user set [find name="admin"] password=$senha
  :set senhaok 1
} on-error={}
:if ($senhaok = 0) do={
  # Usuario "admin" renomeado ou removido: nao da para adivinhar qual e, e
  # seguir sem senha seria deixar o aparelho aberto sem avisar.
  :put "!! Nao consegui definir a senha: nao existe usuario chamado admin."
  :put "!! Defina na mao antes de por este roteador em campo. Abortado."
  :log warning "cdsetup ABORTOU: senha do admin nao definida"
  :error "senha do admin nao definida"
}
:put ("1/9  identidade: " . $ident . "  (senha do admin definida)")
:log info ("cdsetup " . ("1/9  identidade: " . $ident . "  (senha do admin definida)"))

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
# As portas primeiro: remover a bridge deixa as entradas de /interface bridge
# port para tras, e a proxima bridge nao consegue mais pegar aquelas portas.
:do { /interface bridge port remove [find] } on-error={}
:do { /interface bridge remove [find] } on-error={}
:do { /queue simple remove [find] } on-error={}
:put "2/9  configuracao antiga removida"
:log info ("cdsetup " . "2/9  configuracao antiga removida")

# ============================================================
#  3) Bridge com as portas do cliente
#  Lista explicita, e nao "todas menos a WAN": porta com defeito, porta de
#  gerencia ou uplink para outro switch nao podem entrar por descuido.
# ============================================================
:do {
  /interface bridge add name=bridge protocol-mode=rstp comment="captivedata"
} on-error={
  :log warning "cdsetup ABORTOU: nao consegui criar a bridge"
  :put "!! Nao consegui criar a bridge. Veja se sobrou uma: /interface bridge print"
  :error "bridge nao criada"
}
:foreach n in=[:toarray $portas] do={
  # Cinto e suspensorio: se a limpeza acima falhou calada, a porta ainda esta
  # presa na bridge velha e o add recusaria.
  :do { /interface bridge port remove [find interface=$n] } on-error={}
  :do {
    /interface bridge port add bridge=bridge interface=$n
  } on-error={
    :log warning ("cdsetup ABORTOU: " . $n . " nao entrou na bridge")
    :put ("!! " . $n . " nao entrou na bridge. Veja: /interface bridge port print")
    :error "porta na bridge"
  }
}
:put ("3/9  bridge com: " . $portas)
:log info ("cdsetup " . ("3/9  bridge com: " . $portas))

# ============================================================
#  4) WAN, endereco da LAN e NAT
# ============================================================
/ip dhcp-client add interface=$wan disabled=no use-peer-dns=no comment="captivedata"
/ip address add address=($lan . "/24") interface=bridge comment="captivedata"
/ip firewall nat add chain=srcnat out-interface=$wan action=masquerade comment="captivedata"

# Espera o lease ANTES de seguir. Sem isto o script terminava "com sucesso" num
# roteador sem internet: o leadsync nao instalava e a unica pista era uma linha
# de aviso no meio da saida. O sintoma so aparecia dias depois, no painel.
:local lease ""
:for i from=1 to=20 do={
  :if ([:len $lease] = 0) do={
    :do { :set lease [/ip dhcp-client get [find interface=$wan] address] } on-error={}
    :if ([:len $lease] = 0) do={ :delay 1s }
  }
}
:if ([:len $lease] = 0) do={
  :put ("!! A porta " . $wan . " nao recebeu endereco do provedor em 20s.")
  :put "!! Onde HA cabo ligado agora:"
  :foreach i in=[/interface ethernet find where running=yes] do={
    :put ("   " . [/interface ethernet get $i name] . "  <- tem link")
  }
  :put "!! Corrija a PORTA DA INTERNET no topo do arquivo e rode de novo."
  :log warning "cdsetup ABORTOU: WAN sem endereco"
  :error "WAN sem endereco"
}
:put ("4/9  WAN em " . $wan . " (" . $lease . ") e LAN em " . $lan)
:log info ("cdsetup " . ("4/9  WAN em " . $wan . " (" . $lease . ") e LAN em " . $lan))

# ============================================================
#  5) DHCP para os clientes
#  O dns-server aponta para o PROPRIO roteador de proposito: e o que enche o
#  cache de DNS que o leadsync le para dizer no painel QUAL site o cliente
#  acessou. Entregando o DNS do provedor, a consulta nao passa por aqui e a
#  coluna de destino do log fica so com IP.
# ============================================================
/ip pool add name=cd-pool ranges=$faixa
/ip dhcp-server add name=cd-dhcp interface=bridge address-pool=cd-pool lease-time=12h disabled=no comment="captivedata"
/ip dhcp-server network add address=$rede gateway=$lan dns-server=$lan comment="captivedata"
/ip dns set servers=1.1.1.1,8.8.8.8 allow-remote-requests=yes
:put ("5/9  DHCP entregando " . $faixa)
:log info ("cdsetup " . ("5/9  DHCP entregando " . $faixa))

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
:log info ("cdsetup " . "6/9  fuso e NTP")

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
:log info ("cdsetup " . "7/9  firewall fechado na WAN")

# ============================================================
#  8) Hotspot
#
#  login-by=trial,http-chap,http-pap: a tela chama $(link-login-only) com
#  username=T-<mac>, sem senha. O trial sozinho NAO basta — o pedido chega
#  por HTTP e o RouterOS recusa com "configuration error (HTTP/HTTPS login is
#  not allowed)". Foi o vermelho que apareceu na tela do cliente.
#
#  O http-chap ainda preenche o chap-id, e e o chap-id que faz o login.html
#  carregar o tema local (tema.js) e a lista de aparelhos da flash: sem ele
#  metade da pagina nem e enviada pelo roteador.
#
#  html-directory=hostsv7: e a pasta que o leadsync alimenta (login.html,
#  css, fontes, tema, anuncio). Mudar o nome aqui exige mudar no leadsync.
#
#  trial-uptime-limit=60d: hospede fica DIAS, nao horas. Um teto de loja
#  (12h) cortaria o hospede no meio da diaria — o painel diria "pode
#  entrar" e o roteador diria nao. Quem decide a hora de sair e a data de
#  check-out no painel, que o leadsync aplica derrubando a sessao.
#
#  idle-timeout maior pelo mesmo motivo: celular de hospede dorme no
#  quarto e nao pode perder a sessao por isso.
# ============================================================
/ip hotspot profile add name=cd-perfil hotspot-address=$lan dns-name=wifi.local \
    html-directory=hostsv7 login-by=trial,http-chap,http-pap trial-uptime-limit=60d00:00:00 \
    trial-uptime-reset=61d00:00:00 trial-user-profile=default use-radius=no
/ip hotspot add name=cd-hotspot interface=bridge address-pool=cd-pool profile=cd-perfil \
    idle-timeout=30m keepalive-timeout=5m addresses-per-mac=2 disabled=no

# Walled Garden: sem isto o cliente NAO AUTENTICADO nao alcanca a API, e a
# tela de login fica sem tema, sem anuncio e — na pousada — sem conseguir
# validar o numero do hospede.
/ip hotspot walled-garden add dst-host=captivedata.com.br action=allow comment="captivedata"
/ip hotspot walled-garden add dst-host="*.captivedata.com.br" action=allow comment="captivedata"

# A pasta do html-directory tem que EXISTIR antes do primeiro sync: o
# /tool fetch so a cria sozinho em parte das versoes do RouterOS, e onde nao
# cria o portal inteiro falha calado — o hotspot serve a tela padrao do
# RouterOS e do painel parece que o envio deu certo. O leadsync repete esta
# garantia a cada envio; aqui e so para a primeira volta ja achar tudo pronto.
:if ([:len [/file find where name="flash/hostsv7"]] = 0) do={
  :do { /file add name="flash/hostsv7" type=directory } on-error={}
}

# Confere em vez de anunciar: a versao anterior imprimia "hotspot no ar" mesmo
# se o perfil tivesse sido recusado e o servidor nem existisse.
:if ([:len [/ip hotspot find where name="cd-hotspot"]] = 0) do={
  :put "!! O servidor de hotspot nao foi criado. Veja o erro acima."
  :log warning "cdsetup ABORTOU: hotspot nao criado"
  :error "hotspot nao criado"
}
:put "8/9  hotspot no ar (walled garden liberado, pasta hostsv7 pronta)"
:log info ("cdsetup " . "8/9  hotspot no ar (walled garden liberado, pasta hostsv7 pronta)")

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
  :put ("!! Sem internet na " . $wan . ". O hotspot ja esta de pe; o leadsync NAO foi")
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
    :log info "cdsetup 9/9  leadsync instalado"
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
:put "modo .......... HOSPEDAGEM (pousada, hotel)"
:put ("internet ...... " . $wan)
:put ("rede/hotspot .. " . $portas)
# Scheduler herdado de uma instalacao antiga (outro intervalo, script velho)
# faz o roteador parecer saudavel rodando codigo que nao e este. Ja aconteceu.
:local sch [/system scheduler find name="leadsync"]
:if ([:len $sch] = 0) do={
  :put "leadsync ...... NAO INSTALADO (veja o aviso acima)"
} else={
  :put ("leadsync ...... a cada " . [/system scheduler get $sch interval])
}
:put "proximo ....... confira o mesmo nome no painel (tipo: Hospedagem)"
:put ""
:put "senha do admin ja definida por este script."
:put ""
:put "conferir depois:  /log print where message~\"cdsetup\""
:log info "cdsetup FIM"
# So agora: apagar o agendamento pode derrubar este proprio job, e aqui ja
# nao sobrou nada para rodar.
:do { /system scheduler remove [find name="cdsetup"] } on-error={}
