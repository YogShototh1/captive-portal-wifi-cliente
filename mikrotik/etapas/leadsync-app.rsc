# ============================================================
#  Captive Portal - Sincronizacao de leads
#  (tempo online + desconexao por limite de tempo + limite de banda)
#  Rode via Scheduler a cada ~1 min. RouterOS 6.43+ / 7.x.
#
#  Fluxo:
#   1) coleta os MACs online e reporta ao painel
#   2) recebe a resposta no formato "<kick>|<mac=Mbps,...>"
#   3) desconecta os MACs da lista de kick (passaram do tempo limite)
#   4) aplica o limite de banda por usuario via /queue simple
#
#  ANTES DE USAR: troque o token pelo admin_token do seu inc/config.php.
#
#  ESTE E O APP. O scheduler NAO importa este arquivo — quem faz isso e o
#  canal (flash/leadsync.rsc), no fim dele, dentro de :do{} on-error={}.
#  Por isso aqui pode-se mexer a vontade: um erro neste arquivo nao derruba
#  o contato com o painel, e a correcao chega sozinha na rodada seguinte.
# ============================================================

:local token "SEU_ADMIN_TOKEN_AQUI"
:local ident [/system identity get name]

# 1) coletar os MACs das sessoes ativas + consumo (bytes-in + bytes-out)
#    uso = "MAC=bytes,MAC=bytes,..." (o servidor grava na conexao aberta)
:local macs ""
:local uso ""
:foreach i in=[/ip hotspot active find] do={
  :local mac [/ip hotspot active get $i mac-address]
  :local bts ([/ip hotspot active get $i bytes-in] + [/ip hotspot active get $i bytes-out])
  :set macs ($macs . $mac . ",")
  :set uso ($uso . $mac . "=" . $bts . ",")
}

# 2) reportar ao painel e receber "<kick>|<bw>"
:local resp ""
:do {
  :local r [/tool fetch url="https://captivedata.com.br/api/status.php" \
      http-method=post check-certificate=no \
      http-header-field="Content-Type: application/x-www-form-urlencoded" \
      http-data=("token=$token&roteador=$ident&macs=$macs&uso=$uso" . \
                 "&rosver=" . [/system resource get version] . \
                 "&board=" . [/system resource get board-name]) \
      output=user as-value]
  :set resp ($r->"data")
} on-error={ :set resp "" }

# So age se a resposta veio bem formada (contem '|'). Se a API falhou/veio vazia,
# nao mexe em nada (nao desconecta ninguem nem remove os limites de banda ja aplicados).
:local bar [:find $resp "|"]
:if ([:typeof $bar] = "num") do={
  :local kickStr [:pick $resp 0 $bar]
  :local bwStr [:pick $resp ($bar + 1) [:len $resp]]

  # 3) desconectar os que passaram do limite de tempo
  :if ([:len $kickStr] > 0) do={
    :foreach mac in=[:toarray $kickStr] do={
      :local id [/ip hotspot active find where mac-address=$mac]
      :if ([:len $id] > 0) do={ /ip hotspot active remove $id }
    }
  }

  # 4) aplicar limite de banda por usuario (Mbps -> /queue simple max-limit)
  #    ponytail: recria as filas a cada rodada (idempotente, sem filas orfas);
  #    se a rotatividade de usuarios for alta, trocar por update incremental.
  :local orf [/queue simple find comment="captivedata"]
  :if ([:len $orf] > 0) do={ /queue simple remove $orf }
  :if ([:len $bwStr] > 0) do={
    :foreach i in=[/ip hotspot active find] do={
      :local mac [/ip hotspot active get $i mac-address]
      :local addr [/ip hotspot active get $i address]
      :foreach pair in=[:toarray $bwStr] do={
        :local eq [:find $pair "="]
        :if ([:typeof $eq] = "num") do={
          :if ([:pick $pair 0 $eq] = $mac) do={
            :local v [:pick $pair ($eq + 1) [:len $pair]]
            /queue simple add name=("cd-" . $mac) target=$addr \
                max-limit=($v . "M/" . $v . "M") comment="captivedata"
          }
        }
      }
    }
  }
}

# ============================================================
#  Hotspot ligado/desligado, por ordem do painel
#
#  Quando o portal quebra, a saida e desligar o hotspot: o Wi-Fi da loja volta
#  a funcionar sem a tela de login. Isso exigia Winbox e alguem com acesso ao
#  roteador; agora o admin manda pelo painel e a ordem chega na rodada seguinte.
#
#  Uma chamada so: manda o estado de cada servidor ("<nome>:<0|1>,...") e
#  recebe de volta "on", "off" ou "-" (nada a fazer). Vem CEDO no script de
#  proposito -- e a alavanca de socorro, entao nao pode depender de os blocos
#  seguintes terem dado certo.
#
#  Liga/desliga TODOS os servidores: o roteador do cliente tem um so, e se
#  aparecer um segundo por engano o painel lista os nomes para o admin apagar.
#
#  ponytail: o nome do servidor vai cru na query. Nome com & ou = quebraria a
#  leitura no painel (a lista aparece vazia); nome de hotspot no RouterOS e
#  "hotspot1" e afins. Se um dia isso acontecer, sanear aqui antes de montar.
# ============================================================
:do {
  # "<nome>~<0|1>~<perfil>|" — o PERFIL e o que decide se alguem consegue
  # entrar. Ter um perfil com trial no roteador nao basta: o que vale e o
  # perfil que ESTE servidor usa. Sem essa informacao o painel mostrava os
  # perfis e mesmo assim nao dava para dizer qual estava valendo.
  :local hsids [/ip hotspot find]
  :local hs ""
  :foreach i in=$hsids do={
    :local on "1"
    :if ([/ip hotspot get $i disabled] = true) do={ :set on "0" }
    :set hs ($hs . [/ip hotspot get $i name] . "~" . $on . "~" . \
             [/ip hotspot get $i profile] . "|")
  }

  # Perfil do hotspot: e o que diz se o cliente CONSEGUE logar.
  #
  # O portal autentica por TRIAL (GET no link-login-only com username, sem
  # senha). Se "trial" nao estiver no login-by, ou se o MAC ja gastou o
  # trial-uptime-limit do dia, o roteador recusa e devolve a propria tela de
  # login — o cliente volta pro comeco. Sem esta informacao no painel nao da
  # para saber qual dos dois e, e nao da para abrir o Winbox da loja.
  #
  # Formato: "<nome>~<login-by>~<limite>~<reset>|". Em bloco proprio porque
  # trial-uptime-* nem sempre existe: se der erro, o liga/desliga continua.
  :local prof ""
  :do {
    :foreach i in=[/ip hotspot profile find] do={
      :set prof ($prof . [/ip hotspot profile get $i name] . "~" . \
                 [:tostr [/ip hotspot profile get $i login-by]] . "~" . \
                 [:tostr [/ip hotspot profile get $i trial-uptime-limit]] . "~" . \
                 [:tostr [/ip hotspot profile get $i trial-uptime-reset]] . "|")
    }
  } on-error={ :set prof "" }

  :local ordem "-"
  :do {
    :local hr [/tool fetch url="https://captivedata.com.br/api/hotspot_rt.php" \
        http-method=post check-certificate=no \
        http-header-field="Content-Type: application/x-www-form-urlencoded" \
        http-data=("token=$token&roteador=$ident&hs=$hs&prof=$prof") \
        output=user as-value]
    :set ordem ($hr->"data")
  } on-error={ :set ordem "-" }

  # Sem servidor nenhum nao ha o que ligar; enable/disable de lista vazia erra.
  :if ([:len $hsids] > 0) do={
    :if ($ordem = "on")  do={ /ip hotspot enable $hsids }
    :if ($ordem = "off") do={ /ip hotspot disable $hsids }
  }
} on-error={}

# ============================================================
#  Pagina de login do hotspot (flash/hostsv7)
#  O painel guarda o template (HTML/CSS/JS/imagens, com subpastas css/img/xml);
#  aqui o roteador BAIXA cada arquivo e substitui os de flash/hostsv7.
#  So grava na flash quando a versao muda -> poupa a flash.
#
#  A pasta e criada aqui embaixo se faltar: quem envia o pacote e o painel, e
#  o painel NAO alcanca o roteador (CGNAT, sem tunel). Quem pode criar pasta
#  la e o proprio roteador, e o momento certo e este — logo antes de gravar.
# ============================================================
:local pmanifest ""
:do {
  :local pr [/tool fetch url=("https://captivedata.com.br/api/portal.php?token=$token&roteador=$ident") \
      check-certificate=no output=user as-value]
  :set pmanifest ($pr->"data")
} on-error={ :set pmanifest "" }

# Formato do manifesto: "<versao>|caminho1,caminho2,..." (caminho pode ter subpasta,
# ex.: css/style.css). So age se veio bem formado.
:local pbar [:find $pmanifest "|"]
:if ([:typeof $pbar] = "num") do={
  :local pver [:pick $pmanifest 0 $pbar]
  :local pfiles [:pick $pmanifest ($pbar + 1) [:len $pmanifest]]

  # Versao ja aplicada: guardada em variavel global (persiste entre rodadas).
  # ponytail: reinicia no reboot -> uma re-baixada apos ligar; se quiser evitar
  #           ate isso, gravar a versao num arquivo de flash.
  :global cdPortalVer
  :if ([:typeof $cdPortalVer] = "nothing") do={ :set cdPortalVer "" }

  :if ([:len $pver] > 0 && $pver != $cdPortalVer && [:len $pfiles] > 0) do={
    # Garante a pasta ANTES de baixar.
    #
    # Em roteador recem-configurado ela nao existe, e o /tool fetch so cria a
    # pasta sozinho em parte das versoes do RouterOS. Onde nao cria, TODOS os
    # arquivos falhavam, a versao nunca era marcada como aplicada e o hotspot
    # seguia servindo a tela padrao do RouterOS — sem jeito de descobrir isso
    # pelo painel, porque de la parece que o envio deu certo.
    #
    # Barato de repetir: quando a pasta ja existe, o find corta antes.
    :if ([:len [/file find where name="flash/hostsv7"]] = 0) do={
      :do { /file add name="flash/hostsv7" type=directory } on-error={
        # Versao sem /file add: um fetch qualquer para dentro dela costuma
        # criar o caminho. Se nem isso funcionar, os downloads abaixo erram e
        # a rodada seguinte tenta de novo — nada fica num meio-termo.
        :do {
          /tool fetch url=("https://captivedata.com.br/api/portal.php?token=$token&roteador=$ident&f=login.html") \
              check-certificate=no dst-path="flash/hostsv7/login.html"
        } on-error={}
      }
    }
    :local pfail 0
    :foreach fn in=[:toarray $pfiles] do={
      :if ([:len $fn] > 0) do={
        :do {
          /tool fetch url=("https://captivedata.com.br/api/portal.php?token=$token&roteador=$ident&f=$fn") \
              check-certificate=no dst-path=("flash/hostsv7/" . $fn)
        } on-error={ :set pfail ($pfail + 1) }
      }
    }
    # So marca a versao como aplicada se TODOS baixaram; senao repete na proxima rodada.
    :if ($pfail = 0) do={ :set cdPortalVer $pver }
  }
}

# ============================================================
#  Lista de aparelhos ja cadastrados (macs.js)
#  O painel gera a lista (hashes dos MACs); aqui o roteador a baixa para a
#  flash SO quando a versao muda. O login.html le o arquivo LOCAL e pula a
#  pergunta do numero para quem ja tem cadastro, sem falar com o painel no
#  momento da conexao. Painel fora do ar = lista congelada, tudo segue.
#  Mesma estrutura do bloco do portal acima (fetch versionado).
# ============================================================
:global cdMacsVer
:if ([:typeof $cdMacsVer] = "nothing") do={ :set cdMacsVer "" }
:local mver ""
:do {
  :local mr [/tool fetch url=("https://captivedata.com.br/api/macs.php?token=$token&roteador=$ident") \
      check-certificate=no output=user as-value]
  :set mver ($mr->"data")
} on-error={ :set mver "" }

:if ([:len $mver] > 0 && [:len $mver] < 16 && $mver != $cdMacsVer) do={
  :local ok 0
  :do {
    /tool fetch url=("https://captivedata.com.br/api/macs.php?token=$token&roteador=$ident&f=1") \
        check-certificate=no dst-path="flash/hostsv7/macs.js"
    :set ok 1
  } on-error={ :set ok 0 }
  :if ($ok = 1) do={ :set cdMacsVer $mver }
}

# ============================================================
#  Tema da tela de login (tema.js + logo + anuncio) na flash
#  O comprador define cores/efeitos/logo/anuncio no painel; aqui o roteador
#  BAIXA isso para a flash. O login.html le os arquivos LOCAIS, entao o visual
#  do cliente aparece mesmo com a internet do estabelecimento fora — antes o
#  navegador tinha que buscar do painel no momento da conexao, e sem internet
#  a tela abria sem tema, sem logo e sem anuncio.
#  Mesma estrutura versionada do macs.js: so grava quando a versao muda.
# ============================================================
:global cdTemaVer
:if ([:typeof $cdTemaVer] = "nothing") do={ :set cdTemaVer "" }
:local tver ""
:do {
  :local tr [/tool fetch url=("https://captivedata.com.br/api/tema.php?token=$token&roteador=$ident")       check-certificate=no output=user as-value]
  :set tver ($tr->"data")
} on-error={ :set tver "" }

:if ([:len $tver] > 0 && [:len $tver] < 20 && $tver != $cdTemaVer) do={
  :local tok 1
  :do {
    /tool fetch url=("https://captivedata.com.br/api/tema.php?token=$token&roteador=$ident&f=js")         check-certificate=no dst-path="flash/hostsv7/tema.js"
  } on-error={ :set tok 0 }
  # Logo e anuncio podem simplesmente nao existir (404) — isso NAO e falha:
  # o login.html cai no icone Wi-Fi e no "Seu anuncio aqui".
  :do {
    /tool fetch url=("https://captivedata.com.br/api/tema.php?token=$token&roteador=$ident&f=logo")         check-certificate=no dst-path="flash/hostsv7/logo.img"
  } on-error={}
  :do {
    /tool fetch url=("https://captivedata.com.br/api/tema.php?token=$token&roteador=$ident&f=ad")         check-certificate=no dst-path="flash/hostsv7/ad.img"
  } on-error={}
  # So marca a versao quando o tema.js (o essencial) desceu inteiro.
  :if ($tok = 1) do={ :set cdTemaVer $tver }
}

# ============================================================
#  Leads presos no roteador (fila)  — INERTE desde 11/08/2026
#
#  ponytail: este bloco depende de o username da sessao ser "T-<mac>-<telefone>",
#  e o portal voltou a mandar so "T-<mac>" — o RouterOS recusa qualquer outro
#  nome num login por trial, e era essa a causa do ciclo infinito na loja. Sem o
#  telefone no username, o :find abaixo nunca casa e nada e enviado.
#
#  Fica por enquanto porque as sessoes abertas HOJE ainda tem o formato antigo e
#  merecem ser drenadas. Passado o keepalive de todas elas, este bloco pode sair
#  inteiro. Para o numero voltar a sobreviver com a internet da loja fora,
#  precisa de outro carregador que nao seja o username (o RouterOS nao guarda
#  campo livre na sessao) — a alternativa e a fila ficar no proprio navegador.
#  O login.html manda o telefone junto no username da sessao
#  (T-<mac>-<telefone>). Quando a internet do estabelecimento esta fora, o
#  NAVEGADOR do cliente nao alcanca o painel e o lead se perderia — mas o
#  roteador ficou com o numero. Aqui ele e despejado assim que a linha volta.
#  Cada MAC e enviado UMA vez por sessao (a lista global evita repetir a cada
#  rodada); o servidor ainda ignora repeticao dentro de 10 min, por seguranca.
# ============================================================
:global cdLeadsFeitos
:if ([:typeof $cdLeadsFeitos] = "nothing") do={ :set cdLeadsFeitos "" }
:local pend ""
:local pcount 0
:foreach i in=[/ip hotspot active find] do={
  :local u [/ip hotspot active get $i user]
  :local m [/ip hotspot active get $i mac-address]
  # Interessa so "T-<mac>-<telefone>": tem que comecar com T- e ter o 2o hifen.
  :if ([:len $u] > 2 && [:pick $u 0 2] = "T-") do={
    :local resto [:pick $u 2 [:len $u]]
    :local h [:find $resto "-"]
    :if ([:typeof $h] = "num") do={
      :local tel [:pick $resto ($h + 1) [:len $resto]]
      # So manda o que ainda nao foi mandado nesta sessao do roteador.
      :if ([:len $tel] > 9 && [:typeof [:find $cdLeadsFeitos $m]] != "num") do={
        :set pend ($pend . $m . "|" . $tel . ",")
        :set pcount ($pcount + 1)
      }
    }
  }
}
:if ($pcount > 0) do={
  :local lok 0
  :do {
    /tool fetch url="https://captivedata.com.br/api/lead_lote.php" http-method=post check-certificate=no         http-header-field="Content-Type: application/x-www-form-urlencoded"         http-data=("token=$token&roteador=$ident&d=$pend") output=none
    :set lok 1
  } on-error={ :set lok 0 }
  # So marca como enviado se o painel confirmou; senao tenta de novo na proxima
  # rodada — que e justamente o comportamento de fila que se quer aqui.
  :if ($lok = 1) do={
    :foreach i in=[/ip hotspot active find] do={
      :local m [/ip hotspot active get $i mac-address]
      :if ([:typeof [:find $cdLeadsFeitos $m]] != "num") do={ :set cdLeadsFeitos ($cdLeadsFeitos . $m . ",") }
    }
    # Nao deixa a lista crescer sem fim (memoria do script).
    :if ([:len $cdLeadsFeitos] > 3000) do={ :set cdLeadsFeitos "" }
  }
}

# ============================================================
#  Log de acessos (metadados: IP de destino por cliente)
#  Envia ao painel: mapa ip-cliente=MAC (hotspot ativo) + as conexoes ativas
#  (ipCliente>ipDestino). So destinos publicos. O painel deduplica e so o ADMIN
#  ve depois. Empurra por HTTP (nada guardado na flash).
# ============================================================
:local amap ""
:foreach h in=[/ip hotspot active find] do={
  :set amap ($amap . [/ip hotspot active get $h address] . "=" . [/ip hotspot active get $h mac-address] . ",")
}
:local aconns ""
:local acount 0
:foreach cn in=[/ip firewall connection find] do={
  :if ($acount < 400) do={
    :local sa [/ip firewall connection get $cn src-address]
    :local da [/ip firewall connection get $cn dst-address]
    :local sp [:find $sa ":"]
    :local dp [:find $da ":"]
    :local sip $sa
    :local dip $da
    :if ([:typeof $sp] = "num") do={ :set sip [:pick $sa 0 $sp] }
    :if ([:typeof $dp] = "num") do={ :set dip [:pick $da 0 $dp] }
    # ignora destinos privados/locais (10.x, 192.168.x, 127.x) e o proprio roteador
    :if ([:pick $dip 0 3] != "10." && [:pick $dip 0 8] != "192.168." && [:pick $dip 0 4] != "127.") do={
      :set aconns ($aconns . $sip . ">" . $dip . ",")
      :set acount ($acount + 1)
    }
  }
}
:if ([:len $aconns] > 0) do={
  :do {
    /tool fetch url="https://captivedata.com.br/api/acesso.php" http-method=post check-certificate=no \
        http-header-field="Content-Type: application/x-www-form-urlencoded" \
        http-data=("token=$token&roteador=$ident&map=$amap&conns=$aconns") output=none
  } on-error={}
}

# ============================================================
#  BLOCO 8: nome dos sites (cache de DNS do roteador)
#
#  O bloco de cima manda IP de destino, e do IP nao se volta para o site: um IP
#  de CDN atende centenas de milhares de dominios, e o reverse-DNS do painel so
#  consegue dizer "cloudflare". Lista de DNS resolvido baixada da internet nao
#  ajuda — invertida, ela devolve TODOS os dominios daquele IP, nao o que o
#  cliente pediu.
#
#  Quem sabe o nome certo e este roteador, no instante em que o cliente
#  perguntou. Aqui so se le o cache e se manda os pares nome|IP.
#
#  DEPENDE DE UMA COISA: os clientes tem que usar o MikroTik como servidor DNS.
#  Se o DHCP entrega o IP do provedor ou o 8.8.8.8, a pergunta nao passa por
#  aqui e o cache fica vazio.
#      /ip dhcp-server network set [find] dns-server=<IP do MikroTik>
#      /ip dns set allow-remote-requests=yes
#
#  Vai DEPOIS do acesso.php de proposito: o painel so grava o nome de IP que ja
#  esta no log, e quem cria a linha e o bloco de cima. O que nao pegar nesta
#  rodada pega na proxima, enquanto a entrada continuar no cache.
#
#  Teto de 5000 caracteres porque o http-data do /tool fetch corta em algum
#  ponto entre 8 e 64 KB (medido em 11/08/2026, no teste de upload).
:do {
  :local dpares ""
  :foreach dc in=[/ip dns cache find where type="A"] do={
    :if ([:len $dpares] < 5000) do={
      :local dn [/ip dns cache get $dc name]
      :local dd [/ip dns cache get $dc data]
      # ">6" descarta lixo e entrada sem endereco; o menor IPv4 tem 7 caracteres.
      :if ([:len $dn] > 0 && [:len $dd] > 6) do={
        :set dpares ($dpares . $dn . "|" . $dd . ";")
      }
    }
  }
  :if ([:len $dpares] > 0) do={
    /tool fetch url="https://captivedata.com.br/api/dns_nomes.php" http-method=post check-certificate=no \
        http-header-field="Content-Type: application/x-www-form-urlencoded" \
        http-data=("token=$token&roteador=$ident&d=$dpares") output=none
  }
} on-error={}

# ============================================================
#  BLOCO 9: hospedagem — derruba quem ja fez check-out
#
#  Painel de pousada: o hospede so entra se a recepcao cadastrou o numero dele,
#  e a diaria tem hora para acabar. Barrar o login novo NAO resolve sozinho —
#  quem ja esta conectado continua navegando ate desligar o wifi do celular.
#
#  Aqui o roteador manda os MACs conectados AGORA e o painel devolve os que
#  devem cair (saida vencida, ou hospede apagado do painel). Pergunta o roteador,
#  responde a VPS: mesmo caminho do resto, sem tunel e sem IP fixo.
#
#  Roteador de varejo recebe resposta vazia e nada acontece — o hospede_kick.php
#  corta cedo quando a conta nao e de hospedagem.
:do {
  :local hmacs ""
  :foreach ha in=[/ip hotspot active find] do={
    :if ([:len $hmacs] < 4000) do={
      :set hmacs ($hmacs . [/ip hotspot active get $ha mac-address] . ";")
    }
  }
  :if ([:len $hmacs] > 0) do={
    :local kr [/tool fetch url="https://captivedata.com.br/api/hospede_kick.php" \
        http-method=post check-certificate=no \
        http-header-field="Content-Type: application/x-www-form-urlencoded" \
        http-data=("token=$token&roteador=$ident&m=$hmacs") \
        output=user as-value]
    :local fora ($kr->"data")
    # :toarray separa por virgula; resposta vazia vira lista vazia.
    :if ([:len $fora] > 10) do={
      :foreach fm in=[:toarray $fora] do={
        # Um remove por vez, cada um no seu :do — MAC que ja saiu sozinho entre
        # a pergunta e a resposta daria erro e mataria o resto da lista.
        :do { /ip hotspot active remove [/ip hotspot active find where mac-address=$fm] } on-error={}
      }
    }
  }
} on-error={}

# ============================================================
#  Teste de velocidade da internet da LOJA (sob demanda)
#  O comprador pede pelo painel; aqui so se pergunta "tem teste?" a cada
#  rodada — uma resposta de 1 byte, barata.
#
#  QUEM MEDE E ESTE SCRIPT, nao o servidor. Ha um Cloudflare na frente da
#  hospedagem: ele engole a resposta inteira depressa e so entao a repassa para
#  ca, entao o tempo cronometrado do lado do servidor e o do trecho ate o
#  Cloudflare — nao o que chega na loja. O /tool fetch devolve quantos bytes
#  baixou e em quanto tempo (as-value), e sao esses dois numeros que viajam de
#  volta no f=res.
#
#  output=none: os bytes sao descartados conforme chegam, entao nada vai para a
#  flash (a do hEX Gr3 tem 16 MB e vive cheia) nem fica preso na memoria.
# ============================================================
:do {
  :local pedido "0"
  :do {
    :local pr [/tool fetch url=("https://captivedata.com.br/api/speed_rt.php?token=$token&roteador=$ident&f=req") \
        check-certificate=no output=user as-value]
    :set pedido ($pr->"data")
  } on-error={ :set pedido "0" }

  :if ([:len $pedido] > 0 && $pedido != "0") do={
    # O download NAO vem do nosso servidor. Duas razoes:
    #   - a hospedagem e compartilhada e limita a banda de saida, entao o teto
    #     medido seria o dela, nao o do link da loja;
    #   - o Cloudflare na frente dela bufferiza, o que ja tinha estragado a
    #     medicao feita do lado do servidor.
    # speed.cloudflare.com/__down e um endpoint publico de teste de velocidade,
    # servido pelo PoP mais proximo (no Brasil), sem compressao e com tamanho
    # exato. E o que chega perto de "quanto a internet da loja aguenta".
    :local bytesAlvo ($pedido * 1000000)
    :local erro ""

    # ---- Sem TLS -----------------------------------------------------------
    #
    # 11/08/2026: com https, um nucleo bateu 100% enquanto o teste dava 30 Mbps.
    # Nao e o link nem a janela TCP — e este aparelho decifrando TLS em
    # software, numa linha de execucao so. O MT7621 tem aceleracao de cripto
    # para IPsec, e o TLS do /tool fetch NAO usa aquilo.
    #
    # O mesmo /__down da Cloudflare responde em http puro (200, sem redirect —
    # conferido). Trocando o esquema muda SO o TLS: mesmo endpoint, mesmo PoP,
    # mesmo caminho. Se o numero subir, era a cifra.
    #
    # Nao ha risco de privacidade: o corpo e lixo aleatorio de um endpoint
    # publico de teste, sem nada nosso dentro. O que se perde e a garantia de
    # que ninguem no meio adulterou os bytes — para cronometrar tanto faz. O
    # unico efeito colateral possivel e um provedor que faca cache de http, que
    # inflaria a medida; se o numero vier absurdo, e isso.
    :local esq "http"
    :local base ("http://speed.cloudflare.com/__down?bytes=")

    # Custo de abrir a conexao (DNS + TCP + TLS), medido com um download de
    # tamanho zero, no MESMO esquema do teste. Sem descontar isto, um link
    # rapido media errado: 6 MB a 100 Mbps levam 0,5 s, e so o setup ja custa
    # ~0,3 s — o resultado empacava perto de 16 Mbps por construcao.
    #
    # E aqui tambem se descobre se o http passa: se este fetch de 0 byte falhar,
    # cai para https antes de valer alguma coisa.
    :local o0 [:timestamp]
    :do {
      /tool fetch url=($base . "0") check-certificate=no output=none
    } on-error={
      :set esq "https"
      :set base ("https://speed.cloudflare.com/__down?bytes=")
      :do { /tool fetch url=($base . "0") check-certificate=no output=none } on-error={}
    }
    :local over ([:timestamp] - $o0)

    # ---- Por que VARIAS conexoes ao mesmo tempo ----------------------------
    #
    # Uma conexao so media ~40 Mbps num link de 400 (medido com o Ookla, pelo
    # PC, atras deste mesmo roteador). Nao e o roteador que limita: o hEX Gr3
    # (MT7621A, 880 MHz, 2 nucleos / 4 threads) roteia 1802 Mbps de fast path.
    # O que limita e ESTE teste, por dois motivos que somam:
    #
    #   1) janela TCP x distancia. Um unico fluxo TCP nao passa de
    #      janela / RTT. Com ~22 ms ate o PoP da Cloudflare e a janela padrao,
    #      da algo perto de 40 Mbps — que foi exatamente o que apareceu. O
    #      Ookla escolhe um servidor do proprio provedor (RTT de poucos ms) E
    #      abre varios fluxos; por isso ele ve 409.
    #   2) o /tool fetch e uma linha de execucao so, e o TLS e feito em
    #      software. Um fluxo nao usa os 4 threads do aparelho.
    #
    # MEDIDO EM 11/08/2026: quatro conexoes deram 40 MB em 10,3 s = 31 Mbps,
    # contra 26 Mbps de uma conexao so. Quadruplicar os fluxos rendeu 18%.
    # Portanto NAO era a janela TCP: e teto de CPU deste aparelho fazendo TLS em
    # software. Por isso o $cpu abaixo — sem ele a conversa vira chute.
    #
    # $pedido e o tamanho de CADA fluxo, entao o total baixado e ns x pedido.
    # A conta continua a mesma no servidor: ele recebe o total em $bytes.
    :local ns 4
    :local bytes 0
    :local dur "0"
    :local cpu 0
    :local cpu1 0

    :do {
      # :execute solta cada fetch em segundo plano e devolve o job. So existe no
      # RouterOS 7; no 6 isto levanta erro e cai no caminho de uma conexao so,
      # logo abaixo.
      :local base [:len [/system script job find]]
      :local t0 [:timestamp]
      :for i from=1 to=$ns do={
        :execute script=(":do { /tool fetch url=\"$base$bytesAlvo\" check-certificate=no output=none } on-error={}")
      }
      # Espera os jobs acabarem. O teto de 60 s existe para um fetch pendurado
      # nao segurar a rodada inteira do leadsync.
      #
      # E de graca aproveitar esta espera para amostrar a CPU: como os fetch
      # rodam em segundo plano, este laco esta livre. O maximo visto responde a
      # unica pergunta que importa — se o aparelho saturou, o link e mais rapido
      # que o numero medido e o teste chegou ao teto dele. E o mesmo aviso que o
      # /tool speed-test da Mikrotik mostra quando a CPU bate 100%.
      #
      # 11/08/2026: a agregada deu 50% com 4 fluxos. Nao responde sozinha — num
      # MT7621 (2 nucleos x 2 threads) 50% do TOTAL pode ser 2 threads no talo e
      # 2 paradas, que e saturacao no unico lugar que importa. Por isso o
      # $cpu1 tambem: o pico do nucleo MAIS CARREGADO.
      #   um nucleo em 100% -> gargalo de uma linha de execucao so (o /tool
      #     fetch chegou ao teto e nao ha ajuste que resolva);
      #   todos abaixo de ~60% -> nao e o aparelho, e o caminho ate a
      #     Cloudflare, e vale trocar de origem.
      :local guarda 0
      :while ([:len [/system script job find]] > $base && $guarda < 600) do={
        :delay 100ms
        :set guarda ($guarda + 1)
        :do {
          :local c [/system resource get cpu-load]
          :if ($c > $cpu) do={ :set cpu $c }
          :foreach nc in=[/system resource cpu find] do={
            :local l [/system resource cpu get $nc load]
            :if ($l > $cpu1) do={ :set cpu1 $l }
          }
        } on-error={}
      }
      :set dur ([:timestamp] - $t0)
      # Bateu no teto = algum fluxo nao terminou, entao o total baixado e
      # desconhecido e o numero seria inventado.
      :if ($guarda < 600) do={ :set bytes ($ns * $bytesAlvo) }
    } on-error={ :set bytes 0 }

    # Caminho antigo, de uma conexao so: RouterOS 6, ou o paralelo falhou.
    # Melhor um numero baixo que numero nenhum.
    :if ($bytes = 0) do={
      :set ns 1
      :set erro ""
      :local t1 [:timestamp]
      :do {
        /tool fetch url=($base . $bytesAlvo) check-certificate=no output=none
      } on-error={ :set erro "download" }
      :set dur ([:timestamp] - $t1)
      :if ($erro = "") do={ :set bytes $bytesAlvo }
    }

    # Ping ate um destino externo: mede a latencia da internet da loja, nao a
    # do caminho ate o painel.
    #
    # O as-value do /ping NAO devolve avg-rtt — devolve uma LISTA, um item por
    # pacote, cada um com o seu "time". Manda os tempos crus, separados por
    # virgula; a media e feita no servidor.
    #
    # NAO trocar por [:tostr [/ping ...]]: ja foi tentado e derrubou o teste
    # inteiro. O :tostr de uma lista de arrays sai com ";" "=" "{" "}" no meio,
    # e o que sobrava depois de limpar tinha ESPACOS — /tool fetch nao aceita
    # URL com espaco, entao o fetch do resultado morria e o painel ficava sem
    # download E sem ping. O "time" de cada pacote sempre funcionou; quem nao
    # sabia ler o "00:00:00.021866" era o servidor.
    #
    # Dois destinos porque ha provedor que bloqueia um deles.
    :local rtt ""
    :do {
      :foreach r in=[/ping 1.1.1.1 count=3 as-value] do={
        :local t ($r->"time")
        :if ([:typeof $t] != "nothing") do={ :set rtt ($rtt . $t . ",") }
      }
    } on-error={ :set rtt "" }
    :if ([:len $rtt] < 5) do={
      :set rtt ""
      :do {
        :foreach r in=[/ping 8.8.8.8 count=3 as-value] do={
          :local t ($r->"time")
          :if ([:typeof $t] != "nothing") do={ :set rtt ($rtt . $t . ",") }
        }
      } on-error={ :set rtt "" }
    }

    # ---- UPLOAD: NAO DA PARA MEDIR COM /tool fetch. NAO TENTAR DE NOVO. -----
    #
    # A ideia era postar um payload de tamanho conhecido no /__up da Cloudflare
    # e cronometrar. A escada de 11/08/2026 (1 MB, 256 KB, 64 KB, 8 KB, parando
    # na primeira que passasse) respondeu: SO 8 KB PASSOU. O corte esta no
    # http-data do /tool fetch, entre 8 e 64 KB — nao no endpoint, que aceitou
    # os 8 KB numa boa, e nao na montagem da string, que chegou a 1 MB inteira.
    #
    # 8 KB a 30 Mbps passa em 2 ms, dentro do ruido do proprio setup da conexao.
    # Nao ha numero para tirar dali, e as tentativas grandes ainda custavam
    # segundos de teste para falhar. Bloco removido.
    #
    # O que traria o upload de volta, se algum dia valer:
    #   - /tool speed-test (nativo, multi-conexao, TCP e UDP nos dois sentidos)
    #     contra um RouterOS nosso — precisa de CHR numa VPS com licenca paga;
    #   - upload=yes com src-path, que manda um ARQUIVO. Descartado de
    #     proposito: os arquivos da flash sao a logo, o anuncio e o portal do
    #     comprador, e mandar isso para um terceiro so para cronometrar e
    #     entregar dado de cliente sem necessidade nenhuma.
    #
    # O painel esconde o campo de upload sozinho quando nao vem numero.

    :do {
      /tool fetch url=("https://captivedata.com.br/api/speed_rt.php?token=$token&roteador=$ident&f=res&bytes=$bytes&dur=$dur&over=$over&ping=$rtt&conex=$ns&cpu=$cpu&cpu1=$cpu1&esq=$esq&erro=$erro") \
          check-certificate=no output=none
    } on-error={}
  }
} on-error={}
