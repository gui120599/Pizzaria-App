<?php

namespace App\Support\Cardapio;

use App\Models\Produto;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * O que o cardápio público mostra de um produto, resolvido uma vez só: nome,
 * descrição, preço (o mesmo que o servidor cobra, via precoResolvido()),
 * política de sabores e fotos. Card, vitrine e sheet de detalhe leem daqui
 * para não divergirem entre si.
 */
final class ProdutoCardapio
{
    public readonly int $id;

    /** Nome completo (nomeExibicao) — é o que vai para o carrinho. */
    public readonly string $nome;

    /** Só a descrição do produto, para dentro da própria categoria ("Calabresa"). */
    public readonly string $titulo;

    public readonly ?string $descricao;

    public readonly ?string $referencia;

    public readonly float $preco;

    /** Preço sem desconto, exibido riscado quando há promoção. */
    public readonly float $precoDe;

    public readonly float $economia;

    /** produto_preco_venda: o "precoOriginal" que o carrinho sempre recebeu. */
    public readonly float $precoVenda;

    public readonly bool $relampago;

    public readonly bool $permiteSabores;

    public readonly int $maxSabores;

    public readonly ?int $categoriaId;

    public readonly ?string $categoriaNome;

    /** Texto normalizado (sem acento, minúsculo) para a busca do cardápio. */
    public readonly string $busca;

    public function __construct(private readonly Produto $produto)
    {
        $preco = $produto->precoResolvido();

        $this->id = $produto->id;
        $this->nome = $produto->nomeExibicao();
        $this->titulo = $produto->produto_descricao;
        $this->descricao = filled($produto->produto_codimentacao) ? trim($produto->produto_codimentacao) : null;
        $this->referencia = filled($produto->produto_referencia ?? null) ? (string) $produto->produto_referencia : null;
        $this->preco = $preco->precoFinal();
        $this->precoDe = (float) $preco->valorUnitario;
        $this->economia = (float) $preco->descontoUnitario;
        $this->precoVenda = (float) $produto->produto_preco_venda;
        $this->relampago = $preco->temPromocaoRelampago();
        $this->permiteSabores = $produto->permiteSaboresCardapio();
        $this->maxSabores = $produto->maxSaboresCardapio();
        $this->categoriaId = $produto->categoria?->id;
        $this->categoriaNome = $produto->categoria?->categoria_nome;
        $this->busca = Str::lower(Str::ascii($this->nome.' '.$this->descricao));
    }

    /**
     * Um apresentador por produto, mesmo que o produto apareça em várias
     * seções (mais pedidos, promoções e a própria categoria).
     *
     * @param  iterable<Produto>  $produtos
     * @return Collection<int, self>
     */
    public static function mapa(iterable $produtos): Collection
    {
        return collect($produtos)
            ->filter()
            ->unique('id')
            ->mapWithKeys(fn (Produto $produto) => [$produto->id => new self($produto)]);
    }

    public function temDesconto(): bool
    {
        return $this->economia > 0;
    }

    public function temFoto(): bool
    {
        return $this->produto->temFoto();
    }

    /** Foto para a largura exibida em pixels CSS (miniatura quando existir). */
    public function foto(int $larguraExibida): string
    {
        return $this->produto->getMiniaturaUrl($larguraExibida);
    }

    /**
     * Dados do sheet de detalhe. Só exibição: o carrinho segue chamando
     * add()/abrirSabores() e o servidor reprecifica tudo no checkout.
     *
     * @return array<string, mixed>
     */
    public function paraTela(): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'descricao' => $this->descricao,
            'referencia' => $this->referencia,
            'foto' => $this->temFoto() ? $this->foto(320) : null,
            'preco' => $this->preco,
            'precoDe' => $this->precoDe,
            'precoVenda' => $this->precoVenda,
            'permiteSabores' => $this->permiteSabores,
            'maxSabores' => $this->maxSabores,
            'categoriaId' => $this->categoriaId,
            'categoriaNome' => $this->categoriaNome,
        ];
    }
}
