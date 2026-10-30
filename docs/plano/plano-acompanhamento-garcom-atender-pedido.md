# Plano — Acompanhamento do pedido, telas do Garçom e Atender Pedido

> **Status:** implementado em 2026-10-30 (ver "Execução" no fim), sem commit. Proposta original de 2026-10-29.
> Continua o redesign do cardápio (`docs/plano/redesign-cardapio-online-emporio-da-pizza.md`,
> seções 15 e 16). Itens **[Comprovado]** têm evidência no código ou no banco;
> **[Hipótese]** precisa de validação.

---

## 0. Feito junto com este plano: pizza quadrada no "Monte sua pizza"

- `CardapioService` manda `formato` (`quadrada`/`redonda`) por categoria de sabores. Como não
  existe campo para isso, a regra vem do nome: categoria com "quadrad" é quadrada.
- `svgPizzaQuadrada()` (em `cardapio/script.blade.php`) divide o quadrado em faixas verticais
  (2 e 3 sabores) ou numa grade 2×2 (4 sabores). Cada parte mostra o miolo da foto do sabor,
  porque as fotos são de pizzas redondas e a borda curva ficaria estranha. A redonda não mudou.
- Teste: `CardapioRedesignTest::test_categoria_quadrada_monta_a_pizza_no_formato_quadrado`.
- Se um dia houver outro formato (retangular, calzone), o ideal é um campo
  `categoria_formato` no cadastro em vez da regra pelo nome (decisão **A6**).

---

## 1. Acompanhamento do pedido (`/acompanhar/{id}`)

### 1.1 Como funciona hoje
- Rota pública `GET /acompanhar/{id}` → `app/acompanhamento/index` (layout `guest`) →
  Livewire `AcompanhamentoPedido` (68 linhas) + `livewire/acompanhamento-pedido.blade.php`
  (272 linhas), com `wire:poll` de 30 s enquanto o pedido não termina.
- As etapas ficam numa lista **fixa** no componente: Recebido → Confirmado → Preparando →
  Pronto → Saiu para entrega → Entregue.
- O link é enviado na mensagem do WhatsApp do checkout e aparece na nova tela de sucesso.

### 1.2 Diagnóstico
| # | Problema | Evidência | Impacto |
|---|---|---|---|
| T1 | **Qualquer pessoa vê qualquer pedido trocando o número na URL** | [Comprovado] Rota sem token e id sequencial (pedidos na casa de 59 mil). A tela mostra primeiro nome, **endereço**, itens, valores e pagamento | Vazamento de dado pessoal (LGPD); fácil de explorar |
| T2 | **Retirada mostra "Saiu para entrega" e "Entregue"** | [Comprovado] Lista fixa no componente. O fluxo real já sabe a diferença: `StatusPedidoEnum::proximo($exigeEntrega)` vai de PRONTO direto para ENTREGUE quando não há entrega, e `Pedido::tipoAtendimento()` distingue mesa, delivery e retirada | O cliente da retirada espera um entregador que não existe; "Entregue" para quem foi buscar soa errado |
| T3 | **Sem horário em cada etapa** | [Comprovado] Os horários existem (`pedido_datahora_abertura`, `_preparo`, `_pronto`, `_transporte`, `_entrega`, `_finalizado`) e não são exibidos | O cliente não sabe há quanto tempo o pedido está em cada fase |
| T4 | **Visual do sistema antigo** | [Comprovado] Layout `guest`, logo PNG de 1,2 MB, botões teal (contraste 2,49:1), selo com `animate-pulse` infinito | Quebra a experiência que acabou de ser redesenhada no cardápio |
| T5 | **Ordem confusa** | [Comprovado] Avaliações aparecem **acima** da linha do tempo; selo de status repete a etapa atual | O mais importante (onde está meu pedido) não é o primeiro |
| T6 | **Estágio "Em transporte" pode estar desligado** | [Comprovado] `config('pizzaria.pedidos.usa_estagio_transporte')` remove o estágio para todos | Linha do tempo com etapa que nunca acontece |
| T7 | **Retirada sem instrução de onde buscar** | [Comprovado] Nenhum dado da loja na tela, embora o modelo `Empresa` tenha endereço (`empresa_endereco_rua`, `_numero_endereco`, `_cep`…) | Ligação para a loja perguntando |

### 1.3 Proposta
**Etapas derivadas, não fixas.** Um método único (ex.: `Pedido::etapasAcompanhamento()` ou
uma classe `App\Support\AcompanhamentoPedido`) monta a linha do tempo pelo **tipo de
atendimento** e pela **config do estágio de transporte**:

| Tipo | Etapas exibidas |
|---|---|
| **Retirada** | Pedido recebido → Confirmado → Em preparo → **Pronto para retirar** → **Retirado** |
| **Delivery** | Pedido recebido → Confirmado → Em preparo → Pronto → Saiu para entrega* → Entregue |
| **Mesa** (se um dia usar o link) | Recebido → Em preparo → Servido |

\* só com `usa_estagio_transporte` ligado. Cada etapa concluída mostra o horário real
(`campoDataHora()` do enum já mapeia status → coluna). FINALIZADO conta como a última etapa
concluída; CANCELADO tem tela própria.

**Tela** (layout `x-cardapio-layout`, tokens "Forno aceso"):
1. **Cabeçalho**: logo WebP, "Pedido nº 1234", primeiro nome.
2. **Estado atual em destaque**: ícone grande, frase curta por tipo e etapa (ex. retirada
   pronta: "Pode vir buscar! Seu pedido está pronto no balcão"; delivery a caminho: "Saiu
   para entrega às 19:42"), sem tempo estimado (não há dado de previsão, então não inventar).
3. **Linha do tempo** com as etapas do tipo e os horários.
4. **Retirada**: card "Onde retirar" com o endereço da loja (cadastro da empresa) e o horário
   (T7); sem endereço do cliente. **Delivery**: card do endereço de entrega e da
   taxa.
5. **Itens** recolhível ("3 itens · R$ 81,90 ▾"), mesma formatação de sabores da sacola.
6. **Pagamento**: forma e troco.
7. **Ações**: "Falar com a loja" (WhatsApp) e, ao terminar, avaliações (movidas para o fim) e
   "Fazer novo pedido".
8. **Rodapé** discreto: "Atualiza sozinho a cada 30 s · última 19:44".

**Segurança (T1):** link assinado, no mesmo padrão já usado em `Pedido::linkScanEntrega()`
(`URL::signedRoute`), **sem migration**:
- `Pedido::linkAcompanhamento()` gera `URL::signedRoute('pedido.acompanhar', ['id' => $id])`.
  O checkout passa a devolver esse link, e a mensagem do WhatsApp e a tela de sucesso usam o
  link do servidor em vez de montar `/acompanhar/{id}` no navegador.
- Link **sem assinatura** (os que já foram enviados): mostra só o **status e a linha do
  tempo**, sem nome, endereço, itens nem pagamento (decisão **A1**).

### 1.4 Testes
Linha do tempo por tipo (retirada sem "Saiu para entrega"; delivery com e sem o estágio de
transporte); horários exibidos; cancelado; link assinado mostra dados, sem assinatura só o
status, assinatura adulterada → 403 ou só status; checkout devolve o link assinado.

---

## 2. Telas internas: Garçom (`/garcom`) e Atender Pedido (`/admin`)

### 2.1 Como funcionam hoje
- **Garçom › Atender mesa** (`AtenderMesa`, 745 linhas + view de 140): no celular, abas
  Pedir | Rodadas | Conta; no desktop, cardápio à esquerda e painel fixo à direita (Rodada
  atual | Rodadas | Conta). Poll configurável.
- **Garçom › Atender retirada** (`AtenderRetirada`, 298 + 248): escolhe Retirada ou Entrega,
  cliente, itens e envio; depois do envio mostra status, cobrança Stone e "Entregue ao
  cliente".
- **Admin › Atender Pedido** (`AtenderPedido`, 1.208 + 266): seletor (3/5 da tela), carrinho,
  cliente, entrega/pagamento, observação, resumo financeiro, cobrança Stone (modal próprio).
- As três telas usam o mesmo **`PedidoProdutoSelector`** (1.011 linhas + view de 736): busca,
  categorias, grade de produtos, modal de item, modal de edição e modal de sabores.
- Painéis Filament com modo claro e escuro. Cor primária: Orange no garçom, Amber no admin.

### 2.2 Diagnóstico
| # | Problema | Onde | Evidência |
|---|---|---|---|
| G1 | **Grade de produtos baixa a foto original** (até 3 MB cada) | Seletor | [Comprovado] `pedido-produto-selector.blade.php:117` usa `$produto->getImagemUrl()` direto no Blade (o PHP do componente já usa miniaturas desde `5e65a03c`) |
| G2 | **Textos de 9 px** (categoria, ingredientes, estoque, selos) | Seletor | [Comprovado] `text-[9px]` em vários pontos da grade |
| G3 | **"+" de 32 px verde** (contraste 2,28:1) num tablet ou celular de salão | Seletor | [Comprovado] `w-8 h-8 bg-green-500` |
| G4 | **Cada toque num sabor vai ao servidor** | Seletor › sabores | [Comprovado] `wire:click="toggleSabor(...)"` e `setModoSabores`. [Hipótese] lentidão perceptível no Wi-Fi do salão |
| G5 | **Montagem de pizza sem visual e sem a regra de preço** | Seletor › sabores | [Comprovado] lista com preços, sem o desenho que o cardápio ganhou e sem "valor pela média" |
| G6 | **Três lugares mostram o carrinho de jeitos diferentes** | Atender Pedido (card próprio), garçom (carrinho lateral), seletor (FAB + drawer) | [Comprovado] `carrinhoTopo`, `carrinho-lateral`, drawer do seletor |
| G7 | **Retirada no Atender Pedido mostra campos de entrega** | Atender Pedido | [Hipótese] depende do `entrega-pagamento-picker`; a linha "Taxa de entrega: Grátis" do resumo aparece sempre ([Comprovado], `atender-pedido.blade.php:102-105`) |
| G8 | **Somente leitura com `opacity: .6; pointer-events: none` inline** | Atender Pedido | [Comprovado] linha 16: bloqueia até a rolagem e a cópia de textos |
| G9 | **Cabeçalho da mesa é uma linha corrida de informações** | Atender mesa | [Comprovado] pessoas, tempo, garçom, QR, PIN e total na mesma linha |
| G10 | **Divergência de regra no cardápio** | `CardapioService` | [Comprovado] o cardápio decide se a opção exige endereço pelo **nome** ("entrega"/"deliver"), enquanto o pedido usa a coluna `opcaoentrega_requer_endereco`. Hoje coincidem; renomear uma opção quebraria |

**Limitação:** as telas internas foram auditadas pelo código. Para capturar telas reais é
preciso entrar nos painéis (login do admin e PIN do garçom). Fica como Etapa 0 abaixo.

### 2.3 Direção
- **Não aplicar o tema escuro do cardápio nos painéis.** São telas de trabalho com modo
  claro e escuro. A identidade entra como **cor primária** (brasa `#C2410C` como paleta
  Filament, `Color::hex()`), tipografia de título opcional e componentes com os mesmos
  princípios: foto grande e leve, toque de 44 px, preço legível e estados claros.
- **Mudar o seletor uma vez beneficia as três telas.** É onde está o maior ganho.
- **Regra de negócio intocada.** O seletor tem precificação própria (pedido novo sem
  relâmpago, por exemplo), estoque, cortesia, cliente por item na mesa, PIN de gerente e
  Stone. Tudo isso fica; a mudança é de apresentação, com uma exceção opcional (G4).

### 2.4 Proposta por tela
**Seletor (as três telas):**
1. Miniatura na grade (`getMiniaturaUrl(80)`) + `loading="lazy"` (G1). Ganho imediato, risco
   quase zero.
2. Card com foto 1:1, nome em 14–15 px, ingredientes em 12 px (2 linhas), estoque em 12 px,
   "+" com área de toque de 44 px na cor primária do painel (G2, G3).
3. Modal de sabores redesenhado: desenho da pizza (redonda ou quadrada) e linha da regra de
   preço. O desenho vira um módulo JS compartilhado com o cardápio
   (`resources/js/pizza-svg.js`), em vez de copiar o código (G5).
4. **Opcional (A3):** seleção dos sabores no navegador (Alpine) e **um** envio ao confirmar,
   em vez de uma ida e volta ao servidor por toque (G4). Muda a interação com o componente:
   exige testes do seletor e QA no salão.

**Garçom › Atender mesa:**
- Cabeçalho em dois blocos: "Mesa 12 · 4 pessoas · 38 min" e ações (QR, PIN, "Pediu a conta"
  como selo de destaque) (G9).
- Rodadas como linha do tempo (enviada → na cozinha → pronta → servida) usando os mesmos
  status. Conta com "sua parte" por pessoa já existente, só reorganizada.

**Garçom › Atender retirada:**
- Depois do envio, o mesmo componente de linha do tempo do acompanhamento (**retirada sem
  etapa de entrega**). "Entregue ao cliente" vira o passo final "Retirado".

**Admin › Atender Pedido** (coordenar com a frente de endereço em andamento; ver a memória
"Divergência endereço pedido × cadastro"):
- Tipo de atendimento no topo (Retirada | Entrega), escondendo endereço e taxa na retirada
  (G7).
- Um só carrinho visível (o card da página), sem o drawer duplicado do seletor quando
  `carrinhoTopo` (G6).
- Somente leitura com aviso fixo e campos `disabled`, sem `pointer-events: none` (G8).

### 2.5 Testes
O seletor já tem testes (`PedidoProdutoSelector*Test`): devem continuar verdes sem alteração
nas Etapas G1–G3 e G5. A3 (G4) exige testes novos de seleção de sabores. Atender Pedido:
retirada não exibe endereço e taxa; somente leitura sem `pointer-events`.

---

## 3. Etapas (priorizadas)

| Etapa | Entrega | Impacto | Complex. |
|---|---|---|---|
| **A. Segurança do acompanhamento** | Link assinado + tela só de status para links antigos (T1); checkout devolve o link | **Crítico** (dados pessoais expostos) | P |
| **B. Acompanhamento por tipo** | Etapas derivadas (retirada sem entrega, estágio de transporte pela config), horários (T2, T3, T6) | Alto | P |
| **C. Acompanhamento visual** | Layout do cardápio, nova ordem, "onde retirar", ações (T4, T5, T7) | Alto | M |
| **D. Seletor: miniaturas e legibilidade** | G1, G2, G3 | Alto (velocidade no salão) | P |
| **E. Seletor: montagem de pizza** | G5 + módulo JS compartilhado do desenho | Médio | M |
| **F. Garçom: mesa e retirada** | G9, linha do tempo da retirada | Médio | M |
| **G. Atender Pedido** | G6, G7, G8, depois da frente de endereço | Médio | M |
| **H. Ajuste de regra no cardápio** | `requer_endereco` pela coluna (G10) | Baixo hoje, evita bug futuro | P |
| **I. Sabores no navegador** | G4 (A3), feita junto com a E | Médio | M/G |
| **K. Formato da pizza na categoria** | Campo `categoria_formato_pizza` (A6) | Baixo | P |
| **L. Endereço e mapa da loja** | Edição do endereço + `empresa_google_maps_url` (A4), base do card "Onde retirar" | Médio | P |
| **0. Auditoria visual dos painéis** | Capturas reais do garçom e do Atender Pedido antes de D–G | Pré-requisito de qualidade | P |

**Ordem sugerida:** A → B → C (o acompanhamento é público e tem o risco de dados) → H → 0 →
D → E → F → G → (I).

**Ordem após as decisões de 4.1:** A → B → L → C → H → K → 0 → D → A2 → E+I → F → G.

---

## 4. Decisões

| # | Decisão | Recomendação |
|---|---|---|
| **A1** | Links antigos sem assinatura: mostrar só o status ou deixar de funcionar | Só o status (o cliente que já recebeu o link não perde o acompanhamento) |
| **A2** | Cor primária dos painéis vira a brasa da marca (garçom e admin) | Sim no garçom; no admin, só se você quiser a marca no sistema todo |
| **A3** | Seleção de sabores no navegador no seletor (G4) | Depois de medir a lentidão no salão; não é pré-requisito |
| **A4** | "Onde retirar" com endereço da loja | Sim, usando o endereço do cadastro da empresa (confirmar que está preenchido em produção) |
| **A5** | Login para a auditoria visual dos painéis (Etapa 0) | Um usuário de teste local, ou você me passa um acesso de homologação |
| **A6** | Campo `categoria_formato` em vez da regra pelo nome "quadrad" | Só se surgir outro formato; hoje a regra pelo nome basta |

### 4.1 Decisões tomadas (2026-10-30)
| # | Decisão |
|---|---|
| **A1** | **Só o status** para links sem assinatura (os já enviados): linha do tempo e estado, sem nome, endereço, itens nem pagamento |
| **A2** | **Sim nos dois painéis**: brasa da marca como cor primária no garçom **e** no admin |
| **A3** | **Entra nesta rodada**: seleção dos sabores no navegador, junto com a Etapa E (o desenho da pizza passa a ser JS compartilhado com o cardápio) |
| **A4** | Sim, com **endereço e link do Google Maps no cadastro da empresa** (nova Etapa L): o endereço reaproveita as colunas `empresa_endereco_*` e ganha edição no Filament; a localização é a coluna nova `empresa_google_maps_url`. Sem endereço preenchido, o card mostra só o horário |
| **A5** | **Autorizado criar um usuário local** (só no banco de desenvolvimento) para fotografar os painéis |
| **A6** | **Campo de formato na categoria** (nova Etapa K): `categoria_formato_pizza` (redonda/quadrada) editável no cadastro, com a migration marcando como quadrada as categorias que hoje têm "quadrad" no nome; a regra pelo nome sai |


---

## 5. Execução (2026-10-30)

Ordem seguida: A → B → L → C (parada para revisão) → H → K → 0 → D → A2 → E+I → F → G.
Nada foi commitado. Regras de negócio intocadas (`StatusPedidoEnum`/`PedidoStatusService`,
precificação, estoque, cortesia, PIN, Stone, cupom, frete).

### 5.1 O que foi feito
| Etapa | Entrega | Onde |
|---|---|---|
| **A** | Link assinado (`Pedido::linkAcompanhamento()`, sem expiração). Link sem assinatura mostra só o status (A1). Decisão `completo` vem da assinatura da URL e fica em `#[Locked]`. Checkout devolve `link_acompanhamento`; todos os geradores trocados (cardápio/WhatsApp, painel de pedidos, Atender Pedido, legado, confirmações) | `routes/web.php`, `AcompanhamentoPedido`, `Pedido`, `CardapioCheckoutController` |
| **B** | `App\Support\Pedido\EtapasAcompanhamento`: etapas por tipo (retirada sem entrega, delivery com/sem estágio de transporte, mesa), horários reais, finalizado e cancelado. Tem a visão do salão (`paraSalao`) para as rodadas do garçom | `app/Support/Pedido/` |
| **L** | Migration `empresa_google_maps_url`; `Empresa::enderecoRetirada()` e `linkComoChegar()`; seção "Endereço da loja" em Configurações › Pedidos; link só aceita `https` e domínios do Google Maps; o update legado preserva o link | `ConfiguracaoPedidos`, `Empresa` |
| **C** | Acompanhamento no layout do cardápio ("Forno aceso"): estado em destaque, linha do tempo, "Onde retirar" (também no modo só status, decisão do usuário), itens recolhíveis, pagamento, WhatsApp, avaliações só no fim, `noindex` | `livewire/acompanhamento-pedido.blade.php` |
| **H** | `requer_endereco` do cardápio vem de `opcaoentrega_requer_endereco`, não do nome | `CardapioService` |
| **K** | `FormatoPizzaEnum` + `categorias.categoria_formato_pizza` (a migration marca `QUADRADA` nas categorias com "quadrad" no nome); campo "Formato da pizza" no cadastro; a regra pelo nome saiu | `Categoria`, `CategoriaResource`, `CardapioService` |
| **0** | Auditoria visual dos painéis (antes/depois, 390 e 1440 px, claro e escuro) | capturas fora do repositório |
| **D** | Grade do seletor com `getMiniaturaUrl(80)` + `loading="lazy"` + `width/height`; textos ≥ 12 px; "+" de 44 px na cor primária; modais e partials com `primary` no lugar de `teal` | `pedido-produto-selector.blade.php`, `perguntas-item`, `pedido-item-linha` |
| **A2** | Brasa como primária no garçom e no admin (`App\Support\PaletaMarca`); o legado ganhou `--color-primary-*` no `app.css`, porque o seletor é compartilhado | `PaletaMarca`, providers, `app.css` |
| **E+I** | Desenho da pizza em `resources/js/pizza-svg.js` (cardápio e painéis, mesmo resultado). No modal de sabores, seleção, desenho, contador "2 de 3" e prévia do preço rodam no Alpine; `confirmarSabores(?array $ids)` revalida tudo e cobra pelo preço do servidor | `PedidoProdutoSelector`, `pizza-svg.js`, `pizza-svg-painel.js` |
| **F** | Garçom: cabeçalho da mesa em dois blocos (info + "Pediu a conta" em destaque / ações), rodadas com passos Enviada → Na cozinha → Pronta → Servida, Conta reorganizada (resumo, por pessoa, cobrança, ações); retirada enviada com a linha do tempo e "Retirado" como passo final (botão "Cliente retirou o pedido") | `atender-mesa`, `mesa-rodadas`, `mesa-conta`, `atender-retirada`, `components/pedido/passos` |
| **G** | Atender Pedido: tipo de atendimento no topo; retirada sem bloco de endereço e sem "Taxa de entrega"; somente leitura com `fieldset disabled` (sem `opacity`/`pointer-events` inline); carrinho único (o seletor já esconde o dele com `carrinhoTopo`); linha do carrinho com `flex-wrap` na coluna estreita | `AtenderPedido`, `atender-pedido.blade.php`, `ClientePicker` |

### 5.2 Medições
- **Fotos da grade do seletor** (56 produtos com foto, dev): 19.086 KB (originais) → 463 KB (miniaturas de 80 px), −97,6%.
- **Toques no modal de sabores** (garçom 390 px e admin 1440 px, 4 toques incluindo desmarcar/remarcar): 0 chamadas do seletor ao servidor (antes: 1 por toque). Responder uma pergunta re-renderiza o seletor e a seleção continua; confirmar lança com o preço do servidor.
- **Contraste da brasa**: branco sobre `#C2410C` (600) = 5,2:1; botão primário no modo escuro (500, `#CC450E`) = 4,8:1; texto/ícone no modo escuro (400, `#E8673A`) = 5,8:1 sobre gray-900.

### 5.3 Armadilhas encontradas
- **`x-data` não pode mudar entre renders do Livewire.** Com `modo`/`ids` embutidos no atributo, o morph reiniciava só a raiz do modal e as linhas ficavam presas ao escopo antigo (toques sem efeito). Solução: atributo constante e estado inicial lido do `$wire` no `init()`.
- **`@php($x = ...)` numa view com `@php ... @endphp` mais adiante** faz o Blade tratar tudo entre os dois como PHP cru. Usar `$loop->last` direto.
- **Classes do seletor em três CSS** (admin, garçom e legado `app.css`): `primary-*` só existe nos painéis; o legado ganhou os tons no `@theme`.
- **Achado fora do escopo:** na tela do garçom (retirada nova) o `EntregaPagamentoPicker` dispara `pedido-entrega-pagamento-atualizado` em laço, ~6 requisições/s em repouso (no banco de desenvolvimento). Os arquivos dele não foram alterados nesta rodada; vale uma investigação à parte.

### 5.4 Testes
Novos: `AcompanhamentoPedidoTest`, `EtapasAcompanhamentoTest`, `EmpresaEnderecoLojaTest`, `CategoriaFormatoPizzaTest`, `PedidoProdutoSelectorSaboresNavegadorTest`, `AtenderPedidoTipoAtendimentoTest`, além de casos em `CardapioRedesignTest` e `CardapioCheckoutPrecoServidorTest`. Ajustados: `RetiradaGarcomTest` e `EntregaGarcomTest` (texto do botão "Cliente retirou o pedido"). Os testes do seletor passam sem alteração. Saída da suíte completa: ver o relatório final da sessão.

### 5.5 Dados locais de QA (somente banco de desenvolvimento)
- Usuário `qa.redesign@local.test` (id 31, papel Admin). Remover: `User::where('email', 'qa.redesign@local.test')->delete()`.
- Cliente "QA Maria Teste" e pedidos 59411 a 59415 (e os itens deles). Remover: `ItensPedido::whereIn('item_pedido_pedido_id', [59411,59412,59413,59414,59415])->delete(); Pedido::whereIn('id', [59411,59412,59413,59414,59415])->delete(); Cliente::where('cliente_nome', 'QA Maria Teste')->delete();`
- Nada disso existe em produção: **não criar usuário nem rodar seeds de QA lá.**

### 5.6 Deploy
1. `git pull` (o commit inclui `public/build/`).
2. `php artisan migrate --force` (duas migrations: `empresa_google_maps_url` e `categoria_formato_pizza`; a segunda marca como quadrada as categorias com "quadrad" no nome).
3. `php artisan view:cache`
4. `php artisan filament:optimize`
5. `php artisan queue:restart`
6. Recarregar o OPcache (reiniciar o PHP-FPM).
7. **Depois do deploy:** cadastrar o endereço da loja e o link do Google Maps em **Configurações › Pedidos**. Sem endereço, "Onde retirar" mostra só o horário.
8. Links de acompanhamento já enviados continuam abrindo, só com o status (e "Onde retirar"); os novos saem assinados.
