# Programa de Influenciadores: correções, carteira e ida para produção

> Origem: avaliação do painel `/influenciadores` e das telas de admin relacionadas, feita em 24/10/2026. A frente está **inteira sem commit** no working tree (desde 17/09/2026). As 16 migrations `2026_09_17_*` **nunca rodaram**, nem no banco local. Os 50 testes da frente passam num banco isolado, mas não cobrem os problemas abaixo.
> Status: **decisões do usuário registradas em 24/10/2026 (seção 2). Plano aguardando implementação.**
> Execução: por fases (seção 5). **Cada fase é uma entrega independente**, com testes e commit próprios. Não comece a fase seguinte sem que a anterior esteja verde. O go-live exige as Fases 0, 1 e 2 e os itens **[obrigatório]** da Fase 3.

---

## 1. Diagnóstico (fatos verificados no código)

### O que existe e funciona (manter)

| Peça | Onde | Observação |
|---|---|---|
| Painel do influenciador | `app/Providers/Filament/InfluenciadorPanelProvider.php` (`/influenciadores`, guard `influenciador`) | Autocadastro com limite de tentativas; nasce `PENDENTE`; `canAccessPanel()` exige `ATIVO`; página pública "aguardando aprovação" |
| Isolamento | `getEloquentQuery()` dos 4 Resources em `app/Filament/Influenciador/Resources/` | Tudo filtrado pelo influenciador logado; nenhum dado de cliente exposto |
| Cupom | `app/Services/CupomService.php` | `validar()` antes da transação; `consumir()` com `lockForUpdate` dentro dela; uso contado em `pedidosValidos()` |
| Comissão | `app/Services/ComissaoService.php` | Idempotente (`firstOrCreate`); aprovada quando o pedido recebe `pedido_venda_id` (`PedidoObserver` e `VendaObserver::finalizarPedidosEMesas`) |
| Admin | `app/Filament/Resources/{Influenciadores,Comissoes,PagamentosComissao,MateriaisDivulgacao}` | Aprovar e inativar; gerar pagamento em lote |
| Kit de divulgação | `app/Filament/Influenciador/Pages/KitDivulgacao.php` | Materiais + legenda para copiar |
| Testes | 12 arquivos, 50 testes (lista na seção Arquivos) | Todos verdes em 24/10/2026 |

### Problemas encontrados

| # | Problema | Evidência |
|---|---|---|
| B1 | O cardápio não tem campo de cupom | `codigo_cupom` só existe em `CardapioCheckoutController`; nada em `resources/views/cardapio.blade.php` |
| B2 | O desconto do cupom fica só no total do pedido e **não chega à venda, ao caixa nem à NFC-e**. Edições do pedido apagam o desconto. | `VendaService.php:45-77` soma `item_venda_valor`; `item_venda_desconto` copia `item_pedido_desconto` (`LancamentoItensVendaService.php:131`); `TotaisPedido::paraItens()` sem cupom em `AtenderPedido.php:461`, `ConfirmacoesPedidos.php:123`, `RetiradaService.php:98,196`; a Stone cobra `pedido_valor_total` (com desconto), mas a venda espera o valor cheio |
| B3 | O influenciador cria cupom sem teto (percentual, valor fixo, usos), válido na hora, e pode alterar o cupom depois de usado | `Cupoms/Schemas/CupomForm.php` |
| B4 | Cupom de influenciador inativado ou excluído continua valendo | `CupomService::checarRegras()` não olha o dono |
| B5 | Comissões, Pagamentos de Comissão e Materiais não têm Policy, então qualquer usuário do `/admin` gera pagamento. O `ForceDelete` do influenciador apaga comissões e pagamentos em cascata. A `CupomPolicy` só aceita `Influenciador` (quebra para `User`). | `app/Policies/`; `InfluenciadorResource.php:233,239` |
| B6 | `cancelarEntregue` (que usa `updateQuietly`) não cancela a comissão. O estorno de venda não reverte comissão aprovada. A base inclui frete e fica congelada na criação. O cupom de crédito gera comissão sobre si mesmo. "Gerar pagamento" não tem trava. | `PedidoStatusService.php:188`; `CancelamentoVendaService`; `ComissaoService::criarSeAplicavel`; `PagamentoComissaoService` |
| B7 | Pagamento de comissão fora do Financeiro; comprovante no disco `public` | `GerarPagamentoBulkAction` |
| B8 | WhatsApp síncrono, sem timeout, chamado no checkout e dentro de transação | `EvolutionApiService`, notificações sem fila |
| B9 | Frente misturada no working tree com outras: `Pedido.php` (F2 de pedidos), `config/app.php` reescrito, `laravel/boost` no composer e no `vendor`, `.mcp.json`, `boost.json`, `.claude/` | `git status` |
| B10 | Funcionalidades só esboçadas: Campanhas, Níveis, link rastreável (slug, cliques, `pedido_influenciador_ref_id`) | Migrations e models sem rota, tela nem regra |

---

## 2. Decisões aprovadas (24/10/2026)

| # | Decisão |
|---|---|
| D1 | O influenciador **cria os próprios cupons dentro de um teto** definido pelo Admin |
| D2 | Base da comissão = **valor líquido dos itens** (sem frete, depois dos descontos, inclusive o do cupom), apurada **quando a venda é paga** |
| D3 | Cupom informado pelo cliente **ao colaborador** (pedido por WhatsApp ou telefone) também vale e gera comissão |
| D4 | O pagamento de comissão é **despesa de marketing** no Financeiro: plano de despesas "Marketing › Comissões de influenciadores" |
| D5 | **Carteira do influenciador**: as comissões acumulam como saldo. O influenciador pode resgatar em Pix ou **consumir pizzas**, e o valor do pedido é debitado da carteira. **Só o próprio influenciador** usa o saldo. O cupom "CREDITO-…" gerado por pagamento deixa de existir. |
| D6 | **Campanhas e Níveis ficam para a próxima fase** |
| D7 | **WhatsApp (Evolution API) fica para o futuro.** Notificações vão para o próprio painel. |
| D8 | O regulamento do programa é **texto interno** cadastrado pelo Admin, com aceite registrado |

### Escolhas de desenho deste plano (sem decisão pendente)

- **Link rastreável:** `/r/{slug}` registra o clique e abre o cardápio com o **cupom principal** do influenciador já preenchido. A comissão continua exigindo cupom aplicado; a referência do link serve só para métricas (cliques → pedidos). Assim não surge uma segunda regra de atribuição.
- **Prova de identidade no consumo em pizza:** **código de resgate** de 6 dígitos gerado no painel do influenciador (uso único, 15 minutos), digitado pelo caixa na forma de pagamento "Carteira de influenciador".
- **Teto de cupom:** campos de configuração no singleton `Empresa`, no mesmo padrão de `ConfiguracaoPedidos`/`ConfiguracaoSefaz`.

---

## 3. Invariantes

1. **O desconto do cupom vive nos itens.** Ele é distribuído entre os itens do pedido em centavos, então venda, NFC-e, caixa, Stone e recálculos carregam o desconto sem código extra. `pedido_valor_desconto_cupom` passa a ser **informativo** (soma das partes nos itens) e nenhum cálculo subtrai esse campo de novo.
2. **A carteira é um extrato append-only.** Saldo = soma dos movimentos. Nenhum movimento é editado ou apagado; correção é sempre um movimento inverso. Todo débito trava a linha do influenciador (`lockForUpdate`) e confere o saldo.
3. **Comissão nunca é criada nem creditada sobre valor pago com a própria carteira** e nunca sobre o frete.
4. Mudança de status de pedido passa pelo `PedidoStatusService`. Onde houver `updateQuietly` documentado, os ganchos de comissão são chamados explicitamente.
5. Arquivo com dado financeiro ou pessoal (comprovante) **nunca** vai para o disco `public`.
6. Nada de exclusão física de influenciador, cupom, comissão, movimento de carteira ou pagamento.
7. As regras ficam em services, sem lógica de negócio em Resource ou Livewire. Os services não dependem de HTTP (reaproveitáveis por um futuro agente de WhatsApp).

---

## 4. Modelo de dados alvo

> **As migrations `2026_09_17_*` nunca rodaram.** Antes de mexer, **confirme na produção** (`select migration from migrations where migration like '2026_09_17%'`) e em qualquer outro ambiente que elas não existem. Confirmado isso, **edite e consolide as migrations não commitadas** em vez de empilhar `alter` sobre elas. Se alguma já tiver rodado em algum lugar, pare e reporte.

| Tabela | Alvo |
|---|---|
| `influenciadores` | Como hoje. **Remover** `influenciador_nivel_id`, `influenciador_nivel_fixo` e `influenciador_forma_pagamento_comissao` (a carteira substitui a forma fixa). **Adicionar:** `influenciador_cpf` (criptografado, mesmo padrão de `Colaborador`: cast `encrypted` + `cpf_hash`), `influenciador_prestador_id` (FK `prestadores`, `nullOnDelete`, favorecido no Financeiro), `influenciador_cupom_principal_id` (FK `cupons`, `nullOnDelete`), `influenciador_regulamento_versao` + `influenciador_regulamento_aceito_em` |
| `cupons` | Como hoje (`cupom_origem`: `interno`, `influenciador`). **Remover** o uso de `credito_comissao` do enum `OrigemCupomEnum`. **Adicionar** `cupom_codigo` normalizado (maiúsculas, sem espaços) no `saving`. Trava: tipo e valor imutáveis depois do primeiro uso válido. |
| `pedidos` | `pedido_cupom_id`, `pedido_valor_desconto_cupom` (informativo), `pedido_influenciador_ref_id` (do link) |
| `itens_pedidos` | **Novo** `item_pedido_desconto_cupom` decimal(10,2) default 0: a parte do cupom na linha |
| `comissoes` | Como hoje, **sem** `comissao_campanha_id` e **sem** `comissao_pgcom_id`. Estados (`StatusComissaoEnum`): `PENDENTE` → `CREDITADA` (rótulo "Creditada na carteira") → `ESTORNADA`; ou `PENDENTE` → `CANCELADA`. `comissao_base_calculo` e `comissao_valor` são **recalculados na hora do crédito**. Mantém `comissao_pedido_id` unique e nullable. |
| `carteira_movimentos` (nova) | `id`, `cm_influenciador_id`, `cm_tipo` (enum `TipoMovimentoCarteiraEnum`: `CREDITO_COMISSAO`, `ESTORNO_COMISSAO`, `RESGATE_PIX`, `CONSUMO`, `ESTORNO_CONSUMO`, `AJUSTE`), `cm_valor` (com sinal: crédito positivo, débito negativo), `cm_comissao_id`, `cm_resgate_id`, `cm_pagamento_venda_id`, `cm_user_id`, `cm_observacao`, `created_at`. Índice `(cm_influenciador_id, created_at)` com nome explícito. |
| `resgates_carteira` (renomeia `pagamentos_comissao`) | `rc_influenciador_id`, `rc_valor`, `rc_lancamento_id` (FK `lancamentos`), `rc_comprovante_path` (disco **local**), `rc_user_id`, `rc_pago_em`. Model `ResgateCarteira`; `PagamentoComissao` deixa de existir. |
| `codigos_resgate` (nova) | `cr_influenciador_id`, `cr_codigo_hash`, `cr_expira_em`, `cr_usado_em`, `cr_pagamento_venda_id` |
| `regulamentos_influenciador` (nova) | `ri_versao` (inteiro), `ri_texto` (longtext), `ri_publicado_em`, `ri_user_id` |
| `cliques_referencia` | Como hoje |
| `materiais_divulgacao` | Como hoje |
| `empresas` | **Adicionar:** `empresa_influ_teto_percentual` (decimal 5,2), `empresa_influ_teto_valor_fixo` (decimal 10,2), `empresa_influ_teto_usos_total` (nullable), `empresa_influ_comissao_tipo` + `empresa_influ_comissao_valor` (padrão aplicado na aprovação), `empresa_influ_plano_despesa_id`, `empresa_influ_conta_pix_id` |
| `opcoes_pagamentos` | **Adicionar** `opcaopag_carteira_influenciador` boolean default false |
| **Sai do release (D6)** | `campanhas`, `campanha_influenciador`, `niveis_influenciador` + models `Campanha`, `CampanhaInfluenciador`, `NivelInfluenciador` + `NivelInfluenciadorSeeder` (e a linha no `DatabaseSeeder`) + `add_campanha_to_comissoes`. Guardar o desenho num anexo deste plano (seção 9) antes de apagar. |

---

## 5. Fases

### Fase 0: saneamento e base segura

**F0.1 Escopo do release (B9, B10, D6, D7)**
- Remover do release o que é da próxima fase: Campanhas, Níveis (seção 4) e WhatsApp (`EvolutionApiService`, `Notifications/Channels/WhatsappChannel.php`, `EvolutionApiServiceTest`, bloco `evolution` em `config/services.php`, `routeNotificationForWhatsapp`). Registrar na seção 9 o que saiu, para retomar depois.
- Consolidar as migrations `2026_09_17_*` no modelo da seção 4 (ver o aviso da seção 4).
- **Não** incluir no commit: `laravel/boost` (composer e `vendor`), `.mcp.json`, `boost.json`, `.claude/`, a reescrita cosmética de `config/app.php` (manter só o registro do `InfluenciadorPanelProvider`), `docs/planos/evolucao-modulo-pedidos.md` e nada da F2 de pedidos. `app/Models/Pedido.php` está sujo com as duas frentes: monte o blob a partir do HEAD com só o diff desta frente e confira com `php -l`. Não use `git apply --unidiff-zero` (problema conhecido).

**F0.2 Autorização (B5)**
- Policies novas: `ComissaoPolicy`, `ResgateCarteiraPolicy`, `MaterialDivulgacaoPolicy`, e `CupomPolicy` refeita para os dois guards (no padrão de `InfluenciadorPolicy::ehStaffAutorizado()`: `User` com permissão, `Influenciador` só sobre os próprios cupons).
- Permissões no `PermissionSeeder`: `view_any`, `view`, `create`, `update` e `delete` para `influenciador`, `cupom`, `comissao` e `material_divulgacao`; `view_any`/`create` em `resgate_carteira`; `ajustar:carteira_influenciador`. Todas para **Admin e Gerente**.
- `InfluenciadorResource`: remover `ForceDeleteAction` e `ForceDeleteBulkAction`. Bloquear exclusão com saldo diferente de zero ou comissão pendente.
- Testes: Garçom, Atendente e Caixa recebem 403 nos 4 Resources; Gerente acessa; não existe force delete; `CupomPolicy` funciona para `User` e para `Influenciador`.

### Fase 1: cupom funcionando ponta a ponta

**F1.1 Desconto distribuído nos itens (B2, invariante 1)**
- Service novo `app/Services/CupomPedidoService.php`:
  - `aplicar(Pedido $pedido, Cupom $cupom): void` e `remover(Pedido $pedido): void`.
  - `reaplicar(Pedido $pedido): void` é idempotente: com o pedido travado, recalcula o desconto sobre a soma líquida dos itens `INSERIDO`, fora cortesia (percentual, ou fixo limitado à soma), e **distribui** com `App\Support\RateioCentavos::ratearProporcional()` pelo valor de cada item.
  - Grava `item_pedido_desconto_cupom`, recalcula `item_pedido_valor` e atualiza `pedido_valor_desconto_cupom`.
  - **Itens já vinculados a venda (`item_pedido_venda_id`) ficam congelados:** a redistribuição só usa os itens livres. Se o pedido já tiver venda, o cupom não pode ser trocado nem removido.
- `ItensPedido::recalcularValores()`: o valor da linha passa a descontar `item_pedido_desconto_cupom` (fora cortesia, que continua com valor zero).
- `TotaisPedido::paraItens()`: o desconto inclui a soma de `item_pedido_desconto_cupom`, preservando `bruto = líquido + desconto`. **Remova** a subtração de `pedido_valor_desconto_cupom` em `TotaisPedidoService::recalcular()` e o parâmetro `$descontoPedido`, se não sobrar uso. Ajuste `AuditarTotaisPedidos` (linha 84).
- `TotaisPedidoService::recalcular()` chama `CupomPedidoService::reaplicar()` antes de somar (mesma transação e mesmo lock). Isso cobre todas as alterações de item que já passam por ele (`LancamentoItemPedidoService`).
- Passagem para a venda: em todo ponto que copia `item_pedido_desconto` para `item_venda_desconto` (`LancamentoItensVendaService.php:131`, `ItensVendaController.php:130,887,1018`), copiar **`item_pedido_desconto + item_pedido_desconto_cupom`**. Confira no `NfeIoService` que `item_venda_desconto` vira o desconto do item (`vDesc`) na NFC-e.
- `CardapioCheckoutController`: em vez de subtrair o cupom do total, chama `CupomPedidoService::aplicar()` depois de gravar os itens, ainda dentro da transação, e o total sai do `TotaisPedidoService`.
- **Regra do frete mínimo:** continua sendo avaliada sobre o líquido já com o desconto do cupom (comportamento atual de `paraItens`). Registre isso no texto de ajuda.
- Testes (`tests/Feature/CupomPedidoServiceTest.php`, `CardapioCheckoutCupomTest` ampliado):
  - distribuição em centavos fecha com o total (percentual e fixo; 3 itens com valores "quebrados");
  - cortesia fica fora da distribuição;
  - incluir, remover ou alterar item no `AtenderPedido` mantém o percentual correto;
  - `ConfirmacoesPedidos` e `RetiradaService` não perdem o desconto;
  - venda gerada a partir do pedido tem o total igual ao do pedido;
  - `item_venda_desconto` inclui a parte do cupom;
  - cobrança da Stone e total da venda coincidem;
  - item já vendido não é redistribuído.

**F1.2 Cupom no cardápio (B1)**
- Rota `POST /cardapio/cupom/validar` (`throttle` próprio, por exemplo 10 por minuto por IP) que devolve o desconto calculado ou uma mensagem **genérica** de cupom inválido (sem dizer se o código existe). Aplicar throttle também em `cardapio.checkout`.
- `cardapio.blade.php` (Alpine): campo "Cupom" com "Aplicar"/"Remover", linha de desconto no resumo e envio de `codigo_cupom` no checkout. Pré-preencher pelo parâmetro `?cupom=` e pelo cookie do link (F1.4).
- Rodar `vendor/bin/sail npm run build` e versionar `public/build/`.
- Testes: validação com throttle, mensagem genérica, pré-preenchimento pela query.

**F1.3 Teto e travas (B3, B4, D1)**
- Página Admin `app/Filament/Pages/ConfiguracaoInfluenciadores.php` (grupo `Configurações`, só Admin): tetos (percentual, valor fixo, usos totais), comissão padrão (tipo e valor), plano de despesa de marketing, conta financeira do Pix.
- `CupomForm` do influenciador: `maxValue` dinâmico pelo teto conforme o tipo; limite de usos ≤ teto (quando configurado); código normalizado. Tipo e valor ficam **somente leitura** depois do primeiro uso válido. A regra é repetida no `CupomService` (servidor), não só no formulário.
- `CupomService::checarRegras()`: cupom de influenciador só vale se o dono estiver `ATIVO` e não excluído.
- Cupom `interno` (criado pelo Admin) não tem teto.
- Testes: influenciador não grava acima do teto (formulário e service); cupom de influenciador inativo é recusado no checkout; alterar o valor depois do uso é recusado.

**F1.4 Link rastreável (B10)**
- Rota pública `GET /r/{influenciador:influenciador_slug}` com throttle: registra `CliqueReferencia` (IP com hash + user agent), grava o cookie `ref_influenciador` (30 dias) e redireciona para o cardápio com `?cupom=` = cupom principal (se ativo).
- No checkout, preencher `pedido_influenciador_ref_id` a partir do cookie (só métrica; a comissão exige cupom).
- Influenciador escolhe o **cupom principal** em "Meus Cupons".
- Testes: clique registrado; redirecionamento com cupom; influenciador inativo → cardápio sem cupom; referência gravada no pedido.

**F1.5 Admin de cupons**
- Resource `app/Filament/Resources/Cupons/` (grupo `Pessoas`, no padrão de pastas do CLAUDE.md): lista todos os cupons, com filtros por origem, influenciador, ativo e vigência; colunas de usos e usos restantes. Ações: criar cupom interno, editar, ativar/desativar.
- `InfluenciadorResource`: RelationManagers **Cupons**, **Comissões** e **Carteira** (extrato, Fase 2).
- Na aprovação, aplicar a comissão padrão da configuração se a do influenciador estiver zerada, e **exigir** comissão maior que zero.

**F1.6 Cupom no atendimento (D3)**
- `AtenderPedido`: campo "Cupom" (aplicar/remover) que usa `CupomService::validar()` com o cliente do pedido e `CupomPedidoService`. Vale para pedido novo e em edição até o pagamento.
- Fora do escopo desta fase (registrar na seção 9): Painel do Garçom, mesa e venda direta no PDV sem pedido.
- Testes: aplicar no `AtenderPedido` gera a comissão pendente; trocar de cupom recalcula; pedido pago não aceita troca.

### Fase 2: comissão e carteira (D2, D4, D5)

**F2.0 Nota de contabilização (pré-requisito, com OK do usuário)**
Antes de codificar, leia `docs/planos/contabilizacao-vendas-contas-financeiras.md` e o `ContabilizacaoFechamentoService`, e registre na seção 8 deste plano, em até uma página, como cada evento aparece no Financeiro. **Pare e peça OK** antes de implementar a F2.4. O alvo:
- **Resgate em Pix:** `Lancamento` de despesa (plano "Marketing › Comissões de influenciadores", favorecido = `Prestador` do influenciador) **pago** a partir da conta configurada. A movimentação nasce da baixa (`LancamentoPagamento` → `BaixaFinanceira`).
- **Consumo em pizza:** a venda é paga com a forma "Carteira de influenciador". O resultado tem de ser **receita da venda + despesa de marketing de mesmo valor, sem efeito no caixa**. Proponha o mecanismo (por exemplo, uma conta financeira de compensação "Carteira de influenciadores" ligada à forma, mais uma despesa paga a partir dela), respeitando as validações de saldo de `MovimentacaoService`.
- Informe que pagamento a pessoa física pode exigir recibo ou RPA e retenções. **Validar com o contador**; o sistema só precisa permitir anexar o comprovante e emitir um recibo simples.

**F2.1 Ciclo da comissão (B6)**
- `ComissaoService`:
  - `criarSeAplicavel(Pedido)`: comissão `PENDENTE` com valor **estimado** (exibição).
  - `creditar(Pedido)`: na venda paga, **recalcula** a base como a soma de `item_pedido_valor` dos itens `INSERIDO` do pedido, menos a parte paga com carteira; sem frete. Calcula o valor e grava `CREDITADA` + movimento `CREDITO_COMISSAO`, numa transação só.
  - `cancelar(Pedido)`: se `PENDENTE`, vira `CANCELADA`; se `CREDITADA`, vira `ESTORNADA` + movimento `ESTORNO_COMISSAO`.
- Ganchos explícitos onde não há observer: `PedidoStatusService::cancelarEntregue()` (`updateQuietly`) e `CancelamentoVendaService::cancelarFinalizada()` (estorna a comissão creditada). Os ganchos que já existem em `PedidoObserver` e `VendaObserver` passam a chamar `creditar`.
- Nenhuma comissão quando o cupom é `interno` ou quando o pedido foi pago inteiro com a carteira do próprio dono do cupom.
- Estorno pode deixar o saldo negativo. Saldo negativo bloqueia resgate e consumo.
- Testes: base sem frete; base acompanha edição feita antes do pagamento; `cancelarEntregue` cancela; estorno de venda gera `ESTORNO_COMISSAO`; idempotência (creditar duas vezes = um movimento).

**F2.2 Carteira**
- Model `CarteiraMovimento` (sem `updated_at`, sem edição) + `app/Services/CarteiraInfluenciadorService.php` com `saldo()`, `creditar()`, `debitar()` (trava + confere saldo) e `ajustar()` (permissão `ajustar:carteira_influenciador`, observação obrigatória).
- Painel do influenciador: página **Minha Carteira** (saldo + extrato paginado), que substitui "Pagamentos Recebidos".
- Admin: RelationManager **Carteira** no influenciador (extrato + ação Ajustar).
- Testes: saldo = soma; débito concorrente não deixa o saldo negativo (dois débitos em paralelo com lock); ajuste exige permissão e observação.

**F2.3 Resgate em Pix**
- Ação de Admin/Gerente "Pagar saldo em Pix" no influenciador: valor ≤ saldo; comprovante no disco `local`; exige `influenciador_prestador_id` (cria o `Prestador` PF da categoria `AUTONOMO` com nome, CPF e Pix se não existir).
- Numa transação: `ResgateCarteira` + `Lancamento` de despesa pago (conta e plano da configuração) + movimento `RESGATE_PIX`. Trava contra clique duplo.
- Recibo simples em PDF (DomPDF) para o influenciador assinar ou guardar.
- Remover `GerarPagamentoBulkAction`, `PagamentoComissaoService` e o fluxo de cupom `CREDITO-…` (D5).
- Testes: lançamento e movimentação criados uma vez; saldo debitado; sem Prestador ou sem configuração → erro claro; comprovante fora de `storage/app/public`.

**F2.4 Consumo em pizza (só o próprio influenciador)**
- Painel do influenciador: botão "Gerar código de resgate", que mostra 6 dígitos válidos por 15 minutos, de uso único. Gerar um novo invalida o anterior.
- Forma de pagamento com `opcaopag_carteira_influenciador = true` (cadastro manual, documentado no deploy).
- `OperarVenda`: ao escolher essa forma, pede o código, mostra o nome e o saldo do influenciador, limita o valor ao menor entre saldo e restante a pagar, e grava `PagamentosVenda` + `CodigoResgate.usado` + movimento `CONSUMO` (com `cm_pagamento_venda_id`) na mesma transação. Estorno ou remoção do pagamento gera `ESTORNO_CONSUMO`.
- `PagamentosVendaController` (legado) e o cardápio **recusam** essa forma.
- Contabilização conforme a nota aprovada na F2.0.
- Testes: código inválido, vencido ou usado é recusado; valor acima do saldo é recusado; débito acontece uma vez só; estorno devolve o saldo; o pedido pago com a carteira não gera comissão para o próprio influenciador.

### Fase 3: experiência do influenciador e gestão

- **[obrigatório] Regulamento (D8):** página Admin para publicar uma nova versão (`regulamentos_influenciador`). O aceite (checkbox + link para o texto) é obrigatório no autocadastro. Quando sai uma versão nova, um middleware do painel redireciona para "Aceitar regulamento" antes de qualquer outra página. Grava a versão, a data e o IP do aceite.
- **[obrigatório] Senha:** ação do Admin "Redefinir senha" (gera senha temporária e exige troca no próximo acesso). `->passwordReset()` no painel **só** se o e-mail estiver configurado em produção (confirmar `MAIL_*` no deploy).
- **[obrigatório] Perfil:** `->profile()` com página customizada: nome, WhatsApp (normalizado), Instagram, **CPF e chave Pix** (obrigatórios para pedir resgate em Pix), troca de senha.
- **[obrigatório] Notificações no painel (D7):** `->databaseNotifications()` no painel do influenciador. Cadastro aprovado, cupom usado, comissão creditada ou estornada, resgate pago. Envio **depois do commit** da transação (`DB::afterCommit` ou notificação na fila `database` com o worker que já existe), nunca dentro da transação.
- **Dashboard do influenciador:**
  - Pedidos e "Vendas geradas" só com pedidos pagos (exclui `INICIADO` e `CANCELADO`);
  - Comissão pendente (estimada) separada da creditada;
  - Saldo da carteira;
  - Cliques no link no mês;
  - Status com rótulo.
- **Kit de divulgação:** mostrar o link rastreável e o QR do cupom principal, com botão de copiar.
- **Relatório admin `RelatorioInfluenciadores`** (`HasPageShield`, Admin e Gerente, `InteractsComPeriodo`): por influenciador, cliques, pedidos pagos, valor líquido das vendas, descontos concedidos, comissões creditadas, resgates (Pix e consumo) e saldo a pagar.
- Testes de cada item, incluindo o bloqueio do painel até o aceite da versão nova.

---

## 6. Critérios de aceitação do go-live

- [ ] Cliente aplica o cupom no cardápio (ou pelo link) e o desconto aparece igual no pedido, na venda, no caixa, na cobrança da Stone e na NFC-e.
- [ ] Editar o pedido mantém o desconto correto.
- [ ] O influenciador não consegue criar cupom acima do teto. Cupom de influenciador inativo é recusado.
- [ ] Comissão creditada só depois da venda paga, sobre o líquido sem frete. Cancelamento, `cancelarEntregue` e estorno de venda revertem corretamente.
- [ ] O saldo da carteira bate com o extrato. O resgate em Pix gera despesa de marketing paga. O consumo em pizza exige código do próprio influenciador e não gera comissão para ele.
- [ ] Só Admin e Gerente acessam Influenciadores, Cupons, Comissões, Resgates e Materiais. Não existe exclusão física.
- [ ] Regulamento aceito no cadastro; perfil com CPF e Pix; Admin consegue redefinir senha.
- [ ] Nenhuma chamada HTTP externa no checkout. Campanhas, Níveis e WhatsApp fora do release.
- [ ] Testes da frente e a regressão de pedidos, vendas, Stone e financeiro verdes; Pint rodado; `public/build/` atualizado.

---

## 7. Riscos e cuidados

| Risco | Mitigação |
|---|---|
| Distribuição do desconto mexer em totais de pedidos sem cupom | `reaplicar()` não faz nada sem cupom; rodar `pedidos:auditar-totais` em dry-run antes e depois, na cópia local |
| Item vendido parcialmente (mesa) | Itens com `item_pedido_venda_id` ficam congelados (F1.1) |
| Contabilização da carteira errada no DRE | F2.0 com OK do usuário antes da F2.4 |
| Pagamento a pessoa física (RPA e retenções) | Validar com o contador; o sistema guarda comprovante e emite recibo |
| Força bruta de cupom | Throttle + mensagem genérica |
| Fraude no consumo | Código de uso único com validade curta + débito travado |
| Migrations já rodadas em algum ambiente | Conferir antes de consolidar (seção 4) |
| Commit misturado | Seção F0.1; blob montado a partir do HEAD em `Pedido.php` |

---

## 8. Nota de contabilização (preencher na F2.0, antes da F2.4)

_Pendente. Ver F2.0._

---

## 9. Próxima fase (fora deste release)

- **Campanhas** (briefing, convite, entrega de conteúdo, recompensa) e **Níveis** (faixas por volume trimestral, % de comissão e teto por nível). Registrar aqui o desenho das migrations removidas na F0.1.
- **WhatsApp via Evolution API:** canal de notificação com fila e timeout, número normalizado (`55DDD…`), opt-in do influenciador.
- Cupom no Painel do Garçom, na mesa e na venda direta do PDV.

### Anexo: desenho do que saiu na F0.1 (24/10/2026)

Removido do release (arquivos apagados do working tree; nunca foram commitados nem migrados).

**Níveis** — tabela `niveis_influenciador`: `nivel_nome`, `nivel_ordem` (uint), `nivel_minimo_pedidos_trimestre` (uint), `nivel_percentual_comissao` (decimal 5,2), `nivel_desconto_maximo_cupom` (decimal 5,2), `nivel_cor` (nullable), timestamps + softDeletes. Em `influenciadores`: `influenciador_nivel_id` (FK nullOnDelete) e `influenciador_nivel_fixo` (boolean, trava o nível manual). Seeder (`updateOrCreate` por nome): Bronze 1/0 pedidos/5%/10%/`#cd7f32`; Prata 2/20/8%/15%/`#c0c0c0`; Ouro 3/50/12%/20%/`#ffd700`.

**Campanhas** — `campanhas`: `campanha_titulo`, `campanha_briefing` (text), `campanha_data_inicio`, `campanha_data_fim`, `campanha_recompensa_descricao` (nullable), `campanha_recompensa_valor` (decimal 10,2 nullable), `campanha_ativo`, softDeletes. `campanha_influenciador` (participação): `ci_campanha_id`, `ci_influenciador_id` (cascade), `ci_status` (enum `StatusParticipacaoEnum`: convidado, aceito, entregue, aprovado, recusado), `ci_link_conteudo`, `ci_observacao_admin`, `ci_aceito_em`, `ci_entregue_em`, `ci_avaliado_em`, softDeletes; unique `(ci_campanha_id, ci_influenciador_id)` com nome explícito `campanha_influenciador_unico` (o automático estoura 64 chars). Em `comissoes`: `comissao_campanha_id` (FK nullOnDelete) e `comissao_pedido_id` nullable, para o bônus por entrega aprovada (sem pedido). Na retomada, o bônus deve entrar como movimento `AJUSTE`/tipo próprio da carteira, não como `Comissao`.

**WhatsApp (Evolution API)** — `EvolutionApiService::enviarTexto($numero, $mensagem)`: `POST {base_url}/message/sendText/{instance}` com header `apikey` e corpo `{number, text}`; sem as 3 variáveis (`EVOLUTION_API_BASE_URL`, `EVOLUTION_API_INSTANCE`, `EVOLUTION_API_KEY`, em `config/services.php` → `evolution`) virava no-op com log. `WhatsappChannel` chamava o service a partir de `routeNotificationFor('whatsapp')`; as notificações tinham `toWhatsapp()` e o opt-in `influenciador_notificacoes_whatsapp`. Era síncrono e sem timeout (B8). Na retomada: fila, timeout, número `55DDD…` normalizado e opt-in.

---

## 10. Deploy

- Produção não tem npm nem composer: versionar `public/build/` (cardápio) e **não** levar `laravel/boost`. Recarregar o OPcache.
- `migrate` + `PermissionSeeder`.
- **Cadastros manuais no `/admin`:**
  - plano de despesas "Marketing › Comissões de influenciadores";
  - conta financeira de saída do Pix;
  - conta de compensação, se a F2.0 adotar;
  - forma de pagamento "Carteira de influenciador" (com conta e plano, conforme a contabilização de vendas);
  - **Configuração de Influenciadores** (tetos, comissão padrão, plano, conta);
  - **regulamento v1**.
- Conferir `MAIL_*` (recuperação de senha) e o cron do `schedule:run` (worker da fila para as notificações).
- Commits com arquivos listados por nome. Promover `prod-new` → `producao` com `scripts/promote-producao.sh`.

---

## Arquivos

**Existentes da frente (sem commit), a revisar ou alterar**
- Models: `Influenciador`, `Cupom`, `Comissao`, `MaterialDivulgacao`, `CliqueReferencia`, `PagamentoComissao` (vira `ResgateCarteira`); **remover** `Campanha`, `CampanhaInfluenciador`, `NivelInfluenciador`
- Enums: `StatusComissaoEnum`, `OrigemCupomEnum`, `FormaPagamentoComissaoEnum` (remover se ficar sem uso), `StatusInfluenciadorEnum`, `TipoDescontoEnum`, `TipoMaterialEnum`, `StatusParticipacaoEnum` (remover, pertence a campanhas)
- Services: `CupomService`, `ComissaoService`; **remover** `PagamentoComissaoService` e `EvolutionApiService`
- Painel: `app/Providers/Filament/InfluenciadorPanelProvider.php`, `app/Filament/Influenciador/**`, `resources/views/filament/influenciador/**`, `resources/views/influenciador/aguardando-aprovacao.blade.php`
- Admin: `app/Filament/Resources/{Influenciadores,Comissoes,PagamentosComissao,MateriaisDivulgacao}/**`
- Observers: `ComissaoObserver`, `CupomObserver`, `InfluenciadorObserver`; Policies: `CupomPolicy`, `InfluenciadorPolicy`
- Notifications: `CadastroAprovadoNotification`, `ComissaoAprovadaNotification`, `ComissaoPagaNotification`, `CupomUtilizadoNotification` (passam para o canal `database`); **remover** `Channels/WhatsappChannel.php`
- Migrations `database/migrations/2026_09_17_*` (consolidar)
- Testes: `CardapioCheckoutCupomTest`, `ComissaoServiceTest`, `CupomOrigemTest`, `CupomServiceTest`, `GerarPagamentoBulkActionTest` (vira teste de resgate), `InfluenciadorAdminApprovalTest`, `InfluenciadorDashboardTest`, `InfluenciadorPanelAccessTest`, `InfluenciadorRegistroPublicoTest`, `KitDivulgacaoTest`, `VendaFinalizaComissaoTest`; **remover** `EvolutionApiServiceTest`

**Novos**
- `app/Services/CupomPedidoService.php`, `app/Services/CarteiraInfluenciadorService.php`
- Models `CarteiraMovimento`, `ResgateCarteira`, `CodigoResgate`, `RegulamentoInfluenciador`; enum `TipoMovimentoCarteiraEnum`
- `app/Filament/Pages/ConfiguracaoInfluenciadores.php`, `app/Filament/Pages/RelatorioInfluenciadores.php`
- `app/Filament/Resources/Cupons/**`; RelationManagers no `InfluenciadorResource`
- Painel do influenciador: Minha Carteira, Perfil, Aceitar regulamento
- Policies `ComissaoPolicy`, `ResgateCarteiraPolicy`, `MaterialDivulgacaoPolicy`
- Controller/rota do link `/r/{slug}` e de `POST /cardapio/cupom/validar`

**Alterados fora da frente**
- `app/Http/Controllers/CardapioCheckoutController.php`, `resources/views/cardapio.blade.php`, `routes/web.php`
- `app/Models/ItensPedido.php`, `app/Support/TotaisPedido.php`, `app/Services/TotaisPedidoService.php`, `app/Console/Commands/AuditarTotaisPedidos.php`
- `app/Services/LancamentoItensVendaService.php`, `app/Http/Controllers/ItensVendaController.php`, `app/Http/Controllers/PagamentosVendaController.php`
- `app/Filament/Pages/AtenderPedido.php`, `app/Filament/Pages/OperarVenda.php`
- `app/Services/PedidoStatusService.php`, `app/Services/CancelamentoVendaService.php`, `app/Observers/PedidoObserver.php`, `app/Observers/VendaObserver.php`
- `app/Models/Pedido.php`, `app/Models/Empresa.php`, `app/Models/OpcoesPagamento.php`
- `database/seeders/PermissionSeeder.php`, `config/auth.php`, `config/app.php` (só o provider), `app/Providers/EventServiceProvider.php`
