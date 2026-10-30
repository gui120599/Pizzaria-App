# Redesign do Cardápio Online — Empório da Pizza (RazelFood)

> **Status:** Fase 1 (auditoria e proposta) concluída em 2026-10-29. **Nenhum código foi alterado.**
> A Fase 2 (implementação) só começa depois da aprovação deste documento.
>
> Este documento basta para implementar sem refazer a auditoria. Itens marcados
> **[Comprovado]** têm evidência (código, medição ou captura de tela). Itens marcados
> **[Hipótese]** precisam de validação antes de virar decisão.

---

## 0. Resumo executivo

O cardápio funciona e tem regras de negócio maduras (sabores, promoção relâmpago,
oferta "leve outro", perguntas, adicionais, cupom, frete mínimo, horário, mesa por QR).
A apresentação, porém, não está à altura dessas regras, e há três problemas que
**custam pedidos hoje**, antes mesmo da estética:

1. **Peso: cerca de 39 MB de imagens na abertura** [Comprovado]. São 201 imagens
   carregadas de uma vez, nenhuma com `loading="lazy"`. O logo tem 6912×3888 px
   (1,2 MB) e aparece com 64 px de altura. Em 4G isso significa dezenas de segundos
   e consumo do plano de dados do cliente.
2. **Depois do "Confirmar e pedir" não há tela de confirmação** [Comprovado no código].
   O pedido é gravado, o carrinho é limpo e tudo depende de um `window.open()` para o
   WhatsApp, chamado *depois* de um `await fetch`. Navegadores móveis costumam bloquear
   esse tipo de pop-up [Hipótese, no iOS Safari]. Se isso acontecer, o cliente fica
   sem mensagem e sem número do pedido, e a loja recebe um pedido `INICIADO` que
   ninguém confirmou.
3. **Loja fechada só aparece depois que o cliente tenta comprar** [Comprovado em
   captura]. Nada no topo informa o horário. O cliente toca em "+" e cai num carrinho
   vazio com "Estamos fechados".

**Recomendação:** fazer uma evolução incremental, sem reescrever. A lógica do carrinho
(`Alpine.store('cart')`), as rotas e os contratos com o servidor ficam como estão. A
camada visual é refeita com componentes unificados e um layout próprio do cardápio. A
ordem é: desempenho e segurança de conversão primeiro (Etapas 0–1), depois identidade,
navegação e cards (2–4), depois personalização, carrinho e finalização (5–7). São oito
etapas pequenas, cada uma publicável sozinha.

---

## 1. Escopo, fontes e limitações da auditoria

### 1.1 O que foi analisado
- **Produção** (`https://emporiodapizzago.com.br/Cardapio`), em 29/10/2026 de madrugada,
  com a loja fechada: HTML baixado, peso de todas as imagens medido e capturas com
  Chromium (Playwright) em **390×844 (celular, DPR 2)** e **1440×900 (desktop)**.
- Fluxo navegado em produção, **sem enviar pedido**: primeira dobra → rolagem → seção
  Pizzas → modal de sabores (inteira e meia a meia) → montagem → carrinho com 2 itens →
  etapa "Seus dados". Como a loja estava fechada, o horário foi simulado só no navegador
  da captura (`estaAbertoAgora = () => true`). Nada foi gravado no servidor e o
  `localStorage` foi limpo no fim.
- **Código local** (branch `prod-new`): controller, service, view, componentes,
  checkout, modelo `Produto`, `PrecificadorService` (indiretamente), testes, layout,
  CSS/JS e Vite.
- **Banco local** (só leitura): composição do catálogo, categorias, entrega e pagamento.
- **Renderização local**: 46 consultas SQL e ~1,3 s de renderização da view no servidor.

### 1.2 Limitações
- **Nenhum pedido real foi enviado.** O comportamento do `window.open` pós-checkout em
  iOS/Android não foi testado em aparelho físico (é a Hipótese H1).
- Não há acesso à configuração do servidor de produção (nginx/apache, cache, CDN) nem
  a dados de analytics, abandono ou conversão. Hipóteses de conversão não podem ser
  medidas por enquanto.
- O banco local tem **0 perguntas ativas**. O modal de montagem foi auditado pelo código
  e não com dados reais de produção.
- As fotos locais (`storage/app/public/fotos_produtos`, 1.120 arquivos, 107 MB) são
  diferentes das de produção. As medições de peso usam **produção**.

---

## 2. Mapa técnico (como o cardápio realmente funciona)

### 2.1 Stack do cardápio
| Camada | O que é usado |
|---|---|
| Renderização | Blade server-side, sem SPA |
| Interatividade | **Alpine.js do Livewire** (via `@livewireScripts` no layout). `resources/js/app.js` **não** inicia Alpine; stores registradas em `alpine:init` |
| CSS | Tailwind **v4** via `@tailwindcss/vite`, entrada `resources/css/app.css` (`@theme` só define a fonte). `package.json` ainda lista `tailwindcss ^3`, resquício já conhecido |
| Ícones | Boxicons (CSS importado de `/node_modules/boxicons`) |
| Fonte | Figtree 400/500/600 via fonts.bunny.net |
| Persistência do carrinho | `localStorage` (`cardapio_cart`, ou `mesa_cart_{codigo}` na mesa) |
| Imagens | `Produto::getImagemUrl()` → `Storage::disk('public')->url()`, com fallback `public/Sem Imagem.png`. Sem miniaturas. `intervention/image ^3.10` **já é dependência direta** e o GD do Sail tem WebP |

### 2.2 Arquivos envolvidos
| Arquivo | Papel | Linhas |
|---|---|---|
| `routes/web.php:64-73` | `GET /Cardapio` (`cardapio`), `GET /cardapio/lookup-cliente`, `POST /cardapio/checkout` (throttle), `POST /cardapio/cupom/validar` (throttle), `GET /acompanhar/{id}` | — |
| `app/Http/Controllers/CardapioController.php` | `view('cardapio', $service->dados() + ['mesaCliente' => null])` | 14 |
| `app/Services/CardapioService.php` | **Fonte única dos dados** (delivery e mesa): categorias com filhas, top 10, relâmpago, oferta adicional, promoções, mais vendidos, entrega, pagamento, sabores por categoria, perguntas, adicionais, horário, links de avaliação | 317 |
| `app/Http/Controllers/CardapioCheckoutController.php` | Checkout, cupom e lookup de cliente. **O servidor reprecifica tudo** (`LancamentoItemPedidoService::precificar`) e nunca aceita preço do navegador | 380 |
| `app/Http/Controllers/MesaClienteController.php:43-51` | **Reusa a mesma view `cardapio`** com `mesaCliente` preenchido | — |
| `resources/views/cardapio.blade.php` | Página inteira: cabeçalho, seções, drawer de carrinho/checkout, modais (troco, sabores, oferta, montagem), botão de avaliações e **~680 linhas de JS inline** (store `cart`) | 1.910 |
| `resources/views/components/cardapio/produto-card.blade.php` | Card de produto das categorias | 60 |
| `resources/views/components/cardapio/promo-relampago-card.blade.php` | Card de relâmpago (quase cópia do anterior) | 54 |
| `resources/views/components/cardapio/promo-adicional-card.blade.php` | Card de oferta (quase cópia) | 57 |
| `resources/views/components/categoria-button.blade.php` | Atalho circular de categoria (usa a 1ª foto de produto) | 31 |
| `resources/views/mesa-cliente/{faixa,botao-painel,enviar,modais}.blade.php` | Inclusões condicionais da mesa e `Alpine.store('mesa')` | 406 |
| `resources/views/layouts/guest.blade.php` | Layout compartilhado com **login, registro, erros 403/404, acompanhamento e produto** | 61 |
| `resources/views/app/produto/show.blade.php` + `ProdutoController::show` | Página `/Produto/{id}`, destino do toque no card | 88 |

### 2.3 Fluxo de dados e preço
1. `CardapioService::dados()` monta coleções Eloquent. Cada card chama
   `precoResolvido()` (memoizado; `PrecificadorService` é singleton, testado contra N+1).
2. A view serializa para JS: `_categoriasComSabores`, `_perguntasProdutos`,
   `_perguntasCombos`, `_adicionaisProdutos`, `_promocoesAdicionais`, `_opcoesEntregas`,
   `_opcoesPagamento`, `_horarios` e outros.
3. O carrinho calcula no cliente a **prévia**: preço de meia a meia (`MEDIA` em centavos
   ou `MAIOR`), acréscimos de montagem, ofertas, desconto, frete (`valor_frete` se
   `total - cupom < min_frete`).
4. O checkout envia **o que** o cliente quer (ids, sabores, respostas, adicionais,
   `oferta_produto_id`). O servidor reprecifica, valida estoque e limites, aplica cupom
   com lock, calcula frete e grava o pedido `INICIADO`, origem `CARDAPIO`.
5. O cliente monta a mensagem e abre `wa.me/5564981380071` (número fixo no JS).

### 2.4 Contratos que o redesign NÃO pode quebrar
- **API do store**: `$store.cart.add(id, nome, preco, precoOriginal, foto)`,
  `abrirSabores(categoriaId, categoriaNome, maxSabores, produtoId)`, `decrement`, `qty`,
  `drawerOpen`, `step`, `form.*`, `cupom.*`, `submitCheckout()`. A mesa
  (`mesa-cliente/*`) usa `$store.cart.items`, `estaAbertoAgora()` e `drawerOpen`.
- **Formato dos itens no `localStorage`** (`cartKey`, `id`, `nome`, `preco`,
  `precoOriginal`, `qty`, `foto`, `obs`, `sabores`, `respostas`, `respostasTexto`,
  `adicionais`, `adicionaisTexto`, `oferta`, `_promoBackup`). Clientes voltam com
  carrinho salvo. Mudar o formato exige migração no `JSON.parse`.
- **Payload do checkout e do cupom** (seção 2.3, passo 4). Nenhum campo novo
  obrigatório.
- **Âncoras e textos que os testes verificam**:
  - `id="categoria_{id}"` na pai e nas filhas, **com a pai antes da filha**
    (`CardapioCategoriaHierarquiaTest`)
  - textos `RELÂMPAGO` e `restam` (`CardapioPromocaoRelampagoViewTest`)
  - `$store.cart.abrirSabores(` no HTML
  - `preco":39.9` e `precoFracao":55` (serialização `@js` de
    `_categoriasComSabores`)
  - `const _promocoesAdicionais`
  - `viewData`: `campanhasAdicionaisAtivas`, `promocoesAdicionais`
- **`?abrir=carrinho`** (usado por `/Produto/{id}`) e **`?cupom=`** (links de
  influenciador).
- **Modo mesa**: as mesmas telas com `mesaCliente != null`. A faixa da mesa é
  `sticky top-0` dentro do container que rola, e os botões trocam ("Meu Pedido",
  "Enviar pedido").

---

## 3. Catálogo real (o que o design precisa acomodar)

Dados do banco local, coerentes com o HTML de produção:

- **241 produtos visíveis**. Em produção, ~13 aparecem com o placeholder "Sem Imagem"
  e **22 têm `produto_codimentacao` vazio** (bebidas, na maioria).
- **Categorias de topo**: Pizzas, Bebidas, Pastel, Panelinha, Sanduíche, Sorvetes (e
  Tábua de Frios, sem produtos).
- **Pizzas não têm seletor de tamanho.** Cada tamanho é uma **subcategoria**: Grande
  (30), Quadrada (29), Brotinho (29, **sem meia a meia**) e Média (30). São **~118 cards
  de pizza**, e o mesmo sabor aparece 4 vezes.
- **Bebidas** têm 8 subcategorias (Refrigerante 20, Vinho 10, Suco Polpa 8, Cerveja 8…).
- **Regra de preço** das 3 categorias com sabores: `MEDIA`. Opções de quantidade
  vistas em produção: só "Meia a meia" (2).
- **Descrições** (`produto_codimentacao`) chegam a 134 caracteres, ex.: "Arroz, Carne
  Seca Desfiada, Temperos da Casa, Molho de Tomate, Caldo de Carne, Mussarela, Catupiry,
  Tomate Cereja e Pimenta de Cheiro."
- **Caixa inconsistente** nos nomes ("4 Queijos" e "4 queijos"; "A moda" e "A Moda").
  Hoje o `uppercase` do CSS esconde isso.
- **Entrega**: "Entregar" R$ 4,00, grátis a partir de R$ 50,00. "Retirada na loja"
  grátis. "Comer no Local" é filtrado.
- **Pagamento no cardápio**: Dinheiro, Cartão Débito, Cartão Crédito e Pix QRCode.
- `produto_destaque_mais_vendidos` está ligado em 183 dos 241 produtos. Na prática, o
  que decide o destaque é `produto_qtd_vendas`: top 10 no selo, top 8 na seção.

---

## 4. Diagnóstico

Cada item traz: **evidência → impacto → melhoria**. As capturas citadas estão na
seção 12.

### 4.1 Impacto visual e identidade
| # | Problema | Evidência | Impacto | Melhoria |
|---|---|---|---|---|
| V1 | **Sem sistema visual: cada seção tem uma cor** (amarelo, laranja, rosa, vermelho, teal, verde) | Comprovado: classes `yellow-*`, `orange-*`, `pink-*`, `red-*`, `teal-500`, `green-500` em `cardapio.blade.php` e componentes | Aparência de template montado. As cores competem com as fotos e nada parece prioritário | Paleta com função definida (seção 5.2). Promoção tem uma cor só; o resto é neutro e quente |
| V2 | **Logo cortado e sem respiro**: encosta na borda esquerda e divide a linha com um botão teal que não pertence à marca | Comprovado: captura `m1`. `img h-16` sem margem; `x-primary-button` (teal) | Primeira impressão amadora; o botão parece de outro sistema | Cabeçalho próprio (seção 6.A) |
| V3 | **Cabeçalho ocupa 26% fixos da tela**, com uma linha `<hr>` cinza solta | Comprovado: `h-[26%]` + `hr bg-gray-400` | Em 844 px, ~220 px sem produto | Barra compacta de 56–64 px que encolhe ao rolar |
| V4 | **Nomes de produto em CAIXA ALTA**, peso regular, 18 px | Comprovado: `text-lg uppercase` | Caixa alta é mais lenta de ler, quebra em mais linhas ("PIZZA GRANDE A MODA EMPÓRIO DA PIZZA" ocupa 2 linhas) e reduz a hierarquia | Caixa normal, peso 600, com a fonte de títulos para nome de seção |
| V5 | **Rótulo "Codimentos:"** (erro de grafia de "Condimentos") antes de toda descrição, inclusive vazia | Comprovado: captura `m6` (bebidas mostram "Codimentos:" vazio); 22 produtos sem texto | Erro de português visível em todos os cards; ruído | Mostrar a descrição sem rótulo e omitir quando vazia |
| V6 | **Título da página "RazelFood"**, sem `meta description` nem Open Graph | Comprovado: `<title>RazelFood</title>`; 0 tags `og:` | Aba e link compartilhado no WhatsApp/Instagram sem nome da pizzaria e sem imagem | Layout próprio com `<title>`, descrição, `og:image` e `theme-color` |
| V7 | **Selos repetitivos**: "+ VENDIDO" em todo card da seção Mais Vendidos | Comprovado: captura `m1` | O selo perde sentido | Na vitrine, um ranking discreto (nº 1, nº 2…, verdadeiro pela ordenação) e nenhum selo repetido |

### 4.2 Usabilidade e experiência de compra
| # | Problema | Evidência | Impacto | Melhoria |
|---|---|---|---|---|
| U1 | **Sem tela de sucesso após o pedido**; tudo depende de `window.open` depois de `await` | Comprovado no código: `submitCheckout()` faz `window.open(...)`, `clear()` e fecha o drawer. Bloqueio do pop-up é **H1** | Pedido gravado sem confirmação ao cliente; WhatsApp não abre; cliente pode pedir de novo (duplicado) ou desistir | Tela "Pedido nº N recebido" com botão-link **Enviar no WhatsApp** (`<a href>`, não depende de pop-up), link de acompanhamento e resumo. Carrinho limpo só nessa tela |
| U2 | **Loja fechada só aparece depois do toque em "+"** | Comprovado: captura `m4` (carrinho vazio + "Estamos fechados"). `add()` abre o drawer quando fechado | Frustração; o cliente acha que o botão falhou | Status no cabeçalho ("Aberto até 23h" / "Fechado · abre hoje às 18h"), aviso discreto no topo e, ao tocar "+" fechado, mensagem curta explicando |
| U3 | **Seção de pizza com ~118 cards**, o mesmo sabor 4 vezes, e o nome repete a subcategoria ("PIZZA GRANDE" > "PIZZA GRANDE 4 QUEIJOS") | Comprovado: banco + captura `m3` | Rolagem longa; comparar tamanhos é difícil | (a) Imediato: dentro da subcategoria, título = só o sabor (`produto_descricao`); subcategorias como abas fixas. (b) Opcional (seção 9, decisão D3): "escolha o tamanho primeiro" |
| U4 | **Sem busca** | Comprovado | Quem quer "calabresa" ou "coca" rola 241 itens | Busca no cliente, por nome e ingredientes, sobre os cards já renderizados |
| U5 | **Modal de sabores sem ingredientes e sem busca** (30 sabores) | Comprovado: captura `m5`; payload `_categoriasComSabores` não leva `produto_codimentacao` | Para escolher o 2º sabor o cliente precisa decorar o que viu na lista | Mostrar ingredientes (1 linha) e campo de busca; incluir o campo no payload |
| U6 | **Toque no card leva para outra página** (`/Produto/{id}`), que **ignora as regras do cardápio** | Comprovado: `show.blade.php` calcula preço por `produto_preco_promocional` (sem relâmpago, sem janela de datas, sem `precoResolvido()`) e adiciona ao carrinho **sem perguntas, adicionais nem sabores**. Não verifica `visivelCardapio` | Preço exibido pode divergir do cobrado; pizza "inteira" entra sem passar pelo fluxo; produto oculto fica acessível pelo ID | Detalhe do produto em **bottom sheet dentro do cardápio**, usando o mesmo `add()`/`abrirSabores()`; `/Produto/{id}` redireciona para `/Cardapio?produto={id}` (decisão D4) |
| U7 | **Dois CTAs de carrinho** ("Realizar pedido" no topo + "Ver carrinho" flutuante); o do topo tem ícone de WhatsApp com carrinho vazio | Comprovado: captura `m6` | Ambiguidade; o ícone de WhatsApp promete algo que ainda não acontece | Um só ponto de carrinho: barra inferior no celular, painel lateral no desktop e ícone com contador no cabeçalho |
| U8 | **Botão de avaliações cobre o botão do carrinho** | Comprovado: captura `m6` (a estrela amarela fixa em `bottom-6 left-4` cobre o contador do "Ver carrinho") | Esconde a quantidade de itens; toque acidental em avaliação | Avaliações viram um bloco no rodapé do cardápio (o card comentado na linha 369 já existe) |
| U9 | **Checkout em ordem pouco natural**: telefone → nome → como receber → pagamento; taxa de entrega só aparece depois de escolher "Entregar"; cupom sempre aberto | Comprovado: captura `m8` | Surpresa no valor final; formulário longo | "Como receber" primeiro, com a taxa nos próprios botões; cupom recolhido ("Tem cupom?"), aberto automaticamente com `?cupom=` |
| U10 | **Endereço em um campo livre só** ("Rua, número, bairro") | Comprovado | **[Hipótese H2]** endereços incompletos (sem número ou referência) | Opcional (decisão D5): campos separados concatenados no cliente no **mesmo** `endereco`, sem mudar o servidor |
| U11 | **Observação por item sempre visível** | Comprovado: captura `m7` | Carrinho poluído; cada item ocupa o dobro | Link "+ observação" que expande o campo; texto preenchido aparece resumido |
| U12 | **Feedback de "adicionado" fraco**: só o contador muda | Comprovado | O cliente não tem certeza se adicionou | Microanimação no botão (vira o contador) + aviso curto "Adicionado · Ver carrinho" + pulso no total da barra inferior |

### 4.3 Mobile e responsividade
| # | Problema | Evidência | Impacto | Melhoria |
|---|---|---|---|---|
| M1 | **Rolagem aninhada** (`h-[100vh]` + container `h-[74%] overflow-y-auto`) | Comprovado no código | Barra do navegador não recolhe; *pull-to-refresh*, restauração de rolagem e "voltar ao topo" quebram; `100vh` no iOS é maior que a área visível, então **o fim da lista pode ficar sob a barra do Safari** [Hipótese H3] | Rolagem da página (`body`) com cabeçalho e navegação `sticky`; `dvh` onde for preciso |
| M2 | **Campos com 14 px** (`text-sm`) | Comprovado | iOS Safari dá zoom ao focar campos com menos de 16 px e desconfigura o checkout | Campos com 16 px |
| M3 | **Sem `autocomplete`/`inputmode` adequados** | Comprovado: telefone sem `autocomplete="tel"`, nome sem `autocomplete="name"`, endereço sem `street-address` | Digitação mais lenta | Atributos corretos (sem mudança de contrato) |
| M4 | **Atalhos de categoria em 9 px**, com foto de produto como ícone (Bebidas = garrafa em fundo branco) | Comprovado: `text-[9px]`; captura `m1` | Ilegível; o branco destoa | Abas de texto com 14 px, foco claro e área de toque ≥ 44 px |
| M5 | **Botão "+" com 32 px** e "−" com 20 px | Comprovado: `w-8 h-8` e `w-5 h-5` | Abaixo do mínimo de toque (44 px); erros de toque | Área de toque de 44 px (visual pode ser 36 px com padding invisível) |
| M6 | **Desktop é a coluna do celular** (`md:max-w-lg`) sobre fundo preto | Comprovado: captura `d1`, ~65% da tela vazia | Desperdício e aparência de "site de celular" | Grade de 2–3 colunas + carrinho lateral fixo a partir de `lg` |

### 4.4 Conversão e apresentação comercial
| # | Problema | Evidência | Impacto | Melhoria |
|---|---|---|---|---|
| C1 | **Frete grátis acima de R$ 50 não aparece** antes do checkout | Comprovado | Alavanca de ticket médio desperdiçada | No carrinho e na barra: "Faltam R$ X para entrega grátis", com a **mesma** regra do getter `valorFrete` |
| C2 | **Mais Vendidos é uma lista igual às outras** | Comprovado: captura `m1` | Não funciona como vitrine | Carrossel horizontal com foto grande (4:3), ranking verdadeiro e "+" direto |
| C3 | **Promoção sem economia explícita** ("DE/POR" em caixa alta, sem valor economizado) | Comprovado | Benefício menos claro | Preço atual em destaque, preço anterior riscado e "economize R$ X" calculado de `precoResolvido()` (já existe no carrinho) |
| C4 | **Meia a meia não explica a regra de preço** | Comprovado: só "Preço: R$ X" | Dúvida ("por que custou isso?") | Linha "Meia a meia: valor pela média dos sabores" (ou "pelo sabor de maior valor"), derivada de `regraPreco` |

### 4.5 Acessibilidade e legibilidade
| # | Problema | Evidência | Impacto | Melhoria |
|---|---|---|---|---|
| A1 | **Contraste insuficiente nos CTAs** | Comprovado (cálculo WCAG): branco sobre `green-500` = **2,28:1**; branco sobre `teal-500` = **2,49:1** (AA pede 4,5:1, ou 3:1 para texto grande) | Botão principal pouco legível sob sol ou para baixa visão | Paleta da seção 5.2: branco sobre brasa `#C2410C` = 5,18:1 |
| A2 | **Botões sem nome acessível** ("+", "−", lixeira, fechar) | Comprovado: 1 `aria-label` em toda a página | Leitor de tela anuncia "botão" sem contexto | `aria-label="Adicionar Calabresa"` etc. |
| A3 | **`focus:outline-none` sem substituto** (16 ocorrências) | Comprovado | Navegação por teclado invisível | Anel de foco visível com o token `--color-foco` |
| A4 | **Modais sem `role="dialog"`, sem foco preso e sem Esc** | Comprovado | Leitor de tela e teclado se perdem | `role="dialog"`, `aria-modal`, `x-trap` (se disponível no Alpine do Livewire, senão foco manual), Esc fecha |
| A5 | **Textos de 9–10 px** (49 ocorrências) | Comprovado | Ilegível | Mínimo de 12 px; selos com 11–12 px em peso 600 |
| A6 | **Card inteiro é um `<a>` com botões absolutos por cima** | Comprovado | Alvos sobrepostos; leitor de tela lê o card como link longo | Card como `<article>`, título como botão de "ver detalhe" e "+" separado |
| A7 | **Sem `prefers-reduced-motion`** | Comprovado (`animate-pulse` no relâmpago) | Desconforto para quem é sensível a movimento | Respeitar a preferência em todas as animações |

### 4.6 Desempenho técnico
| # | Problema | Evidência | Impacto | Melhoria |
|---|---|---|---|---|
| P1 | **~39 MB de imagens na abertura** | Comprovado: 201 URLs únicas medidas em produção; mediana 96 KB, **70 acima de 200 KB**, maior 3,06 MB | Abertura lenta, dados móveis gastos, rolagem travando em aparelho simples | `loading="lazy"` + `decoding="async"` + dimensões fixas (Etapa 0); **miniaturas WebP** de 160/320/640 px geradas com `intervention/image`, que já está instalado (Etapa 1) |
| P2 | **Logo com 6912×3888 (1,2 MB)** e `favicon.png` com 297 KB | Comprovado | 1,2 MB antes de qualquer produto | Logo WebP de ~480 px de largura (≈20–40 KB) + favicons em tamanhos corretos |
| P3 | **Sem `Cache-Control`** em `/storage` e `/img` | Comprovado (cabeçalhos HTTP) | Revisitas baixam tudo de novo [Hipótese: depende da heurística do navegador] | Recomendação de servidor (fora do código; decisão D6) |
| P4 | **HTML de ~710 KB**, ~118 KB de JS inline e expressões Alpine repetidas em 241 cards | Comprovado | Mais tempo de parse; JS sem cache entre visitas | Componente único com atributos `data-*` e um handler; JS do store para módulo Vite (Etapa 8, opcional) |
| P5 | **`Storage::exists()` em toda chamada de `getImagemUrl()`** (2–3 por card + atalhos + payload de sabores) | Comprovado no código. Custo real **[Hipótese]**: render local de 1,3 s com 46 consultas | Centenas de *stat* no disco por requisição | Memoizar por instância e medir antes e depois |
| P6 | **Mudança de layout ao carregar imagens** | Comprovado: `<img>` sem `width/height` | Conteúdo pula enquanto o cliente lê | `aspect-ratio` fixo + fundo de placeholder |

### 4.7 Fora do escopo do redesign, mas registrado
- **Privacidade**: `GET /cardapio/lookup-cliente?telefone=` devolve **nome e endereço**
  de qualquer cliente cujo celular *contenha* os dígitos informados (`LIKE %tel%`, a
  partir de 8 dígitos), sem limite de requisições. Recomendo tratar isso numa tarefa
  própria de segurança (throttle, casamento exato, endereço parcial). **Não será mexido
  no redesign sem aprovação.**
- O número de WhatsApp está fixo no JS (`5564981380071`). O ideal é configuração, mas
  isso não entra no redesign.
- Na mensagem do WhatsApp, `parseFloat("1.000,00")` vira 1. O troco de valores com
  milhar sai errado. É uma correção de uma linha que pode entrar na Etapa 7 (com teste),
  se aprovada.

---

## 5. Direção de arte: "Forno aceso"

### 5.1 Conceito
O Empório da Pizza é uma pizzaria e pastelaria de bairro, com logo de **forno de tijolos
e chamas**, "desde 2021". A direção proposta parte desse forno: **fundo escuro e quente
como a boca do forno à noite, com a comida sob luz âmbar.** O tema escuro atual é mantido
(o cliente já reconhece a marca assim, e fundo escuro destaca as fotos), mas deixa de ser
preto chapado e neutro e passa a ter o calor da brasa. A cor fica com a comida e com as
ações; o resto se apaga.

Princípios:
1. **A foto é a protagonista.** Proporção fixa, cantos generosos e nenhuma cor
   competindo ao lado.
2. **Uma cor de ação.** Tudo que leva ao pedido é brasa. Promoção usa dourado. Verde
   só aparece para confirmação e WhatsApp.
3. **Tipografia com voz.** Títulos com serifa calorosa (artesanal) e corpo
   sem serifa limpo (ágil).
4. **Densidade de cardápio, não de catálogo.** Cards compactos na lista, vitrine só
   onde vende (mais pedidos e promoções).

### 5.2 Paleta (tokens) com função e contraste medido
| Token | Hex | Função | Contraste medido |
|---|---|---|---|
| `--color-forno` | `#14100D` | Fundo da página (carvão quente) | — |
| `--color-forno-2` | `#1F1915` | Superfície de cards e sheets | — |
| `--color-forno-3` | `#2A221C` | Superfície elevada, inputs, abas inativas | — |
| `--color-linha` | `#3A2F27` | Bordas e divisores | — |
| `--color-creme` | `#F6EEE3` | Texto principal (cor da massa) | 16,45:1 sobre forno; 15,11:1 sobre forno-2 |
| `--color-farinha` | `#B9AC9E` | Texto secundário e descrições | 8,52:1 sobre forno; 7,83:1 sobre forno-2 |
| `--color-brasa` | `#C2410C` | **Ação principal**: "+", "Adicionar", "Continuar", barra do carrinho | Branco sobre brasa: **5,18:1** (AA) |
| `--color-brasa-viva` | `#E8673A` | Brasa como **texto/ícone** sobre fundo escuro, hover e aba ativa | 5,80:1 sobre forno |
| `--color-ouro` | `#F2B33D` | **Promoção e destaque** (selo, preço promocional, ranking); cor da borda dourada da pizza | 10,17:1 sobre forno; texto forno sobre ouro também 10,17:1 |
| `--color-manjericao` | `#4ADE80` | Sucesso ("Adicionado", "Frete grátis") | 9,98:1 sobre forno-2 |
| `--color-whats` | `#15803D` | Somente o botão final de WhatsApp | Branco sobre ela: 5,02:1 |
| `--color-tomate` | `#F87171` | Erro e validação | Validar na implementação (≥ 4,5:1 sobre forno-2) |
| `--color-foco` | `#F2B33D` | Anel de foco (2 px + deslocamento) | — |

Por que não verde nas ações: o verde atual (`green-500`) falha em contraste e não tem
relação com a marca. As chamas do logo são vermelho e laranja; a brasa liga a ação ao
forno. O verde fica para o WhatsApp, onde o cliente o espera.

### 5.3 Tipografia
- **Títulos (marca, seções, nome no detalhe):** **Fraunces** (serifa "soft", com
  calor de cardápio artesanal), pesos 600 e 700, servida pelo **fonts.bunny.net**,
  provedor já usado. Uma família a mais, só 2 pesos, com `font-display: swap`.
  *Alternativa sem fonte nova:* Figtree 700 com `letter-spacing` negativo. Menos
  personalidade, custo zero.
- **Corpo e interface:** **Figtree** (já carregada) 400/500/600.
- Escala (mobile → desktop): seção 24→28 px; nome de produto 16→17 px/600;
  descrição 14 px/400 `farinha` com no máximo 2 linhas; preço 16→17 px/700 com
  `font-variant-numeric: tabular-nums`; selos 11–12 px/600.
- Caixa normal em nomes. Caixa alta só em selos curtos e com espaçamento.
- Para a caixa inconsistente dos dados: `first-letter:uppercase` no título, sem tocar
  no banco.

### 5.4 Forma, sombra e movimento
- **Raio:** 20 px em sheets e modais, 16 px em cards, 12 px em fotos e inputs, `full`
  em botões redondos e pílulas.
- **Sombra:** quase nenhuma. Profundidade vem da superfície (`forno-2`/`forno-3`) e de
  uma borda de 1 px `linha`. Uma sombra quente só na barra do carrinho
  (`0 -8px 24px rgb(0 0 0 / .45)`).
- **Espaçamento:** base de 4 px; gutter lateral de 16 px no celular e 24 px no desktop;
  24–32 px entre seções.
- **Movimento** (todo desligado com `prefers-reduced-motion`):
  - "+" → vira contador com escala de 120 ms; a barra do carrinho dá um pulso curto no
    total.
  - Sheets sobem em 240 ms `ease-out`.
  - **Pizza que se monta**: no modal de sabores, um SVG da pizza dividido em 2 ou 3
    fatias, que se preenchem com a cor de cada sabor escolhido e o nome na fatia. É
    leve (SVG inline), explica o meia a meia sem texto e é o momento memorável da
    experiência.
  - Relâmpago: o ícone de raio pisca **uma vez** ao entrar na tela, em vez de
    `animate-pulse` infinito.

### 5.5 Iconografia
Boxicons continua (já carregado; trocar custaria muito e não traria ganho). Usar sempre o
mesmo estilo (`bx-` linha para interface, `bxs-` sólido só em selos) e tamanho de 20 px.

### 5.6 Regras de fotografia
- **Card de lista:** quadrado 1:1, 96 px (celular) / 112 px (desktop), `object-cover`,
  raio de 12 px, à direita do texto.
- **Vitrine** (mais pedidos, relâmpago): 4:3, largura de 240–280 px, em cima do texto.
- **Detalhe e sheet:** 4:3 até 1:1, largura total.
- **Sem foto:** em vez do PNG "Sem Imagem" de 1080 px, um bloco `forno-3` com o
  monograma "E" do logo em `linha`. Mesma proporção, sem salto de layout. O vínculo
  imagem↔produto **não muda**.
- **Placeholder de carregamento:** fundo `forno-3` + `aspect-ratio` fixo; *fade-in* de
  150 ms quando a imagem termina de carregar.
- Fotos de garrafa em fundo branco (Bebidas) ganham `bg-white` com padding interno de 8
  px dentro do quadrado, como "produto embalado", para não brigar com as fotos de comida.
  É só apresentação e pode ser aplicado por categoria ou automaticamente para PNG com
  fundo claro (decisão de implementação).

---

## 6. Especificação dos componentes

### A. Cabeçalho e apresentação
**Mobile (≤ 767 px)**
- **Barra fixa** (56 px, `forno` com 92% de opacidade + `backdrop-blur`): logo WebP
  (altura de 36 px) à esquerda; **pílula de status** no centro ou à direita ("● Aberto ·
  até 23:00" em manjericão, ou "● Fechado · abre hoje às 18:00" em farinha); ícone de
  sacola com contador; menu (⋮) com os links atuais (Painel Admin, Dashboard), mantidos
  como estão hoje.
- **Faixa de apresentação** logo abaixo, só no topo (rola junto, ~120 px): nome em
  Fraunces "Empório da Pizza", subtítulo "Pizzaria & Pastelaria" (do próprio logo) e
  2–3 **chips informativos** gerados dos dados reais: "🛵 Entrega R$ 4,00 · grátis
  acima de R$ 50", "🏪 Retirada na loja", "🕖 Hoje 18:00–23:30" (de `_horarios`).
  Nenhum dado inventado: se a opção não existir, o chip não aparece.
- **Loja fechada:** a faixa ganha uma linha `brasa-viva` ("Estamos fechados agora.
  Você pode ver o cardápio; os pedidos abrem às 18:00").
- Primeira dobra (844 px): barra (56) + faixa (120) + abas (52) = ~228 px. **O
  primeiro produto aparece acima de 300 px**, contra ~600 px hoje.

**Desktop (≥ 1024 px):** a mesma barra com largura máxima de 1200 px; a faixa vira uma
linha horizontal (nome à esquerda, chips à direita).

**Modo mesa:** a barra troca a sacola pelo botão "Minha mesa" (`mesa-cliente.botao-painel`)
e a faixa da mesa (`mesa-cliente.faixa`) substitui os chips de entrega. Toda a
lógica da mesa fica como está; ajusto só os estilos para os tokens.

### B. Categorias e navegação
- **Barra de abas fixa** abaixo do cabeçalho: pílulas de texto (14 px/600, altura de 40
  px, área de toque de 44 px), rolagem horizontal com *snap*, **aba ativa** em `creme`
  sobre `forno-3` com sublinhado `brasa`. Primeiro vêm os atalhos especiais quando
  existem (⚡ Relâmpago, 🎁 Oferta, % Promoções, ★ Mais pedidos), depois as categorias de
  topo.
- **Scrollspy:** `IntersectionObserver` marca a seção visível e centraliza a aba ativa.
  Tocar numa aba faz `scrollIntoView` com o deslocamento das barras fixas
  (`scroll-margin-top` nas seções).
- **Subcategorias** (Tamanho da pizza, tipo de bebida): segunda linha de pílulas
  **dentro da seção**, fixa abaixo da barra principal enquanto a seção está na tela. O
  filtro "Todos / Grande / Média…" atual continua (mesmo estado `catFilhaAtiva`), só
  muda o visual.
- **Busca:** ícone de lupa no fim da barra de abas, que expande um campo. Filtra no
  cliente por `data-busca` (nome + ingredientes, normalizado sem acento) e esconde
  seções vazias. Estado vazio: "Nada encontrado para 'xyz'". O botão "Limpar" volta tudo.
- Os atalhos com foto (`x-categoria-button`) **saem do cardápio**. O componente continua
  existindo enquanto for usado em outro lugar (hoje só no cardápio; posso removê-lo na
  limpeza final).

### C. Mais pedidos (vitrine)
- Título "Os mais pedidos" + subtítulo "Os favoritos de quem pede no Empório".
- **Carrossel horizontal** com *snap*: cards verticais de 240 px (foto 4:3 em cima, nome
  em 2 linhas, preço, "+" sobre a foto no canto inferior direito).
- **Ranking**: um selo pequeno "nº 1", "nº 2"… em `ouro`. É verdadeiro porque a lista
  já vem `orderByDesc('produto_qtd_vendas')`. Sem estrelas, sem notas, sem "x vendidos"
  (o número existe, mas exibir volume de vendas é decisão comercial; ver D7).
- Os filtros por categoria da seção atual saem: com 8 itens num carrossel, filtro não
  ajuda.
- Nos cards de lista, o selo "+VENDIDO" do top 10 vira um selo discreto "★ Mais pedido"
  (ouro, 11 px), só para os top 10 reais (`$top10Ids`).

### D. Card de produto (componente único)
Um componente `x-cardapio.produto-card` com a prop `variante`:
`lista` (padrão) | `vitrine` | `relampago` | `oferta` | `promocao`. Ele substitui
`produto-card`, `promo-relampago-card`, `promo-adicional-card` e os dois cards inline
(Promoções e Mais Vendidos) da view. Hoje são 5 cópias quase idênticas da lógica de "+".

**Regras do card `lista`:**
- `<article>` em grade de 2 colunas: texto (flex-1) + foto 1:1 de 96 px à direita;
  altura mínima de ~120 px (hoje ~155 px). **Cerca de 30% mais produtos por tela.**
- **Nome:** dentro da própria categoria, `produto_descricao` (o sabor); fora dela
  (vitrine, promoções, busca), `nomeExibicao()`. **O nome enviado ao carrinho continua
  `nomeExibicao()`**, sem mudança no contrato.
- **Referência** (`produto_referencia`), quando houver: texto pequeno `farinha` abaixo
  do nome.
- **Descrição:** `produto_codimentacao` sem rótulo, 2 linhas (`line-clamp-2`), omitida
  se vazia.
- **Preço:** `R$ 64,00` (com espaço, `tabular-nums`). Promoção: preço atual em `ouro` +
  anterior riscado em `farinha` + selo "−R$ 8,00" (diferença real de
  `PrecoResolvido`).
- **Botão "+":** círculo `brasa` de 36 px com área de toque de 44 px, sobreposto ao canto
  inferior direito da foto. Com quantidade > 0 vira **stepper** `− 2 +` (sem sabores) ou
  **contador + "+"** (com sabores, porque cada pizza é uma linha diferente). Mantém as
  mesmas chamadas `add`, `decrement`, `abrirSabores` e `qty`.
- **Sem foto:** placeholder com monograma (seção 5.6).
- **Indisponível:** o filtro `visivelCardapio` já esconde o produto, então não há
  estado de "esgotado" visível hoje. Não invento um.
- **Toque no corpo do card** abre o **sheet de detalhe** (seção E.2).

**Variantes:**
- `vitrine`: foto 4:3 em cima, largura fixa de 240 px.
- `relampago`: borda de 1 px em `ouro` com 40% e selo "⚡ RELÂMPAGO". **O texto
  `RELÂMPAGO` e o contador "restam N" continuam** (contrato de teste).
- `oferta`: linha "🎁 Leve também: X por +R$ Y" (dados reais de `$ofertas`).
- `promocao`: selo de economia.

**Desktop:** grade de 2 colunas (≥ 768 px) e 3 colunas (≥ 1280 px, quando o carrinho
lateral cabe).

### E. Personalização da pizza
**E.1 Sheet "Monte sua pizza"** (substitui o modal de sabores; o estado
`saboresModal` é o mesmo):
1. **Cabeçalho:** "Pizza Grande", a categoria, que **é** o tamanho. Fica claro que o
   tamanho é aquele.
2. **Segmentado "Como você quer?":** Inteira | Meia a meia | 3 sabores (só as opções
   reais de `opcoesSabores` filtradas por `maxSabores`, como hoje).
3. **Pizza visual (SVG):** 1, 2 ou 3 fatias; cada fatia mostra o sabor escolhido; a
   fatia "da vez" fica destacada. Tocar numa fatia preenchida remove o sabor.
4. **Busca de sabor** (aparece quando há 8 sabores ou mais).
5. **Lista de sabores:** foto 56 px, nome, **ingredientes em 1 linha** (campo novo no
   payload: `ingredientes` = `produto_codimentacao`) e preço. Selecionado: borda
   `brasa` + check. Na meia a meia, preço exibido **da fração** (`precoFracao`) quando
   diferente do inteiro, com o rótulo "como meia: R$ X". Isso explica a promoção "só
   inteira" que hoje fica invisível.
6. **Rodapé fixo:** sabores escolhidos ("Calabresa + Frango Especial"), **regra de
   preço** em 1 linha (seção 4.4 C4), total via `precoSabores()` e botão
   "Adicionar · R$ 75,90" (desabilitado com "Escolha mais 1 sabor" até completar).
- **Regras mantidas:** ordem dos sabores (`cartKey` por posição), `maxSabores` da
  promoção, perguntas por combo × por produto, interseção de adicionais, oferta só na
  inteira, bloqueio fora do horário. **Nada no cálculo muda.**

**E.2 Sheet de detalhe do produto** (novo, substitui a navegação para `/Produto/{id}`):
- Foto grande, nome, referência, descrição completa, preço (de `precoResolvido`, como no
  card) e selos reais. Botão "Adicionar" chama **o mesmo** `add()` ou `abrirSabores()` do
  card. Nenhuma regra paralela.
- Abre por toque no card ou por `?produto={id}` na URL (decisão D4 para o
  redirecionamento de `/Produto/{id}`).

**E.3 Sheet de montagem** (perguntas e adicionais): só visual. Agrupamento "Obrigatório
· escolha 1" / "Opcional · até 3", opções com 48 px de altura (já é assim), total ao vivo
no botão (já existe: `acrescimoMontagem`), rolagem automática até a primeira pergunta
obrigatória sem resposta ao tocar no botão desabilitado.

**E.4 Sheet de oferta:** visual da oferta com foto; "Não, obrigado" com o mesmo peso
visual de um botão secundário (não esconder a recusa).

### F. Carrinho
- **Celular, barra inferior fixa** (aparece quando `count > 0`): "🛍 3 itens ·
  R$ 82,90 · Ver sacola ›" em `brasa`, 56 px, com `safe-area-inset-bottom`. Acima dela,
  quando houver opção com frete mínimo, uma linha fina: **"Faltam R$ 12,10 para entrega
  grátis"** com barra de progresso (mesmo cálculo de `valorFrete`, `total -
  cupomDesconto` contra `min_frete`). Ao atingir: "✓ Entrega grátis liberada".
- **Desktop (≥ 1024 px), painel lateral fixo** de 360 px com os itens e o botão
  "Continuar". O drawer só é usado no celular. O mesmo template de item serve aos dois
  (um `<template x-for>` em parcial reutilizada).
- **Item:** foto 48 px, nome em 2 linhas (pizza: "Pizza Grande" + "Frango Especial ½ ·
  A Moda ½"), sub-linhas de respostas e adicionais, oferta como sub-item, stepper de
  44 px, valor à direita, link "Observação" que expande o campo e lixeira com
  `aria-label`.
- **Resumo:** Subtotal, "Descontos de promoção" (quando houver), Total. **Taxa de entrega
  não aparece antes da escolha** (depende dela), mas a linha de frete grátis já prepara
  o cliente.
- **Vazio:** ilustração simples (forno, em SVG/ícone) + "Sua sacola está vazia" +
  botão "Ver os mais pedidos" (rola até a vitrine).
- **Fechado:** aviso no topo do carrinho, como hoje, com o novo visual.

### G. Entrega, retirada e finalização
Nova ordem da etapa "Seus dados" (mesmos campos, mesmo payload):
1. **Como você quer receber?** Cards grandes: "🛵 Entrega · R$ 4,00 (grátis acima de
   R$ 50,00)" e "🏪 Retirar na loja · grátis". A taxa aparece **no próprio card**,
   antes da escolha.
2. **Seu WhatsApp** (`type=tel`, `autocomplete=tel`, `inputmode=tel`, 16 px). O lookup
   continua igual (debounce de 600 ms) e mostra "Que bom te ver de novo, {nome}!" quando
   encontra o cliente.
3. **Nome** (`autocomplete=name`).
4. **Endereço** (só em Entrega). Campo único como hoje (`autocomplete=street-address`),
   ou campos separados (decisão D5).
5. **Pagamento** em grade 2×2, com descrição da forma escolhida (já existe) e o fluxo de
   troco atual (modal de troco), só restilizado.
6. **Cupom** recolhido: "Tem um cupom?" expande. Aberto e aplicado automaticamente com
   `?cupom=` (como hoje, via `aplicarCupomPendente`).
7. **Resumo fixo:** itens, descontos, cupom, **taxa de entrega** e **total**, que é a
   mesma conta de hoje (`totalComFrete`).
8. **Botão final** `whats`: "Enviar pedido pelo WhatsApp", com microcopy "Você vai
   confirmar o pedido nº N no WhatsApp da loja".

**Tela de sucesso (nova, é o item U1):**
- Depois do `200` do checkout: "Pedido nº 1234 recebido! 🍕" + "Falta só enviar a
  mensagem para a loja confirmar" + **botão-link** `<a href="https://wa.me/...">`
  "Enviar no WhatsApp" (funciona mesmo com pop-up bloqueado) + "Acompanhar pedido"
  (`/acompanhar/{id}`) + resumo do total retornado pelo servidor (`total_final`,
  `valor_frete`, descontos).
- `window.open` continua sendo tentado automaticamente. Se abrir, ótimo; se não, o botão
  resolve.
- O carrinho é limpo ao chegar nessa tela (mesmo `clear()`), e "Fazer novo pedido" fecha
  o sheet.
- **Modo mesa:** o fluxo de envio da mesa (`mesa-cliente.enviar`) fica como está.

---

## 7. Arquitetura da implementação

### 7.1 Princípios
- **Mesmo store, nova pele.** `Alpine.store('cart')` e `Alpine.store('mesa')` mantêm
  nomes, métodos e formato de dados. Acréscimos permitidos são **aditivos** (ex.:
  `ultimoPedido` para a tela de sucesso, `busca` para o filtro e um getter
  `faltaParaFreteGratis`).
- **O servidor continua dono do preço.** Nenhum valor novo calculado no cliente vai para
  o checkout. O que for exibido usa os mesmos getters de hoje ou dados do servidor.
- **Mudanças no PHP** limitadas a: (1) layout próprio; (2) `ingredientes` no payload de
  sabores; (3) miniaturas de imagem (Etapa 1); (4) memoização de `getImagemUrl`;
  (5) opcional: redirecionamento de `/Produto/{id}` (D4).

### 7.2 Arquivos (novos e alterados)
| Arquivo | Ação |
|---|---|
| `resources/views/layouts/cardapio.blade.php` | **Novo.** Layout só do cardápio (o `guest` atende login e erros e não deve mudar): `<title>Empório da Pizza · Cardápio</title>`, `meta description`, Open Graph (`og:image` = logo otimizado ou foto de destaque), `theme-color` `#14100D`, Fraunces + Figtree, `@vite`, `@livewireStyles/Scripts` |
| `resources/css/cardapio.css` | **Novo** ponto de entrada Vite (`@import 'tailwindcss'` + `@theme` com os tokens da seção 5). Isola o cardápio do CSS do sistema legado. Requer adicionar a entrada em `vite.config.js` |
| `resources/views/cardapio.blade.php` | Passa a usar `<x-cardapio-layout>`; vira composição de parciais (alvo: < 400 linhas de marcação + o `<script>` do store, que fica onde está até a Etapa 8) |
| `resources/views/cardapio/partials/*.blade.php` | **Novos:** `cabecalho`, `apresentacao`, `abas`, `secao-vitrine`, `secao-categoria`, `carrinho-itens`, `carrinho-sheet`, `carrinho-lateral`, `checkout`, `sucesso`, `sheet-sabores`, `sheet-montagem`, `sheet-oferta`, `sheet-troco`, `sheet-produto`, `rodape` |
| `resources/views/components/cardapio/produto-card.blade.php` | **Reescrito** como componente único com `variante` |
| `resources/views/components/cardapio/botao-adicionar.blade.php` | **Novo:** a lógica "+ / stepper / abrir sabores", hoje copiada 5 vezes |
| `resources/views/components/cardapio/foto.blade.php` | **Novo:** `<img>` com `srcset` das miniaturas, `loading`, `decoding`, `width/height`, `aspect-ratio` e placeholder com monograma |
| `components/cardapio/promo-relampago-card`, `promo-adicional-card` | **Removidos** depois da migração para as variantes |
| `resources/views/mesa-cliente/{faixa,botao-painel,enviar,modais}` | Só restilização com os tokens; **lógica intacta** |
| `app/Services/CardapioService.php` | + `'ingredientes'` no map de `produtos` de `categoriasComSabores` |
| `app/Models/Produto.php` | `getImagemUrl()` memoizado; novo `getImagemUrl(?int $largura)` ou `getMiniaturaUrl(int)` com *fallback* para a original |
| `app/Console/Commands/GerarMiniaturasProdutos.php` | **Novo** (`produtos:miniaturas {--dry-run}`): gera `fotos_produtos/miniaturas/{160,320,640}/{nome}.webp` com `intervention/image`. **Não altera nem apaga a original** |
| Observer ou hook de upload (`ProdutoObserver` ou `saved`) | Gera as miniaturas quando `produto_foto` muda (Filament e legado `saveFoto`) |
| `public/img/marca/` | **Novo:** `logo.webp` (~480 px), `logo@2x.webp`, `og-cardapio.jpg` (1200×630), favicons. Gerados do PNG atual, **sem alterar a arte** |
| `app/Http/Controllers/ProdutoController::show` | **Somente se D4 for aprovada:** redireciona para `cardapio?produto={id}` |

### 7.3 O que NÃO muda
`CardapioCheckoutController`, `LancamentoItemPedidoService`, `PrecificadorService`, rotas
e nomes de rota, throttles, reCAPTCHA, `HorarioFuncionamento`, regras de sabores,
perguntas, adicionais, ofertas, cupom e frete, o formato do `localStorage`, o fluxo da
mesa e o `guest.blade.php`.

---

## 8. Plano incremental (priorizado por impacto)

Complexidade: **P** (≤ meio dia), **M** (1–2 dias), **G** (3+ dias). Cada etapa termina
com testes verdes, QA visual em 390 px e 1440 px e o fluxo completo (inteira, meia a
meia, item com montagem, oferta, cupom, entrega, retirada, mesa) navegado no Playwright
com o horário simulado como aberto.

| Etapa | Entrega | Impacto | Complex. | Depende de |
|---|---|---|---|---|
| **0. Ganhos rápidos de desempenho** | `loading="lazy"`/`decoding="async"`/`width`/`height` em todas as imagens fora da primeira dobra; `fetchpriority="high"` no logo; logo WebP otimizado + favicons; memoização de `getImagemUrl()`; medição antes e depois (bytes na abertura, LCP no Chromium) | **Muito alto**: os ~39 MB caem para a primeira dobra (estimativa < 1,5 MB) | P | — |
| **1. Miniaturas WebP** | Comando `produtos:miniaturas` (`--dry-run`), geração no upload, componente `x-cardapio.foto` com `srcset`; *fallback* para a original quando não houver miniatura | Alto (fotos de 3 MB → ~15–30 KB por card) | M | 0 |
| **2. Fundação visual + segurança de conversão** | Layout `cardapio`, `cardapio.css` com tokens, Fraunces; rolagem da página (fim da rolagem aninhada); **tela de sucesso do checkout (U1)**; **status aberto/fechado no topo (U2)**; avaliações no rodapé (U8); campos com 16 px, `autocomplete`, `aria-label`, anel de foco | Alto (conversão + base de todo o resto) | M | 0 |
| **3. Cabeçalho, abas, scrollspy e busca** | Seções A e B: barra fixa, faixa com chips reais, abas fixas com scrollspy, subcategorias fixas, busca no cliente | Alto (encontrar produto) | M | 2 |
| **4. Card único + vitrines** | Seções C e D: `produto-card` com variantes, `botao-adicionar`, Mais pedidos em carrossel, relâmpago/oferta/promoções no novo visual; grade de desktop; remoção dos cards duplicados | Alto (apetite + densidade) | M/G | 2, 3 |
| **5. Personalização** | Seção E: sheet "Monte sua pizza" com SVG, ingredientes, busca de sabor, regra de preço; sheets de montagem, oferta e detalhe do produto (E.2) | Alto (fluxo principal de pizza) | G | 4 |
| **6. Carrinho** | Seção F: barra inferior com frete grátis, painel lateral no desktop, item redesenhado, observação recolhível, estado vazio | Médio/alto (ticket médio) | M | 4 |
| **7. Finalização** | Seção G: nova ordem do checkout, taxa nos cards de entrega, cupom recolhido, resumo fixo; (D5) endereço em campos; correção do troco com milhar na mensagem | Médio/alto | M | 6 |
| **8. Limpeza (opcional)** | JS do store para `resources/js/cardapio/*.js` (módulo Vite, com cache), configuração via `window.__cardapio`; remover `categoria-button` se não houver outro uso | Médio (desempenho em revisita, manutenção) | M | 7 |

**Ordem recomendada para publicar:** 0 → 1 → 2 primeiro (melhoram desempenho e conversão
sem mudar o visual de forma radical). Depois 3 → 4 → 5 → 6 → 7, de preferência em
sequência curta para a página não ficar muito tempo com metade em cada estilo. Seguindo o
processo do projeto, cada publicação inclui `npm run build` + `public/build/` no commit, e
a etapa 1 exige rodar `produtos:miniaturas` no servidor depois do deploy.

---

## 9. Decisões que preciso de você (antes ou durante a Fase 2)

| # | Decisão | Minha recomendação |
|---|---|---|
| **D1** | Aprovar a direção "Forno aceso" (tema escuro quente, brasa como ação, ouro como promoção) | Aprovar. Mantém o reconhecimento do tema escuro atual e corrige o contraste |
| **D2** | Fonte de título: **Fraunces** (nova, ~30 KB, 2 pesos) ou só Figtree | Fraunces: é o que dá personalidade de "pizzaria artesanal" |
| **D3** | Pizzas: manter "subcategoria = tamanho" (só restilizar) **ou** criar um "escolha o tamanho → escolha sabores" agrupando os sabores iguais por nome entre os tamanhos | **Manter nesta rodada.** O agrupamento depende de nomes consistentes (hoje há "4 Queijos"/"4 queijos") e de o Brotinho não ter meia a meia. Reavaliar depois, com os dados limpos |
| **D4** | `/Produto/{id}` redireciona para o detalhe dentro do cardápio (corrige preço divergente e itens sem montagem) | Aprovar. Links antigos continuam funcionando via redirecionamento |
| **D5** | Endereço em campos separados (rua, número, bairro, complemento, referência), concatenados no mesmo `endereco` | Perguntar à operação se há problema de endereço incompleto. Se houver, aprovar (sem mudar o servidor) |
| **D6** | `Cache-Control` longo para `/storage/fotos_produtos/miniaturas` e `/build` no servidor | Aprovar, mas é configuração de servidor: faço só a recomendação, não aplico |
| **D7** | Exibir número de vendas ("+500 pedidos") na vitrine | **Não** por enquanto. O ranking (nº 1, nº 2) é verdadeiro e não expõe volume |
| **D8** | Corrigir a privacidade do `lookup-cliente` (seção 4.7) | Tratar numa tarefa própria, logo depois |

---

## 10. Riscos de regressão e prevenção

| Risco | Prevenção |
|---|---|
| Quebrar o **modo mesa** (mesma view) | Toda etapa é testada também em `/mesa/{codigo}` (estados identificar/pedindo/fechada); `PedidoMesaClienteServiceTest` e `PainelGarcomMesaQrTest` rodam a cada etapa; parciais da mesa mudam só classes |
| Carrinho salvo de clientes ficar incompatível | Formato do `localStorage` intocado; teste manual com carrinho "antigo" pré-carregado no Playwright |
| **Divergência entre preço exibido e cobrado** | Nenhum cálculo novo de preço; exibição usa `precoResolvido()`, `precoFracao` e os getters atuais. Teste de feature para o card renderizar `precoResolvido()->precoFinal()` em todas as variantes |
| Testes que verificam HTML (`id="categoria_*"`, `RELÂMPAGO`, `restam`, `abrirSabores(`, `@js` de sabores) | Mantidos como contrato (seção 2.4); a suíte `Cardapio*` roda em toda etapa |
| Rolagem da página quebrar a faixa *sticky* da mesa e o `scrollIntoView` | `scroll-margin-top` nas seções; faixa da mesa passa para dentro do cabeçalho fixo; testado em 390 px |
| Tela de sucesso esconder o WhatsApp de quem já o abria | `window.open` continua automático; o botão é complementar |
| Miniaturas apagando ou trocando fotos | Comando só **cria** arquivos em `miniaturas/`, nunca altera nem apaga `produto_foto`; `--dry-run`; *fallback* para a original |
| Alpine sem plugin `focus` (`x-trap`) | Verificar o build do Livewire; se não houver, foco manual simples (sem dependência nova) |
| Tailwind v4 com a entrada nova no Vite | Build local + checagem do `public/build/manifest.json`; o processo de promoção já conhecido inclui `public/build/` |
| Página "meio nova, meio velha" entre etapas | Etapas 3–7 publicadas em sequência curta; a Etapa 2 já entrega uma base coerente |
| Cache do navegador servindo CSS ou JS antigo | Assets versionados pelo Vite; nada a fazer |

---

## 11. Estratégia de testes

- **Feature (PHPUnit, banco isolado conforme o padrão do projeto):**
  - Cardápio renderiza com o layout novo e o `<title>` "Empório da Pizza".
  - Card exibe `precoResolvido()` em lista, vitrine, relâmpago, oferta e promoção.
  - `ingredientes` presente em `categoriasComSabores`.
  - Produto sem foto renderiza o placeholder (sem `Sem Imagem.png` de 1080 px).
  - `getImagemUrl()`/miniatura: com miniatura, sem miniatura (fallback) e sem foto.
  - Comando `produtos:miniaturas`: gera arquivos, `--dry-run` não grava, não altera
    `produto_foto`.
  - (D4) `/Produto/{id}` redireciona; produto invisível → 404.
  - Testes atuais `Cardapio*`, `PromocaoRelampago*`, `QuantidadesSabores*`,
    `PedidoMesaClienteService*` e `PainelGarcomMesaQr*` verdes em toda etapa.
- **QA visual e funcional (Playwright, Chromium local):** roteiro fixo em 390×844 e
  1440×900, com capturas antes e depois anexadas a cada etapa: primeira dobra; aba e
  scrollspy; busca com e sem resultado; inteira; meia a meia (preço = média); item com
  pergunta obrigatória; oferta aceita e recusada; cupom por `?cupom=`; entrega abaixo e
  acima de R$ 50; retirada; dinheiro com troco; tela de sucesso (checkout contra o
  ambiente local, nunca produção); loja fechada; modo mesa.
- **Desempenho:** bytes transferidos na abertura e LCP antes e depois (Chromium com rede
  "Fast 4G"), registrados aqui.
- **Acessibilidade:** navegação só por teclado no checkout; contraste dos tokens (já
  calculado); `prefers-reduced-motion`.

---

## 12. Evidências (capturas da auditoria)

Capturas de produção (29/10/2026) feitas durante a auditoria, mencionadas nas tabelas:
- `m1`: primeira dobra no celular (cabeçalho com 26%, logo encostado na borda,
  selos repetidos, botão de avaliação sobre o card).
- `m3`: seção Pizzas (redundância "Pizza Grande" > "PIZZA GRANDE 4 QUEIJOS",
  descrição com "Codimentos").
- `m4`: toque em "+" com a loja fechada → carrinho vazio + "Estamos fechados".
- `m5`: modal meia a meia (sem ingredientes, sem busca, sem regra de preço).
- `m6`: bebidas com "Codimentos:" vazio; **estrela de avaliação cobrindo o "Ver
  carrinho"**; dois CTAs de carrinho.
- `m7`: carrinho com nome em 5 linhas e observação sempre aberta.
- `m8`: etapa "Seus dados" (taxa invisível antes da escolha, cupom sempre aberto).
- `d1`: desktop 1440 px, coluna de celular sobre fundo preto.

As capturas ficaram no diretório temporário da sessão e não foram versionadas. Na Fase 2,
o roteiro do Playwright (seção 11) as recria antes de cada etapa, para comparar antes e
depois.

---

## 13. Recomendações de fotografia (separadas do código)

Nada disso altera o catálogo automaticamente. São recomendações para a equipe:
1. **~13 produtos sem foto** em produção. Priorizar os de pizza e pastel, que vendem por
   imagem.
2. **Padronizar a captura das pizzas:** vista de cima, pizza inteira centralizada, fundo
   de madeira escura ou pedra (combina com a direção "Forno aceso"), mesma luz. Hoje há
   fundos azuis, de granito e de madeira misturados.
3. **Bebidas:** manter as fotos de produto em fundo branco (o design acomoda), mas usar
   imagens do mesmo tamanho e enquadramento.
4. **Fotos de vitrine:** para os 8 mais pedidos, vale uma sessão com foto 4:3 mais
   "apetitosa" (fatia sendo puxada, queijo esticando), porque é onde a foto aparece
   grande.
5. **Uploads novos** já saem quadrados em 500 px pelo Filament. O problema de peso vem
   das fotos antigas do upload legado (`saveFoto`, sem redimensionamento), que a
   Etapa 1 resolve sem tocar nos originais.
6. **Nomes:** padronizar a caixa ("4 Queijos" e "4 queijos") ajuda a busca e é requisito
   para a decisão D3.

---

## 14. Recomendação final

Aprovar o plano com as decisões **D1, D2, D4 e D7** como recomendadas, **D3 adiada**,
**D5** dependendo da conversa com a operação, e **D6/D8** como tarefas separadas.
Começar a Fase 2 pelas **Etapas 0 → 1 → 2**: elas atacam o que mais custa pedidos hoje
(peso de 39 MB, confirmação do pedido que pode se perder e loja fechada sem aviso) e
deixam a fundação visual pronta para as etapas de identidade, navegação, cards e
personalização.

---

## 15. Execução (Fase 2, 2026-10-29)

Etapas 0 a 7 implementadas de uma vez, a pedido. A Etapa 8 (JS do store em módulo
Vite) ficou de fora: é opcional e não muda nada para o cliente.

### 15.1 O que foi feito
| Etapa | Entrega | Arquivos principais |
|---|---|---|
| 0 | Logo WebP (1,2 MB → 6 KB), imagem de compartilhamento 1200×630, favicons; `loading="lazy"`, `decoding`, `width/height` em todas as fotos; `getImagemUrl()` memoizado | `public/img/marca/*`, `app/Models/Produto.php` |
| 1 | Miniaturas WebP 160/320/640 (lado menor, sem ampliar), geradas no `saved` do produto e pelo comando `produtos:miniaturas {--dry-run} {--forcar}`; original nunca é tocada; *fallback* para a original | `app/Services/MiniaturaProdutoService.php`, `app/Console/Commands/GerarMiniaturasProdutos.php`, `Produto::getMiniaturaUrl()` |
| 2 | Layout próprio (`<x-cardapio-layout>`, title, OG, theme-color), CSS próprio com os tokens, rolagem da página, **tela de sucesso** com link do WhatsApp, **status aberto/fechado**, aviso em vez de carrinho vazio com a loja fechada, avaliações no rodapé, campos de 16 px com `autocomplete` | `app/View/Components/CardapioLayout.php`, `resources/views/layouts/cardapio.blade.php`, `resources/css/cardapio.css`, `resources/css/marca/forno-aceso.css` |
| 3 | Cabeçalho fixo, faixa com chips reais, abas fixas com scrollspy, subcategorias fixas, busca (nome + ingredientes, sem acento) | `cardapio/partials/{cabecalho,apresentacao,abas}` |
| 4 | Card único (`lista`/`vitrine`) + `botao-adicionar` + `foto` + `preco`; carrosséis de mais pedidos (ranking), relâmpago e promoções; grade de 2 colunas no desktop. Removidos `promo-relampago-card`, `promo-adicional-card`, `categoria-button` | `components/cardapio/*`, `app/Support/Cardapio/ProdutoCardapio.php` |
| 5 | "Monte sua pizza" com SVG que se divide, busca de sabor, ingredientes, regra de preço e preço "em sabores"; sheets de montagem, oferta e **detalhe do produto**; `/Produto/{id}` → `/Cardapio?produto={id}` (404 fora do cardápio); view `app/produto/show` removida | `cardapio/partials/sheet-*`, `ProdutoController::show` |
| 6 | Barra inferior com "Faltam R$ X para entrega grátis", sacola lateral fixa no desktop, observação recolhível, estado vazio | `cardapio/partials/sacola*` |
| 7 | Checkout com "Como você quer receber?" primeiro e taxa no próprio card, cupom recolhido, resumo fixo, botão verde-WhatsApp; troco com milhar corrigido na mensagem (R$ 1.000,00 saía R$ 1,00) | `cardapio/partials/{checkout,sucesso}`, `cardapio/script.blade.php` |

Contratos mantidos: `Alpine.store('cart')` com os mesmos métodos e o mesmo formato no
`localStorage` (só acréscimos: `ultimoPedido`, `detalhe`, `aviso`, `relogio`,
`progressoFrete`, `statusLoja`, `svgPizza`…), payload do checkout idêntico, âncoras e
textos verificados pelos testes, modo mesa.

### 15.2 Medições (ambiente local, Chromium, 390×844)
- Abertura: **~36 MB → 2,6 MB** transferidos (29 imagens na primeira carga, todas miniaturas).
- HTML: 47 KB → 70 KB comprimido (cards mais ricos e o JSON do detalhe; compensado com folga pelas imagens).
- CSS do cardápio: 22 KB comprimido (o `app.css` do sistema tem 39 KB e não é mais carregado aqui).

### 15.3 Testes
- Novos: `CardapioRedesignTest` (8) e `MiniaturaProdutoTest` (7).
- Suítes do cardápio, promoções, sabores, mesa por QR e seletor: verdes.
- QA no Playwright (local): primeira dobra, loja fechada, abas, busca, inteira, meia a meia (R$ 75,90 + R$ 64,00 = R$ 69,95), sacola, checkout com resposta **simulada** (nada gravado), tela de sucesso, carrinho antigo do `localStorage`, `/Produto/{id}`, mesa fechada, desktop. Zero erros de JS.
- Sem teste automatizado de JS (o projeto não tem infraestrutura para isso): o troco com milhar foi verificado no Playwright.

### 15.4 Deploy
1. `npm run build` + `public/build/` no commit (entrada nova `resources/css/cardapio.css` no Vite).
2. `public/img/marca/` no commit.
3. No servidor, depois do deploy: `php artisan produtos:miniaturas --dry-run` e depois `php artisan produtos:miniaturas` (só cria arquivos em `storage/app/public/fotos_produtos/miniaturas`).
4. Reiniciar o PHP-FPM (OPcache).
5. Recomendado (D6, fora do código): `Cache-Control` longo para `/storage/fotos_produtos/miniaturas/` e `/build/`.

## 16. Terreno preparado para os próximos painéis

| Superfície | O que já está pronto | Próximo passo sugerido |
|---|---|---|
| **Mesa por QR** (cliente) | Usa a mesma tela: herdou todo o redesign. Parciais `mesa-cliente/*` restilizadas para os tokens; faixa da mesa dentro do cabeçalho fixo | Redesenhar o painel "Minha mesa" (`mesa-cliente/modais`): rodadas como linha do tempo, conta com divisão visual, chamados com estado claro |
| **Garçom** (`/garcom`, Filament) | `PedidoProdutoSelector` já usa as miniaturas (fotos de 56 px deixam de baixar originais de até 3 MB); tokens em `resources/css/marca/forno-aceso.css` | Importar `forno-aceso.css` no `resources/css/filament/garcom/theme.css` e aplicar a cor primária do painel (`->colors()` no `GarcomPanelProvider`) com a brasa; revisar a grade de produtos do seletor com o card `vitrine` |
| **Atender Pedido** (admin) | Mesmo seletor (miniaturas) e `ProdutoCardapio` como fonte única de nome/preço/foto para telas que mostram produto | Quando a frente em andamento no `AtenderPedido.php` for commitada: aplicar os tokens no tema do admin só nesta página (ou como cor primária geral, se aprovado) e reaproveitar o "Monte sua pizza" (SVG + regra de preço) no fluxo de sabores do balcão |

`ProdutoCardapio`, `MiniaturaProdutoService`, os tokens e os componentes `x-cardapio.foto`
e `x-cardapio.preco` foram escritos sem dependência do cardápio público, para serem
reutilizados nessas telas.
