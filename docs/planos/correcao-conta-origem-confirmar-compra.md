# Correção: "Conta de origem é obrigatório" ao confirmar compra já paga

> Origem: relato de 20/10/2026. Ao confirmar a compra com a parcela marcada como **Já pago** e a **Conta de origem** escolhida, o modal não salva e mostra "O campo conta de origem é obrigatório."
> Diagnóstico de 20/10/2026, reproduzido em navegador real (Playwright + banco isolado) sobre o HEAD `dd378ef1`.
> Status: **implementado** (Fases 1, 2 e 3.1; commit 1a4af4b3). Pendentes: 3.2 (aguarda decisão do usuário), 3.4 e Fase 4 (fora do escopo).

## 1. Causa raiz (comprovada)

O bug só acontece quando um **Prazo de pagamento** é escolhido no modal, por exemplo "À Vista", que é o fluxo normal de quem compra e paga na hora.

1. Ao escolher o prazo, `afterStateUpdated` do Select chama `$set('parcelas', self::calcularParcelas(...))` (`app/Filament/Resources/Compras/Support/ConfirmarCompraAction.php:132`).
2. `calcularParcelas()` (`:334-342`) monta cada linha **só** com `vencimento`, `valor`, `forma_pagamento` e `ja_pago`. As chaves `conta_financeira_id` e `sessao_caixa_id` ficam de fora. O `$set` substitui o array e não hidrata os defaults dos campos filhos do Repeater.
3. Ao marcar "Já pago", o `ContaFinanceiraSelect` aparece. Como ele é um Select não nativo, liga o estado com `$wire.$entangle('mountedActions.0.data.parcelas.{uuid}.conta_financeira_id')`. A propriedade não existe, e o console mostra:
   `Livewire Entangle Error: Livewire property ['mountedActions.0.data.parcelas.<uuid>.conta_financeira_id'] cannot be found on component: ['app.filament.resources.compras.pages.edit-compra']`
4. Com o entangle quebrado, o usuário escolhe a conta na tela, mas **nenhuma requisição leva o valor ao servidor**. No envio, o estado continua sem a conta e a validação `required` falha.

### Evidência (requisições Livewire capturadas)

| Cenário | Depois de escolher a conta | Ao confirmar |
|---|---|---|
| Sem prazo (linha padrão do Repeater) | `updates: {...conta_financeira_id: "1"}` | Salva. Título nasce Pago e gera a movimentação |
| **Com prazo "À Vista"** | Só `getOptionsForJs`, **sem update** + Entangle Error | `errors: {...conta_financeira_id: ["O campo conta de origem é obrigatório."]}` |
| Com prazo + chaves criadas no estado antes (simulação da correção) | `updates: {...conta_financeira_id: "1"}` | Salva normalmente |

### Por que os testes não pegaram

`Livewire::test()->set('...conta_financeira_id', $id)` grava no servidor com `data_set`, que **cria** a chave que falta. No navegador, o caminho é o `$entangle`, que **exige** que a chave já exista. Nenhum teste atual combina prazo + já pago, e mesmo que combinasse, passaria. Por isso o teste de regressão precisa verificar a **forma** do estado depois do `$set` do prazo (seção 2.2).

As tentativas anteriores (`dc477b92`, sessão de caixa mais evidente, e `ec073da6`, `resetErrorBag` ao escolher a conta) não eram a causa. Podem ficar, porque são melhorias de UX válidas. O `dd378ef1` (DIAG) precisa ser removido.

## 2. Fase 1: correção (obrigatória)

### 2.1 Linha de parcela com formato único

Em `ConfirmarCompraAction`, crie um helper privado que devolva a linha completa e use-o nos **dois** pontos que montam parcelas:

```php
/**
 * Linha do Repeater de parcelas com todas as chaves dos campos filhos.
 * Linha sem a chave quebra o $entangle dos Selects não nativos (a escolha
 * nunca chega ao servidor): o $set('parcelas', ...) não hidrata defaults.
 *
 * @return array{vencimento: string, valor: string, forma_pagamento: null, ja_pago: false, conta_financeira_id: null, sessao_caixa_id: null}
 */
private static function linhaParcela(string $vencimento, string $valor): array
```

- `->default(...)` do Repeater (`:169-176`): passa a usar `linhaParcela(...)`.
- `calcularParcelas()` (`:336-341`): passa a usar `linhaParcela(...)` e mantém as chaves UUID.
- Atualize o `@return` do PHPDoc de `calcularParcelas()` (`:309`).

Não use `->dehydrated(false)` nem `->visible()` diferente nesses campos. A correção é só o formato do estado.

### 2.2 Teste de regressão

Em `tests/Feature/ConfirmarCompraContaPagarTest.php`:

- `test_parcelas_calculadas_pelo_prazo_trazem_os_campos_de_pagamento`: monta a action, faz `set('mountedActions.0.data.prazo_pagamento_id', $prazo->id)` e, em **cada** linha de `get('mountedActions.0.data.parcelas')`, verifica `assertArrayHasKey('conta_financeira_id', $linha)` e `assertArrayHasKey('sessao_caixa_id', $linha)`. Use `assertArrayHasKey` e não `assertSet(null)`, porque uma chave ausente também devolve `null`.
- `test_prazo_a_vista_ja_pago_com_conta_confirma_e_gera_titulo_pago`: prazo de 1 parcela (0 dias, 100%), `ja_pago = true`, conta escolhida, `callMountedAction()`. Verifique compra `CONFIRMADA`, lançamento `Pago` e `lancamento_pagamentos.conta_financeira_id` preenchido. É o fluxo de ponta a ponta do relato, mas sozinho não reproduz o bug (ver "Por que os testes não pegaram").

Rode `ConfirmarCompraContaPagarTest` inteiro num banco isolado (ver memória de testes em banco isolado).

### 2.3 QA em navegador (obrigatório para este bug)

O teste PHPUnit não enxerga o `$entangle`. Repita no navegador, com o DevTools aberto (console sem "Entangle Error"):

1. Prazo "À Vista" → Já pago → conta → Confirmar: deve salvar.
2. Prazo "7/14/21" → marcar já pago só na 1ª parcela → conta → Confirmar: 1 título Pago e 2 Pendentes.
3. Trocar de prazo depois de marcar já pago: as linhas são recriadas e o já pago volta para falso, que é o esperado. Marcar de novo e confirmar.
4. Prazo escolhido + "Adicionar parcela" → já pago na linha nova → conta → Confirmar.
5. Sem prazo (fluxo que já funcionava) continua funcionando.
6. A mesma sequência pela **tabela** de Compras (`ListCompras`), não só pela página de edição.

## 3. Fase 2: limpeza do diagnóstico (obrigatória, junto da Fase 1)

Remova tudo que estiver marcado com `// DIAG-TEMP` (commit `dd378ef1`, que já está em `producao` via `6fcf7a16`):

- `ConfirmarCompraAction.php`: `use Illuminate\Support\Facades\Log;` (`:27`), `use Livewire\Component;` (`:32`) e o bloco `->beforeFormValidated(...)` (`:65-67`).
- `app/Filament/Components/ContaFinanceiraSelect.php`: `use Illuminate\Support\Facades\Log;` (`:12`) e a linha `Log::info('DIAG-CONTA ...')` (`:42`).

Confirme com `grep -rn "DIAG" app` → vazio.

## 4. Fase 3: consistência de regras (recomendado, mesmo PR ou o seguinte)

| # | Problema | Onde | Proposta |
|---|---|---|---|
| 3.1 | Com forma **Compensação**, a confirmação ainda exige conta, mas `BaixaFinanceira::preparar()` (`:43-46`) descarta a conta nesse caso. Na tela "Registrar pagamento" a conta já é dispensada (`LancamentosTable.php:492-496`) | `ConfirmarCompraAction.php:162-165` | `required`/`visible` = `ja_pago && forma !== Compensacao`. Reaproveite a normalização de enum (`LancamentosTable::forma()`), porque `$get('forma_pagamento')` pode vir como enum ou string |
| 3.2 | Com "Já pago", o campo vira "Data do pagamento", mas aceita data futura. Isso gera movimentação futura na conta e mexe no `saldoEm()` | `ConfirmarCompraAction.php:144-148` | `->maxDate(fn (Get $get) => $get('ja_pago') ? today() : null)`. **Confirmar com o usuário** se existe caso de pagamento agendado |
| 3.3 | Padrão frágil: linha de Repeater montada à mão fora do `default()` | Hoje só existe em `calcularParcelas` (varredura de `$set(` em `app/Filament` não achou outro caso) | O helper da Fase 1 resolve. Ao revisar código novo, desconfie de `$set('<repeater>', [...])` com Select não nativo nos filhos |
| 3.4 | `->key(fn (Get $get) => 'parcelas-'.prazo)` no Repeater (`:142`) foi uma das tentativas especulativas do bug de valor zerado (agosto), não a causa | `ConfirmarCompraAction.php:136-142` | **Não mexer agora.** Se quiser remover, faça em PR separado com o QA da seção 2.3 (o valor das parcelas tem de aparecer certo ao trocar de prazo) |

## 5. Fase 4: inovações (backlog, cada item precisa de OK do usuário)

Todas partem do que o código já tem:

1. **Prazo à vista sugere "Já pago"**: quando a única parcela do prazo tem `parcela_dias = 0`, `calcularParcelas` pode marcar `ja_pago = true` e mostrar a Conta de origem já de cara. Reduz cliques no caso mais comum.
2. **Conta sugerida pela forma** (o inverso do `sugerirForma()` atual): ao escolher PIX/Transferência, sugere a conta bancária; ao escolher Dinheiro, o Caixa PDV ou o Cofre. Precisa de um mapeamento forma → conta padrão (nova coluna em `contas_financeiras` ou configuração).
3. **Padrões por fornecedor**: guardar no `Prestador` o prazo, a forma e a conta usados na última compra confirmada e pré-preencher o modal. Notas importadas por XML chegam sem nada disso.
4. **Aviso de saldo ao vivo**: o rótulo da conta já mostra o saldo. Um aviso na linha quando `valor > saldo` (e a conta não permite negativo) antecipa a `FinanceiroException` que hoje só aparece depois de clicar em Confirmar, como notificação, com a transação inteira desfeita.
5. **Relação de leitura na Compra**: `Compra` tem `lancamentos()`, mas nada que mostre de quais contas saiu o dinheiro. Um `hasManyThrough` para `LancamentoPagamento`/`MovimentacaoFinanceira` permitiria listar, na tela da compra, "Pago via Banco X em dd/mm".

## 6. Entrega

- Só PHP, sem asset: não precisa de `npm run build`. Commitar apenas os arquivos tocados, listados por nome.
- Depois do push em `prod-new`, promova para `producao` (já autorizado) e lembre do reload do OPcache em produção.
- Critério de aceite: os 6 cenários da seção 2.3 passam no navegador, o console fica sem "Entangle Error", `ConfirmarCompraContaPagarTest` passa verde e não sobra nenhum `DIAG` no código.
