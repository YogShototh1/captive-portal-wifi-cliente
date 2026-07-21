# Captive Portal — Backend + Painel (PHP / HostGator compartilhado)

Versão em **PHP + MySQL** para rodar em **hospedagem compartilhada** (cPanel),
24/7, sem VPS e sem depender do seu notebook. Faz o mesmo que a versão FastAPI:
recebe os leads do captive portal e serve o painel de cada comprador.

> O portal (`login.html`, `css/`, `anuncio.*`) continua indo para o **MikroTik**.
> Esta pasta (`backend-php/`) vai para a **hospedagem**.

## O que tem aqui

```
backend-php/
├── index.php            # casca: mantém a URL sempre em "seudominio.com.br" (app roda no iframe)
├── entrar.php           # tela de login (única). Admin -> admin.php; cliente -> painel.php
├── painel.php           # cliente: leads só do roteador dele
├── admin.php            # admin: lista de contas + criar/editar/excluir + ver leads
├── admin_form.php       # admin: formulário criar/editar usuário
├── admin_excluir.php    # admin: excluir usuário
├── admin_leads.php      # admin: leads de um cliente específico
├── sair.php             # logout
├── api/lead.php         # endpoint público (POST do login.html)
├── assets/style.css     # visual
├── inc/                 # config, conexão, validação, auth, util (bloqueado via .htaccess)
├── sql/schema.sql       # tabelas
├── tools/               # criar_comprador (1º admin), gerar_hash, limpar_antigos (LGPD)
├── .htaccess            # HTTPS, URL limpa /api/lead, proteção das pastas
└── README.md
```

**Perfis:** cada conta tem `is_admin`. O **admin** (você) gerencia contas e, por sua
decisão, também vê os leads de todos (isso amplia sua responsabilidade sob a LGPD).
Cada **cliente** vê apenas os leads do roteador vinculado à conta dele.

## Passo a passo na HostGator

**1. Contrate** um plano de **Hospedagem Compartilhada** + domínio.

**2. Crie o banco** no cPanel → *Bancos de Dados MySQL*: crie um banco, um usuário,
   associe o usuário ao banco com **todos os privilégios**. Anote nome/usuário/senha
   (costumam vir com prefixo, ex.: `cpaneluser_captive`).

**3. Importe as tabelas**: cPanel → *phpMyAdmin* → selecione o banco → aba *Importar*
   → envie `sql/schema.sql` (ou cole o conteúdo na aba *SQL*).

**4. Configure**: copie `inc/config.example.php` para `inc/config.php` e preencha
   as credenciais do banco e um `admin_token` aleatório.

**5. Envie os arquivos**: pelo *Gerenciador de Arquivos* (ou FTP), suba o conteúdo
   desta pasta para `public_html/` (ou uma subpasta, ex.: `public_html/painel/`).
   O `.htaccess` já bloqueia o acesso web às pastas `inc/` e `sql/`.

**6. HTTPS**: a HostGator ativa o SSL automático (AutoSSL). Confirme que o cadeado
   aparece em `https://seudominio.com.br`.

**7. Crie o PRIMEIRO admin** (você). Como ainda não há ninguém para logar, crie por
   fora — depois todo o resto é pela tela de administração. Duas formas:
   - **Terminal** (se o plano tiver): `php tools/criar_comprador.php "Seu Nome" voce@ex.com suasenha - admin`
   - **Sem terminal**: abra `https://seudominio.com.br/tools/gerar_hash.php?token=SEU_ADMIN_TOKEN&senha=suasenha`
     (o token é o `admin_token` do `inc/config.php`),
     copie o hash; no phpMyAdmin → tabela `compradores` → *Inserir*: `nome`, `email`,
     `senha_hash` = o hash, deixe `roteador_id` **vazio/NULL**, e `is_admin` = **1**.
     **Depois apague `tools/gerar_hash.php`.**

**8. Acesse e use o painel**: `https://seudominio.com.br` → login.
   - **Você (admin)** cai na **tela de administração**: cria/edita/exclui usuários e
     vê os leads de qualquer cliente. Para cada cliente, crie a conta ali definindo o
     `roteador (identity)` = o `$(identity)` do MikroTik dele.
   - **Cada cliente** loga e vê **só os leads do roteador dele**.

**9. Ligue o portal ao servidor**: no `login.html` (que vai pro MikroTik):
   ```js
   var API_LEAD_URL = "https://seudominio.com.br/api/lead";
   ```
   (pode remover a linha do header `ngrok-skip-browser-warning` — não é mais preciso).
   E no MikroTik:
   ```
   /ip hotspot walled-garden
   add dst-host=seudominio.com.br action=allow
   ```

**10. LGPD (retenção)**: cPanel → *Cron Jobs*, agende 1x/dia:
   ```
   php /home/USUARIO/public_html/tools/limpar_antigos.php
   ```
   (apaga leads com mais de 6 meses — ajuste em `tools/limpar_antigos.php`).

## Endpoint `POST /api/lead`

Igual à versão anterior — o `login.html` não muda em nada além da URL.
Request JSON: `{ telefone, mac, ip, roteador, consentimento }` → resposta `201 {"ok":true,"id":N}`.
Entrada inválida → `422`; excesso → `429`.

Teste rápido (depois de subir):
```bash
curl -X POST https://seudominio.com.br/api/lead \
  -H "Content-Type: application/json" \
  -d '{"telefone":"11987654321","mac":"AA:BB:CC:DD:EE:FF","roteador":"PRIMIX-LOJA-01","consentimento":true}'
```

## Segurança
- `.htaccess` na raiz: força HTTPS, bloqueia o acesso web a `inc/`, `sql/` e
  `mikrotik/`, a arquivos ocultos e a extensões sensíveis (`.sql`, `.rsc`, `.md`,
  `.dst`, `config.php`...), aplica cabeçalhos de proteção (nosniff, anti-clickjacking,
  CSP, HSTS, noindex) e cria a URL limpa `/api/lead`.
  **Atenção ao subir por FTP: arquivos `.htaccess` são ocultos — confirme que os
  quatro subiram** (raiz, `inc/`, `sql/`, `mikrotik/`; `ads/` já tem o dele).
- Login com proteção contra força bruta: 8 falhas por IP em 15 min bloqueiam novas
  tentativas (tabela `login_tentativas`, criada automaticamente pelo código).
- Senhas com `password_hash` (bcrypt) e verificação em tempo constante (não dá para
  descobrir por timing quais e-mails têm conta). Sessão com cookie
  HttpOnly/Secure/SameSite, `use_strict_mode` e ID trocado no login. CSRF nos formulários.
- Ferramentas de `tools/` só funcionam pela web com o `admin_token` (inclusive
  `gerar_hash.php`). Apague `gerar_hash.php` após usar. Mantenha o token secreto —
  ele também está dentro de `mikrotik/leadsync.rsc`; não compartilhe esse arquivo.
- Erros do PHP nunca aparecem na tela (vão para o log do servidor).
- Ainda assim, o ideal em cPanel é mover `inc/` para **fora** de `public_html`
  (um nível acima) — opcional.

## Página de login do hotspot pelo painel
Na tela de leads de um cliente (admin) há o bloco **"Página de login do hotspot"**:
envie um **.zip** com o template (`login.html`, `css/`, `img/`, `xml/`…). O servidor
extrai (substituindo tudo) e o MikroTik baixa sozinho para `flash/hostsv7`, criando as
subpastas e trocando os arquivos (em até ~1 min). Também dá para enviar um **arquivo
avulso** (ex.: só `login.html`) para trocar só ele. Como o servidor compartilhado não
alcança o roteador, é o roteador que **puxa** os arquivos (igual ao `status.php`).

- Só aceita tipos de página (html/css/js/imagens/xml/xsd/fontes); `.php` e ocultos são
  ignorados. Tetos anti zip bomb: 2 MB por arquivo, 20 MB e 300 arquivos por zip.
- Requer a extensão **ZipArchive** no PHP (padrão na HostGator).

- **Quem edita:** o **admin** sempre pode editar a página de login de qualquer cliente
  (pela tela de leads dele). O **cliente** só vê e usa esse bloco no painel dele se a
  conta tiver a opção *"Liberar upload da página de login do hotspot"* marcada em
  **Administração → Editar / Novo usuário** — vem **desligada** por padrão.
- **Banco já existente?** rode `sql/migracao_portal.sql` UMA vez no phpMyAdmin
  (adiciona a coluna `portal_habilitado`). Bancos novos já vêm pelo `schema.sql`.
- O `leadsync.rsc` já faz esse pull no fim do script — **reenvie o `.rsc` atualizado**
  para o roteador após esta mudança.
- Só grava na flash quando algum arquivo muda (versão comparada por rodada) — poupa a flash.
- A pasta `flash/hostsv7` é criada pelo próprio `fetch` (não precisa existir antes).
  Ela deve bater com o `html-directory` do perfil do hotspot
  (`/ip hotspot profile set [find] html-directory=hostsv7`).
- Nada de novo no Walled Garden: quem baixa é o próprio roteador, não o cliente.

## Próximo (passo 7 — tempo de conexão, adaptado ao compartilhado)
O servidor compartilhado não abre túnel para consultar o MikroTik. A gente inverte:
o **MikroTik envia** o status (conectou/desconectou) para um endpoint `api/status.php`,
usando o *scheduler* do RouterOS. Fica sem túnel. (A implementar quando você quiser.)
