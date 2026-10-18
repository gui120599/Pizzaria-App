# Roteiro de testes — Homologação Stone Connect

- **Aplicação:** RazelFood Menu Manager
- **Integração:** Stone Connect (API Pagar.me v5)
- **Tipo de pedido:** Pedido Direto (pagamento definido no pedido, `payment_setup`)
- **Onde ver as evidências no sistema:** Financeiro › Pedidos Stone (Order ID, Charge ID e status) e Financeiro › Webhooks Stone (eventos recebidos)

## Pré-requisitos

- Sessão de caixa aberta no PDV.
- Maquininha Stone cadastrada com o número de série.
- Forma de pagamento "Crédito" marcada como integrada Stone.
- Webhook de produção cadastrado na Pagar.me para os eventos `charge.paid` e `charge.refunded`.

---

## Teste 001 — Criação de pedido aberto

**Objetivo:** criar um pedido aberto de R$ 5,00 com pagamento direto no crédito à vista.

**Execução no RazelFood Menu Manager:**
1. No PDV, lançar um item de R$ 5,00.
2. Em pagamento, escolher **Crédito** (integrado Stone), selecionar a maquininha e enviar.
3. O sistema cria o pedido na Stone e exibe *"Aguardando o pagamento na maquininha…"*.
4. A cobrança de R$ 5,00, crédito à vista, aparece na maquininha.

**Resultado esperado:** pedido criado com status aguardando e Order ID em Financeiro › Pedidos Stone.

| Evidência | Registro |
|---|---|
| Data da transação | |
| Order_id | |
| Observação | Pedido Direto criado pelo PDV. Cobrança exibida na maquininha. |
| Foto | Maquininha com a transação para ser paga |

---

## Teste 002 — Pagamento do Pedido Direto

**Objetivo:** pagar o pedido do Teste 001 (R$ 5,00, crédito à vista) e receber o webhook `charge.paid`.

**Execução:**
1. Pagar com cartão de crédito na maquininha.
2. O sistema recebe o webhook `charge.paid` e o PDV mostra *"Pagamento confirmado!"* sem ação do operador.

**Resultado esperado:** evento `charge.paid` registrado em Financeiro › Webhooks Stone, com o Charge ID, e pagamento lançado na venda.

| Evidência | Registro |
|---|---|
| Data da transação | |
| Charge_id | |
| Observação | Webhook `charge.paid` recebido; pagamento registrado automaticamente na venda. |
| Webhook | Tela de Webhooks Stone com o status `charge.paid` |

---

## Teste 003 — Fechamento do pedido aberto

**Objetivo:** fechar o pedido aberto do Teste 001.

**Execução:**
1. Com o valor total recebido, o sistema fecha o pedido na Stone e finaliza a venda automaticamente.
2. A maquininha volta para a tela inicial, sem transação pendente, e imprime o comprovante.

**Resultado esperado:** pedido fechado, sem transação aberta no POS, e venda finalizada no caixa.

| Evidência | Registro |
|---|---|
| Data da transação | |
| Charge_id | |
| Observação | Fechamento automático ao receber o valor total; venda finalizada no PDV. |
| Foto | POS sem a transação aberta e comprovante da transação |

---

## Teste 004 — Impressão de NF (se aplicável)

**Não se aplica.** A NFC-e é emitida e impressa pelo próprio RazelFood Menu Manager (impressora do caixa), não pelo POS.

| Evidência | Registro |
|---|---|
| Data da transação | — |
| Order_id | — |
| Observação | Não aplicável: a nota fiscal não é impressa no POS. |

---

## Teste 005 — Cancelamento (estorno) da transação

**Objetivo:** estornar a transação do Teste 001 pelo app de cancelamento do POS e receber o webhook `charge.refunded`.

**Execução:**
1. Na maquininha, abrir o app de cancelamento, selecionar a transação de R$ 5,00 e confirmar o estorno.
2. O sistema recebe o webhook `charge.refunded` e desfaz a venda automaticamente: a venda fica cancelada, a NFC-e é cancelada, o valor sai da sessão de caixa e os itens voltam ao estoque.

**Resultado esperado:** evento `charge.refunded` em Financeiro › Webhooks Stone, com o mesmo Charge ID, e venda cancelada.

| Evidência | Registro |
|---|---|
| Data da transação | |
| Charge_id cancelada | |
| Observação | Estorno pelo POS; venda e NFC-e canceladas automaticamente no sistema. |
| Webhook | Tela de Webhooks Stone com o status `charge.refunded` |

---

## Teste 006 — Cancelamento de pedido antes do pagamento

**Objetivo:** criar um novo pedido aberto, não pagar e fechá-lo em seguida.

**Execução:**
1. No PDV, criar uma nova cobrança de R$ 5,00 no Crédito Stone.
2. Sem pagar na maquininha, clicar em **Cancelar cobrança** no PDV.
3. O sistema fecha o pedido na Stone, a cobrança sai da maquininha e o PDV mostra *"Cobrança cancelada."*.

**Resultado esperado:** pedido com status cancelado e Order ID em Financeiro › Pedidos Stone.

| Evidência | Registro |
|---|---|
| Data da transação | |
| Order_id cancelada | |
| Observação | Pedido fechado antes do pagamento pelo botão "Cancelar cobrança" do PDV. |
| Evidência do sistema | Tela de Pedidos Stone com o pedido cancelado |
