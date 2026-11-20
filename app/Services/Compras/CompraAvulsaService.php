<?php

namespace App\Services\Compras;

use App\Enums\Compras\OrigemInformacaoAvulsa;
use App\Enums\Compras\OrigemVinculo;
use App\Enums\Compras\SituacaoPagamentoAvulsa;
use App\Enums\Compras\StatusRascunhoCompra;
use App\Enums\Compras\TipoPendenciaCompra;
use App\Enums\CompraStatusEnum;
use App\Enums\ConfiancaOperacaoEnum;
use App\Enums\FormaPagamento;
use App\Enums\OperacaoEntradaEnum;
use App\Enums\PrestadorCategoriaEnum;
use App\Enums\PrestadorTipoEnum;
use App\Exceptions\FinanceiroException;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\CompraRascunho;
use App\Models\ContaFinanceira;
use App\Models\FornecedorProduto;
use App\Models\PlanoDespesa;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\User;
use App\Services\Compras\Dto\ResultadoMatch;
use App\Services\CompraService;
use App\Services\Financeiro\CaixaPdvBridge;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Compra avulsa sem NF-e lançada pelo Claude (Fase 5, spec B §14): o Claude
 * lê a foto/mensagem e chama preparar(); o sistema calcula a prévia (produtos
 * reconhecidos, custos, alertas) num rascunho; só quando o MESMO usuário
 * confirma é que nascem compra, itens, título, baixa e estoque — numa única
 * transação. Nada é gravado em compras/financeiro/estoque antes disso.
 */
class CompraAvulsaService
{
    /** Alertas que impedem a confirmação até serem corrigidos. */
    private const ALERTAS_BLOQUEANTES = ['total_divergente', 'caixa_pdv_fechado', 'saldo_insuficiente', 'data_futura', 'data_retroativa'];

    public function __construct(
        private ProdutoMatcher $matcher,
        private CompraService $compras,
        private PendenciaService $pendencias,
        private CaixaPdvBridge $caixa,
        private CustoAquisicaoCalculator $custos,
    ) {}

    /**
     * Cria o rascunho, ou corrige um rascunho aberto do mesmo usuário
     * (`rascunho_uuid`), e devolve a prévia recalculada.
     *
     * @param  array<string, mixed>  $entrada
     *
     * @throws ValidationException
     */
    public function preparar(array $entrada, User $usuario): CompraRascunho
    {
        $dados = $this->validar($entrada);
        $previa = $this->calcularPrevia($dados);

        $rascunho = filled($dados['rascunho_uuid'] ?? null)
            ? $this->rascunhoAbertoDoUsuario((string) $dados['rascunho_uuid'], $usuario)
            : new CompraRascunho(['uuid' => (string) Str::uuid(), 'user_id' => $usuario->id]);

        $rascunho->fill([
            'prestador_id' => $dados['fornecedor_id'] ?? null,
            'fornecedor_novo' => $dados['fornecedor_novo'] ?? null,
            'entrada' => $dados,
            'previa' => $previa,
            'status' => StatusRascunhoCompra::Aberto,
            'expira_em' => now()->addMinutes((int) config('compras.avulsa.expiracao_rascunho_min', 30)),
        ])->save();

        return $rascunho;
    }

    /**
     * Grava tudo numa transação (B §14.4). Idempotente: confirmar de novo o
     * mesmo rascunho devolve a mesma compra.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function confirmar(string $uuid, User $usuario): Compra
    {
        return DB::transaction(function () use ($uuid, $usuario): Compra {
            $rascunho = CompraRascunho::query()->where('uuid', $uuid)->lockForUpdate()->first();

            if ($rascunho === null) {
                throw ValidationException::withMessages(['rascunho_uuid' => 'Rascunho não encontrado. Prepare a compra de novo.']);
            }

            if ((int) $rascunho->user_id !== (int) $usuario->id) {
                throw new AuthorizationException('Este rascunho foi preparado por outro usuário.');
            }

            if ($rascunho->status === StatusRascunhoCompra::Confirmado && $rascunho->compra !== null) {
                return $rascunho->compra;
            }

            if ($rascunho->status !== StatusRascunhoCompra::Aberto || $rascunho->expirou()) {
                throw ValidationException::withMessages(['rascunho_uuid' => 'Este rascunho expirou ou foi descartado. Prepare a compra de novo para recalcular a prévia.']);
            }

            // Revalida com saldos, sessão de caixa e vínculos de agora.
            $dados = $rascunho->entrada;
            $previa = $this->calcularPrevia($dados);

            if (! $previa['pode_confirmar']) {
                $motivos = collect($previa['alertas'])->where('bloqueia', true)->pluck('mensagem')->implode(' ');
                throw ValidationException::withMessages(['rascunho_uuid' => "Não dá para confirmar: {$motivos}"]);
            }

            if (filled($previa['pagamento']['conta_financeira_id'])) {
                ContaFinanceira::query()->whereKey($previa['pagamento']['conta_financeira_id'])->lockForUpdate()->first();
            }

            $fornecedor = $this->fornecedorDaCompra($dados);
            $compra = $this->criarCompra($dados, $previa, $fornecedor, $usuario);
            $this->criarTitulo($compra, $previa, $fornecedor);
            $this->aprenderVinculos($compra, $usuario);
            $this->abrirPendencias($compra);

            if ($compra->itens->every(fn (CompraItem $item): bool => $item->ci_produto_id !== null)) {
                $compra = $this->compras->confirmar($compra, Carbon::parse($dados['data_compra']));
            }

            $rascunho->forceFill([
                'status' => StatusRascunhoCompra::Confirmado,
                'confirmado_em' => now(),
                'compra_id' => $compra->id,
                'prestador_id' => $fornecedor->id,
                'previa' => $previa,
            ])->save();

            return $compra->refresh();
        });
    }

    /** @throws ValidationException|AuthorizationException */
    public function descartar(string $uuid, User $usuario): CompraRascunho
    {
        $rascunho = $this->rascunhoAbertoDoUsuario($uuid, $usuario);
        $rascunho->forceFill(['status' => StatusRascunhoCompra::Descartado])->save();

        return $rascunho;
    }

    /** Link assinado (24 h) para enviar a foto do comprovante. */
    public function linkAnexo(Compra $compra): string
    {
        return URL::temporarySignedRoute(
            'compras.anexo.formulario',
            now()->addHours((int) config('compras.avulsa.anexo_link_horas', 24)),
            ['compra' => $compra->id],
        );
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validar(array $entrada): array
    {
        $dados = Validator::make($entrada, [
            'rascunho_uuid' => ['nullable', 'string', 'size:36'],
            'fornecedor_id' => ['nullable', 'integer', 'required_without:fornecedor_novo', 'prohibits:fornecedor_novo'],
            'fornecedor_novo' => ['nullable', 'array', 'required_without:fornecedor_id'],
            'fornecedor_novo.nome' => ['required_with:fornecedor_novo', 'string', 'max:120'],
            'fornecedor_novo.telefone' => ['nullable', 'string', 'max:30'],
            'fornecedor_novo.observacao' => ['nullable', 'string', 'max:500'],
            'data_compra' => ['required', 'date_format:Y-m-d'],
            'origem_informacao' => ['nullable', Rule::enum(OrigemInformacaoAvulsa::class)],
            'itens' => ['required', 'array', 'min:1', 'max:100'],
            'itens.*.descricao' => ['required', 'string', 'max:255'],
            'itens.*.quantidade' => ['required', 'numeric', 'gt:0'],
            'itens.*.unidade' => ['required', 'string', 'max:10'],
            'itens.*.valor_total' => ['required', 'numeric', 'min:0'],
            'itens.*.produto_id' => ['nullable', 'integer'],
            'itens.*.fator_conversao' => ['nullable', 'numeric', 'gt:0'],
            'valor_total_informado' => ['nullable', 'numeric', 'min:0'],
            'desconto' => ['nullable', 'numeric', 'min:0'],
            'situacao_pagamento' => ['required', Rule::enum(SituacaoPagamentoAvulsa::class)],
            'forma_pagamento' => ['nullable', Rule::enum(FormaPagamento::class)],
            'conta_financeira_id' => ['nullable', 'integer'],
            'vencimento' => ['nullable', 'date_format:Y-m-d', 'required_if:situacao_pagamento,a_pagar', 'after_or_equal:data_compra'],
            'plano_despesa_id' => ['nullable', 'integer'],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ], [
            'fornecedor_id.required_without' => 'Informe o fornecedor (fornecedor_id de buscar_fornecedores) ou os dados de um fornecedor novo.',
            'vencimento.required_if' => 'Compra a pagar precisa de vencimento.',
        ])->validate();

        $erros = [];

        if (filled($dados['fornecedor_id'] ?? null) && ! Prestador::query()->whereKey($dados['fornecedor_id'])->exists()) {
            $erros['fornecedor_id'] = 'Fornecedor não encontrado. Use buscar_fornecedores.';
        }

        foreach ($dados['itens'] as $indice => $item) {
            if (filled($item['produto_id'] ?? null) && ! Produto::query()->whereKey($item['produto_id'])->exists()) {
                $erros["itens.{$indice}.produto_id"] = "Produto {$item['produto_id']} não encontrado. Use buscar_produtos.";
            }
        }

        if (filled($dados['plano_despesa_id'] ?? null) && ! PlanoDespesa::query()->whereKey($dados['plano_despesa_id'])->exists()) {
            $erros['plano_despesa_id'] = 'Plano de despesa não encontrado.';
        }

        if (filled($dados['conta_financeira_id'] ?? null) && ! ContaFinanceira::query()->whereKey($dados['conta_financeira_id'])->exists()) {
            $erros['conta_financeira_id'] = 'Conta não encontrada. Use listar_contas_pagamento.';
        }

        $condicao = filled($dados['fornecedor_id'] ?? null)
            ? Prestador::query()->with('condicaoCompra')->find($dados['fornecedor_id'])?->condicaoCompra
            : null;

        // Padrões do fornecedor quando o Claude não informou.
        $dados['forma_pagamento'] ??= $condicao?->fcc_forma_pagamento instanceof FormaPagamento
            ? $condicao->fcc_forma_pagamento->value
            : ($condicao?->fcc_forma_pagamento ?: null);
        $dados['conta_financeira_id'] ??= $condicao?->fcc_conta_financeira_id;

        if ($dados['situacao_pagamento'] === SituacaoPagamentoAvulsa::Pago->value) {
            if (blank($dados['conta_financeira_id'])) {
                $erros['conta_financeira_id'] = 'Compra paga: informe de onde saiu o dinheiro (conta_financeira_id de listar_contas_pagamento).';
            }
            if (blank($dados['forma_pagamento'])) {
                $erros['forma_pagamento'] = 'Compra paga: informe a forma de pagamento (dinheiro, pix…).';
            }
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }

        return $dados;
    }

    /**
     * Prévia: o que a confirmação vai gravar, com os alertas.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function calcularPrevia(array $dados): array
    {
        $fornecedor = filled($dados['fornecedor_id'] ?? null)
            ? Prestador::query()->with('condicaoCompra')->find($dados['fornecedor_id'])
            : null;
        $condicao = $fornecedor?->condicaoCompra;
        $alertas = [];

        $itens = $this->itensDaPrevia($dados, $fornecedor, (float) ($condicao?->fcc_tolerancia_preco_pct ?? 15), $alertas);

        $somaItens = round(collect($itens)->sum('valor_total'), 2);
        $desconto = round((float) ($dados['desconto'] ?? 0), 2);
        $total = round($somaItens - $desconto, 2);
        $informado = isset($dados['valor_total_informado']) ? round((float) $dados['valor_total_informado'], 2) : null;
        $diferenca = $informado !== null ? round($informado - $total, 2) : null;

        if ($diferenca !== null && abs($diferenca) > (float) config('compras.avulsa.tolerancia_total', 0.05)) {
            $alertas[] = $this->alerta('total_divergente', 'A soma dos itens menos o desconto dá '.$this->moeda($total).', mas o total informado é '.$this->moeda($informado).'. Corrija os valores.');
        }

        $data = Carbon::parse($dados['data_compra'])->startOfDay();
        if ($data->isFuture()) {
            $alertas[] = $this->alerta('data_futura', 'A data da compra está no futuro.');
        } elseif ($data->lt(today()->subDays((int) config('compras.avulsa.dias_retroativos', 30)))) {
            $alertas[] = $this->alerta('data_retroativa', 'Compra com mais de '.config('compras.avulsa.dias_retroativos', 30).' dias: lance pelo painel.');
        }

        $pagamento = $this->pagamentoDaPrevia($dados, $data, $total, $alertas);

        if ($fornecedor === null) {
            $semelhantes = $this->fornecedoresSemelhantes((string) ($dados['fornecedor_novo']['nome'] ?? ''));
            if ($semelhantes !== []) {
                $alertas[] = $this->alerta('fornecedor_semelhante', 'Já existe fornecedor com nome parecido: '.collect($semelhantes)->map(fn (array $f): string => "{$f['nome']} (id {$f['id']})")->implode(', ').'. Use o existente, se for o mesmo.', false, ['fornecedores' => $semelhantes]);
            }
        } elseif ($this->possivelDuplicidade($fornecedor->id, $data, $total)) {
            $alertas[] = $this->alerta('possivel_duplicidade', 'Já existe compra avulsa deste fornecedor, nesta data e com o mesmo total lançada nas últimas '.config('compras.avulsa.janela_duplicidade_horas', 48).' h. Confira se não é a mesma.', false);
        }

        $planoId = $dados['plano_despesa_id'] ?? $condicao?->fcc_plano_despesa_id ?? config('compras.avulsa.plano_despesa_padrao');
        $previa = [
            'fornecedor' => $fornecedor !== null
                ? ['id' => $fornecedor->id, 'nome' => $fornecedor->nome_exibicao, 'novo' => false]
                : ['id' => null, 'nome' => $dados['fornecedor_novo']['nome'] ?? null, 'novo' => true],
            'data_compra' => $data->toDateString(),
            'itens' => $itens,
            'totais' => [
                'soma_itens' => $somaItens,
                'desconto' => $desconto,
                'total' => $total,
                'valor_total_informado' => $informado,
                'diferenca' => $diferenca,
            ],
            'pagamento' => $pagamento,
            'plano_despesa_id' => filled($planoId) ? (int) $planoId : null,
            'alertas' => $alertas,
            'pode_confirmar' => ! collect($alertas)->contains('bloqueia', true),
        ];
        $previa['resumo_texto'] = $this->resumo($previa);

        return $previa;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @param  list<array<string, mixed>>  $alertas
     * @return list<array<string, mixed>>
     */
    private function itensDaPrevia(array $dados, ?Prestador $fornecedor, float $toleranciaPct, array &$alertas): array
    {
        $somaItens = collect($dados['itens'])->sum(fn (array $item): float => (float) $item['valor_total']);
        $desconto = (float) ($dados['desconto'] ?? 0);
        $naoIdentificados = [];
        $foraDoPadrao = [];

        $itens = collect($dados['itens'])->map(function (array $item) use ($fornecedor, $somaItens, $desconto, $toleranciaPct, &$naoIdentificados, &$foraDoPadrao): array {
            $match = $fornecedor !== null ? $this->matcher->identificarPorDescricao($fornecedor->id, $item['descricao']) : null;

            if (filled($item['produto_id'] ?? null)) {
                $produtoId = (int) $item['produto_id'];
                $fator = (float) ($item['fator_conversao'] ?? ($match?->produtoId === $produtoId ? $match->fatorConversao : 1));
                $reconhecimento = 'produto_informado';
            } elseif ($match !== null) {
                $produtoId = $match->produtoId;
                $fator = (float) ($item['fator_conversao'] ?? $match->fatorConversao);
                $reconhecimento = ResultadoMatch::DESCRICAO;
            } else {
                $produtoId = null;
                $fator = (float) ($item['fator_conversao'] ?? 1);
                $reconhecimento = 'nao_identificado';
                $naoIdentificados[] = $item['descricao'];
            }

            $produto = $produtoId !== null ? Produto::query()->find($produtoId) : null;
            $quantidade = (float) $item['quantidade'];
            $valor = round((float) $item['valor_total'], 2);
            $rateioDesconto = $somaItens > 0 ? $desconto * ($valor / $somaItens) : 0.0;
            $quantidadeEstoque = round($quantidade * $fator, 4);
            $custoAquisicao = $quantidadeEstoque > 0 ? round(($valor - $rateioDesconto) / $quantidadeEstoque, 4) : 0.0;

            $ultimo = $match?->produtoId === $produtoId ? (float) ($match->vinculo?->fp_ultimo_custo_unitario ?? 0) : 0.0;
            $variacao = $ultimo > 0 && $custoAquisicao > 0 ? round(($custoAquisicao - $ultimo) / $ultimo * 100, 2) : null;
            if ($variacao !== null && abs($variacao) > $toleranciaPct) {
                $foraDoPadrao[] = "{$item['descricao']} ({$variacao}%)";
            }

            return [
                'descricao' => $item['descricao'],
                'quantidade' => $quantidade,
                'unidade' => mb_strtoupper($item['unidade']),
                'valor_total' => $valor,
                'produto_id' => $produto?->id,
                'produto' => $produto?->produto_descricao,
                'unidade_estoque' => $produto?->produto_unidade_estoque,
                'reconhecimento' => $reconhecimento,
                'fator_conversao' => $fator,
                'quantidade_estoque' => $quantidadeEstoque,
                'custo_unitario_aquisicao' => $custoAquisicao,
                'ultimo_custo_unitario' => $ultimo > 0 ? $ultimo : null,
                'variacao_pct' => $variacao,
            ];
        })->values()->all();

        if ($naoIdentificados !== []) {
            $alertas[] = $this->alerta('itens_nao_identificados', 'Sem produto do estoque: '.implode(', ', $naoIdentificados).'. A compra e o pagamento são gravados e o item vira pendência na Caixa de entrada.', false);
        }

        if ($foraDoPadrao !== []) {
            $alertas[] = $this->alerta('preco_fora_padrao', 'Custo fora do padrão em relação à última compra: '.implode(', ', $foraDoPadrao).'.', false);
        }

        return $itens;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @param  list<array<string, mixed>>  $alertas
     * @return array<string, mixed>
     */
    private function pagamentoDaPrevia(array $dados, Carbon $data, float $total, array &$alertas): array
    {
        $pago = $dados['situacao_pagamento'] === SituacaoPagamentoAvulsa::Pago->value;
        $conta = filled($dados['conta_financeira_id'] ?? null) ? ContaFinanceira::query()->find($dados['conta_financeira_id']) : null;
        $sessaoId = null;

        if ($pago && $conta !== null) {
            if ($conta->ehCaixaPdv()) {
                if (! $data->isToday()) {
                    $alertas[] = $this->alerta('caixa_pdv_fechado', 'Dinheiro do Caixa PDV só para compra de hoje, com o caixa aberto. Escolha outra conta (ex.: Cofre).');
                } else {
                    try {
                        $sessao = $this->caixa->sessaoParaMovimentar();
                        $sessaoId = $sessao->id;
                        // Mesma regra da baixa (MovimentacaoService::garantirSaldo): a gaveta
                        // nunca fica negativa, com ou sem "permite saldo negativo".
                        if ($this->caixa->dinheiroDisponivel($sessao) < $total - 0.001) {
                            $alertas[] = $this->alerta('saldo_insuficiente', 'A gaveta do caixa tem '.$this->moeda($this->caixa->dinheiroDisponivel($sessao)).', menos que o total da compra.');
                        }
                    } catch (FinanceiroException $e) {
                        $alertas[] = $this->alerta('caixa_pdv_fechado', $e->getMessage());
                    }
                }
            } elseif ((float) $conta->saldo_atual < $total && ! $conta->permite_saldo_negativo) {
                $alertas[] = $this->alerta('saldo_insuficiente', "A conta {$conta->nome} tem ".$this->moeda((float) $conta->saldo_atual).', menos que o total da compra.');
            }
        }

        return [
            'situacao' => $dados['situacao_pagamento'],
            'forma_pagamento' => $dados['forma_pagamento'] ?? null,
            'conta_financeira_id' => $conta?->id,
            'conta' => $conta?->nome,
            'conta_tipo' => $conta?->tipo?->value,
            'sessao_caixa_id' => $sessaoId,
            'vencimento' => $pago ? $data->toDateString() : ($dados['vencimento'] ?? null),
        ];
    }

    /** @return list<array{id: int, nome: string}> */
    private function fornecedoresSemelhantes(string $nome): array
    {
        $chave = NormalizadorDescricao::normalizar($nome);
        if ($chave === '') {
            return [];
        }

        return Prestador::query()
            ->where('categoria', PrestadorCategoriaEnum::FORNECEDOR)
            ->get(['id', 'nome', 'razao_social', 'nome_fantasia'])
            ->filter(fn (Prestador $prestador): bool => collect([$prestador->nome, $prestador->razao_social, $prestador->nome_fantasia])
                ->filter()
                ->contains(fn (string $outro): bool => NormalizadorDescricao::normalizar($outro) === $chave))
            ->map(fn (Prestador $prestador): array => ['id' => $prestador->id, 'nome' => $prestador->nome_exibicao])
            ->values()
            ->all();
    }

    private function possivelDuplicidade(int $fornecedorId, Carbon $data, float $total): bool
    {
        return Compra::query()
            ->where('compra_origem', Compra::ORIGEM_AVULSA)
            ->where('compra_prestador_id', $fornecedorId)
            ->whereDate('compra_data_emissao', $data)
            ->where('created_at', '>=', now()->subHours((int) config('compras.avulsa.janela_duplicidade_horas', 48)))
            ->get(['compra_valor_total'])
            ->contains(fn (Compra $compra): bool => abs((float) $compra->compra_valor_total - $total) < 0.01);
    }

    /** @param  array<string, mixed>  $dados */
    private function fornecedorDaCompra(array $dados): Prestador
    {
        if (filled($dados['fornecedor_id'] ?? null)) {
            return Prestador::query()->findOrFail($dados['fornecedor_id']);
        }

        $novo = $dados['fornecedor_novo'];

        return Prestador::query()->create([
            'tipo' => PrestadorTipoEnum::PF,
            'categoria' => PrestadorCategoriaEnum::FORNECEDOR,
            'avulso' => true,
            'nome' => $novo['nome'],
            'celular' => $novo['telefone'] ?? null,
            'observacoes' => $novo['observacao'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $dados
     * @param  array<string, mixed>  $previa
     */
    private function criarCompra(array $dados, array $previa, Prestador $fornecedor, User $usuario): Compra
    {
        $compra = Compra::query()->create([
            'compra_prestador_id' => $fornecedor->id,
            'compra_origem' => Compra::ORIGEM_AVULSA,
            'compra_origem_informacao' => $dados['origem_informacao'] ?? null,
            'compra_data_emissao' => $previa['data_compra'],
            'compra_data_entrada' => $previa['data_compra'],
            'compra_user_id' => $usuario->id,
            'compra_valor_desconto' => $previa['totais']['desconto'],
            'compra_observacao' => $dados['observacao'] ?? null,
        ]);

        // Sem NF-e para classificar: quem confirmou disse que é compra.
        $compra->forceFill([
            'compra_status' => CompraStatusEnum::RASCUNHO,
            'compra_operacao' => OperacaoEntradaEnum::COMPRA->value,
            'compra_operacao_confianca' => ConfiancaOperacaoEnum::ALTA->value,
        ])->save();

        foreach ($previa['itens'] as $item) {
            CompraItem::query()->create([
                'ci_compra_id' => $compra->id,
                'ci_produto_id' => $item['produto_id'],
                'ci_descricao_fornecedor' => $item['descricao'],
                'ci_quantidade_compra' => $item['quantidade'],
                'ci_unidade_compra' => $item['unidade'],
                'ci_fator_conversao' => $item['fator_conversao'],
                'ci_custo_unitario_compra' => $item['quantidade'] > 0 ? round($item['valor_total'] / $item['quantidade'], 8) : 0,
            ]);
        }

        $compra->load('itens');
        $this->compras->recalcularTotais($compra);

        $custos = $this->custos->calcular($compra, $compra->itens);
        foreach ($compra->itens as $item) {
            $item->forceFill([
                'ci_custo_aquisicao_total' => $custos[$item->id]['total'],
                'ci_custo_unitario_aquisicao' => $custos[$item->id]['unitario'],
            ])->save();
        }

        return $compra->refresh()->load('itens');
    }

    /** @param  array<string, mixed>  $previa */
    private function criarTitulo(Compra $compra, array $previa, Prestador $fornecedor): void
    {
        $pagamento = $previa['pagamento'];

        $this->compras->criarTitulos($compra, [[
            'vencimento' => $pagamento['vencimento'],
            'valor' => $previa['totais']['total'],
            'forma_pagamento' => $pagamento['forma_pagamento'],
            'ja_pago' => $pagamento['situacao'] === SituacaoPagamentoAvulsa::Pago->value,
            'conta_financeira_id' => $pagamento['conta_financeira_id'],
            'sessao_caixa_id' => $pagamento['sessao_caixa_id'],
        ]], planoPadraoId: $previa['plano_despesa_id']);
    }

    /** Cada item com produto ensina o de-para por descrição deste fornecedor (regra 5). */
    private function aprenderVinculos(Compra $compra, User $usuario): void
    {
        foreach ($compra->itens as $item) {
            $chave = $item->ci_produto_id !== null ? ChaveVinculo::atributos($item, $compra) : null;
            if ($chave === null) {
                continue;
            }

            $vinculo = FornecedorProduto::withTrashed()->firstOrNew($chave);
            $vinculo->fill([
                'fp_produto_id' => $item->ci_produto_id,
                'fp_descricao_fornecedor' => $item->ci_descricao_fornecedor,
                'fp_unidade_compra' => $item->ci_unidade_compra,
                'fp_fator_conversao' => $item->ci_fator_conversao,
                'fp_ultimo_custo_unitario' => $item->ci_custo_unitario_aquisicao,
                'fp_origem' => OrigemVinculo::Manual,
                'fp_confirmado_por' => $usuario->id,
            ]);
            $vinculo->deleted_at = null;
            $vinculo->save();

            $item->forceFill(['ci_vinculo_id' => $vinculo->id])->save();
        }
    }

    private function abrirPendencias(Compra $compra): void
    {
        foreach ($compra->itens->whereNull('ci_produto_id') as $item) {
            $this->pendencias->abrir(TipoPendenciaCompra::ProdutoNaoIdentificado, $compra, $item, [
                'descricao' => $item->ci_descricao_fornecedor,
                'unidade' => $item->ci_unidade_compra,
                'quantidade' => (float) $item->ci_quantidade_compra,
                'valor_unitario' => (float) $item->ci_custo_unitario_compra,
                'origem' => Compra::ORIGEM_AVULSA,
            ]);
        }
    }

    /** @throws ValidationException|AuthorizationException */
    private function rascunhoAbertoDoUsuario(string $uuid, User $usuario): CompraRascunho
    {
        $rascunho = CompraRascunho::query()->where('uuid', $uuid)->first();

        if ($rascunho === null) {
            throw ValidationException::withMessages(['rascunho_uuid' => 'Rascunho não encontrado.']);
        }

        if ((int) $rascunho->user_id !== (int) $usuario->id) {
            throw new AuthorizationException('Este rascunho foi preparado por outro usuário.');
        }

        if ($rascunho->status !== StatusRascunhoCompra::Aberto) {
            throw ValidationException::withMessages(['rascunho_uuid' => 'Este rascunho não está mais aberto ('.$rascunho->status->getLabel().'). Prepare a compra de novo sem rascunho_uuid.']);
        }

        return $rascunho;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function alerta(string $codigo, string $mensagem, ?bool $bloqueia = null, array $dados = []): array
    {
        return array_filter([
            'codigo' => $codigo,
            'mensagem' => $mensagem,
            'bloqueia' => $bloqueia ?? in_array($codigo, self::ALERTAS_BLOQUEANTES, true),
            'dados' => $dados ?: null,
        ], fn (mixed $valor): bool => $valor !== null);
    }

    /** @param  array<string, mixed>  $previa */
    private function resumo(array $previa): string
    {
        $pagamento = $previa['pagamento'];
        $situacao = $pagamento['situacao'] === SituacaoPagamentoAvulsa::Pago->value
            ? 'pago'.($pagamento['conta'] ? " de {$pagamento['conta']}" : '').($pagamento['forma_pagamento'] ? ' ('.(FormaPagamento::tryFrom($pagamento['forma_pagamento'])?->getLabel() ?? $pagamento['forma_pagamento']).')' : '')
            : 'a pagar em '.Carbon::parse($pagamento['vencimento'])->format('d/m/Y');

        $linhas = [
            ($previa['fornecedor']['nome'] ?? 'Fornecedor').($previa['fornecedor']['novo'] ? ' (será cadastrado)' : '')
                .' · '.Carbon::parse($previa['data_compra'])->format('d/m/Y')
                .' · '.count($previa['itens']).' '.Str::plural('item', count($previa['itens']))
                .' · '.$this->moeda($previa['totais']['total'])
                ." · {$situacao}",
        ];

        foreach ($previa['itens'] as $item) {
            $produto = $item['produto'] !== null
                ? "→ {$item['produto']} (".Number::format($item['quantidade_estoque'], maxPrecision: 3, locale: 'pt_BR')." {$item['unidade_estoque']})"
                : '→ sem produto (vira pendência)';
            $linhas[] = '- '.Number::format($item['quantidade'], maxPrecision: 3, locale: 'pt_BR')." {$item['unidade']} {$item['descricao']}: ".$this->moeda($item['valor_total'])." {$produto}";
        }

        if ($previa['totais']['desconto'] > 0) {
            $linhas[] = 'Desconto: '.$this->moeda($previa['totais']['desconto']);
        }

        foreach ($previa['alertas'] as $alerta) {
            $linhas[] = ($alerta['bloqueia'] ? '⛔ ' : '⚠️ ').$alerta['mensagem'];
        }

        return implode("\n", $linhas);
    }

    private function moeda(float $valor): string
    {
        return (string) Number::currency($valor, 'BRL', 'pt_BR');
    }
}
