# Correção: total do pedido não acompanha itens incluídos na edição

> Origem: incidente do pedido #58656 (17/10/2026). Diagnóstico concluído em 18/10/2026 sobre uma cópia local do banco de produção. O código de `producao` (`c96a22a4`) é idêntico ao HEAD nos arquivos envolvidos.
> Status: **escopo aprovado, aguardando implementação.**

## 1. Incidente

O pedido #58656 veio do cardápio: 2× Calabresa Especial, R$ 139,80. Na confirmação por WhatsApp, a atendente incluiu pelo Filament (`AtenderPedido`) mais dois itens: La Fruit Maracujá (R$ 10,00) e Coca-Cola 2L (R$ 16,00). O total esperado era R$ 165,80, mas ficou gravado R$ 139,80. A atendente percebeu e cobrou o valor correto manualmente.

### Estado no banco (fatos)

| Campo | Valor |
|---|---|
| status / origem | `ENTREGUE` / `cardapio` |
| `pedido_valor_itens` / `desconto` / `frete` / `total` | 139,80 / 0 / 0 / **139,80** |
| `pagamentos_pedidos` | **nenhuma linha** (o checkout do cardápio só grava o texto `pedido_descricao_pagamento`) |
| Itens `INSERIDO` | 114991 Calabresa 2×69,90 (00:08:07 UTC) · 115017 La Fruit 10,00 (**00:39:14**) · 115018 Coca 2L 16,00 (**00:39:22**) |
| Soma dos itens | **165,80**, ou seja, R$ 26,00 a mais que o total gravado |

Linha do tempo (UTC, segundo `historico_status_pedidos`): criado 00:08 → confirmado 00:15:06 → PREPARANDO 00:15:07 → **itens incluídos 00:39** → PRONTO 00:40:32 → EM TRANSPORTE 00:44:33 → ENTREGUE 00:45:52.

Os itens foram gravados e o total não acompanhou. Não existe auditoria de colunas, então não dá para saber se o `save()` foi tentado e barrado ou se nunca foi clicado. Nos dois casos a causa raiz é a mesma.

## 2. Causa raiz (comprovada)

**A edição grava em dois momentos.** Em um pedido já gravado, o `PedidoProdutoSelector` persiste cada item **no momento do clique**, por meio do `LancamentoItemPedidoService`, que não altera `pedido_valor_*`. O total só é recalculado em `AtenderPedido::save()`, e esse método tem 7 validações que retornam antes da transação. Se o `save()` não chega até a transação, os itens ficam gravados com o total antigo.

No pedido do cardápio, o `save()` é barrado **por padrão**. Como não existe `pagamentos_pedidos`, o `EntregaPagamentoPicker` abre com uma linha sem forma de pagamento escolhida, e o `save()` para em `AtenderPedido.php:321` ("Escolha ao menos uma forma de pagamento…").

A tela dá a impressão de que está tudo certo: o `resumoTotais` recalcula a partir do carrinho em memória e mostra o valor correto, enquanto o banco continua com o valor antigo.

### Rastreamento

| Etapa | Local | Comportamento |
|---|---|---|
| Abertura | `app/Filament/Pages/AtenderPedido.php:123` `mount()` | Carrega `pagamentosCombinados`, que vem vazio para pedido do cardápio |
| Picker | `app/Livewire/EntregaPagamentoPicker.php:50` | Sem pagamentos → `linhaEmBranco()` |
| Inclusão | `app/Livewire/PedidoProdutoSelector.php:395` `lancar()` → `:439` | `LancamentoItemPedidoService::gravar()` faz o INSERT em transação própria |
| Serviço | `app/Services/LancamentoItemPedidoService.php` `gravar` (:252), `gravarOferta` (:274), `trocarAdicionais` (:318), `atualizarQuantidade` (:349) | Nenhum desses métodos toca o `Pedido` |
| Remoção | `PedidoProdutoSelector.php:863-892` `removerItem()` | Estorna promoções e faz DELETE dentro do próprio componente; o total não muda |
| Aviso ao pai | `PedidoProdutoSelector.php:1015` → `AtenderPedido::onItensAtualizados()` (:242) | Só atualiza a memória e a tela |
| Gravação do total | `AtenderPedido.php:290-406` `save()` | Retornos antecipados (:292-355); depois `DB::transaction` → `TotaisPedido::paraItens` → `update(pedido_valor_*)` (:390-401) |
| Edição não regrava itens | `AtenderPedido.php:492-498` `sincronizarItens()` | Retorna cedo quando o pedido já existe |
| Regra de cálculo | `app/Support/TotaisPedido.php` | Fonte única da regra, correta |
| Observers | `ItemPedidoProducaoObserver`, `PedidoObserver`, `PedidoProducaoObserver` | Cuidam de KDS e estoque; **nenhum** recalcula totais |

Os 4 caminhos que alteram itens sem atualizar o total são: inclusão, alteração de quantidade, remoção e troca de adicionais. O mesmo selector com `pedidoId` também é usado em `AtenderMesa`, `AtenderRetirada`, `ConfirmacoesPedidos` e nas telas Blade legadas. Nessas telas o total só é recalculado no "enviar"/"confirmar".

### Alcance nos dados (banco local)

Desde 09/09/2026, quando o `AtenderPedido` entrou em produção, o #58656 é o **único** pedido de cardápio ou atendente, já confirmado e não cancelado, com o total diferente da soma dos itens. Desde 30/09, 9 pedidos do cardápio receberam itens depois da criação, e todos os outros ficaram consistentes. O fluxo funciona quando o `save()` conclui.

Outras divergências encontradas que **não** foram atribuídas a esta causa:
- 27 rascunhos `atendente`/`INICIADO` com total 0, gerados pelo create legado entre 09/09 e 03/10. Nunca foram cobrados.
- 2 rascunhos de mesa `INICIADO`.
- Pedidos de mesa 55375 e 57588 (`FINALIZADO`), que vêm de outro fluxo e não foram investigados.

### Impacto do total desatualizado

Estes pontos leem `pedido_valor_*` gravado:
- **Cobrança Stone do entregador** (`app/Livewire/PainelEntregador.php:115`)
- PDF/impressão (`pedidoPDF`, `pedidosEntregasPDF`)
- Página de acompanhamento do cliente
- Card do PDV (`operar-venda.blade.php:223`)
- `ComissaoService` / `ComissaoStatsOverview`
- `DebitosClienteService:171`
- `CupomUtilizadoNotification`
- Somas de mesa (`MapaMesasService`, `SessoesMesaTable`)

O `VendaService` calcula os valores a partir dos itens, mas soma `pedido_valor_frete`. Esse frete fica desatualizado se a inclusão de itens ultrapassar o mínimo de frete grátis.

### Achado secundário (comprovado na leitura do código, não reproduzido)

`resources/views/filament/pages/painel-pedidos/detalhes-modal.blade.php:13` passa `pedido_valor_desconto` como `descontoPedido` para `TotaisPedido::paraItens`. Esse campo já inclui o desconto dos itens, então o modal subtrai duas vezes o desconto de itens promocionais.

## 3. Decisões aprovadas

1. **Recalcular na camada de serviço**, dentro da mesma transação de toda alteração de item, para cobrir todos os fluxos que usam o `LancamentoItemPedidoService`, e não só o `AtenderPedido`.
2. **Pagamento combinado:** se o pedido tiver **exatamente 1** linha em `pagamentos_pedidos` com `pg_pedido_pago = false`, o valor dela acompanha o novo total. Com várias linhas, ou com alguma linha paga, nada muda e a tela mostra um aviso de divergência.
3. **Dados de produção:** criar um comando de auditoria que por padrão só lista (dry-run) e só corrige com `--corrigir` e IDs explícitos.
4. **Manter as colunas `pedido_valor_*` persistidas.** Elas são o registro comercial do pedido e são lidas por cerca de 40 pontos. Não remover nem passar a derivar.

### Invariante

Para todo pedido com `pedido_status ≠ INICIADO`:
`pedido_valor_{itens,desconto,frete,total}` = `TotaisPedido::paraItens(itens INSERIDO, opcaoEntrega, descontoPedido)`, gravado **na mesma transação** que alterou os itens.

Pedidos `INICIADO` ficam de fora: são rascunhos (checkout em andamento, rodada do garçom, legado) que já recebem o total no envio ou na confirmação. Além disso, `SessoesMesaTable` soma o total sem filtrar status, então recalcular rascunhos mudaria valores exibidos hoje.

## 4. Escopo da implementação

### P0: correção da causa raiz (obrigatório)

1. **Criar `app/Services/TotaisPedidoService.php`** com `vendor/bin/sail artisan make:class`. O método é `recalcular(Pedido|int $pedido): array{itens: float, desconto: float, frete: float, total: float}`:
   - Começa com `Pedido::whereKey(...)->lockForUpdate()->firstOrFail()`, no mesmo padrão de `app/Services/PedidoStatusService.php:133`.
   - Se o status for `INICIADO`, devolve os totais atuais sem gravar nada.
   - Senão, relê os itens `INSERIDO`, carrega `opcaoEntrega` e chama `TotaisPedido::paraItens($itens, $opcao, $descontoPedido)`.
   - `$descontoPedido` = `pedido_valor_desconto_cupom` se a coluna existir, senão 0. Ver a dependência na seção 7.
   - Só grava se algum valor mudou.
   - Ajusta o pagamento combinado conforme a decisão 2.
   - Não reprecifica nada: os preços de cada linha já estão congelados no item.
2. **`app/Services/LancamentoItemPedidoService.php`:** chamar `recalcular()` dentro das transações que já existem em `gravar`, `gravarOferta`, `trocarAdicionais` e `atualizarQuantidade`. O lock do pedido deve ser obtido **no início** da transação, antes de gravar o item, para manter a ordem pedido → itens.
3. **Mover a remoção para o serviço:** criar `LancamentoItemPedidoService::remover(ItensPedido $item): array` (devolve os ids removidos) com o corpo atual de `PedidoProdutoSelector.php:871-892` (estornos de promoção, cascata da oferta e DELETE), mais o `recalcular()`. O `PedidoProdutoSelector::removerItem()` passa apenas a chamar o serviço.
4. **`AtenderPedido::save()`:** trocar o bloco das linhas 390-401 por `app(TotaisPedidoService::class)->recalcular($pedido)`. O fluxo de criação não muda de comportamento.

### P1: complementos (mesmo PR)

5. **Aviso de divergência no `AtenderPedido`.** Em edição, quando a soma de `pagamentosCombinados` for diferente de `pedido_valor_total`, mostrar um aviso fixo: "Forma de pagamento combinada não bate com o total — revise e salve". Arquivos: `AtenderPedido.php` e `resources/views/filament/pages/atender-pedido.blade.php`.
6. **Modal de detalhes do Painel** (`detalhes-modal.blade.php:13`): exibir os valores **gravados**, que passam a ser consistentes depois do P0, em vez de recalcular com o `descontoPedido` errado. Isso também elimina o desconto subtraído duas vezes.
7. **Comando `pedidos:auditar-totais`**, criado com `make:command`, com as opções `{--desde=} {--ids=*} {--corrigir}`:
   - Por padrão (dry-run) lista id, origem, status, valor gravado, valor calculado e a diferença.
   - A comparação usa o **bruto dos itens**, `soma(item_pedido_valor + item_pedido_desconto)` dos itens `INSERIDO` contra `pedido_valor_itens`. Esse valor não depende de frete, cuja configuração pode ter mudado desde o pedido.
   - Considera apenas status diferente de `INICIADO`.
   - `--corrigir` exige `--ids`. Preserva o frete e o desconto do pedido que estão gravados e recalcula itens, desconto dos itens e total, em transação, com `Log::info` registrando o antes e o depois.

### Fora do escopo (só com nova aprovação)

- Trocar os `update(pedido_valor_*)` duplicados de `ConfirmacoesPedidos`, `RetiradaService`, `AtendimentoMesaService` e `PedidoMesaClienteService` pelo serviço novo.
- Fazer o checkout do cardápio criar `pagamentos_pedidos`. Isso afeta `PagamentosDoPedidoNaVenda` e a sugestão de pagamento do PDV.
- Investigar os pedidos de mesa 55375 e 57588.
- Corrigir divergências anteriores a 09/09/2026.

## 5. Testes

Usar PHPUnit com `Livewire::test`, seguindo `tests/Feature/AtenderPedidoEdicaoTest.php` e `tests/Feature/PedidoProdutoSelectorLancamentoTest.php`. Rodar em banco isolado, por exemplo `vendor/bin/sail exec -u sail -e DB_DATABASE=testing_totais php artisan test --compact ...`.

### `tests/Feature/TotaisPedidoServiceTest.php` (novo)
- Recalcula itens, desconto, frete e total a partir dos itens `INSERIDO` e ignora os `REMOVIDO`.
- Cobra frete abaixo do mínimo e zera o frete quando o total passa do mínimo.
- Pedido `INICIADO` não é alterado.
- Ajusta 1 pagamento combinado não pago. Não altera nada quando há 2 linhas ou quando a linha está paga.
- Não executa UPDATE quando nada mudou.

### `tests/Feature/AtenderPedidoEdicaoTotaisTest.php` (novo)
- **Cenário principal:** pedido de cardápio de R$ 139,80 (2× 69,90, sem `pagamentos_pedidos`) aberto no `AtenderPedido`. Incluir suco R$ 10,00 e refrigerante R$ 16,00 pelo selector, **sem chamar `save`**. Depois de `$pedido->refresh()`, esperar `pedido_valor_total = 165.80` e 3 itens.
- O mesmo cenário com um `save` que **falha** por falta de forma de pagamento: itens e total continuam em 165,80.
- Vários produtos incluídos na mesma edição.
- Incrementar e decrementar quantidade atualiza o total. Item promocional continua sem permitir mudança de quantidade (regra atual).
- Remover item atualiza o total, inclusive quando o item é gatilho de oferta e a oferta sai junto em cascata.
- Trocar adicionais atualiza o total.
- Item com desconto promocional: o total é o valor líquido, sem desconto em dobro.
- Edição seguida de um `save` completo mantém o comportamento atual de status e pagamentos.
- **Falha no meio da operação:** forçar uma exceção em `recalcular()` faz rollback do INSERT do item, sem deixar item órfão nem total parcial.

### Comando
- O dry-run não altera nada.
- `--corrigir --ids=` corrige apenas os ids informados e preserva o frete.

### Regressão (suítes existentes)
`CardapioCheckout*`, `PedidoProdutoSelector*`, `AtenderPedido*`, `ConfirmacoesPedidosTotaisTest`, `RetiradaGarcomTest`, `PainelGarcomTest`, `PedidoMesaClienteServiceTest`, `PainelEntregadorStoneCobrancaTest`, `PainelPedidos*`, `PedidoObserverEstoqueTest`, `PedidoStatusServiceTest`, `TotaisPedidoTest`, `PedidoPDFQrCodeTest`.

## 6. Critérios de aceitação

- Incluir, alterar ou remover um item em pedido confirmado deixa `pedido_valor_*` igual a `TotaisPedido` dos itens **na mesma requisição**, sem depender do `save()`.
- O cenário 139,80 + 10,00 + 16,00 grava **165,80**, e o valor se mantém depois de recarregar o pedido.
- Uma falha durante a operação não deixa item gravado sem o total correspondente.
- Checkout do cardápio e rascunhos `INICIADO` sem mudança de comportamento.
- A cobrança Stone do entregador e o PDF usam o total correto.
- O modal do Painel mostra o mesmo total que está gravado no banco.
- As suítes de regressão passam e `vendor/bin/sail bin pint --dirty --format agent` foi aplicado.

## 7. Riscos e cuidados

- **Lock de linha a cada clique de item:** o lock é curto e segue a mesma ordem pedido → itens do `PedidoStatusService`, o que evita deadlock e serializa duas abas editando o mesmo pedido.
- **Frete recalculado pela configuração atual da opção de entrega:** é o mesmo comportamento que o `save()` já tem hoje.
- **Pagamento em dinheiro com `troco_para` menor que o novo total:** continua precisando de revisão manual; o aviso do item 5 cobre esse caso.
- **Dependência do cupom:** o working tree tem a feature de cupom/influenciador ainda **não commitada**, com a coluna `pedido_valor_desconto_cupom`. Hoje o `save()` ignora esse desconto e o apagaria ao editar um pedido. O serviço precisa respeitar a coluna quando ela existir, e o teste do cupom deve entrar junto com aquela feature.
- **Working tree sujo:** `EntregaPagamentoPicker`, `CardapioCheckoutController`, `Pedido` e `PedidoObserver` têm alterações de outra sessão. Commitar apenas os arquivos desta correção, listando cada um por nome (nunca `git add -A`).
- **Deploy:** não há migration. Produção não tem npm/composer e não recarrega o OPcache sozinha. Seguir `scripts/promote-producao.sh`.

## 8. Verificação em produção (depois do deploy)

1. Rodar `php artisan pedidos:auditar-totais --desde=2026-09-09` (dry-run) e revisar a lista.
2. Corrigir o #58656 com `--corrigir --ids=58656`. O cliente já pagou R$ 165,80, e a correção alinha relatórios e comissão.
3. Analisar os demais casos individualmente antes de corrigir.
4. Repetir o dry-run periodicamente como alarme de regressão.

## Arquivos

- **Novos:** `app/Services/TotaisPedidoService.php`, `app/Console/Commands/AuditarTotaisPedidos.php`, `tests/Feature/TotaisPedidoServiceTest.php`, `tests/Feature/AtenderPedidoEdicaoTotaisTest.php`
- **Alterados:** `app/Services/LancamentoItemPedidoService.php`, `app/Livewire/PedidoProdutoSelector.php` (`removerItem`), `app/Filament/Pages/AtenderPedido.php` (`save`, aviso), `resources/views/filament/pages/atender-pedido.blade.php`, `resources/views/filament/pages/painel-pedidos/detalhes-modal.blade.php`
- **Reutilizar:** `App\Support\TotaisPedido::paraItens`, o padrão de `lockForUpdate` de `App\Services\PedidoStatusService`, o formato de linhas de `App\Services\PagamentosCombinadosService`
