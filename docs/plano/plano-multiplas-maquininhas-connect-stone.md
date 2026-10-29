# Plano — Múltiplas maquininhas com Connect Stone

> **Status:** decisões aprovadas em 2026-10-28 (ver seção 0). Nada foi implementado.
> **Data:** 2026-10-28.
> **Legenda:** **[FATO]** foi comprovado no código ou na documentação oficial. **[HIPÓTESE]** precisa ser confirmado. **[DECISÃO]** depende de aprovação.

---

## 0. Decisões aprovadas (2026-10-28)

| # | Decisão | Efeito no plano |
|---|---|---|
| D1 | **Opção "Cobrar na Stone / Lançar manual"** no pagamento, mantendo uma única forma "Crédito" (e "Débito", "PIX"). **Não** criar formas "— Stone Connect" separadas. | Seções 5 e 6.3. `opcaopag_stone_integrada` passa a significar "esta forma **pode** ser cobrada na Stone", não mais "sempre vai para a Stone". |
| D2 | **Manter o envio em lote** no painel, com verificação do limite de **30 pedidos abertos por maquininha**: a quantidade selecionada não pode ultrapassar as vagas restantes. | Seções 6.2 e 8.9. |
| D3 | **Aprovado o estado `indeterminado`** (resultado desconhecido). | Seções 7 e 8.4. |
| D4 | **Pedido Direto: uma cobrança pendente por alvo** (trava). **Listado: aceita mais de uma** ao mesmo tempo, porque o pedido Listado já aceita pagamento dividido na maquininha. | Seção 8.2. A soma das cobranças pendentes nunca passa do valor devido (8.5). |

---

## 1. Resumo executivo

O RazelFood não guarda a integração com a Stone na **maquininha**. Ela fica na **forma de pagamento** (`opcoes_pagamentos.opcaopag_stone_integrada`). Esse único flag decide três coisas:

1. **No PDV:** se toda cobrança daquela forma vai para a Stone ou é lançada manualmente.
2. **No pedido:** se aparece o botão "Cobrar na maquininha" no atendimento e "Enviar para a maquininha" no painel de pedidos.
3. **No webhook:** qual forma é gravada quando o tipo da transação (crédito, débito ou PIX) é resolvido.

Na prática, o problema aparece assim. Quando a forma "Crédito" é marcada como integrada, o balcão e o salão deixam de conseguir lançar uma venda feita na Cielo Flash ou na Stone Tectoy T8, porque o PDV tenta mandar a cobrança para a Sunmi P2-B. Quando o flag é desmarcado, o botão de envio some do pedido do entregador.

**Recomendação:** uma solução híbrida e enxuta, mais próxima da **Alternativa A**, com três desacoplamentos pontuais:

- **Maquininha:** a maquininha passa a dizer se recebe pedidos Connect, com um flag explícito.
- **Forma de pagamento (D1):** "Crédito", "Débito" e "PIX" continuam sendo formas únicas. O flag `opcaopag_stone_integrada` passa a significar "pode ser cobrada na Stone". No modal de pagamento do PDV, o operador escolhe **"Lançar manual"** (Cielo Flash, Tectoy T8 ou qualquer maquininha, com bandeira e autorização) ou **"Cobrar na Stone"** (Sunmi P2-B).
- **Botão de envio:** deixa de depender da forma combinada no pedido. Passa a depender do estado do pedido, do valor devido e de existir uma maquininha Connect.

Junto com isso, o plano endurece a **idempotência**:

- uma trava no banco e um lock contra cobranças paralelas;
- um estado *indeterminado* para timeout, recuperado reenviando com a **mesma** `Idempotency-Key`;
- `code` próprio no pedido da Stone;
- valor cobrado = **valor devido** (total − pagamentos já efetuados), e não o total bruto.

Não haverá abstração de vários provedores, porque não há um segundo provedor integrado em vista.

---

## 2. Causa raiz

### 2.1 Comprovada no código [FATO]

| # | Evidência | Efeito |
|---|---|---|
| 1 | `OperarVenda::registrarPagamento()` em `app/Filament/Pages/OperarVenda.php:~1906`. Trecho: `if ($opcaoPagamento->ehIntegracaoStone()) { $this->iniciarCobrancaStone(...); return; }` | Qualquer pagamento numa forma integrada **sempre** vira cobrança Connect. Não existe caminho de "lançar manualmente" para essa forma. |
| 2 | `resources/views/filament/pages/operar-venda.blade.php:892-931` | Com forma integrada, o select mostra **só** `Maquininha::stoneDisponivel()`. Os campos "Maquininha" (todas), "Cartão / bandeira" e "Número de autorização" ficam escondidos. A Cielo Flash nem aparece. |
| 3 | `OpcoesPagamento::ehIntegracaoStone()` lê `opcaopag_stone_integrada` (`app/Models/OpcoesPagamento.php:~120`) | O flag é da **forma**, não do terminal nem do provedor. |
| 4 | `Pedido::temPagamentoCombinadoStone()` (`app/Models/Pedido.php:~215`), usado em: `PedidosTable::podeEnviarStone()`, `AtenderPedido::podeCobrarStone()`/`enviarCobrancaStone()` e `PainelEntregador::abrirModalStone()` | O botão de envio só aparece se a forma **combinada** no pedido estiver marcada como integrada. Desmarcar o flag para liberar o salão esconde o botão do entregador. |
| 5 | `StoneRecebimentoService::resolverOpcaoStone()` (`app/Services/Stone/StoneRecebimentoService.php:~495`) procura `OpcoesPagamento` com `opcaopag_stone_integrada = true` e o mesmo `opcaopag_desc_nfe` | No modo Listado, a forma gravada no pagamento é a forma integrada do mesmo tipo. Se nenhuma estiver marcada, o pagamento entra **sem forma** (com log de aviso). |
| 6 | `Maquininha::scopeStoneDisponivel()` = `operadora = stone` **e** `numero_serie` preenchido (`app/Models/Maquininha.php:~107`) | "Apta ao Connect" é deduzido do cadastro. Uma **Tectoy T8** cadastrada como Stone e com número de série também aparece como destino de pedidos Connect. |

### 2.2 Ainda a confirmar em produção [HIPÓTESE]

- **H1.** Em produção, as formas usadas no dia a dia ("Crédito", "Débito" e talvez "PIX") estão com `opcaopag_stone_integrada = 1`. A consulta abaixo confirma, sem alterar nada:
  ```sql
  SELECT id, opcaopag_nome, opcaopag_desc_nfe, opcaopag_stone_integrada FROM opcoes_pagamentos ORDER BY id;
  SELECT id, nome, operadora, numero_serie FROM maquininhas ORDER BY id;
  ```
- **H2.** A Tectoy T8 está cadastrada como operadora Stone. Se também tiver número de série, ela aparece no select da Stone.
- **H3.** A Tectoy T8 não está habilitada no Connect (ou está em outra conta Stone). O FAQ oficial só cita os modelos S920 e Q92 para o Connect 2.0. A Sunmi P2-B funciona na prática, como mostra a homologação concluída. **Nada aqui depende do SDK:** o RazelFood não usa SDK Android, só a API REST (`api.pagar.me/core/v5`), e o terminal é escolhido por `poi_payment_settings.devices_serial_number`.

**Conclusão.** A restrição vem da **regra de negócio interna** (o flag na forma), não do SDK nem da Stone.

### 2.3 Lacunas de integridade encontradas na investigação [FATO]

| # | Evidência | Risco |
|---|---|---|
| G1 | `AtenderPedido::enviarCobrancaStone()` não verifica se já existe um `StonePedido` pendente. Fechar o modal (`fecharModalStone`) não cancela a cobrança. | Abrir o modal de novo e enviar gera **dois pedidos** no POS para o mesmo pedido. |
| G2 | `PedidosTable::podeEnviarStone()` checa a pendência **fora de transação e sem lock**. O mesmo vale para `PainelEntregador` e `MesaStoneCobranca`. | Dois operadores, ou duas abas, podem enviar o mesmo pedido em paralelo. |
| G3 | `StoneRecebimentoService::criarStonePedido()` marca `Falha` em **qualquer** `StoneConnectException`, inclusive timeout ou erro de conexão. | Se o POST chegou à Stone e só a resposta se perdeu, o pedido existe no POS, mas o sistema mostra "falha" e convida a reenviar. O reenvio cria um `StonePedido` novo, com `Idempotency-Key` nova (`stone-pedido-{id}`), e o cliente pode pagar **duas vezes**. |
| G4 | O valor enviado no painel e no entregador é `pedido_valor_total`. No atendimento é `totalPreview` (estado do formulário, que pode não estar salvo). | O valor ignora o "pagamento já efetuado" (`pagamentos_pedidos.pg_pedido_pago`, ex.: PIX CNPJ) e pagamentos parciais, o que pode gerar **cobrança a mais**. Além disso, `StoneVendaAutomaticaService` não lança na venda os pagamentos já efetuados no pedido. |
| G5 | O PDV (`iniciarCobrancaStone`) valida `valor ≤ valorRestante`, mas `valorRestante` não desconta cobranças Stone **pendentes**. | Duas cobranças de R$ 50 sobre um restante de R$ 50 passam na validação. |
| G6 | O `POST /orders` não envia `code` próprio (`StoneConnectService::criarPedido`). | Sem `order_id`, não há como achar o pedido na Stone por um identificador nosso. |

---

## 3. Mapeamento dos fluxos atuais

### 3.1 Pontos de disparo de cobrança Connect [FATO]

| Tela | Classe / método | Origem (`stp_origem`) | Modo | Valor enviado | Guarda de duplicidade |
|---|---|---|---|---|---|
| PDV (Nova Venda), forma integrada | `OperarVenda::registrarPagamento → iniciarCobrancaStone` | `venda` | Direto (cai para Listado se `STONE_CONNECT_PEDIDO_DIRETO=false`) | digitado (≤ restante) | modal aberto, `wire:loading`; **sem** checar pendente |
| PDV, "Lançar total na maquininha" | `OperarVenda::lancarPedidoTotalStone` | `venda` | Listado | restante | idem |
| Atendimento de pedido (Filament) | `AtenderPedido::enviarCobrancaStone` | `pedido` | Direto se escolher forma, senão Listado | `totalPreview` | **nenhuma** (G1) |
| Painel de pedidos (linha e lote) | `PedidosTable::enviarStoneAction` / `enviarStoneBulkAction` | `pedido` | idem | `pedido_valor_total` | `stonePedidosPendentes` sem lock (G2) |
| Painel do entregador | `PainelEntregador::enviarCobrancaStone` | `pedido` | Listado | `pedido_valor_total` | sem checagem |
| Conta da mesa (garçom / mesa) | `MesaStoneCobranca::enviarCobranca` | `sessao_mesa` ou `pedido` | Listado | total em aberto | sem checagem |

Todos esses pontos passam por `StoneRecebimentoService::iniciarCobranca()` (venda) ou `iniciarCobrancaDePedido()` (pedido ou mesa). O caminho é: `validarECalcularModoEfetivo` → `criarStonePedido`, que primeiro cria a linha em `stone_pedidos` e depois chama `StoneConnectService::criarPedido` com `Idempotency-Key: stone-pedido-{id}`.

### 3.2 Retorno e pós-pagamento [FATO]

- **Webhook:** `POST /api/webhook/stone-connect` → `StoneWebhookController`. Persiste o payload em `stone_webhooks` antes de processar e repassa para `StoneRecebimentoService::processarWebhook`.
- **`charge.paid`** (`processarChargePaid`):
  - Deduplica por `stw_charge_id`.
  - Para origem pedido ou mesa, cria a venda (`StoneVendaAutomaticaService::resolverOuCriarVenda`).
  - Dentro de uma transação com `lockForUpdate` no `StonePedido`, cria `PagamentosVenda` com `pg_venda_tipo_integracao='integrated'` e `pg_venda_maquininha_id = stp_maquininha_id`.
  - Atualiza o `StonePedido` para `Pago` ou `PagoParcial` e fecha o pedido na Stone (`PATCH /orders/{id}/closed`).
  - Na origem pedido ou mesa, também finaliza a venda (`finalizarSePago`).
- **PDV:** no polling `verificarStatusStone`, finaliza a venda quando ela está quitada (correção da homologação).
- **`charge.refunded`** (`processarChargeRefunded`): reverte o caixa, e no estorno total cancela a venda e a NFC-e (`CancelamentoVendaService`).
- **Conciliação:** o comando `stone:conciliar-pedidos`, agendado no `Kernel`, consulta `GET /orders/{id}` para recuperar webhooks perdidos e fechar pedidos.
- **Cancelamento antes do pagamento:** `cancelarCobranca` → `PATCH /orders/{id}/closed {status: canceled}`.
- **Hub:** Financeiro › Pedidos Stone tem as ações "fechar pedido", "cancelar pedido" e "gerar venda".

### 3.3 Pagamento manual (Cielo Flash / Tectoy T8) [FATO]

- **Caminho:** `OperarVenda::registrarPagamento` com uma forma **não** integrada → `gravarPagamento`.
- **Dados gravados:** `PagamentosVenda` com `pg_venda_maquininha_id` (select com todas as maquininhas), bandeira e autorização. Não depende da Stone em nenhum ponto.
- **Pagamentos parciais e múltiplas formas:** suportados (vários `PagamentosVenda` por venda, "dividir conta").
- **Pagamentos combinados do pedido:** viram sugestões ou lançamentos na venda (`OperarVenda` ~2080–2150), com "pagamento já efetuado" (`pg_pedido_pago`).

### 3.4 Caixa e relatórios [FATO]

- **Saldo da sessão:** `MovimentacaoCaixaService::recalcularSaldoFinal` soma as vendas FINALIZADAS e os movimentos manuais. Não distingue integrado de manual.
- **Fechamento e relatório:** `FechamentoCaixaService` e `RelatorioFechamentoCaixaService` agrupam por forma, categoria (`opcaopag_desc_nfe`), **maquininha** e **operadora** (`FechamentoCaixaPorOperadoraWidget`). O terminal usado já é registrado em `pg_venda_maquininha_id`, nos dois fluxos.

**Consequência:** com a opção "Lançar manual / Cobrar na Stone" (D1), os dois casos usam a mesma forma e não muda nada nos relatórios. A categoria vem de `opcaopag_desc_nfe` e a operadora vem da maquininha (`pg_venda_maquininha_id`). Ver os testes da seção 11.

### 3.5 O sistema precisa distinguir método, terminal e provedor?

- **Método** (crédito, débito ou PIX): já existe em `opcaopag_desc_nfe`.
- **Terminal:** já existe em `maquininhas` + `pg_venda_maquininha_id`.
- **Provedor e integração:** hoje é **inferido** de `operadora` + `numero_serie` (terminal) e de `opcaopag_stone_integrada` (forma). **Falta só** um flag explícito no terminal: "recebe pedidos Connect". Uma entidade "provedor" seria redundante com um único provedor.

---

## 4. Alternativas arquiteturais

### Alternativa A — Ação explícita de pagamento integrado

O envio à Connect acontece **só** por um botão explícito (PDV, atendimento, painel, entregador, mesa). Os demais pagamentos seguem os fluxos manuais. No PDV, isso significa ter formas integradas separadas, ou uma escolha "Cobrar na Sunmi / Lançar manual".

### Alternativa B — Arquitetura de pagamentos desacoplada

Tabelas e serviços genéricos: `provedores_pagamento`, `terminais` (N:1 provedor), `solicitacoes_pagamento` (genérica, substituindo `stone_pedidos`) e uma interface `ProvedorPagamentoTerminal` com uma implementação Stone. A forma de pagamento passa a ser só o método.

### Comparação

| Critério | A (+ desacoplamentos pontuais) | B (desacoplada) |
|---|---|---|
| Complexidade | Baixa a média: 1 migration pequena, ajustes em 6 pontos de disparo, endurecimento do serviço existente | Alta: migrar `stone_pedidos`/`stone_webhooks`, reescrever 6 telas, conciliação e relatórios |
| Compatibilidade | Total: reaproveita `StonePedido`, `StoneRecebimentoService`, webhook e hub | Exige migração de dados históricos e período de convivência |
| Segurança financeira | Alta, com as travas da seção 8 | Alta, mas com mais superfície de regressão |
| Risco de duplicidade | Reduzido por lock + índice único + Idempotency-Key estável | Igual (mesmas técnicas), com mais código novo |
| Experiência operacional | Escolha clara no modal: "Lançar manual" (qualquer maquininha) × "Cobrar na Stone" (Sunmi) | Parecida, com mais configuração |
| Manutenção | Mantém o padrão atual do projeto (serviços concretos, como `NfeIoService`) | Abstração sem segundo provedor para justificar |
| Caixa e relatórios | Nenhuma mudança estrutural | Precisa adaptar as consultas |

---

## 5. Solução recomendada (aprovada)

**Alternativa A, com três desacoplamentos mínimos:**

1. **Flag no terminal:** coluna `maquininhas.maquininha_connect_ativo` (boolean, padrão `false`). `scopeStoneDisponivel()` passa a exigir `connect_ativo = true` **e** `numero_serie`, e deixa de deduzir pela operadora. O backfill marca `true` só nas maquininhas que hoje são Stone, têm número de série **e** foram confirmadas pelo usuário (a Sunmi P2-B).
2. **Opção "Cobrar na Stone / Lançar manual" no pagamento (D1):**
   - O flag `opcaopag_stone_integrada` muda de sentido: deixa de ser "sempre cobra na Stone" e passa a ser "**pode** cobrar na Stone". O rótulo no cadastro vira "Permite cobrar na maquininha Stone (Connect)".
   - A escolha aparece no modal de pagamento do PDV só quando a forma permite e existe maquininha Connect. Se não aparecer, o lançamento é sempre manual.
   - **Padrão: "Lançar manual"**, porque balcão e salão são a maioria das vendas. O sistema lembra a última escolha do operador na sessão (mesmo padrão de `stone_ultima_maquininha_id`).
   - Em "Lançar manual": mostra **todas** as maquininhas, bandeira e autorização, e grava pelo `gravarPagamento` atual. A Stone não é chamada.
   - Em "Cobrar na Stone": mostra só as maquininhas Connect e segue o fluxo `iniciarCobrancaStone` atual.
   - A escolha é **validada no servidor**: o Livewire não confia só no estado da tela. "Cobrar na Stone" com forma que não permite, ou com maquininha não Connect, é recusado.
   - `resolverOpcaoStone` continua funcionando: procura a forma que permite Stone com o mesmo `desc_nfe`. Para não ficar ambíguo, o cadastro passa a **impedir duas formas** com o flag e o mesmo `opcaopag_desc_nfe`. Isso é uma validação no `OpcoesPagamentoResource`, com teste.
   - Nos relatórios, os dois casos caem na mesma forma e categoria. A diferença fica em `pg_venda_maquininha_id` (operadora) e em `pg_venda_tipo_integracao` (`integrated` ou nulo). Nenhuma consulta muda.
3. **Botão de envio independente da forma combinada:** troca `temPagamentoCombinadoStone()` por uma regra de **elegibilidade** centralizada no serviço (`StoneRecebimentoService::elegibilidade(Pedido|SessaoMesa)`). O pedido de entrega pode ter sido combinado como "Crédito" e mesmo assim ser cobrado na Sunmi.

**Por que essa escolha:** resolve a dor operacional sem mudar o modelo financeiro, o caixa nem os relatórios, e corrige as lacunas G1–G6 no ponto central do serviço.

**Mitigação até a Etapa 1 ir ao ar** (opcional, só cadastro): criar temporariamente as formas "Crédito — Stone Connect", "Débito — Stone Connect" e "PIX — Stone Connect" com o flag, e desmarcar o flag em "Crédito", "Débito" e "PIX". O salão volta a lançar manualmente na hora. Quando a opção do modal entrar, o flag volta para as formas comuns e as temporárias perdem o flag. Como `opcoes_pagamentos` **não tem campo "ativa"** [FATO], as temporárias ficam cadastradas e são renomeadas com o sufixo "(não usar)". O histórico delas continua nos relatórios, que agrupam pela categoria `desc_nfe`.

---

## 6. Especificação das ações

### 6.1 Atendimento / criação de pedido (`AtenderPedido`)

**Ciclo de vida [FATO].**
- O pedido só existe no banco depois de `save()`.
- Em pedido novo, `save()` **redireciona para a tela em branco** (`$this->redirect(static::getUrl())`). O botão "Cobrar na maquininha" só aparece ao **reabrir** o pedido (`pedidoId` preenchido).
- `enviarCobrancaStone` usa `totalPreview`, que é o estado do formulário e pode conter alterações não salvas.

**Proposta.**
- **Botão novo "Salvar e cobrar na maquininha"**, ao lado de "Criar pedido" / "Salvar alterações". Ele chama a mesma rotina de persistência de `save()`, refatorada para `persistir(): ?Pedido` (sem redirecionar e sem notificar), e só depois abre o modal Connect para o `pedidoId` persistido.
  - Se a persistência falhar (validação, cupom), o modal **não** abre.
  - O pedido não duplica: em pedido novo, `persistir()` grava `pedidoId` no componente antes de abrir o modal. Um novo clique trata o pedido como edição, e o botão fica desabilitado com `wire:loading.attr="disabled"` durante a requisição.
- **Com alterações não salvas:** se o formulário estiver "sujo" em um pedido existente, o botão "Cobrar na maquininha" vira "Salvar e cobrar". **Nunca** se cobra com base em `totalPreview`.
- **Valor:** `StoneRecebimentoService::valorDevido($pedido)`, calculado no **servidor** a partir do registro persistido (seção 8.5).
- **Modal:** maquininha (só `connect_ativo`, lembrando a última usada) e forma opcional (Direto) ou nenhuma (Listado).
- **Pendência existente:** se já houver um `StonePedido` pendente, o modal abre direto no estado "Aguardando" desse pedido, com o botão "Cancelar cobrança", em vez de oferecer outro envio (corrige G1).
- **Fechar o modal:** não cancela a cobrança (comportamento atual mantido). Mostra o aviso "A cobrança continua na maquininha".

### 6.2 Painel de pedidos (`PedidosTable` + `ViewPedido`)

- **Ação "Enviar para a maquininha"** (linha, e também no cabeçalho do `ViewPedido`): visível conforme a matriz da seção 7.
- **Ação "Ver cobrança"** (nova, só leitura, em slide-over): mostra status, Order ID, Charge ID, maquininha, valor solicitado e pago, e o histórico de webhooks. Reaproveita as colunas do hub `StonePedidos`.
- **Ação "Reconsultar na Stone"** (nova): para status `Indeterminado` ou para `Aguardando` antigo. Chama `consultarPedido` ou a recuperação da seção 8.4.
- **Ação "Cancelar cobrança na maquininha"** (já existe): passa a usar o lock da seção 8.
- **Envio em lote** (`enviarStoneBulkAction`) **mantido (D2)**, com estas regras:
  - **Só pedidos elegíveis:** cada linha passa pela mesma `elegibilidade()`. As não elegíveis são ignoradas e o motivo aparece no resumo final.
  - **Limite de 30 por maquininha:** antes de enviar, o sistema calcula as vagas da maquininha escolhida (`StoneRecebimentoService::vagasAbertas()`, seção 8.9) e compara com a quantidade de pedidos **elegíveis** selecionados.
    - Se couberem, envia todos.
    - Se não couberem, **não envia nenhum** e avisa: "A maquininha X tem N vaga(s) livre(s) e você selecionou M pedido(s) elegível(eis). Desmarque M−N ou feche pedidos pagos." Nada de enviar parte do lote silenciosamente.
  - **Aviso antes de confirmar:** o modal da ação mostra as vagas atuais da maquininha selecionada (campo `live()` no Select) e a quantidade elegível da seleção.
  - **Concorrência:** a checagem de vagas é refeita **sob lock** no momento do envio (8.9). Assim, outro operador enviando ao mesmo tempo não estoura o limite.
  - **Modo:** sem forma escolhida, segue em Listado. Com forma, vai em Direto, uma cobrança por pedido, como hoje.
- A ação **individual** também respeita o limite: com zero vagas, o botão fica desabilitado com o tooltip "Maquininha com 30 pedidos abertos".
- **Coluna/badge na tabela** "Stone": último status do `StonePedido` do pedido (eager load como `stonePedidosPendentes`, sem N+1).

### 6.3 PDV (`OperarVenda`)

- **Modal de pagamento (D1):** com uma forma que permite Stone e alguma maquininha Connect cadastrada, aparece um seletor de dois botões logo abaixo da forma: **"Lançar manual"** (padrão) e **"Cobrar na Stone"**.
  - A propriedade nova `modoRecebimento` (`manual` | `stone`) troca os campos exibidos:
    - **manual:** select "Maquininha" com todas, bandeira e autorização (o mesmo bloco das formas comuns, `operar-venda.blade.php:907-931`);
    - **stone:** select "Maquininha Stone" só com as maquininhas `connect_ativo` (bloco 892-905).
  - O texto do botão principal também muda: "Registrar pagamento" ou "Enviar para a maquininha".
  - **`registrarPagamento()`:** o desvio `if ($opcaoPagamento->ehIntegracaoStone())` vira `if ($this->modoRecebimento === 'stone')`. O servidor recusa `stone` quando a forma não permite.
  - **Edição de pagamento** (`pagamentoEmEdicaoId`): sempre manual. Um pagamento integrado não se edita, como já é a regra de `CorrecaoPagamentoService`.
- **Validação do valor:** `valor ≤ valorRestante − soma das cobranças Stone pendentes da venda` (G5).
- **Concorrência na venda (D4):**
  - **Direto:** com uma cobrança Direto pendente, uma nova cobrança Direto é recusada ("Já existe cobrança aguardando na maquininha"). O lançamento manual continua liberado para outras formas.
  - **Listado ("Lançar total na maquininha"):** pode haver mais de um pendente, desde que a soma não passe do restante.

### 6.4 Entregador e mesa

Usam a mesma `elegibilidade()` e o mesmo `valorDevido()`. Com cobrança pendente, abrem direto no estado "Aguardando".

---

## 7. Modelo de estados e regras de reenvio

### 7.1 Estados do `StonePedido`

**Existentes [FATO]:** `aguardando`, `pago_parcial`, `pago`, `estornado`, `cancelado`, `falha`.

**Novo (aprovado, D3):** `indeterminado`, para quando o envio teve timeout ou erro de rede e **não se sabe** se o pedido foi criado na Stone.

`falha` passa a significar **só** recusa comprovada da API (HTTP 4xx com corpo). Nesse caso a Stone não criou o pedido, e é seguro reenviar.

| Estado (UI) | Como é determinado | Botão "Enviar" | Outras ações |
|---|---|---|---|
| Não enviado | Nenhum `StonePedido` não-terminal | Visível, se elegível | — |
| Enviando… | Requisição em curso (Livewire `wire:loading`) | Desabilitado | — |
| Aguardando pagamento | `aguardando` com `stp_order_id` | **Direto:** oculto. **Listado:** visível só se ainda sobrar valor devido além das cobranças pendentes (D4) | Cancelar, Ver, Reconsultar |
| Pago em parte | `pago_parcial` | Igual à linha acima: o saldo vai no mesmo pedido Listado, numa nova cobrança Listado pelo restante, ou é recebido manualmente no PDV | Ver |
| Pago | `pago` | **Oculto** (não reenvia) | Ver |
| Recusado na criação | `falha` (4xx) | Visível (nova tentativa) | Ver erro |
| Resultado desconhecido | `indeterminado` | **Oculto** até reconsultar | Reconsultar (obrigatório) |
| Cancelado | `cancelado` | Visível, se ainda elegível | Ver |
| Estornado | `estornado` | Visível, se o pedido voltou a ser devido (ver `CancelamentoVendaService`) | Ver |

**Recusa no cartão [FATO, via documentação].** Uma recusa no POS **não** gera webhook documentado (só `charge.paid` e `charge.refunded`), e no fluxo Listado o pedido continua aberto no POS para nova tentativa. Por isso "pagamento recusado" **não** vira um estado próprio: o pedido segue `aguardando`. Não inventamos esse estado.

### 7.2 Elegibilidade (`StoneRecebimentoService::elegibilidade`)

O pedido é elegível quando todas as condições abaixo valem:
- o status não é CANCELADO nem FINALIZADO, e o pedido não está na lixeira;
- não tem `pedido_venda_id`, ou seja, ainda não foi recebido no PDV;
- `valorDevido > 0`;
- existe `Maquininha::stoneDisponivel()` (com `connect_ativo`);
- não existe `StonePedido` **Direto** em `aguardando` ou `pago_parcial` para o alvo (D4);
- não existe `StonePedido` em `indeterminado` para o alvo, de nenhum modo: primeiro se reconsulta;
- `valorDevido > 0` já **descontando** as cobranças Listado pendentes. Isso deixa abrir uma segunda cobrança Listado só pelo restante e impede repetir o total;
- a maquininha escolhida tem vaga (menos de 30 pendentes, seção 8.9);
- a config `services.stone.secret_key` está preenchida.

O método devolve `{elegivel: bool, motivo: ?string}`. O motivo aparece como tooltip no botão desabilitado e como notificação se a ação for chamada mesmo assim.

---

## 8. Idempotência e integridade financeira

### 8.1 Trava de concorrência (envio)

Dentro de `DB::transaction` em `iniciarCobranca` / `iniciarCobrancaDePedido`:
1. `lockForUpdate` no **alvo** (`Pedido`, `Venda` ou `SessaoMesa`).
2. Rechecar a elegibilidade **dentro** da transação (pendência e valor devido).
3. Criar o `StonePedido` (`aguardando`, sem `stp_order_id`).
4. **Commit**, e só **depois** fazer o HTTP para a Stone. Assim a transação não fica aberta durante a chamada externa, e uma segunda tentativa já vê a linha pendente.

### 8.2 Guarda no banco (defesa em profundidade, D4)

A regra aprovada é: **Direto trava em uma pendente por alvo; Listado aceita mais de uma.** O banco garante a parte do Direto e a de `indeterminado` com uma coluna gerada `stp_chave_pendente`:

```text
IF(
  (stp_modo = 'direto' AND stp_status IN ('aguardando','pago_parcial'))
  OR stp_status = 'indeterminado',
  CONCAT(stp_origem, ':', COALESCE(stp_venda_id, stp_pedido_id, stp_sessao_mesa_id)),
  NULL
)
```

Com um índice `UNIQUE` sobre ela, o MySQL aceita vários NULL. Na prática:
- só pode existir **uma** cobrança Direto pendente por alvo;
- só pode existir **um** `indeterminado` por alvo;
- as cobranças Listado pendentes ficam com NULL e não colidem.

Use um nome curto para o índice; ver o gotcha de 64 caracteres em `feedback_mysql_identifier_length`.

**O que protege o Listado:** a soma das cobranças pendentes não pode passar do valor devido. Essa conta é feita **sob o lock do alvo** (8.1 e 8.5), e por isso não precisa de índice. Exemplo: um pedido de R$ 80 com uma Listado de R$ 80 pendente tem valor devido R$ 0, então um segundo envio é recusado. Com uma Listado de R$ 50 pendente, só cabe uma nova de até R$ 30.

**Por que o Direto trava:** no Direto a maquininha abre direto na tela de pagamento daquele valor, e o valor pago é sempre o total do pedido [FATO, docs Connect]. Duas cobranças Direto do mesmo alvo seriam duas telas de pagamento para a mesma conta.

### 8.3 Idempotência na Stone

- **[FATO]** O cabeçalho `Idempotency-Key: stone-pedido-{id}` já é enviado.
- **[FATO, fonte Stone]** A Pagar.me guarda a chave por **24 h** e devolve o mesmo resultado para requisições repetidas com ela.
- **[HIPÓTESE]** A página de referência do Connect não menciona o cabeçalho. A fonte é um artigo institucional da Stone, não a referência da API. Isso precisa ser validado em produção com um pedido de R$ 1,00, cancelado em seguida: repetir o POST com a mesma chave deve devolver o mesmo `order_id`.
- **Regra:** como a chave nasce do `StonePedido.id`, a **nova tentativa depois de um resultado desconhecido reenvia o mesmo `StonePedido`**, com a mesma chave, e nunca cria um registro novo.
- **Novo:** enviar `code: "rf-{stp_id}"` no corpo do `POST /orders`. **[FATO, docs Pagar.me]** O campo `code` existe (máx. 52 caracteres). Ele é o identificador nosso na Stone, útil no dashboard e na conciliação.

### 8.4 Resultado desconhecido (timeout / erro de rede)

- **Classificação no `StoneConnectService::criarPedido`:**
  - `ConnectionException` ou timeout → exceção nova `StoneResultadoDesconhecidoException` → `StonePedido` vai para `indeterminado`.
  - HTTP 4xx com corpo → `falha` (recusa comprovada).
  - HTTP 5xx → `indeterminado` (o servidor pode ter processado).
- **Recuperação** ("Reconsultar", automática no `stone:conciliar-pedidos` e na reabertura do modal):
  1. Se houver `stp_order_id` → `GET /orders/{id}`.
  2. Se não houver → **repetir o `POST /orders` com a mesma `Idempotency-Key`** dentro de 24 h. Se o pedido já existe, a Stone devolve o mesmo pedido, e o sistema grava `stp_order_id` → `aguardando`.
  3. Depois de 24 h sem `order_id` → mantém `indeterminado` e pede conferência manual no dashboard Pagar.me pelo `code` `rf-{id}`. Um "Marcar como não criado" (gerente, auditado) libera o reenvio.
- **[HIPÓTESE]** Busca de pedidos por `code` (`GET /orders?code=`). Não foi encontrada na documentação Connect consultada, então **não** é usada como mecanismo principal.

### 8.5 Valor devido

`valorDevido(alvo)` = total persistido − pagamentos já efetuados no pedido (`pg_pedido_pago = true`) − pagamentos já lançados (PDV) − cobranças Stone ainda não convertidas em pagamento: valor **solicitado menos o já pago** das cobranças pendentes (`aguardando`, `pago_parcial`, `indeterminado`), de qualquer modo. É isso que permite várias Listado sem passar do total (D4).

**Complemento obrigatório para a lacuna G4:** `StoneVendaAutomaticaService` precisa lançar na venda os pagamentos já efetuados no pedido, reaproveitando a mesma rotina que o PDV usa (`OperarVenda` ~2080). Sem isso, a venda gerada pelo webhook ficaria "devendo" e não finalizaria.

### 8.6 Envio ≠ aprovação

- O botão "Enviar" só cria o `StonePedido` em `aguardando`.
- O `PagamentosVenda` integrado continua nascendo **exclusivamente** do `charge.paid` (comportamento atual, mantido).
- A UI nunca mostra "pago" com base no retorno do POST.

### 8.7 Falha entre a Stone e a persistência local

| Cenário | Tratamento |
|---|---|
| POST OK, mas o `update` de `stp_order_id` falha (ex.: queda do DB) | O `StonePedido` fica `aguardando` sem `order_id`. O `stone:conciliar-pedidos` passa a tratar esse caso como `indeterminado` (repete o POST com a mesma chave). |
| `charge.paid` chega antes de gravarmos `stp_order_id` (corrida) | `resolverPedido` falha → hoje só loga. **Novo:** resolver também por `order.code = rf-{id}`, que o webhook traz. |

### 8.8 Conciliação

O `stone:conciliar-pedidos` ganha três tratamentos: `indeterminado` (8.4), `aguardando` sem `order_id`, e alerta (log + notificação de banco para Admin) para `StonePedido` pago sem `PagamentosVenda` correspondente.

### 8.9 Limite de 30 pedidos abertos por maquininha (D2)

**[FATO, docs Connect]** O POS lista no máximo 30 pedidos abertos. Um pedido só sai do POS quando é **fechado**. Não fechar depois do pagamento pode fazer a maquininha parar de mostrar pedidos novos.

- **Contagem:** `StoneRecebimentoService::vagasAbertas(Maquininha): int` = 30 − quantidade de `StonePedido` da maquininha (`stp_maquininha_id`) que ainda estão abertos na Stone. "Aberto" significa: `stp_order_id` preenchido, `stp_fechado_em` nulo, e status `aguardando`, `pago_parcial`, `indeterminado`, ou `pago` ainda sem fechamento confirmado. O limite (30) fica em `config('services.stone.limite_pedidos_abertos', 30)`, para ajustar sem deploy se a Stone mudar.
- **[HIPÓTESE]** Pedidos criados fora do RazelFood para a mesma maquininha (outro sistema, dashboard) também ocupam vagas e não aparecem na nossa contagem. Hoje só o RazelFood cria pedidos, então a contagem local basta. A API não oferece uma consulta documentada de "pedidos abertos por terminal" [FATO: não encontrada nas fontes].
- **Envio individual:** com 0 vagas, recusa com a mensagem "A maquininha X já tem 30 pedidos abertos. Feche ou cancele pedidos em Financeiro › Pedidos Stone."
- **Envio em lote:** em uma única transação, faz `lockForUpdate` na linha da `Maquininha` (serializa envios concorrentes para o mesmo aparelho), recalcula as vagas e compara com a quantidade elegível. Se passar, **recusa o lote inteiro** com a mensagem da seção 6.2. Se couber, cria os `StonePedido` (cada um sob o lock do seu alvo, 8.1), faz commit e só então dispara os POSTs, um por vez.
- **Lote com falha no meio:** cada POST segue as regras de 8.4. Uma falha comprovada (`falha`) devolve a vaga; um `indeterminado` continua ocupando a vaga até a reconsulta. O resumo final lista enviados, ignorados (com motivo) e com resultado desconhecido.
- **Conciliação:** o `stone:conciliar-pedidos` passa a fechar também os `pago` que ficaram sem `stp_fechado_em`, para liberar vagas.

---

## 9. Arquivos, classes e tabelas prováveis

| Tipo | Item | Mudança |
|---|---|---|
| Migration | `maquininhas` | `maquininha_connect_ativo` boolean padrão false, mais o backfill (decisão do usuário) |
| Migration | `stone_pedidos` | `stp_chave_pendente` (coluna gerada) + índice único; `stp_erro` (texto do último erro) |
| Enum | `StonePedidoStatus` | `Indeterminado` (label "Resultado desconhecido", cor warning, `pendente()` true) |
| Exception | `StoneResultadoDesconhecidoException` (nova, estende `StoneConnectException`) | timeout, rede, 5xx |
| Model | `Maquininha` | `$fillable`/cast + `scopeStoneDisponivel` com `connect_ativo` |
| Model | `Pedido` | `temPagamentoCombinadoStone()` sai dos pontos de disparo (pode ficar para sugestão de UI) |
| Model | `OpcoesPagamento` | `ehIntegracaoStone()` → `permiteCobrarStone()` (mesmo campo, novo sentido); rótulo do campo no cadastro |
| Config | `config/services.php` | `stone.limite_pedidos_abertos` (padrão 30) |
| Service | `StoneConnectService::criarPedido` | `code`, classificação de erros |
| Service | `StoneRecebimentoService` | `elegibilidade()`, `valorDevido()` (desconta Listado pendentes), `vagasAbertas()`, envio em lote com lock na maquininha, lock + transação no envio, `reconsultar()`, `resolverPedido` por `code` |
| Service | `StoneVendaAutomaticaService` | lançar os pagamentos já efetuados no pedido |
| Command | `StoneConciliarPedidos` | `indeterminado`, sem `order_id`, e `pago` sem `stp_fechado_em` (libera vaga) |
| Filament | `AtenderPedido` (+ blade) | `persistir()`, "Salvar e cobrar", modal reabre na pendência |
| Filament | `PedidosTable`, `ViewPedido` | elegibilidade, "Ver cobrança", "Reconsultar", badge; lote mantido com checagem de vagas (D2) |
| Filament | `OperarVenda` (+ blade) | seletor "Lançar manual / Cobrar na Stone" (`modoRecebimento`, D1), desconto das pendências no restante, trava Direto (D4) |
| Livewire | `PainelEntregador`, `MesaStoneCobranca` | elegibilidade, `valorDevido`, reabrir pendência |
| Filament | `MaquininhaResource` (form/table) | toggle "Recebe pedidos Stone Connect" |
| Filament | `OpcoesPagamentoResource` | validação: no máximo uma forma que permite Stone por `opcaopag_desc_nfe` |
| Docs | `docs/Instrucoes-Integracao-Stone.pdf` | seção "Lançar manual × Cobrar na Stone" e limite de 30 pedidos |

---

## 10. Plano de implementação por etapas

Cada etapa pode ser entregue e testada sozinha. A Etapa 1 resolve a dor operacional; a Etapa 0 é a mitigação opcional até ela entrar.

| Etapa | Conteúdo | Pode ir para produção sozinha? |
|---|---|---|
| **0. Mitigação opcional (sem código)** | Confirmar H1/H2 com as consultas da seção 2.2. Se o salão precisar destravar antes da Etapa 1: formas temporárias "— Stone Connect" (seção 5). Revisar o cadastro da T8 (tirar o número de série ou trocar a operadora). | Sim |
| **1. Opção no PDV + flag no terminal** | `modoRecebimento` no modal (D1), novo sentido do flag da forma + validação de unicidade por `desc_nfe`, migration `maquininha_connect_ativo` + backfill + `scopeStoneDisponivel` + toggle no Resource. Se a Etapa 0 foi feita, voltar o flag para as formas comuns. | Sim. **Resolve a operação.** |
| **2. Elegibilidade e valor devido** | `elegibilidade()`, `valorDevido()` (com Listado pendentes), remoção da dependência de `temPagamentoCombinadoStone()` nos 4 pontos de pedido, lançamento dos pagamentos já efetuados no `StoneVendaAutomaticaService` | Sim |
| **3. Concorrência, idempotência e limite** | Lock + transação, coluna gerada única (Direto e `indeterminado`, D4), `code`, estado `indeterminado` (D3), classificação de erros, reconsulta, `vagasAbertas()` + lote com lock na maquininha (D2), conciliação | Sim (com a validação de idempotência em produção da seção 12) |
| **4. UX das ações** | "Salvar e cobrar" no atendimento, modal reabrindo a pendência, "Ver cobrança"/"Reconsultar"/badge no painel, vagas no modal do lote | Sim |
| **5. Documentação** | Atualizar o PDF de instruções e o manual | — |

---

## 11. Testes e critérios de aceitação

**Padrão:** PHPUnit, `RefreshDatabase`, `Http::fake()` + `Http::preventStrayRequests()` (como em `EstornoStoneCancelaVendaTest`), banco isolado (`DB_DATABASE=testing_stone`). Nenhuma chamada real.

| # | Cenário | Tipo | Critério de aceitação |
|---|---|---|---|
| 1 | Pedido elegível enviado pelo painel e pelo atendimento | Feature (Livewire/TestAction) | 1 `StonePedido` `aguardando` com `order_id`; POST com `code`, `Idempotency-Key` e `devices_serial_number` da Sunmi; valor = `valorDevido` |
| 2 | Segundo envio **Direto** com cobrança Direto pendente | Feature | Ação oculta/desabilitada; chamada direta ao serviço lança exceção de domínio; só 1 POST (`Http::assertSentCount(1)`) |
| 2a | Segunda cobrança **Listado** com Listado pendente (D4) | Feature | Pedido R$ 80 com Listado R$ 50 pendente: aceita nova Listado até R$ 30 e recusa R$ 31; com Listado R$ 80 pendente, recusa qualquer valor |
| 2b | Corrida: dois envios Direto "simultâneos" | Feature | O índice único rejeita o segundo insert, o serviço converte o erro em mensagem amigável, 1 POST só |
| 2c | Limite de 30 (D2) | Feature | Maquininha com 28 abertos: lote com 2 elegíveis envia os 2; lote com 3 elegíveis (+ 1 não elegível) recusa tudo, sem nenhum POST, com a mensagem de vagas; envio individual com 30 abertos é recusado; `pago` sem `stp_fechado_em` conta como aberto; `cancelado`/`falha` não contam |
| 3 | Pagamento aprovado | Feature | Com `pago`, a ação some; o serviço recusa novo envio |
| 4a | Cancelado antes de pagar | Feature | `PATCH closed canceled`; status `cancelado`; envio liberado de novo |
| 4b | Recusa de cartão (sem webhook) | Feature | O status segue `aguardando`; nenhum pagamento é criado |
| 4c | API recusa com 4xx | Feature | `falha` + `stp_erro`; novo envio permitido |
| 5a | Timeout na criação | Feature (`Http::fake` lançando `ConnectionException`) | `indeterminado`; envio bloqueado; "Reconsultar" repete o POST com a **mesma** `Idempotency-Key` e grava `order_id` |
| 5b | 5xx na criação | Feature | `indeterminado` (não `falha`) |
| 5c | `charge.paid` com `order_id` desconhecido mas `code` `rf-{id}` | Feature | Resolve o `StonePedido` pelo `code` e lança o pagamento |
| 6 | Pagamento manual Cielo Flash e Tectoy T8 no PDV com a forma "Crédito" que permite Stone, em modo **"Lançar manual"** (D1) | Feature | `PagamentosVenda` com `pg_venda_maquininha_id` correto, bandeira e autorização; nenhum HTTP para a Stone |
| 6a | Mesma forma em modo **"Cobrar na Stone"** | Feature | Cria `StonePedido` Direto na maquininha Connect; nenhum `PagamentosVenda` até o `charge.paid` |
| 6b | Modo "stone" forçado com forma que não permite, ou com maquininha sem `connect_ativo` | Feature | Recusado no servidor; nenhum POST |
| 6c | Cadastro de duas formas que permitem Stone com o mesmo `desc_nfe` | Feature (Filament) | Erro de validação no formulário |
| 7 | Registro manual sem Connect configurado (`secret_key` nula) e com a API fora (`Http::fake` 500) | Feature | O pagamento manual grava normalmente; nenhuma exceção |
| 8 | Duplicidade de pedidos/pagamentos/lançamentos | Feature | Webhook `charge.paid` repetido → 1 `PagamentosVenda`; "Salvar e cobrar" com duplo clique → 1 pedido; venda gerada com o pagamento já efetuado do pedido sem duplicar |
| 9 | Ações do formulário e do painel | Feature (Livewire) | Visibilidade conforme a matriz da seção 7 para cada estado; "Salvar e cobrar" não abre o modal se a validação falhar |
| 10 | Fechamento de caixa e relatórios | Feature | Uma venda em "Crédito" manual (Cielo) + uma em "Crédito" cobrada na Stone (Sunmi): totais da forma e da categoria crédito somam as duas; por operadora, Cielo × Stone separadas; saldo da sessão correto; os testes existentes de `RelatorioFechamentoCaixa*`, `FechamentoCaixa*` e `Stone*` seguem verdes |
| 11 | `scopeStoneDisponivel` | Unit/Feature | Uma T8 Stone com número de série e `connect_ativo=false` não aparece |

**Regressão:** suíte completa antes de cada promoção (`--compact`), com atenção especial a `OperarVendaStonePagamentoTest`, `EstornoStoneCancelaVendaTest` e aos testes de webhook.

**Teste em terminal real** (separado, ambiente controlado, conta de produção, já que **não existe sandbox** [FATO, docs Connect]):
- Cobranças de R$ 1,00, sempre estornadas ou canceladas em seguida.
- Roteiro:
  1. Envio pelo painel.
  2. Duplo clique.
  3. Desligar o Wi-Fi do servidor de teste durante o POST (resultado desconhecido) e reconsultar.
  4. Salão lança Cielo e T8 em "Lançar manual" durante uma cobrança pendente na Sunmi.
  5. Duas cobranças Listado do mesmo pedido (pagamento dividido) aparecendo na lista do POS.

---

## 12. Riscos, dependências e implantação

| Risco | Mitigação |
|---|---|
| Operador escolher o modo errado ("Lançar manual" depois de passar o cartão na Sunmi sem pedido, ou vice-versa) | Padrão "Lançar manual" com a escolha visível em destaque; o manual não deixa escolher maquininha com `connect_ativo` sem confirmação "Esta maquininha é integrada: deseja mesmo lançar manual?"; charge avulsa na Sunmi já gera log de aviso (`processarChargePaid` sem `StonePedido`); treinamento rápido no manual |
| A Idempotency-Key da Pagar.me não funcionar como descrito | Validação em produção na Etapa 3 antes de ativar a "Reconsultar" automática; fallback manual com o `code` |
| Coluna gerada e índice único em MySQL de produção | Testar a migration num dump; nome curto de índice; checar com `SHOW INDEX` depois do deploy |
| Pendências antigas (`aguardando` sem `order_id`) bloqueando envios depois da trava | Script de diagnóstico (`--dry-run`) antes da migration, para listar e resolver |
| Limite de 30 pedidos abertos por POS [FATO] | Checagem de vagas no envio individual e no lote, com lock na maquininha (8.9); conciliação fecha os `pago` não fechados |
| Pedidos abertos criados fora do RazelFood ocupando vagas [HIPÓTESE] | Limite configurável (`stone.limite_pedidos_abertos`); se a Stone recusar por limite, a resposta vira `falha` com a mensagem dela |
| A T8 estar de fato no Connect | Confirmar com a Stone; mesmo se estiver, `connect_ativo=false` impede o uso |

**Dependências:** confirmação de H1–H3; decisões D1–D4 já aprovadas; falta só a lista de maquininhas para o backfill de `connect_ativo` (esperado: só a Sunmi P2-B).

**Implantação** (por etapa, seguindo o processo do projeto):
1. Commit em `prod-new` e promoção com `scripts/promote-producao.sh`.
2. No servidor: `git pull`, `php artisan migrate` (Etapas 1 e 3), `php artisan config:cache` se o `.env` mudar e reinício do PHP (OPcache).
3. A Etapa 0 é só cadastro, feita pelo Filament, sem deploy.

**Rollback:** cada migration tem `down()`. A Etapa 0 se desfaz remarcando os flags. Para a Etapa 1, voltar o código anterior restaura o sentido antigo do flag (sempre cobra na Stone), então se o rollback for necessário, desmarcar o flag nas formas usadas pelo salão.

---

## 13. Fontes oficiais consultadas

- **Connect Stone, Criar pedido para maquininha:** https://connect-stone.stone.com.br/reference/criar-pedido. `closed:false`, `poi_payment_settings`, `payment_setup` no Pagamento Direto, header `ServiceRefererName`. **Não** documenta `Idempotency-Key`.
- **Connect Stone, Fechamento de um pedido:** https://connect-stone.stone.com.br/reference/fechamento-de-um-pedido. `PATCH /orders/{id}/closed` com `paid`/`canceled`/`failed`; limite de 30 pedidos abertos; fechar sempre depois do pagamento.
- **Connect Stone, Operações:** https://connect-stone.stone.com.br/docs/opera%C3%A7%C3%B5es. Webhooks `charge.paid` e `charge.refunded`; estorno só pelo POS ou portal; sem sandbox.
- **Connect Stone, Dúvidas frequentes:** https://connect-stone.stone.com.br/page/faq. Modelos citados S920/Q92; pedidos não expiram; estorno sem rota de API; transações avulsas ficam sem vínculo.
- **Connect Stone, Exemplos Direto e Listado:** https://connect-stone.stone.com.br/reference/pedido-direto.
- **Partner Hub, Editar Ativação:** https://partnerhubapi.stone.com.br/reference/editar-ativação. `payment_type` `direct_payment` × `order_selection`, `webhook_url`.
- **Pagar.me v5, Criar pedido:** https://docs.pagar.me/reference/criar-pedido-2. Campo `code` (máx. 52).
- **Stone, Idempotência (conteúdo institucional):** https://conteudo.stone.com.br/idempotencia/. Header `Idempotency-key`, validade de 24 h na criação de pedidos. *Fonte secundária, validar.*

**Não encontrado na documentação oficial:**
- lista de modelos compatíveis que inclua Sunmi P2-B ou Tectoy T8;
- busca de pedido por `code`;
- tratamento de timeout;
- webhook de recusa.

---

## 14. Prompt para o agente implementador

```text
Implemente, de uma vez, o plano docs/plano/plano-multiplas-maquininhas-connect-stone.md
no RazelFood (Laravel 12, Filament 4, Livewire 3, Sail/MySQL). Leia o plano inteiro
antes de começar, depois CLAUDE.md, .ai/rules/index.md e as regras cujos globs cobrem
os arquivos da seção 9. O plano é a especificação: em caso de dúvida, ele prevalece.

DECISÕES APROVADAS (seção 0 do plano):
- D1: forma única ("Crédito", "Débito", "PIX"). O flag opcaopag_stone_integrada passa a
  significar "PODE cobrar na Stone". No modal de pagamento do OperarVenda, quando a
  forma permite e existe maquininha Connect, mostrar o seletor "Lançar manual"
  (padrão) / "Cobrar na Stone" (propriedade modoRecebimento, lembrada por operador
  na sessão). Manual = todas as maquininhas + bandeira + autorização, grava pelo
  gravarPagamento atual. Stone = só maquininhas connect_ativo, fluxo
  iniciarCobrancaStone atual. Validar o modo no servidor. Edição de pagamento é
  sempre manual. Cadastro: no máximo uma forma com o flag por opcaopag_desc_nfe.
- D2: envio em lote no PedidosTable mantido, com limite de pedidos abertos por
  maquininha (config services.stone.limite_pedidos_abertos, padrão 30). Se os
  elegíveis selecionados não couberem nas vagas, NÃO envia nenhum e explica
  (vagas × selecionados). Modal mostra as vagas da maquininha escolhida. Checagem
  refeita sob lockForUpdate da Maquininha. Envio individual também respeita o limite.
- D3: novo StonePedidoStatus::Indeterminado (timeout, erro de rede, HTTP 5xx no
  POST /orders). Falha passa a ser só recusa 4xx comprovada.
- D4: Direto = no máximo UMA cobrança pendente por alvo (venda/pedido/sessão de
  mesa). Listado aceita várias pendentes, desde que a soma não passe do valor devido.

BACKFILL de maquininhas.maquininha_connect_ativo: marcar true onde operadora = stone
E numero_serie não nulo (preserva o comportamento atual). O usuário desmarca a T8
pelo Resource depois do deploy; registre isso no resumo final.

ORDEM DE TRABALHO (seção 10, etapas 1 a 4; a Etapa 0 é cadastro e não é sua):
1. Opção no PDV + flag no terminal: migration connect_ativo + backfill,
   Maquininha (fillable/cast, scopeStoneDisponivel exige connect_ativo e
   numero_serie), toggle no MaquininhaResource, OpcoesPagamento::permiteCobrarStone()
   (renomeie ehIntegracaoStone e atualize todos os usos), rótulo e validação no
   OpcoesPagamentoResource, modoRecebimento no OperarVenda + blade.
2. Elegibilidade e valor devido no StoneRecebimentoService: elegibilidade() que
   devolve {elegivel, motivo}; valorDevido() conforme 8.5 (desconta pagamentos já
   efetuados no pedido, pagamentos lançados e o saldo das cobranças pendentes de
   qualquer modo). Trocar temPagamentoCombinadoStone() por elegibilidade() em
   AtenderPedido, PedidosTable, PainelEntregador e MesaStoneCobranca. Usar
   valorDevido() em todos os pontos de disparo (nunca pedido_valor_total nem
   totalPreview). StoneVendaAutomaticaService passa a lançar na venda os pagamentos
   já efetuados no pedido (pg_pedido_pago), reaproveitando a lógica do OperarVenda.
3. Concorrência, idempotência e limite:
   - Envio em DB::transaction com lockForUpdate no alvo; elegibilidade rechecada lá
     dentro; cria o StonePedido; commit; só então o HTTP.
   - Coluna gerada stp_chave_pendente + UNIQUE (fórmula da 8.2, índice com nome
     curto); converter a violação em mensagem amigável.
   - POST /orders com "code": "rf-{stp_id}" e a Idempotency-Key atual
     (stone-pedido-{id}).
   - StoneResultadoDesconhecidoException (estende StoneConnectException);
     classificação 4xx → falha (+ stp_erro), timeout/rede/5xx → indeterminado.
   - reconsultar(): com order_id faz GET /orders/{id}; sem order_id repete o POST
     com a MESMA Idempotency-Key dentro de 24 h; depois disso mantém indeterminado.
   - resolverPedido também por order.code = rf-{id}.
   - vagasAbertas() conforme 8.9; lote com lock na Maquininha.
   - stone:conciliar-pedidos trata indeterminado, aguardando sem order_id e pago sem
     stp_fechado_em.
4. UX:
   - AtenderPedido: refatorar save() em persistir(): ?Pedido; botão "Salvar e
     cobrar na maquininha"; formulário com alterações não salvas troca "Cobrar"
     por "Salvar e cobrar"; com pendência, o modal abre direto em "Aguardando".
   - PedidosTable/ViewPedido: matriz de visibilidade da seção 7.1, ações "Ver
     cobrança" (slide-over só leitura) e "Reconsultar na Stone", badge do último
     status Stone (eager load, sem N+1), tooltip com o motivo quando desabilitado.
   - OperarVenda: valor ≤ restante − pendentes; trava de Direto (D4).
   - PainelEntregador e MesaStoneCobranca: reabrem a pendência existente.

TESTES: implemente todos os cenários da seção 11 (1, 2, 2a, 2b, 2c, 3, 4a–4c, 5a–5c,
6, 6a–6c, 7, 8, 9, 10, 11). Use PHPUnit, RefreshDatabase, Http::fake() +
Http::preventStrayRequests() (modelo: tests/Feature/EstornoStoneCancelaVendaTest.php)
e crie arquivos com `vendor/bin/sail artisan make:test --phpunit --no-interaction`.
Atualize os testes Stone existentes que dependiam do sentido antigo do flag
(ex.: OperarVendaStonePagamentoTest passa a escolher modoRecebimento = stone) sem
enfraquecer as asserções. Rode sempre em banco isolado:
  vendor/bin/sail exec -u sail -e DB_DATABASE=testing_stone laravel.test \
    php -d memory_limit=1G artisan test --compact <arquivos ou --filter>
Ao terminar cada etapa, rode os testes dela + todos os Stone*, *Caixa*,
*Fechamento*, *RelatorioSessao* e OperarVenda*. No fim, rode a suíte inteira e
compare com as falhas pré-existentes (não atribua a você falha que já existia; prove
com git stash ou com o resultado antes das mudanças).

REGRAS INEGOCIÁVEIS:
- PagamentosVenda integrado só nasce de charge.paid. Envio não é aprovação.
- Resultado desconhecido nunca gera StonePedido novo: reenvia o MESMO registro.
- Nenhum HTTP para a Stone dentro de transação de banco.
- O valor cobrado é sempre calculado no servidor (valorDevido).
- O modo manual nunca chama StoneConnectService e funciona com a Stone fora ou sem
  secret_key.
- Sem chamadas reais à Stone, sem tocar em dados de produção, sem npm/composer novos.
- Migrations via `vendor/bin/sail artisan make:migration --no-interaction`, com
  down() funcional; ao alterar coluna existente, repetir todos os atributos dela.
- Siga o padrão dos arquivos irmãos (prefixo de colunas, serviços concretos, Http
  facade, Filament 4: Actions em Filament\Actions, Schema components em
  Filament\Schemas\Components). Use search-docs do Boost antes de APIs Filament,
  Livewire ou Laravel em que tiver dúvida.
- Rode `vendor/bin/sail bin pint --dirty --format agent` antes de concluir.
- Se mexer em blade/CSS, rode `vendor/bin/sail npm run build` e inclua public/build.
- Não commitar sem autorização. Quando autorizado, um commit por etapa, git add
  listando arquivos por nome (nunca -A; o working tree tem trabalho de outras
  sessões), mensagem em português terminando com:
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>

ENTREGA FINAL (em português):
- O que foi feito por etapa, com arquivos alterados e criados.
- Testes: quantos rodaram, resultado, e falhas pré-existentes separadas.
- Desvios do plano e por quê (só com evidência).
- Passos de deploy: git pull, php artisan migrate, php artisan config:cache (por
  causa de stone.limite_pedidos_abertos), reiniciar PHP no cPanel (OPcache);
  desmarcar connect_ativo da Tectoy T8 no Filament; se a Etapa 0 tiver sido usada,
  devolver o flag às formas comuns.
- Roteiro curto de teste na maquininha real (R$ 1,00, cancelar/estornar em seguida)
  e validação da Idempotency-Key em produção (seção 8.3).
```

---

## 15. Revisão 1 (2026-10-28): atendimento em Listado e NFC-e automática

> **Contexto:** o plano (Etapas 1 a 4) foi implementado em `00be6f69`. Esta revisão ajusta duas regras depois do uso real. As seções 0 a 14 continuam valendo, exceto onde esta seção diz o contrário.

### 15.1 Decisões

| # | Decisão | Substitui |
|---|---|---|
| D5 | O botão **"Cobrar na maquininha"** do atendimento de pedido (`AtenderPedido`) envia **sempre em modo Listado** e **não fica esperando o pagamento na tela**. A maioria dos pedidos é de entrega e o pagamento acontece no ato da entrega, no POS do entregador. | Seção 6.1 (modal com forma opcional → Direto, e acompanhamento "Aguardando" na tela) |
| D6 | Quando o webhook cria a venda e a finaliza com o recebimento total, a **NFC-e é enviada à NFE.io automaticamente**, sem depender de fila com worker. | Seção 3.2 (emissão em fila `EmitirNfeAutomaticaJob`) |

### 15.2 D5: atendimento sempre em Listado, sem espera

**Como está hoje [FATO, `00be6f69`]:**
- `AtenderPedido::enviarCobrancaStone()` (~linha 1022) envia em Direto quando o operador escolhe uma forma no modal (`stoneOpcaoPagamentoId`) e em Listado quando não escolhe.
- Depois do envio, o modal fica em "Aguardando" com polling (`verificarStatusStone`) até o `charge.paid`.

**Como fica:**
1. **O modal só pede a maquininha.** Sai o select de forma (`stoneOpcaoPagamentoId`, `opcoesPagamentoStone()` e o bloco do blade). O serviço é chamado com `opcao: null` e `modo: StonePedidoModo::Listado`. O tipo (crédito, débito ou PIX) é escolhido na maquininha, e a forma é resolvida no webhook (`resolverOpcaoStone`).
2. **Depois do envio com sucesso:** o modal fecha e mostra uma notificação de sucesso, "Pedido #N enviado para a maquininha X (R$ valor). O pagamento será registrado quando o entregador receber." Não há polling. Vale o mesmo comportamento do `save()`:
   - pedido novo (veio de "Salvar e cobrar") → redireciona para o atendimento em branco;
   - pedido existente → recarrega o próprio pedido.
3. **Resultado desconhecido (`indeterminado`):** **mantém** o modal aberto com a mensagem e o botão "Reconsultar" (D3). Esse caso não pode sumir da tela, porque o operador não sabe se o pedido chegou ao POS.
4. **Falha comprovada (4xx):** mantém o modal com o erro e permite tentar de novo, como hoje.
5. **Pedido com cobrança já enviada** (reabrir o modal com `aguardando` ou `pago_parcial`): o modal mostra um resumo, sem polling, com:
   - maquininha, valor, data e hora do envio e status;
   - botão **"Cancelar cobrança"**, com confirmação;
   - botão **"Enviar valor restante"**, só se `valorDevido() > 0` (D4: Listado aceita mais de uma, e a soma não passa do devido);
   - botão **"Fechar"**.
6. **Indicador fora do modal:** abaixo dos botões do resumo, um aviso discreto quando houver cobrança Stone pendente: "Na maquininha X desde hh:mm · R$ valor". Ele substitui o acompanhamento em tempo real.
7. **Fica como está:** o PDV (`OperarVenda`), que continua com Direto e espera na tela (balcão); o painel de pedidos (`PedidosTable`), que pode enviar Direto se o operador escolher a forma; o entregador e a mesa, que já usam Listado.

**Arquivos:** `app/Filament/Pages/AtenderPedido.php`, `resources/views/filament/pages/atender-pedido.blade.php`. Nenhuma migration.

### 15.3 D6: NFC-e automática quando o webhook finaliza a venda

**Como está hoje [FATO]:**
- `StoneVendaAutomaticaService::finalizarSePago()` (~linha 220) finaliza a venda e chama `EmitirNfeAutomaticaJob::dispatch($venda->id)->afterCommit()`.
- O job só emite se `EmissaoNfeAutomaticaService::deveEmitir()` for verdadeiro, ou seja:
  - empresa com NFE.io configurada;
  - `Venda::exigeEnvioNfe()`: algum pagamento com forma `opcaopag_envio_automatico_nfe = true`;
  - item fiscal na venda.
- Em produção a fila é `database`. O único worker agendado (`app/Console/Kernel.php`, ~linha 63) consome **só** a fila da exportação de pedidos. O comentário do próprio arquivo diz: *"A fila padrão (NFC-e automática) não é tocada"*.

**Causas prováveis, a confirmar com o diagnóstico abaixo:**
- **C1 (principal) [HIPÓTESE]:** o job fica parado na tabela `jobs` porque não há worker para a fila padrão.
- **C2 [HIPÓTESE]:** a forma "pode cobrar na Stone" do tipo resolvido no webhook não tem "envio automático de NFC-e" marcado, então `deveEmitir()` é falso.
- **C3 [HIPÓTESE]:** a venda não chegou a ser finalizada (por exemplo, sem sessão de caixa aberta), então o job nem é despachado. Fica registrado no log `stone` como "não pôde ser finalizada automaticamente".

**Diagnóstico (produção, só leitura):**
```sql
SELECT queue, COUNT(*) FROM jobs GROUP BY queue;
SELECT id, failed_at FROM failed_jobs WHERE payload LIKE '%EmitirNfeAutomaticaJob%' ORDER BY id DESC LIMIT 10;
SELECT id, opcaopag_nome, opcaopag_desc_nfe, opcaopag_stone_integrada, opcaopag_envio_automatico_nfe FROM opcoes_pagamentos;
```

**Como fica:**
1. **Emissão logo depois da resposta ao webhook.** Em `finalizarSePago()`, o `dispatch(...)->afterCommit()` vira `EmitirNfeAutomaticaJob::dispatchAfterResponse($venda->id)`.
   - Roda no mesmo processo PHP, depois que a resposta 200 já saiu para a Stone. Não atrasa o webhook e não depende de worker.
   - **Atenção:** `finalizarSePago()` é chamado de dentro do fluxo do webhook, mas também pelo `stone:conciliar-pedidos` (CLI) e pela ação "Gerar venda" do hub (Livewire). Em todos esses casos, `dispatchAfterResponse` executa no `terminate` da aplicação, então funciona nos três. Se a chamada acontecer dentro de uma transação aberta, o job precisa só **ler** a venda já commitada. Verifique que `finalizarSePago()` não está dentro de `DB::transaction` no chamador. Se estiver, despache depois do commit com `DB::afterCommit(fn () => EmitirNfeAutomaticaJob::dispatchAfterResponse(...))`.
2. **O job continua idempotente:** não emite se `venda_id_nfe` já estiver preenchido ou se a venda não estiver FINALIZADA. Isso já existe e deve ser mantido.
3. **Visibilidade quando não emite (C2):** se `deveEmitir()` for falso numa venda finalizada pela Stone, registrar no log `stone` em nível `warning` "NFC-e automática não emitida", com o motivo específico (NFE.io não configurada / nenhuma forma com envio automático / sem item fiscal). Hoje isso é silencioso.
4. **Rede de segurança:** o `stone:conciliar-pedidos` passa a procurar vendas **FINALIZADAS** com `StonePedido` de origem `pedido` ou `sessao_mesa`, sem `venda_id_nfe`, em que `deveEmitir()` é verdadeiro, finalizadas há mais de 5 minutos e há menos de 24 horas (dentro do prazo de autorização da NFC-e). Para cada uma, chama `EmissaoNfeAutomaticaService::emitir()` diretamente, sem fila, protegido por `lockForUpdate` na venda e rechecando `venda_id_nfe` dentro do lock, para não emitir em dobro com o job.
5. **Falha na NFE.io:** o comportamento atual se mantém: loga e não lança. A venda continua finalizada, e a emissão pode ser refeita manualmente pela tela de vendas ou pela rede de segurança do item 4.
6. **Fila padrão:** não é preciso adicionar worker para ela nesta revisão. Jobs `EmitirNfeAutomaticaJob` antigos parados na tabela `jobs` podem ser descartados depois do deploy, porque a rede de segurança emite as notas das últimas 24 horas que ficaram pendentes. Notas mais antigas que isso precisam de conferência manual e não são reenviadas automaticamente.

**Arquivos:** `app/Services/Stone/StoneVendaAutomaticaService.php`, `app/Jobs/EmitirNfeAutomaticaJob.php` (só se precisar de log), `app/Console/Commands/StoneConciliarPedidos.php`, e possivelmente `app/Services/Nfe/EmissaoNfeAutomaticaService.php` (um método `motivoNaoEmitir(Venda): ?string` para o log). Nenhuma migration.

### 15.4 Testes da revisão

| # | Cenário | Critério de aceitação |
|---|---|---|
| R1 | Atendimento: "Cobrar na maquininha" com pedido elegível | POST `/orders` **sem** `payment_setup` (Listado); `StonePedido.stp_modo = listado`, `stp_opcaopagamento_id` nulo; o modal fecha, a notificação de sucesso aparece, e nenhum polling fica ativo (`stonePedidoId` nulo, `modalStoneAberta` falso) |
| R2 | Atendimento: "Salvar e cobrar" em pedido novo | 1 pedido criado, 1 `StonePedido` Listado, redireciona para o atendimento em branco |
| R3 | Atendimento: resultado desconhecido | O modal **continua aberto** em `indeterminado` com "Reconsultar" |
| R4 | Atendimento: reabrir pedido com cobrança pendente | O modal mostra o resumo (maquininha, valor, status) e "Cancelar cobrança"; "Enviar valor restante" aparece só com `valorDevido > 0`; não há select de forma |
| R5 | O select de forma não existe mais no modal do atendimento | `assertDontSee` do rótulo antigo; a propriedade `stoneOpcaoPagamentoId` foi removida |
| R6 | Webhook `charge.paid` quita um pedido de origem `pedido` com forma de envio automático | Venda FINALIZADA e `EmitirNfeAutomaticaJob` despachado **after response** (`Bus::fake()` + `Bus::assertDispatchedAfterResponse`) |
| R7 | O job executa contra a NFE.io fake | `Http::fake` da NFE.io recebe 1 POST; `venda_id_nfe` preenchido; executar o job de novo não faz um segundo POST |
| R8 | Forma sem envio automático | Nenhum POST para a NFE.io; log `warning` com o motivo |
| R9 | Rede de segurança no `stone:conciliar-pedidos` | Venda finalizada pela Stone há 10 minutos sem NFC-e → emite 1 vez; finalizada há 2 minutos → ignora; finalizada há 25 horas → ignora; com `venda_id_nfe` → ignora; rodar o comando 2 vezes → 1 POST só |
| R10 | Regressão do PDV | `OperarVenda` segue Direto com espera na tela; os testes `OperarVendaStone*` passam sem mudança |

---

## 16. Prompt para o agente implementar a Revisão 1

```text
Implemente a Revisão 1 (seção 15) de docs/plano/plano-multiplas-maquininhas-connect-stone.md
no RazelFood (Laravel 12, Filament 4, Livewire 3, Sail/MySQL). O plano original já está
implementado no commit 00be6f69; leia a seção 15 inteira e as seções 6.1, 7, 8.4 e 8.9
para contexto. Siga CLAUDE.md, .ai/rules/index.md e as regras que cobrem os arquivos.

D5 — Atendimento de pedido (AtenderPedido + blade):
- "Cobrar na maquininha" e "Salvar e cobrar" enviam SEMPRE em modo Listado, sem forma:
  remova stoneOpcaoPagamentoId, opcoesPagamentoStone() e o select do blade; chame
  iniciarCobrancaDePedido($pedido, null, $maquininha, modo: StonePedidoModo::Listado).
- Envio com sucesso: fecha o modal, notificação de sucesso com número do pedido,
  maquininha e valor, sem polling; pedido novo redireciona para o atendimento em
  branco, pedido existente recarrega o próprio pedido.
- indeterminado: o modal continua aberto com a mensagem e "Reconsultar". Falha 4xx:
  o modal continua com o erro e permite tentar de novo.
- Reabrir com cobrança aguardando/pago_parcial: resumo (maquininha, valor, envio,
  status) sem polling, com "Cancelar cobrança" (confirmação), "Enviar valor restante"
  (só se valorDevido() > 0) e "Fechar".
- Aviso discreto fora do modal quando houver cobrança pendente:
  "Na maquininha X desde hh:mm · R$ valor".
- Não altere OperarVenda (PDV segue Direto com espera), PedidosTable, PainelEntregador
  nem MesaStoneCobranca.

D6 — NFC-e automática quando o webhook finaliza a venda:
- Em StoneVendaAutomaticaService::finalizarSePago(), troque
  EmitirNfeAutomaticaJob::dispatch(...)->afterCommit() por dispatchAfterResponse.
  Antes, confira todos os chamadores de finalizarSePago() (webhook,
  stone:conciliar-pedidos, ação "Gerar venda" do hub StonePedidos): se algum chama
  dentro de DB::transaction, despache via DB::afterCommit(fn () =>
  EmitirNfeAutomaticaJob::dispatchAfterResponse($id)).
- Mantenha a idempotência do job (não emite com venda_id_nfe preenchido ou venda
  não FINALIZADA).
- Quando deveEmitir() for falso numa venda finalizada pela Stone, logue warning no
  canal stone com o motivo específico (crie EmissaoNfeAutomaticaService::
  motivoNaoEmitir(Venda): ?string reaproveitando as mesmas condições de deveEmitir()).
- stone:conciliar-pedidos ganha a rede de segurança da seção 15.3 item 4: vendas
  FINALIZADAS com StonePedido de origem pedido/sessao_mesa, sem venda_id_nfe,
  deveEmitir() verdadeiro, finalizadas entre 5 min e 24 h atrás; emitir direto com
  lockForUpdate na venda e rechecagem de venda_id_nfe dentro do lock; NfeIoException
  é logada e não interrompe o comando.
- Não adicione worker para a fila padrão e não altere o Kernel.

TESTES: implemente R1 a R10 da seção 15.4. PHPUnit, RefreshDatabase, Http::fake() +
Http::preventStrayRequests() (Stone e NFE.io), Bus::fake() para R6 (use
Bus::assertDispatchedAfterResponse) e execução real do job para R7. Use
travel()/Carbon::setTestNow para R9. Atualize os testes do atendimento que esperavam
Direto/polling sem enfraquecer as asserções. Crie arquivos com
`vendor/bin/sail artisan make:test --phpunit --no-interaction`. Rode em banco isolado:
  vendor/bin/sail exec -u sail -e DB_DATABASE=testing_stone laravel.test \
    php -d memory_limit=1G artisan test --compact <arquivos ou --filter>
Antes de concluir, rode todos os testes Stone*, AtenderPedido*, OperarVenda*, *Nfe*,
*Caixa* e a suíte inteira, separando as falhas pré-existentes (prove com git stash).

REGRAS:
- Envio não é aprovação: PagamentosVenda integrado só nasce de charge.paid.
- Nenhum HTTP (Stone ou NFE.io) dentro de transação de banco.
- Sem chamadas reais, sem tocar em dados de produção, sem dependências novas.
- Rode `vendor/bin/sail bin pint --dirty --format agent`. Mexeu em blade: rode
  `vendor/bin/sail npm run build` e inclua public/build.
- Não commitar sem autorização. Quando autorizado: um commit, git add listando os
  arquivos por nome (nunca -A), mensagem em português terminando com
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>

ENTREGA FINAL (em português): arquivos alterados; testes rodados e resultado (com as
falhas pré-existentes separadas); desvios do plano com evidência; passos de deploy
(git pull, reiniciar PHP/OPcache; sem migration); como conferir em produção: um
pedido de entrega enviado pelo atendimento aparece na lista do POS, e depois do
pagamento a venda finaliza e a NFC-e é autorizada; e o que fazer com jobs
EmitirNfeAutomaticaJob antigos na tabela jobs (consulta + descarte).
```
