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
  :local hsids [/ip hotspot find]
  :local hs ""
  :foreach i in=$hsids do={
    :local on "1"
    :if ([/ip hotspot get $i disabled] = true) do={ :set on "0" }
    :set hs ($hs . [/ip hotspot get $i name] . ":" . $on . ",")
  }

  :local ordem "-"
  :do {
    :local hr [/tool fetch url="https://captivedata.com.br/api/hotspot_rt.php" \
        http-method=post check-certificate=no \
        http-header-field="Content-Type: application/x-www-form-urlencoded" \
        http-data=("token=$token&roteador=$ident&hs=$hs") \
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
#  aqui o roteador BAIXA cada arquivo e substitui os de flash/hostsv7. O fetch cria
#  as subpastas sozinho. So grava na flash quando a versao muda -> poupa a flash.
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
#  Leads presos no roteador (fila)
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

    # Custo de abrir a conexao (DNS + TCP + TLS), medido com um download de
    # tamanho zero. Sem descontar isto, um link rapido media errado: 6 MB a 100
    # Mbps levam 0,5 s, e so o setup ja custa ~0,3 s — o resultado empacava
    # perto de 16 Mbps por construcao, que foi o que apareceu no painel.
    :local o0 [:timestamp]
    :do {
      /tool fetch url="https://speed.cloudflare.com/__down?bytes=0" check-certificate=no output=none
    } on-error={}
    :local over ([:timestamp] - $o0)

    :local t0 [:timestamp]
    :do {
      /tool fetch url=("https://speed.cloudflare.com/__down?bytes=$bytesAlvo") \
          check-certificate=no output=none
    } on-error={ :set erro "download" }
    :local dur ([:timestamp] - $t0)

    # Baixou inteiro? Entao sao os bytes pedidos. Se deu erro no meio, nao ha
    # tamanho confiavel e o painel mostra a falha em vez de um numero inventado.
    :local bytes 0
    :if ($erro = "") do={ :set bytes $bytesAlvo }

    # Ping ate um destino externo: mede a latencia da internet da loja, nao a
    # do caminho ate o painel.
    #
    # Duas tentativas ja falharam aqui. Primeiro pedindo ($pg->"avg-rtt"), que o
    # as-value do /ping nao devolve. Depois lendo ($r->"time") de cada pacote,
    # que tambem veio vazio. Como nao da para inspecionar o roteador daqui, esta
    # versao nao aposta em nome de campo nenhum: converte o retorno inteiro em
    # texto e manda para o servidor, que garimpa os tempos com expressao
    # regular. Qualquer que seja o formato, os "12ms300us" estao la dentro.
    #
    # Dois destinos porque ha provedor que bloqueia um deles.
    :local rtt ""
    :do { :set rtt [:tostr [/ping 1.1.1.1 count=3 as-value]] } on-error={ :set rtt "" }
    :if ([:len $rtt] < 5) do={
      :do { :set rtt [:tostr [/ping 8.8.8.8 count=3 as-value]] } on-error={ :set rtt "" }
    }
    # Os separadores do :tostr (; = { }) quebrariam a query string; viram espaco.
    :local plimpo ""
    :local n [:len $rtt]
    :if ($n > 400) do={ :set n 400 }
    :for i from=0 to=($n - 1) do={
      :local c [:pick $rtt $i ($i + 1)]
      :if ($c = ";" || $c = "=" || $c = "{" || $c = "}" || $c = "&" || $c = "+" || $c = "%") do={
        :set plimpo ($plimpo . " ")
      } else={ :set plimpo ($plimpo . $c) }
    }
    :set rtt $plimpo

    :do {
      /tool fetch url=("https://captivedata.com.br/api/speed_rt.php?token=$token&roteador=$ident&f=res&bytes=$bytes&dur=$dur&over=$over&ping=$rtt&erro=$erro") \
          check-certificate=no output=none
    } on-error={}
  }
} on-error={}
