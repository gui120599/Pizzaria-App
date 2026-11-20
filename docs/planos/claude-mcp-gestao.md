# Claude + PizzariaAPP (MCP) — roteiro de novas funcionalidades

> Aprovado em 2026-11-19. Cada etapa vira uma fase com checkpoint, no padrão de `docs/planos/`. A etapa G0 é a Fase 5 de `compras-automatizadas.md` (verificação em `compras-automatizadas-fase5.md`).

## Contexto

Hoje o Claude fala com o sistema por um único servidor MCP, `/mcp/compras`. Ele é usado pelo agente de compras: um usuário técnico (papel Agente), com 9 tools de leitura e de proposta, numa rotina diária. A Fase 5, compras avulsas num servidor separado `/mcp/compras-avulsa` para usuário humano, está planejada (`docs/planos/compras-automatizadas-fase5.md`) e aguarda o checkpoint 5.0.

O pedido é mapear o que mais do sistema vale levar para o Claude, tanto em perguntas no chat quanto em tarefas no Cowork ou em rotinas agendadas. O sistema tem cerca de 60 resources e 25 páginas, cobrindo:
- pedidos, vendas e caixa;
- financeiro, estoque/CMV e fichas técnicas;
- promoções, cozinha, checklists, ponto, influenciadores, fiscal e Stone.

Muitos desses módulos já têm um *service* de relatório pronto para reaproveitar.

## Princípios (valem para tudo abaixo)

1. **Um servidor por nível de confiança, não por módulo.**
   - `/mcp/compras` (agente, já existe);
   - `/mcp/compras-avulsa` (humano, Fase 5);
   - **novo `/mcp/gestao`** (humano: dono e gerente) para perguntas e ações confirmadas.
   - Cada conector do Claude guarda um login, então separar por quem loga evita misturar permissões.
2. **Tools filtradas pela permissão Spatie do usuário logado.** O `shouldRegister()` do `laravel/mcp` v1.2 já funciona assim, como na Fase 5.
3. **Leitura primeiro.** Escrita só no padrão *rascunho → resumo → confirmação explícita do humano*, o mesmo da compra avulsa. Nenhuma tool movimenta dinheiro sem confirmação humana, e o agente técnico nunca escreve.
4. **Reaproveitar os services de relatório**, para o número que o Claude responde ser o mesmo da tela:
   - `RelatorioFechamentoCaixaService`, `RelatorioContasPagarReceberService`, `DebitosClienteService`;
   - `RelatorioExcecoesPedidosService`, `RelatorioCortesiasService`, `RelatorioVendasExportService`;
   - `RelatorioTaxaServicoService`, `RelatorioFiscalNotasService`, `RelatorioInfluenciadoresService`;
   - `CaixaPdvBridge::dinheiroDisponivel`, `PrecificadorService`;
   - dados de `CustoMedioHistorico` e `RelatorioProducao`.
5. **LGPD:** tools agregadas por padrão; telefone e endereço de cliente mascarados; todo acesso registrado num log, como o canal `agente_compras`.

## Catálogo — o que implementar

### A. Perguntas no chat (servidor `/mcp/gestao`, só leitura) — maior valor, menor risco

| Área | Tools | Exemplo de pergunta | Reaproveita |
|---|---|---|---|
| Vendas e pedidos | `resumo_vendas(periodo, canal)`, `ranking_produtos`, `comparar_periodos` | "Como foi a sexta comparada à semana passada?" | `RelatorioVendasExportService`, `PedidosDashboard` |
| Caixa | `situacao_caixa_agora`, `fechamento_caixa(data)` | "Quanto tem na gaveta? O fechamento de ontem bateu?" | `CaixaPdvBridge`, `RelatorioFechamentoCaixaService` |
| Financeiro | `contas_a_pagar_receber(dias)`, `saldos_contas`, `resultado_periodo` (DRE) | "O que vence esta semana e tenho saldo?" | `RelatorioContasPagarReceberService`, `FinanceiroDashboard` |
| Fiado | `debitos_clientes`, `historico_cliente` | "Quem me deve há mais de 30 dias?" | `DebitosClienteService` |
| Estoque e CMV | `saldo_estoque`, `custo_insumo_historico`, `margem_produto` | "Quanto me custa uma calabresa grande hoje? A margem caiu?" | fichas técnicas, `CustoMedioHistorico`, `PrecificadorService` |
| Exceções e controle | `excecoes_pedidos`, `cortesias_periodo` | "Quem mais cancelou pedidos esta semana?" | `RelatorioExcecoesPedidosService`, `RelatorioCortesiasService` |
| Equipe | `quem_esta_no_turno`, `resumo_ponto(periodo)`, `checklists_pendentes` | "Alguém faltou hoje? Os checklists de abertura foram feitos?" | services de `Ponto` e `Checklist` |
| Promoções e marketing | `desempenho_promocao`, `ranking_influenciadores` | "O combo de 2 pizzas vendeu quanto?" | services `PromocaoCombo`/`Relampago`, `RelatorioInfluenciadoresService` |
| Fiscal e Stone | `notas_fiscais_com_falha`, `transacoes_stone_pendentes` | "Tem NFC-e rejeitada? Pagamento Stone travado?" | `RelatorioFiscalNotasService`, `StonePedidos` |

### B. Ações com confirmação (mesmo servidor, permissões específicas)

Padrão único reaproveitado da Fase 5: uma tool `preparar_*` devolve uma prévia, e uma tool `confirmar_*` grava a prévia confirmada.

1. **Compra avulsa** (Fase 5, já especificada).
2. **Despesa avulsa:** foto do boleto ou conta de luz/gás → título a pagar, com fornecedor, vencimento e plano de despesa.
3. **Disponibilidade no cardápio:** "acabou a massa de calabresa, pausa até amanhã" → marca o produto como indisponível. Reversível.
4. **Promoção relâmpago:** "liga a promoção X hoje das 18h às 20h".
5. **Aprovar sugestões do agente pelo chat:** listar as sugestões de compra e aprová-las sem abrir o painel.
6. **Contagem de estoque (balanço):** foto da lista de contagem → rascunho de balanço para revisar no painel.

### C. Rotinas autônomas e Cowork (agendadas, sem humano na hora)

1. **Agente de compras** (já existe).
2. **Resumo do dia** (todo dia ~1h): vendas, fechamento, exceções, cortesias, NFC-e com falha. Só leitura; gera um texto curto.
3. **Sugestão de compras semanal:** consumo pelas fichas técnicas × saldo atual × último custo por fornecedor → lista de compras por fornecedor. Só leitura.
4. **Monitor de anomalias:** cancelamentos, cortesias e descontos fora do padrão por operador; divergência de caixa.
5. **Relatórios no Cowork:** DRE ou CMV mensal numa planilha Google, e a análise semanal num documento. A sessão já tem os conectores do Google Drive, Docs e Sheets.

Para os resumos das rotinas, reaproveitar o padrão `registrar_resumo` + tela de rodadas (`AgenteExecucaoResource`), generalizado para "relatórios do Claude".

### D. Extras do protocolo (depois)

- **Prompts MCP** (o `laravel/mcp` suporta), prontos no menu do Claude: "Fechamento do dia", "Análise da semana", "Lançar compra avulsa".
- **MCP Apps (UI):** o pacote tem `Server/Ui`, que permite um cartão interativo no chat, como um mini-dashboard do dia. Avaliar só depois que as tools de leitura estiverem estáveis.

## Decisões do usuário (2026-11-19)

- Todos os quatro grupos entram no roteiro, nesta ordem: perguntas → rotinas → ações confirmadas → prompts e cartões.
- O conector de gestão é para **dono e gerentes**: cada um loga com o próprio usuário, e as tools aparecem conforme as permissões do perfil no Shield.

## Roteiro recomendado

| Etapa | Entrega | Depende de |
|---|---|---|
| G0 | Fechar o checkpoint 5.0 e implementar a Fase 5 (compra avulsa) | respostas Q1–Q6 da 5.0 |
| G1 | Servidor `/mcp/gestao` com as tools da seção A, começando por vendas, caixa, financeiro e fiado | G0 (reaproveita auth e middleware de humano) |
| G2 | Rotinas da seção C (resumo do dia e sugestão de compras) + "relatórios do Claude" | G1 |
| G3 | Ações confirmadas da seção B (despesa avulsa, cardápio, promoção, aprovar sugestões) | G1 + padrão rascunho da Fase 5 |
| G4 | Prompts MCP e, se fizer sentido, MCP Apps | G1 |

Cada etapa vira uma fase com checkpoint, no padrão dos planos de `docs/planos/`. Este documento é o roteiro.

## Arquivos e padrões que serão reaproveitados

- Servidor e tools: `app/Mcp/Servers/ComprasServer.php`, `app/Mcp/Tools/Compras/FerramentaCompras.php` (base com log e cache da rodada), `routes/ai.php`.
- Autenticação: Passport + `Mcp::oauthRoutes()`. O middleware novo segue o modelo de `app/Http/Middleware/GarantirAgenteCompras.php`, invertido: recusa o papel Agente e exige a permissão.
- Permissões: migration de seed no padrão de `2026_11_13_100005_seed_automacao_compras_permissions` + `PermissionSeeder`.
- Testes: padrão de `tests/Feature/ComprasAgenteMcpTest.php` (`ComprasServer::actingAs(...)->tool(...)`).

## Verificação (por etapa)

- **Testes PHPUnit por tool:**
  - números iguais aos do service de relatório correspondente;
  - usuário sem permissão não vê a tool (`shouldRegister`) e recebe 403;
  - papel Agente recusado no servidor humano.
- `php artisan route:list` com a rota nova; `curl` com `Accept: application/json, text/event-stream` deve dar 401 com `WWW-Authenticate`.
- Em produção: cadastrar o conector "PizzariaAPP Gestão" no Claude logado com o seu usuário e fazer 3 a 5 perguntas reais, conferindo com as telas.

---

## G1 — conector "PizzariaAPP Gestão": implementado (2026-11-21), primeiro lote

**Servidor:** `GestaoServer` em `/mcp/gestao` (`auth:api` + `GarantirUsuarioGestao` + `throttle:60,1`).
- O middleware recusa o papel Agente e exige `usar:claude_gestao` (Admin e Gerente; a migration `2026_11_21_100001` já dá ao Gerente).
- Depois de autenticar pelo Passport, o usuário passa a valer no guard `web` da requisição. Assim as permissões e as policies são avaliadas exatamente como no painel.

**Tools (todas só leitura).** Cada uma aparece só para quem tem a permissão da tela de onde o número vem:

| Tool | O que responde | Mesma conta de | Permissão |
|---|---|---|---|
| `resumo_vendas(inicio, fim)` | faturamento, vendas, ticket, CMV, margem, descontos e cancelamentos + comparativo com o período anterior | Painel Financeiro (`FinanceiroStatsOverview`) | `view:financeiro_dashboard` |
| `ranking_produtos(inicio, fim, limite, ordenar_por)` | produtos mais vendidos (sabor por fração), com receita, custo e margem | tabela "Produtos mais vendidos" | `view:financeiro_dashboard` |
| `situacao_caixa_agora()` | sessões abertas, operador, vendas da sessão e esperado por forma (dinheiro na gaveta) | `FechamentoCaixaService::calcularEsperado` | `view:relatorio_fechamento_caixa` |
| `fechamento_caixa(inicio, fim)` | resumo, conferência por sessão (esperado × apurado) e recebimentos por forma | `RelatorioFechamentoCaixaService` | `view:relatorio_fechamento_caixa` |
| `contas_a_pagar_receber(dias, tipo)` | totais em aberto e vencidos, saldo projetado por faixa e títulos até N dias | `RelatorioContasPagarReceberService` | `view:relatorio_contas_pagar_receber` |
| `saldos_contas()` | saldo de cada conta financeira e o total | contas financeiras | `view:relatorio_contas_pagar_receber` |
| `debitos_clientes(somente_vencidos, busca, limite)` | fiado por cliente (total, vencido, títulos, vencimento mais antigo), com telefone mascarado | `DebitosClienteService` | ver clientes (`viewAny` Cliente) |

**Refatoração:** o cálculo dos indicadores de vendas saiu de `FinanceiroStatsOverview` para `App\Support\IndicadoresVendas`, sem mudar a conta. Widget e tool usam a mesma classe, e um teste confere o widget.

**Log:** `storage/logs/claude-gestao-*.log` registra quem perguntou o quê.

**Testes:** `tests/Feature/ClaudeGestaoMcpTest.php`, com acesso, filtro por perfil e números iguais aos das telas.

**Hoje, com as permissões de produção:**
- o **Gerente** vê vendas, caixa e fiado;
- **contas a pagar/receber** e **saldos** ficam só com o Admin, como no painel (o Gerente não tem `view:relatorio_contas_pagar_receber`). Para liberar, basta dar a permissão no Shield.

**Ativação:**
1. No servidor: `git pull`, `php artisan migrate --force`, `config:cache` e reset do OPcache.
2. No Claude, adicione o conector "PizzariaAPP Gestão" com a URL `https://emporiodapizzago.com.br/mcp/gestao`. Cada pessoa loga com o próprio usuário.
3. Perguntas para testar:
   - "Como foram as vendas de ontem comparadas a anteontem?"
   - "Quais os 5 produtos que mais venderam este mês?"
   - "Tem caixa aberto agora? Quanto deveria ter na gaveta?"
   - "O fechamento de sábado bateu?"
   - "O que vence esta semana e quanto tenho nas contas?"
   - "Quem me deve fiado há mais tempo?"

## G1 — segundo lote: implementado (2026-11-21)

| Tool | O que responde | Mesma conta de | Permissão |
|---|---|---|---|
| `saldo_estoque(busca, tipo, somente_zerados, limite)` | saldo, custo médio e valor em estoque, do menor saldo para o maior | cadastro de produtos | `view_any:produto` |
| `custo_insumo_historico(produto_id/busca, inicio, fim)` | custo médio no início e no fim do período + compras do período | página Custo médio histórico (`mov_custo_medio_apos`) | `view:custo_medio_historico` |
| `margem_produto(produto_id/busca)` | custo pela ficha técnica (custo médio atual), preço, margem e CMV % | `Produto::custoUnitario()` | `view_any:ficha_tecnica` |
| `excecoes_pedidos(inicio, fim)` | cancelamentos por motivo e por usuário, itens removidos, descontos, cortesias, excluídos e não pagos | `RelatorioExcecoesPedidosService` | `view:relatorio_excecoes_pedidos` |
| `cortesias_periodo(inicio, fim, busca)` | cortesias com produto, valor de tabela, custo, motivo e autorizador | `RelatorioCortesiasService` | `conceder_cortesia:pedido` |
| `quem_esta_no_turno()` | ponto aberto agora + escalados que ainda não bateram a entrada | widget "Trabalhando agora" + escala | `view_espelho:ponto` |
| `checklists_hoje()` | checklists do dia (status, progresso, quem iniciou) + não conformidades pendentes | widget "Checklists de hoje" | `view:checklists_dashboard` |
| `desempenho_promocoes(inicio, fim)` | Relâmpago e Produto Adicional (itens e valor) e Combo (aplicações, valor e desconto) em pedidos não cancelados | itens dos pedidos e `pedido_combo_aplicacoes` | `view:financeiro_dashboard` |
| `ranking_influenciadores(inicio, fim)` | cliques, pedidos, vendas, comissões e saldo de cada influenciador | `RelatorioInfluenciadoresService` | `view:relatorio_influenciadores` |
| `notas_fiscais_situacao(inicio, fim)` | NFC-e por situação + vendas com falha ou pendentes de envio | `Venda::scopeSituacaoNfe` | `view_any:nota_fiscal` |
| `transacoes_stone_pendentes(dias)` | cobranças Stone com falha, indeterminadas ou aguardando há mais de 30 min | pedidos Stone | `view_any:stone_pedido` |

**Base das tools:** `FerramentaGestao::produto()` aceita `produto_id` ou o nome. Quando o nome é ambíguo, devolve os candidatos para o Claude perguntar qual é.

**Com as permissões de produção:**
- o **Gerente** vê estoque, margem, exceções, cortesias, equipe, checklists, NFC-e, promoções e influenciadores;
- **custo histórico**, **Stone**, **contas** e **saldos** ficam só com o Admin, como no painel.

**Testes:** `ClaudeGestaoMcpTest`, com 10 testes no total.

**Perguntas para testar:**
- "Quais insumos estão zerados?"
- "Quanto subiu a muçarela nos últimos 3 meses?"
- "Quanto me custa uma pizza calabresa grande e qual a margem?"
- "Quem cancelou mais pedidos esta semana?"
- "Quem está trabalhando agora e quem faltou?"
- "Os checklists de abertura foram feitos?"
- "Quanto vendeu o combo de 2 pizzas este mês?"
- "Tem NFC-e com erro hoje?"
- "Alguma cobrança da Stone travada?"

A G1 está completa. A próxima etapa é a **G2**: rotinas automáticas (resumo do dia, sugestão de compras, anomalias e relatórios no Cowork).

---

## G2 — rotinas automáticas: implementado (2026-11-22)

**No sistema:**
- **`App\Services\Gestao\RotinasGestaoService`:** monta os pacotes de dados de cada rotina. Cada seção reaproveita o serviço da tela correspondente: `IndicadoresVendas`, `RelatorioFechamentoCaixaService`, `RelatorioExcecoesPedidosService`, `Venda::scopeSituacaoNfe`, `PagamentosAConfirmar::consulta` e o de-para/compras.
- **Tools novas no conector "PizzariaAPP Gestão":**
  - `resumo_do_dia(data)`: vendas × mesmo dia da semana passada + top 5, caixa, exceções, NFC-e, Stone, compras e contas. Cada seção só aparece com a permissão da tela.
  - `sugestao_compras(cobertura_dias, janela_dias)`: consumo médio diário (saídas de venda, produção, consumo interno, perda e cortesia) × saldo → quanto comprar para N dias. Agrupa pelo fornecedor da última compra confirmada e dá a quantidade na unidade de compra e o custo estimado. Permissão: `view_any:compra`.
  - `anomalias(data)`: cancelamentos, cortesias e descontos acima de média × 2 do mesmo dia da semana nas últimas 4 semanas (com valor mínimo), diferença de caixa acima de R$ 20, NFC-e com problema, Stone travada e estoque negativo.
  - `registrar_relatorio(tipo, titulo, conteudo, referencia)`: grava o texto final em **Indicadores › Relatórios do Claude** (tabela `claude_relatorios`). Não altera nenhum outro dado.
- **Configuração:** limites em `config/gestao.php` (variáveis `GESTAO_*` no `.env`).
- **Tela "Relatórios do Claude":** só leitura, com o Markdown renderizado. Vê quem tem `usar:claude_gestao`.
- **Testes:** `tests/Feature/ClaudeGestaoRotinasTest.php`.

**Deploy:** `git pull`, `php artisan migrate --force` (cria `claude_relatorios`), `config:cache` e reset do OPcache.

### Rotinas para criar no Claude

Em **claude.ai/code/routines**, crie uma rotina para cada item abaixo, com **só o conector "PizzariaAPP Gestão"**. Ele deve estar logado com o seu usuário, porque as seções seguem o seu perfil. Horários em Brasília; o cron é em UTC.

**1. Resumo do dia** — todo dia às 08:00 (`0 11 * * *`)
```
Use somente o conector "PizzariaAPP Gestão". Não altere nenhum arquivo do repositório.
1. Chame resumo_do_dia (sem data: é ontem) e anomalias (sem data).
2. Escreva um resumo em português, curto, em Markdown:
   - Vendas: faturamento, nº de vendas e ticket, com a variação contra o mesmo dia da semana passada; os 3 produtos que mais venderam.
   - Caixa: recebido, fiado e se algum fechamento teve diferença.
   - Pontos de atenção: cada alerta de anomalias numa linha, do mais grave para o menos grave; NFC-e com falha; pagamentos de compra a confirmar; contas vencidas ou vencendo hoje/amanhã.
   - Se estiver tudo normal, diga isso numa frase.
3. Termine com registrar_relatorio (tipo resumo_dia, título "Resumo de <dia da semana>, <dd/mm>", referencia = a data do resumo).
```

**2. Sugestão de compras** — segundas e quintas às 09:00 (`0 12 * * 1,4`)
```
Use somente o conector "PizzariaAPP Gestão". Não altere nenhum arquivo do repositório.
1. Chame sugestao_compras (padrão: 7 dias de cobertura).
2. Monte a lista em Markdown, um bloco por fornecedor (maior valor primeiro): item, quantidade na unidade de compra (ex.: 2 SC), dias de estoque que restam e custo estimado; total por fornecedor e total geral.
3. Destaque no topo os itens com menos de 2 dias de estoque.
4. Termine com registrar_relatorio (tipo sugestao_compras, título "Sugestão de compras — <dd/mm>").
Não faça pedido nenhum: é só a sugestão.
```

**3. (Opcional) Anomalias à noite** — todo dia às 23:30 (`30 2 * * *`), olhando o dia que está acabando
```
Use somente o conector "PizzariaAPP Gestão". Chame anomalias com data = hoje (AAAA-MM-DD). Se total_alertas for 0, não registre nada. Senão, liste os alertas em Markdown (mais grave primeiro) e registre com registrar_relatorio (tipo anomalias, título "Alertas de <dd/mm>").
```

### Relatório mensal no Cowork (planilha Google)

No Cowork, com os conectores "PizzariaAPP Gestão" e Google Sheets/Drive ativos, peça no dia 1 de cada mês:
> "Monte o fechamento de <mês>: use resumo_vendas e fechamento_caixa do mês inteiro, ranking_produtos (top 20), desempenho_promocoes, excecoes_pedidos e contas_a_pagar_receber. Crie a planilha 'Fechamento <mês>/<ano>' no meu Drive com uma aba de resumo (faturamento, ticket, CMV, margem, cancelamentos, comparando com o mês anterior), uma aba de produtos e uma de promoções. No fim, registre um resumo com registrar_relatorio (tipo outro) com o link da planilha."

**Próxima etapa:** G3, ações confirmadas pelo chat (despesa avulsa, pausar produto, promoção relâmpago, aprovar sugestões e contagem de estoque).
