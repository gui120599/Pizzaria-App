# Prompt de implementação — Acompanhamento do pedido, Garçom e Atender Pedido

Você vai implementar o plano `docs/plano/plano-acompanhamento-garcom-atender-pedido.md`
no RazelFood (Laravel 12 + Filament 4 + Livewire 3 + Alpine + Tailwind v4, rodando no
Sail). A auditoria já foi feita e as decisões estão tomadas (seção 4.1 do plano).
**Não refaça a auditoria; execute o plano.**

## 1. Leia antes de qualquer código
1. `docs/plano/plano-acompanhamento-garcom-atender-pedido.md`, inteiro (diagnóstico T1–T7 e
   G1–G10, propostas, etapas, decisões).
2. `docs/plano/redesign-cardapio-online-emporio-da-pizza.md`, seções 5 (tokens "Forno
   aceso"), 15 e 16: o acompanhamento usa a mesma identidade do cardápio já em produção.
3. `CLAUDE.md`, `.ai/rules` (se existir) e as skills `laravel-best-practices`,
   `testing-best-practices` e `tailwindcss-development`. O projeto usa **Tailwind v4**, apesar
   do que diz a skill.
4. Rode `git status` e `git log --oneline -10`. Há trabalho de **outras frentes** no working
   tree (hoje: `RelatorioSessaoCaixaService`, `PedidoObserver`, `EventServiceProvider`,
   `composer.*`, `config/app.php`, `config/auth.php`, `CLAUDE.md`, views de sessão de caixa).
   **Não toque nesses arquivos.** Se um arquivo que você precisa alterar já estiver sujo
   antes de você começar, pare e me avise.

## 2. Decisões (seção 4.1 do plano)
- **A1:** link sem assinatura (os já enviados) mostra **só o status**: estado atual e linha
  do tempo, **sem** nome, endereço, itens, valores nem pagamento.
- **A2:** brasa da marca (`#C2410C`) como cor **primária** nos painéis **garçom e admin**.
- **A3:** seleção de sabores no navegador **entra nesta rodada**, junto com a Etapa E
  (Etapa E+I).
- **A4:** "Onde retirar" usa o endereço e a localização no Google Maps **cadastrados na
  empresa** (Etapa L): o endereço reaproveita as colunas `empresa_endereco_*`, que hoje só
  se editam no formulário legado, e a localização ganha a coluna `empresa_google_maps_url`.
  Sem endereço preenchido, o card mostra só o horário.
- **A5:** você está **autorizado a criar um usuário local** (só no banco de
  desenvolvimento) para fotografar os painéis. Veja a seção 5.
- **A6:** o formato da pizza vira **campo da categoria** (`categoria_formato_pizza`,
  Etapa K). A regra pelo nome "quadrad" sai do código.

## 3. Regras invioláveis
- **Regra de negócio intocada:** `StatusPedidoEnum`/`PedidoStatusService` (fluxo e
  transições), precificação do `PedidoProdutoSelector` (pedido novo sem relâmpago etc.),
  estoque, cortesia, cliente por item na mesa, PIN de gerente, cobrança Stone, cupom,
  frete. A mudança nas telas internas é **de apresentação**.
- **Não mude o fluxo de status.** A linha do tempo **lê** o que existe
  (`Pedido::tipoAtendimento()`, `StatusPedidoEnum::proximo($exigeEntrega)`,
  `campoDataHora()`, `config('pizzaria.pedidos.usa_estagio_transporte')`).
- **Segurança do link (Etapa A):** a decisão "completo × só status" vem da **assinatura da
  URL no primeiro request**. No componente Livewire, guarde-a numa propriedade
  `#[Locked]`, senão o navegador muda o valor nos requests de `wire:poll` e vê os dados.
  Escreva teste para isso.
- **Remover componente, parcial ou view:** grep **sem truncar a saída** e rode
  `vendor/bin/sail artisan view:cache` (depois `view:clear`). O deploy roda `view:cache`;
  em 2026-10-30 um componente removido ainda usado no legado derrubou duas telas.
  `tests/Feature/ViewsCompilamTest` deve passar.
- **Nenhuma dependência nova** (PHP ou JS) sem pedir. Nenhuma API paga do Google (Maps
  Embed/JavaScript com chave): o mapa é um **link** que abre o Google Maps.
- **Migrations:** só as duas desta rodada (Etapas K e L), com `down()` funcional, criadas
  com `vendor/bin/sail artisan make:migration`. Rode-as no banco de desenvolvimento e no
  isolado de testes; **nunca** contra produção.
- **Dados reais:** não invente previsão de tempo, avaliações ou contagens.
- Tudo via Sail. `vendor/bin/sail bin pint --dirty --format agent` depois de mexer em PHP.
- **Git:** sem commit nem push sem ordem explícita. Quando eu pedir: nunca `git add -A`,
  liste os arquivos por nome, rode `npm run build` e inclua `public/build/`.

## 4. Etapas, nesta ordem (seção 3 do plano)

Ordem: **A → B → L → C (parar) → H → K → 0 → D → A2 → E+I → F → G.**

**A. Link de acompanhamento assinado (T1).** Crítico.
- `Pedido::linkAcompanhamento(): string` com `URL::signedRoute('pedido.acompanhar', ['id' => $this->id])`,
  sem expiração, no mesmo padrão de `Pedido::linkScanEntrega()`.
- A rota `pedido.acompanhar` continua aceitando o link sem assinatura (A1): assinatura
  válida → tela completa; ausente ou inválida → tela "só status". Passe esse booleano ao
  Livewire `AcompanhamentoPedido` como `#[Locked]`.
- **Trocar todos os geradores do link** para `linkAcompanhamento()`:
  - `resources/views/cardapio/script.blade.php`: mensagem do WhatsApp e
    `ultimoPedido.linkAcompanhar`. O checkout passa a devolver `link_acompanhamento` em
    `CardapioCheckoutController::checkout`, e o JS usa esse valor em vez de montar
    `/acompanhar/{id}`.
  - `app/Filament/Support/PedidoStatusActions.php` (`jsCopiarLink`) e
    `resources/views/filament/pages/painel-pedidos/card.blade.php`.
  - `app/Filament/Pages/AtenderPedido.php` (ação de copiar link).
  - `resources/views/app/pedido/partials/list.blade.php` (legado).
  - `resources/views/livewire/confirmacoes-pedidos.blade.php`.
  Rode `grep -rn "pedido.acompanhar\|/acompanhar/" app resources routes` (sem truncar) no
  fim: não pode sobrar link sem assinatura gerado pelo sistema.
- Testes: assinado mostra nome e endereço; sem assinatura e com assinatura adulterada
  mostram só status (sem nome, endereço, itens); `#[Locked]` não aceita alteração pelo
  cliente; checkout devolve o link assinado.

**B. Linha do tempo por tipo (T2, T3, T6).**
- Uma classe (ex.: `App\Support\Pedido\EtapasAcompanhamento`) monta as etapas a partir de
  `tipoAtendimento()` e da config do estágio de transporte:
  - **Retirada:** Pedido recebido → Confirmado → Em preparo → **Pronto para retirar** →
    **Retirado**. Sem etapa de entrega.
  - **Delivery:** … → Pronto → Saiu para entrega (só com o estágio ligado) → Entregue.
  - **Mesa:** Recebido → Em preparo → Servido.
- Cada etapa concluída mostra o horário real (`campoDataHora()`). FINALIZADO = última etapa
  concluída. CANCELADO tem tela própria.
- A mesma classe será reaproveitada na Etapa F (garçom › retirada); não a acople ao
  Livewire.
- Testes: retirada sem etapa de entrega; delivery com e sem o estágio de transporte;
  horários; finalizado; cancelado.

**L. Endereço e mapa da loja (A4).** Antes da C, que usa estes dados.
- Migration: `empresa_google_maps_url` (string 500, nullable) em `empresas`. Acrescente ao
  `$fillable` da `Empresa`. **Não** crie colunas de endereço novas: as `empresa_endereco_*`
  já existem.
- Métodos na `Empresa`:
  - `enderecoRetirada(): ?string`: "Rua, número – complemento – bairro – CEP", só com as
    partes preenchidas; `null` sem rua.
  - `linkComoChegar(): ?string`: o `empresa_google_maps_url` cadastrado; sem ele e com
    endereço, `https://www.google.com/maps/search/?api=1&query=` + endereço codificado; sem
    os dois, `null`.
- Edição no Filament: nova seção **"Endereço da loja"** na página existente
  `ConfiguracaoPedidos` (mesmo padrão de `mount()`/`save()` dela; não crie página nova, para
  não exigir permissão nova em produção). Campos: rua, número, complemento, bairro, CEP (use
  o campo de CEP do `leandrocfe/filament-ptbr-form-fields`, já instalado) e o link do Google
  Maps com texto de ajuda ("No Google Maps, abra a loja › Compartilhar › Copiar link").
- **Cidade (IBGE) e UF não entram neste formulário**: a UF alimenta a consulta à SEFAZ
  (`SefazNfephpClient`). Mostre-as só como texto, se quiser.
- **Validação do link:** obrigatório `https://` e domínio do Google Maps (`google.com`,
  `www.google.com`, `maps.google.com`, `maps.app.goo.gl`, `goo.gl`). Ele aparece numa
  página pública: não pode virar link para qualquer site.
- O formulário legado (`EmpresaController`) continua igual; o `update()` dele não envia a
  coluna nova, então não a apaga. Confira isso num teste.
- Testes: salvar endereço e link pela página; link de outro domínio recusado;
  `enderecoRetirada()` com partes vazias; `linkComoChegar()` nos três casos; o update
  legado preserva `empresa_google_maps_url`.

**C. Visual do acompanhamento (T4, T5, T7).**
- `x-cardapio-layout` (layout do cardápio) com os tokens e as fontes da marca; nada do
  `guest`, do teal ou do logo PNG de 1,2 MB.
- Ordem: cabeçalho → estado atual em destaque (frase por tipo e etapa) → linha do tempo →
  "Onde retirar" (retirada, A4: `enderecoRetirada()` + botão "Como chegar" com
  `linkComoChegar()`, abrindo em nova aba com `rel="noopener"`) ou endereço e taxa
  (delivery) → itens recolhíveis →
  pagamento → "Falar com a loja" → avaliações (só no fim, quando entregue) → rodapé
  "atualiza a cada 30 s".
- A versão "só status" (A1) usa o mesmo visual, sem as seções de dados.
- **Pare e me mostre** capturas (390 px e 1440 px) das variações: retirada em preparo,
  retirada pronta, delivery a caminho, entregue, cancelado e só status.

**H. Regra de endereço no cardápio (G10).**
- Em `CardapioService`, `requer_endereco` passa a vir da coluna
  `opcaoentrega_requer_endereco`, não do nome da opção. Teste com uma opção cujo nome não
  contém "entrega" mas exige endereço.

**K. Formato da pizza na categoria (A6).**
- `App\Enums\FormatoPizzaEnum` (`REDONDA`, `QUADRADA`) com `HasLabel`, no padrão de
  `RegraPrecoSaboresEnum`.
- Migration: `categoria_formato_pizza` (string, default `REDONDA`, não nula) em
  `categorias`. Na mesma migration, marque `QUADRADA` nas categorias cujo nome, sem acento
  e em minúsculas, contém "quadrad" (a regra de hoje, feita em PHP com `Str::ascii`). Assim
  a produção continua igual depois do deploy.
- `Categoria`: `$fillable` e cast para o enum. O formato é **da própria categoria** e não
  segue `categoria_herda_sabores`: uma filha quadrada pode herdar as quantidades de um pai
  redondo.
- `CategoriaResource`: `Select` "Formato da pizza" logo depois de "Preço da pizza de vários
  sabores", visível com `categoria_permite_sabores`, sem placeholder.
- `CardapioService`: `'formato' => $c->categoria_formato_pizza->value` (em minúsculas, o JS
  espera `redonda`/`quadrada`). Remova a regra pelo nome e o comentário dela.
- Testes: categoria com o campo `QUADRADA` e nome sem "quadrada" sai quadrada no cardápio;
  o default sai redonda; ajuste `CardapioRedesignTest::test_categoria_quadrada_monta_a_pizza_no_formato_quadrado`
  para usar o campo.

**0. Auditoria visual dos painéis (A5)**, antes de mexer nas telas internas.
- Crie **um** usuário local com `vendor/bin/sail artisan tinker`. A tabela `users` exige
  `name_first`. Dê o papel `Admin`: ele está em `User::PANEL_ROLES` (admin) e em
  `User::GARCOM_PANEL_ROLES` (garçom). Para o garçom, use o modo "senha" do
  `LoginGarcom` ou defina `user_pin` pelo `PinService`.
  Ex.: e-mail `qa.redesign@local.test`. Anote no relatório como removê-lo.
- **Nunca** crie usuário em produção nem use dados de clientes reais nas capturas que
  forem para o repositório.
- Capture com Playwright (Chromium em `~/.cache/ms-playwright/chromium-1140`, módulo em
  `~/.npm/_npx/*/node_modules/playwright`), em 390 px e 1440 px:
  - Garçom: Mapa de mesas, Atender mesa (abas Pedir, Rodadas e Conta), Atender retirada
    (montando e enviado), modal de sabores do seletor.
  - Admin: Atender Pedido (novo e edição, retirada e entrega).
  Guarde as capturas fora do repositório (scratchpad) e use-as no "antes e depois".

**D. Seletor: miniaturas e legibilidade (G1–G3).**
- `resources/views/livewire/pedido-produto-selector.blade.php`: a grade usa
  `$produto->getImagemUrl()` (linha ~117) → `getMiniaturaUrl(80)` + `loading="lazy"` +
  `width`/`height`.
- Textos mínimos de 12 px (hoje `text-[9px]`), "+" com área de toque de 44 px na cor
  primária do painel (sem `bg-green-500`), modo claro **e** escuro.
- Os testes do seletor (`PedidoProdutoSelector*Test`) devem continuar verdes **sem
  alteração**.

**A2. Cor primária dos painéis.**
- `GarcomPanelProvider` (hoje `Color::Orange`) e `AdminPanelProvider` (hoje `Color::Amber`)
  → paleta gerada a partir de `#C2410C`. Confira no `search-docs` a API do Filament 4
  (`Color::hex()` ou equivalente).
- Verifique o contraste do botão primário nos modos claro e escuro e reveja as telas que
  usam `primary` com outra intenção (avisos, por exemplo).
- Os tokens de marca estão em `resources/css/marca/forno-aceso.css`. Importe-os nos temas
  Filament **só** se for usar as classes; não traga o fundo escuro do cardápio para os
  painéis.

**E+I. Seletor: montagem de pizza no navegador (G4, G5, A3).**
Hoje cada toque no modal de sabores (`toggleSabor`) é uma ida e volta ao servidor. O
objetivo é o toque responder na hora, com o desenho da pizza, e o servidor continuar dono
do preço e da validação.
- **Desenho compartilhado:** extraia `svgPizza()` e `svgPizzaQuadrada()` de
  `resources/views/cardapio/script.blade.php` para `resources/js/pizza-svg.js`, como funções
  puras (`desenharPizza({ formato, quantidade, sabores: [{ foto, nome }] })` → string SVG).
  O cardápio passa a chamá-las, com o **mesmo resultado visual** (compare capturas). Nos
  painéis Filament, o `viteTheme` só carrega CSS: carregue o módulo por render hook com
  `@vite` ou por `FilamentAsset` (confira no `search-docs` o jeito do Filament 4) só no
  garçom e no admin.
- **Seleção no Alpine:** o modal guarda os ids escolhidos num estado Alpine; marcar,
  desmarcar, o desenho, o contador "2 de 3" e a prévia do preço (regra `MEDIA`/`MAIOR`
  sobre o `preco` de cada sabor, que já vem em `saboresProdutos`) não chamam o servidor.
- **O que continua indo ao servidor:**
  - `setModoSabores()` (trocar a quantidade muda as perguntas).
  - No modo inteira (1 sabor), escolher o sabor: perguntas e ofertas dependem do produto.
    Uma chamada por escolha, não por toque.
  - Responder perguntas (`alternarResposta`), como hoje.
  - Confirmar: `confirmarSabores(?array $ids = null)`. Com `$ids`, o servidor valida que
    todos estão em `saboresProdutos`, sem repetição, e que a contagem bate com o modo, grava
    em `saboresSelecionados` e segue o fluxo atual (`LancamentoItemPedidoService::precificar`).
    Sem `$ids`, comportamento atual: os testes que usam `toggleSabor()` +
    `confirmarSabores()` continuam passando **sem alteração**. Mantenha `toggleSabor()`.
- **O preço do Alpine é só prévia.** O valor lançado é o do servidor; se divergir, vale o do
  servidor (não confie em nada vindo do navegador além dos ids).
- **Re-render do Livewire:** responder uma pergunta re-renderiza o seletor. A seleção do
  Alpine não pode se perder nem "voltar" para a do servidor. Teste isso no navegador:
  escolha 2 sabores, responda uma pergunta, confirme.
- Sabor sem estoque (onde o modal já bloqueia hoje) continua bloqueado.
- Mostre a linha da regra de preço ("valor pela média dos sabores" ou "do sabor mais
  caro") e use o formato da categoria (Etapa K) para o desenho.
- Testes novos: `confirmarSabores([ids])` lança o item com o preço do servidor; id fora da
  categoria, repetido ou contagem errada é recusado sem lançar; o modo inteira continua
  carregando as perguntas e ofertas do produto. QA no navegador em 390 px (garçom) e
  1440 px (admin), incluindo a pizza quadrada.

**F. Garçom: mesa e retirada.**
- Atender mesa: cabeçalho em dois blocos (G9). Rodadas como linha do tempo com os status
  reais. Conta só reorganizada.
- Atender retirada: depois do envio, a linha do tempo da Etapa B (retirada, sem entrega);
  "Entregue ao cliente" vira o passo final "Retirado". A ação e a regra continuam as
  mesmas.

**G. Atender Pedido.**
- Tipo de atendimento visível no topo; **na retirada, sem endereço e sem linha de taxa de
  entrega** (G7). Confira no `entrega-pagamento-picker` o que já se esconde.
- Um só carrinho visível quando `carrinhoTopo` (G6).
- Somente leitura com aviso e campos `disabled`, sem `opacity`/`pointer-events: none`
  inline (G8).
- A frente "Divergência endereço pedido × cadastro" mexe em `AtenderPedido::mount`. Se
  houver alteração dela não commitada nesse arquivo, pare e me avise antes de editar.

## 5. Testes e QA
- **Banco isolado:**
  `vendor/bin/sail exec -u sail -e DB_DATABASE=testing_cardapio laravel.test php artisan test --compact --filter=…`.
  Não use o banco `testing` padrão, porque outras sessões o usam.
- Ao fim de cada etapa: testes da etapa + `--filter='Cardapio|Acompanhamento|ProdutoSelector|Garcom|AtenderPedido|MesaCliente|Sabores|Perguntas|Categoria|ConfiguracaoPedidos|ViewsCompilam'`.
- Banco isolado novo depois das migrations: rode `migrate` nele antes dos testes.
- No fim: **suíte completa**, com a saída **inteira** salva em arquivo, sem `tail` cortando
  a lista de falhas. Há 21 falhas preexistentes do scaffold Breeze (factory de `User` sem
  `name_first`, `ExampleTest` com redirect de `/`). Qualquer outra falha é sua.
- QA no Playwright, comparando antes e depois. No acompanhamento, crie o pedido de teste
  **no banco local** (factory ou tinker) com cada tipo e status; **nunca** acesse links de
  pedidos reais de produção.

## 6. Critério de pronto
- Nenhum link de acompanhamento gerado pelo sistema sai sem assinatura; os antigos mostram
  só o status.
- Retirada sem etapa de entrega, com os horários reais.
- Acompanhamento com a identidade do cardápio.
- Seletor sem fotos originais e com textos legíveis; sabores marcados na hora, sem ida ao
  servidor por toque, e preço final sempre do servidor.
- Pizza quadrada pelo campo da categoria, com as quadradas de hoje marcadas pela migration.
- "Onde retirar" com o endereço e o link do Google Maps editáveis no Filament.
- Brasa como primária no garçom e no admin, em modo claro e escuro.
- Telas do garçom e do Atender Pedido conforme o plano, com regras de negócio intactas.
- `ViewsCompilamTest` e a suíte verdes (exceto as 21 preexistentes).
- Seção "Execução" no fim do plano com o que foi feito, as medições, como remover o usuário
  local e os passos de deploy (`git pull`, **`migrate --force`**, `view:cache`,
  `filament:optimize`, `queue:restart`, OPcache) e o lembrete de cadastrar o endereço e o
  link do Google Maps em Configurações › Pedidos depois do deploy.
