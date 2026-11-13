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
use App\Models\Prestador;
use App\Models\SefazNotaRecebida;
use App\Services\Compras\CompraFiscalService;
use App\Services\Compras\ProdutoMatcher;
use App\Services\CompraService;
use App\Services\Nfe\Dto\NfeParseada;
use Illuminate\Support\Carbon;
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
        private ProdutoMatcher $matcher,
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
                'compra_data_entrada' => $this->dataEntrada($prestador, $nfe)->toDateString(),
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
                $match = $this->matcher->identificar($prestador->id, $item->codigoFornecedor, $item->ean);

                CompraItem::create([
                    'ci_compra_id' => $compra->id,
                    'ci_produto_id' => $match?->produtoId,
                    'ci_vinculo_id' => $match?->vinculo?->id,
                    'ci_descricao_fornecedor' => $item->descricao,
                    'ci_codigo_fornecedor' => $item->codigoFornecedor,
                    'ci_ean' => $item->ean !== null ? mb_substr($item->ean, 0, 14) : null,
                    'ci_quantidade_compra' => $item->quantidadeComercial,
                    'ci_unidade_compra' => $item->unidadeComercial,
                    'ci_fator_conversao' => $match?->fatorConversao ?? 1,
                    'ci_custo_unitario_compra' => $item->custoUnitarioLiquido(),
                    'ci_valor_frete' => $item->valorFrete,
                    'ci_valor_seguro' => $item->valorSeguro,
                    'ci_valor_outros' => $item->valorOutros,
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

    /** Dia da importação, ou a emissão da nota quando o fornecedor tem essa regra de data de entrada. */
    private function dataEntrada(Prestador $prestador, NfeParseada $nfe): Carbon
    {
        $emissao = $nfe->dataEmissao !== null ? Carbon::instance($nfe->dataEmissao) : null;

        return $prestador->condicaoCompra?->dataEntradaSugerida($emissao, now()) ?? now();
    }

    /**
     * Quando a chave de acesso importada corresponde a uma nota pendente na
     * caixa de entrada da SEFAZ, marca-a como importada — cobre o upload
     * manual de XML, que não passa por SefazDistribuicaoService::importarNota().
     */
    public function marcarNotaSefazComoImportada(string $chaveAcesso, int $compraId): void
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

    public function armazenarXml(string $conteudo, string $chave): string
    {
        $path = 'compras_xml/'.now()->format('Y/m')."/{$chave}.xml";
        Storage::disk('local')->put($path, $conteudo);

        return $path;
    }
}
