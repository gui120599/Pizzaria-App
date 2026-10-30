<x-cardapio-layout :titulo="$mesaCliente ? 'Pedir na mesa · Empório da Pizza' : 'Empório da Pizza · Cardápio'">
    {{--
        Cardápio público (delivery) e página da mesa pelo QR ($mesaCliente):
        mesma tela, mesmo carrinho (Alpine.store('cart') em cardapio/script).
        Identidade "Forno aceso" — ver docs/plano/redesign-cardapio-online-emporio-da-pizza.md.
    --}}
    <div x-data="cardapioPagina" x-effect="travarRolagem()" class="min-h-dvh">

        @include('cardapio.partials.cabecalho')

        @unless ($mesaCliente)
            @include('cardapio.partials.apresentacao')
        @endunless

        @include('cardapio.partials.abas')

        <div class="mx-auto w-full max-w-[1232px] lg:grid lg:grid-cols-[minmax(0,1fr)_360px] lg:gap-8 lg:px-6">
            <main class="min-w-0 pb-36 lg:pb-16">
                <div x-show="busca.trim() !== '' && semResultado" x-cloak class="px-4 py-16 text-center lg:px-0">
                    <i class='bx bx-search-alt text-5xl text-linha' aria-hidden="true"></i>
                    <p class="mt-3 font-semibold">Nada encontrado para “<span x-text="busca"></span>”</p>
                    <button type="button" @click="limparBusca()" class="mt-4 text-sm font-semibold text-brasa-viva">Ver o cardápio completo</button>
                </div>

                @include('cardapio.partials.secoes-destaque')

                @foreach ($categorias as $categoria)
                    @include('cardapio.partials.secao-categoria', ['categoria' => $categoria, 'primeira' => $loop->first])
                @endforeach

                @include('cardapio.partials.rodape')
            </main>

            @include('cardapio.partials.sacola-lateral')
        </div>

        @include('cardapio.partials.sacola-barra')
        @include('cardapio.partials.aviso')
        @include('cardapio.partials.sacola')
        @include('cardapio.partials.sheet-detalhe')
        @include('cardapio.partials.sheet-sabores')
        @include('cardapio.partials.sheet-montagem')
        @include('cardapio.partials.sheet-oferta')
        @include('cardapio.partials.sheet-troco')

        @if ($mesaCliente)
            @include('mesa-cliente.modais')
        @endif
    </div>

    @include('cardapio.script')
</x-cardapio-layout>
