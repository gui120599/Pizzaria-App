{{--
    Apresentação curta (só delivery): nome, subtítulo do próprio logo e chips
    gerados dos dados reais — entrega/retirada cadastradas e o horário de hoje.
--}}
@php
    $entrega = $opcoesEntregas->first(fn ($o) => $o['requer_endereco']);
    $temRetirada = $opcoesEntregas->contains(fn ($o) => ! $o['requer_endereco']);
    $turnosHoje = collect($horarios)
        ->where('dia', now()->dayOfWeek)
        ->sortBy('abertura')
        ->map(fn ($h) => $h['abertura'].'–'.$h['fechamento'])
        ->implode(' · ');
    $moeda = fn (float $valor) => 'R$ '.number_format($valor, 2, ',', '.');
@endphp

<section class="bg-[radial-gradient(900px_260px_at_15%_0%,rgb(194_65_12/.22),transparent_70%)]">
    <div class="mx-auto w-full max-w-[1232px] px-4 pb-4 pt-4 lg:flex lg:items-end lg:gap-6 lg:px-6 lg:pb-6 lg:pt-6">
        <div>
            <h1 class="font-display text-[28px] font-bold leading-none tracking-tight lg:text-[40px]">Empório da Pizza</h1>
            <p class="mt-1.5 text-sm text-farinha lg:text-[15px]">Pizzaria &amp; Pastelaria · desde 2021</p>
        </div>

        <div class="rolagem-x -mx-4 mt-3.5 flex gap-2 px-4 lg:mx-0 lg:ml-auto lg:mt-0 lg:px-0">
            @if ($entrega)
                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-linha bg-forno-2 px-3 py-2 text-[13px] font-medium">
                    <i class='bx bx-cycling text-lg text-brasa-viva' aria-hidden="true"></i>
                    @if ($entrega['valor_frete'] > 0)
                        Entrega {{ $moeda($entrega['valor_frete']) }}
                        @if ($entrega['min_frete'] > 0)
                            · <span class="text-manjericao">grátis acima de {{ $moeda($entrega['min_frete']) }}</span>
                        @endif
                    @else
                        <span class="text-manjericao">Entrega grátis</span>
                    @endif
                </span>
            @endif
            @if ($temRetirada)
                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-linha bg-forno-2 px-3 py-2 text-[13px] font-medium">
                    <i class='bx bx-store-alt text-lg text-brasa-viva' aria-hidden="true"></i> Retirada na loja
                </span>
            @endif
            @if ($turnosHoje !== '')
                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-linha bg-forno-2 px-3 py-2 text-[13px] font-medium">
                    <i class='bx bx-time-five text-lg text-brasa-viva' aria-hidden="true"></i> Hoje {{ $turnosHoje }}
                </span>
            @endif
        </div>

        <p x-show="!$store.cart.estaAbertoAgora()" x-cloak
           class="mt-3 flex items-start gap-2 rounded-xl border border-brasa-viva/30 bg-brasa/10 px-3 py-2.5 text-[13px] leading-snug text-creme lg:hidden">
            <i class='bx bx-time-five mt-px text-lg text-brasa-viva' aria-hidden="true"></i>
            <span>Estamos fechados agora. Você pode ver o cardápio; os pedidos abrem <span x-text="$store.cart.proximoHorarioAgora() ?? 'no próximo horário'"></span>.</span>
        </p>
    </div>
</section>
