# Roteiro — Vídeo de demonstração Stone Connect

- **Aplicação:** RazelFood Menu Manager
- **Duração alvo:** 4 a 5 minutos
- **Formato:** gravação de tela do sistema + câmera (celular) apontada para a maquininha nos momentos indicados com **[CÂMERA]**.

## Preparação (antes de gravar)

- Sessão de caixa **aberta** no caixa que será usado.
- Maquininha Stone cadastrada com número de série e forma de pagamento "Crédito" marcada como integrada Stone.
- Um produto de teste de **R$ 5,00** (ex.: "Água 500 ml").
- Abas abertas no navegador: **PDV (Nova Venda)**, **Financeiro › Pedidos Stone**, **Financeiro › Webhooks Stone**.
- Maquininha ligada, logada e com papel.
- Zoom do navegador em 110–125% para a leitura no vídeo.
- Para a câmera, usar suporte ou pedir a alguém que segure o celular. A tela da maquininha precisa ficar legível.

---

## Cena 1 — Abertura (0:00–0:20)

**Tela:** painel inicial do RazelFood Menu Manager com o logo.

**Narração:**
> "Olá! Este é o RazelFood Menu Manager, sistema de gestão para restaurantes e pizzarias. Neste vídeo vamos demonstrar a integração com a Stone via Connect, na modalidade Pedido Direto: o caixa envia a cobrança do PDV para a maquininha, e o pagamento, o fechamento e o estorno voltam automaticamente para o sistema."

---

## Cena 2 — Configuração (0:20–0:50)

**Tela:** Cadastro de Maquininhas (mostrar a Stone com número de série) → Formas de Pagamento (mostrar "Crédito" com a opção integrada Stone ligada).

**Narração:**
> "A configuração é simples: cadastramos a maquininha Stone pelo número de série e marcamos quais formas de pagamento são integradas. A partir daí, sempre que o operador escolher uma dessas formas no PDV, a cobrança vai direto para a maquininha."

---

## Cena 3 — Teste 001: criação do pedido aberto (0:50–1:40)

**Tela:** PDV.
1. Lançar o produto de R$ 5,00 na venda.
2. Em pagamento, escolher **Crédito** (integrado Stone), selecionar a maquininha e enviar.
3. O modal **"Cobrança na maquininha"** aparece com a mensagem *"Aguardando o pagamento na maquininha…"*.

**[CÂMERA]** Corte para a câmera: a maquininha recebe a cobrança de R$ 5,00, crédito à vista.

**Narração:**
> "Lançamos um item de cinco reais e escolhemos crédito à vista. Ao confirmar, o sistema cria um pedido aberto na Stone, com o pagamento já definido, e envia para a maquininha selecionada. Repare que a cobrança aparece na maquininha com o valor exato, pronta para ser paga."

**Tela:** alternar para **Financeiro › Pedidos Stone** e mostrar a linha com o **Order ID** e o status *aguardando*.

> "Aqui no sistema já temos o registro do pedido com o Order ID gerado pela Stone."

---

## Cena 4 — Teste 002: pagamento (1:40–2:20)

**[CÂMERA]** aproximar e pagar com o cartão de crédito na maquininha. Aguardar a aprovação.

**Tela:** voltar ao PDV. O modal muda sozinho para **"Pagamento confirmado!"**.

**Narração:**
> "Realizamos o pagamento na maquininha. Sem nenhuma ação do operador, o sistema recebe o webhook charge.paid da Stone e confirma o pagamento na tela do caixa."

**Tela:** **Financeiro › Webhooks Stone** → mostrar o evento **charge.paid** com o **Charge ID** e o Order ID.

> "No registro de webhooks fica o evento charge.paid com o Charge ID da transação, que é a nossa trilha de auditoria."

---

## Cena 5 — Teste 003: fechamento do pedido (2:20–2:50)

**Tela:** PDV. Como o valor foi quitado, a venda é **finalizada automaticamente**. Mostrar a venda com status *Finalizada* e a sessão de caixa com o valor.

**[CÂMERA]** maquininha de volta na tela inicial, sem cobrança pendente, e o comprovante impresso.

**Narração:**
> "Com o total recebido, o sistema fecha o pedido na Stone e finaliza a venda no caixa automaticamente. A maquininha volta para a tela inicial, sem transação pendente, e o comprovante é impresso."

> **Teste 004 (NFC-e no POS):** não se aplica. A NFC-e é emitida e impressa pelo próprio RazelFood, não pela maquininha. Se quiser citar no vídeo:
> "A nota fiscal do consumidor é emitida pelo próprio sistema, por isso não é impressa no POS."

---

## Cena 6 — Teste 005: estorno pelo POS (2:50–3:40)

**[CÂMERA]** na maquininha, abrir o **app de cancelamento**, selecionar a transação de R$ 5,00 e confirmar o estorno.

**Tela:** **Financeiro › Webhooks Stone** → mostrar o evento **charge.refunded** com o mesmo **Charge ID**.

**Tela:** abrir a venda → status **Cancelada**, NFC-e em cancelamento/cancelada, valor fora do saldo da sessão de caixa.

**Narração:**
> "Agora fazemos o estorno direto pelo aplicativo de cancelamento da maquininha. A Stone envia o webhook charge.refunded, e o sistema desfaz a venda sozinho: a venda fica cancelada, a nota fiscal é cancelada, o valor sai do caixa e os itens voltam ao estoque. Tudo isso sem o operador precisar refazer nada no sistema."

---

## Cena 7 — Teste 006: cancelamento antes do pagamento (3:40–4:20)

**Tela:** PDV.
1. Nova venda com o mesmo produto, Crédito Stone, enviar para a maquininha.
2. **[CÂMERA]** Mostrar a cobrança na maquininha **sem pagar**.
3. No modal, clicar em **"Cancelar cobrança"**. O modal mostra **"Cobrança cancelada."**.
4. **[CÂMERA]** Mostrar a maquininha sem a cobrança.
5. **Financeiro › Pedidos Stone** → o pedido com o **Order ID** e status *cancelado*.

**Narração:**
> "Por fim, o cancelamento antes do pagamento. Criamos um novo pedido e, antes de o cliente pagar, o operador clica em Cancelar cobrança. O sistema fecha o pedido na Stone, a cobrança sai da maquininha e o registro fica como cancelado, com o Order ID para conferência."

---

## Cena 8 — Encerramento (4:20–4:40)

**Tela:** Pedidos Stone com as linhas do teste (paga/estornada e cancelada) e, se quiser, a página **Ajuda › Manuais** com o PDF de instruções.

**Narração:**
> "Esse é o fluxo completo da integração Stone Connect no RazelFood Menu Manager: criação do pedido, pagamento, fechamento automático, estorno pelo POS e cancelamento antes do pagamento, tudo sincronizado por webhook. Obrigado!"

---

## Checklist de evidências durante a gravação

| Teste | O que precisa aparecer no vídeo |
|---|---|
| 001 | Cobrança de R$ 5,00 na maquininha + Order ID em Pedidos Stone |
| 002 | Pagamento aprovado + webhook `charge.paid` com Charge ID |
| 003 | Maquininha sem transação aberta + comprovante + venda finalizada |
| 004 | Não se aplica (NFC-e emitida pelo sistema) |
| 005 | Estorno no app do POS + webhook `charge.refunded` + venda cancelada |
| 006 | Cobrança cancelada antes do pagamento + Order ID cancelado |

**Dicas de edição:** use cortes secos entre a tela e a câmera e legendas com o número do teste ("Teste 001 — Criação do pedido") no canto superior. Se a narração for gravada depois, grave a tela sem áudio e encaixe os trechos acima.
