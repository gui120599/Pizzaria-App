# Investigação — divergência entre endereço do pedido e cadastro do cliente

**Data:** 2026-10-29 · **Escopo:** somente investigação (nenhum código, banco ou registro foi alterado).

**Fontes de evidência**

- **Dados de produção:** banco importado no MySQL local (`laravel`). Último pedido presente: 59407.
- **Código de produção:** branch `producao` (4242e07c). Verifiquei que `app/`, `resources/` e `routes/` são idênticos ao `HEAD` da `prod-new` (427a5769). As alterações locais ainda não commitadas (redesign do cardápio em `resources/views/cardapio/`) **não estão em produção** e não mudam o fluxo de endereço (o `script.blade.php` novo repete a mesma lógica de lookup/pré-preenchimento).
- **Fuso horário:** no banco importado, `created_at`/`updated_at` aparecem 3 h à frente das colunas `pedido_datahora_*` (ex.: 59406 `created_at` 00:41:05 × `pedido_datahora_incio` 21:41:05). Nas linhas do tempo abaixo, uso o **horário local (−3 h)**, que é o mesmo das colunas `pedido_datahora_*`.

**Legenda:** **[Confirmado]** comprovado por dados ou código · **[Possível]** há uma condição no código que pode provocar o problema · **[Não comprovado]** faltam dados para concluir.

---

## 1. Estrutura de dados envolvida

| Tabela | Campos de endereço | Observação |
|---|---|---|
| `pedidos` | `pedido_endereco_entrega` (TEXT) | **Snapshot em texto livre**, sem rua/número/bairro/CEP separados e sem complemento/referência estruturados. |
| `clientes` | `cliente_endereco`, `cliente_numero_endereco`, `cliente_bairro`, `cliente_cidade`, `cliente_estado`, `cliente_uf_estado`, `cliente_cep` | Não existe campo de complemento nem de referência. Cliente tem **um único endereço**: não há tabela de endereços salvos nem `endereco_id` no pedido. |
| `historico_status_pedidos` | — | Registra só a troca de status (de, para, usuário, data e hora). **Não registra edição de endereço.** |
| `pagamentos_pedidos` | — | Só é gravada pela tela **Atender Pedido** (e pela entrega do garçom em rascunho `INICIADO`). Serve de **evidência indireta** de que um pedido foi salvo nessa tela. |

Não existe log de auditoria de alterações em `pedidos` nem em `clientes` (nenhum pacote de activity log, nenhuma tabela de histórico de endereço). **[Confirmado]**

Na prática, os clientes do cardápio têm só `cliente_endereco` preenchido, num texto único: dos 2.096 clientes, apenas 107 têm `cliente_bairro`.

---

## 2. Diagnóstico dos pedidos reais

### 2.1 Pedido 59406, cliente 1241 (Juliane Alves)

| Campo | Pedido 59406 (`pedido_endereco_entrega`) | Cadastro atual do cliente 1241 |
|---|---|---|
| Endereço | `Rua São Paulo, Qd 4, Lt 3,  Setor Nova Indiara. , Rua da Pamonharia Grão de Milho, segunda casa após a Igreja Presebeteriana de Indiara. Em frente ao Salão Nobre.` | `cliente_endereco` = `Rua das Acácias QD 1 LT 6 Jardins Primavera Indiara-GO` |
| Número / bairro / cidade / UF / CEP | (texto único) | todos `NULL` |
| Complemento / referência | Dentro do texto ("segunda casa após a Igreja…") | Não existe o campo |
| `updated_at` | 2026-10-29 21:48:50 | **2026-08-09 20:58:24** (nunca mais alterado) |

São endereços completamente diferentes (outra rua, outro setor). **[Confirmado]**

**Linha do tempo (horário local)**

| Hora | Evento | Evidência |
|---|---|---|
| 21:41:05 | Criado pelo cardápio (`pedido_origem = cardapio`, opção 3 "Entregar") | `pedidos.created_at`, `historico_status_pedidos` 9691 |
| 21:43:00 | INICIADO → ABERTO (Confirmar), usuário 2 | `historico_status_pedidos` 9692 |
| 21:43:01 | ABERTO → PREPARANDO (**Aceitar**) | `historico_status_pedidos` 9693. No Painel de Pedidos, "Aceitar" manda a comanda para a impressora (`PainelPedidos::aceitarAction`, [PainelPedidos.php:200](../../app/Filament/Pages/PainelPedidos.php:200)) |
| **21:48:50** | **Pedido salvo na tela Atender Pedido** | `pedidos.updated_at` = 21:48:50 **e** `pagamentos_pedidos` id 581 criado no mesmo segundo. Só `AtenderPedido::persistir()` grava `pagamentos_pedidos` num pedido em PREPARANDO (o `RetiradaService` só opera em rascunho `INICIADO`). |

**Conclusões sobre o 59406**

1. **[Confirmado]** O endereço atual do pedido foi **gravado pela tela Atender Pedido às 21:48:50**, cerca de 6 minutos **depois** do Aceitar (21:43:01), que normalmente dispara a impressão da comanda. O formato do texto confirma isso: `"…Setor Nova Indiara. , Rua da Pamonharia…"` é exatamente o que `montarEnderecoSnapshot()` produz ao juntar `rua` (com espaço no fim) + `", "` + `bairro`, como mostra [AtenderPedido.php:734](../../app/Filament/Pages/AtenderPedido.php:734). Ou seja, alguém digitou "Rua São Paulo…" no campo **Rua** e "Rua da Pamonharia…" no campo **Bairro**.
2. **[Confirmado]** O cadastro do cliente 1241 **não foi alterado** nem na criação pelo cardápio nem na edição. Na edição isso é esperado: a tela Atender Pedido nunca altera o endereço de um cliente já vinculado (veja a seção 4).
3. **[Não comprovado, mas com inferência forte]** O endereço que o cardápio gravou em 21:41:05 era **igual ao cadastro** (`Rua das Acácias…`) ou estava vazio. O checkout do cardápio sobrescreve o cadastro com o nome e o endereço enviados (`ClienteResolverService`, veja 4.A). Como `clientes.updated_at` continua em 09/08, o Eloquent não encontrou nenhuma diferença para gravar. Portanto, nome e endereço enviados eram idênticos aos do cadastro, o que bate com o pré-preenchimento automático pelo telefone. O texto original **não ficou preservado** em nenhum lugar.
4. **[Possível]** Se a comanda foi impressa no Aceitar, ela saiu com **"Rua das Acácias…"**, enquanto o sistema passou a mostrar **"Rua São Paulo…"** a partir de 21:48:50. Não existe log de impressão para confirmar se houve impressão, reimpressão ou com qual texto.
5. **[Confirmado — risco ativo]** O pedido 59406 está em PREPARANDO. Se alguém abrir esse pedido de novo na tela Atender Pedido, o formulário vai mostrar **"Rua das Acácias…"** (cadastro), e não o endereço do pedido. Se alguém salvar depois de preencher o bairro, o pedido volta ao endereço antigo (veja 4.C).

Histórico da cliente: todos os pedidos anteriores foram para Rua das Acácias (52688 e 51534/52868/55623/55629, nos cadastros duplicados 1040 e 1638). **Há três cadastros para a mesma pessoa:** 1241 `64993346600`, 1638 `6493346600` (sem o 9) e 1040 `6492629003`. **[Possível]**

Existe também o pedido **59387** (19:57, atendente, cliente 2105 "Valteir Aguiaria") para `Rua São Paulo q4 l4, setor nova indiara na rua da pamonharia do carlinho…`, o lote vizinho na mesma rua. Isso sugere que o endereço digitado no 59406 é real e foi informado à loja por outro canal (provavelmente WhatsApp). **[Não comprovado]**

### 2.2 Pedido 59396, cliente 45 (Janaina Gomes)

| Campo | Pedido 59396 | Cadastro atual do cliente 45 |
|---|---|---|
| Endereço | `Avenida da prata qd 4 LT 2 são Simão 1` | `cliente_endereco` = `Avenida da prata qd 4 LT 2 são Simão 1` |
| Número / bairro / cidade / UF / CEP | (texto único) | todos `NULL` |
| `updated_at` | 21:33:39 (= EM TRANSPORTE) | **2026-10-02 20:21:31** |

O pedido e o cadastro são **idênticos, caractere por caractere**. **[Confirmado]**

**Linha do tempo (horário local):** 20:53:43 criado pelo cardápio → 20:54:59 ABERTO → 20:55:09 PREPARANDO (Aceitar/impressão) → 21:26:48 PRONTO → 21:33:39 EM TRANSPORTE (entregador 28). Não houve salvamento em Atender Pedido: não existe `pagamentos_pedidos` e o `updated_at` coincide com o último status. No momento da importação, o pedido continuava EM TRANSPORTE. **[Confirmado]**

**Conclusões sobre o 59396**

1. **[Confirmado]** O endereço do pedido **não foi alterado depois da criação**. O que foi impresso no Aceitar é o mesmo que está no banco.
2. **[Confirmado]** O cadastro não mudou na criação (`updated_at` de 02/10), ou seja, o cardápio enviou exatamente o texto que já estava no cadastro: **o endereço pré-preenchido pelo telefone**.
3. **[Possível]** O endereço pré-preenchido pode estar desatualizado. O histórico sugere que a cliente tem **dois cadastros**: 45 `64992372001` e 514 `6492372001` (sem o 9). Os dois estrearam com o mesmo endereço (`Rua Antônio f de faria Qd 20 LT 22a bela vista 2`, nos pedidos 47155 e 48511).
   - Cadastro 514 (atendente): de 07/07 a **27/10** sempre `Rua Zé paraíba qd 15 LT 12 Bela vista 2`.
   - Cadastro 45 (cardápio): **02/10** (pedido 57377) `Avenida da prata… são Simão 1`, depois **29/10** (59396) o mesmo texto.

   O 59396 pode ter herdado do cadastro 45 um endereço digitado uma única vez em 02/10, enquanto os pedidos pelo balcão de 10/10 e 27/10 foram para outro endereço. **Não há como saber qual endereço a cliente queria em 29/10**: o texto enviado coincide com o cadastro e não existe outro registro.

O pedido 57377 (02/10, mesma cliente) mostra o mesmo padrão do 59406: cardápio às 20:21:31, salvo em Atender Pedido às 20:41:52 (`pagamentos_pedidos` id 24), e o endereço reaparece como `"Avenida da prata qd 4 LT 2 ,  são Simão 1"`. O atendente precisou "quebrar" o texto em Rua + Bairro para conseguir salvar (veja 4.C). **[Confirmado]**

---

## 3. Volume no banco de produção (desde 10/09, quando a tela Atender Pedido foi lançada)

| Origem (entrega) | Pedidos | Snapshot ≠ cadastro atual |
|---|---|---|
| cardápio | 342 | 126 (85 foram salvos depois em Atender Pedido; 82 desses divergem) |
| atendente | 501 | 318 |
| garçom | 15 | 7 |

A divergência entre o snapshot e o cadastro **é esperada e correta** em muitos casos: o cliente mudou de endereço depois, ou o atendente digitou um endereço só para aquela entrega. O problema não é a divergência. **O problema é que a tela de edição ignora o snapshot e usa o cadastro.** Pedidos **ativos** neste momento que seriam afetados se forem reabertos e salvos em Atender Pedido: **59405** (PRONTO) e **59406** (PREPARANDO). **[Confirmado]**

---

## 4. Mapeamento do fluxo do endereço

### Etapa A — Cardápio digital (checkout público)

| Passo | Código (produção) | Comportamento |
|---|---|---|
| Lookup por telefone | `resources/views/cardapio.blade.php:1703-1724` (JS `lookupCliente`) → `CardapioCheckoutController::lookupCliente` ([CardapioCheckoutController.php:103-129](../../app/Http/Controllers/CardapioCheckoutController.php:103)) | A cada digitação do telefone (debounce de 600 ms), busca o cliente por `cliente_celular LIKE %tel%` e **preenche automaticamente `form.endereco`** com `cliente_endereco, número, bairro` do cadastro. A tela mostra "Cliente encontrado — dados preenchidos automaticamente". |
| Reset ao mexer no telefone | `cardapio.blade.php:1695-1700` (`resetCamposCliente`) | Qualquer alteração no telefone **apaga** o endereço já digitado, e o lookup em seguida preenche de novo com o do cadastro. |
| Campo de endereço | `cardapio.blade.php:684` | Um único input de texto livre ("Rua, número, bairro"), editável. Não há endereço selecionável por ID nem campo de complemento ou referência. |
| Envio | `cardapio.blade.php:1823` | `endereco: this.form.endereco.trim()`: **texto completo**, não um ID. |
| Mensagem de WhatsApp | `cardapio.blade.php:1879` | Inclui `*Endereço:* ${form.endereco}`, o mesmo texto enviado ao servidor. **A conversa do WhatsApp da loja é a única fonte externa que registra o que o cliente confirmou.** |

**Respostas para a Etapa A**
- O endereço **vem pré-preenchido do cadastro** e o cliente pode alterá-lo. O que chega ao backend é o texto que estava no campo no momento do envio. **[Confirmado]**
- O checkout **atualiza o cadastro do cliente automaticamente** com o endereço enviado. Veja a Etapa B. **[Confirmado]**
- O lookup usa `LIKE %tel%`, mas a gravação usa igualdade exata (`ClienteResolverService::buscar`, [ClienteResolverService.php:88](../../app/Services/ClienteResolverService.php:88)). Com cadastros duplicados ou telefones com e sem o 9, o endereço pré-preenchido pode vir de **um cadastro diferente** daquele em que o pedido será gravado. **[Possível]**

### Etapa B — Criação e persistência

| Passo | Código | Comportamento |
|---|---|---|
| Resolver cliente | [CardapioCheckoutController.php:249-254](../../app/Http/Controllers/CardapioCheckoutController.php:249) → [ClienteResolverService.php:30-77](../../app/Services/ClienteResolverService.php:30) | Busca por CPF/CNPJ/celular **exato**. Se encontrar o cliente, **sobrescreve** `cliente_nome` e `cliente_endereco` com o que veio no request (linhas 54-66). Se não encontrar, cria um novo cliente. |
| Snapshot | [CardapioCheckoutController.php:316](../../app/Http/Controllers/CardapioCheckoutController.php:316) | `'pedido_endereco_entrega' => $request->endereco`: **cópia do texto do request**, não do cadastro. Os dados são copiados para o pedido: não existe apenas uma relação com o cliente. **[Confirmado]** |
| Validação | — | Nenhuma validação de servidor no endereço (nem obrigatoriedade para "Entregar"). A obrigatoriedade existe só no JS (`cardapio.blade.php:1736`). **[Confirmado]** |
| Observers / eventos | `PedidoObserver` (comissão, estoque, movimentação de mesa), `ClienteObserver::saving`, `ClienteMergeService` | **Nenhum deles altera `pedido_endereco_entrega`.** O merge de clientes só troca o `pedido_cliente_id`. **[Confirmado]** |
| Concorrência | — | O snapshot é gravado na mesma transação do pedido. Não encontrei corrida que troque o endereço. **[Confirmado]** |

**Cenário 1 — o cliente altera o cadastro depois do pedido:** o snapshot do pedido antigo **não muda** e nenhuma tela de exibição ou impressão lê o cadastro. **Exceção:** se o pedido antigo, ainda ativo, for reaberto em Atender Pedido e salvo, ele recebe o endereço novo (veja 4.C). **[Confirmado]**
**Cenário 2 — o cliente escolhe outro endereço:** não existe "outro endereço salvo". O cliente apenas edita o texto pré-preenchido. O texto editado é gravado no pedido **e passa a ser o endereço do cadastro**. **[Confirmado]**
**Cenário 3 — o cadastro é atualizado durante a criação:** sim, ele é atualizado. Mas a criação **não usa o cadastro como fonte do snapshot** (usa `$request->endereco`). A consequência indireta é que o **próximo** pedido do mesmo telefone vem pré-preenchido com esse endereço. **[Confirmado]**

### Etapa C — Painel administrativo e operação

| Tela | Fonte do endereço | Arquivo |
|---|---|---|
| Painel de Pedidos — card | `$pedido->pedido_endereco_entrega` | `resources/views/filament/pages/painel-pedidos/card.blade.php:99-102` |
| Painel de Pedidos — modal de detalhes | `$pedido->pedido_endereco_entrega` | `…/painel-pedidos/detalhes-modal.blade.php:67` |
| Pedidos (Resource) — visualização | `TextEntry::make('pedido_endereco_entrega')` | [PedidoInfolist.php:79](../../app/Filament/Resources/Pedidos/Schemas/PedidoInfolist.php:79) |
| Confirmações (cardápio, INICIADO) — exibição e edição | Lê e grava o snapshot | [ConfirmacoesPedidos.php:82-136](../../app/Livewire/ConfirmacoesPedidos.php:82) |
| Painel do entregador / scan QR | `$pedido->pedido_endereco_entrega` | `livewire/painel-entregador.blade.php:62,150`, `app/entregador/scan.blade.php:12,31` |
| Acompanhamento público | snapshot | `livewire/acompanhamento-pedido.blade.php:164` |
| Legado — editar pedido | **carrega o snapshot** e grava o snapshot | `app/pedido/edit.blade.php:60`, [PedidoController.php:245](../../app/Http/Controllers/PedidoController.php:245) |
| Garçom — retirada/entrega | Monta o snapshot a partir dos campos do formulário (só rascunho INICIADO) | [RetiradaService.php:218-226](../../app/Services/Garcom/RetiradaService.php:218) |
| **Atender Pedido (Filament) — edição** | **⚠️ carrega o CADASTRO do cliente e grava o snapshot a partir dele** | **[AtenderPedido.php:209-219](../../app/Filament/Pages/AtenderPedido.php:209) e [:587](../../app/Filament/Pages/AtenderPedido.php:587)** |

Nenhuma tela de **exibição** usa `$pedido->cliente->cliente_endereco`. Não existe accessor, mutator, resource ou transformer de endereço no model `Pedido`. **[Confirmado]**

#### 4.C — O defeito: `AtenderPedido` (edição de pedido existente) **[Confirmado no código]**

```php
// AtenderPedido::mount() — app/Filament/Pages/AtenderPedido.php:209-219
$cliente = $pedido->pedido_cliente_id ? $pedido->cliente : null;
$this->clienteData = [
    'enderecoRua'    => $cliente?->cliente_endereco ?? '',        // ← CADASTRO, não o pedido
    'enderecoNumero' => $cliente?->cliente_numero_endereco ?? '',
    'enderecoBairro' => $cliente?->cliente_bairro ?? '',
    'enderecoCidade' => $cliente?->cliente_cidade ?? '',
    ...
];

// AtenderPedido::persistir() — :587 (roda em TODO salvamento, inclusive edição)
'pedido_endereco_entrega' => $this->montarEnderecoSnapshot(),   // ← reconstruído a partir do formulário

// AtenderPedido::resolverCliente() — :697-713
// "Cliente já identificado: ... nunca mexe no endereço do cadastro
//  — o endereço desta tela vira só o snapshot pedido_endereco_entrega."
```

O resultado é uma **assimetria**: a tela **lê** o endereço do cadastro, mas **escreve** só no pedido, e o snapshot que o pedido já tinha **não aparece em lugar nenhum da tela** (`atender-pedido.blade.php` e `cliente-picker.blade.php` exibem apenas os campos Rua, Número, Bairro, Cidade, UF e CEP vindos de `clienteData`). Consequências:

- **Cenário 4 — edição pelo atendimento: [Confirmado]** Qualquer salvamento de um pedido existente em Atender Pedido, mesmo que seja só para trocar a forma de pagamento, o status ou um item, **substitui `pedido_endereco_entrega` pelo endereço do cadastro** que estiver no formulário. Isso acontece sempre que o snapshot difere do cadastro, por exemplo:
  - pedido criado no próprio Atender Pedido com um endereço "só desta entrega" (o cadastro não é atualizado). **Reabrir e salvar volta ao endereço do cadastro**;
  - endereço corrigido em Confirmações (só altera o snapshot) e, depois, pedido salvo em Atender Pedido. **A correção é desfeita**;
  - cliente que fez outro pedido pelo cardápio com outro endereço (o cadastro foi sobrescrito). **Salvar o pedido anterior leva o endereço novo**;
  - o próprio 59406: hoje o snapshot é "Rua São Paulo…" e o cadastro é "Rua das Acácias…".
- **Trava que mascara o problema:** com uma opção de entrega que exige endereço, o salvamento exige **Rua e Bairro** preenchidos ([AtenderPedido.php:561-566](../../app/Filament/Pages/AtenderPedido.php:561)). Como 95% dos cadastros não têm bairro, o atendente costuma ser **obrigado a redigitar ou dividir** o endereço, o que explica os padrões `"… , …"` em 57377 e 59406. Quando o cadastro tem bairro, o salvamento é imediato e a troca passa **despercebida**.
- **Cenário 6 — dados incompletos e combinação de fontes: [Possível]** `ClientePicker::preencherDoCliente()` ([ClientePicker.php:113-124](../../app/Livewire/ClientePicker.php:113)) preenche **campo a campo apenas os que estão vazios** com os dados do cadastro. Se o atendente digitar só a rua e depois selecionar ou buscar o cliente, o snapshot fica **misturado**: rua digitada com bairro, número e cidade do cadastro.

### Etapa D — Impressão

| Impressão | Gatilho | Template | Fonte do endereço | Outras informações |
|---|---|---|---|---|
| Comanda do pedido (1ª via) | "Aceitar" no Painel de Pedidos → iframe oculto ([PainelPedidos.php:200-206](../../app/Filament/Pages/PainelPedidos.php:200), `painel-pedidos.blade.php:301`) | `resources/views/pedidoPDF.blade.php` via `PDFController::pedidoPDF` ([PDFController.php:28-45](../../app/Http/Controllers/PDFController.php:28)) | **`$pedido->pedido_endereco_entrega`** (linha 50) | **Nome e telefone vêm do cadastro atual** (`$pedido->cliente?->cliente_nome/celular`, linhas 43-44) |
| 2ª via | Botão Imprimir no card do Painel, ViewPedido, Pedidos do cliente, Operar Venda e telas legadas (`linkImpressao()`) | o mesmo `pedidoPDF` | snapshot | Renderizado **na hora da impressão**: reflete o snapshot vigente naquele momento |
| Relatório de entregas | `pedidosEntregasPDF.imprimir` | `pedidosEntregasPDF.blade.php:54` | snapshot | — |
| Retirada | `pedidoPDF` (ramo "não entrega") | — | não imprime endereço | telefone do cadastro |
| Cozinha (KDS) | — | — | não imprime endereço | — |

**Origem exata do endereço impresso:** em todos os modelos, é a **opção 1 — o endereço registrado no próprio pedido** (`pedidos.pedido_endereco_entrega`). Nenhum template usa `cliente_endereco` nem endereço por ID. Os modelos combinam fontes apenas no **nome e telefone**, que vêm do cadastro atual (opção 4 parcial). **[Confirmado]**

A comanda reflete o snapshot **no instante da impressão**. Se o endereço for alterado depois do Aceitar, como no 59406, a comanda em papel fica com o endereço anterior, e o sistema **não avisa nem reimprime** automaticamente. **[Confirmado no código; ocorrência no 59406: Possível]**

---

## 5. Confirmação ou descarte da hipótese inicial

> *"Em algum momento o sistema busca o endereço diretamente do cadastro atual do cliente, em vez de usar o endereço registrado no pedido."*

| Parte da hipótese | Veredito |
|---|---|
| A **impressão** usa o cadastro do cliente | **Descartada.** Todas as impressões e telas de exibição usam `pedido_endereco_entrega`. **[Confirmado]** |
| Existe um ponto em que o cadastro **substitui** o endereço do pedido | **Confirmada:** a tela **Atender Pedido** carrega o cadastro ao editar um pedido existente e regrava o snapshot com ele ao salvar. **[Confirmado no código]** |
| O "endereço informado" no cardápio pode ser, na verdade, o do cadastro | **Confirmada como comportamento:** o cardápio pré-preenche o campo com o cadastro, e nos dois casos reais o texto gravado na criação coincidia com o cadastro. **[Confirmado no 59396; inferência forte no 59406]** |
| Nos casos 59406 e 59396 especificamente, o endereço impresso diverge do que o cliente quis | **[Não comprovado]** Não existe log do texto original nem de impressão. Os dados mostram que o 59406 teve o endereço trocado depois do Aceitar e que o 59396 saiu com o endereço do cadastro (possivelmente desatualizado). |

## 6. Causa raiz

1. **Causa principal [Confirmado no código]:** `AtenderPedido::mount()` inicializa o formulário de endereço a partir de `$pedido->cliente` (cadastro), e não de `$pedido->pedido_endereco_entrega`. Em todo salvamento, `persistir()` reconstrói o snapshot a partir desse formulário. Como a mesma tela, por decisão consciente (`resolverCliente()`), não atualiza o cadastro de um cliente já vinculado, cadastro e snapshot divergem com frequência (318 de 501 pedidos de atendente). Cada reabertura e salvamento troca silenciosamente o endereço do pedido.
2. **Causa contribuinte [Confirmado no código]:** o checkout do cardápio pré-preenche o endereço com o cadastro (lookup por `LIKE`), e qualquer alteração no telefone apaga o que já foi digitado. Clientes que não revisam o campo enviam o endereço antigo. Combinado com **cadastros duplicados** (45/514; 1241/1638/1040), o pré-preenchimento pode trazer um endereço defasado.
3. **Causa contribuinte [Confirmado no código]:** não existe **histórico** do endereço original, de quem o alterou e quando, nem aviso de que o endereço mudou depois da impressão. Por isso não é possível reconstruir os casos com certeza.

## 7. Outros riscos no mesmo fluxo

| # | Risco | Classificação |
|---|---|---|
| R1 | Snapshot em **texto único**: a edição precisa "adivinhar" rua/bairro, e a validação Rua + Bairro força o atendente a redigitar | Confirmado |
| R2 | `pedidoPDF` imprime **nome e telefone do cadastro atual**, não do momento do pedido. Um merge de clientes ou uma troca de nome altera comandas antigas na reimpressão | Confirmado (código) |
| R3 | Lookup do cardápio por `LIKE %tel%` × gravação por celular exato: o endereço pré-preenchido pode vir de outro cadastro | Possível |
| R4 | O checkout **sobrescreve o nome e o endereço do cadastro** com o que foi digitado. Um pedido para "outro endereço" (casa da mãe, trabalho) muda o endereço padrão da próxima compra | Confirmado (código) |
| R5 | Sem validação de servidor do endereço quando a opção de entrega exige endereço (só no JS) | Confirmado (código) |
| R6 | `ClientePicker` mistura campos digitados com campos do cadastro | Possível |
| R7 | Endereço alterado depois do Aceitar não gera reimpressão nem alerta: o entregador sai com a comanda antiga | Confirmado (código) |
| R8 | `historico_status_pedidos` não registra alterações de endereço, forma de pagamento etc. | Confirmado |
| R9 | O redesign do cardápio (não commitado) mantém o mesmo pré-preenchimento (`resources/views/cardapio/script.blade.php:636-652`) | Confirmado |

## 8. Proposta de solução técnica (para aprovação, nada foi implementado)

O princípio já está **parcialmente** implementado: o pedido tem um snapshot próprio e todas as telas de exibição e impressão o respeitam. O que falta é a **edição** respeitar o snapshot e haver **rastreabilidade**.

**Fase 1 — correção do defeito (pequena, sem migration)**
1. `AtenderPedido::mount()`: ao editar um pedido existente, **não** carregar o endereço do cadastro. Mostrar o snapshot (`pedido_endereco_entrega`) num campo próprio, "Endereço desta entrega", em texto livre, pré-preenchido com o snapshot.
2. `AtenderPedido::persistir()`: em edição, **só regravar `pedido_endereco_entrega` se o atendente alterou o endereço** (comparar com o valor carregado). Em criação, manter o comportamento atual.
3. Validação de endereço obrigatório em edição: exigir só que o snapshot não esteja vazio, sem obrigar Rua + Bairro estruturados quando o pedido já tem endereço.
4. `ClientePicker`: em edição, ao trocar ou selecionar o cliente, **não** completar campos vazios com o cadastro sem ação explícita (ex.: botão "usar endereço do cadastro").

**Fase 2 — rastreabilidade e operação (com migration)**
5. Gravar o **endereço original** na criação (ex.: `pedido_endereco_original`, imutável) e registrar alterações de endereço (de/para, usuário, data e hora). Pode ser uma tabela própria ou uma extensão de `historico_status_pedidos` para "eventos do pedido".
6. Se o endereço mudar **depois do Aceitar ou da impressão**, sinalizar no card do Painel ("endereço alterado após impressão") e oferecer ou disparar a reimpressão.
7. Opcional: snapshot de `nome` e `telefone` no pedido para a comanda (R2).

**Fase 3 — cardápio**
8. Mostrar o endereço pré-preenchido como **confirmação explícita** ("Entregar em: … [Alterar]") em vez de um input silenciosamente preenchido. Não apagar o endereço digitado ao corrigir o telefone se ele já foi alterado manualmente.
9. Alinhar o lookup ao `ClienteResolverService` (mesma regra de busca: celular exato normalizado).
10. Avaliar se o checkout deve continuar sobrescrevendo o endereço do cadastro (R4) ou só preencher quando estiver vazio. Isso é uma decisão de negócio.
11. Unificar os cadastros duplicados identificados (45/514; 1241/1638/1040) com o `ClienteMergeService` existente. Operação de dados: só com autorização.

## 9. Testes necessários

**Atender Pedido (Feature/Livewire)**
- Editar um pedido existente cujo snapshot difere do cadastro e salvar **sem mexer no endereço**: o `pedido_endereco_entrega` é preservado. *(falha hoje)*
- Editar um pedido e alterar o endereço: o snapshot é atualizado e o cadastro **não** muda.
- Alterar o cadastro do cliente depois de criar o pedido e abrir a edição: a tela mostra o endereço **do pedido**. *(falha hoje)*
- Endereço corrigido em Confirmações, depois salvo em Atender Pedido: a correção é mantida. *(falha hoje)*
- Edição de pedido cujo cadastro não tem bairro: salvar sem ser obrigado a redigitar o endereço. *(falha hoje)*

**Cardápio (checkout)**
- O `endereco` enviado é gravado literalmente em `pedido_endereco_entrega`.
- Segundo pedido do mesmo telefone com outro endereço: o snapshot do **primeiro** pedido não muda.
- Opção de entrega que exige endereço e chega sem endereço: 422 (validação de servidor).

**Impressão**
- `pedidoPDF` renderiza o snapshot mesmo quando o cadastro do cliente tem outro endereço.
- (Fase 2) Alteração de endereço depois do Aceitar gera um registro de histórico e o alerta de reimpressão.

---

## Resumo executivo

- **Existe problema confirmado no código**, mas **não na impressão**. Todas as comandas, a 2ª via, o relatório de entregas e as telas de exibição usam o endereço gravado no pedido.
- **A causa** está na tela **Atender Pedido** (Filament): ao **editar** um pedido existente, ela carrega o endereço do **cadastro do cliente** (`AtenderPedido.php:209-219`) e, ao salvar, **regrava o endereço do pedido** a partir dele (`:587`). Como essa mesma tela nunca atualiza o cadastro de um cliente já vinculado, reabrir e salvar um pedido pode trocar silenciosamente o endereço da entrega. Hoje isso afeta os pedidos ativos 59405 e 59406.
- **Pedido 59406:** os dados confirmam que o endereço foi reescrito em Atender Pedido às 21:48:50, cerca de 6 minutos **depois** do Aceitar (que imprime a comanda). O cadastro ficou em "Rua das Acácias", e o cardápio provavelmente enviou esse mesmo endereço pré-preenchido. A comanda impressa no Aceitar provavelmente diverge do que o sistema mostra agora. Não há log de impressão para provar isso.
- **Pedido 59396:** o endereço nunca foi alterado. Ele é idêntico ao cadastro porque o cardápio **pré-preenche** o campo com o cadastro, que possivelmente estava desatualizado: a cliente tem um cadastro duplicado (514) usado pelo balcão com outro endereço. Não é possível comprovar qual endereço a cliente queria.
- **Recomendação:** começar pela **Fase 1**, uma correção pequena e sem migration. Em edição, a tela Atender Pedido deve mostrar e preservar o snapshot do pedido e só regravá-lo quando o atendente alterar o endereço. Depois, na Fase 2, gravar o endereço original e o histórico de alterações, com alerta de reimpressão. Por fim, na Fase 3, tornar explícita a confirmação do endereço pré-preenchido no cardápio e unificar os cadastros duplicados.
- **Ação imediata sugerida (operacional):** conferir na conversa de WhatsApp da loja o endereço enviado pelas clientes dos pedidos 59406 e 59396 (a mensagem automática inclui `*Endereço:*`), e evitar reabrir e salvar o 59405 e o 59406 em Atender Pedido até a correção.
