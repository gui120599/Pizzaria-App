# Condições de compra do fornecedor: pré-preenchimento da confirmação de compra

> Avaliação e plano de 28/10/2026, sobre o HEAD `8a8aab34` (branch `prod-new`).
> Status: **Fases 1, 2 e 3 implementadas em 28/10/2026** (commit na `prod-new`; hash no `git log`). 95 testes da suíte afetada verdes e QA em navegador real feito na Fase 2. **Pendentes: implantação** (migrate das 2 migrations, OPcache, manual da equipe sobre a regra de data de entrada pela emissão). Sem permissão nova. Decisões do responsável registradas na seção 2. Implementar por fases, com OK do usuário entre elas.
>
> **Fase 1:** tabela `fornecedor_condicoes_compra`, model + factory, `Prestador::condicaoCompra()`, enums `BaseVencimentoEnum`/`RegraDataEntradaEnum`, Section "Condições de compra" no `PrestadorResource`, regra no `NfeImportService` e no default do modal (`Compra::dataEntradaSugerida()`). Desvios: a data pela regra `emissao` nunca é futura (limitada a hoje, por causa do `maxDate(today())`); o Select de conta do cadastro usa `semCaixaPdv()`.
>
> **Fase 2:** `parcela_forma_pagamento` no Prazo (CRUD e cadastro rápido), `compra_vencimento_origem`, `VencimentoOrigemEnum`, `SugestaoPagamentoCompra` + DTO, modal com `fill()` no `mountUsing`, Placeholders de fontes e avisos, origem gravada na confirmação (por `Compra::update` na action; `gerarContaPagar()` não mudou). Desvios: (1) o `fill()` leva as datas como `Carbon` à meia-noite, porque string `Y-m-d` passa pelo `DateTimeStateCast` (`createFromFormat`) e ganha a hora atual, estourando o `maxDate(today())` (teste de regressão `test_confirma_com_data_de_entrada_de_hoje_sem_erro_de_validacao`); (2) `fill(array)` não aplica defaults, então `gerar_conta_pagar` vai explícito; (3) há um `Hidden` extra `base_vencimento` para a data-base não acompanhar a entrada quando o prazo é contado da emissão; (4) `parcelasDoPrazo()` ganhou o 5º parâmetro opcional `$formaDaNota`.
>
> **Fase 3:** `corrigirDataEntrada(..., bool $deslocarVencimentos = true)` e Toggle "Deslocar vencimentos em aberto", com o padrão por `VencimentoOrigemEnum::dependeDaEntrada()`.
>
> **QA em navegador (Playwright + php -S contra banco isolado):** prazo do fornecedor (30/60 da emissão) com forma por parcela e conta → marcar "Já pago" → conta já preenchida → confirmar: título 1 Pago com movimentação na conta certa, título 2 Pendente, origem `prazo_emissao`. Nota com duplicatas: vencimentos da nota, aviso de divergência com o prazo, origem `duplicatas_nf`. Console sem "Livewire Entangle Error" nos dois fluxos.

---

## 1. Resumo

Hoje, a cada compra confirmada, o usuário escolhe à mão o prazo de pagamento, a forma de cada parcela, a conta de origem e confere a data de entrada. O fornecedor (`Prestador`) não guarda nenhuma condição comercial, e as duplicatas da NF-e, que trazem os vencimentos reais dos boletos, são gravadas e ninguém usa.

A proposta:
1. Uma tabela 1:1 de **condições de compra do fornecedor**: prazo padrão, base do prazo (emissão ou entrada), forma de pagamento padrão, conta financeira sugerida e regra da data de entrada.
2. **Forma de pagamento por parcela no Prazo de Pagamento**, para condições mistas ("50% Pix na entrega + 50% boleto 30d").
3. Uma classe pura, `SugestaoPagamentoCompra`, que monta a sugestão do modal na ordem **duplicatas da NF-e → prazo do fornecedor → parcela única**.
4. **Nada é marcado como pago automaticamente.** Forma e conta só são sugeridas, e o usuário marca "Já pago".
5. A correção da data de entrada passa a **perguntar** se desloca os vencimentos, com o padrão definido pela origem das parcelas.

---

## 2. Decisões do responsável [decisão]

| # | Pergunta | Decisão |
|---|---|---|
| 1 | Base do prazo: emissão ou entrada? | **Configurável por fornecedor.** |
| 2 | Duplicatas da NF-e prevalecem sobre o prazo do fornecedor? | **Sim.** |
| 3 | "Já pago" pode vir marcado pelo cadastro? | **Não, só sugerir** forma e conta. O usuário marca. |
| 4 | Forma de pagamento por parcela no próprio Prazo? | **Sim.** |

---

## 3. Estado atual [código]

- **Fornecedor = `Prestador`** (`app/Models/Prestador.php`), um cadastro com vários papéis: `categoria` FORNECEDOR, espelho de colaborador (somente leitura, `booted()`) e vínculo com `cliente_id`. Relações: `fornecedorProdutos`, `cliente`, `colaborador`. Não há condição comercial.
- **Cadastro**: `PrestadorResource` é um resource simples (`ManagePrestadors`, formulário em modal). O Select `categoria` (`PrestadorResource.php:76`) não é `live()`.
- **Prazo de Pagamento**: `PrazoPagamento` → `PrazoPagamentoParcela` (`parcela_numero`, `parcela_dias`, `parcela_percentual`). Os campos da parcela ficam em `PrazoPagamentoForm::parcelasSchema()`, reaproveitado no CRUD e no cadastro rápido (`camposRapidos()`).
- **Modal de confirmação** (`app/Filament/Resources/Compras/Support/ConfirmarCompraAction.php`):
  - `data_entrada` (`:64`) tem como default `compra_data_entrada ?? now()`. Ao mudar, copia para `data_base` (`:69`).
  - `data_base` (`:77`) tem como default a data de entrada.
  - O Select `prazo_pagamento_id` (`:85`) recalcula as parcelas por `$set('parcelas', calcularParcelas(...))` só quando o usuário escolhe um prazo.
  - O default do Repeater é uma parcela única com o total. `linhaParcela()` (`:365`) garante todas as chaves de cada linha. Isso é obrigatório, ver seção 7.
  - `ContaFinanceiraSelect::make()->sugerirForma()` (`:159`) só aparece com "Já pago" marcado e só sugere a forma quando o usuário escolhe a conta.
- **Importador**: `NfeImportService::importar()` grava `compra_data_entrada = now()` (`app/Services/Nfe/NfeImportService.php:64`). O fornecedor já é resolvido antes (`resolverFornecedor`).
- **Duplicatas e pagamentos da nota**: `CompraFiscalService` grava `compra_duplicatas_nf` (`[{nDup, dVenc, vDup}]`) e `compra_pagamentos_nf` (`[{tPag, indPag, vPag}]`), com cast `array` em `Compra`. Hoje só alimentam os alertas do `ClassificadorOperacaoEntrada`.
- **Correção da data de entrada**: `CompraService::corrigirDataEntrada()` (`:167`) **sempre** desloca o vencimento dos títulos Pendente/Parcial pela diferença de dias. Isso só está certo quando o vencimento foi contado a partir da entrada.
- **Geração dos títulos**: `CompraService::gerarContaPagar()` recebe as parcelas já calculadas. **Não muda neste plano.**

---

## 4. Modelo de dados [recomendação]

Todas as migrations são aditivas. Nenhuma altera o comportamento de compras e fornecedores existentes.

### 4.1 Tabela `fornecedor_condicoes_compra` (1:1 com `prestadores`)

Fica em tabela própria, e não em colunas de `prestadores`, por três motivos: só se aplica a `categoria = FORNECEDOR`, não incha um cadastro que já tem três papéis e facilita levar para o SaaS multi-tenant. O prefixo `fcc_` segue o padrão do `fp_` de `FornecedorProduto`.

| Coluna | Tipo | Observação |
|---|---|---|
| `id` | bigint | |
| `fcc_prestador_id` | FK `prestadores`, **unique**, `cascadeOnDelete` | |
| `fcc_prazo_pagamento_id` | FK `prazos_pagamento`, nullable, `nullOnDelete` | Prazo padrão. Inativo ou excluído (soft delete) é ignorado na hora de usar. |
| `fcc_prazo_base` | string(20), default `'entrada'` | `BaseVencimentoEnum`: `emissao` \| `entrada`. O default `entrada` mantém o comportamento atual. |
| `fcc_forma_pagamento` | string(30), nullable | `FormaPagamento`. Forma padrão das parcelas. |
| `fcc_conta_financeira_id` | FK `contas_financeiras`, nullable, `nullOnDelete` | Conta sugerida para "Já pago". |
| `fcc_data_entrada_regra` | string(20), default `'hoje'` | `RegraDataEntradaEnum`: `hoje` (comportamento atual) \| `emissao`. |
| `timestamps` | | |

O nome do índice unique precisa ser explícito e curto, por exemplo `fcc_prestador_unique`. Ver a memória sobre o limite de 64 caracteres em identificadores do MySQL.

**Model** `FornecedorCondicaoCompra`, com factory e casts para os enums. Em `Prestador`, acrescentar `condicaoCompra(): HasOne`.

### 4.2 Forma de pagamento por parcela do Prazo

- `prazo_pagamento_parcelas.parcela_forma_pagamento`: string(30), nullable, `FormaPagamento`.
- Cast em `PrazoPagamentoParcela`, entrada em `$fillable` e campo `Select` em `PrazoPagamentoForm::parcelasSchema()`, para valer no CRUD e no cadastro rápido. O `createOptionUsing` de `ConfirmarCompraAction` (`:85`) também precisa gravar o campo novo.
- Vazio significa "usa a forma padrão do fornecedor".

### 4.3 Origem dos vencimentos na compra

- `compras.compra_vencimento_origem`: string(20), nullable. `VencimentoOrigemEnum`: `duplicatas_nf` \| `prazo_emissao` \| `prazo_entrada` \| `manual`.
- Gravado na confirmação (quando gera conta a pagar) com a origem da **sugestão** aplicada. É ele que define o padrão do "deslocar vencimentos" na correção da data de entrada (seção 5.4).
- Nas compras antigas fica `null`, tratado como `prazo_entrada` para manter o comportamento atual.

### 4.4 Enums novos (`app/Enums/`)

- `BaseVencimentoEnum` (`emissao`, `entrada`), com `HasLabel`.
- `RegraDataEntradaEnum` (`hoje`, `emissao`), com `HasLabel`.
- `VencimentoOrigemEnum` (`duplicatas_nf`, `prazo_emissao`, `prazo_entrada`, `manual`), com `HasLabel`.

Antes de criar, confira o padrão de nome e de `HasLabel` dos enums vizinhos (`FormaPagamento`, `PrestadorCategoriaEnum`).

---

## 5. Regras de negócio

### 5.1 Data de entrada

| `fcc_data_entrada_regra` | Importador (`NfeImportService:64`) | Default do modal |
|---|---|---|
| `hoje` ou fornecedor sem condição | `now()`, como hoje | `compra_data_entrada ?? now()`, como hoje |
| `emissao` | `compra_data_emissao`, ou `now()` sem emissão | `compra_data_emissao ?? compra_data_entrada ?? now()` |

- O modal também aplica a regra para cobrir compras manuais e compras importadas antes desta mudança.
- As validações de `campoDataEntrada()` continuam (não futura, não anterior à emissão). O aviso amarelo de "data não é hoje" também continua: com a regra `emissao` ele vai aparecer com frequência, e é intencional. O texto ganha um complemento: "(regra do fornecedor: data de emissão)".
- O WAC não muda, porque segue a ordem de id. Os relatórios por data (CMV do período) passam a refletir a data de emissão. Isso precisa constar no manual da equipe.

### 5.2 Parcelas: ordem de preferência [decisão 2]

A classe `SugestaoPagamentoCompra` (seção 6.1) decide nesta ordem:

1. **Duplicatas da NF-e** (`compra_duplicatas_nf` com ao menos uma linha com `dVenc` e `vDup > 0`):
   - Uma linha por duplicata: `vencimento = dVenc`, `valor = vDup`. Origem `duplicatas_nf`.
   - Se a soma das duplicatas for **menor** que `compra_valor_total` em mais de R$ 0,05 (nota com entrada à vista, por exemplo `detPag` em PIX + duplicatas do restante), acrescenta uma linha com a diferença, vencimento na emissão e forma vinda do `detPag` (5.3). Mostra um aviso.
   - Se a soma for **maior** que o total, mantém as duplicatas e mostra um aviso. O resumo do modal já exibe o percentual.
   - Duplicata sem `dVenc` ou com valor 0 é descartada, com aviso. Se nenhuma sobrar, passa para a regra 2.
2. **Prazo padrão do fornecedor** (ativo, não excluído, com parcelas):
   - `data_base` = emissão (`fcc_prazo_base = emissao`, com fallback para entrada se não houver emissão) ou data de entrada.
   - Valores pelo `calcularParcelas()` atual, com a reconciliação de centavos já existente.
   - Origem `prazo_emissao` ou `prazo_entrada`.
   - O Select `prazo_pagamento_id` do modal já vem com esse prazo selecionado.
3. **Parcela única** com o total, vencimento na data de entrada. Origem `prazo_entrada`, que é o comportamento atual.

Escolher outro prazo no Select continua recalculando as parcelas, como hoje, e a origem passa a ser a do prazo escolhido com a base do fornecedor (ou `entrada`, se o fornecedor não tiver condição). Se a sugestão veio das duplicatas e o usuário troca para um prazo, o modal avisa que está descartando os vencimentos da nota.

Quando houver duplicatas **e** prazo do fornecedor com quantidade de parcelas ou vencimentos diferentes, o modal avisa ("O prazo do fornecedor é 30/60, mas a nota traz 1 duplicata para 28/11"), sem trocar a sugestão.

### 5.3 Forma de pagamento e conta (só sugestão) [decisão 3, 4]

Forma de cada linha, nesta precedência:
1. `parcela_forma_pagamento` da parcela do Prazo, quando a linha veio de um prazo.
2. `fcc_forma_pagamento` do fornecedor.
3. Mapeamento do `tPag` da nota (`compra_pagamentos_nf`), quando há um único `tPag` mapeável: `01`→Dinheiro, `03`→Cartão de crédito, `04`→Cartão de débito, `15`→Boleto, `16`/`18`→Transferência, `17`/`20`→Pix. `90`, `99` e os demais ficam sem mapeamento.
4. `null`.

Conta financeira:
- `fcc_conta_financeira_id` entra no estado de **todas** as linhas como `conta_financeira_id`, se ainda estiver em `ContaFinanceiraSelect::opcoes()`. Se a conta foi excluída, fica `null`.
- O campo continua oculto até o usuário marcar "Já pago". Ao marcar, a conta já aparece preenchida. É assim que a sugestão chega sem pagar nada sozinha.
- `ja_pago` é **sempre** `false` na sugestão. Nenhum parâmetro liga isso.
- `sessao_caixa_id` é sempre `null`. Com o Caixa PDV e mais de uma sessão aberta, o usuário escolhe.
- `gerarContaPagar()` já ignora `conta_financeira_id` quando `ja_pago = false`. Isso precisa de um teste (seção 9) para garantir que a conta sugerida não movimenta nada.

Sinalização no modal: um Placeholder no topo, "Pré-preenchido por: duplicatas da NF-e · forma e conta do cadastro do fornecedor", com link para editar o fornecedor. Os avisos da seção 5.2 aparecem logo abaixo.

### 5.4 Correção da data de entrada

- `CorrigirDataEntradaAction` ganha o Toggle **"Deslocar vencimentos em aberto"**, com o default definido por `compra_vencimento_origem`:
  - `prazo_entrada`, `manual` ou `null` → **ligado**, como hoje;
  - `duplicatas_nf` ou `prazo_emissao` → **desligado**, com o texto de ajuda "Os vencimentos foram contados a partir da nota/emissão e não dependem da entrada."
- `CompraService::corrigirDataEntrada()` ganha o parâmetro `bool $deslocarVencimentos = true`. Com `false`, não toca nos títulos e retorna `titulos = 0`.
- A prévia do modal mostra os títulos só quando o Toggle está ligado.

---

## 6. Peças e arquivos

### 6.1 `App\Services\Compras\SugestaoPagamentoCompra` (nova, pura)

Ela tira a lógica do `ConfirmarCompraAction` e permite testar sem Livewire.

```php
public function para(Compra $compra): SugestaoPagamento   // DTO readonly
```

O DTO `SugestaoPagamento` (em `app/Services/Compras/Dto/`, se a pasta seguir o padrão de `app/Services/Nfe/Dto/`) traz:
- `dataEntrada: Carbon`
- `dataBase: Carbon`
- `baseVencimento: BaseVencimentoEnum`
- `prazoPagamentoId: ?int`
- `origem: VencimentoOrigemEnum`
- `parcelas: array<string, array{vencimento, valor, forma_pagamento, ja_pago: false, conta_financeira_id, sessao_caixa_id: null}>` (chaves UUID)
- `avisos: list<string>`
- `fontes: list<string>`, os textos do Placeholder "Pré-preenchido por"

Também expõe `parcelasDoPrazo(PrazoPagamento $prazo, Carbon $dataBase, float $total, ?FornecedorCondicaoCompra $condicao): array`. O Select de prazo do modal usa esse método ao trocar de prazo.

`calcularParcelas()` e `linhaParcela()` saem do `ConfirmarCompraAction` e vêm para cá. A linha completa continua sendo obrigatória (seção 7).

### 6.2 Arquivos que mudam

| Arquivo | Mudança |
|---|---|
| `database/migrations/*` (3) | Tabela `fornecedor_condicoes_compra`, `parcela_forma_pagamento`, `compra_vencimento_origem` |
| `app/Enums/` (3 novos) | Seção 4.4 |
| `app/Models/FornecedorCondicaoCompra.php` + factory | Novo |
| `app/Models/Prestador.php` | `condicaoCompra(): HasOne` |
| `app/Models/PrazoPagamentoParcela.php` | fillable + cast |
| `app/Models/Compra.php` | fillable + cast de `compra_vencimento_origem` |
| `app/Filament/Resources/Prestadors/PrestadorResource.php` | Section "Condições de compra", `->relationship('condicaoCompra')`, visível só para `categoria = fornecedor` (o Select `categoria` passa a ser `live()`). Na listagem, eager load se exibir alguma coluna. |
| `app/Filament/Resources/PrazosPagamento/Schemas/PrazoPagamentoForm.php` | Select `parcela_forma_pagamento` em `parcelasSchema()` e no `itemLabel` |
| `app/Filament/Resources/Compras/Support/ConfirmarCompraAction.php` | Defaults vindos da `SugestaoPagamentoCompra`, Placeholder de fontes e avisos, campo oculto da origem, `data_base` só acompanha `data_entrada` quando a base é `entrada`, gravação da origem |
| `app/Services/CompraService.php` | `corrigirDataEntrada(..., bool $deslocarVencimentos = true)` e, na confirmação, gravar `compra_vencimento_origem` (parâmetro opcional de `gerarContaPagar()` ou `forceFill` na action) |
| `app/Filament/Resources/Compras/Support/CorrigirDataEntradaAction.php` | Toggle "Deslocar vencimentos em aberto" |
| `app/Services/Nfe/NfeImportService.php` | Regra de data de entrada (`:64`) |

### 6.3 Ligação no modal

- Calcular a sugestão **uma vez** no `mountUsing` (depois do `$record->refresh()` que já existe) e preencher pelo `$schema->fill([...])` com `data_entrada`, `data_base`, `prazo_pagamento_id`, `parcelas` e `vencimento_origem`. Não usar `$set` encadeado em `afterStateUpdated`.
- O `->default()` atual do Repeater e de `data_entrada`/`data_base` fica como fallback (o `fill` explícito prevalece). Confirmar o comportamento do `fill()` com argumento no Filament 4 pelo `search-docs` antes de implementar.
- A origem fica num `Hidden::make('vencimento_origem')`. Ao trocar de prazo no Select, o `afterStateUpdated` atualiza a origem. O Hidden é desidratado, então não pode receber `dehydrated(false)`.
- O `afterStateUpdated` de `data_entrada` (`:69`) só copia para `data_base` quando a base vigente é `entrada`.

---

## 7. Armadilhas conhecidas (ler antes de mexer)

- **`$entangle` do Repeater**: toda linha de parcela, seja montada no `fill`, seja no `$set` do prazo, precisa ter **todas** as chaves dos filhos (`vencimento`, `valor`, `forma_pagamento`, `ja_pago`, `conta_financeira_id`, `sessao_caixa_id`). Linha incompleta quebra o Select não nativo no navegador, e `Livewire::test()->set()` mascara o problema. Ver `docs/planos/correcao-conta-origem-confirmar-compra.md`.
- **Chaves UUID** nas linhas e o `->key()` do Repeater por prazo continuam valendo (máscara do `Money`).
- **Valor no formato do `Money`**: as linhas levam o valor como string decimal com ponto (`number_format(..., 2, '.', '')`), como já faz `linhaParcela()`.
- **Section com `->relationship()` em formulário de modal (ManageRecords)**: confirmar no `search-docs` que salva a relação 1:1 na criação e na edição. Se não salvar, usar `mutateFormDataUsing`/`using` nas actions de `ManagePrestadors`.
- **Espelho de colaborador**: o `booted()` do `Prestador` lança exceção ao atualizar espelho. Salvar a condição não toca no `Prestador`, mas a Section precisa ficar oculta para espelho e para categoria diferente de fornecedor.
- **Testes em banco isolado**, como as outras sessões (`sail exec -u sail -e DB_DATABASE=...`).

---

## 8. Fases (OK do usuário entre elas)

### Fase 1: cadastro e data de entrada
1. Migration e model `FornecedorCondicaoCompra`, factory, `Prestador::condicaoCompra()`, enums `BaseVencimentoEnum` e `RegraDataEntradaEnum`.
2. Section "Condições de compra" no `PrestadorResource`, já com **todos** os campos da tabela. Prazo, base, forma e conta só são lidos na Fase 2.
3. Regra da data de entrada no importador e no default do modal, com o complemento no aviso.
4. Testes da seção 9.1.

### Fase 2: parcelas sugeridas
1. Migration de `parcela_forma_pagamento` e `compra_vencimento_origem`, enum `VencimentoOrigemEnum`.
2. Forma por parcela no `PrazoPagamentoForm` e no cadastro rápido.
3. `SugestaoPagamentoCompra` com as regras 5.2 e 5.3, mais o mapeamento de `tPag`.
4. Modal: `fill` no `mountUsing`, Placeholder de fontes e avisos, origem gravada na confirmação.
5. Testes da seção 9.2.
6. **QA no navegador real**, obrigatório pelo histórico do `$entangle`: prazo do fornecedor → marcar "Já pago" → a conta aparece preenchida → confirmar → título Pago com a conta certa. Também: nota com duplicatas → vencimentos da nota.

### Fase 3: correção da data de entrada
1. `corrigirDataEntrada(..., bool $deslocarVencimentos)` e o Toggle na action, com o default pela origem.
2. Testes da seção 9.3.

As fases 2 e 3 podem sair juntas no mesmo commit. A Fase 3 é a proteção contra o efeito colateral da base `emissao`. **Não liberar a base `emissao` em produção sem a Fase 3.**

---

## 9. Testes e critérios de aceite

### 9.1 Fase 1
- Cadastro: criar e editar fornecedor com condições grava 1:1. A Section fica oculta para categoria diferente de fornecedor e para espelho de colaborador.
- Importador: fornecedor com `emissao` → `compra_data_entrada = dhEmi`. Sem condição ou com `hoje` → hoje (regressão).
- Modal: o default de `data_entrada` segue a regra. Fornecedor sem condição mantém o comportamento atual.

### 9.2 Fase 2
Teste unitário da `SugestaoPagamentoCompra` (sem Livewire):
- duplicatas válidas → linhas iguais às duplicatas, origem `duplicatas_nf`;
- duplicatas somando menos que o total → linha extra com a diferença e aviso; somando mais → aviso, sem linha extra;
- duplicata sem `dVenc` descartada; todas inválidas → cai no prazo;
- prazo com base `emissao` × `entrada` → vencimentos diferentes; prazo inativo ou excluído → parcela única;
- precedência da forma: parcela do prazo > fornecedor > `tPag` > `null`;
- conta excluída → `conta_financeira_id = null`;
- **toda linha tem as 6 chaves** (`assertArrayHasKey`) e `ja_pago === false`.

Feature (`ConfirmarCompraContaPagarTest`):
- montar a action com fornecedor configurado → o estado de `parcelas` vem preenchido, com `prazo_pagamento_id` selecionado;
- **conta sugerida sem "Já pago" não gera pagamento nem movimentação** (título Pendente, nenhum `LancamentoPagamento`);
- com "Já pago" marcado e a conta sugerida → título Pago e movimentação na conta;
- `compra_vencimento_origem` gravado conforme a sugestão e conforme a troca manual de prazo;
- `PrazoPagamentoResourceTest`: grava e edita `parcela_forma_pagamento`.

### 9.3 Fase 3
- origem `prazo_entrada`/`null` → o Toggle vem ligado e os vencimentos são deslocados (regressão do comportamento atual);
- origem `duplicatas_nf`/`prazo_emissao` → o Toggle vem desligado e os vencimentos ficam como estão;
- o usuário pode inverter o Toggle nos dois casos.

---

## 10. Riscos

| Risco | Mitigação |
|---|---|
| A sugestão vira "confirmar sem olhar" | Placeholder de fontes e avisos de divergência. "Já pago" nunca vem marcado. |
| Duplicatas erradas na nota do fornecedor | Avisos de soma e de divergência com o prazo. As linhas continuam editáveis. |
| A regra `emissao` muda o CMV por período | Comunicar a equipe e atualizar o manual de compras. Só vale para quem ativar no fornecedor. |
| Correção de data desloca vencimento que não devia | Fase 3 obrigatória junto com a base `emissao`. |
| Regressão do `$entangle` | Linha completa centralizada, teste de formato e QA em navegador real. |

---

## 11. Implantação

- Migrations aditivas, sem backfill: fornecedor sem condição e compra com `compra_vencimento_origem = null` mantêm o comportamento atual.
- Não há permissão nova: a condição é editada por quem pode editar o fornecedor (Policy de `Prestador`).
- Seguir o fluxo de sempre: `npm run build` + `public/build/` e `vendor/` no commit, promoção pelo `scripts/promote-producao.sh`, `migrate` no servidor e recarga do OPcache.

## 12. Fora do escopo (backlog)

- Parâmetro "não gerar conta a pagar" por fornecedor (bonificação/consignado). Hoje isso é decidido pela operação fiscal.
- Plano de despesa padrão por fornecedor (hoje vem do produto).
- Sugerir o fornecedor e as condições numa compra manual já no `CompraForm`. A sugestão vale no modal de confirmação, que é o ponto único.
