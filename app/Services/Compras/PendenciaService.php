<?php

namespace App\Services\Compras;

use App\Enums\Compras\OrigemVinculo;
use App\Enums\Compras\StatusPendencia;
use App\Enums\Compras\TipoPendenciaCompra;
use App\Enums\CompraStatusEnum;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\CompraPendencia;
use App\Models\FornecedorProduto;
use App\Models\Produto;
use App\Models\User;
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
