<?php

namespace App\Services\Nfe;

use App\Enums\CompraStatusEnum;
use App\Enums\PrestadorCategoriaEnum;
use App\Enums\PrestadorTipoEnum;
use App\Enums\SefazNotaRecebidaStatusEnum;
use App\Exceptions\NfeDestinatarioDivergenteException;
use App\Exceptions\NfeXmlInvalidoException;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\FornecedorProduto;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\SefazNotaRecebida;
use App\Services\Compras\CompraFiscalService;
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
        private CompraFiscalService $fiscal,
    ) {}

    /**
     * @throws ValidationException se a chave de acesso já foi importada antes
     * @throws NfeDestinatarioDivergenteException se o destinatário da nota não for a empresa
     * @throws NfeXmlInvalidoException se o XML for inválido ou a nota não estiver autorizada
     */
    public function importar(string $xmlConteudo, ?int $userId = null): Compra
    {
        $nfe = $this->parser->parse($xmlConteudo);

        $this->validarDestinatario($nfe);

        return DB::transaction(function () use ($nfe, $xmlConteudo, $userId) {
            $this->garantirNaoDuplicada($nfe->chaveAcesso);

            $prestador = $this->resolverFornecedor($nfe);
            $classificacao = $this->fiscal->classificar($nfe, $prestador->id);

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
                ...$this->fiscal->atributosCompra($nfe, $classificacao),
            ]);

            foreach ($nfe->itens as $indice => $item) {
                $dePara = $this->resolverDePara($prestador, $item);

                CompraItem::create([
                    'ci_compra_id' => $compra->id,
                    'ci_produto_id' => $dePara?->fp_produto_id ?? $this->resolverProdutoPorEan($item),
                    'ci_descricao_fornecedor' => $item->descricao,
                    'ci_codigo_fornecedor' => $item->codigoFornecedor,
                    'ci_quantidade_compra' => $item->quantidadeComercial,
                    'ci_unidade_compra' => $item->unidadeComercial,
                    'ci_fator_conversao' => $dePara ? (float) $dePara->fp_fator_conversao : 1,
                    'ci_custo_unitario_compra' => $item->custoUnitarioLiquido(),
                    'ci_lote_codigo' => $item->loteCodigo,
                    'ci_validade' => $item->validade?->toDateString(),
                    ...$this->fiscal->atributosItem($item, $classificacao->porItem[$indice]),
                ]);
            }

            $this->compras->recalcularTotais($compra->fresh('itens'));

            $this->marcarNotaSefazComoImportada($nfe->chaveAcesso, $compra->id);

            return $compra->refresh();
        });
    }

    /**
     * Quando a chave de acesso importada corresponde a uma nota pendente na
     * caixa de entrada da SEFAZ, marca-a como importada — cobre o upload
     * manual de XML, que não passa por SefazDistribuicaoService::importarNota().
     */
    private function marcarNotaSefazComoImportada(string $chaveAcesso, int $compraId): void
    {
        SefazNotaRecebida::where('snr_chave_acesso', $chaveAcesso)
            ->where('snr_status', SefazNotaRecebidaStatusEnum::PENDENTE)
            ->update([
                'snr_status' => SefazNotaRecebidaStatusEnum::IMPORTADA,
                'snr_compra_id' => $compraId,
            ]);
    }

    /**
     * Bloqueia a nota cujo destinatário (CNPJ/CPF) não é a empresa — a
     * distribuição DFe também entrega notas em que somos só transportador ou
     * autXML. Sem <dest> no XML ou sem CNPJ da empresa cadastrado, não há
     * como comparar e a importação segue.
     *
     * @throws NfeDestinatarioDivergenteException
     */
    private function validarDestinatario(NfeParseada $nfe): void
    {
        $empresa = $this->fiscal->cnpjEmpresa();

        if ($empresa === null || $nfe->destinatarioDocumento === null || $nfe->destinatarioDocumento === $empresa) {
            return;
        }

        throw NfeDestinatarioDivergenteException::withMessages([
            'destinatario' => 'Esta nota fiscal não é destinada à sua empresa (destinatário '
                .$nfe->destinatarioDocumento.'). Confira se o XML é o correto.',
        ]);
    }

    /**
     * Barra chave já importada, inclusive a de compra excluída (o unique do
     * banco vale para linhas com soft delete). Rascunho excluído é removido de
     * vez para a nota poder ser importada de novo — o XML segue guardado em
     * disco; compra excluída que não seja rascunho tem estoque/financeiro
     * associados e precisa ser tratada, não reimportada.
     */
    private function garantirNaoDuplicada(string $chave): void
    {
        $existente = Compra::withTrashed()->where('compra_chave_nfe', $chave)->first();

        if ($existente === null) {
            return;
        }

        if (! $existente->trashed()) {
            throw ValidationException::withMessages([
                'compra_chave_nfe' => 'Esta nota fiscal já foi importada anteriormente.',
            ]);
        }

        if (! $existente->isRascunho()) {
            throw ValidationException::withMessages([
                'compra_chave_nfe' => "Esta nota fiscal pertence à compra #{$existente->id}, já {$existente->compra_status->label()} e depois excluída. "
                    .'Restaure a compra (filtro "Excluídos") em vez de importar de novo.',
            ]);
        }

        $existente->forceDelete();
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
     * Resolve o de-para do fornecedor (fornecedor_produtos por cProd) para o
     * item, quando existir. Carrega junto o fp_fator_conversao já aprendido
     * em compras anteriores, para não reiniciar o fator de estoque em 1 a
     * cada nova importação do mesmo fornecedor/produto.
     */
    private function resolverDePara(Prestador $prestador, NfeItem $item): ?FornecedorProduto
    {
        return FornecedorProduto::where('fp_prestador_id', $prestador->id)
            ->where('fp_codigo_fornecedor', $item->codigoFornecedor)
            ->first();
    }

    /**
     * Resolve o insumo do estoque pelo EAN, só quando o XML traz um GTIN de
     * verdade. Usado apenas como fallback quando não há de-para cadastrado —
     * nunca cria produto novo (item sem match fica pendente para o usuário
     * mapear manualmente na revisão).
     */
    private function resolverProdutoPorEan(NfeItem $item): ?int
    {
        if ($item->ean === null) {
            return null;
        }

        return Produto::where('produto_codigo_EAN', $item->ean)->value('id');
    }

    private function armazenarXml(string $conteudo, string $chave): string
    {
        $path = 'compras_xml/'.now()->format('Y/m')."/{$chave}.xml";
        Storage::disk('local')->put($path, $conteudo);

        return $path;
    }
}
