@props(['produto', 'grande' => false])

{{-- Preço que o servidor cobra (precoResolvido); promoção mostra o anterior riscado e a economia real. --}}
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-baseline gap-x-2 gap-y-0.5']) }}>
    <span @class([
        'num font-bold',
        'text-lg' => $grande,
        'text-base' => ! $grande,
        'text-ouro' => $produto->temDesconto(),
        'text-creme' => ! $produto->temDesconto(),
    ])>R$ {{ number_format($produto->preco, 2, ',', '.') }}</span>
    @if ($produto->temDesconto())
        <span class="num preco-de">R$ {{ number_format($produto->precoDe, 2, ',', '.') }}</span>
        <span class="num preco-economia">−R$ {{ number_format($produto->economia, 2, ',', '.') }}</span>
    @endif
</div>
