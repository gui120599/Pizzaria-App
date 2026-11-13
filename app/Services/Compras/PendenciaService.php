<?php

namespace App\Services\Compras;

use App\Enums\Compras\AutonomiaAgente;
use App\Enums\Compras\OrigemVinculo;
use App\Enums\Compras\StatusPendencia;
use App\Enums\Compras\TipoPendenciaCompra;
use App\Enums\CompraStatusEnum;
use App\Enums\ProdutoTipoEnum;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\CompraPendencia;
use App\Models\ExtratoBancarioItem;
use App\Models\FornecedorProduto;
use App\Models\Lancamento;
use App\Models\Produto;
use App\Models\User;
use App\Services\Financeiro\ConciliacaoBoletoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Único ponto que abre e resolve pendências da automação de compras. Toda
 * resolução de item grava o de-para (RF06): a mesma linha da nota não vira
 * pendência de novo.
 */
class PendenciaService
{
    public function __construct(private ConferenciaCompraService $conferencia) {}

    /**
     * Abre a pendência, ou devolve a que já está em aberto para a mesma
     * compra, item e tipo (reprocessar não duplica).
     *
     * @param  array<string, mixed>  $dados
     */
    public function abrir(TipoPendenciaCompra $tipo, Compra $compra, ?CompraItem $item = null, array $dados = []): CompraPendencia
    {
        $existente = CompraPendencia::query()
            ->where('compra_id', $compra->id)
            ->where('compra_item_id', $item?->id)
            ->where('tipo', $tipo)
            ->emAberto()
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        return CompraPendencia::create([
            'compra_id' => $compra->id,
            'compra_item_id' => $item?->id,
            'tipo' => $tipo,
            'status' => StatusPendencia::Aberta,
            'dados' => $dados,
        ]);
    }

    /**
     * Resolve um item sem produto (ou com conversão a confirmar): vincula o
     * item ao produto com o fator, grava o de-para do fornecedor e tenta a
     * entrada automática da compra. Pendências do mesmo código do fornecedor
     * em outras compras em rascunho são resolvidas junto.
     *
     * @throws ValidationException
     */
    public function resolverVinculo(
        CompraPendencia $pendencia,
        int $produtoId,
        float $fatorConversao,
        ?User $usuario = null,
        OrigemVinculo $origem = OrigemVinculo::Manual,
    ): CompraPendencia {
        $outrasCompras = [];

        $resolvida = DB::transaction(function () use ($pendencia, $produtoId, $fatorConversao, $usuario, $origem, &$outrasCompras): CompraPendencia {
            $pendencia = CompraPendencia::query()->lockForUpdate()->findOrFail($pendencia->getKey());
            $item = $pendencia->item;

            if (! $pendencia->estaEmAberto() || $item === null) {
                throw ValidationException::withMessages(['pendencia' => 'Esta pendência não está em aberto ou não é de um item.']);
            }

            if ($pendencia->compra->compra_status !== CompraStatusEnum::RASCUNHO) {
                throw ValidationException::withMessages(['pendencia' => 'A compra já foi confirmada ou cancelada.']);
            }

            if ($fatorConversao <= 0 || ! Produto::query()->whereKey($produtoId)->exists()) {
                throw ValidationException::withMessages(['produto' => 'Informe um produto existente e um fator de conversão maior que zero.']);
            }

            $vinculo = $this->gravarVinculo($item, $produtoId, $fatorConversao, $usuario, $origem);
            $resolucao = ['produto_id' => $produtoId, 'fator_conversao' => $fatorConversao, 'vinculo_id' => $vinculo?->id];

            $this->vincularItem($item, $produtoId, $fatorConversao, $vinculo);
            $this->marcar($pendencia, StatusPendencia::Resolvida, $usuario, $resolucao);

            if (filled($item->ci_codigo_fornecedor)) {
                $outrasCompras = $this->resolverMesmoCodigo($pendencia, $item, $produtoId, $fatorConversao, $vinculo, $usuario, $resolucao);
            }

            return $pendencia;
        });

        $this->conferencia->tentarConferir($resolvida->compra);

        foreach ($outrasCompras as $compra) {
            $this->conferencia->tentarConferir($compra);
        }

        return $resolvida->refresh();
    }

    /**
     * Guarda a sugestão (do agente) e deixa a pendência aguardando aprovação.
     *
     * Formatos de $sugestao:
     * - ['acao' => 'vincular', 'produto_id' => int, 'fator_conversao' => float]
     * - ['acao' => 'novo_produto', 'produto' => [atributos do Produto], 'fator_conversao' => float]
     * - ['acao' => 'lancamento', 'lancamento_id' => int]
     *
     * @param  array<string, mixed>  $sugestao
     *
     * @throws ValidationException
     */
    public function registrarSugestao(CompraPendencia $pendencia, array $sugestao, ?float $confianca = null, ?string $justificativa = null): CompraPendencia
    {
        if (! $pendencia->estaEmAberto()) {
            throw ValidationException::withMessages(['pendencia' => 'Esta pendência não está mais em aberto.']);
        }

        $pendencia->forceFill([
            'status' => StatusPendencia::Sugerida,
            'sugestao' => $sugestao,
            'confianca' => $confianca !== null ? max(0, min(1, round($confianca, 3))) : null,
            'justificativa' => $justificativa,
        ])->save();

        return $pendencia;
    }

    /**
     * Proposta de vínculo do agente: executa se a autonomia do fornecedor não
     * for "sugerir" e a confiança atingir o limite (compras.agente.limite_confianca);
     * senão fica como sugestão para aprovação humana.
     *
     * @return array{pendencia_id: int, situacao: string, motivo: string}
     *
     * @throws ValidationException
     */
    public function proporVinculo(CompraPendencia $pendencia, int $produtoId, float $fatorConversao, float $confianca, string $justificativa, ?User $agente = null): array
    {
        $this->garantirPendenciaDeItem($pendencia);

        if (! Produto::query()->whereKey($produtoId)->exists()) {
            throw ValidationException::withMessages(['produto_id' => 'Produto não encontrado: use buscar_produtos.']);
        }

        $this->registrarSugestao($pendencia, ['acao' => 'vincular', 'produto_id' => $produtoId, 'fator_conversao' => $fatorConversao], $confianca, $justificativa);

        $autonomia = $this->autonomia($pendencia);
        $limite = (float) config('compras.agente.limite_confianca', 0.9);

        if ($autonomia === AutonomiaAgente::Sugerir || $confianca < $limite) {
            return $this->resultadoProposta($pendencia, 'sugerida', $autonomia === AutonomiaAgente::Sugerir
                ? 'O fornecedor está com autonomia "sugerir": aguarda aprovação.'
                : "Confiança abaixo de {$limite}: aguarda aprovação.");
        }

        $this->aprovarSugestao($pendencia->fresh(), $agente);

        return $this->resultadoProposta($pendencia, 'executada', 'Vínculo gravado no de-para.');
    }

    /**
     * Proposta de produto novo: só executa com autonomia "completa" e
     * confiança no limite; o produto nasce para revisão.
     *
     * @param  array<string, mixed>  $dadosProduto
     * @return array{pendencia_id: int, situacao: string, motivo: string}
     *
     * @throws ValidationException
     */
    public function proporNovoProduto(CompraPendencia $pendencia, array $dadosProduto, float $fatorConversao, float $confianca, string $justificativa, ?User $agente = null): array
    {
        $this->garantirPendenciaDeItem($pendencia);

        $this->registrarSugestao($pendencia, ['acao' => 'novo_produto', 'produto' => $dadosProduto, 'fator_conversao' => $fatorConversao], $confianca, $justificativa);

        $limite = (float) config('compras.agente.limite_confianca', 0.9);
        if ($this->autonomia($pendencia) !== AutonomiaAgente::Completa || $confianca < $limite) {
            return $this->resultadoProposta($pendencia, 'sugerida', 'Produto novo só é cadastrado sozinho com autonomia "completa" e confiança alta: aguarda aprovação.');
        }

        $this->aprovarSugestao($pendencia->fresh(), $agente);

        return $this->resultadoProposta($pendencia, 'executada', 'Produto cadastrado (pendente de revisão) e vinculado.');
    }

    /**
     * Sugestão de lançamento (lançamento ambíguo ou débito do extrato com
     * mais de uma parcela possível): sempre aguarda aprovação.
     *
     * @return array{pendencia_id: int, situacao: string, motivo: string}
     *
     * @throws ValidationException
     */
    public function sugerirLancamento(CompraPendencia $pendencia, int $lancamentoId, ?float $confianca, string $justificativa): array
    {
        $candidatos = collect($pendencia->dados['candidatos'] ?? [])->pluck('lancamento_id')->map(fn ($id): int => (int) $id);

        $escolhe = in_array($pendencia->tipo, [TipoPendenciaCompra::LancamentoAmbiguo, TipoPendenciaCompra::PagamentoAConfirmar], true);

        if (! $escolhe || ! $candidatos->contains($lancamentoId)) {
            throw ValidationException::withMessages(['lancamento_id' => 'Só para lançamento ambíguo ou pagamento a confirmar com candidatos, escolhendo um deles.']);
        }

        $this->registrarSugestao($pendencia, ['acao' => 'lancamento', 'lancamento_id' => $lancamentoId], $confianca, $justificativa);

        return $this->resultadoProposta($pendencia, 'sugerida', 'Lançamentos sempre aguardam aprovação humana.');
    }

    /** @throws ValidationException */
    private function garantirPendenciaDeItem(CompraPendencia $pendencia): void
    {
        if (! $pendencia->estaEmAberto()
            || ! in_array($pendencia->tipo, [TipoPendenciaCompra::ProdutoNaoIdentificado, TipoPendenciaCompra::ConversaoUnidade], true)) {
            throw ValidationException::withMessages(['pendencia_id' => 'Só para pendências em aberto de produto não identificado ou conversão de unidade.']);
        }
    }

    private function autonomia(CompraPendencia $pendencia): AutonomiaAgente
    {
        return $pendencia->compra?->prestador?->condicaoCompra?->autonomiaAgente() ?? AutonomiaAgente::PADRAO;
    }

    /** @return array{pendencia_id: int, situacao: string, motivo: string} */
    private function resultadoProposta(CompraPendencia $pendencia, string $situacao, string $motivo): array
    {
        return ['pendencia_id' => $pendencia->id, 'situacao' => $situacao, 'motivo' => $motivo];
    }

    /**
     * Aplica a sugestão registrada (aprovação humana ou execução direta do
     * agente, quando a autonomia permite). O vínculo fica com origem
     * "agente"; quem aprovou fica como confirmado_por.
     *
     * @throws ValidationException
     */
    public function aprovarSugestao(CompraPendencia $pendencia, ?User $usuario = null): CompraPendencia
    {
        $sugestao = $pendencia->sugestao ?? [];

        return match ($sugestao['acao'] ?? null) {
            'vincular' => $this->resolverVinculo($pendencia, (int) ($sugestao['produto_id'] ?? 0), (float) ($sugestao['fator_conversao'] ?? 1), $usuario, OrigemVinculo::Agente),
            'novo_produto' => $this->resolverComProdutoNovo($pendencia, (array) ($sugestao['produto'] ?? []), (float) ($sugestao['fator_conversao'] ?? 1), $usuario),
            'lancamento' => $this->resolverLancamento($pendencia, (int) ($sugestao['lancamento_id'] ?? 0), $usuario),
            default => throw ValidationException::withMessages(['pendencia' => 'Esta pendência não tem sugestão para aprovar.']),
        };
    }

    /**
     * Lançamento ambíguo: vincula à compra o lançamento escolhido entre os
     * candidatos (ainda sem compra). Pagamento a confirmar vindo do extrato
     * (débito com mais de uma parcela possível): concilia o débito com a
     * parcela escolhida, baixando-a na data do extrato.
     *
     * @throws ValidationException
     */
    public function resolverLancamento(CompraPendencia $pendencia, int $lancamentoId, ?User $usuario = null): CompraPendencia
    {
        return DB::transaction(function () use ($pendencia, $lancamentoId, $usuario): CompraPendencia {
            $pendencia = CompraPendencia::query()->lockForUpdate()->findOrFail($pendencia->getKey());
            $candidatos = collect($pendencia->dados['candidatos'] ?? [])->pluck('lancamento_id')->map(fn ($id): int => (int) $id);
            $doExtrato = $pendencia->tipo === TipoPendenciaCompra::PagamentoAConfirmar && filled($pendencia->dados['extrato_item_id'] ?? null);

            if (! $pendencia->estaEmAberto() || ($pendencia->tipo !== TipoPendenciaCompra::LancamentoAmbiguo && ! $doExtrato)) {
                throw ValidationException::withMessages(['pendencia' => 'Esta pendência não é de escolha de lançamento.']);
            }

            $lancamento = Lancamento::query()->lockForUpdate()->find($lancamentoId);
            if ($lancamento === null || ! $candidatos->contains($lancamentoId)) {
                throw ValidationException::withMessages(['lancamento' => 'Escolha um dos lançamentos candidatos.']);
            }

            if ($doExtrato) {
                $item = ExtratoBancarioItem::query()->findOrFail((int) $pendencia->dados['extrato_item_id']);
                app(ConciliacaoBoletoService::class)->conciliarManual($item, $lancamento, $usuario);

                return $pendencia->refresh();
            }

            if ($lancamento->compra_id !== null) {
                throw ValidationException::withMessages(['lancamento' => 'Este lançamento já está ligado a outra compra.']);
            }

            $lancamento->forceFill(['compra_id' => $pendencia->compra_id])->save();
            $this->marcar($pendencia, StatusPendencia::Resolvida, $usuario, ['lancamento_id' => $lancamentoId]);

            return $pendencia;
        });
    }

    /** Débito do extrato conciliado: resolve a pendência de escolha que apontava para ele. */
    public function resolverDoExtrato(ExtratoBancarioItem $item, Lancamento $titulo, ?User $usuario = null): int
    {
        $pendencias = CompraPendencia::query()
            ->emAberto()
            ->where('tipo', TipoPendenciaCompra::PagamentoAConfirmar)
            ->get()
            ->filter(fn (CompraPendencia $pendencia): bool => (int) ($pendencia->dados['extrato_item_id'] ?? 0) === $item->id);

        foreach ($pendencias as $pendencia) {
            $this->marcar($pendencia, StatusPendencia::Resolvida, $usuario, ['lancamento_id' => $titulo->id, 'extrato_item_id' => $item->id]);
        }

        return $pendencias->count();
    }

    /**
     * Pagamento confirmado por outra tela: resolve as pendências de baixa
     * (caixa fechado, pagamento a confirmar) que citam o título.
     */
    public function resolverDoLancamento(Lancamento $lancamento, ?User $usuario = null): int
    {
        if ($lancamento->compra_id === null) {
            return 0;
        }

        $pendencias = CompraPendencia::query()
            ->emAberto()
            ->where('compra_id', $lancamento->compra_id)
            ->whereIn('tipo', [TipoPendenciaCompra::CaixaPdvFechado, TipoPendenciaCompra::PagamentoAConfirmar])
            ->get()
            ->filter(fn (CompraPendencia $pendencia): bool => (int) ($pendencia->dados['lancamento_id'] ?? 0) === $lancamento->id
                || in_array($lancamento->id, array_map('intval', $pendencia->dados['lancamentos'] ?? []), true));

        foreach ($pendencias as $pendencia) {
            $this->marcar($pendencia, StatusPendencia::Resolvida, $usuario, ['lancamento_id' => $lancamento->id, 'pagamento_confirmado' => true]);
        }

        return $pendencias->count();
    }

    /**
     * Cadastra o produto sugerido (marcado para revisão, fora das fichas
     * técnicas) e vincula o item a ele.
     *
     * @param  array<string, mixed>  $dados
     *
     * @throws ValidationException
     */
    public function resolverComProdutoNovo(CompraPendencia $pendencia, array $dados, float $fatorConversao, ?User $usuario = null): CompraPendencia
    {
        $produto = $this->cadastrarProdutoParaRevisao($pendencia, $dados);

        return $this->resolverVinculo($pendencia, $produto->id, $fatorConversao, $usuario, OrigemVinculo::Agente);
    }

    /**
     * @param  array<string, mixed>  $dados
     *
     * @throws ValidationException
     */
    private function cadastrarProdutoParaRevisao(CompraPendencia $pendencia, array $dados): Produto
    {
        $descricao = trim((string) ($dados['produto_descricao'] ?? ''));
        if ($descricao === '') {
            throw ValidationException::withMessages(['produto' => 'Informe o nome do produto novo.']);
        }

        $categoriaId = $dados['produto_categoria_id'] ?? $this->categoriaSugerida($pendencia);
        if ($categoriaId === null) {
            throw ValidationException::withMessages(['produto' => 'Não há categoria para o produto novo: informe produto_categoria_id.']);
        }

        return Produto::create([
            'produto_descricao' => mb_substr($descricao, 0, 255),
            'produto_categoria_id' => (int) $categoriaId,
            'produto_tipo' => $dados['produto_tipo'] ?? ProdutoTipoEnum::INSUMO->value,
            'produto_unidade_estoque' => $dados['produto_unidade_estoque'] ?? $pendencia->item?->ci_unidade_compra,
            'produto_codigo_EAN' => $dados['produto_codigo_EAN'] ?? ($pendencia->item?->ci_ean ?: 'SEM GTIN'),
            'produto_plano_despesa_id' => $dados['produto_plano_despesa_id'] ?? $pendencia->compra?->prestador?->condicaoCompra?->fcc_plano_despesa_id,
            'produto_cadastro_pendente_revisao' => true,
            'produto_origem_cadastro' => 'agente',
        ]);
    }

    /** Categoria do último produto vinculado a este fornecedor; senão a primeira com insumos. */
    private function categoriaSugerida(CompraPendencia $pendencia): ?int
    {
        $prestadorId = $pendencia->compra?->compra_prestador_id;

        $doFornecedor = $prestadorId === null ? null : Produto::query()
            ->whereIn('id', FornecedorProduto::query()->where('fp_prestador_id', $prestadorId)->select('fp_produto_id'))
            ->latest('id')
            ->value('produto_categoria_id');

        return $doFornecedor ?? Produto::query()->where('produto_tipo', ProdutoTipoEnum::INSUMO->value)->value('produto_categoria_id');
    }

    /**
     * Marca como resolvida sem ação de item (ex.: fornecedor parametrizado,
     * pagamento confirmado por outra tela).
     *
     * @param  array<string, mixed>  $resolucao
     */
    public function resolver(CompraPendencia $pendencia, ?User $usuario = null, array $resolucao = []): CompraPendencia
    {
        $this->marcar($pendencia, StatusPendencia::Resolvida, $usuario, $resolucao);

        return $pendencia;
    }

    public function descartar(CompraPendencia $pendencia, ?User $usuario = null, ?string $motivo = null): CompraPendencia
    {
        $this->marcar($pendencia, StatusPendencia::Descartada, $usuario, array_filter(['motivo' => $motivo]));

        return $pendencia;
    }

    /** Descarta todas as pendências em aberto da compra (ex.: NF-e cancelada). */
    public function descartarDaCompra(Compra $compra, string $motivo, ?User $usuario = null): int
    {
        $pendencias = $compra->pendencias()->emAberto()->get();

        foreach ($pendencias as $pendencia) {
            $this->descartar($pendencia, $usuario, $motivo);
        }

        return $pendencias->count();
    }

    /** @param  array<string, mixed>  $resolucao */
    private function marcar(CompraPendencia $pendencia, StatusPendencia $status, ?User $usuario, array $resolucao): void
    {
        $pendencia->forceFill([
            'status' => $status,
            'resolucao' => $resolucao ?: null,
            'resolvida_por' => $usuario?->id,
            'resolvida_em' => now(),
        ])->save();
    }

    private function gravarVinculo(CompraItem $item, int $produtoId, float $fator, ?User $usuario, OrigemVinculo $origem): ?FornecedorProduto
    {
        $prestadorId = $item->compra->compra_prestador_id;

        if ($prestadorId === null || blank($item->ci_codigo_fornecedor)) {
            return null;
        }

        $vinculo = FornecedorProduto::withTrashed()->firstOrNew([
            'fp_prestador_id' => $prestadorId,
            'fp_codigo_fornecedor' => $item->ci_codigo_fornecedor,
        ]);

        $vinculo->fill([
            'fp_produto_id' => $produtoId,
            'fp_descricao_fornecedor' => $item->ci_descricao_fornecedor,
            'fp_unidade_compra' => $item->ci_unidade_compra,
            'fp_fator_conversao' => $fator,
            'fp_ean' => $item->ci_ean ?? $vinculo->fp_ean,
            'fp_origem' => $origem,
            'fp_confirmado_por' => $usuario?->id,
        ]);
        $vinculo->deleted_at = null;
        $vinculo->save();

        return $vinculo;
    }

    private function vincularItem(CompraItem $item, int $produtoId, float $fator, ?FornecedorProduto $vinculo): void
    {
        $item->forceFill([
            'ci_produto_id' => $produtoId,
            'ci_fator_conversao' => $fator,
            'ci_vinculo_id' => $vinculo?->id,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $resolucao
     * @return list<Compra> as outras compras que tiveram pendência resolvida
     */
    private function resolverMesmoCodigo(
        CompraPendencia $origem,
        CompraItem $item,
        int $produtoId,
        float $fator,
        ?FornecedorProduto $vinculo,
        ?User $usuario,
        array $resolucao,
    ): array {
        $outras = CompraPendencia::query()
            ->emAberto()
            ->whereKeyNot($origem->id)
            ->whereIn('tipo', [TipoPendenciaCompra::ProdutoNaoIdentificado, TipoPendenciaCompra::ConversaoUnidade])
            ->whereHas('compra', fn ($query) => $query
                ->where('compra_prestador_id', $item->compra->compra_prestador_id)
                ->where('compra_status', CompraStatusEnum::RASCUNHO))
            ->whereHas('item', fn ($query) => $query->where('ci_codigo_fornecedor', $item->ci_codigo_fornecedor))
            ->with('item', 'compra')
            ->get();

        foreach ($outras as $outra) {
            $this->vincularItem($outra->item, $produtoId, $fator, $vinculo);
            $this->marcar($outra, StatusPendencia::Resolvida, $usuario, [...$resolucao, 'pela_pendencia' => $origem->id]);
        }

        return $outras->pluck('compra')->unique('id')->values()->all();
    }
}
