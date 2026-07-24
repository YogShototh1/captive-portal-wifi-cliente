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
      http-data=("token=$token&roteador=$ident&macs=$macs&uso=$uso") \
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
#  pergunta do numero para quem ja tem cadastro — sem falar com o painel
#  no momento da conexao. Painel fora do ar = lista congelada, tudo segue.
# ============================================================
:do {
  :local mres [/tool fetch url=("https://captivedata.com.br/api/macs.php?token=$token&roteador=$ident") \
      check-certificate=no output=user as-value]
  :local mver ($mres->"data")
  :global cdMacsVer
  :if ([:typeof $cdMacsVer] = "nothing") do={ :set cdMacsVer "" }
  :if ([:len $mver] > 0 && [:len $mver] < 16 && $mver != $cdMacsVer) do={
    /tool fetch url=("https://captivedata.com.br/api/macs.php?token=$token&roteador=$ident&f=1") \
        check-certificate=no dst-path="flash/hostsv7/macs.js"
    :set cdMacsVer $mver
  }
} on-error={}
