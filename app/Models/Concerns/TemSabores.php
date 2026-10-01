<?php

namespace App\Models\Concerns;

use App\Support\FormatoQuantidade;

/**
 * Item (de pedido ou de venda) que pode ser uma pizza de vários sabores.
 *
 * A pizza é UMA linha: o produto da linha é o 1º sabor e a coluna JSON de
 * sabores congela, por sabor, o produto, o nome, o percentual (posição na
 * opção de quantidade da categoria) e o preço/desconto no momento da venda.
 * Congelar o percentual é o que mantém a baixa/estorno de estoque e o ledger
 * de promoção corretos mesmo se a categoria mudar o rateio depois.
 *
 * O model que usa o trait define colunaSabores(), colunaProduto() e
 * colunaQuantidade().
 */
trait TemSabores
{
    abstract protected function colunaSabores(): string;

    abstract protected function colunaProduto(): string;

    abstract protected function colunaQuantidade(): string;

    public function ehMultiSabor(): bool
    {
        return count($this->sabores()) > 1;
    }

    /**
     * @return array<int, array{produto_id: int, nome: string, percentual: float, rotulo?: string, valor_unitario?: float, desconto_unitario?: float, promocao_adicional_regra_id?: ?int, custo_unitario?: float}>
     */
    public function sabores(): array
    {
        $sabores = $this->getAttribute($this->colunaSabores());

        if (! is_array($sabores)) {
            return [];
        }

        // O JSON devolve 50.0 como inteiro — normaliza o percentual.
        return array_map(
            fn (array $sabor): array => ['percentual' => (float) ($sabor['percentual'] ?? 0)] + $sabor,
            array_values($sabores),
        );
    }

    /**
     * Quanto de cada produto esta linha consome: a quantidade da linha vezes
     * o percentual congelado de cada sabor. Item comum devolve o próprio
     * produto com a quantidade cheia. Fonte única para baixa de estoque,
     * ledger de promoção e relatórios por produto.
     *
     * @return array<int, float> produto_id => quantidade
     */
    public function consumosPorProduto(?float $quantidade = null): array
    {
        $quantidade ??= (float) $this->getAttribute($this->colunaQuantidade());

        if (! $this->ehMultiSabor()) {
            return [(int) $this->getAttribute($this->colunaProduto()) => $quantidade];
        }

        $consumos = [];

        foreach ($this->sabores() as $sabor) {
            $produtoId = (int) $sabor['produto_id'];
            $consumos[$produtoId] = ($consumos[$produtoId] ?? 0) + round($quantidade * ((float) $sabor['percentual']) / 100, 4);
        }

        return $consumos;
    }

    /**
     * "MEIA Calabresa / MEIA Mussarela" numa linha só (onde só cabe texto
     * corrido, ex.: JSON do kanban legado); NF-e usa $ascii ("1/2 Calabresa
     * / 1/2 Mussarela"). Vazio para item comum. Para telas e impressões,
     * prefira linhasSabores() — um sabor por linha.
     */
    public function descricaoSabores(bool $ascii = false): string
    {
        if (! $this->ehMultiSabor()) {
            return '';
        }

        return collect($this->sabores())
            ->map(fn (array $sabor): string => $ascii
                ? self::rotuloFracao((float) $sabor['percentual'], ascii: true).' '.$sabor['nome']
                : self::linhaSabor($sabor))
            ->implode(' / ');
    }

    /**
     * Nome do item para exibição (comanda, PDFs, painéis): a lista de sabores
     * com a fração de cada um, ou o nome do produto para item comum.
     */
    public function nomeProduto(): string
    {
        if ($this->ehMultiSabor()) {
            return $this->descricaoSabores();
        }

        return $this->produto?->produto_descricao ?? '—';
    }

    /**
     * Sabores para telas e impressões, um por linha e com a fração por
     * extenso — "MEIA CALABRESA" / "MEIA MUSSARELA" —, que é o que a
     * cozinha lê mais rápido. Vazio para item comum.
     *
     * @return array<int, string>
     */
    public function linhasSabores(): array
    {
        if (! $this->ehMultiSabor()) {
            return [];
        }

        return array_map(fn (array $sabor): string => self::linhaSabor($sabor), $this->sabores());
    }

    /**
     * "MEIA Calabresa": a descrição congelada no item (configurada na opção
     * de quantidade da categoria) + o nome. Descrição vazia = só o nome; item
     * gravado antes da descrição existir (sem a chave) cai no por extenso.
     *
     * @param  array{nome: string, percentual: float, rotulo?: ?string}  $sabor
     */
    private static function linhaSabor(array $sabor): string
    {
        $rotulo = array_key_exists('rotulo', $sabor)
            ? (string) $sabor['rotulo']
            : self::fracaoPorExtenso((float) $sabor['percentual']);

        return trim($rotulo.' '.$sabor['nome']);
    }

    /**
     * 50 → MEIA, 33,33 → TERÇO, 25 → QUARTO; fração fora dessas cai no
     * percentual (ex.: 40%).
     */
    public static function fracaoPorExtenso(float $percentual): string
    {
        return match (self::rotuloFracao($percentual)) {
            '½' => 'MEIA',
            '⅓' => 'TERÇO',
            '¼' => 'QUARTO',
            '⅔' => 'DOIS TERÇOS',
            '¾' => 'TRÊS QUARTOS',
            default => self::rotuloFracao($percentual),
        };
    }

    /**
     * Fração legível de um percentual: 50 → ½ (ou 1/2 em $ascii), 33,33 → ⅓, 40 → 40%.
     */
    public static function rotuloFracao(float $percentual, bool $ascii = false): string
    {
        $fracao = FormatoQuantidade::item($percentual / 100);

        // NF-e: o caractere de fração (⅓) fica fora do Latin-1 e pode ser
        // recusado na SEFAZ — usa a forma "1/3".
        if ($ascii) {
            $fracao = strtr($fracao, ['¼' => '1/4', '⅓' => '1/3', '½' => '1/2', '⅔' => '2/3', '¾' => '3/4']);
        }

        return str_contains($fracao, ',') || $fracao === '0' || $fracao === '1'
            ? rtrim(rtrim(number_format($percentual, 2, ',', ''), '0'), ',').'%'
            : $fracao;
    }
}
