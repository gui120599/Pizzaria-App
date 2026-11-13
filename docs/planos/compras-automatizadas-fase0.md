# Compras Automatizadas — Fase 0 (diagnóstico)

> Leitura do código em 2026-11-13, HEAD `2a079107` (branch `prod-new`). Nenhum arquivo de código foi alterado.
> Banco: o MySQL do Sail não respondeu durante a leitura, então **nada foi conferido em dados** (contagens, roles, flags). Onde isso importa, está marcado como pergunta.

## Resumo executivo

A maior parte da infraestrutura que a spec pede **já existe com outro nome**: importador de XML, Distribuição DF-e com manifestação, de-para fornecedor×produto, condições de compra por fornecedor, contas financeiras com `MovimentacaoService`, parcelamento de contas a pagar e baixa com conta de origem. A Fase 1 deve **estender** essas peças (colunas novas e serviços que as orquestram), não criar tabelas paralelas.

Há quatro conflitos com o comportamento atual que precisam de decisão antes da Fase 1 (detalhes na seção 4):

1. Hoje o contas a pagar só nasce **depois** da confirmação da compra (entrada no estoque). A spec quer o título já na importação.
2. A spec quer entrada automática no estoque. Hoje a confirmação é humana: pede data de entrada e respeita a classificação fiscal.
3. Custo de aquisição: hoje ST, IPI, seguro e FCP-ST **ficam fora** do custo e do total. Isso é a pergunta Q4 do contador, ainda em aberto no plano de operações fiscais.
4. A decisão anterior "`Já pago` nunca vem marcado" seria revertida pela regra `automatica_na_emissao`.

---

## 1. Respostas 0.1–0.10

### 0.1 Módulo de compras hoje

- **Tabelas**: `compras` (prefixo `compra_*`) e `compra_itens` (prefixo `ci_*`). Ver `database/migrations/2026_06_22_140001_create_compras_table.php` e `..._140002_create_compra_itens_table.php`, mais as extensões `2026_08_02_000001` (`compra_xml_path`, **unique em `compra_chave_nfe`**), `2026_10_23_000001/2` (dados fiscais, `compra_valor_nf`, `compra_duplicatas_nf`, `compra_pagamentos_nf`, operação classificada, `ci_cfop`, `ci_valor_st/ipi/fcp_st`) e `2026_10_28_092341` (`compra_vencimento_origem`).
- **Status** (`app/Enums/CompraStatusEnum.php`): `rascunho`, `confirmada`, `cancelada`, `sem_efeito`. Origem (`compra_origem`): `manual` ou `xml`.
- **Models**: `app/Models/Compra.php`, `app/Models/CompraItem.php`. `Compra::lancamentos()` é HasMany ([Compra.php:124](app/Models/Compra.php:124)).
- **Filament**: `app/Filament/Resources/Compras/` (Resource, Schemas, Tables, RelationManagers, `Support/ImportarXmlAction.php`, `BuscarNfePorChaveAction.php`, `ConfirmarCompraAction.php`, `CorrigirDataEntradaAction`). Caixa de entrada SEFAZ em `app/Filament/Resources/SefazNotasRecebidas/` (`sefaz_notas_recebidas`, status `pendente/importada/ignorada`).
- **Importação de XML** (manual e automática): [NfeImportService.php:46](app/Services/Nfe/NfeImportService.php:46) faz o parse (`NfeXmlParser`), valida o destinatário e a duplicidade ([:154](app/Services/Nfe/NfeImportService.php:154)), resolve o fornecedor pelo CNPJ e o cria se ele não existir ([:179](app/Services/Nfe/NfeImportService.php:179)). Também classifica a operação fiscal (`CompraFiscalService`), grava o XML em `storage/app/compras_xml/Y/m/{chave}.xml` ([:235](app/Services/Nfe/NfeImportService.php:235)) e cria a compra **em rascunho**. Cada item é resolvido por de-para ([:213](app/Services/Nfe/NfeImportService.php:213)), depois por EAN ([:226](app/Services/Nfe/NfeImportService.php:226)); sem correspondência, fica com `ci_produto_id = null`. O importador nunca cria produto.
- **Confirmação** ([CompraService.php:87](app/Services/CompraService.php:87)): exige todos os itens vinculados, rateia acréscimos e gera as entradas via `EstoqueService::registrarEntrada` (custo médio WAC, lote/FEFO). Também atualiza o de-para ([:486](app/Services/CompraService.php:486)) e passa a compra a `confirmada`. Com lock (`travarCompra`).
- **Custo**: `ci_custo_unitario_compra = (vProd − vDesc do item) / qCom` (`NfeItem::custoUnitarioLiquido`). O rateio do cabeçalho é `frete + outros − desconto não distribuído` ([Compra.php:199](app/Models/Compra.php:199)), proporcional ao valor do item. `compra_valor_total = produtos + rateável` ([CompraService.php:34](app/Services/CompraService.php:34)). **ST, IPI, seguro e FCP-ST não entram** (achado D4 de `docs/planos/operacoes-fiscais-entrada-nfe.md`). O parser **não lê** `vFrete`/`vSeg`/`vOutro` por item, só no total ([NfeXmlParser.php:159](app/Services/Nfe/NfeXmlParser.php:159)).
- **Contas a pagar**: [CompraService.php:293](app/Services/CompraService.php:293) `gerarContaPagar()` **exige compra confirmada** (linha 300) e é idempotente porque a compra não pode já ter lançamentos (linha 304). Cria **um `Lancamento` por parcela** (`parcela_numero`/`parcela_total`/`prazo_pagamento_id`), faz o rateio por plano de despesa do **produto** (`lancamento_despesas`), abate créditos de devolução e trata "já pago" por parcela (`registrarPagamento` com conta e sessão).
- **Sugestão de pagamento**: `app/Services/Compras/SugestaoPagamentoCompra.php` monta parcelas pelas duplicatas do XML, que prevalecem, ou pelo prazo do fornecedor. `ja_pago` é **sempre false**, por decisão registrada (linha 21).

### 0.2 Fornecedores

- **Não existe tabela `fornecedores`.** Fornecedor é `Prestador` (`prestadores`) com `categoria = fornecedor` (`PrestadorCategoriaEnum::FORNECEDOR`). Há dois Resources sobre o mesmo model, `Fornecedores/` e `Prestadors/`.
- `cpf_cnpj` tem **índice, não unique** (`2026_08_02_000002`). O importador usa `where('cpf_cnpj')->first()`, então um CNPJ duplicado não é bloqueado.
- Campos: tipo (PF/PJ), razão social, nome fantasia, nome, cpf_cnpj, IE, contatos, endereço, observações, `cliente_id`, dados de colaborador e PIX. SoftDeletes.
- **Condições de compra já existem**: `fornecedor_condicoes_compra` (`fcc_*`, 1:1 com unique em `fcc_prestador_id`). Model em `app/Models/FornecedorCondicaoCompra.php`, com `fcc_prazo_pagamento_id`, `fcc_prazo_base` (entrada/emissão), `fcc_forma_pagamento`, `fcc_conta_financeira_id` e `fcc_data_entrada_regra`. Na UI é o componente compartilhado `app/Filament/Components/CondicoesCompraSection.php`. **Elas só sugerem valores**: nada é pago ou confirmado sozinho.

### 0.3 Produtos

- **EAN**: `produtos.produto_codigo_EAN` (string, default `'SEM GTIN'`, **sem índice**). Já é usado no casamento por EAN.
- **Unidade**: `produto_unidade_comercial` e `produto_unidade_estoque` (string nullable), mais `UnidadeProdutoEnum`.
- **Tipo**: `produto_tipo` (`ProdutoTipoEnum`: produzido/revenda/insumo/consumo interno). Insumo e produto ficam na mesma tabela `produtos`.
- **Plano de despesa**: `produto_plano_despesa_id` (é de onde vem o rateio do título).
- **Ficha técnica**: `ficha_tecnica_itens` (`fti_produto_id` → produto, `fti_insumo_id` → **produtos**), mais o model `FichaTecnica` (versionado, `FichaTecnicaVersaoObserver`). Não há sinônimos de produto.

### 0.4 MDe / Distribuição DF-e

- **Pacote** `nfephp-org/sped-nfe` ^5.2. Interface em `app/Services/Sefaz/Contracts/SefazClient.php` (consultarPorChave, consultarPorNsu, manifestarCiencia, confirmarOperacao); a implementação real é `SefazNfephpClient`, e os testes usam `tests/Support/FakeSefazClient.php`.
- **Certificado**: A1 enviado na página `ConfiguracaoSefaz` e guardado no disco `local` (`empresa_certificado_path`, senha com cast `encrypted`). É lido de forma preguiçosa em `SefazNfephpClient`.
- **Último NSU**: dois cursores em `empresas`. `empresa_sefaz_ultimo_nsu` é do polling automático e `empresa_sefaz_ultimo_nsu_revisao` é da sincronização da caixa de entrada.
- **Polling** ([SefazDistribuicaoService.php:244](app/Services/Sefaz/SefazDistribuicaoService.php:244)): reprocessa `sefaz_documentos_pendentes`, percorre o NSU, manifesta Ciência e baixa o XML completo; com ele, chama `NfeImportService::importar`. Comando `sefaz:importar-novas-notas`, **de hora em hora** ([Kernel.php:26](app/Console/Kernel.php:26)), só roda se `empresa_sefaz_auto_importacao_ativa = true` (o padrão é false). Controle de consumo indevido (656), prazo da Ciência (596) e confirmação da operação (210200) já estão tratados.
- **Eventos (cancelamento)**: **não são tratados**. `resEvento`/`procEventoNFe` são decodificados, mas o DTO não guarda `tpEvento`, e o polling e a sincronização descartam todo evento ([SefazDistribuicaoService.php:317](app/Services/Sefaz/SefazDistribuicaoService.php:317) e [:457](app/Services/Sefaz/SefazDistribuicaoService.php:457)).

### 0.5 Financeiro

- **`lancamentos`**: tipo (`TipoLancamento`: pagar/receber), `plano_despesa_id`/`plano_receita_id`, `comportamento` (snapshot), descrição, `favorecido_id` (→ prestadores), `cliente_id`, `numero_documento`, valor, vencimento, `data_pagamento`, status (`StatusLancamento`: pendente/parcial/pago/cancelado), `forma_pagamento`, **`compra_id` (já existe, indexado, sem unique)**, `prazo_pagamento_id`, `parcela_numero`, `parcela_total`, contrato, venda, sessão de caixa, recebível, repasse e adiantamento. "Vencido" é derivado.
- **Não existe `parcelas_pagamento`**: cada parcela é um `Lancamento`. As baixas ficam em `lancamento_pagamentos` (valor, data, forma, `conta_financeira_id`, `sessao_caixa_id`, cartão, maquininha, `conciliado_em`).
- **Não existe `LancamentoGateway`.** A baixa é `Lancamento::registrarPagamento()` ([Lancamento.php:333](app/Models/Lancamento.php:333)). Os eventos de `LancamentoPagamento` chamam `app/Services/Financeiro/BaixaFinanceira.php`: `preparar` valida conta, saldo e sessão; `movimentar` chama `MovimentacaoService::saida`. Estorno: `Lancamento::estornarPagamento()` ([:382](app/Models/Lancamento.php:382)). Status recalculado pela soma ([:402](app/Models/Lancamento.php:402)).
- **Enum de forma** (`app/Enums/FormaPagamento.php`): `dinheiro`, `pix`, `pix_cnpj`, `cartao_credito`, `cartao_debito`, `boleto`, `transferencia`, `compensacao`.
- **Plano de despesas**: `planos_despesas` (model `PlanoDespesa`, grupo→conta, SoftDeletes), com rateio em `lancamento_despesas`.
- **Policies**: `LancamentoPolicy` com `markAsPaid`, `reversePayment`, `compensar`, `liquidarRecebivel`, `conciliar`. `CompraPolicy::confirm`.

### 0.6 Contas Financeiras

**Implementado.** Tabelas `contas_financeiras` (tipo `TipoContaFinanceira`: caixa_pdv, caixa_interno, banco, conta_digital, outro; saldo, `permite_saldo_negativo`, banco/agência/conta, PIX) e `movimentacoes_financeiras` (append-only, estorno por `estorno_de_id`, `registrado_por`). Serviços:

- `app/Services/Financeiro/MovimentacaoService.php`: `saida`/`entrada`/`transferir`/`reverter`. Recusa saldo negativo ([:371](app/Services/Financeiro/MovimentacaoService.php:371)).
- `app/Services/Financeiro/CaixaPdvBridge.php`: `sessaoParaMovimentar` lança `FinanceiroException` se não houver sessão aberta, ou se houver mais de uma ([:42](app/Services/Financeiro/CaixaPdvBridge.php:42)).
- `BaixaFinanceira`, `ContabilizacaoFechamentoService`, `LiquidacaoRecebiveisService`.
- O componente `app/Filament/Components/ContaFinanceiraSelect.php` existe.

Já existe conciliação **manual** (`lancamento_pagamentos.conciliado_em`, ação "Marcar conciliada" em `MovimentacoesFinanceirasTable`), útil na Fase 4.

### 0.7 Estoque

`movimentacao_produtos` (campos `mov_*`, origem `MovimentacaoOrigemEnum::COMPRA`, morph `mov_referencia` → Compra, custo e saldo após), `estoque_lotes` (`lote_compra_item_id`). O único ponto de escrita é `app/Services/EstoqueService.php`. Uma compra entra no estoque **somente** em `CompraService::confirmar()`. Estorno de compra confirmada: `app/Services/Compras/CompraEstornoService.php` (núcleo da Fase 5 de operações fiscais).

### 0.8 Autenticação e permissões

- Guards: `web` (session, users) e um guard de influenciador. **Não existe guard `api`.**
- **Sanctum** v4.3.3 instalado. `User` usa `Laravel\Sanctum\HasApiTokens`. O único uso é a rota boilerplate `GET /api/user` (`routes/api.php:21`).
- **Passport não está instalado.** **`laravel/mcp` v1.0.0** está em `vendor/`, mas só como dependência de `laravel/boost` (require-dev, não commitado; `vendor/laravel/mcp` aparece como untracked).
- **Papéis**: `spatie/laravel-permission` + `bezhansalleh/filament-shield`. `Admin` é super_admin com `define_via_gate` (passa em todo `Gate`). Permissões seguem o formato `{verbo}:{recurso}` em `database/seeders/PermissionSeeder.php`. Pages e Widgets precisam de `HasPageShield`/`HasWidgetShield`.
- `canAccessPanel` ([User.php:42](app/Models/User.php:42)): o painel admin é liberado para `Admin, Gerente, Atendente, Caixa, Entregador` ([:31](app/Models/User.php:31)). Um papel novo `Agente` fica **fora do painel sem mudar nada**.

### 0.9 Infra

- **Scheduler**: `app/Console/Kernel.php` (estrutura do Laravel 10), não `routes/console.php`. Docs anteriores dizem que o cron do `schedule:run` em produção ainda estava **pendente de confirmação**.
- **Filas**: `QUEUE_CONNECTION=database`. Não há worker permanente; o Kernel só agenda `queue:work --stop-when-empty` para a fila de exportação de pedidos. Os jobs atuais rodam com `dispatchAfterResponse`/`dispatchSync`.
- **XMLs**: disco `local` (`storage/app/compras_xml/...`). O preview da caixa de entrada também fica no `local`. Os logs da SEFAZ vão para o canal `sefaz` (`storage/logs/sefaz.log`).
- `.env` local: `APP_ENV=local`, `DB_DATABASE=laravel`.

### 0.10 Convenções observadas

- **Prefixo de coluna** nas tabelas antigas e de estoque/compras (`compra_*`, `ci_*`, `fp_*`, `fcc_*`, `snr_*`). As tabelas mais novas do financeiro (`lancamentos`, `contas_financeiras`, `movimentacoes_financeiras`) **não usam prefixo**.
- **Enums**: maioria em `app/Enums/` na raiz, com subpastas `Financeiro/`, `Checklist/`, `Cozinha/` e `Ponto/`. Os novos implementam `HasLabel`/`HasColor`; os antigos usam `label()`/`cor()`.
- **Services**: `app/Services/<Modulo>/` (já existe `app/Services/Compras/`).
- **SoftDeletes** no lugar de booleano `ativo`.
- **Auditoria**: **não há auditoria genérica**. Os observers são por módulo (`PontoAuditoriaObserver`, `ChecklistAuditoriaObserver`, `CozinhaAuditoriaObserver`); compras e lançamentos não têm auditoria. O plano de operações fiscais previa `compra_eventos`, que não foi implementado.
- **Dinheiro**: `DB::transaction` + `lockForUpdate` (`travarCompra`, `PedidoStatusService`).
- **Testes**: **PHPUnit 11, sem Pest** (não existe `tests/Pest.php`). Fixtures de NF-e em `tests/Fixtures/` e `tests/Fixtures/nfe-entrada/` (inclui `venda-5102-duplicatas.xml` e `venda-5102-sem-pagamento.xml`). Certificado de teste: `tests/Fixtures/certificado-teste.pfx`.
- **Deploy**: produção não roda composer nem npm, então `vendor/` e `public/build/` vão no commit. O fluxo é push em `prod-new` e depois promoção para `producao`.

---

## 2. Mapeamento spec → código real

| Spec | Real | Observação |
|---|---|---|
| `fornecedores` / `Fornecedor` | `prestadores` / `Prestador` (categoria `fornecedor`) | `fornecedor_id` vira `prestador_id`/`fcc_prestador_id`/`fp_prestador_id` |
| `parametros_compra_fornecedor` | **`fornecedor_condicoes_compra`** (`FornecedorCondicaoCompra`) | Estender com colunas nullable `fcc_*` |
| `condicao` + `prazo_dias_padrao` | `fcc_prazo_pagamento_id` (`PrazoPagamento`, N parcelas) + `fcc_prazo_base` | Mais rico que a spec; dispensa as duas colunas |
| `usar_duplicatas_xml` | comportamento fixo (duplicatas prevalecem) | Decisão já tomada em condições de compra |
| `forma_pagamento` | `fcc_forma_pagamento` (`FormaPagamento`) | Existe |
| `conta_financeira_id` | `fcc_conta_financeira_id` | Existe |
| `regra_baixa`, `plano_despesa_id`, `autonomia_agente`, `tolerancia_preco_pct`, `observacoes` | **novas** `fcc_regra_baixa`, `fcc_plano_despesa_id`, `fcc_autonomia_agente`, `fcc_tolerancia_preco_pct`, `fcc_observacoes` | |
| `produto_fornecedor_vinculos` / `ProdutoFornecedorVinculo` | **`fornecedor_produtos`** / `FornecedorProduto` (`fp_*`) | Unique (prestador, código) já existe. FK de produto é `cascadeOnDelete` (a spec pede restrict; não dá para mudar sem alterar coluna existente) |
| `codigo_fornecedor`, `descricao_fornecedor`, `unidade_fornecedor`, `fator_conversao` | `fp_codigo_fornecedor`, `fp_descricao_fornecedor`, `fp_unidade_compra`, `fp_fator_conversao` | Existem |
| `ean`, `ultimo_custo_unitario`, `origem`, `confirmado_por` | **novas** `fp_ean`, `fp_ultimo_custo_unitario`, `fp_origem`, `fp_confirmado_por` | |
| `compras.chave_acesso` | `compra_chave_nfe` (unique) | Existe |
| `compras.xml_path` | `compra_xml_path` | Existe |
| `compras.origem` (`manual`/`dfe`) | `compra_origem` (`manual`/`xml`) | Acrescentar o valor `dfe`, sem mudar o schema |
| `compras.status_importacao` | derivado: `compra_status` + pendências abertas | Ver Q6 |
| `compra_itens.codigo_fornecedor/descricao/unidade/quantidade_fornecedor` | `ci_codigo_fornecedor`, `ci_descricao_fornecedor`, `ci_unidade_compra`, `ci_quantidade_compra` | Existem |
| `compra_itens.ean`, `vinculo_id`, `custo_aquisicao_total`, `custo_unitario_aquisicao` | **novas** `ci_ean`, `ci_vinculo_id` (→ `fornecedor_produtos`), `ci_custo_aquisicao_total`, `ci_custo_unitario_aquisicao` | |
| `lancamentos.compra_id` | `lancamentos.compra_id` | Existe |
| `parcelas_pagamento` | `lancamentos` (`parcela_numero`/`parcela_total`) | Parcela = lançamento |
| `LancamentoGateway` / "fluxo de baixa existente" | `Lancamento::registrarPagamento()` → `BaixaFinanceira` → `MovimentacaoService` | |
| `produtos.ean` | `produto_codigo_EAN` | Existe, sem índice |
| `produtos.cadastro_pendente_revisao`, `origem_cadastro` | **novas** `produto_cadastro_pendente_revisao`, `produto_origem_cadastro` | |
| plano de despesas | `planos_despesas` / `PlanoDespesa` | |
| `contas_financeiras.integracao`, `ultimo_extrato_em` | novas (Fase 4) | |
| `ImportarNfeEntradaJob` | `sefaz:importar-novas-notas` + `SefazDistribuicaoService::executarPolling` | Estender; não criar um Job de fila (sem worker) |
| `CompraImportacaoService::importar(xml)` | `NfeImportService::importar()` + novo orquestrador pós-importação | |
| `ProdutoMatcher` | lógica hoje privada em `NfeImportService` (:213, :226) | Extrair sem mudar comportamento |
| `MovimentacaoService`, `CaixaPdvBridge` | idem | Existem |
| `StatusCompraImportada::cancelada` | `CompraStatusEnum::CANCELADA` | |
| papéis `admin` / `gerente` / `agente` | `Admin` / `Gerente` / **novo** `Agente` | |
| testes Pest | PHPUnit 11 (`tests/Feature`) | Ver Q11 |

---

## 3. Lacunas e riscos

| # | Lacuna / risco | Impacto |
|---|---|---|
| R1 | O contas a pagar exige compra confirmada ([CompraService.php:300](app/Services/CompraService.php:300)) e o rateio usa o plano do **produto**, que não existe para item sem vínculo | Bloqueia RF07/RF09 na importação sem decisão (Q1) |
| R2 | Confirmar = entrada de estoque + data de entrada + checagem da operação fiscal (`garantirQueGeraEntrada`). Confirmar sozinho pode dar entrada de bonificação/devolução ou com data errada | RF15 (Q2) |
| R3 | ST, IPI, seguro e FCP-ST fora do custo e do `compra_valor_total`; conta a pagar menor que a nota. Pergunta Q4 ao contador em aberto | Regra 12 muda o CMV de **toda** compra por XML (Q3) |
| R4 | O parser não lê `vFrete`/`vSeg`/`vOutro` por item | O `CustoAquisicaoCalculator` precisa estender `NfeItem` (aditivo) |
| R5 | Eventos de cancelamento são descartados; o DTO não tem `tpEvento` | RF14 exige mudança no decoder e no polling |
| R6 | Polling e caixa de entrada usam **cursores NSU diferentes** sobre o mesmo stream. Risco de "consulta fora de sequência" (já anotado na integração) | Com a auto-importação ligada, as duas vias concorrem (Q8) |
| R7 | Baixa automática pode falhar por **saldo insuficiente** (`permite_saldo_negativo = false`), não só por caixa fechado | A spec não prevê; proponho a pendência `saldo_insuficiente` (Q7) |
| R8 | `BaixaFinanceira` grava `auth()->user()` como `registrado_por`; no scheduler fica null | Rastreabilidade das baixas automáticas (Q9) |
| R9 | Não há auditoria genérica; a spec supõe "Observers existentes" | Rastreio fica em `compra_pendencias` (resolvida_por/em, resolucao) e no log de chamadas MCP (Fase 3) |
| R10 | `cpf_cnpj` sem unique | Fornecedor duplicado pega o primeiro; pendência `fornecedor_novo` ajuda a revisar |
| R11 | `produto_codigo_EAN` sem índice | Desempenho do matcher; um índice seria alteração de tabela existente (não é coluna nova), por isso pergunto |
| R12 | Fase 3: o `HasApiTokens` do Passport conflita com o do Sanctum no `User`; não existe guard `api`; `laravel/mcp` só como dev-dependency e fora do Git; o Passport precisa de chaves em `storage/` no servidor | Planejar na Fase 3: Sanctum só serve hoje ao `/api/user` boilerplate |
| R13 | Sem worker de fila permanente | Implementar a importação como comando agendado, não como Job enfileirado |
| R14 | Cron do `schedule:run` em produção não confirmado | Sem ele, nada de automático roda |
| R15 | Working tree sujo em `prod-new` (composer/boost, `PedidoObserver`, relatório de sessão de caixa, `node_modules`) | Criar a branch carrega isso; commits devem listar arquivos por nome |
| R16 | Índices compostos em tabelas de nome longo estouram 64 caracteres no MySQL | Nomear índices explicitamente nas migrations novas |

---

## 4. Plano da Fase 1 ajustado

Princípio: **estender, não duplicar**. Toda alteração em tabela existente é coluna nova nullable (ou com default). O fluxo manual atual (upload, "Buscar por chave", confirmar com modal) continua funcionando igual.

### Migrations (todas aditivas)

1. `add_automacao_to_fornecedor_condicoes_compra`: `fcc_regra_baixa` string(30) nullable (null = `manual`), `fcc_plano_despesa_id` FK `planos_despesas` nullOnDelete, `fcc_autonomia_agente` string(20) nullable, `fcc_tolerancia_preco_pct` decimal(5,2) default 15, `fcc_observacoes` text nullable.
2. `add_aprendizado_to_fornecedor_produtos`: `fp_ean` string(14) nullable + índice, `fp_ultimo_custo_unitario` decimal(12,4) nullable, `fp_origem` string(10) nullable, `fp_confirmado_por` FK users nullable.
3. `create_compra_pendencias_table`: `compra_id`, `compra_item_id` nullable, `tipo`, `status`, `dados` json, `sugestao` json, `confianca` decimal(4,3), `justificativa`, `resolucao` json, `resolvida_por`, `resolvida_em`, timestamps, softDeletes. Índices nomeados (`status`, `tipo`) e (`compra_id`).
4. `add_custo_aquisicao_to_compra_itens`: `ci_ean`, `ci_vinculo_id` FK `fornecedor_produtos` nullOnDelete, `ci_custo_aquisicao_total` decimal(12,2), `ci_custo_unitario_aquisicao` decimal(12,4), além de `ci_valor_frete`/`ci_valor_seguro`/`ci_valor_outros` decimal(12,4) default 0 (ver R4).
5. `add_revisao_to_produtos`: `produto_cadastro_pendente_revisao` bool default false, `produto_origem_cadastro` string(10) nullable. (Fica para a Fase 3; pode sair da Fase 1.)

### Enums — `app/Enums/Compras/` (com `HasLabel`/`HasColor`)

`RegraBaixaCompra`, `AutonomiaAgente`, `TipoPendenciaCompra` (os da spec mais `saldo_insuficiente`, se Q7 aprovar), `StatusPendencia`, `OrigemVinculo`. `CondicaoPagamentoFornecedor` não entra (o prazo cobre). `StatusCompraImportada` vira um método derivado em `Compra`, se Q6 aprovar.

### Classes

| Classe | Tipo | O que faz |
|---|---|---|
| `app/Models/CompraPendencia.php` + factory | nova | |
| `FornecedorCondicaoCompra`, `FornecedorProduto`, `Compra` (`pendencias()`, `situacaoImportacao()`), `CompraItem` | alteradas | fillable/casts/relações |
| `app/Services/Compras/ProdutoMatcher.php` | nova | Lógica extraída de `NfeImportService` (de-para → EAN no de-para → EAN no produto). `NfeImportService` passa a usá-la, com o mesmo resultado |
| `app/Services/Compras/CustoAquisicaoCalculator.php` | nova | Regra 12 (depende de Q3) |
| `app/Services/Compras/PendenciaService.php` | nova | `abrir` (idempotente por compra+item+tipo), `resolverVinculo` (grava de-para, RF06), `descartar` |
| `app/Services/Compras/FinanceiroCompraService.php` | nova | `localizarLancamentoExistente` (regra 3), `gerarContasPagar` (reaproveita `SugestaoPagamentoCompra` + o núcleo de `CompraService::gerarContaPagar`; depende de Q1), `aplicarRegraBaixa` (`registrarPagamento` com conta/forma do fornecedor; `FinanceiroException` vira pendência) |
| `app/Services/Compras/CompraImportacaoService.php` | nova | Orquestra a importação: `NfeImportService::importar` → matcher/pendências → financeiro → regra de preço → conferência (Q2) |
| `app/Services/Compras/CancelamentoNfeService.php` | nova | Regra 15; usa `CompraEstornoService` quando couber |
| `NfeXmlParser`, `NfeItem` | alteradas (aditivo) | `vFrete`, `vSeg`, `vOutro` por item |
| `SefazResumoDocumento`, `SefazRespostaDecoder`, `SefazDistribuicaoService::processarResumo` | alteradas | Ler `tpEvento`; 110111 vai para `CancelamentoNfeService`; o polling chama `CompraImportacaoService` em vez de `NfeImportService` direto |
| `app/Console/Commands/ComprasImportarXml.php` | nova | `compras:importar-xml {arquivo}` |
| `config/compras.php` | nova | `agente.limite_confianca`, `custo.deduzir_creditos_icms`, janela/tolerância da regra 3 |
| `CondicoesCompraSection` | alterada | Campos novos, preset "Aplicar perfil", Select de plano |
| `app/Filament/Resources/FornecedorProdutos/` | nova | Resource do de-para. O `FornecedorProdutosRelationManager` atual continua |
| `PermissionSeeder` | alterada | `update:condicao_compra_automacao` (Admin) e permissões de pendência (Gerente), com a gramática atual |

### Testes (PHPUnit, `tests/Feature/Compras/`)

Usam `FakeSefazClient`, `Http::fake()` e fixtures em `tests/Fixtures/nfe-entrada/` (três XMLs novos, sintéticos e anonimizados: PIX à vista, boleto com 3 duplicatas, item desconhecido). Cobrem os cenários de B §11 da Fase 1: idempotência, PIX na Stone, Caixa PDV fechado, 3 duplicatas, a prazo sem duplicatas, lançamento pré-existente pago, dois candidatos, de-para/EAN, custo (frete/IPI/ST/desconto, rateio, soma = vNF), preço fora do padrão e cancelamento. Rodam em banco isolado (`sail exec -u sail -e DB_DATABASE=...`).

---

## 5. Perguntas para o Guilherme

**Bloqueiam a Fase 1:**

- **Q1 — Título na importação.** Hoje o contas a pagar só nasce ao confirmar a compra. A spec quer o título já na importação, mesmo com itens pendentes. *Recomendo*: gerar na importação, ratear pelo plano do produto quando houver e usar o `fcc_plano_despesa_id` do fornecedor para os itens sem vínculo. Se os vínculos mudarem depois, o rateio se ajusta na conferência enquanto o título estiver em aberto. Ok?
- **Q2 — Entrada automática no estoque.** Quando todos os itens estiverem resolvidos, o sistema confirma a compra sozinho? *Recomendo*: só quando a operação for `COMPRA` com confiança ALTA, com data de entrada pela regra do fornecedor (emissão) ou, sem regra, pela data da importação. Os demais casos ficam em rascunho para o "Confirmar" de 1 clique.
- **Q3 — Custo de aquisição.** Incluir ST, IPI, seguro e FCP-ST no custo e no total muda o CMV e o valor do título de **toda** compra por XML (inclusive upload manual), e é a pergunta Q4 do contador. Aplico em todas as compras por XML, só nas `dfe`, ou espero o contador?
- **Q4 — Reaproveitar tabelas.** Estender `fornecedor_condicoes_compra` e `fornecedor_produtos` em vez de criar `parametros_compra_fornecedor` e `produto_fornecedor_vinculos` (recomendado).
- **Q5 — "Já pago".** Antes você decidiu que "Já pago" nunca vem marcado. Confirma que, para fornecedor com `regra_baixa = automatica_na_emissao`, o sistema dá baixa sozinho?

**Podem ter resposta padrão:**

- **Q6** — Status de importação derivado (`rascunho` + pendências = "com pendências"; `confirmada` = "conferida"), sem coluna nova. Ok?
- **Q7** — Saldo insuficiente na conta do fornecedor numa baixa automática: abrir a pendência nova `saldo_insuficiente` e deixar o título aberto?
- **Q8** — A auto-importação (`empresa_sefaz_auto_importacao_ativa`) está ligada em produção? O cron do `schedule:run` está confirmado? A spec pede a cada 2h; hoje é de hora em hora. Mantenho de hora em hora?
- **Q9** — Baixas automáticas do scheduler ficam com `registrado_por = null` ou com um usuário técnico "Sistema"?
- **Q10** — Fluxo de Git: a spec pede a branch `feature/compras-automatizadas`, mas o seu fluxo é commitar em `prod-new` e promover. O working tree tem mudanças alheias (composer/boost, `PedidoObserver`, relatório de sessão de caixa). Crio a branch mesmo assim, só com os arquivos deste módulo?
- **Q11** — Testes em PHPUnit (o projeto não tem Pest). Ok?
- **Q12** — O upload manual de XML e o "Buscar por chave" também passam pela automação (pendências, financeiro, baixa), ou só a importação DF-e e o comando `compras:importar-xml`? *Recomendo* só DF-e e comando na Fase 1.
- **Q13** — Posso criar um índice em `produtos.produto_codigo_EAN` (não é coluna nova)?
- **Q14** — Fixtures: você manda XMLs reais para eu anonimizar, ou gero XMLs sintéticos a partir dos que já existem em `tests/Fixtures/nfe-entrada/`?

---

## 6. Decisões do Checkpoint 0 (2026-11-13)

| # | Decisão | Efeito no plano da Fase 1 |
|---|---|---|
| Q1 | **Autorizado**: o título a pagar nasce na importação | `FinanceiroCompraService` gera os lançamentos com a compra ainda em rascunho. Rateio pelo plano do produto e, nos itens sem vínculo, pelo `fcc_plano_despesa_id` do fornecedor. Ao confirmar, o rateio dos títulos **em aberto** é refeito com os vínculos finais. `CompraService::gerarContaPagar()` continua exigindo compra confirmada no fluxo manual. |
| Q2 | **Parâmetro no fornecedor**: entra no mesmo dia da emissão ou N dias depois | Colunas novas `fcc_entrada_automatica` (bool, default false) e `fcc_entrada_dias_apos_emissao` (smallint, default 0 = mesmo dia). Com a entrada automática ligada, a compra é confirmada sozinha com data = emissão + N, se todos os itens estiverem resolvidos e a operação for `COMPRA` com confiança ALTA. Se essa data ainda não chegou, o comando agendado `compras:conferir-automaticas` confirma no dia. Sem o parâmetro, nada muda: fica em rascunho para o "Confirmar" manual. A data nunca é futura nem anterior à emissão (mesma validação de `CompraService::validarDataEntrada`). |
| Q3 | **Aguardando o contador** | `CustoAquisicaoCalculator` é implementado, mas o que entra no custo é controlado por `config('compras.custo.incluir_tributos_nao_recuperaveis')`, **padrão `false`**: o custo do estoque continua exatamente como hoje (vProd − desconto + rateio de frete e outros). As colunas `ci_custo_aquisicao_*` são gravadas com o cálculo vigente. Quando o contador responder, basta virar a chave. O título usa o valor real da nota (duplicatas ou vNF), não o custo. |
| Q4 | Sem objeção registrada; segue a recomendação | Estender `fornecedor_condicoes_compra` e `fornecedor_produtos`. |
| Q5 | **Autorizado**: baixa automática para `regra_baixa = automatica_na_emissao` | `FinanceiroCompraService::aplicarRegraBaixa` registra o pagamento pela conta e forma do fornecedor, com data = emissão. |
| Q7 | **Pode trabalhar com saldo negativo** | A baixa automática não valida o saldo de contas banco/digitais/cofre (pode ficar negativo). Caixa PDV continua exigindo sessão aberta e dinheiro na gaveta; se faltar, o título fica aberto e abre a pendência `caixa_pdv_fechado`. Não muda a configuração `permite_saldo_negativo` das contas, nem a baixa manual. |
| Q8 | Cron do `schedule:run` **ativo** em produção | A ativação depende de `empresa_sefaz_auto_importacao_ativa` estar ligada (a confirmar no servidor). |

Respostas padrão assumidas (sem objeção): Q6 status derivado, sem coluna nova; Q9 baixas automáticas com `registrado_por = null` e observação "Baixa automática (compras)"; Q11 PHPUnit; Q12 automação só para DF-e e `compras:importar-xml`; Q14 fixtures sintéticas a partir de `tests/Fixtures/nfe-entrada/`. Q10: commits direto em `prod-new` (fluxo atual do projeto). Q13: sem índice novo em `produtos` por enquanto.

---

## 7. Fase 1 — implementada (Checkpoint 1, 2026-11-13)

**Banco (aditivo):** `2026_11_13_100001..100005`
- colunas `fcc_*` de automação (regra de baixa, plano padrão, autonomia, tolerância, entrada automática + dias, observações);
- `fp_ean`, `fp_ultimo_custo_unitario`, `fp_origem`, `fp_confirmado_por`;
- `compras.compra_importacao_automatica`;
- `ci_ean`, `ci_vinculo_id`, `ci_valor_frete/seguro/outros`, `ci_custo_aquisicao_total`, `ci_custo_unitario_aquisicao`;
- tabela `compra_pendencias`;
- permissões `configurar:automacao_compra` e `resolver:pendencia_compra`.

**Código:**
- enums em `app/Enums/Compras/`;
- serviços em `app/Services/Compras/`: `ProdutoMatcher`, `CustoAquisicaoCalculator`, `PendenciaService`, `FinanceiroCompraService`, `ConferenciaCompraService`, `CompraImportacaoService`, `CancelamentoNfeService`, `UsuarioAutomacaoCompras`;
- comandos `compras:importar-xml`, `compras:conferir-automaticas` (00:30) e `compras:baixar-vencimentos` (06:10);
- Filament: campos de automação na seção "Condições de compra" e resource "De-para de fornecedores".

**Desvios da spec:**
- Tipo de pendência extra `operacao_fiscal`: nota que não é COMPRA não gera título nem entra sozinha.
- Usuário técnico "Automação de Compras" (`automacao.compras@sistema.local`, sem papel), criado sob demanda. Motivo: `movimentacao_produtos.mov_user_id` é obrigatório e o agendador roda sem usuário.
- `prazo_dias_padrao`/`condicao`/`usar_duplicatas_xml` não foram criados: o prazo do fornecedor (`PrazoPagamento`) e a regra "duplicatas prevalecem" já cobrem.
- Testes em PHPUnit.

---

## 8. Fase 2 — implementada (2026-11-13)

**Telas:**
- Estoque › **Caixa de entrada de compras**: abas por tipo com contador; ações Resolver (produto + fator), Escolher lançamento, Aprovar sugestão (individual e em lote), Marcar como resolvida e Descartar; widget com o resumo.
- Financeiro › **Pagamentos a confirmar**: individual com valor editável, ou em lote.
- Estoque › **Revisão de produtos**: só Admin; produto pendente fica fora do seletor de insumo da ficha.

**Permissões:** `resolver:pendencia_compra` e `confirmar:pagamento_compra` (Gerente, via `PermissionSeeder`); `revisar:produto_automacao` (Admin).

---

## 9. Fase 3 — servidor MCP do agente (2026-11-13)

**Implementação:**
- `laravel/mcp` ^1.2 e `laravel/passport` ^13.10 em `require`.
- `symfony/psr-http-message-bridge` fixado na linha 7: a v8 exige PHP 8.4.1 e a produção exige 8.3.
- O `User` usa o `HasApiTokens` do Passport. O Sanctum continua instalado, mas não tinha uso além da rota padrão `/api/user`.
- Guard `api` (passport).
- Servidor `App\Mcp\Servers\ComprasServer` com 9 tools. Nenhuma faz baixa, estorno, parâmetros ou exclusão.
- Middleware `GarantirAgenteCompras` exige o papel **Agente**. A checagem é pelo papel, não por Gate, porque o Admin passaria pelo `Gate::before`.
- Toda chamada vai para `storage/logs/agente-compras-*.log`.
- Rodadas ficam em `agente_execucoes` (Estoque › Agente de compras), com a ação "Revogar acesso do agente".

**Ativação em produção (servidor):**
1. `git pull` e `php artisan migrate`: tabelas `oauth_*`, `agente_execucoes` e o papel Agente.
2. `php artisan passport:keys`: gera `storage/oauth-*.key`, que fica fora do Git. Confira a permissão 600.
3. `php artisan compras:criar-agente`: mostra login e senha do usuário "Agente Compras". Guarde.
4. `php artisan config:cache`, `route:cache` (se usar) e resetar o OPcache.
5. Teste: `https://<domínio>/.well-known/oauth-authorization-server` deve responder JSON.

**Cadastrar o conector no Claude** (Configurações › Conectores › Adicionar conector personalizado):
- Nome: `PizzariaAPP Compras`.
- URL: `https://<domínio-do-pizzariaapp>/mcp/compras`.
- Ao conectar, o Claude abre o login do PizzariaAPP: entre com o usuário **Agente Compras** (não com o seu) e clique em **Autorizar**.

**Tarefa agendada** (uma vez por dia, ~9h40; opcional 16h40). Prompt:

> Você é o assistente de compras do Empório da Pizza. Use o conector "PizzariaAPP Compras".
> 1. Chame `listar_pendencias`.
> 2. Para cada `produto_nao_identificado` ou `conversao_unidade`:
>    - leia `fornecedor.observacoes`: são orientações do usuário para aquele fornecedor (unidades, conversões, produtos proibidos) e prevalecem sobre a sua inferência;
>    - consulte `historico_vinculos_fornecedor` (do fornecedor da pendência) e `buscar_produtos` (com palavras da descrição e o EAN, se houver);
>    - proponha com `propor_vinculo` o produto existente mais provável, com o fator de conversão (1 unidade da nota = X unidades de estoque do produto), confiança honesta de 0 a 1 e justificativa curta;
>    - na dúvida entre dois produtos, use confiança baixa;
>    - só use `propor_novo_produto` se nenhum produto existente servir.
> 3. Para `lancamento_ambiguo`: veja os candidatos em `dados.candidatos` e use `sugerir_lancamento` com o mais provável, explicando o porquê.
> 4. Não tente resolver outros tipos; apenas cite-os no resumo.
> 5. Chame `listar_pagamentos_a_confirmar` (7 dias) e termine com `registrar_resumo`, em português e curto, cobrindo:
>    - pendências tratadas (executadas e sugeridas);
>    - o que aguarda aprovação;
>    - pagamentos a confirmar nos próximos 7 dias (quantidade e valor);
>    - alertas de preço e outros tipos em aberto.

**Recomendação da spec:** deixar todos os fornecedores com autonomia **"sugerir"** por 2 semanas e passar para "vincular" nos que acertarem. **Desligar o agente:** "Revogar acesso do agente" na tela de rodadas.

---

## 10. Fase 4 — extrato do Banco do Brasil e conciliação de boletos (2026-11-13)

**Implementação:**
- `config/bancos.php` + variáveis `BB_*` no `.env` (exemplo em `.env.example`).
- `BancoBrasilExtratoClient`: OAuth client credentials com token em cache e extrato paginado. Ignora as linhas de saldo; mTLS é opcional.
- `ImportacaoExtratoService`: grava em `extrato_bancario_itens` com `hash_unico` (reimportar não duplica) e concilia os débitos pendentes.
- `ConciliacaoBoletoService`:
  - parcela única com valor exato e vencimento entre o débito − 5 e + 3 dias → baixa na data do extrato;
  - 2 ou mais parcelas → débito "ambíguo" e pendência `pagamento_a_confirmar` com as candidatas (o agente pode sugerir; a escolha na caixa de entrada concilia);
  - saída já registrada à mão → só concilia;
  - valor diferente (juros/multa) → "sem correspondência", para conciliar à mão com o valor real.
- Comando `financeiro:importar-extrato-bb` (diário 07:30, D-3 até hoje) e tela Financeiro › **Extrato bancário** (só Admin: conciliar com parcela, ignorar, importar agora).
- Conta financeira ganhou "Importar extrato por API".
- "Pagamentos a confirmar" também mostra parcelas "por conciliação" vencidas há mais de 3 dias.

**⚠ Não confirmado na documentação oficial:** o portal do BB não pôde ser lido (renderiza só com JavaScript). Os padrões abaixo vêm da documentação conhecida da API Extratos v1. Todos são sobrescrevíveis no `.env`, sem mudar código:
- URLs de token e da API (homologação e produção);
- escopo `extrato-info`;
- parâmetro `gw-dev-app-key`;
- path `/conta-corrente/agencia/{agencia}/conta/{conta}`;
- datas DDMMAAAA sem zero à esquerda;
- campos `listaLancamento`, `dataLancamento`, `valorLancamento`, `indicadorSinalLancamento`, `textoDescricaoHistorico`, `numeroDocumento` e `numeroPaginaProximo`.

**Checklist de ativação em produção:**
1. **Portal Developers BB** (responsável pela conta PJ):
   - criar a aplicação com a API Extratos;
   - anotar `client_id`, `client_secret` e `developer_application_key` de homologação;
   - conferir na documentação as URLs, o escopo, o nome do parâmetro da chave e se a produção exige certificado mTLS.
2. **Homologação:**
   - no `.env`, preencher `BB_CLIENT_ID`, `BB_CLIENT_SECRET`, `BB_DEVELOPER_APP_KEY` e `BB_AMBIENTE=homologacao` (e `BB_URL_*` se diferirem);
   - rodar `php artisan config:cache`;
   - na conta "Banco do Brasil" (Financeiro › Contas), preencher agência e conta e marcar "Importar extrato por API";
   - rodar `php artisan financeiro:importar-extrato-bb --de=AAAA-MM-DD` e conferir as linhas em Financeiro › Extrato bancário.
3. **Produção:**
   - pedir no portal as credenciais de produção;
   - se exigir mTLS, colocar o certificado em `storage/app/...` e preencher `BB_MTLS_CERT`, `BB_MTLS_KEY` e `BB_MTLS_SENHA`;
   - definir `BB_AMBIENTE=producao` e rodar `config:cache`.
4. **Fornecedores de boleto** (Estoque › Fornecedores › Condições de compra): regra de baixa **"Por conciliação com o extrato"** e conta de origem **Banco do Brasil**.
5. **Acompanhar a primeira semana:**
   - débitos "sem correspondência" e "ambíguos" no Extrato bancário;
   - parcelas "por conciliação" vencidas em Pagamentos a confirmar.
