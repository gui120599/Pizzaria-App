# Pedido na mesa por autoatendimento (cliente pelo celular)

> Documento de arquitetura / proposta. Objetivo: permitir que o cliente, com o
> próprio celular, faça pedidos para a mesa **sem precisar de garçom** anotando.
>
> Status: **proposta** (Fase 0 concluída). Última atualização: 2026-06-09.

## Objetivo

O cliente escaneia um QR Code na mesa, cai no cardápio já contextualizado naquela
mesa, identifica-se pelo telefone e o pedido entra na sessão da mesa — sem garçom
intermediar.

## O que já existe (e ajuda)

| Peça | Estado atual | Serve para |
|---|---|---|
| `Mesa` | `mesa_nome`, `mesa_status` (LIBERADA/OCUPADA/INATIVA) | Âncora do QR Code |
| `SessaoMesa` | mesa + cliente + **garçom** + status + `clientes()` (N clientes) + `pedidos()` | Já é multi-cliente |
| `SessaoMesaCliente` | pivot sessão↔cliente | Cada pessoa da mesa |
| `Pedido` | `pedido_sessao_mesa_id`, **`pedido_origem`** | Vínculo + origem |
| `item_pedido_cliente_id` | já existe | Atribuir item a quem pediu (divisão de conta) |
| Cardápio público | `/Cardapio` sem auth + `lookupCliente` por telefone | Reaproveitável quase inteiro |

O modelo de mesa **já é multi-cliente** e o pedido **já sabe a qual sessão pertence**.
O grosso do trabalho não é modelagem nova — é abrir um caminho público e seguro até a sessão.

## A ideia central

QR Code por mesa → cardápio "modo mesa". O cliente escaneia, se identifica pelo
telefone (fluxo já existente) e o pedido entra na sessão da mesa.

## Deltas de modelagem

1. **`mesas` → adicionar `mesa_token`** (uuid/slug único, indexado).
   O QR aponta para `/mesa/{token}`, **nunca** para `/mesa/{id}` — senão dá para
   mandar pedido a qualquer mesa adivinhando o id (risco num endpoint público).

2. **`sessao_mesas` → garantir `sessao_mesa_usuario_id` nullable.**
   No autoatendimento a sessão nasce **sem** garçom. Sugestão de marcador:
   `sessao_mesa_autoatendimento` (bool) ou reaproveitar a origem.

3. **`PedidoOrigemEnum` → adicionar `mesa_cliente`.**
   Já temos `mesa` (garçom lançou). Separar `mesa_cliente` (cliente lançou sozinho)
   muda relatório, KPI e a regra de "precisa de confirmação".

4. **Identidade do cliente que volta a pedir.**
   Guardar o `cliente_id` no `localStorage`/cookie assinado do celular, para o 2º/3º
   pedido da mesma pessoa não re-perguntar nome/telefone.

## Fluxo proposto

```
Cliente escaneia QR  →  GET /mesa/{token}  (público)
        │
        ├─ Mesa INATIVA?            → bloqueia
        ├─ Sem sessão ABERTA?       → [decisão A] auto-abre OU pede pra chamar atendente
        └─ Sessão ABERTA            → mostra cardápio + "comanda atual da mesa"
                                          │
   Cliente monta pedido, identifica-se por telefone (lookupCliente)
                                          │
        POST /mesa/{token}/pedido  →  Pedido(origem=mesa_cliente, sessao=X, status=INICIADO)
                                          │
                    [decisão B] confirmação do balcão/cozinha → ABERTO → cozinha
                                          │
        Fechamento/pagamento continua no caixa (staff) — fluxo atual de Venda
```

## Decisões que definem o projeto

### Decisão A — quem abre a sessão?
- **Conservador (recomendado p/ fase 1):** garçom/atendente abre a sessão; cliente só
  **anexa** pedidos. Risco baixíssimo.
- **"Sem garçom" de verdade:** primeiro pedido do cliente **auto-abre** a sessão
  (`usuario_id = null`). Objetivo final, exige guardas (mesa OCUPADA, token, horário).

### Decisão B — pedido self-service vai direto pra cozinha?
- **Com gate humano (recomendado):** entra como `INICIADO` numa fila de confirmação —
  igual o cardápio já faz no `ConfirmacoesPedidos`. Alguém no balcão libera. Sem garçom
  *anotando*, mas com um humano evitando trote/erro.
- **Auto-fire:** vai direto, confiando no token + mesa OCUPADA. Mais ágil, mais arriscado.

### Decisão C — pagamento agora ou depois?
- **Fase 1:** pagamento continua no caixa (não mexe em nada). Recomendado.
- **Futuro:** self-checkout / pagamento online na mesa.

## Segurança (endpoint público afeta operação interna)

- Token por mesa (não-adivinhável) — essencial.
- Só aceitar pedido com mesa OCUPADA (ou auto-abrir só se LIBERADA).
- Checagem de horário (já existe no cardápio) + reCAPTCHA (já existe) + rate-limit.

## Caminho faseado

| Fase | Entrega | Risco |
|---|---|---|
| **0** ✅ | `pedido_origem` (origem rastreável) — *concluído em 2026-06-09* | — |
| **1** | `mesa_token` + QR + cardápio "modo mesa" só-leitura (vê menu + comanda) | baixo |
| **2** | Cliente **anexa** pedido a sessão **aberta por staff** (Decisão A conservador) | baixo |
| **3** | Auto-abrir sessão no 1º pedido do cliente (toggle por config) | médio |
| **4** | Fila de confirmação / KDS pros pedidos self-service (Decisão B) | médio |
| **5** | Pagamento/self-checkout na mesa | alto, futuro |

A fase 2 já entrega ~80% do valor ("cliente pede pelo celular na mesa") com risco
mínimo, porque o staff ainda controla abertura/fechamento da conta.

## Recomendação de início

Fase 1 + Fase 2 com **Decisão A conservadora** e **Decisão B com gate de confirmação**.
Provar o conceito, medir adoção e abuso, e só então liberar o auto-open (fase 3).

## Pontos de código relevantes (referência)

- Cardápio público: `routes/web.php` (`cardapio`, `cardapio.checkout`, `cardapio.lookup_cliente`)
- Checkout que cria pedido: `app/Http/Controllers/CardapioCheckoutController.php`
- Sessão de mesa: `app/Http/Controllers/SessaoMesaController.php`
- Confirmação de pedidos do cardápio: `app/Livewire/ConfirmacoesPedidos.php`
- Origem do pedido: `app/Enums/PedidoOrigemEnum.php`, coluna `pedidos.pedido_origem`
