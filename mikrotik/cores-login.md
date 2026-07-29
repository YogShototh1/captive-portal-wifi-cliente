# Cores avançadas no login.html do hotspot

O painel (aba **Personalizar → avançado**) salva 9 cores por roteador. Elas
chegam ao login.html como `window.CORES` (via `dst.php`, que o login já carrega
para o destino pós-anúncio). Falta o login **aplicar** essas cores nas variáveis
CSS. Duas mudanças, uma vez, no `login.html` que está no flash do MikroTik.

As 4 cores antigas (`primary/accent/bg/fg`) continuam valendo — isto só amplia.

## 1. CSS: deixar o texto do botão usar a cor

No arquivo de estilo do portal (`css/style.css`), na regra `.btn-glass`, troque
a cor fixa do texto por uma variável com o branco como padrão:

```css
/* antes */  .btn-glass{ ... color:#fff; ... }
/* depois */ .btn-glass{ ... color:var(--btn-fg,#fff); ... }
```

(As outras cores já são variáveis: `--bg`, `--surface`, `--primary`, `--accent`,
`--fg`, `--fg-2`, `--fg-3`, `--muted`, `--border`.)

## 2. login.html: aplicar window.CORES nas variáveis

Cole este bloco dentro de um `<script>` no `login.html` (pode ser logo depois do
`<script>` que carrega o `dst.php`). Ele espera as cores chegarem (o `dst.php` é
assíncrono) e as escreve nas variáveis CSS:

```html
<script>
(function () {
  var HEX = /^#[0-9a-fA-F]{6}$/;
  function aplica() {
    var c = window.CORES;
    if (!c) return false;
    var r = document.documentElement.style;
    function set(varName, val) { if (HEX.test(val)) r.setProperty(varName, val); }
    set('--bg', c.bg);
    set('--surface', c.surface);
    set('--primary', c.primary);
    set('--primary-600', c.primary);   // derivados usam a mesma base
    set('--secondary', c.primary);
    set('--ring', c.primary);
    set('--accent', c.accent);
    set('--accent-600', c.accent);
    set('--fg', c.fg);
    set('--fg-2', c.fg2);
    set('--fg-3', c.fg2);
    set('--muted', c.field);
    set('--border', c.border);
    set('--btn-fg', c.btnfg);
    return true;
  }
  // Aplica assim que window.CORES existir (dst.php chega em ~1-2s); desiste após ~6s.
  if (!aplica()) {
    var n = 0, t = setInterval(function () { if (aplica() || ++n > 60) clearInterval(t); }, 100);
  }
})();
</script>
```

## Mapa (chave do painel → variável CSS do login)

| Painel                    | Variável CSS                          |
|---------------------------|---------------------------------------|
| Fundo da tela             | `--bg`                                |
| Cartão                    | `--surface`                           |
| Cor principal             | `--primary` (+ `-600`, `--secondary`, `--ring`) |
| Cor de destaque           | `--accent` (+ `--accent-600`)         |
| Título / texto principal  | `--fg`                                |
| Texto secundário          | `--fg-2`, `--fg-3`                     |
| Campo do número (fundo)   | `--muted`                             |
| Borda dos campos          | `--border`                            |
| Texto do botão            | `--btn-fg`                            |

Depois de salvar o `login.html` no roteador (Winbox → Files), qualquer mudança
de cor no painel passa a valer na hora, sem reenviar o arquivo.
