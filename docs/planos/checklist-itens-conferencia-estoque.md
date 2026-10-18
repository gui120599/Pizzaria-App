# Checklists: itens de "fazer se precisar" (conferir estoque) e ajustes de uso

> Origem: análise dos primeiros registros reais do módulo de checklists (execuções de 13/10 a 17/10/2026, banco local `laravel`). Diagnóstico feito em 18/10/2026.
> Status: **F1–F5 implementadas em 2026-10-18 (não commitado).**
> Spec do módulo: `docs/modulo-checklists.md`. Este plano altera os §4.2, §5.1 e §5.2 de lá (atualizar o spec junto com o código).

## 1. O que os dados mostram

### 1.1 Cadastro

| Modelo | Dias | Itens | Execuções |
|---|---|---|---|
| #1 Abertura Cozinha 1 (versão 7) | ter–dom | 9 (1 seção "Geral") | 4 (13, 15, 16, 17/10) |
| #2 Abertura Cozinha 2 | ter–sex | 12 (Legumes + Carnes) | **nenhuma** |

- Os dois modelos são atribuídos só à role `Cozinha` e executados pelo mesmo usuário (#29).
- 19 dos 21 itens estão como `critico = 1`. 20 são do tipo `check` e 1 é `sim_nao`.
- 18 itens usam `exige_foto = se_nao_conforme`. Todos usam `exige_observacao_nao_conforme = 1`.
- As instruções já descrevem itens condicionais em texto livre: "*Verificar o estoque antes do porcionamento.*", "*Verificar estoque e fatiar quando necessário*", "*Conferir se tem muita na vasilha", "*Uma vez por semana".

### 1.2 Execuções: o problema central

Foram 9 respostas "Não feito", e **todas** são falsos negativos. O item não foi feito porque já havia estoque:

| Data | Item | Observação digitada | Foto |
|---|---|---|---|
| 13/10 | Fatiar Presunto e Porcionar | "Não precisou" | 1 |
| 13/10 | Fatiar Calabresa e Porcionar | "Não precisou" | 0 |
| 15/10 | Fatiar Presunto e Porcionar | "Já tem pronto e fatiado" | 1 |
| 15/10 | Fazer brigadeiro | "Tem pronto" | 1 |
| 15/10 | Fatiar lombinho | "Tem pronto" | 0 |
| 16/10 | Fatiar Calabresa e Porcionar | "Tem feito" | 1 |
| 16/10 | Fatiar lombinho | "Tem feito" | 0 |
| 17/10 | Fatiar Presunto e Porcionar | "Tem pronto" | 1 |
| 17/10 | Fazer brigadeiro | "Fiz ontem" | 1 |

Consequências de cada resposta:
- **9 não conformidades abertas**, todas críticas. A gerência resolveu 5 à mão com o texto "Possuía estoque" / "não houve necessidade de fatiar", e **4 continuam abertas** (NC #6–#9) sem nada a corrigir.
- **22 notificações `ChecklistItemCriticoNotification`** enviadas aos gestores em 5 dias, todas sem problema real.
- A conformidade ficou entre **66% e 78%**, quando na prática a abertura foi 100% cumprida. Com isso, o dashboard e o widget "itens que mais falham" mostram um quadro falso.
- A equipe digitou observação e tirou foto (6 fotos) em todos os casos só para conseguir finalizar.

### 1.3 Outros achados

1. **Instrução sem quebra de linha.** As receitas (molho, brigadeiro, carne) foram cadastradas em várias linhas, mas `execucao-checklist.blade.php:163` e a pré-visualização renderizam `{{ $item->instrucao }}` num `<p>` comum. Tudo vira um parágrafo corrido.
2. **Item semanal dentro de checklist diário.** "Fazer brigadeiro" é feito "uma vez por semana", mas aparece todo dia.
3. **O agendador não está rodando** no ambiente analisado. Nunca houve execução `pendente` nem `nao_realizado`. O modelo #2 nunca gerou execução, e o dia 14/10 (quarta, agendado no #1) não tem registro. Como as execuções só nascem quando alguém clica, a gerência **não fica sabendo** de checklist que não foi feito. Isso é configuração de servidor, não código (ver §6).
4. A equipe usa o checklist como roteiro ao longo da produção: as respostas de 16/10 vão de 16:12 a 17:50, uma a uma. Isso confirma o autosave por item como o fluxo certo.
5. Há erros de digitação no cadastro: "Codimentos", "vazilha", "precione", "muido", "colherde cha". A correção é de dados (ver §6).

## 2. Decisões propostas

| # | Decisão | Motivo |
|---|---|---|
| D1 | Criar o tipo de resposta **`producao`**, com o rótulo "Fazer se precisar (conferir estoque)" e 3 respostas fixas: **Feito**, **Tinha estoque** e **Não feito** | Descreve exatamente o caso real. O tipo `selecao` até permitiria montar isso, mas exigiria configurar opções item a item e não diferencia "dispensado" de "feito" nos relatórios |
| D2 | "Tinha estoque" é **conforme**. Não gera NC nem notificação e não exige observação | O item foi conferido e está em ordem. É o que a gerência já registrava à mão ("Possuía estoque") |
| D3 | "Não feito" continua não conforme, com NC, foto/observação conforme o cadastro e notificação se o item for crítico | Agora "Não feito" significa de fato "faltou e ninguém fez", que é o caso que importa |
| D4 | Campo opcional **"Quando fazer"** (`criterio`), por exemplo "quando tiver menos de 10 sacos" | Deixa a decisão "tinha estoque" auditável e tira o critério do meio da instrução |
| D5 | Toggle opcional **"Pedir foto quando marcar 'Tinha estoque'"** (`exige_foto_estoque`, padrão desligado) | Evita que "Tinha estoque" vire uma saída fácil para quem não quer fazer. A gerência liga onde achar necessário |
| D6 | **Dias da semana por item** (`dias_semana` no item, `null` = todos os dias do modelo). O item fora do dia não entra no snapshot | Resolve o brigadeiro semanal sem precisar criar outro modelo |
| D7 | O **histórico não é reescrito**. As 9 respostas antigas continuam "Não feito" (princípio do snapshot, §1 do spec). As 4 NCs abertas são canceladas pela gerência com a nova ação em massa (F4) | Mantém a auditoria. O widget de 30 dias se normaliza sozinho |
| D8 | Instrução renderizada com quebras de linha, recolhida em 2 linhas com "Ver instrução completa" | Corrige as receitas ilegíveis e encurta a tela no celular |

> Se o usuário discordar de D5 ou D6, retire a fase ou o campo correspondente. As demais fases não dependem deles.

## 3. Modelagem

### 3.1 Enum de respostas — `app/Enums/Checklist/RespostaProducao.php` (novo)

```php
enum RespostaProducao: string implements HasColor, HasIcon, HasLabel
{
    case Feito = 'feito';
    case TinhaEstoque = 'tinha_estoque';
    case NaoFeito = 'nao_feito';

    // getLabel: 'Feito' | 'Tinha estoque' | 'Não feito'
    // getColor: success | info | danger
    // getIcon: Heroicon::OutlinedCheck | OutlinedArchiveBox | OutlinedXMark
    public function conforme(): bool { return $this !== self::NaoFeito; }
}
```

### 3.2 `TipoResposta` (`app/Enums/Checklist/TipoResposta.php`)

- Novo case `Producao = 'producao'`, com o rótulo "Fazer se precisar (conferir estoque)" e ícone `Heroicon::OutlinedArchiveBoxArrowDown` (ou similar que exista).
- `colunaValor()`: `Producao` → `'valor_opcao'`.

### 3.3 Migration — `add_producao_fields_to_checklist_modelo_itens` (nova)

Na tabela `checklist_modelo_itens`:
- `criterio` string(200) nullable, depois de `instrucao`
- `exige_foto_estoque` boolean default false, depois de `exige_foto`
- `dias_semana` json nullable, depois de `ordem` (array de ints ISO 1–7, mesmo formato de `checklist_modelos.dias_semana`)

`checklist_execucao_itens` não muda: as regras novas vão no `config` (snapshot). A coluna `valor_opcao` string(50) já comporta `tinha_estoque`.

### 3.4 `ChecklistModeloItem`

- Adicionar os 3 campos em `$fillable`, com cast `exige_foto_estoque` → boolean e `dias_semana` → array.
- `toSnapshot()`: incluir em `config` as chaves `criterio`, `exige_foto_estoque` e `dias_semana`. Como `ChecklistModeloService::assinatura()` usa `toSnapshot()`, alterar esses campos gera nova versão sem nenhuma mudança no service.
- Novo método `agendadoPara(CarbonInterface $dia): bool`, que retorna `true` quando `dias_semana` está vazio ou contém `$dia->isoWeekday()`.

### 3.5 `ChecklistExecucaoItem`

- `respostaProducao(): ?RespostaProducao`, que retorna `RespostaProducao::tryFrom($this->valor_opcao)` quando o tipo é `Producao`.
- `foiDispensado(): bool`, verdadeiro quando o tipo é `Producao` e a resposta é `TinhaEstoque`.
- `exigeFotoEstoque(): bool` e `criterio(): ?string`, lendo de `regra(...)`.
- `respostaFormatada()`: `Producao` → rótulo do enum.

## 4. Implementação por fases

Pare ao fim de cada fase, mostre o resumo e aguarde o OK (padrão do módulo). Não rode `migrate:fresh` nem `db:wipe`. Rode os testes no banco isolado `testing_checklists` (ver memória de testes isolados).

### F1 — Tipo `producao` no domínio (service + avaliador)

1. Enum `RespostaProducao` e case novo em `TipoResposta` (§3.1–3.2).
2. Migration + model `ChecklistModeloItem` (§3.3–3.4). Por enquanto, só `criterio` e `exige_foto_estoque`. `dias_semana` entra na F3.
3. `ConformidadeAvaliador::avaliar()`: `Producao` → `respostaProducao()?->conforme()` (`null` sem resposta).
4. `ChecklistExecucaoService`:
   - `aplicarValor()`: `Producao` → `normalizarRespostaProducao($valor)`, que aceita apenas os valores do enum e, fora disso, lança `ValidationException` com "Resposta inválida.".
   - `validarFinalizacao()`:
     - `ExigenciaFoto::SeNaoConforme` já cobre "Não feito", porque `conforme === false`. Não precisa mudar.
     - Regra nova: `foiDispensado() && exigeFotoEstoque() && anexos_count === 0` → "Tire uma foto do estoque.".
     - A observação obrigatória continua só para `conforme === false`. Sem mudança.
   - `descricaoNaoConformidade()`: sem mudança, porque usa `respostaFormatada()`.
5. Factory `ChecklistModeloItemFactory`: state `producao()`. Ver se a `ChecklistExecucaoItemFactory` precisa de state equivalente.
6. Testes, em `ChecklistExecucaoServiceTest` (ou um `ChecklistProducaoTest` novo, se ficar grande):
   - "Feito" → conforme. "Tinha estoque" → conforme, sem NC e sem notificação, mesmo com o item crítico. "Não feito" → não conforme, cria NC e notifica se for crítico.
   - Finalizar com "Tinha estoque" sem observação e sem foto passa, mesmo com `exige_foto = se_nao_conforme`.
   - Com `exige_foto_estoque`, "Tinha estoque" sem foto bloqueia a finalização.
   - Valor inválido (`'talvez'`) → `ValidationException`.
   - `percentual_conformidade` conta "Tinha estoque" como conforme.
   - Trocar "Não feito" → "Tinha estoque" antes de finalizar não deixa NC. Reaberta após reprovação, a NC existente é cancelada (fluxo atual de `sincronizarNaoConformidades`).

### F2 — Telas (execução, cadastro, pré-visualização, infolist)

1. **Execução** — `resources/views/livewire/checklists/execucao-checklist.blade.php` (componente compartilhado entre `/garcom` e cozinha):
   - Bloco novo para `TipoResposta::Producao`: grid de 3 botões (`grid-cols-3`, `py-4`, texto `text-sm` no celular), com "✓ Feito" (emerald), "Tinha estoque" (sky/azul, ícone de caixa) e "✗ Não feito" (red). Cada um chama `wire:click="responder({{ $item->id }}, '<valor>')"`, igual ao bloco de `selecao`. Conferir se `ExecucaoChecklist::responder()` repassa a string sem conversão. A seleção para `Selecao` já funciona assim.
   - Se houver `criterio`, mostrar acima dos botões a linha "**Fazer quando:** {criterio}".
   - `$pedeFoto`: incluir `$item->foiDispensado() && $item->exigeFotoEstoque()`.
   - Anel do card: dispensado → `ring-sky-500` em vez de emerald, para a equipe distinguir "fiz" de "só conferi".
   - Modo leitura (`! $podeResponder`): `respostaFormatada()` já resolve. Para dispensado, usar a cor sky.
   - **Instrução (D8):** `whitespace-pre-line` no `<p>`. Se passar de cerca de 2 linhas, recolher com Alpine (`line-clamp-2` + botão "Ver instrução completa" / "Recolher"). Aplicar também em `resources/views/filament/checklists/pre-visualizar-modelo.blade.php`.
   - Lembrete do memory de checklists: se um `x-show` depender de um estado que muda na re-renderização, usar `wire:key` que muda junto.
   - QA visual em 390px (garçom) e em tablet paisagem (cozinha).
2. **Cadastro** — `app/Filament/Resources/ChecklistModelos/Schemas/ChecklistModeloForm.php`, em `camposDoItem()`:
   - `TextInput::make('criterio')` com label "Quando fazer", `maxLength(200)`, placeholder "Ex.: quando tiver menos de 10 sacos" e `visible` só para `Producao`.
   - `Toggle::make('exige_foto_estoque')` com label "Pedir foto quando marcar 'Tinha estoque'", `visible` só para `Producao`.
   - `exige_foto`: para `Producao`, o helperText deve deixar claro que "Só se não conforme" vale para "Não feito".
   - `critico`: trocar o helperText para "Avisa a gerência na hora se ficar não conforme. Use só quando a falha impede a venda ou põe a segurança em risco." Os dados mostraram uso indiscriminado.
   - O resumo do item no repeater (`itemLabel`, por volta da linha 265) já mostra o rótulo do tipo e passa a exibir o novo sem mudança.
3. **Pré-visualização** (`pre-visualizar-modelo.blade.php`): renderizar os 3 botões (desabilitados) e o critério.
4. **Infolist da execução** (`ChecklistExecucaoInfolist.php`): a resposta "Tinha estoque" deve aparecer com cor/ícone `info`, e não como conforme genérico. Ver como o ícone de conformidade é montado hoje e acrescentar o caso.
5. Testes: `ExecutarChecklistTest` (Livewire: responder as 3 opções, critério visível), `ChecklistModeloResourceTest` (criar item `producao` com critério e toggle; campos invisíveis em outros tipos).
6. `vendor/bin/sail npm run build` ao final, porque a blade usa classes novas do Tailwind (`ring-sky-500`, `line-clamp-2`, `whitespace-pre-line`).

### F3 — Dias da semana por item (D6)

1. A migration da F1 já cria `dias_semana`. Se F1 e F3 forem feitas juntas, use uma migration só.
2. `ChecklistExecucaoService::copiarItens()` recebe o dia (`$execucao->data_referencia`) e pula os itens em que `! $item->agendadoPara($dia)`. A `ordem` continua sequencial entre os itens copiados. `regerarSnapshot()` passa pelo mesmo caminho.
3. Form: `CheckboxList::make('dias_semana')` no item, com label "Só nestes dias", as mesmas opções/rótulos usados no modelo, `columns(7)`, helperText "Vazio = todos os dias do checklist" e o campo recolhido/compacto. Reaproveitar as opções de dias do form do modelo, sem duplicar o array.
4. No `itemLabel` do repeater, acrescentar "só Seg" (etc.) quando houver restrição.
5. Testes: item restrito não entra na execução de um dia fora da lista e entra no dia certo. Alterar `dias_semana` gera nova versão (assinatura) e regera execução pendente sem respostas.

### F4 — Gestão: NCs em massa e dashboard

1. **Cancelar NCs em massa** — `ChecklistNaoConformidadesTable.php`, `toolbarActions`:
   - `BulkAction::make('cancelar')` com o label "Cancelar selecionadas" e um formulário de motivo obrigatório (`Textarea observacao_resolucao`). Para cada registro pendente, chama `ChecklistNaoConformidadeService::atualizar($nc, ['status' => Cancelada, 'observacao_resolucao' => $motivo], $user)`. Ver no service se ele já grava `resolvida_por`/`resolvida_em` ao cancelar e, se não gravar, ajustar lá.
   - Autorização explícita: pular (e contar) os registros que o usuário não pode `update` pela policy, e só mexer em NC com status pendente. Lembrete das memórias: ações de relação/attach no Filament 4 ignoram a policy, então não presuma que a bulk action respeita.
   - Ação `deselectRecordsAfterCompletion()` e notificação com "X canceladas, Y ignoradas".
2. **Widget "Itens mais dispensados"**, ao lado de `ChecklistItensMaisFalhamWidget`, seguindo o mesmo formato:
   - Top 10 dos últimos 30 dias de itens `tipo_resposta = producao` com `valor_opcao = tinha_estoque`, com a coluna "dispensado X de Y execuções".
   - Serve para ajustar a frequência: um item dispensado em 80% dos dias deve virar semanal (F3) ou ter o critério revisto.
   - `use HasWidgetShield` como no widget vizinho e registro na página `ChecklistsDashboard`. Rodar o seed/permission do Shield, se o padrão do projeto exigir (ver memória de gotchas do Shield).
3. Testes: bulk action cancela só as pendentes e grava motivo/autor. O widget conta só `tinha_estoque` do período.

### F5 — Spec e fechamento

1. Atualizar `docs/modulo-checklists.md`:
   - §4.2: `TipoResposta` com `producao`; novo enum `RespostaProducao`.
   - §4.3: novas colunas de `checklist_modelo_itens`.
   - §5.1: linha `producao` na tabela de conformidade.
   - §5.2: regra de foto do estoque.
   - §6.1: novos campos do form.
2. Os manuais `docs/Manual-Checklists-{Equipe,Gerencia}.pdf` ficam desatualizados. **Perguntar ao usuário** se deve regerá-los, não refazer por conta própria.
3. `vendor/bin/sail bin pint --dirty --format agent`, nunca em pasta inteira.
4. Rodar toda a suíte de checklists (`--filter=Checklist` + `CozinhaChecklistsTest`, `GarcomChecklistAcessoTest`, `HistoricoChecklistGarcomTest`, `ExecutarChecklistTest`) no banco isolado.
5. Commit: listar os arquivos por nome, nunca `git add -A`. A árvore tem muitas alterações de outras sessões. Incluir `public/build/` (sem npm no servidor). Push/promoção conforme as memórias do projeto.

## 5. Arquivos envolvidos

| Arquivo | Fase |
|---|---|
| `app/Enums/Checklist/RespostaProducao.php` (novo) | F1 |
| `app/Enums/Checklist/TipoResposta.php` | F1 |
| `database/migrations/2026_10_xx_add_producao_fields_to_checklist_modelo_itens.php` (novo) | F1/F3 |
| `app/Models/ChecklistModeloItem.php` | F1/F3 |
| `app/Models/ChecklistExecucaoItem.php` | F1 |
| `app/Services/Checklist/ConformidadeAvaliador.php` | F1 |
| `app/Services/Checklist/ChecklistExecucaoService.php` | F1/F3 |
| `database/factories/ChecklistModeloItemFactory.php` | F1 |
| `resources/views/livewire/checklists/execucao-checklist.blade.php` | F2 |
| `app/Livewire/Checklists/ExecucaoChecklist.php` (só se `responder()` precisar de ajuste) | F2 |
| `resources/views/filament/checklists/pre-visualizar-modelo.blade.php` | F2 |
| `app/Filament/Resources/ChecklistModelos/Schemas/ChecklistModeloForm.php` | F2/F3 |
| `app/Filament/Resources/ChecklistExecucoes/Schemas/ChecklistExecucaoInfolist.php` | F2 |
| `app/Filament/Resources/ChecklistNaoConformidades/Tables/ChecklistNaoConformidadesTable.php` | F4 |
| `app/Services/Checklist/ChecklistNaoConformidadeService.php` (se precisar) | F4 |
| `app/Filament/Widgets/ChecklistItensMaisDispensadosWidget.php` (novo) + `ChecklistsDashboard` | F4 |
| `docs/modulo-checklists.md` | F5 |
| Testes em `tests/Feature/Checklist*`, `ExecutarChecklistTest` | todas |

## 6. Depois do deploy (dados e servidor, sem código)

### 6.1 Servidor
- Configurar o cron `* * * * * php artisan schedule:run` em produção. Sem ele, `checklists:gerar-pendentes` e `checklists:expirar` não rodam e o "não realizado" nunca chega à gerência (achado 1.3.3).
- `migrate --force`, `optimize:clear` e reset do OPcache (padrão de deploy do projeto).

### 6.2 Reconfiguração dos itens (pela gerência, no /admin)

Fazer pelo formulário do modelo, não por SQL: assim o versionamento regera as execuções pendentes. A tabela abaixo é uma sugestão e precisa ser confirmada com quem opera a cozinha.

**Modelo #1 — Abertura Cozinha 1**

| Item | Hoje | Sugestão | Crítico | Quando fazer (exemplo) |
|---|---|---|---|---|
| Retirar Condimentos do Freezer | check | manter `check` | sim | — |
| Ralar Mussarela | check | manter `check` (feito 4/4) | sim | — |
| Porcionar Mussarela | check | **producao** | sim | menos de X sacos porcionados |
| Fatiar Presunto e Porcionar | check | **producao** (estoque 3/4 dias) | não | menos de X pacotes |
| Fatiar Calabresa e Porcionar | check | **producao** (estoque 2/4) | não | menos de X pacotes |
| Produção Molho para Pizzas | check | manter `check` (feito 4/4) | sim | — |
| Verificação de Queijos | sim_nao | **producao** | não | sem queijo porcionado |
| Fazer brigadeiro | check | **producao** + dia fixo (F3) | não | 1× por semana ou quando acabar |
| Fatiar lombinho | check | **producao** (estoque 2/3) | não | menos de X pacotes |

**Modelo #2 — Abertura Cozinha 2** (nunca executado, sugestão baseada só nas instruções)

| Item | Sugestão |
|---|---|
| Fatiar Cebola, Fatiar Tomate, Tomate pra X, Alface, Abacaxi pra X | manter `check` (preparo fresco do dia), a confirmar |
| Milho pra pizza, Palmito, Azeitona, Milho pra X | **producao** ("Azeitona" já diz "conferir se tem muita na vasilha") |
| Carne pra X, Carne pra pastel, Frango moído pro X | **producao** (congelado com validade de 1 mês) |

- Depois de criar o tipo `producao`, remover das instruções os trechos "*Verificar estoque…*", que passam a ser o próprio tipo + "Quando fazer".
- Revisar o `critico`: deixar só onde a falta impede a venda. Com isso, as 22 notificações em 5 dias caem para perto de zero.
- Corrigir os erros de digitação: Codimentos→Condimentos, vazilha→vasilha, precione→pressione, muido→moído, "colherde cha"→"colher de chá", agua→água, ate→até.
- Cancelar as NCs abertas #6–#9 com a ação em massa da F4, com o motivo "Falso positivo: havia estoque (antes do tipo 'Fazer se precisar')".
- Verificar por que o modelo #2 nunca foi executado: se a equipe não vê, se é longo demais ou se é de outro turno.

## 7. Fora de escopo (backlog)

- **Ligar a produção ao estoque:** gravar a quantidade produzida ("rendeu 15 sacos") e gerar a movimentação de produção interna no módulo de estoque/CMV, com sugestão automática de "Tinha estoque" pelo saldo. Isso depende de cadastrar os insumos porcionados como produto.
- Respostas com rótulos customizáveis no `sim_nao` (ex.: "OK / Com problema").
- Reclassificar o histórico das 9 respostas antigas.
- Duração por item (`respondido_em` mostra o ritmo da produção), útil para dimensionar a equipe.

## 8. Critérios de aceite

- [ ] A cozinha responde "Tinha estoque" com um toque, sem observação e sem foto (salvo `exige_foto_estoque`), e a execução fecha como **Concluído** com 100%.
- [ ] "Não feito" num item `producao` continua gerando NC, pedindo observação/foto conforme o cadastro e notificando se for crítico.
- [ ] Nenhuma notificação de item crítico é enviada por "Tinha estoque".
- [ ] O critério "Quando fazer" aparece na execução e na pré-visualização.
- [ ] As instruções com várias linhas aparecem com as quebras, recolhidas por padrão no celular.
- [ ] Item com dias da semana só aparece nos dias marcados. A mudança gera nova versão do modelo.
- [ ] A gerência cancela NCs em massa com motivo, e o dashboard mostra os itens mais dispensados.
- [ ] Os testes do módulo passam no banco isolado, o Pint está limpo e o build foi gerado.
