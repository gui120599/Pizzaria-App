# Evolução do Módulo de Pedidos: confiabilidade, consulta profissional e base de demonstração

> Origem: auditoria técnica e funcional do módulo de pedidos feita em 19/10/2026, na preparação do vídeo comercial do RazelFood. Os dados citados vêm de uma cópia local do banco de produção (58.682 pedidos, até 18/10/2026).
> Status: **plano aprovado em 19/10/2026, aguardando implementação.** As decisões do usuário estão na seção 2.
> Execução: por fases. **Cada fase é uma entrega independente**, com seus próprios testes e o próprio commit. Não comece a fase seguinte sem que a anterior esteja verde. A Trilha D (base de demonstração) não depende das fases e pode rodar em paralelo.

---

## 1. Contexto (fatos verificados)

### O que já existe e NÃO deve ser refeito

| Peça | Onde | Observação |
|---|---|---|
| Vocabulário de status | `app/Enums/StatusPedidoEnum.php` | Tem `label()`, `cor()`, `icone()`, `campoDataHora()` e `proximo()`. **Não é cast** do model, de propósito. |
| Transições de status | `app/Services/PedidoStatusService.php` | Transação + `lockForUpdate` + `save()` no model (o `PedidoObserver` baixa e estorna estoque). Inclui `rejeitar`, `cancelar` e `cancelarEntregue`. |
| Linha do tempo | `HistoricoStatusPedido` + `HistoricoStatusPedidoObserver`; relação `Pedido::historicoStatus()` | Grava o usuário em cada transição. |
| SLA | `app/Enums/UrgenciaPedidoEnum.php` + `config('pizzaria.pedidos.sla')` | Mede o tempo no status atual. |
| Kanban | `app/Filament/Pages/PainelPedidos.php` | Busca por nº, nome e celular; filtro rápido; filtros de entregador, período e "só atrasados"; linha de produção. |
| Modal de detalhes | `PedidoStatusActions::verDetalhes()` + `resources/views/filament/pages/painel-pedidos/detalhes-modal.blade.php` | Mostra itens, valores, pagamento e timeline. |
| Dashboards | `PedidosDashboard` (`/admin/operacional`), `FinanceiroDashboard`, trait `App\Filament\Widgets\Concerns\InteractsComPeriodo` | Reaproveitar o trait em todo widget novo. |
| Autoria de exceções | `pedido_motivo_cancelamento`, `pedido_usuario_cancelou_id`, `item_pedido_usuario_removeu` (100% preenchido nos últimos 90 dias), cortesia com usuário e motivo | Os dados existem; o que falta é apresentá-los. |
| Cancelar pedido entregue sem pagamento | `PedidoStatusService::cancelarEntregue()` + `PedidoPolicy::cancelarEntregue()` (Admin/Gerente) + `PedidoStatusActions::cancelarEntregueDaTabela()` | Já existe. |
| Desfazer venda paga | `CancelamentoVendaService::cancelarFinalizada()` | Devolve os pedidos de FINALIZADO para ENTREGUE. Depois disso, o `cancelarEntregue` se aplica. |

### Problemas encontrados

| # | Problema | Evidência |
|---|---|---|
| 1 | Ticket público com ID sequencial expõe nome, celular e endereço do cliente (LGPD) | `routes/web.php:104` fora do grupo `auth`; `resources/views/pedidoPDF.blade.php:43-80`; `PDFController::pedidoPDF()` usa `find($id)` sem nenhuma verificação |
| 2 | Exclusão física em lote na lista | `PedidosTable` tem `DeleteBulkAction`. `itens_pedidos.item_pedido_pedido_id` não apaga em cascata, então a exclusão gera QueryException em pedido com itens. Em pedido sem itens, apaga em cascata `historico_status_pedidos` e `pagamentos_pedidos` sem estornar nada. `PedidoController::destroy()` não faz nada. |
| 3 | Lista sem filtros, sem data visível e sem busca por nº | `PedidosTable`: `->filters([])`, `created_at` oculto, `id` não pesquisável |
| 4 | Rascunhos misturados com pedidos reais | 4.142 pedidos `INICIADO` |
| 5 | Caixa não consegue ver um pedido | `recordUrl` aponta para `AtenderPedido`, que exige `update`. O Caixa só tem `view:pedido`. |
| 6 | Textos crípticos na tela | Valores de `withDefault` ("S/G", "S/E", "S/OPE") e o status bruto ("EM TRANSPORTE") |
| 7 | Motivo de cancelamento pouco informativo | 90 dias: 161 de 262 são `outro` e 69 estão vazios; não existe campo de texto |
| 8 | "Tempo de ciclo" engana | `PedidosStatsOverview` mede da abertura até a **finalização** (pagamento): média de ~391 min em balcão e ~126 min em mesa |
| 9 | Tempos de etapa medem cliques | Mesa: 30% chegam a "pronto" menos de 1 min depois do "preparo"; 81% chegam a "entregue" menos de 1 min depois do "pronto" |
| 10 | Sem índice em `pedidos.pedido_venda_id` | É usado em `whereHas` a partir de `Venda` |
| 11 | Provável N+1 na lista | O `visible()` das ações da Stone consulta `StonePedido`, `Maquininha::stoneDisponivel()->exists()` e `temPagamentoCombinadoStone()` a cada linha |
| 12 | Pedidos pendurados | 92 `ENTREGUE` sem pagamento (desde jan/2026), 26 `ABERTO` (de 14 a 18/10) e 1 `PREPARANDO` (desde 27/09) |

### Modelo financeiro (não confundir)

- `pedido_valor_*` é o **valor do pedido** (esperado). Na interface o rótulo é **"Valor do pedido"** / **"Valor dos pedidos"**.
- A **receita** vem de `Venda`: `venda_valor_total`, `venda_valor_pago`, `pagamentos_vendas`. O rótulo "Faturamento" só aparece em telas que leem `Venda`.
- Uma sessão de mesa tem N pedidos e 1 venda. O pagamento pode ser parcial, por item (`item_pedido_venda_id`).
- `pagamentos_pedidos` ("combinados") é informativo e existe em só ~4% dos pedidos. Não serve como fonte para filtrar forma de pagamento.

---

## 2. Decisões aprovadas (19/10/2026)

| # | Decisão |
|---|---|
| D1 | O ticket de impressão passa a usar **link assinado com prazo**, e o prazo é **configurado pelo Admin**. |
| D2 | Exclusão de pedido vira **soft delete com motivo obrigatório**. **Admin e Gerente** podem excluir, restaurar e cancelar pedido entregue. |
| D3 | Rascunhos (`INICIADO`) saem da visão padrão e ganham **aba própria**. |
| D4 | Na lista e nos widgets de pedido o rótulo é "Valor do pedido"; "Faturamento" fica só com `Venda`. |
| D5 | O saneamento dos pedidos pendurados (problema 12) é decisão operacional do **Admin e do Gerente**, feita pela própria interface entregue (aba Pendências). Nada é corrigido por script. |
| D6 | Exportação com o **ExportAction nativo do Filament**. |
| D7 | Motivo "Outro" exige **texto obrigatório**. |
| D8 | As métricas por usuário se limitam a **volume e exceções**, visíveis só para Admin e Gerente. **Nenhuma métrica de tempo por pessoa.** |
| D9 | **Base de demonstração**: cópia da produção em que só os dados sensíveis são trocados por fictícios. Valores, produtos e cardápio continuam reais. |
| D10 | Este plano fica salvo em `docs/planos/evolucao-modulo-pedidos.md`. |

**Interpretação de D2 ("cancelar pedidos entregues"):** um pedido `ENTREGUE` **sem pagamento** é cancelado direto por `cancelarEntregue` (já existe para Admin e Gerente; esta entrega leva a ação para a página de visualização e para a aba Pendências). Um pedido **pago** (`FINALIZADO`) segue o caminho fiscal que já existe: primeiro desfaz a venda (`CancelamentoVendaService`, com NFC-e), o pedido volta a `ENTREGUE` e então pode ser cancelado. **Não crie** atalho que cancele pedido pago sem passar pela venda.

---

## 3. Invariantes (não quebrar)

1. Toda mudança de `pedido_status` passa pelo `PedidoStatusService`. Nada de `update()` por query builder e nada de `updateQuietly()` fora dos casos já documentados.
2. A exclusão **nunca** é física. Não existe `ForceDeleteAction` em lugar nenhum. Atenção: o Admin passa por qualquer Gate (`config/filament-shield.php`, `super_admin.define_via_gate`), então a proteção é **não oferecer a ação**, não apenas negar na Policy.
3. A exclusão nunca pode deixar efeito colateral sem estorno (estoque, promoção, cupom, comissão, cobrança na Stone). Ver F1.2.
4. Consulta crua (`DB::table('pedidos')` ou `join('pedidos', …)`) precisa filtrar `pedidos.deleted_at IS NULL` explicitamente.
5. Todo filtro novo da tabela é registrado no `filtersFormSchema()` quando houver layout customizado. Um filtro esquecido fica `isActive=true` e derruba as bulk actions (problema conhecido).
6. Arquivo exportado (que contém dados pessoais) **nunca** vai para o disco `public`.
7. O Kanban (`PainelPedidos`) mantém o comportamento atual: `PainelPedidosFiltrosTest`, `PainelPedidosQueryCountTest` e `PainelPedidosTest` continuam verdes.

---

## 4. Fase 1: confiabilidade, segurança e lista mínima (P0)

### F1.1 Ticket com link assinado e prazo configurável (D1)

**Configuração**
- Migration: `empresas.empresa_validade_link_ticket_horas`, `unsignedSmallInteger`, `default 24`, não nulo.
- A `Empresa` é singleton (`Empresa::firstOrFail()`); siga o padrão de `ConfiguracaoSefaz`.
- Página Filament nova: `app/Filament/Pages/ConfiguracaoPedidos.php`, grupo `Configurações`, acesso **só para Admin**. Campo "Validade do link do ticket (horas)", com mínimo 1 e máximo 720. O texto de ajuda explica que tickets já impressos ou compartilhados deixam de abrir depois do prazo.

**Geração**
- `Pedido::linkImpressao(): string` → `URL::temporarySignedRoute('pedido.imprimir', now()->addHours($validade), ['id' => $this->id])`. A validade é lida da `Empresa`, com fallback 24.
- Trocar todos os pontos que geram o link no **PHP/Blade** por `$pedido->linkImpressao()`:
  - `app/Filament/Pages/PainelPedidos.php:203` e `:339` (a impressão automática de rodadas recebe URLs prontas: monte-as com o método)
  - `resources/views/filament/pages/painel-pedidos/card.blade.php:201`
  - `app/Filament/Resources/Clientes/RelationManagers/PedidosRelationManager.php:100`
  - `resources/views/filament/pages/operar-venda.blade.php:227`
  - `resources/views/app/venda/index.blade.php:424`, `resources/views/app/venda/edit.blade.php:433`
  - `resources/views/app/pedido/partials/list.blade.php:59` e `:97`
- **Exceção:** `resources/views/app/pedido/abertos.blade.php` (5 pontos: 341, 556, 797, 1129, 1373) monta a URL **no JavaScript** trocando o `1` pelo id. Não dá para assinar no cliente. Essas telas são autenticadas e continuam funcionando pela sessão (ver a regra de acesso abaixo). Não mexa no JS legado.

**Regra de acesso** (rota `pedido.imprimir` continua fora do grupo `auth`)
- `PDFController::pedidoPDF()`: `findOrFail`, depois **permite** se `$request->hasValidSignature()` **ou** se `$request->user()?->can('view', $pedido)`. Fora desses casos, `abort(403)`.
- O QR do entregador (`Pedido::linkScanEntrega()`, assinado e sem prazo, exige login com o papel Entregador) **não muda**.

**Testes** (atualizar `tests/Feature/PedidoPDFQrCodeTest.php` e criar `PedidoImpressaoAcessoTest`)
- Sem sessão e sem assinatura → 403.
- Sem sessão com assinatura válida → 200 (os asserts atuais do QR continuam).
- Assinatura vencida (`$this->travel(25)->hours()` com validade 24) → 403.
- Validade configurada na `Empresa` é respeitada (por exemplo, 2 h: vence em 3 h).
- Logado com `view:pedido`, sem assinatura → 200 (cobre o JS legado).
- Logado sem `view:pedido` (Entregador), sem assinatura → 403.
- Pedido inexistente → 404.
- `ConfiguracaoPedidos`: Admin salva o valor; Atendente recebe 403; valor fora do intervalo gera erro de validação.

**Verificação antes do deploy:** procurar no log de acesso da produção requisições **sem sessão** a `/Pedido/*/Imprimir` vindas de algum aparelho (agente de impressão, tablet não logado). Se existirem, esses aparelhos têm de receber o link assinado e não podem montar a URL sozinhos.

### F1.2 Soft delete com motivo (D2)

**Schema**
- Migration em `pedidos`: `softDeletes()`, `pedido_motivo_exclusao` (string nullable), `pedido_exclusao_observacao` (text nullable), `pedido_usuario_excluiu_id` (FK `users`, `nullOnDelete`).
- Nomeie FKs e índices explicitamente, respeitando o limite de 64 caracteres.
- Enum novo `app/Enums/MotivoExclusaoPedidoEnum.php`: `DUPLICADO`, `LANCADO_POR_ENGANO`, `TESTE_TREINAMENTO`, `OUTRO`, com `label()` e `paraSelect()`, no mesmo padrão de `MotivoCancelamentoEnum`.
- `Pedido`: `use SoftDeletes`; cast do motivo; relação `usuarioExcluiu()`.

**Regra de domínio:** classe nova `app/Services/PedidoExclusaoService.php`
- `excluir(Pedido $pedido, User $ator, MotivoExclusaoPedidoEnum $motivo, ?string $observacao): Pedido`
  - Autoriza com `delete` na Policy. Roda em `DB::transaction` com o pedido relido em `lockForUpdate`.
  - **Pode excluir:** `CANCELADO`, `INICIADO` e `ABERTO`.
    - Se for `INICIADO` ou `ABERTO`, **cancela antes** por `PedidoStatusService::rejeitar()` (motivo de cancelamento `DUPLICADO` ou `ERRO_LANCAMENTO`, conforme o motivo de exclusão). Assim os estornos que já existem (promoção, cupom, produção) rodam pelo caminho de sempre. Confira na leitura de `cancelamento()` que `rejeitar` aceita os dois status; se não aceitar, ajuste só a guarda de status e cubra com teste.
  - **Recusa** (exceção de domínio nova, com mensagem clara para a interface):
    - status `PREPARANDO` em diante (o pedido tem de ser cancelado antes, porque envolve estoque e cozinha);
    - `pedido_venda_id` preenchido ou algum item com `item_pedido_venda_id`;
    - `StonePedido` em `Aguardando`, `PagoParcial` ou `Pago`;
    - comissão ativa (`Pedido::comissao()` não cancelada; confira os estados em `StatusComissaoEnum`).
  - `OUTRO` exige observação com pelo menos 10 caracteres.
  - Grava motivo, observação, usuário e então `delete()` (soft).
- `restaurar(Pedido $pedido, User $ator): Pedido` autoriza com `restore`, faz `restore()` e limpa os três campos. O pedido volta como **`CANCELADO`** e não "renasce" ativo.

**Permissões** (`database/seeders/PermissionSeeder.php`, no mesmo padrão de `conceder_cortesia:pedido`)
- Gerente recebe `delete:pedido`, `delete_any:pedido`, `restore:pedido` e `restore_any:pedido`.
- Retire do Admin `force_delete:pedido` e `force_delete_any:pedido`. É só higiene: o Admin já passa no Gate. A garantia de verdade é a invariante 2.
- `cancelarEntregue` já está correto (`cancel:pedido` + papel Admin ou Gerente; o Gerente já tem `cancel:pedido`). Mantenha.

**Interface**
- `PedidosTable`: remover `DeleteBulkAction`.
  - Action `excluir` (registro) com schema `Select` de motivo + `Textarea` de observação, obrigatória quando o motivo é `OUTRO`. Visível se `can('delete')` e se `PedidoExclusaoService::podeExcluir()`.
  - BulkAction `excluir` chama o service registro a registro e informa "X excluídos, Y ignorados (motivo)", no mesmo padrão de `enviarStoneBulkAction`.
  - `RestoreAction` e `RestoreBulkAction` aparecem **só** na aba Lixeira.
- `PedidoResource::getEloquentQuery()` remove o `SoftDeletingScope` (padrão do Filament para soft delete); as abas decidem o que aparece.
- Remova a rota sem efeito `pedido.destroy` (`routes/web.php:257`) e o método `PedidoController::destroy()`, depois de confirmar com grep que nenhuma view a usa.

**Consultas cruas** (invariante 4): acrescentar `whereNull('pedidos.deleted_at')` em
- `app/Filament/Widgets/MotivosCancelamentoChart.php`
- `app/Filament/Widgets/PedidosPorOrigemChart.php`
- `app/Filament/Widgets/PedidosStatsOverview.php` (join de itens)
- `app/Support/ContaMesa.php`
- `app/Services/RelatorioTaxaServicoService.php`

Antes de fechar a fase, rode de novo `grep -rn "DB::table('pedidos')\|join('pedidos'" app` para pegar algum ponto novo.

**Testes:** `tests/Feature/PedidoExclusaoServiceTest.php` e `tests/Feature/PedidosTableExclusaoTest.php`
- Exclui um `CANCELADO`: grava motivo, usuário e observação; some da consulta padrão; aparece em `onlyTrashed()`.
- Exclui um `INICIADO` e um `ABERTO`: o pedido passa por `CANCELADO` (aparece no histórico) antes da exclusão.
- Recusa `PREPARANDO`, pedido com venda, pedido com item vendido, pedido com StonePedido pendente.
- `OUTRO` sem observação → erro de validação.
- Gerente exclui e restaura; Atendente e Caixa não veem a ação.
- Restaurar devolve o pedido como `CANCELADO` e limpa os campos.
- Nenhuma ação de force delete na tabela, nem para o Admin.
- O `MotivosCancelamentoChart` não conta pedido excluído.

### F1.3 Motivo "Outro" com texto obrigatório no cancelamento (D7)

- Migration: `pedidos.pedido_motivo_cancelamento_observacao` (text nullable). Pode ir na mesma migration da F1.2.
- `PedidoStatusActions::camposMotivo()` ganha o `Textarea` de observação, **obrigatório quando o motivo é `OUTRO`** (`requiredIf` ou `required(fn (Get $get) => …)`).
- `PedidoStatusService::cancelar`, `rejeitar` e `cancelarEntregue` recebem `?string $observacao` e gravam. A validação de "OUTRO exige texto" fica **no service** e não só no formulário, porque o service tem outros chamadores.
- Telas legadas que cancelam (modal `cancelar-pedido-{id}` em `pedido/partials/list.blade.php` e `pedido/edit.blade.php`, via `PedidoController`): acrescentar o campo e a validação no controller. Se o volume de uso do legado não justificar, a validação do service basta, desde que o erro volte para a tela com mensagem clara.
- Investigar a origem dos **69 cancelamentos sem motivo** nos últimos 90 dias. Algum caminho grava `CANCELADO` sem motivo. Liste-o no PR; corrija só se for trivial, senão registre como pendência.
- Testes: `OUTRO` sem texto falha (na action e no service); com texto, grava; outros motivos continuam sem exigir texto.

### F1.4 Página "Ver pedido" (somente leitura)

- `app/Filament/Resources/Pedidos/Pages/ViewPedido.php` + `app/Filament/Resources/Pedidos/Schemas/PedidoInfolist.php` (padrão `Schemas/` do CLAUDE.md), registrada como `'view'` no `PedidoResource`.
- A rota resolve **inclusive pedidos excluídos** (`resolveRecordRouteBinding` com `withTrashed()`), para Admin e Gerente.
- **Seções**
  1. **Cabeçalho:** nº; status (rótulo + ícone + cor do enum); tipo de atendimento (`tipoAtendimento()`); origem; abertura. Se estiver excluído, faixa de destaque com motivo, observação, quem excluiu e quando.
  2. **Cliente:** nome, celular, endereço de entrega (o snapshot em `pedido_endereco_entrega`).
  3. **Itens `INSERIDO`:** quantidade, produto, sabores, adicionais, observação, valor; badge "Cortesia" com o motivo.
  4. **Itens removidos** (seção recolhida): produto, quantidade, quem removeu.
  5. **Valores:** itens (bruto), desconto, cupom, frete, **Valor do pedido** (D4).
  6. **Pagamento:** combinados (informativo) + venda vinculada (nº, status, valor pago, link para a venda se o usuário tiver `view:venda`). Situação: Pago / A receber / Parcial.
  7. **Linha do tempo:** `historicoStatus` com usuário e **tempo decorrido entre etapas**. Texto de ajuda: "conforme registrado no sistema".
  8. **Cancelamento:** motivo, observação, quem cancelou e quando.
- **Ações do cabeçalho:**
  - Editar (`AtenderPedido`, visível se `can('update')` e se o status não está em `AtenderPedido::STATUS_SOMENTE_LEITURA`);
  - Imprimir (`linkImpressao()`, nova aba);
  - Cancelar;
  - Cancelar pedido entregue;
  - Excluir / Restaurar.

  Reaproveite `PedidoStatusActions` sempre que der.
- `PedidosTable::recordUrl` passa a apontar **sempre** para `ViewPedido`. A ação "Atender" continua como atalho de edição.
- Carregamento antecipado num só lugar: o mesmo `with()` usado hoje por `verDetalhes()`, mais `vendas`, `usuarioCancelou`, `usuarioExcluiu` e os itens removidos com `usuarioRemoveu`. O modal do Kanban **não** é alterado nesta entrega.
- Testes (`tests/Feature/ViewPedidoTest.php`):
  - Caixa (`view:pedido`) abre e vê itens e timeline;
  - FINALIZADO não mostra "Editar";
  - pedido excluído abre para Gerente e devolve 404 para Atendente;
  - teste de quantidade de consultas (constante com 1 ou 10 itens).

### F1.5 Lista mínima utilizável (D3, D4)

- **Abas** (`ListPedidos::getTabs()`):
  - **Pedidos** (padrão: tudo menos `INICIADO`, sem excluídos);
  - **Rascunhos** (`INICIADO`);
  - **Lixeira** (`onlyTrashed()`, visível só com `restore_any:pedido`).

  A Fase 2 acrescenta abas.
- **Colunas:**
  - `#` (pesquisável por número exato);
  - **Abertura** (`pedido_datahora_abertura`, com fallback para `created_at` em rascunho; ordenável; descrição relativa "há X min");
  - **Tipo** (badge de `TipoAtendimentoEnum`);
  - **Cliente** (nome, com o celular como `description`; nome e celular pesquisáveis);
  - **Valor do pedido**;
  - **Status** (`formatStateUsing` → `label()`, `icon` → `icone()`, `color` → `cor()`);
  - **Atendente** e **Entregador**, ocultáveis.
- **Sem textos crípticos:** quando a FK for nula, a coluna mostra `placeholder('—')`. **Não** altere os `withDefault()` do model, porque as views legadas dependem deles. Resolva na coluna, por exemplo `state(fn (Pedido $r) => $r->pedido_usuario_garcom_id ? $r->garcom->name : null)`.
- Ordenação padrão: `pedido_datahora_abertura desc` na aba Pedidos e `id desc` nas outras.
- Testes (`tests/Feature/PedidosTableTest.php`):
  - a aba padrão não lista `INICIADO` e a aba Rascunhos lista;
  - busca "58656" (número) e busca por celular;
  - status aparece como "Em transporte";
  - nenhuma célula com "S/G", "S/E" ou "S/OPE".

### F1.6 Indicadores do `/operacional` que enganam

Arquivo: `app/Filament/Widgets/PedidosStatsOverview.php`.
- **"Tempo de ciclo" vira "Tempo até a entrega"**: de `pedido_datahora_abertura` até `pedido_datahora_entrega`, com a descrição "Abertura → entregue (conforme registro)". O fim **não** pode mais ser `pedido_datahora_finalizado`, que é o pagamento.
- **"Tempo de preparo"** ganha a descrição "Preparo → pronto (conforme registro no painel)".
- Novo filtro de página **Tipo de atendimento** (multi-select Delivery/Retirada/Mesa) no `PedidosDashboard::filtersForm`, aplicado nos widgets do dashboard pelo escopo `Pedido::scopeTipoAtendimento` (criado na F2.1; se a F1 vier antes, crie o escopo aqui).
- Testes (`tests/Feature/PedidosStatsOverviewTest.php`, novo, porque hoje não existe nenhum teste dos widgets): cenário com tempos controlados para checar que "até a entrega" ignora `finalizado`; o filtro de tipo funciona; pedido excluído não entra.

### F1.7 Índice

- Migration: índice em `pedidos.pedido_venda_id` (nome explícito, por exemplo `pedidos_venda_id_idx`).

### Critérios de aceitação da Fase 1

- [ ] Ticket sem assinatura e sem sessão → 403. O link vence no prazo configurado pelo Admin. Telas autenticadas, legadas inclusive, continuam imprimindo.
- [ ] Não existe exclusão física por nenhum caminho de interface. A exclusão pede motivo, guarda o autor e pode ser revertida.
- [ ] Cancelamento com "Outro" exige texto.
- [ ] O Caixa consegue abrir qualquer pedido em modo somente leitura, com a timeline.
- [ ] A lista mostra data, tipo e status legíveis, e nenhum "S/E", "S/G" ou "S/OPE".
- [ ] O `/operacional` não usa mais o horário de finalização para medir tempo.
- [ ] Todos os testes de pedidos passam (`--filter` com Pedido, Painel, AtenderPedido, StatusPedido, Cancelar); Pint rodado.

---

## 5. Fase 2: consulta profissional e exportação (P1)

### F2.1 Escopos compartilhados (`app/Models/Pedido.php`)

- `scopeTipoAtendimento(Builder $q, TipoAtendimentoEnum $tipo)`: **mova** a regra de `PainelPedidos::aplicarTipoAtendimento()` para cá, e o Kanban passa a usar o escopo (os testes do Painel continuam verdes).
- `scopeReais()`: `pedido_status != INICIADO`.
- `scopeEmAndamento()`: `StatusPedidoEnum::valoresEmAndamento()` sem `INICIADO`.
- `scopeNaoPagos()`: `ENTREGUE`, `pedido_venda_id IS NULL` e nenhum item com `item_pedido_venda_id`. **A mesma regra** do guard de `PedidoStatusService::cancelarEntregue()`. Extraia para um único lugar, para as duas não divergirem.
- `scopeParados(?Carbon $agora = null)`: em andamento e acima do limite `atrasado` do SLA do status atual. É um OR por status, usando `campoDataHora()` e `config('pizzaria.pedidos.sla')`. Inclui `ABERTO` aberto em turno anterior.
- `scopePagos()`: o inverso do critério de pagamento (venda vinculada ou item vendido).

### F2.2 Abas finais

Em andamento · **Pendências** (`naoPagos` ∪ `parados`, com contador em badge vermelho) · Concluídos (`ENTREGUE` pago + `FINALIZADO`) · Cancelados · Todos (sem rascunhos) · Rascunhos · Lixeira.

A aba Pendências é a ferramenta da decisão D5: tem as ações **Cancelar pedido entregue** (para os não pagos), **Cancelar** e **Ver**, e o Admin ou Gerente sanea os 92 + 26 + 1 pedidos pela própria interface.

### F2.3 Filtros (todos combináveis, `persistFiltersInSession()`)

- **Período** (abertura): `DatePicker` de início e fim, com indicador; padrão "últimos 7 dias" nas abas Concluídos, Cancelados e Todos.
- Status (multi), Tipo de atendimento (multi, pelo escopo), Origem (`PedidoOrigemEnum`), Opção de entrega, Atendente, Entregador, Mesa.
- Situação de pagamento (Pago / A receber).
- Com desconto ou cupom; Com cortesia (`whereHas` em itens com `item_pedido_cortesia`); Cancelado por motivo.
- Forma de pagamento **da venda** (`whereHas('vendas.<relação de pagamentos>')`; confira o nome da relação em `Venda`). Nunca pelos combinados.

### F2.4 Colunas e rodapé

- Coluna **Pagamento** (badge Pago / A receber / Parcial) calculada sem N+1. Prefira `withExists` / `withCount` na `modifyQueryUsing` em vez de acessar a relação linha a linha.
- Coluna **SLA** (badge de `UrgenciaPedidoEnum`) só na aba Em andamento.
- Coluna **Origem**, oculta por padrão.
- Rodapé: `Sum` de `pedido_valor_total` com rótulo **"Valor dos pedidos (exceto cancelados)"**; o summarizer exclui `CANCELADO` pela própria query.

### F2.5 N+1 das ações da Stone (problema 11)

- Carregar antecipadamente `pagamentosCombinados.opcaoPagamento` e os `StonePedido` pendentes (relação `hasMany` com restrição, carregada antes, em vez da query de `stonePedidoPendente()` por linha).
- `Maquininha::stoneDisponivel()->exists()` calculado **uma vez por request** (`once()`).
- Teste: `PedidosTableQueryCountTest`, com quantidade de consultas igual para 5 e para 25 registros (no mesmo molde do `PainelPedidosQueryCountTest`).

### F2.6 Exportação nativa do Filament (D6)

- Antes de codificar, rode `search-docs` (exports, exporter, job connection).
- `vendor/bin/sail artisan make:filament-exporter Pedido --no-interaction`.
- `ExportAction` no cabeçalho e `ExportBulkAction`. Permissão nova `export:pedido` (Admin e Gerente) no `PermissionSeeder`. Exporta o filtro e a aba atuais.
- Colunas: nº, abertura, status (rótulo), tipo, origem, cliente, celular, atendente, entregador, opção de entrega, itens (bruto), desconto, cupom, frete, valor do pedido, situação de pagamento, nº da venda, motivo e observação do cancelamento, cancelado por, e a data/hora de cada etapa.
- **Disco privado:** `fileDisk('local')` (ou equivalente na versão instalada). Nunca `public`, porque os IDs de export são sequenciais e o arquivo tem dados pessoais (invariante 6).
- **Fila sem efeito colateral:** hoje existe `App\Jobs\EmitirNfeAutomaticaJob` (`ShouldQueue`). **Não troque** o `QUEUE_CONNECTION` padrão da produção: isso mudaria o envio automático de NFC-e. Direcione só o export para a conexão `database` pelo método do exporter que define a conexão do job (confira o nome exato com `search-docs`).
- **Pré-requisitos** (migrations versionadas no repositório):
  - tabela `jobs` (`make:queue-table`);
  - `job_batches` (`make:queue-batches-table`);
  - `exports`, `imports` e `failed_import_rows` (publicar as migrations do Filament Actions).

  `notifications` e `->databaseNotifications()` já existem. No banco local **não existem** `jobs` nem `job_batches`.
- **Worker em produção:** a produção não tem composer, e o cron do `schedule:run` ainda está pendente de confirmação. Agende no scheduler `queue:work database --stop-when-empty` a cada minuto, com `withoutOverlapping()`, e **confirme o cron** antes do deploy. Sem worker, a notificação de download nunca chega.
- Testes: a action cria o registro `Export` e despacha o batch (`Bus::fake()`); `Atendente` não vê a ação; o exporter mapeia o status para o rótulo e os valores corretamente.

### Critérios de aceitação da Fase 2

- [ ] As abas Pendências, Em andamento, Concluídos, Cancelados, Todos, Rascunhos e Lixeira funcionam, com contadores corretos.
- [ ] Os filtros combinam, ficam salvos na sessão e não desativam as bulk actions.
- [ ] O Kanban usa o mesmo escopo de tipo de atendimento.
- [ ] A quantidade de consultas da lista não cresce com o número de linhas.
- [ ] Gerente exporta em CSV/XLSX, recebe a notificação e baixa o arquivo; o arquivo fica fora de `storage/app/public`.

---

## 6. Fase 3: gestão orientada por dados (P1)

### F3.1 Comparativo com o período anterior

- No trait `InteractsComPeriodo`: `periodoAnterior()` (mesma duração, logo antes) e `variacao(float $atual, float $anterior): ?float`. Divisão por zero → null → "—".
- Aplicar na descrição dos Stats de `PedidosStatsOverview` e `FinanceiroStatsOverview` ("▲ 12% vs. período anterior"). O cálculo usa **exatamente** os mesmos filtros nos dois períodos.

### F3.2 Relatório de Exceções e Perdas

Página `app/Filament/Pages/RelatorioExcecoesPedidos.php` (com `HasPageShield`; Admin e Gerente), filtro de período pelo `InteractsComPeriodo`.

Inspiração: relatórios de caixa e perdas do Toast e voids/comps/descontos do Lightspeed Pulse.

- **Stats:** cancelados (quantidade, valor, % do valor dos pedidos), itens removidos (quantidade e valor), descontos + cupom (R$), cortesias (R$, com link para `RelatorioCortesias`, sem duplicar o relatório), pedidos excluídos (quantidade), entregues não pagos (quantidade e valor). Todos com comparativo (F3.1).
- **Tabelas:**
  - cancelamentos por motivo (com observação);
  - cancelamentos por **quem lançou × quem cancelou** (contagens);
  - itens removidos por usuário (`item_pedido_usuario_removeu`);
  - pedidos excluídos (motivo, observação, autor);
  - entregues não pagos (com ação Cancelar pedido entregue).
- **Lançamentos por usuário** (D8): pedidos lançados, valor dos pedidos, ticket médio, cancelamentos, remoções. **Sem nenhuma métrica de tempo.** Texto fixo no topo: "Contagens para acompanhamento. Não reflete desempenho individual sem o contexto da operação (turno, função, tipo de atendimento)." Ordenação padrão por nome, não por "pior".
- Widgets com `pedidos.deleted_at IS NULL`, exceto o bloco de excluídos.
- Testes: cada indicador com cenário controlado; acesso negado para Atendente e Caixa; o bloco "Lançamentos por usuário" não tem colunas de tempo.

### Critérios de aceitação da Fase 3

- [ ] Os números do relatório batem com consultas manuais num cenário de teste.
- [ ] Comparativo correto, e "—" quando não há base.
- [ ] Só Admin e Gerente acessam.

---

## 7. Trilha D: base de demonstração com dados sensíveis fictícios (D9)

**Objetivo:** gravar o vídeo comercial com valores, produtos, cardápio e volume reais, sem expor pessoas reais. Sai um banco separado; **a base `laravel` nunca é alterada**.

### D.1 Banco

- Nome: `razelfood_demo`. O usuário `sail` só tem grant em `laravel` e `testing%`. **Não use o prefixo `testing`**, porque suítes com `RefreshDatabase` apagariam a base.
- Grant feito **uma vez** como root, documentado no manual do comando:
  `GRANT ALL PRIVILEGES ON razelfood_demo.* TO 'sail'@'%';`
- Cópia: `mysqldump` existe no container `laravel.test` (`/usr/bin/mysqldump`). O comando roda o dump de `laravel` para `razelfood_demo` com `--single-transaction --no-tablespaces`.

### D.2 Comando

`app/Console/Commands/GerarBaseDemonstracao.php` (`demo:gerar-base {--destino=razelfood_demo} {--forcar}`) + service `app/Services/Demo/AnonimizadorBaseDemo.php`, que recebe o **nome da conexão** e por isso é testável.

**Travas obrigatórias:** recusa se `app()->isProduction()`, se o destino for igual a `config('database.connections.mysql.database')`, se o destino começar com `testing`, ou se o destino já existir sem `--forcar`.

**Passos:**
1. Copia (dump + restore).
2. Registra uma conexão em tempo de execução (`demo`) apontando para o destino.
3. Anonimiza (tabela abaixo).
4. Verifica: para cada coluna sensível, compara com a origem pelo `id` e **falha** se sobrar algum valor igual que não seja nulo.
5. Imprime um resumo: linhas por tabela, a senha e o PIN de demonstração.

**Determinismo:** Faker `pt_BR` com `seed` por `id` de linha, para que rodar de novo gere os mesmos nomes. Celular único derivado do `id` (por exemplo `(62) 9 9xxx-xxxx`). CPF fictício, mas com dígitos verificadores válidos.

**Atualizações por query builder** na conexão `demo`, sem disparar observers. Onde houver hash ou criptografia, use os helpers do próprio model (`Colaborador::hashCpf()`, `Colaborador::hashPin()`, `Crypt`, `Hash::make`). O `APP_KEY` é o mesmo da origem.

### D.3 O que muda e o que fica

| Tabela | Anonimizar | Manter |
|---|---|---|
| `clientes` | nome, cpf, rg, cnpj (PF), celular, email, rua, número, CEP, data de nascimento; foto → null | bairro, cidade, UF, tipo, limite de crédito |
| `pedidos` | `pedido_endereco_entrega` (refeito a partir do novo endereço do cliente, ou fictício); `pedido_observacao_pagamento` com depuração (ver abaixo) | todos os valores, status, datas |
| `users` | name, name_first, email (`usuario{id}@demo.razelfood.com.br`), password (senha de demo única), `user_pin` (PIN de demo único, hash), avatar → null, remember_token → null | papéis e permissões |
| `colaboradores` | nome, cpf (criptografado) + `cpf_hash`, chave Pix, `pin_quiosque`/`pin_lookup` (PIN de demo) | cargo, escalas, remuneração (sem nome e CPF, deixa de identificar a pessoa) |
| `prestadores` | **PF** (11 dígitos): nome, nome_fantasia, cpf, email, telefone, celular, chave Pix, CEP, endereço; foto → null. **PJ**: só os contatos (email, telefone, celular, chave Pix) | razão social e CNPJ de PJ (dado público), bairro |
| `contas_financeiras` | agência, conta, chave Pix | nome, banco, tipo |
| `mesa_participantes` | nome, celular; token, IP e user agent → null | — |
| `influenciadores` | nome, email, password, chave Pix, remember_token | nível, regras |
| `ponto_marcacoes` | `foto_caminho` (selfie), latitude, longitude, IP, user agent → null | horários |
| `checklist_*`, `producao_itens` | `operador_nome` → mesmo nome fictício do usuário correspondente (mapa nome antigo → novo), ou fictício; caminhos de anexo com foto → null | respostas, status |
| `repasses` | `ciente_ip` → null; inspecionar `remuneracao_snapshot` (JSON) e anonimizar nome/CPF se houver | valores |
| `empresas` | `empresa_api_nfeio_apikey`, `empresa_certificado_path`, `empresa_certificado_senha` → null; `empresa_api_nfeio_ambiente` e `empresa_sefaz_ambiente` → homologação; `empresa_sefaz_auto_importacao_ativa` → false; **identidade fictícia** (razão social, fantasia, CNPJ, endereço; ver P-1) | regime, % de imposto |
| `stone_webhooks`, `nfe_webhooks` | `stw_payload`, `nfw_payload` → null | status |
| Tabelas inteiras | **truncate:** `sessions`, `password_reset_tokens`, `personal_access_tokens`, `failed_jobs`, `jobs`, `job_batches`, `notifications`, `cache`, `cache_locks`; `dispositivos_cozinha.token_hash`/`ultimo_ip` → null | — |

**Texto livre** (`item_pedido_observacao`, `item_venda_observacao`, `pedido_observacao_pagamento`, `comissao_observacao`, `mov_observacoes`, `sessaocaixa_observacoes`, `observacoes` de fechamento, lançamentos, contratos e prestadores, `observacao`/`observacao_geral` de checklist):
- troca por regex: sequências de 8 ou mais dígitos (telefone/CPF), e-mails e chaves Pix;
- **mantém** o resto ("sem cebola", "troco p/ 100") para a demonstração parecer real.

Antes de codificar, rode de novo o inventário de colunas (consulta em `information_schema` com o padrão `cpf|cnpj|celular|telefone|email|endereco|nome|pix|token|senha|password|pin|apikey|certificado|payload|ip|user_agent|foto|latitude|longitude|observa`) para pegar colunas criadas depois de 19/10.

### D.4 Ambiente de gravação

`.env.demo` de exemplo, documentado e **não versionado com segredos**:
- `DB_DATABASE=razelfood_demo`
- integrações desligadas: sem chaves NFe.io, Stone/Pagar.me e SEFAZ; `MAIL_MAILER=log`; `QUEUE_CONNECTION=sync`

O objetivo é que **nenhuma ação do vídeo gere NFC-e, cobrança na maquininha ou e-mail reais**.

O `storage` é compartilhado: fotos de produtos aparecem normalmente, e selfies e anexos não aparecem porque os caminhos foram anulados.

### D.5 Testes (`tests/Feature/AnonimizadorBaseDemoTest.php`)

O teste roda o service sobre a conexão de teste (não sobre o dump):
- PII alterada, valores e produtos intactos;
- prestador PJ mantém a razão social;
- `sessions` vazia;
- `cpf_hash` coerente com o CPF novo;
- login com a senha de demo funciona;
- determinismo (duas execuções geram os mesmos nomes).

Teste das travas do comando: recusa em produção, recusa com destino igual à origem, recusa com prefixo `testing`.

### Critérios de aceitação da Trilha D

- [ ] `demo:gerar-base` cria `razelfood_demo` e termina com a verificação zerada (nenhum valor sensível igual à origem).
- [ ] Os dashboards e relatórios na base demo mostram os mesmos totais financeiros da origem.
- [ ] Com o `.env.demo`, nenhuma integração externa é chamada.

---

## 8. Fase 4: evolução estratégica (P2; **precisa de nova aprovação**)

Só registro, não implementar:
- mapa de calor dia × hora;
- resumo diário por e-mail ou WhatsApp (referência: Lightspeed Daily Digest, e-mail diário do Square);
- tempos reais de produção a partir da cozinha (depende de `COZINHA_ATIVO` em produção);
- indicadores de entregador (depende de atribuição obrigatória: hoje só ~4% dos pedidos têm entregador);
- vendas por bairro;
- passar o `StatusPedidoEnum` a cast;
- fazer o modal do Kanban reaproveitar o `PedidoInfolist`.

Candidatos a plano superior do SaaS: F3.2, resumo diário e comparativos avançados.

---

## 9. Riscos e cuidados

| Risco | Mitigação |
|---|---|
| Link vencido em ticket impresso | Texto de ajuda na configuração; prazo padrão de 24 h; telas logadas não dependem da assinatura |
| Aparelho que imprime sem sessão | Verificação no log antes do deploy (F1.1) |
| Soft delete esconder dado de consulta crua | Invariante 4 + grep no fim da F1.2 + teste no `MotivosCancelamentoChart` |
| Excluir para esconder cancelamento | Exclusão só depois do cancelamento, com motivo e autor, listada no Relatório de Exceções (F3.2) |
| Admin passa em qualquer Gate | Force delete não é oferecido em nenhuma interface |
| Export vazar dados pessoais | Disco privado + permissão `export:pedido` |
| Trocar a conexão padrão de fila mudar o envio de NFC-e | Só o export usa a conexão `database` |
| Worker inexistente em produção | Pré-requisito explícito de deploy (F2.6) |
| Mudança de definição de KPI | Descrição do card explica a nova medida; registrar no PR |
| Base demo com segredo ou PII residual | Verificação automática no fim do comando + `.env.demo` sem integrações |

---

## 10. Deploy (lembretes do projeto)

- Produção não tem npm nem composer: commitar `public/build/` (depois de `npm run build`) e `vendor/` quando mudar. Recarregar o OPcache depois do deploy.
- Rodar as migrations e o `PermissionSeeder` (permissões novas: `delete`/`restore` do Gerente e `export:pedido`) na produção.
- Fase 2: confirmar o cron do `schedule:run` e o worker antes de liberar a exportação.
- Commits com arquivos listados por nome (nunca `git add -A`). Promover `prod-new` → `producao` com `scripts/promote-producao.sh`.

---

## 11. Pendências de decisão (não bloqueiam a Fase 1)

- **P-1 (Trilha D): DECIDIDO em 19/10/2026: identidade fictícia.** Na base demo, razão social, nome fantasia, CNPJ (fictício, com dígitos verificadores válidos), endereço, telefone e chaves Pix da empresa viram fictícios ("Pizzaria Demonstração"). Textos e imagens fixos com a marca real em views (por exemplo, `asset('img/qrcode insta.png')` e o alt "Empório da Pizza" em `pedidoPDF.blade.php:219`) **não** saem do banco: liste no PR cada ocorrência da marca real nas views que aparecem no vídeo (ticket, cardápio, painel). Para essas, a decisão é tornar o texto/imagem configurável pela `Empresa` (preferível) ou anotar como "não exibir no vídeo". Não remova a marca real da produção.
- **P-2 (F2.3):** período padrão "últimos 7 dias" nas abas de histórico, mantido até nova orientação.

---

## Arquivos

**Novos**
- `app/Filament/Pages/ConfiguracaoPedidos.php`
- `app/Enums/MotivoExclusaoPedidoEnum.php`
- `app/Services/PedidoExclusaoService.php` (+ exceção de domínio em `app/Exceptions/`)
- `app/Filament/Resources/Pedidos/Pages/ViewPedido.php`
- `app/Filament/Resources/Pedidos/Schemas/PedidoInfolist.php`
- `app/Filament/Exports/PedidoExporter.php`
- `app/Filament/Pages/RelatorioExcecoesPedidos.php` (+ widgets em `app/Filament/Widgets/`)
- `app/Console/Commands/GerarBaseDemonstracao.php`, `app/Services/Demo/AnonimizadorBaseDemo.php`
- Migrations: validade do link na `empresas`; soft delete + exclusão + observação do cancelamento em `pedidos`; índice `pedido_venda_id`; `jobs`, `job_batches`, `exports`/`imports`/`failed_import_rows`
- Testes: `PedidoImpressaoAcessoTest`, `PedidoExclusaoServiceTest`, `PedidosTableExclusaoTest`, `ViewPedidoTest`, `PedidosTableTest`, `PedidosTableQueryCountTest`, `PedidosStatsOverviewTest`, `PedidoExporterTest`, `RelatorioExcecoesPedidosTest`, `AnonimizadorBaseDemoTest`, `ConfiguracaoPedidosTest`

**Alterados**
- `app/Models/Pedido.php` (SoftDeletes, `linkImpressao()`, escopos, relação `usuarioExcluiu`)
- `app/Http/Controllers/PDFController.php`, `app/Http/Controllers/PedidoController.php` (remover `destroy`; cancelamento com observação), `routes/web.php`
- `app/Services/PedidoStatusService.php` (observação do cancelamento; regra de "não pago" compartilhada)
- `app/Filament/Support/PedidoStatusActions.php` (`camposMotivo`)
- `app/Filament/Resources/Pedidos/PedidoResource.php`, `Pages/ListPedidos.php`, `Tables/PedidosTable.php`
- `app/Filament/Pages/PainelPedidos.php`, `app/Filament/Pages/PedidosDashboard.php`
- `app/Filament/Widgets/PedidosStatsOverview.php`, `MotivosCancelamentoChart.php`, `PedidosPorOrigemChart.php`, `FinanceiroStatsOverview.php`, `Concerns/InteractsComPeriodo.php`
- `app/Support/ContaMesa.php`, `app/Services/RelatorioTaxaServicoService.php`
- Views com o link do ticket (lista na F1.1); `resources/views/app/pedido/partials/list.blade.php` e `pedido/edit.blade.php` (observação no cancelamento)
- `app/Console/Kernel.php` (worker da fila de export)
- `database/seeders/PermissionSeeder.php`
- `tests/Feature/PedidoPDFQrCodeTest.php`

---

## Referências de mercado usadas

- Toast, relatórios de caixa e perdas (anulações por atendente/aprovador, itens removidos, descontos, pedidos não pagos): https://support.toasttab.com/en/article/Exceptions-Report-Overview
- Toast, busca e filtros de pedidos: https://doc.toasttab.com/doc/platformguide/platformSearchingAndSortingOrders.html
- Square, relatórios (item/categoria/complemento, descontos, cortesias e anulações, exportação): https://squareup.com/help/ca/en/topic/reports · resumo diário com comparativo: https://api.squareup.com/help/ca/en/article/8579-review-daily-sales-for-your-restaurant
- Lightspeed K-Series, relatórios de pedidos (abertos, cancelados, rascunhos não pagos, exportação): https://k-series-support.lightspeedhq.com/hc/articles/4403189420187 · Pulse (anulações, cortesias, descontos, comparativos): https://lightspeedhq.com/pos/restaurant/lightspeed-pulse · Advanced Insights: https://www.lightspeedhq.com/pos/restaurant/advanced-insights/
- Saipos (material de marketing): https://saipos.com/sistema/restaurante/relatorios-de-sistema-para-restaurante
