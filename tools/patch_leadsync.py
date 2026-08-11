# -*- coding: utf-8 -*-
"""Insere no leadsync.rsc o bloco de auto-atualizacao e o report de versao.

Rodar uma vez:  python tools/patch_leadsync.py
E idempotente: se ja estiver aplicado, nao faz nada.
"""
import io
import sys

P = 'mikrotik/leadsync.rsc'

BLOCO0 = """# ============================================================
#  BLOCO 0 - auto-atualizacao deste script
#  Roda ANTES de tudo e nunca deixa erro escapar. Enquanto este pedaco viver, o
#  painel consegue corrigir qualquer coisa no roteador sem visita - inclusive um
#  erro neste proprio arquivo. Nao havia isso antes: uma vez instalado, o script
#  so mudava indo ate o local.
#
#  O painel responde qual versao do leadsync deve rodar. Se for diferente da que
#  esta em memoria, baixa por cima de flash/leadsync.rsc; o scheduler
#  (/import flash/leadsync.rsc) executa a versao nova na proxima volta.
# ============================================================
:global cdSyncVer
:if ([:typeof $cdSyncVer] = "nothing") do={ :set cdSyncVer "" }
:do {
  :local sv ""
  :do {
    :local svr [/tool fetch url=("https://captivedata.com.br/api/leadsync.php?token=$token&roteador=$ident") \\
        check-certificate=no output=user as-value]
    :set sv ($svr->"data")
  } on-error={ :set sv "" }
  :if ([:len $sv] > 0 && [:len $sv] < 20 && $sv != $cdSyncVer) do={
    :do {
      /tool fetch url=("https://captivedata.com.br/api/leadsync.php?token=$token&roteador=$ident&f=1") \\
          check-certificate=no dst-path="flash/leadsync.rsc"
      :set cdSyncVer $sv
    } on-error={}
  }
} on-error={}

"""

ALVO = ':local ident [/system identity get name]\n'

VELHO_POST = 'http-data=("token=$token&roteador=$ident&macs=$macs&uso=$uso") \\'
NOVO_POST = (
    'http-data=("token=$token&roteador=$ident&macs=$macs&uso=$uso" . \\\n'
    '                 "&rosver=" . [/system resource get version] . \\\n'
    '                 "&board=" . [/system resource get board-name]) \\'
)


def main():
    s = io.open(P, encoding='utf-8').read()
    mudou = False

    if 'cdSyncVer' not in s:
        if ALVO not in s:
            print('ERRO: nao achei a linha do ident'); return 1
        i = s.index(ALVO) + len(ALVO)
        s = s[:i] + '\n' + BLOCO0 + s[i:]
        mudou = True
        print('  + bloco 0 (auto-atualizacao)')

    if 'rosver' not in s:
        if VELHO_POST not in s:
            print('ERRO: nao achei a linha do http-data'); return 1
        s = s.replace(VELHO_POST, NOVO_POST)
        mudou = True
        print('  + report de versao/modelo do RouterOS')

    if mudou:
        io.open(P, 'w', encoding='utf-8', newline='\n').write(s)
        print('leadsync.rsc atualizado')
    else:
        print('nada a fazer (ja aplicado)')
    return 0


if __name__ == '__main__':
    sys.exit(main())
