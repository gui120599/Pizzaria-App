# Compras automatizadas — Fase 5 (compras avulsas sem NF-e): verificação 5.0

> Verificação somente leitura feita em 2026-11-15 sobre `prod-new` (aba7f933), conforme a Parte A da spec (`docs/planos/compras-automatizadas.md`, Revisão 3). Nenhum código foi alterado. Termina no **CHECKPOINT 5.0** (perguntas na seção 5).

## 1. O que das Fases 1–4 já existe (nomes reais)

A Fase 0 (`compras-automatizadas-fase0.md`, seções 2 e 6–11) já mapeou spec → código. Resumo do que a Fase 5 reaproveita:

| Spec | Código real |
|---|---|
| `fornecedores` | `prestadores` (model `Prestador`, `cpf_cnpj`, `nome`, `razao_social`, `telefone`, `celular`) |
| parâmetros de compra | `fornecedor_condicoes_compra` (`FornecedorCondicaoCompra`, `fcc_*`: forma, conta, regra de baixa, plano, observações) |
| `produto_fornecedor_vinculos` | `fornecedor_produtos` (`FornecedorProduto`, `fp_*`), unique `fornecedor_produto_codigo_unique` (`fp_prestador_id`, `fp_codigo_fornecedor`) — vale também para linhas excluídas (soft delete) |
| `ProdutoMatcher` | `App\Services\Compras\ProdutoMatcher::identificar(prestadorId, codigo, ean)`: de-para por código → de-para por EAN → EAN do cadastro |
| título a pagar | `Lancamento` (tipo pagar); criação por `CompraService::criarTitulos()` (aceita `ja_pago`, `conta_financeira_id`, `sessao_caixa_id`, `forma_pagamento`) |
| baixa | `Lancamento::registrarPagamento(..., sessaoCaixaId, contaFinanceiraId, ignorarSaldo)` → `BaixaFinanceira` → `MovimentacaoService` |
| `compras.origem` | `compras.compra_origem` (string): hoje `manual` (74) e `xml` (263) |
| `compras.registrado_por` | `compras.compra_user_id` (já existe) |
| movimentação `registrado_por` | `movimentacoes_financeiras.registrado_por` (já existe) |
| `CustoAquisicaoCalculator`, pendências, `ComprasHojeWidget`, `PendenciaService` | existem (Fases 1–2) |

## 2. Respostas aos pontos da 5.0

**Fornecedor sem CNPJ:** `prestadores.cpf_cnpj` já é nullable e não tem unique. Falta só a coluna `avulso` (bool default false). `tipo` é NOT NULL (`pf`/`pj`): fornecedor avulso nasce como `pf`, sem documento.

**De-para e `ProdutoMatcher`:**
- `fp_codigo_fornecedor` guarda o `cProd` da NF-e.
- Para a descrição normalizada, o plano é:
  - criar `fp_tipo_chave` (`codigo`/`descricao`, default `codigo`);
  - trocar o unique para (`fp_prestador_id`, `fp_tipo_chave`, `fp_codigo_fornecedor`). O nome do índice continua curto, dentro do limite de 64 caracteres do MySQL;
  - fazer `identificar()` filtrar `fp_tipo_chave = codigo`, para que uma descrição nunca case com um `cProd`;
  - criar o método novo `identificarPorDescricao()`.
- Coluna `fp_codigo_fornecedor`: `varchar(255)` nullable, cabe a descrição normalizada (truncada em 255).

**Autenticação do MCP e filtro de tools:**
- `/mcp/compras` usa `auth:api` (Passport) + `GarantirAgenteCompras` (exige o papel `Agente`) + `throttle`.
- O `laravel/mcp` v1.2.0 instalado suporta `shouldRegister()` por tool (`Primitive::eligibleForRegistration()`, usado em `ServerContext` e no `ToolSearch`). O método recebe injeção do container, então dá para checar `request()->user()`.
- O novo `/mcp/compras-avulsa` pode:
  - filtrar as tools por permissão;
  - recusar o papel `Agente` num middleware próprio (o inverso do atual).
- Os dois servidores usam o mesmo OAuth: cada conector do Claude registra o próprio cliente (DCR) e guarda o próprio token.
- O login do humano é o `/login` padrão (e-mail e senha do usuário do painel).

**Baixa e `CaixaPdvBridge`:** o dinheiro do Caixa PDV já sai do jeito que a spec pede.
- `CaixaPdvBridge::sessaoParaMovimentar()` exige sessão aberta. Com uma sessão aberta só, ela é usada; com várias, pede para escolher; com nenhuma, dá erro.
- `registrarRetirada()` grava a retirada ligada à `MovimentacaoFinanceira`, e o fechamento bate.
- A confirmação de compra usa esse caminho via `criarTitulos(['ja_pago' => true, 'sessao_caixa_id' => …])`.
- Só pode haver uma conta `caixa_pdv`.
- Contas locais: Banco do Brasil (`banco`, saldo negativo permitido), Stone (`conta_digital`), "Caixinha Empório da Pizza" (`caixa_interno`, que parece ser o "Cofre" da spec) e "Caixa Pizzaria" (`caixa_pdv`).

**Gates × autorização atual (conflito com a Revisão 3):**
- O projeto usa Spatie Permission + Filament Shield. O Shield registra um `Gate::before` global: o `super_admin` (Admin) passa em tudo.
- As Fases 1–4 criaram permissões no padrão `verbo:recurso` e as deram ao Gerente pelo `PermissionSeeder`:
  - `configurar:automacao_compra`;
  - `resolver:pendencia_compra`;
  - `confirmar:pagamento_compra`;
  - `revisar:produto_automacao`;
  - `view:extrato_bancario`.
- Ou seja, as "permissões configuráveis por perfil" que a spec deixa para o futuro já existem: o Shield edita papéis pelo painel.
- O usuário `Agente Compras` tem só o papel `Agente`, sem nenhuma dessas permissões.

**Upload do comprovante:** o PHP local tem `upload_max_filesize = 2M`. O da produção (web) precisa ser conferido. Para aceitar 10 MB, `upload_max_filesize` e `post_max_size` precisam ser de pelo menos 12M. Alternativa: reduzir a foto no navegador antes de enviar.

## 3. Desvios da spec que proponho

1. **Autorização:**
   - Permissões Spatie novas no padrão do projeto: `lancar:compra_avulsa` para Admin e Gerente, seed por migration como nas Fases 1–4.
   - Reaproveitar as já existentes, em vez de criar Gates `compras.*` paralelos.
   - Se quiser os nomes da spec, defino `Gate::define('compras.avulsa', …)` como apelido que delega à permissão e recusa o papel `Agente`.
   - Em qualquer caso, um teste garante que o `Agente Compras` nunca passa em `lancar:compra_avulsa` nem em `confirmar:pagamento_compra`. Ver Q1.
2. **Entrada no estoque:**
   - A spec pede entrada parcial (itens resolvidos agora, o resto depois).
   - Hoje a confirmação da compra é tudo ou nada (`CompraService::confirmar`).
   - Proposta, igual ao fluxo da NF-e: com todos os itens reconhecidos, a compra é confirmada na hora. Com algum item não identificado, o título e a baixa são gravados, a compra fica em rascunho `com_pendencias` e entra no estoque inteira quando a última pendência for resolvida (via `ConferenciaCompraService`). Ver Q2.
3. **Origem:** `compra_origem` ganha o valor `avulsa`. Os existentes ficam `manual` e `xml`, sem renomear para `dfe`.
4. **Testes:** PHPUnit, como no resto do projeto (não há Pest).

## 4. Plano da 5.1 ajustado

1. **Migrations:**
   - `compra_rascunhos` (uuid, user, fornecedor, `fornecedor_novo` json, `entrada` json, `previa` json, status, `expira_em`, `confirmado_em`, compra);
   - `compra_anexos`;
   - `prestadores.avulso`;
   - `fornecedor_produtos.fp_tipo_chave` + troca do unique;
   - `compras.compra_origem_informacao` (nullable);
   - permissão `lancar:compra_avulsa` (Admin e Gerente).
2. **Enums:** `SituacaoPagamentoAvulsa`, `StatusRascunhoCompra`, `OrigemInformacaoAvulsa`, `TipoChaveVinculo`.
3. **Serviços:**
   - `NormalizadorDescricao`;
   - `ProdutoMatcher::identificarPorDescricao()` e filtro `codigo` no `identificar()`;
   - `CompraAvulsaService`: `preparar`, `confirmar` e `descartar`, com as regras da B §14.3. A confirmação roda numa transação com `lockForUpdate` no rascunho e reaproveita `criarTitulos` e o `CaixaPdvBridge`.
4. **MCP:**
   - `CompraAvulsaServer` em `/mcp/compras-avulsa` (`auth:api`, middleware que recusa o papel `Agente`, permissão `lancar:compra_avulsa`);
   - tools em `app/Mcp/Tools/Compras/Avulsa/`: `buscar_fornecedores`, `listar_contas_pagamento`, `preparar_compra_avulsa`, `confirmar_compra_avulsa`, `descartar_rascunho`, + `buscar_produtos`;
   - `instructions` com o texto da B §9.2;
   - o `ComprasServer` fica intacto.
5. **Comprovante:** rota assinada (24 h) com página Blade mobile-first para enviar a foto (só imagem); arquivo no disco `local`, em `compras_anexos/`.
6. **Filament:**
   - filtro e badge "Avulsa" na lista de compras e coluna "Registrado por";
   - abas "Comprovante" e "Entrada original" na compra avulsa;
   - contador de compras avulsas do dia e do total pago em dinheiro no `ComprasHojeWidget`;
   - título `a_pagar` de compra avulsa em "Pagamentos a confirmar" (Q4).
7. **Limpeza:** comando agendado que marca rascunhos expirados e apaga os com mais de 30 dias.
8. **Testes da B §14.6.**

## 5. Perguntas — CHECKPOINT 5.0

- **Q1 — Autorização:**
  - **(a) recomendado:** permissão Spatie `lancar:compra_avulsa` (Admin e Gerente), editável pelo Shield como as outras;
  - **(b):** Gates `compras.*` com os nomes da spec, delegando às permissões.
- **Q2 — Estoque com item não identificado:**
  - **(a) recomendado:** a compra entra inteira quando a última pendência for resolvida. O financeiro é gravado na hora.
  - **(b) spec:** entrada parcial imediata dos itens reconhecidos, que exige mudar a confirmação de compra.
- **Q3 — "Cofre":** é a conta "Caixinha Empório da Pizza" (`caixa_interno`)? E `listar_contas_pagamento` deve mostrar todas as contas, incluindo Banco do Brasil e Stone para PIX, ou só as de dinheiro?
- **Q4 — Compra "a pagar" (fiado):**
  - **(a) recomendado:** aparece em "Pagamentos a confirmar" a partir do vencimento;
  - **(b):** fica só no contas a pagar normal.
- **Q5 — Foto de até 10 MB:**
  - **(a) recomendado:** reduzir a foto no navegador antes de enviar (cerca de 1600 px, JPEG), o que dispensa mexer no PHP da hospedagem;
  - **(b):** aumentar `upload_max_filesize`/`post_max_size` no MultiPHP INI Editor.
- **Q6 — Conector existente:** renomear no Claude para "PizzariaAPP Compras (Agente)" e criar o novo "PizzariaAPP Compras Avulsas", logado com o seu usuário? Se a tarefa agendada permitir escolher conectores, deixe nela só o do agente.

## 6. Decisões do checkpoint 5.0 (2026-11-20)

O usuário aceitou todas as recomendações:
- **Q1 (a):** permissão Spatie `lancar:compra_avulsa` (Admin e Gerente), com o Gate `compras.avulsa` como apelido.
- **Q2 (a):** com item não identificado, a compra entra inteira quando a última pendência for resolvida.
- **Q3:** "Cofre" = "Caixinha Empório da Pizza"; listar todas as contas.
- **Q4 (a):** fiado aparece em "Pagamentos a confirmar" a partir do vencimento.
- **Q5 (a):** foto reduzida no navegador.
- **Q6:** renomear o conector atual para "(Agente)" e criar "PizzariaAPP Compras Avulsas".

## 7. Fase 5.1 — implementada (2026-11-20)

- **Migrations `2026_11_20_1000xx`:**
  - `compra_rascunhos`, `compra_anexos`;
  - `prestadores.avulso`;
  - `fornecedor_produtos.fp_tipo_chave` + unique `fornecedor_produto_chave_unique` (`fp_prestador_id`, `fp_tipo_chave`, `fp_codigo_fornecedor`), criado antes de remover o antigo;
  - `compras.compra_origem_informacao`;
  - permissão `lancar:compra_avulsa`, já dada ao Gerente na migration.
  - `compra_origem` ganhou o valor `avulsa` (`Compra::ORIGEM_AVULSA`).
- **De-para:** `ChaveVinculo` decide a chave (cProd da NF-e ou, na compra avulsa, a descrição normalizada por `NormalizadorDescricao`). A regra é usada em `CompraService::atualizarDePara`, `PendenciaService::gravarVinculo` e na importação. `ProdutoMatcher::identificar` passou a filtrar `codigo`, e o novo `identificarPorDescricao` cobre a compra avulsa.
- **`CompraAvulsaService`:** `preparar`, `confirmar` e `descartar`, com as regras da B §14.3.
  - **Alertas que bloqueiam a confirmação:** `total_divergente`, `caixa_pdv_fechado`, `saldo_insuficiente`, `data_futura`, `data_retroativa`.
  - **Alertas só informativos:** `itens_nao_identificados`, `preco_fora_padrao`, `possivel_duplicidade`, `fornecedor_semelhante`.
  - **Confirmação:** numa transação com `lockForUpdate`, cria fornecedor avulso → compra (operação compra, confiança alta) → itens e custos → título (`criarTitulos`, com baixa e retirada na sessão se for Caixa PDV) → de-para por descrição → pendências → confirmação no estoque, se não houver item pendente.
  - **Gaveta do Caixa PDV:** segue a mesma regra de `MovimentacaoService::garantirSaldo`, que nunca deixa a gaveta negativa.
- **Entrada da compra avulsa com pendência:** `ConferenciaCompraService::dataDeEntrada` usa a data da compra, então resolver a última pendência confirma a compra.
- **Pagamentos a confirmar:** `PagamentosAConfirmar::consulta` passou a incluir título de compra avulsa vencido ou vencendo hoje.
- **MCP:** `CompraAvulsaServer` em `/mcp/compras-avulsa` (`auth:api` + `GarantirUsuarioCompraAvulsa` + throttle).
  - **Tools:** `buscar_fornecedores`, `buscar_produtos`, `listar_contas_pagamento`, `preparar_compra_avulsa`, `confirmar_compra_avulsa`, `descartar_rascunho`.
  - **Proteção:** `shouldRegister` e checagem por chamada no Gate.
  - **Log:** `storage/logs/compras-avulsa-*.log`.
  - O `ComprasServer` do agente ficou intacto.
- **Comprovante:** link assinado de 24 h (`compras.anexo.formulario`) para uma página Blade mobile-first. A foto é reduzida no navegador para 1600 px JPEG e gravada em `storage/app/compras_anexos/{compra}/`. No painel, a imagem é servida por `compras.anexo.ver` (login + permissão de ver a compra).
- **Filament:**
  - origem "Avulsa (Claude)" com badge e clipe quando há comprovante, mais filtro por origem;
  - coluna "Registrado por";
  - seção "4. Compra avulsa" com as abas Comprovante e Entrada original;
  - "Compras avulsas hoje / pago em dinheiro" no `ComprasHojeWidget`;
  - tipo da chave na tela de de-para.
- **Limpeza:** `compras:limpar-rascunhos-avulsa` às 03:20 expira os rascunhos abertos vencidos e apaga os expirados e descartados com mais de 30 dias.
- **Testes:** `tests/Feature/CompraAvulsaTest.php`, com 13 testes cobrindo a B §14.6, as telas e o acesso por MCP.

**Deploy:**
- `git pull` + `php artisan migrate` + `config:cache` + reset do OPcache.
- Se o `PermissionSeeder` for rodado de novo, ele também dá a permissão ao Gerente.
- O `.htaccess` de `.well-known` já cobre a URL nova (`/.well-known/oauth-protected-resource/mcp/compras-avulsa`).

## 8. CHECKPOINT 5 — roteiro de teste pelo Claude no celular

1. **No Claude** (Configurações › Conectores):
   - renomeie o conector atual para "PizzariaAPP Compras (Agente)";
   - adicione um novo, "PizzariaAPP Compras Avulsas", com a URL `https://emporiodapizzago.com.br/mcp/compras-avulsa`;
   - ao conectar, entre com **o seu usuário** do PizzariaAPP, não com o do agente.
2. **Na tarefa agendada do agente**, se der para escolher conectores, deixe só o "(Agente)".
3. **No celular**, abra uma conversa nova com o conector de compras avulsas ativo e mande a foto de um cupom de mercado com o texto: "lança essa compra, paguei do cofre em dinheiro".
4. **O Claude deve:**
   - buscar o fornecedor (ou propor cadastrá-lo);
   - buscar os produtos e as contas;
   - mostrar o resumo com os alertas;
   - **esperar o seu "sim"**.
5. **Depois de confirmar:**
   - a resposta traz o número da compra, o título pago e o link "anexar comprovante". Abra o link, tire a foto e envie;
   - no painel, a compra aparece com a origem "Avulsa (Claude)" e o clipe;
   - a seção 4 mostra a foto e a entrada original;
   - o título está pago na conta Cofre.
6. **Casos para conferir:**
   - **Caixa PDV com o caixa fechado:** o Claude deve oferecer outra conta.
   - **Item que não existe no estoque:** a compra é gravada e o item vira pendência na Caixa de entrada.
   - **Mesma compra lançada duas vezes:** aparece o aviso de possível duplicidade.
