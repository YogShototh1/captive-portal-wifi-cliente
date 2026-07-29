# Portal do hotspot — arquivos que vão no MikroTik

Estes dois arquivos são a fonte da verdade do visual da tela de login:

| Arquivo      | Vai para (no roteador)         | Também usado em |
|--------------|--------------------------------|-----------------|
| `login.html` | `hotspot/login.html`           | —               |
| `style.css`  | `hotspot/css/style.css`        | `assets/portal.css` (prévia do painel) |

`style.css` é **cópia idêntica** de `assets/portal.css`. A prévia do painel
(aba Personalizar → avançado) carrega esse mesmo CSS, então o que aparece lá é
o que o cliente vê no Wi-Fi. Ao mexer no visual, altere `assets/portal.css` e
copie para cá.

## O que o login.html consome do painel

Tudo chega via `dst.php` (já no Walled Garden), sem reenviar arquivo ao roteador:

- `window.CORES`  — 9 cores → variáveis CSS (`--bg`, `--surface`, `--primary`,
  `--accent`, `--fg`, `--fg-2`/`--fg-3`, `--muted`, `--border`, `--btn-fg`).
- `window.ESTILO` — 7 efeitos. Cada um em 0 vira a classe `cd-no-<nome>` no
  `<html>`, e o CSS desliga o efeito:
  `vidro`, `brilho`, `manchas`, `grade`, `sombra`, `anim`, `grad`.

Depois de subir os dois arquivos uma vez (Winbox → Files), qualquer mudança de
cor ou de efeito no painel passa a valer na hora.
