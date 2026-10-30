# Prompt de implementação — Redesign do Cardápio Online (Fase 2)

Você vai implementar o redesign do cardápio público (`/Cardapio`) do Empório da Pizza, no
RazelFood (Laravel 12 + Blade + Alpine do Livewire + Tailwind v4, rodando no Sail). A
auditoria e o plano já foram feitos e aprovados. **Não refaça a auditoria.** Execute o plano.

## 1. Leia antes de qualquer código
1. `docs/plano/redesign-cardapio-online-emporio-da-pizza.md`: o plano aprovado,
   inteiro. As seções 2.4 (contratos), 5 (direção de arte e tokens), 6 (especificação dos
   componentes), 7 (arquitetura e arquivos), 8 (etapas), 10 (riscos) e 11 (testes) são
   obrigatórias.
2. Os mockups de referência visual (são o alvo de aparência, não a especificação de
   comportamento):
   - `docs/plano/mockups/cardapio-mobile.png` (+ `cardapio-mobile.html` com CSS e tokens):
     três telas no celular (cardápio, "Monte sua pizza" e sacola).
   - `docs/plano/mockups/cardapio-desktop.png` (+ `cardapio-desktop.html`): desktop
     de 1440 px com grade e sacola lateral.
   Os HTMLs têm as medidas, os raios, as cores e as tipografias exatas. Use-os como
   referência de valores, **não copie o HTML estático para a view**: a view continua
   Blade + Alpine com os dados reais.
3. `CLAUDE.md`, `.ai/rules/index.md` e toda regra cujo glob cubra os arquivos que você
   vai tocar (rode `grep -rin 'cardapio\|blade\|tailwind' .ai/rules`, se existir).
4. As skills `tailwindcss-development`, `laravel-best-practices` e `testing-best-practices`.

## 2. Decisões já tomadas (seção 9 do plano)
- **D1** Direção "Forno aceso": aprovada (tokens da seção 5.2).
- **D2** Fonte de título Fraunces (fonts.bunny.net, pesos 600/700) + Figtree no corpo:
  aprovada.
- **D3** Pizza "tamanho primeiro": **adiada**. Mantenha subcategoria = tamanho; só
  restilize (abas Grande/Média/Quadrada/Brotinho).
- **D4** `/Produto/{id}` redireciona para `/Cardapio?produto={id}`, que abre o sheet de
  detalhe: aprovada. Produto não visível no cardápio → 404.
- **D5** Endereço: **mantenha o campo único** (`endereco`). Só acrescente
  `autocomplete="street-address"` e 16 px.
- **D6** Cache no servidor: **não faça** (é configuração de servidor). Só documente no
  relatório final.
- **D7** Não exiba número de vendas. Use o ranking "nº 1, nº 2…".
- **D8** Privacidade do `lookup-cliente`: **fora do escopo**. Não mexa.

## 3. Regras invioláveis
- **Não mude regra de negócio.** `CardapioCheckoutController`,
  `LancamentoItemPedidoService`, `PrecificadorService`, rotas, nomes de rota, throttles,
  reCAPTCHA, horário, cupom, frete, sabores, perguntas, adicionais e ofertas ficam
  intactos.
- **`Alpine.store('cart')` e `Alpine.store('mesa')`** mantêm nomes, métodos, getters e o
  **formato dos itens no `localStorage`**. Só são permitidos acréscimos aditivos (ex.:
  `ultimoPedido` para a tela de sucesso, `busca`, getter `faltaParaFreteGratis` usando a
  mesma conta de `valorFrete`).
- **Nenhum preço novo calculado no cliente.** O que for exibido vem de
  `precoResolvido()`, `precoFracao`, `precoSabores()` e dos getters atuais. O nome
  enviado ao carrinho continua `nomeExibicao()`.
- **A mesma view serve a página da mesa por QR** (`MesaClienteController::show` com
  `mesaCliente`). Toda etapa precisa funcionar nos dois modos. Nas parciais
  `mesa-cliente/*`, só troque classes de estilo.
- **Não altere `layouts/guest.blade.php`** (login, erros e acompanhamento usam). Crie
  `layouts/cardapio.blade.php`.
- **Contratos de teste** (seção 2.4): `id="categoria_{id}"` (pai antes da filha),
  textos `RELÂMPAGO` e `restam`, `$store.cart.abrirSabores(`, serialização `@js` de
  `_categoriasComSabores` (`preco":…`, `precoFracao":…`),
  `const _promocoesAdicionais` e as chaves de `viewData`.
- **Imagens:** nunca altere nem apague `produto_foto` ou o arquivo original. Miniaturas
  são arquivos **novos** em `fotos_produtos/miniaturas/{160,320,640}/`, com *fallback*
  para a original. Use `intervention/image`, que já é dependência direta. **Nenhuma
  dependência nova** (PHP ou JS) sem pedir.
- **Não invente dados comerciais** (avaliações, "x vendidos", descontos, horários). Chips
  e selos saem dos dados reais (`_horarios`, `_opcoesEntregas`, `$top10Ids`,
  `PrecoResolvido`, `restam`).
- **Produção:** não rode nada contra produção e não envie pedido real. QA e checkout de
  teste só no ambiente local.
- Tudo via Sail (`vendor/bin/sail …`). Rode `vendor/bin/sail bin pint --dirty --format
  agent` ao mexer em PHP.
- **Git:** não faça commit nem push sem ordem explícita. Quando eu pedir commit: nunca
  use `git add -A`; liste os arquivos por nome; inclua `npm run build` + `public/build/`
  (produção não tem npm); o working tree já tem arquivos sujos de outras frentes, então
  não os inclua.

## 4. Execução por etapas (seção 8 do plano)
Faça **uma etapa por vez, nesta ordem**. Ao fim de cada etapa: testes verdes, QA visual e
um relatório curto (o que mudou, arquivos, resultados de teste, capturas antes e depois,
pendências). **Pare e me mostre o resultado ao fim das Etapas 2, 5 e 7** antes de
seguir.

- **Etapa 0. Ganhos rápidos de desempenho.** `loading="lazy"` + `decoding="async"` +
  `width/height` em toda imagem fora da primeira dobra; logo WebP otimizado (~480 px,
  gerado do PNG atual, sem mudar a arte) + favicons; memoização de
  `Produto::getImagemUrl()`. Meça bytes transferidos na abertura e LCP antes e depois
  (Chromium local) e registre os números.
- **Etapa 1. Miniaturas WebP.** Comando `produtos:miniaturas {--dry-run}`, geração ao
  trocar `produto_foto` (Filament e `saveFoto` legado), componente
  `x-cardapio.foto` com `srcset` e placeholder (monograma, sem o `Sem Imagem.png`).
- **Etapa 2. Fundação + segurança de conversão.** Layout `cardapio` (title "Empório da
  Pizza · Cardápio", meta description, Open Graph, `theme-color #14100D`), entrada
  `resources/css/cardapio.css` no Vite com os tokens `@theme`, rolagem da página (fim
  do `h-[100vh]` + `overflow-y-auto` aninhado), **tela de sucesso pós-checkout** com
  link `<a href="https://wa.me/...">` + "Acompanhar pedido" (mantendo o `window.open`
  automático), **status aberto/fechado no topo** e toque em "+" com a loja fechada
  mostrando aviso em vez de carrinho vazio, avaliações movidas para o rodapé, campos
  com 16 px + `autocomplete`, `aria-label` e anel de foco.
- **Etapa 3. Cabeçalho, abas, scrollspy e busca** (seções 6.A e 6.B).
- **Etapa 4. Card único + vitrines** (seções 6.C e 6.D): `x-cardapio.produto-card` com
  `variante`, `x-cardapio.botao-adicionar` (a lógica de "+", hoje copiada 5 vezes),
  carrossel "Os mais pedidos" com ranking, grade de 2/3 colunas no desktop. Remova
  `promo-relampago-card`/`promo-adicional-card` só depois que as variantes substituírem
  todos os usos.
- **Etapa 5. Personalização** (seção 6.E): sheet "Monte sua pizza" com SVG da pizza que
  se divide, busca de sabor, ingredientes (acrescente `'ingredientes' =>
  $p->produto_codimentacao` em `CardapioService::categoriasComSabores`), linha da regra
  de preço (`regraPreco` MEDIA/MAIOR) e preço "como meia" quando `precoFracao ≠ preco`;
  sheets de montagem, oferta e detalhe do produto (D4).
- **Etapa 6. Carrinho** (seção 6.F): barra inferior com "Faltam R$ X para entrega
  grátis", painel lateral fixo no desktop (≥ 1024 px), item redesenhado, observação
  recolhível, estado vazio.
- **Etapa 7. Finalização** (seção 6.G): "Como você quer receber?" primeiro, com a taxa no
  próprio card; cupom recolhido (aberto automaticamente com `?cupom=`); resumo fixo;
  botão final verde-WhatsApp. Corrija o troco com milhar na mensagem do WhatsApp
  (`parseFloat("1.000,00")`), com teste.
- **Etapa 8 (opcional, só se eu pedir).** JS do store para módulo Vite.

## 5. Testes e QA obrigatórios
- **Feature (PHPUnit, banco isolado do projeto):** os casos listados na seção 11 do
  plano. Rode a cada etapa:
  `vendor/bin/sail artisan test --compact --filter='Cardapio|PromocaoRelampago|QuantidadesSabores|PedidoMesaCliente|PainelGarcomMesaQr|PromocaoAdicional'`.
- **QA visual (Playwright, Chromium local, já instalado no cache do npx):** roteiro em
  390×844 e 1440×900: primeira dobra; abas e scrollspy; busca com e sem resultado; pizza
  inteira; meia a meia (confira o preço = média em centavos); item com pergunta
  obrigatória; oferta aceita e recusada; `?cupom=`; entrega abaixo e acima de R$ 50;
  retirada; dinheiro com troco; checkout local até a tela de sucesso; loja fechada (sem
  simular horário); **modo mesa**; carrinho "antigo" pré-carregado no `localStorage`.
  Compare cada tela com os mockups e com a versão anterior, e corrija regressões antes de
  seguir.
- **Acessibilidade:** checkout navegável só por teclado; `prefers-reduced-motion`
  respeitado; contraste conforme os tokens.

## 6. Critério de pronto
Visual fiel aos mockups e aos tokens; primeiro produto visível acima de ~300 px no
celular; fluxo completo funcionando nos modos delivery e mesa; preços exibidos iguais aos
cobrados; nenhuma regressão nos testes; carga da primeira dobra muito abaixo dos ~39 MB
atuais (com o número medido); decisões e limitações registradas numa seção "Execução" no
fim do documento do plano.
