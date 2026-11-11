# Nivora — sistema de design

Fonte única: `src/public/css/tokens.css`. Os contrastes de cor são verificados por
`tests/Unit/TokensDesignTest.php` (WCAG 2.1 AA, ≥ 4,5:1 para texto).

## Como usar

1. A página carrega `css/tokens.css` antes dos outros CSS. As páginas com menu lateral já o
   recebem pelo `includes/sidebar.php`; as páginas públicas (login, nova senha, esqueci a senha,
   pedir plano) carregam-no no `<head>`.
2. No CSS, use `var(--nv-…)`. Não escreva cores, raios ou sombras em hexadecimal/píxeis soltos.
3. Para a cor do plano use `var(--nv-plano)`. Muda sozinha entre Básico, Profissional e Empresarial.

## Cores

| Token | Uso | Contraste |
|---|---|---|
| `--nv-tinta` | texto principal | 16:1 |
| `--nv-tinta-2` | texto secundário forte | 10:1 |
| `--nv-tinta-suave` | legendas, ajuda, requisitos | 5,9:1 |
| `--nv-placeholder` | placeholder de campos | 4,8:1 |
| `--nv-laranja` | **só decorativo** (ícones grandes, gradientes) | 2,8:1 — não usar em texto |
| `--nv-laranja-texto` | links e texto laranja; fundo de botão com texto branco | 5,2:1 |
| `--nv-sucesso` / `-suave` | OK, guardado | 5,9:1 |
| `--nv-aviso` / `-suave` | a acabar, atenção | 7,1:1 |
| `--nv-perigo` / `-suave` | erro, acabou, apagar | 6,5:1 |
| `--nv-info` / `-suave` | informação | 6,7:1 |

### Cor de cada plano (`--nv-plano`)

| Plano | Cor | Branco por cima |
|---|---|---|
| Básico | `#475569` cinza-azulado | 7,6:1 |
| Profissional | `#0e7490` azul-petróleo | 5,4:1 |
| Empresarial | `#c2410c` laranja | 5,2:1 |

## Tipografia

- Títulos de marca (login, emails): `--nv-fonte-titulo` (Cormorant Garamond).
- Interface: `--nv-fonte-texto` (Manrope).
- Tamanho mínimo de texto informativo: `--nv-texto-xs` (12px). Abaixo disso só elementos decorativos.

## Espaço, raio e sombra

- Espaço em múltiplos de 4px: `--nv-esp-1` (4) … `--nv-esp-10` (40).
- Raio: `--nv-raio-sm` 8 · `--nv-raio-md` 12 (campos, botões) · `--nv-raio-lg` 16 (janelas, cartões grandes).
- Sombra: `--nv-sombra-1` (repouso) · `-2` (hover) · `-3` (janelas).

## Componentes partilhados

| Componente | Ficheiro | Para quê |
|---|---|---|
| Janela de confirmação / pergunta | `js/nivora_dialogo.js` | `nivoraConfirmar()`, `nivoraPerguntar()` — substitui `confirm()`/`prompt()` |
| Notificações | `js/feedback.js` + `css/feedback.css` | `NivoraFeedback.success/error/…`, com botão "Desfazer" opcional |
| Autorização do gerente | `js/autorizacao_gerente.js` | PIN do gerente para ações sensíveis |

## Regras de acessibilidade

- Botão desativado continua legível (≥ 4,5:1) e com sinal extra (contorno tracejado), não só "mais pálido".
- Foco sempre visível: `box-shadow: var(--nv-foco)` ou `outline` equivalente.
- Estado nunca só por cor: a etiqueta tem texto ("Acabou", "OK").

## Imagens

- Fotos de fundo: AVIF + WebP + JPG de reserva com `image-set()` (ver `css/auth-landing.css`).
  O hero passou de 311 KB (JPG) para 68 KB (AVIF) / 95 KB (WebP).
- Nada de imagens de fundo vindas de sites externos (ex.: Unsplash): ficam em `src/public/images/`.

## Migração (o que falta)

Os CSS antigos (`style.css`, `premium.css`, `empresarial.css`, …) ainda têm cores próprias.
Ao mexer numa página, troque as cores dela pelos tokens. Não é preciso fazer tudo de uma vez.

## JavaScript e segurança (CSP)

- A política de segurança **não permite JavaScript escrito no HTML** (`'unsafe-inline'` saiu de `script-src`).
- Um `<script>` dentro de uma página PHP tem de levar o nonce:
  `<script nonce="<?php echo security_csp_nonce(); ?>">`. O teste `tests/Unit/CspScriptsTest.php` falha se faltar.
- Os `onclick="…"` existentes continuam a funcionar através de `js/nv_acoes.js` (carregado no `<head>`
  de todas as páginas): é um interpretador pequeno e seguro, sem `eval`. Em código novo, prefira
  `addEventListener` num ficheiro `.js`.
- Escape de emergência (só se algo partir em produção): variável `CSP_SCRIPT_UNSAFE_INLINE=1` no `.env`.

## Imagens enviadas

Logotipos, fotos de produtos e de utilizadores e comprovativos em imagem são reduzidos ao guardar
(`app/src/config/imagem_otimizar.php`): no máximo 512 px (logos, perfis), 1200 px (produtos) ou 1600 px.
