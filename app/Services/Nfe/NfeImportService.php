<?php

namespace App\Services\Nfe;

use App\Enums\CompraStatusEnum;
use App\Enums\PrestadorCategoriaEnum;
use App\Enums\PrestadorTipoEnum;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\FornecedorProduto;
use App\Models\Prestador;
use App\Models\Produto;
use App\Services\CompraService;
use App\Services\Nfe\Dto\NfeItem;
use App\Services\Nfe\Dto\NfeParseada;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Importa um XML de NF-e de compra: parseia (via NfeXmlParser), resolve
 * fornecedor e produtos, e persiste um rascunho de Compra + CompraItem
 * pronto para revisão manual no fluxo existente (ItensRelationManager →
 * ConfirmarCompraAction). Não duplica lógica de estoque/rateio/de-para —
 * isso continua 100% em CompraService/EstoqueService.
 */
class NfeImportService
{
    public function __construct(
        private NfeXmlParser $parser,
        private CompraService $compras,
    ) {}

    /**
     * @throws ValidationException se a chave de acesso já foi importada antes
     * @throws \App\Exceptions\NfeXmlInvalidoException se o XML for inválido ou a nota não estiver autorizada
     */
    public function importar(string $xmlConteudo, ?int $userId = null): Compra
    {
        $nfe = $this->parser->parse($xmlConteudo);

        return DB::transaction(function () use ($nfe, $xmlConteudo, $userId) {
            $this->garantirNaoDuplicada($nfe->chaveAcesso);

            $prestador = $this->resolverFornecedor($nfe);

            $compra = Compra::create([
                'compra_prestador_id' => $prestador->id,
                'compra_numero' => $nfe->numero,
                'compra_serie' => $nfe->serie,
                'compra_chave_nfe' => $nfe->chaveAcesso,
                'compra_xml_path' => $this->armazenarXml($xmlConteudo, $nfe->chaveAcesso),
                'compra_data_emissao' => $nfe->dataEmissao?->toDateString(),
                'compra_data_entrada' => now()->toDateString(),
                'compra_valor_frete' => $nfe->valorFrete,
                'compra_valor_desconto' => $nfe->valorDescontoParaRateio(),
                'compra_valor_outros' => $nfe->valorOutros,
                'compra_status' => CompraStatusEnum::RASCUNHO,
                'compra_origem' => 'xml',
                'compra_observacao' => $nfe->avisos() ? implode(' ', $nfe->avisos()) : null,
                'compra_user_id' => $userId,
            ]);

            foreach ($nfe->itens as $item) {
                CompraItem::create([
                    'ci_compra_id' => $compra->id,
                    'ci_produto_id' => $this->resolverProduto($prestador, $item),
                    'ci_descricao_fornecedor' => $item->descricao,
                    'ci_codigo_fornecedor' => $item->codigoFornecedor,
                    'ci_quantidade_compra' => $item->quantidadeComercial,
                    'ci_unidade_compra' => $item->unidadeComercial,
                    'ci_fator_conversao' => 1,
                    'ci_custo_unitario_compra' => $item->custoUnitarioLiquido(),
                    'ci_lote_codigo' => $item->loteCodigo,
                    'ci_validade' => $item->validade?->toDateString(),
                ]);
            }

            $this->compras->recalcularTotais($compra->fresh('itens'));

            return $compra->refresh();
        });
    }

    private function garantirNaoDuplicada(string $chave): void
    {
        if (Compra::where('compra_chave_nfe', $chave)->exists()) {
            throw ValidationException::withMessages([
                'compra_chave_nfe' => 'Esta nota fiscal já foi importada anteriormente.',
            ]);
        }
    }

    /** Reaproveita o fornecedor pelo CNPJ/CPF; cria um novo apenas se não existir. */
    private function resolverFornecedor(NfeParseada $nfe): Prestador
    {
        $emit = $nfe->emitente;

        $prestador = Prestador::where('cpf_cnpj', $emit->documento)->first();
        if ($prestador) {
            return $prestador;
        }

        return Prestador::create([
            'tipo' => $emit->isPessoaFisica() ? PrestadorTipoEnum::PF : PrestadorTipoEnum::PJ,
            'categoria' => PrestadorCategoriaEnum::FORNECEDOR,
            'razao_social' => $emit->razaoSocial,
            'nome_fantasia' => $emit->nomeFantasia,
            'cpf_cnpj' => $emit->documento,
            'inscricao_estadual' => $emit->inscricaoEstadual,
            'email' => $emit->email,
            'telefone' => $emit->telefone,
            'cep' => $emit->cep,
            'endereco' => $emit->endereco,
            'numero' => $emit->numero,
            'complemento' => $emit->complemento,
            'bairro' => $emit->bairro,
            'cidade' => $emit->cidade,
            'uf' => $emit->uf,
        ]);
    }

    /**
     * Resolve o insumo do estoque para o item, em ordem estrita — nunca cria
     * produto novo (evita poluir o cadastro; item sem match fica pendente
     * para o usuário mapear manualmente na revisão):
     *  (a) de-para do fornecedor (fornecedor_produtos por cProd);
     *  (b) EAN, só quando o XML traz um GTIN de verdade;
     *  (c) sem match.
     */
    private function resolverProduto(Prestador $prestador, NfeItem $item): ?int
    {
        $dePara = FornecedorProduto::where('fp_prestador_id', $prestador->id)
            ->where('fp_codigo_fornecedor', $item->codigoFornecedor)
            ->first();
        if ($dePara) {
            return $dePara->fp_produto_id;
        }

        if ($item->ean !== null) {
            $produto = Produto::where('produto_codigo_EAN', $item->ean)->first();
            if ($produto) {
                return $produto->id;
            }
        }

        return null;
    }

    private function armazenarXml(string $conteudo, string $chave): string
    {
        $path = 'compras_xml/'.now()->format('Y/m')."/{$chave}.xml";
        Storage::disk('local')->put($path, $conteudo);

        return $path;
    }
}
