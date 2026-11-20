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
