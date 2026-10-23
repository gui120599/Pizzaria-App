# Classificação da operação nas notas de entrada (NF-e) e seus efeitos em estoque e financeiro

> Auditoria e plano de 23/10/2026, sobre o HEAD `ad8bce6d` (branch `prod-new`).
> Status: **Fases 0 e 1 em implementação (commit a registrar abaixo)**. Fases 2 em diante aguardam as respostas da seção 11.
> Este documento não é parecer tributário. Os pontos fiscais e contábeis marcados com ⚖️ precisam ser validados pelo contador.

Legenda das afirmações:
- **[código]**: fato verificado no código, com o arquivo e a linha.
- **[dados]**: fato verificado no banco de desenvolvimento ou nos XMLs armazenados.
- **[fonte]**: regra sustentada por norma ou documentação externa (lista na seção 5).
- **[recomendação]**: decisão de arquitetura proposta neste plano.

---

## 1. Resumo executivo

Hoje, qualquer NF-e importada vira uma **Compra em rascunho**. Ao confirmar, o sistema sempre dá **entrada de estoque** de todos os itens e, se o toggle "Gerar conta a pagar" estiver ligado (ele vem ligado), sempre gera **título a pagar**. O sistema não lê o CFOP, a natureza da operação, o tipo da nota (entrada ou saída), a finalidade, as notas referenciadas, o destinatário nem as formas de pagamento do XML. Por isso, ele não distingue compra de bonificação, doação, comodato, vasilhame, devolução ou nota complementar. Quem distingue é o usuário, de cabeça, e o sistema não registra essa decisão.

O lado bom é que a base é sólida. O `EstoqueService` é o ponto único de escrita (WAC + FEFO, com lock). O `CompraService` concentra a confirmação e as parcelas. A importação já está separada do processamento (o rascunho fica parado até alguém clicar em "Confirmar"). Já existem devolução com crédito, correção de movimentação com replay do WAC (`CorrecaoEstoqueService`) e uma caixa de entrada da SEFAZ com a ação "Ignorar".

Recomendação: **não criar um pipeline paralelo**. A proposta é:
1. Capturar no parser os dados fiscais que hoje são descartados e gravá-los na Compra e nos itens.
2. Criar um **classificador puro** que sugere a operação com critérios explícitos e grau de confiança. O usuário confirma ou corrige, com motivo.
3. Fazer a **matriz de efeitos** ficar num único lugar, um enum com `efeitos()`. O `CompraService::confirmar()` e o modal de confirmação passam a seguir essa matriz.
4. Registrar um **histórico de processamento** auditável por compra.

A **primeira etapa recomendada** (Fases 0 e 1) corrige riscos atuais de integridade e passa a capturar e exibir a classificação **sem mudar nenhum efeito** em estoque ou financeiro. Com isso, a regra é validada com notas reais e com o contador antes de passar a decidir alguma coisa.

---

## 2. Arquitetura atual do módulo de compras

### 2.1 Componentes

| Camada | Arquivo | Papel |
|---|---|---|
| Model | `app/Models/Compra.php` | Cabeçalho (`compra_*`), SoftDeletes, `itens()`, `lancamentos()`, `devolucoes()`, `movimentacoes()` (morph em `movimentacao_produtos`) |
| Model | `app/Models/CompraItem.php` | Item (`ci_*`): quantidade/unidade de compra, `ci_fator_conversao`, custo unitário, rateio, lote/validade/marca. `quantidadeEstoque()` e `custoUnitarioEstoque()` |
| Parser | `app/Services/Nfe/NfeXmlParser.php` | Lê o XML: chave, número, série, emissão, emitente, totais (vProd, vFrete, vDesc, vOutro, vNF) e itens (cProd, xProd, EAN, uCom, qCom, vUnCom, vProd, vDesc, rastro) |
| Importação | `app/Services/Nfe/NfeImportService.php` | Barra chave duplicada, resolve fornecedor por CNPJ (cria se não houver), resolve produto por de-para → EAN → pendente, grava o rascunho e o XML em `storage/app/compras_xml/AAAA/MM/` |
| SEFAZ | `app/Services/Sefaz/SefazDistribuicaoService.php` | Busca por chave, polling por NSU (`executarPolling`), caixa de entrada (`sefaz_notas_recebidas`), manifestação de Ciência e Confirmação |
| Confirmação | `app/Services/CompraService.php::confirmar()` (`:48-112`) | Rateia frete+outros−desconto, chama `EstoqueService::registrarEntrada` por item (origem `COMPRA`), vincula lote, atualiza o de-para e marca `CONFIRMADA` |
| Financeiro | `CompraService::gerarContaPagar()` (`:249-373`) | 1 `Lancamento` (Pagar) por parcela, rateio por plano de despesa do produto, abate créditos de devolução e registra "já pago" |
| Estoque | `app/Services/EstoqueService.php` | Ponto único: `registrarEntrada` (WAC, lote), `registrarSaida` (FEFO), `registrarAjuste`, com `lockForUpdate` no produto |
| Correção | `app/Services/CorrecaoEstoqueService.php` | Corrige ou exclui uma movimentação com **replay** do WAC e dos lotes (`aplicar`, `aplicarExclusao`) |
| Devolução | `app/Services/CompraDevolucaoService.php` | Saída `DEVOLUCAO_COMPRA` + `PrestadorCredito`, abatido na **próxima** compra do fornecedor |
| UI | `app/Filament/Resources/Compras/**` | `CompraForm`, `ComprasTable`, `ItensRelationManager`, ações `ConfirmarCompra`, `RegistrarDevolucao`, `CorrigirDataEntrada`, `ImportarXml`, `BuscarNfePorChave`, `ImprimirDanfe` |
| UI SEFAZ | `app/Filament/Resources/SefazNotasRecebidas/**` | Caixa de entrada: Ver NF, Importar, Confirmar recebimento e importar, Ignorar |
| Autorização | `app/Policies/CompraPolicy.php` | `confirm:compra`, `registrar:devolucao_compra`, `corrigir:data_entrada_compra` |

### 2.2 Fluxos (respostas às perguntas 1 a 12 da Etapa A)

1. **Criação** [código]: manual em `CreateCompra` (origem `manual`) ou por XML (`ImportarXmlAction`, `BuscarNfePorChaveAction`, `sefaz:importar-novas-notas`, caixa de entrada), sempre via `NfeImportService::importar()`, com origem `xml`. Todas as entradas terminam em **rascunho**.
2. **Armazenamento** [código]: o cabeçalho fica em `compras`, os itens em `compra_itens` e o XML bruto em disco (`compra_xml_path`). Não há colunas para natOp, tpNF, finNFe, CFOP, CST/CSOSN, tributos, NFref, pagamentos ou duplicatas.
3. **Identificação da operação** [código]: **não existe**. O parser não lê `ide/natOp`, `ide/tpNF`, `ide/finNFe`, `ide/NFref`, `det/prod/CFOP`, `dest`, `pag`, `cobr` nem `ICMSTot/vST|vIPI|vSeg|vFCPST`. O emitente é lido (CNPJ e dados cadastrais). O destinatário **não é conferido** contra `empresas.empresa_cnpj`.
4. **Vínculo de produtos** [código]: `fornecedor_produtos` (unique por fornecedor + cProd) → EAN (`produto_codigo_EAN`) → pendente. Na confirmação, `atualizarDePara()` aprende o vínculo e o fator.
5. **Quantidades e custos** [código]: estoque = `ci_quantidade_compra × ci_fator_conversao`. O custo do item é `(vProd − vDesc do item) / qCom` (`NfeItem::custoUnitarioLiquido`). O rateio do cabeçalho é `frete + outros − desconto não distribuído` (`Compra::valorRateavel`, `NfeParseada::valorDescontoParaRateio`), proporcional ao valor do item. **ICMS-ST, FCP-ST, IPI, seguro e II ficam fora do custo e do total.**
6. **Estoque** [código]: `EstoqueService::registrarEntrada` por item, com origem `COMPRA`, referência morph à Compra, data = data de entrada e lote quando `rastreiaLote()`.
7. **Contas a pagar** [código]: `gerarContaPagar` cria N títulos. A base sugerida é `compra_valor_total`, e o modal permite editar as parcelas. O toggle vem com `default(true)` (`ConfirmarCompraAction.php:71-75`).
8. **Classificação** [código]: inexistente. A única distinção é a origem (`manual` ou `xml`).
9. **Duplicidade** [código]: na importação, existe o unique `compras_compra_chave_nfe_unique` com checagem prévia. No financeiro, `gerarContaPagar` faz um `exists()` sem lock (o unique em `lancamentos.compra_id` caiu com o parcelamento). No estoque, `confirmar()` checa `isRascunho()` **sem lock**.
10. **Cancelamento, estorno, devolução e correção** [código]: `CompraStatusEnum::CANCELADA` existe, mas **nenhum código o usa**. Não há estorno de compra confirmada. A devolução existe (saída + crédito). A correção de quantidade ou custo existe por item (`ItensRelationManager::acaoCorrigirMovimentacao` → `CorrecaoEstoqueService`). A correção de data existe. Um evento de cancelamento da NF-e vindo da SEFAZ é **ignorado** (`processarResumo`: `if ($item->isEvento) return 'puladas'`).
11. **Participantes** [código]: `NfeImportService`, `CompraService`, `EstoqueService`, `CompraDevolucaoService`, `CorrecaoEstoqueService`, `SefazDistribuicaoService` e os eventos de `LancamentoPagamento` → `BaixaFinanceira` (movimenta a conta financeira). Não há observer de Compra. Tudo roda em `DB::transaction`.
12. **Tenant** [código]: single-tenant. São 21 chamadas a `Empresa::first*()` e nenhum `empresa_id` nos models. A memória do projeto registra que o multi-tenant ficará num **produto separado** ("RazelFood Menu Manager"). Este plano **não** introduz `tenant_id` [recomendação], mas mantém as regras em classes puras, portáveis para o SaaS.

### 2.3 Dados reais (banco dev, espelho de produção)

[dados]:
- Compras: 289 confirmadas (216 por XML, 73 manuais), 5 rascunhos (3 excluídos com soft delete, 1 deles por XML).
- Caixa SEFAZ: 207 importadas, 2 ignoradas, 1 pendente. A empresa é **Simples Nacional, GO**. A auto-importação está **desligada** no dev.
- XMLs disponíveis no dev (12, a maioria fixtures). As notas reais de fornecedores vêm com **tpNF=1** (saída do emitente), CFOP **5102/5405** e natOp "Venda...", ou seja, sob a ótica do emitente. Uma delas tem **tPag=90 (sem pagamento)** com vNF de R$ 239,38: forte indício de bonificação ou remessa, que hoje seria confirmada como compra. Outra tem 3 duplicatas (`cobr/dup`) que o sistema não usa.
- Total da compra × vNF da SEFAZ: em 1 das 206 compras ligadas à caixa de entrada, a diferença é de R$ 26,06 (tributo ou seguro não importado).

---

## 3. Diagnóstico: limitações e inconsistências

| # | Achado | Evidência | Impacto |
|---|---|---|---|
| D1 | Toda NF confirmada dá **entrada** de estoque, seja qual for a operação | `CompraService::confirmar()` `:73-102` | Bonificação vira custo cheio; comodato e vasilhame inflam o estoque; nota de devolução emitida pelo fornecedor (tpNF=0, finNFe=4) entraria como **nova entrada** |
| D2 | Conta a pagar sugerida para qualquer nota (toggle ligado por padrão) | `ConfirmarCompraAction.php:71-75` | Obrigação inexistente em bonificação, doação e remessa, se o usuário não desligar |
| D3 | CFOP, natOp, tpNF, finNFe, NFref, dest, pag e cobr não são lidos nem guardados | `NfeXmlParser::parse()` | Sem base para classificar e sem rastreabilidade fiscal |
| D4 | ST, FCP-ST, IPI, seguro e II ficam fora do custo e do total | `NfeXmlParser.php:58-62`, `CompraService::recalcularTotais` | Custo (WAC) e conta a pagar **menores** que a nota. Para o Simples Nacional, esses tributos não são recuperáveis ⚖️ |
| D5 | Destinatário não é validado | `NfeImportService::importar()` | Upload manual aceita XML de outra empresa. Pela distribuição DFe, chegam também notas em que a empresa é transportadora ou consta em `autXML` |
| D6 | `DeleteBulkAction` sem filtro de status e `CompraPolicy::delete` sem checar status | `ComprasTable.php:113-115`, `CompraPolicy.php:35-38` | Compra **confirmada** pode ser excluída (soft delete) em massa, deixando movimentações e títulos órfãos |
| D7 | `confirmar()` sem lock na compra | `CompraService.php:50-55` | Duplo clique ou duas abas podem dar entrada **em dobro** (o lock é só por produto) |
| D8 | Reimportar uma chave cujo rascunho foi excluído falha | `garantirNaoDuplicada` não usa `withTrashed`; o unique do banco inclui as linhas excluídas | O usuário vê "já foi importada" e não acha a compra. No polling, a `QueryException` não é capturada (`processarResumo` só pega `NfeXmlInvalido`/`Validation`) |
| D9 | Cancelamento da NF-e depois da importação não é detectado | `processarResumo` pula eventos | Compra confirmada de nota cancelada continua com estoque e título |
| D10 | Não há estorno de compra confirmada; `CANCELADA` não é usado | grep | Correção só por devolução (que gera crédito, não cancelamento) ou por correção item a item |
| D11 | Devolução: a saída é valorada pelo **custo médio vigente**, mas o crédito usa o **custo original da compra** | `CompraDevolucaoService.php:72-94` × `EstoqueService::registrarSaida` (`:106`) | Diferença entre o valor que sai do estoque e o crédito, sem registro ⚖️ |
| D12 | Devolução sem vínculo com o documento fiscal (chave da NF-e de devolução) | `compra_devolucoes` não tem coluna de chave | Sem rastreabilidade fiscal da devolução |
| D13 | Crédito de devolução só abate a **próxima** compra, nunca título em aberto da própria compra | `gerarContaPagar` `:294-317` | Pode ser a regra desejada, mas precisa de decisão (Q7) |
| D14 | Sem trilha de auditoria estruturada | Não há pacote de auditoria; só texto em `compra_observacao`, `compra_user_id` e `compra_confirmada_em` | Não dá para responder "quem decidiu que era bonificação e por quê" |
| D15 | Polling importa automaticamente qualquer resumo e manifesta Ciência | `processarResumo` | Hoje é seguro porque tudo vira rascunho. Com a classificação, deve importar **e classificar**, nunca processar |

**Duplicidade de caminhos** [código]: a criação de rascunho tem um caminho só (`NfeImportService`). A confirmação também (`ConfirmarCompraAction` → `CompraService`, a mesma action na tabela e na página). O ponto fraco não é a duplicidade, é a **ausência de regra**.

---

## 4. Classificação proposta e matriz de efeitos

### 4.1 Princípios

- **Perspectiva** [fonte + recomendação]: o XML recebido descreve a operação sob a ótica do **emitente**. Quando a empresa é o destinatário: `tpNF=1` (saída do emitente) é **entrada física** para nós; `tpNF=0` (entrada do emitente, como a nota que o fornecedor emite ao receber nossa devolução) é **saída ou retorno** para nós e nunca pode virar entrada. O CFOP do item é o do emitente (5xxx/6xxx). O "CFOP espelho" de entrada (1xxx/2xxx) é **derivado** só para exibição e relatório ⚖️.
- **Nenhum sinal isolado decide** [recomendação]: a classificação combina tpNF + finNFe + CFOP de **cada item** + NFref + tPag/duplicatas + emitente/destinatário + histórico do fornecedor. Conflito entre sinais rebaixa a confiança.
- **Granularidade por item** [recomendação]: a mesma nota pode trazer itens 5102 (venda) e 5910 (bonificação). O efeito é calculado **por item**. O cabeçalho recebe a operação predominante, ou `MISTA`.
- **Importar ≠ processar** [código + recomendação]: isso já é verdade (rascunho). A classificação acontece na importação. Os efeitos só acontecem na confirmação humana.
- **Confiança** [recomendação]: `ALTA` (pode confirmar direto), `MEDIA` (exige o usuário marcar "conferi a operação") e `BAIXA` ou `NAO_PROCESSAVEL` (exige escolher a operação ou bloqueia).

### 4.2 Enum de operação (`OperacaoEntradaEnum`)

| Operação | Uso |
|---|---|
| `COMPRA` | Aquisição onerosa de mercadoria, insumo, revenda ou uso e consumo |
| `BONIFICACAO` | Mercadoria sem cobrança, vinculada a relação comercial com o fornecedor |
| `DOACAO` | Recebimento gratuito sem contrapartida comercial |
| `AMOSTRA_GRATIS` | Amostra |
| `DEVOLUCAO_AO_FORNECEDOR` | Documento do fornecedor (tpNF=0/finNFe=4) que acoberta uma devolução **nossa** |
| `COMPLEMENTAR` | finNFe=2: complemento de preço ou imposto |
| `AJUSTE` | finNFe=3 |
| `COMODATO` | Bem recebido em comodato (ex.: freezer, chopeira) |
| `VASILHAME` | Entrada ou retorno de vasilhame/sacaria (barril, engradado, botijão) |
| `CONSERTO` | Remessa ou retorno de conserto |
| `TRANSFERENCIA` | Mesma raiz de CNPJ (futuro multi-filial) |
| `OUTRA` | 5949/6949 e afins: decisão manual |
| `NAO_PROCESSAVEL` | Destinatário não é a empresa, emitente é a própria empresa, nota cancelada ou denegada |

`MISTA` é só um rótulo de cabeçalho. Os efeitos vêm dos itens.

### 4.3 Regras do classificador (ordem de avaliação)

| Ordem | Condição | Resultado | Confiança |
|---|---|---|---|
| 1 | `dest` ≠ CNPJ da empresa | `NAO_PROCESSAVEL` ("documento de outra empresa ou em que somos transportador/autXML") | — (bloqueia) |
| 2 | Emitente = CNPJ da empresa | `NAO_PROCESSAVEL` (nota própria; não é entrada de terceiro) | — |
| 3 | Protocolo com cStat ≠ 100, ou evento de cancelamento conhecido | `NAO_PROCESSAVEL` | — |
| 4 | `finNFe=4` e `tpNF=0` | `DEVOLUCAO_AO_FORNECEDOR`, que exige NFref apontando para uma compra nossa | ALTA se a NFref casar com uma Compra; senão BAIXA |
| 5 | `finNFe=4` e `tpNF=1` | Devolução de venda **feita por um cliente nosso a nós**? Não se aplica a quem vende por NFC-e ao consumidor → `OUTRA` | BAIXA |
| 6 | `finNFe=2` | `COMPLEMENTAR` | MEDIA (efeito manual) |
| 7 | `finNFe=3` | `AJUSTE` | ALTA (sem efeito) |
| 8 | Raiz do CNPJ do emitente = raiz da empresa, ou CFOP 5151-5159/5408/5409 (6xxx) | `TRANSFERENCIA` | MEDIA |
| 9 | Item com CFOP x908/x909 | `COMODATO` | ALTA |
| 10 | Item com CFOP x920/x921 | `VASILHAME` | ALTA |
| 11 | Item com CFOP x915/x916 | `CONSERTO` | ALTA |
| 12 | Item com CFOP x911 | `AMOSTRA_GRATIS` | ALTA |
| 13 | Item com CFOP x910 | `BONIFICACAO` (padrão) ou `DOACAO`: mesmo CFOP, distinção **comercial** (fornecedor habitual → bonificação; terceiro sem compras → doação) | MEDIA: sempre pede confirmação |
| 14 | Item com CFOP x101-x125, x401-x405, x551, x556 (vendas do emitente) | `COMPRA` | ALTA se tPag ≠ 90 ou houver duplicata; **MEDIA** se tPag=90 ou vPag=0 (venda "sem pagamento" é suspeita) |
| 15 | Item com CFOP x949 ou desconhecido | `OUTRA` | BAIXA |
| 16 | natOp contém "bonific", "doa", "brinde", "comodato", "remessa", "devolu" em conflito com o CFOP | Mantém o CFOP e **rebaixa a confiança**, com alerta "natureza da operação diverge do CFOP" | — |

A tabela de CFOP → operação fica num arquivo de configuração versionado (`config/operacoes_entrada.php`), não espalhada pelo código [recomendação]. Regras configuráveis por fornecedor, editáveis em tela e por tenant, ficam como candidatas ao SaaS.

### 4.4 Matriz de efeitos

Os efeitos de estoque são do ponto de vista da pizzaria.

| Operação | Critérios | Estoque | Custo | Contas a pagar | Pagamento/compensação | Vínculo anterior | Validações | Automático? | Confirmação manual | Fonte | Dúvidas ⚖️ |
|---|---|---|---|---|---|---|---|---|---|---|---|
| **COMPRA** | tpNF=1, finNFe=1, CFOP de venda do emitente | **Entrada** | WAC com custo de aquisição: vProd − vDesc + rateio de frete, seguro, outras despesas, **ST/FCP-ST/IPI se não recuperáveis** | **Sim**: base = duplicatas (cobr/dup) se houver; senão vPag ≠ 90; senão o valor da nota | "Já pago", parcelas, crédito de devolução (fluxo atual) | — | Itens vinculados; fator > 0; total calculado = vNF (alerta se divergir) | Pode confirmar com 1 clique se a confiança for ALTA | Só se MEDIA/BAIXA | CPC 16 item 11; CFOP 1102/1403/1556 (Conv. SINIEF s/nº/70, Anexo II) | ST/IPI no custo (Q4); 1101 × 1102 × 1556 para restaurante (Q5) |
| **BONIFICACAO** | CFOP x910 + fornecedor com histórico de compra; tPag=90 | **Entrada** | ⚖️ Opção A: custo **zero** (dilui o WAC, como abatimento sobre compras). Opção B: valor da nota com contrapartida em receita não operacional. Configuração única por empresa | **Não** | — | Opcional: compra relacionada | Confirmar que não há duplicata nem cobrança; se houver, alerta "bonificação com cobrança" | Não | **Sempre** | CFOP 1910/5910; CPC 16 item 11 (abatimentos); artigos contábeis divergem | Q1 |
| **DOACAO** | CFOP x910 sem relação comercial | **Entrada** | ⚖️ Igual à bonificação ou valor justo + receita de doação | **Não** | — | — | Idem | Não | **Sempre** | CFOP 1910 | Q2 |
| **AMOSTRA_GRATIS** | CFOP x911 | ⚖️ **Sem movimentação** (padrão) ou entrada a custo zero | — | **Não** | — | — | — | Sim (sem efeito) | Não | CFOP 1911 | Q3 |
| **DEVOLUCAO_AO_FORNECEDOR** | tpNF=0, finNFe=4, NFref de compra nossa | **Saída**, só se ainda não houver devolução registrada para esses itens; se já houver, **sem movimentação** e só vínculo | Valor da compra original (D11) | **Não gera.** Abate ou gera crédito (fluxo de devolução) | Crédito `PrestadorCredito` (atual) ou abatimento em título aberto (Q7) | **Obrigatório**: NFref → Compra | Quantidade ≤ comprado − já devolvido | Não | **Sempre** | finNFe=4 exige NFref (MOC); CFOP 5202/5411 do nosso lado | Q7, Q8 |
| **COMPLEMENTAR** | finNFe=2 | **Sem movimentação** de quantidade | Ajuste de valor no custo (via `CorrecaoEstoqueService` sobre a entrada original) | **Sim**, se houver cobrança | — | **Obrigatório**: NFref → Compra | Compra original confirmada | Não | **Sempre** | finNFe=2 (MOC) | Fase futura |
| **AJUSTE** | finNFe=3 | **Sem movimentação** | — | **Não** | — | NFref | — | Sim (registrar sem efeito) | Não | finNFe=3; tPag=90 obrigatório para finNFe 3/4 (NT 2016.002, rejeição 871) | — |
| **COMODATO** | CFOP x908 | **Sem movimentação** (o bem não é estoque) | — | **Não** | — | Contrato (fora do sistema) | — | Sim (registrar sem efeito) | Não | CFOP 1908 | Q6 (controle patrimonial?) |
| **VASILHAME** | CFOP x920/x921 | **Sem movimentação** de produto vendável | — | **Não**, salvo cobrança explícita | — | — | Se houver duplicata, alerta | Sim (sem efeito) | Se houver cobrança | CFOP 1920/1921 | Q6 |
| **CONSERTO** | CFOP x915/x916 | **Sem movimentação** | — | **Não** (o serviço de conserto vem em outro documento) | — | — | — | Sim (sem efeito) | Não | CFOP 1915/1916 | — |
| **TRANSFERENCIA** | Mesma raiz de CNPJ | **Depende da operação** (multi-filial não existe aqui) | Custo de transferência | **Não** | — | Nota de saída da outra filial | — | Não | **Sempre** | CFOP 1152 | Fora do escopo (SaaS) |
| **OUTRA** | x949, desconhecido | **Depende da operação** | Depende | Depende | — | — | — | Não | **Sempre**, com operação escolhida | CFOP 1949 | — |
| **NAO_PROCESSAVEL** | dest ≠ empresa, nota própria, cancelada | **Sem movimentação** | — | **Não** | — | — | Bloqueia a confirmação | — | Só arquivar ou ignorar | Cancelamento em até 24h (Ajuste SINIEF 07/05, cl. 12ª) | — |

"Registrar sem efeito" [recomendação]: o documento fica guardado e rastreável (`compra_status = SEM_EFEITO`, novo case), sem tocar em estoque nem em financeiro, e sai da fila de rascunhos.

---

## 5. Pesquisa fiscal: fontes e limites

| Tema | O que a fonte sustenta | Natureza | Fonte |
|---|---|---|---|
| CFOPs 1101, 1102, 1152, 1202, 1403, 1407, 1556, 1908, 1909, 1910, 1911, 1915, 1916, 1920, 1921, 1949, 5202, 5411, 5910 e 5920 | Títulos e notas explicativas oficiais (ex.: 1.910 "Entrada de bonificação, doação ou brinde", com bonificação, doação e brinde **no mesmo código**) | Norma (Conv. SINIEF s/nº de 15/12/1970, Anexo II, redação do Ajuste SINIEF 07/01) | [legisweb – Ajuste SINIEF 07/2001](https://www.legisweb.com.br/legislacao/?id=138) |
| Tabela de CFOP da NF-e | IT 2023.002 (v2.10, set/2026) traz título e descrição; v2.00 adiciona `indExcIBSCBS` | Documento técnico do Portal NF-e | [IT 2023.002 v2.10](https://www.reformatributaria.com/wp-content/uploads/2026/09/IT_2023.002_v2.10_Tabela_CFOP_Descricao.pdf), [Inventti](https://inventti.com.br/?p=19187), [Tecnospeed](https://blog.tecnospeed.com.br/cfop-tabela-atualizada/) |
| Finalidade 3/4 → tPag=90 | Rejeição 871 (regra YA02, NT 2016.002) | Regra de validação | [Oobj](https://oobj.com.br/bc/rejeicao-871-campo-pagamento-preenchido-como-resolver/), [Portal SPED Brasil](https://portalspedbrasil.com.br/?p=12019) |
| Manifestação do destinatário | Ciência: 10 dias. Conclusivas (Confirmação, Desconhecimento, Operação não realizada): **90 dias** desde o Ajuste SINIEF 14/26 (NT 2020.001 v1.60; antes, 180). Ciência não é conclusiva | NT + Ajuste SINIEF | [Senior – NT 2020.001 v1.60](https://documentacao.senior.com.br/exigenciaslegais/noticias/federal/2026/2026-04-27-nf-e-nota-tecnica-no-2020-001-v-1-60-evento-de-manifestacao-do-destinatario), [Focus NFe](https://focusnfe.com.br/notas-tecnicas/mde/2020-001/) |
| Cancelamento | Até 24h da autorização, sem circulação da mercadoria. Cancelamento extemporâneo a critério de cada UF | Ajuste SINIEF 07/05, cl. 12ª (red. Ajuste SINIEF 44/20) | [Portal SPED Brasil](https://portalspedbrasil.com.br/?p=16342), [SEF/MG](https://portalsped.fazenda.mg.gov.br/spedmg/export/sites/spedmg/nfe/download/Manual-para-Cancelamento-extemporaneo-de-NF.pdf) |
| Carta de correção | **Não** pode alterar base de cálculo, alíquota, quantidade, valor, dados cadastrais de remetente ou destinatário, nem data de emissão ou saída. Logo, a CC-e **nunca** muda efeito de estoque ou financeiro | Conv. SINIEF s/nº/70 art. 7º §1º-A (Ajuste SINIEF 01/07); Ajuste SINIEF 07/05 cl. 14ª-A | [legisweb – Ajuste SINIEF 01/2007](https://www.legisweb.com.br/legislacao/?id=211) |
| Custo de aquisição | Inclui preço, tributos **não recuperáveis**, frete, seguro e manuseio; descontos comerciais e abatimentos são deduzidos (item 11) | Pronunciamento contábil | [CPC 16(R1) – USP](https://edisciplinas.usp.br/pluginfile.php/5383386/mod_resource/content/1/CPC%2016%20Estoques.pdf), [CVM Deliberação 575](https://conteudo.cvm.gov.br/export/sites/cvm/legislacao/deliberacoes/anexos/0500/deli575.pdf) |
| Bonificação recebida | **Divergência na literatura**: estoque pelo valor desembolsado (custo diluído) × receita pelo valor recebido. SC Cosit 212/2015 (IRPJ, para quem concede) | Artigos (não oficiais) | [Jus.com.br](https://jus.com.br/artigos/48870/saiba-como-funciona-a-contabilizacao-das-bonificacoes-de-mercadorias-para-quem-concede-e-para-quem-recebe), [Contábeis](https://www.contabeis.com.br/artigos/3272/saiba-como-funciona-a-contabilizacao-das-bonificacoes-de-mercadorias-para-quem-concede-e-para-quem-recebe/), [Tributo Devido](https://tributodevido.com.br/portal/tratamento-tributario-das-bonificacoes-em-mercadorias/) |
| Devolução por optante do Simples | Informa BC e ICMS na NF-e de devolução (Res. CGSN 140/2018, art. 59 §9º, conforme consulta paulista); CFOP 5202/6202 | Resposta de consulta SP (aplicação em GO a confirmar) | [SEFAZ/SP RC 25535/2022](https://legislacao.fazenda.sp.gov.br/Paginas/RC25535_2022.aspx), [RC 21761/2020](https://legislacao.fazenda.sp.gov.br/Paginas/RC21761_2020.aspx) |
| Reforma tributária | NT 2025.002: finNFe **5 (crédito)** e **6 (débito)**, só IBS/CBS | NT (fontes secundárias; conferir o XSD) | [NFE.io](https://nfe.io/blog/notas-tecnicas/nota-tecnica-2025-002-guia-estrutural-da-reforma-tributaria-para-nf-e-e-nfc-e/), [Tecnospeed](https://blog.tecnospeed.com.br/?p=34112) |

**Limites desta pesquisa:**
- Não consultei o RCTE-GO (regulamento do ICMS de Goiás).
- Os detalhes de tPag/vPag variam entre versões da NT 2016.002.
- O tratamento contábil de bonificação e doação **não tem fonte oficial única**.
- O classificador usa os códigos de domínio do MOC (tpNF 0/1; finNFe 1-4 e, futuramente, 5/6), que precisam ser conferidos no XSD do leiaute vigente na implementação.
- **O que é norma:** o significado dos campos, o CFOP, os prazos e as vedações. **O que é decisão interna do sistema:** o enum de operação, a confiança, o padrão de custo da bonificação, "sem efeito" para comodato e vasilhame e a base da conta a pagar.

---

## 6. Proposta arquitetural

### 6.1 Peças (reaproveitando o que existe)

```
NfeXmlParser (estendido) ──► NfeParseada (+ tpNF, finNFe, natOp, dest, refs, pagamentos, duplicatas, tributos; itens + CFOP, CST/CSOSN, vST, vIPI, vFCPST, vSeg, vFrete, vOutro)
        │
NfeImportService::importar() ── grava colunas fiscais + chama ──► ClassificadorOperacaoEntrada (puro, sem banco além de leituras)
        │                                                          └─► ClassificacaoOperacao DTO {operacao, confianca, criterios[], alertas[], porItem[]}
        ▼
Compra (rascunho) + compra_eventos("importada", "classificada")
        │  revisão no Filament (reclassificar com motivo → evento)
        ▼
CompraService::previaEfeitos(Compra): EfeitosPrevistos   ◄── usado pelo modal (padrão de previaCorrecaoDataEntrada)
CompraService::confirmar(...)  ── lê OperacaoEntradaEnum::efeitos() por item:
        ├─ EstoqueService::registrarEntrada / nada / (devolução → CompraDevolucaoService)
        ├─ política de custo (normal | zero | valor da nota)
        ├─ gerarContaPagar só se efeitos.financeiro
        └─ compra_eventos("confirmada", efeitos efetivados)
CompraEstornoService (fase 5) ── CorrecaoEstoqueService::aplicarExclusao + cancelamento de títulos
```

- **`OperacaoEntradaEnum::efeitos(): EfeitosOperacao`** (readonly: `estoque` Entrada/Saída/Nenhum, `politicaCusto`, `geraContaPagar`, `exigeVinculo`, `exigeConfirmacao`). É a **única** implementação da matriz da seção 4.4. Tela, serviço e testes leem daí.
- **`ClassificadorOperacaoEntrada`** em `app/Services/Compras/` (pasta nova sob `Services`, que já tem subpastas como `Financeiro` e `Sefaz`). É função pura de `NfeParseada` + CNPJ da empresa + histórico do fornecedor → DTO. Testável sem Livewire.
- **Não** criar um "ProcessadorDeNota" paralelo ao `CompraService`. A confirmação continua sendo o único ponto de processamento.

### 6.2 Dados (migrations aditivas, todas `nullable`)

`compras`:
- `compra_tp_nf` (tinyint), `compra_fin_nfe` (tinyint), `compra_nat_op` (string)
- `compra_dest_documento` (string 14)
- `compra_valor_nf` (decimal: o vNF original)
- `compra_valor_st`, `compra_valor_ipi`, `compra_valor_seguro`, `compra_valor_fcp_st` (decimal 12,2, default 0)
- `compra_notas_referenciadas` (json: chaves NFref)
- `compra_pagamentos_nf` (json: tPag, indPag, vPag)
- `compra_duplicatas_nf` (json: nDup, dVenc, vDup)
- `compra_operacao` (string: enum ou `mista`), `compra_operacao_confianca` (string), `compra_operacao_criterios` (json)
- `compra_operacao_definida_por` (FK users, nullable), `compra_operacao_definida_em` (datetime), `compra_operacao_motivo` (text: obrigatório quando diverge da sugestão)

`compra_itens`:
- `ci_cfop` (string 4), `ci_cst_csosn` (string 4), `ci_operacao` (string)
- `ci_valor_st`, `ci_valor_ipi`, `ci_valor_fcp_st` (decimal 12,4, default 0)

Nova tabela `compra_eventos` (`ce_*`, seguindo o prefixo do projeto):
- `ce_compra_id`, `ce_tipo` (importada, classificada, reclassificada, confirmada, sem_efeito, devolucao, estornada, cancelamento_sefaz_detectado), `ce_dados` (json: snapshot da classificação e dos efeitos previstos ou efetivados), `ce_user_id`, timestamps.
- Índice curto e explícito (ver memória do limite de 64 caracteres em nomes de índice no MySQL).

`CompraStatusEnum`: novo case `SEM_EFEITO`. `CANCELADA` passa a ser usado pelo estorno (fase 5). `MovimentacaoOrigemEnum`: novos cases `BONIFICACAO`, `DOACAO` e `ESTORNO_COMPRA`.

### 6.3 Requisitos transversais

| Requisito | Como |
|---|---|
| Transação | Tudo dentro do `DB::transaction` do `confirmar()` / `ConfirmarCompraAction` (já existe) |
| Idempotência e concorrência | `Compra::lockForUpdate()->findOrFail()` no início de `confirmar()`, `gerarContaPagar()`, devolução e estorno. Checar `isRascunho()` **depois** do lock (D7) |
| Duplicidade de importação | `garantirNaoDuplicada` com `withTrashed()`. Se a compra excluída for rascunho, oferecer restaurar ou fazer forceDelete do rascunho excluído na reimportação (D8) |
| Proteção pós-processamento | `CompraPolicy::delete/deleteAny` + `DeleteBulkAction` só para rascunho (D6). Colunas fiscais e de operação imutáveis depois de `CONFIRMADA`/`SEM_EFEITO` (só o estorno muda) |
| Rastreabilidade | `compra_eventos` + colunas `compra_operacao_*` + referência morph já existente em `movimentacao_produtos` |
| Reversão | Fase 5: `CompraEstornoService` reusa `CorrecaoEstoqueService::aplicarExclusao` (replay do WAC), não um ajuste cego |
| Permissões | Novas: `reclassificar:compra` (alterar a operação sugerida) e `estornar:compra`. Seguir `PermissionSeeder` + `CompraPolicy` (padrão de `confirm:compra`) e lembrar de rodar o seeder em produção |
| Tenant | Single-tenant (seção 2.2 item 12). Nada de `empresa_id` agora; o CNPJ da empresa vem de `Empresa::firstOrFail()` |

### 6.4 Arquivos que provavelmente mudam

- `app/Services/Nfe/NfeXmlParser.php`, `app/Services/Nfe/Dto/{NfeParseada,NfeItem}.php` (+ novos DTOs `NfePagamento`, `NfeDuplicata`)
- `app/Services/Nfe/NfeImportService.php`
- `app/Services/Compras/ClassificadorOperacaoEntrada.php` (novo), `app/Services/Compras/Dto/ClassificacaoOperacao.php` (novo)
- `app/Enums/OperacaoEntradaEnum.php` (novo), `app/Enums/CompraStatusEnum.php`, `app/Enums/MovimentacaoOrigemEnum.php`
- `config/operacoes_entrada.php` (novo: mapa CFOP → operação)
- `app/Models/Compra.php`, `app/Models/CompraItem.php`, `app/Models/CompraEvento.php` (novo)
- `app/Services/CompraService.php` (`previaEfeitos`, `confirmar`, lock), `app/Services/CompraDevolucaoService.php` (fase 4)
- `app/Services/Sefaz/SefazDistribuicaoService.php` + `SefazRespostaDecoder.php` (eventos de cancelamento, fase 5)
- `app/Filament/Resources/Compras/Schemas/CompraForm.php`, `Tables/ComprasTable.php`, `RelationManagers/ItensRelationManager.php`, `Support/ConfirmarCompraAction.php`
- `app/Filament/Resources/SefazNotasRecebidas/Tables/*` (classificação prevista na prévia)
- `app/Policies/CompraPolicy.php`, `database/seeders/PermissionSeeder.php`
- Migrations aditivas e o comando `compras:diagnosticar-operacoes` (novo, fase 1)
- Testes: `tests/Unit/ClassificadorOperacaoEntradaTest.php` (novo), `tests/Feature/{ImportarXmlCompraTest,CompraServiceTest,ConfirmarCompraContaPagarTest,SefazDistribuicaoServiceTest}.php` e fixtures novas em `tests/Fixtures/`

---

## 7. Plano de implementação (fases com OK do usuário entre elas)

### Fase 0: integridade (sem mudar regra de negócio) ⟶ **recomendada já**
1. D6: `CompraPolicy::delete` retorna false se a compra não for rascunho; `DeleteBulkAction` com `->authorizeIndividualRecords('delete')` ou filtro de rascunho; teste: o bulk delete não exclui compra confirmada.
2. D7: lock da Compra em `confirmar()` e `gerarContaPagar()`; teste: a segunda confirmação da mesma instância obsoleta falha sem nova movimentação.
3. D8: reimportação de chave com rascunho excluído (`withTrashed`), mais captura de `QueryException` no polling; teste.
4. D5: validar `dest` = `empresa_cnpj`. **Bloquear** no upload manual e no polling (o resumo `resNFe` não traz dest; validar após o XML completo, marcando a nota da caixa como IGNORADA com motivo). Teste com fixture de outro destinatário. ⚖️ Q9.

### Fase 1: captura + classificação **somente leitura** ⟶ **recomendada já, junto com a Fase 0**
1. Estender o parser e os DTOs (seção 6.4) e fazer as migrations aditivas da seção 6.2, **exceto** `compra_eventos` e `SEM_EFEITO`, que entram na Fase 2.
2. `OperacaoEntradaEnum` + `efeitos()` (a matriz inteira já codificada, mas ainda **não consumida** pelo `confirmar()`).
3. `ClassificadorOperacaoEntrada` + `config/operacoes_entrada.php`, chamado no `NfeImportService` (grava sugestão, confiança e critérios).
4. UI só de leitura: seção "Operação fiscal" no `CompraForm` (natOp, finalidade, tpNF, CFOPs, operação sugerida, badge de confiança, critérios, alertas); coluna CFOP e operação no `ItensRelationManager`; coluna e filtro "Operação" na `ComprasTable`; **alerta** no modal de confirmação quando a operação sugerida não for `COMPRA` ou a confiança não for ALTA ("Esta nota parece ser BONIFICAÇÃO: confira antes de gerar conta a pagar"). Nada é bloqueado ainda.
5. Comando `compras:diagnosticar-operacoes {--gravar}`: lê o XML guardado de cada compra (`compra_xml_path`). **Sem `--gravar`**, imprime um relatório (operação sugerida × efeitos que a compra efetivamente gerou: entrada de estoque? título?). **Com `--gravar`**, preenche **só** as colunas fiscais e de sugestão. Nunca toca em estoque, títulos ou status.
6. Alerta de divergência `compra_valor_total` × `compra_valor_nf` (D4) na tela, sem mudar o cálculo ainda.

### Fase 2: processamento orientado pela operação (COMPRA, BONIFICACAO, DOACAO, AMOSTRA, "sem efeito")
- Depende das respostas Q1–Q4 e Q11.
- Itens:
  - `compra_eventos`, `SEM_EFEITO` e a ação "Registrar sem efeito".
  - Reclassificação com motivo e permissão.
  - `previaEfeitos()` no modal ("O que vai acontecer": estoque, custo, financeiro e o motivo de cada "não").
  - `confirmar()` lendo `efeitos()` por item; política de custo da bonificação; toggle de conta a pagar com padrão vindo da operação; base da conta a pagar pelas duplicatas.
  - Tributos não recuperáveis no custo e no total, se Q4 confirmar.
  - Confirmação bloqueada sem o "conferi a operação" quando a confiança for MEDIA ou BAIXA.

### Fase 3: notas sem movimentação física
- COMODATO, VASILHAME, CONSERTO, AJUSTE e NAO_PROCESSAVEL, com "Registrar sem efeito" e classificação prevista na caixa SEFAZ.

### Fase 4: devolução ao fornecedor
- Chave da NF-e de devolução em `compra_devolucoes`.
- Importação da nota do fornecedor (tpNF=0/finNFe=4) vinculando por NFref à Compra e à devolução existente (sem segunda saída).
- Valoração da saída (D11, Q8) e opção de abater em título aberto (Q7).

### Fase 5: cancelamento e estorno
- Ler `procEventoNFe`/`resEvento` com tpEvento 110111 no NSU e marcar a compra com alerta + evento `cancelamento_sefaz_detectado`.
- `CompraEstornoService` (estoque via replay, títulos pendentes cancelados, títulos pagos exigem estorno de pagamento antes), status `CANCELADA` e permissão `estornar:compra`.
- **Estorno sempre manual** (Q10).

### Futuro (backlog, fora do escopo)
- Complementar com ajuste de custo.
- Duplicatas → parcelas automáticas.
- Manifestação "Desconhecimento" e "Operação não realizada".
- CT-e de frete (custo + título ao transportador).
- Transferência multi-filial (SaaS).
- finNFe 5/6 (IBS/CBS).
- Regras configuráveis em tela por fornecedor.

---

## 8. Compatibilidade e implantação

- Migrations **aditivas** e nullable. Compra antiga fica com `compra_operacao = null`, exibida como "Legado (não classificada)". Nenhuma regra nova se aplica retroativamente.
- **Não reprocessar** compras antigas. O diagnóstico (Fase 1.5) gera uma lista para **revisão humana**. Correções seguem pelos caminhos auditados que já existem (correção de movimentação, devolução, estorno de pagamento) ou pelo estorno da Fase 5.
- Compras manuais (73) não têm XML: ficam sem classificação, e a tela mostra "sem documento eletrônico".
- Deploy: só PHP nas Fases 0–1 (sem build de assets, salvo se a UI usar classe Tailwind nova; nesse caso, ver a memória de build). Fluxo: `migrate` em produção, rodar o diagnóstico sem `--gravar`, revisar e então rodar com `--gravar`. Recarregar o OPcache depois do deploy (a produção não recarrega sozinha). Na Fase 2, rodar o `PermissionSeeder` em produção.
- Ordem segura: Fase 0 → Fase 1 (observar algumas semanas de notas reais com a classificação visível) → calibrar o `config/operacoes_entrada.php` → Fase 2.

---

## 9. Plano de testes e critérios de aceite

**Unit (classificador, sem banco):** um fixture XML por cenário em `tests/Fixtures/nfe-entrada/`:
- venda 5102 com duplicatas → COMPRA/ALTA
- 5102 com tPag=90 → COMPRA/MEDIA com alerta
- 5910 → BONIFICACAO/MEDIA
- nota mista 5102 + 5910 → itens distintos, cabeçalho MISTA
- tpNF=0 + finNFe=4 com NFref → DEVOLUCAO_AO_FORNECEDOR
- finNFe=2 → COMPLEMENTAR
- 5908 → COMODATO
- 5920 → VASILHAME
- dest ≠ empresa → NAO_PROCESSAVEL
- emitente com a mesma raiz de CNPJ → TRANSFERENCIA
- natOp "BONIFICAÇÃO" com CFOP 5102 → confiança rebaixada

**Feature:**
- Fase 0: bulk delete de confirmada bloqueado; confirmação dupla não duplica estoque; reimportação após exclusão; destinatário errado rejeitado.
- Fase 1: a importação grava colunas e sugestão; o diagnóstico sem `--gravar` não altera nada (`assertDatabaseCount`/hash antes e depois); com `--gravar`, não toca em `movimentacao_produtos`, `lancamentos` nem `compra_status`.
- Fase 2: para cada operação, a confirmação produz exatamente os efeitos de `efeitos()` (movimentações, custo médio resultante, quantidade de títulos); reclassificação exige motivo e permissão e gera evento.
- **Falha parcial:** exceção no meio de `gerarContaPagar` desfaz a entrada de estoque (já é uma transação; cobrir com teste).
- **Reprocessamento:** chamar `confirmar()` duas vezes não gera efeito duplicado.

**Execução:**
- Testes em banco isolado: `vendor/bin/sail exec -u sail -e DB_DATABASE=testing_<nome> laravel.test php artisan test ...`.
- QA visual com Playwright quando a UI mudar.

**Critério de aceite global:**
- Nenhuma nota com operação ≠ COMPRA gera conta a pagar ou entrada a custo cheio sem ação explícita e registrada de um usuário.
- Nenhuma nota tpNF=0 vira entrada.
- Toda decisão fica em `compra_eventos` com o usuário responsável.

---

## 10. Riscos

| Tipo | Risco | Mitigação |
|---|---|---|
| Técnico | CFOP mal preenchido pelo fornecedor gera classificação errada | Confiança + confirmação humana; a natOp funciona como alerta, não como decisão |
| Técnico | Replay do WAC (estorno) em produto com muitas movimentações posteriores | Usar o `CorrecaoEstoqueService`, já testado; prévia (`simularExclusao`) no modal |
| Técnico | Polling ligado em produção importa notas de outras naturezas em volume | A classificação marca; nada é processado sem humano (D15) |
| Fiscal | Tratar como regra fiscal o que é convenção do sistema (ex.: comodato "sem efeito") | Seção 5 separa norma de decisão; Q1–Q6 com o contador |
| Fiscal | Cancelamento extemporâneo e eventos fora do NSU | Fase 5 + consulta pontual por chave na tela da compra |
| Financeiro | Bonificação com duplicata (o fornecedor cobra) classificada como gratuita | Regra 14/4.4: duplicata ou cobrança presente → alerta e confirmação |
| Financeiro | ST/IPI passam a compor o custo e mudam o CMV de uma hora para outra | Só depois de Q4, a partir da data de corte, sem retroagir |
| Operacional | Mais cliques na confirmação | Notas ALTA/COMPRA seguem com o fluxo de hoje (1 modal) |
| Operacional | Usuário reclassifica para "passar" | Motivo obrigatório + permissão + evento auditável |

---

## 11. Questões para o responsável e o contador ⚖️

1. **Bonificação:** entra no estoque a custo zero (diluindo o custo médio) ou pelo valor da nota com receita de bonificação?
2. **Doação:** mesmo tratamento da bonificação? Precisa de relatório separado?
3. **Amostra grátis:** entra no estoque ou é só registrada?
4. **Simples Nacional/GO:** confirmar que ICMS-ST, FCP-ST, IPI e seguro destacados compõem o custo do estoque e o valor a pagar (hoje ficam de fora).
5. Para a escrituração do contador, qual CFOP de entrada a pizzaria usa por tipo de item (1101 × 1102 × 1403 × 1556)? O sistema precisa de relatório de entradas por CFOP?
6. **Comodato e vasilhame** (freezer, chopeira, barril, engradado): basta registrar sem efeito ou quer controle de bens de terceiros e saldo de vasilhame?
7. **Crédito de devolução:** manter "abate na próxima compra" ou permitir abater em título **aberto** da própria compra?
8. **Valor da devolução:** a saída do estoque deve usar o custo original da compra (igual ao crédito) ou o custo médio vigente (hoje)?
9. **Nota com destinatário diferente** do CNPJ da empresa: bloquear sempre, ou permitir com justificativa (ex.: matriz e filial)?
10. **Nota cancelada pelo fornecedor** depois de confirmada: só alertar e deixar o estorno manual (recomendado) ou estornar automaticamente?
11. Em nota mista (venda + bonificação), quando o vNF inclui os itens bonificados, o valor a pagar é o das **duplicatas** (recomendado), o vNF ou só os itens onerosos?
12. A auto-importação da SEFAZ está ligada em produção?
13. Quem pode reclassificar uma nota (perfil Gerente, Admin)?

---

## 12. Recomendação da primeira etapa

Implementar **Fase 0 + Fase 1** num único ciclo:
- **Segurança:** fecha os riscos D5–D8, que já existem hoje e não dependem de nenhuma decisão fiscal.
- **Consistência:** não muda nenhum efeito em estoque ou financeiro; só adiciona dados, sugestão e alertas.
- **Reaproveitamento:** estende o parser, o importador e o `CompraService` existentes, sem pipeline paralelo.
- **Base para decidir:** o diagnóstico sobre as ~216 compras com XML mostra quantas bonificações, remessas ou devoluções já foram confirmadas como compra. Isso dá ao contador números reais para responder Q1–Q11 antes da Fase 2.
