<?php

namespace App\Services\Compras;

use App\Models\Compra;
use App\Models\CompraItem;
use App\Services\Nfe\Dto\NfeItem;
use App\Services\Nfe\NfeXmlParser;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Recupera o <det> original da NF-e de um item de compra, a partir do XML
 * guardado na importação — traz o que não fica gravado no item (NCM, CEST,
 * unidade tributável), para o usuário configurar o produto corretamente.
 * Compra sem XML (lançada à mão) ou XML ilegível devolve null.
 */
class ItemNotaFiscalResolver
{
    /** @var array<int, list<NfeItem>|null> */
    private array $itensPorCompra = [];

    public function __construct(private NfeXmlParser $parser) {}

    public function resolver(CompraItem $item): ?NfeItem
    {
        $itens = $this->itensDaNota($item->compra);
        if ($itens === null || $itens === []) {
            return null;
        }

        $mesmoCodigo = array_values(array_filter(
            $itens,
            fn (NfeItem $nfeItem): bool => $nfeItem->codigoFornecedor === (string) $item->ci_codigo_fornecedor,
        ));

        foreach ($mesmoCodigo as $nfeItem) {
            if ($nfeItem->descricao === (string) $item->ci_descricao_fornecedor) {
                return $nfeItem;
            }
        }

        return $mesmoCodigo[0] ?? null;
    }

    /** @return list<NfeItem>|null */
    private function itensDaNota(?Compra $compra): ?array
    {
        if ($compra === null || blank($compra->compra_xml_path)) {
            return null;
        }

        if (! array_key_exists($compra->id, $this->itensPorCompra)) {
            try {
                $xml = Storage::disk('local')->get($compra->compra_xml_path);
                $this->itensPorCompra[$compra->id] = $xml ? $this->parser->parse($xml)->itens : null;
            } catch (Throwable) {
                $this->itensPorCompra[$compra->id] = null;
            }
        }

        return $this->itensPorCompra[$compra->id];
    }
}
