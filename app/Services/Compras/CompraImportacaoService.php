<?php

namespace App\Services\Compras;

use App\Enums\Compras\OrigemVinculo;
use App\Enums\Compras\TipoPendenciaCompra;
use App\Enums\OperacaoEntradaEnum;
use App\Exceptions\NfeDestinatarioDivergenteException;
use App\Exceptions\NfeXmlInvalidoException;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\FornecedorProduto;
use App\Models\Prestador;
use App\Services\Nfe\Dto\NfeParseada;
use App\Services\Nfe\NfeImportService;
use App\Services\Nfe\NfeXmlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Importação automática de NF-e de compra (DF-e e comando
 * compras:importar-xml). Reaproveita o NfeImportService para criar o
 * rascunho e acrescenta, na mesma transação:
 * - pendências do que a regra não decide (fornecedor, item, operação, preço);
 * - de-para pelo EAN (RF06);
 * - custo de aquisição de cada item;
 * - contas a pagar e baixa pelo perfil do fornecedor (FinanceiroCompraService);
 * - entrada automática no estoque (ConferenciaCompraService).
 *
 * Idempotente pela chave de acesso: a mesma nota devolve a compra existente
 * (sem duplicar compra, título nem estoque).
 */
class CompraImportacaoService
{
    public function __construct(
        private NfeXmlParser $parser,
        private NfeImportService $importador,
        private CustoAquisicaoCalculator $custos,
        private PendenciaService $pendencias,
        private FinanceiroCompraService $financeiro,
        private ConferenciaCompraService $conferencia,
        private UsuarioAutomacaoCompras $usuarioAutomacao,
    ) {}

    /**
     * @throws NfeXmlInvalidoException
     * @throws NfeDestinatarioDivergenteException
     * @throws ValidationException
     */
    public function importar(string $xml, ?int $userId = null): Compra
    {
        $nfe = $this->parser->parse($xml);

        // Compra excluída com a mesma chave segue para o importador, que
        // reimporta o rascunho excluído ou recusa a confirmada excluída.
        $existente = Compra::query()->where('compra_chave_nfe', $nfe->chaveAcesso)->first();
        if ($existente !== null) {
            return $existente;
        }

        $manual = $this->compraLancadaAMao($nfe);
        if ($manual !== null) {
            return $this->vincularXml($manual, $xml, $nfe);
        }

        $fornecedorNovo = ! Prestador::query()->where('cpf_cnpj', $nfe->emitente->documento)->exists();
        // Sem usuário (polling/comando), a compra e o estoque ficam com o usuário técnico.
        $userId ??= $this->usuarioAutomacao->id();

        $compra = DB::transaction(function () use ($xml, $userId, $fornecedorNovo): Compra {
            $compra = $this->importador->importar($xml, $userId);
            $compra->forceFill(['compra_importacao_automatica' => true])->save();

            $this->processar($compra, $fornecedorNovo);

            return $compra;
        });

        $this->conferencia->tentarConferir($compra);

        return $compra->refresh();
    }

    private function processar(Compra $compra, bool $fornecedorNovo): void
    {
        $compra->load(['itens.vinculo', 'prestador.condicaoCompra']);
        $condicao = $compra->prestador?->condicaoCompra;

        if ($fornecedorNovo) {
            $this->pendencias->abrir(TipoPendenciaCompra::FornecedorNovo, $compra, null, [
                'cnpj' => $compra->prestador?->cpf_cnpj,
                'nome' => $compra->prestador?->nome_exibicao,
            ]);
        }

        if ($condicao === null) {
            $this->pendencias->abrir(TipoPendenciaCompra::FornecedorSemParametros, $compra, null, [
                'prestador_id' => $compra->compra_prestador_id,
            ]);
        }

        $ehCompra = $compra->compra_operacao === OperacaoEntradaEnum::COMPRA->value;
        if (! $ehCompra) {
            $this->pendencias->abrir(TipoPendenciaCompra::OperacaoFiscal, $compra, null, [
                'operacao' => $compra->compra_operacao,
                'confianca' => $compra->compra_operacao_confianca?->value,
                'motivo' => 'A nota não foi classificada como compra: o contas a pagar e a entrada no estoque ficam para a confirmação manual.',
            ]);
        }

        foreach ($compra->itens as $item) {
            $this->reconhecerItem($compra, $item);
        }

        $this->gravarCustos($compra);
        $this->conferirPrecos($compra, (float) ($condicao?->fcc_tolerancia_preco_pct ?? 15));

        if ($ehCompra) {
            $this->financeiro->processar($compra);
        }
    }

    /** Item sem produto vira pendência; reconhecido pelo EAN do cadastro ganha de-para. */
    private function reconhecerItem(Compra $compra, CompraItem $item): void
    {
        if ($item->ci_produto_id === null) {
            $this->pendencias->abrir(TipoPendenciaCompra::ProdutoNaoIdentificado, $compra, $item, [
                'codigo_fornecedor' => $item->ci_codigo_fornecedor,
                'descricao' => $item->ci_descricao_fornecedor,
                'ean' => $item->ci_ean,
                'unidade' => $item->ci_unidade_compra,
                'quantidade' => (float) $item->ci_quantidade_compra,
                'valor_unitario' => (float) $item->ci_custo_unitario_compra,
            ]);

            return;
        }

        if ($item->ci_vinculo_id !== null || blank($item->ci_codigo_fornecedor) || $compra->compra_prestador_id === null) {
            return;
        }

        // Vínculo excluído pelo usuário com o mesmo código: não recria sozinho.
        $existente = FornecedorProduto::withTrashed()
            ->where('fp_prestador_id', $compra->compra_prestador_id)
            ->where('fp_codigo_fornecedor', $item->ci_codigo_fornecedor)
            ->first();
        if ($existente !== null) {
            return;
        }

        $vinculo = FornecedorProduto::query()->create([
            'fp_prestador_id' => $compra->compra_prestador_id,
            'fp_codigo_fornecedor' => $item->ci_codigo_fornecedor,
            'fp_produto_id' => $item->ci_produto_id,
            'fp_descricao_fornecedor' => $item->ci_descricao_fornecedor,
            'fp_unidade_compra' => $item->ci_unidade_compra,
            'fp_fator_conversao' => $item->ci_fator_conversao,
            'fp_ean' => $item->ci_ean,
            'fp_origem' => OrigemVinculo::Ean,
        ]);

        $item->forceFill(['ci_vinculo_id' => $vinculo->id])->save();
        $item->setRelation('vinculo', $vinculo);
    }

    private function gravarCustos(Compra $compra): void
    {
        $custos = $this->custos->calcular($compra, $compra->itens);

        foreach ($compra->itens as $item) {
            $item->forceFill([
                'ci_custo_aquisicao_total' => $custos[$item->id]['total'],
                'ci_custo_unitario_aquisicao' => $custos[$item->id]['unitario'],
            ])->save();
        }
    }

    /** Pendência informativa: não bloqueia a compra (regra 14). */
    private function conferirPrecos(Compra $compra, float $toleranciaPct): void
    {
        foreach ($compra->itens as $item) {
            $ultimo = (float) ($item->vinculo?->fp_ultimo_custo_unitario ?? 0);
            $atual = (float) $item->ci_custo_unitario_aquisicao;

            if ($ultimo <= 0 || $atual <= 0) {
                continue;
            }

            $variacao = round(($atual - $ultimo) / $ultimo * 100, 2);
            if (abs($variacao) > $toleranciaPct) {
                $this->pendencias->abrir(TipoPendenciaCompra::PrecoForaPadrao, $compra, $item, [
                    'ultimo_custo_unitario' => $ultimo,
                    'custo_unitario' => $atual,
                    'variacao_pct' => $variacao,
                    'tolerancia_pct' => $toleranciaPct,
                ]);
            }
        }
    }

    /** Compra lançada à mão, sem chave, do mesmo fornecedor + número + série (regra 2). */
    private function compraLancadaAMao(NfeParseada $nfe): ?Compra
    {
        $prestadorId = Prestador::query()->where('cpf_cnpj', $nfe->emitente->documento)->value('id');

        if ($prestadorId === null || blank($nfe->numero)) {
            return null;
        }

        return Compra::query()
            ->where('compra_prestador_id', $prestadorId)
            ->whereNull('compra_chave_nfe')
            ->whereNotNull('compra_numero')
            ->get()
            ->first(fn (Compra $compra): bool => self::mesmoNumero($compra->compra_numero, $nfe->numero)
                && self::mesmoNumero($compra->compra_serie, $nfe->serie));
    }

    private function vincularXml(Compra $compra, string $xml, NfeParseada $nfe): Compra
    {
        $compra->forceFill([
            'compra_chave_nfe' => $nfe->chaveAcesso,
            'compra_xml_path' => $this->importador->armazenarXml($xml, $nfe->chaveAcesso),
            'compra_observacao' => trim(($compra->compra_observacao ?? '')."\nXML da NF-e vinculado automaticamente em ".now()->format('d/m/Y H:i').'.'),
        ])->save();

        $this->importador->marcarNotaSefazComoImportada($nfe->chaveAcesso, $compra->id);

        return $compra->refresh();
    }

    private static function mesmoNumero(?string $a, ?string $b): bool
    {
        return ltrim((string) $a, '0 ') === ltrim((string) $b, '0 ');
    }
}
