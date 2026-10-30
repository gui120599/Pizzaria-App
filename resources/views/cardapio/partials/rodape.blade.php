{{-- Rodapé: horários reais da semana e links de avaliação (antes um botão flutuante que cobria a sacola). --}}
@php
    $dias = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
    $semana = collect($horarios)->groupBy('dia');
@endphp

<footer data-ocultar-na-busca class="mx-4 mt-12 grid gap-4 border-t border-linha pt-8 lg:mx-0 lg:grid-cols-2">
    @if ($semana->isNotEmpty())
        <div class="rounded-card border border-linha bg-forno-2 p-4">
            <h2 class="font-display text-xl font-bold">Horários</h2>
            <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-6 gap-y-1.5 text-sm">
                @foreach ($dias as $numero => $dia)
                    <dt @class(['font-semibold', 'text-brasa-viva' => $numero === now()->dayOfWeek, 'text-farinha' => $numero !== now()->dayOfWeek])>{{ $dia }}</dt>
                    <dd class="num text-right">
                        {{ $semana->has($numero) ? $semana[$numero]->sortBy('abertura')->map(fn ($h) => $h['abertura'].'–'.$h['fechamento'])->implode(' · ') : 'Fechado' }}
                    </dd>
                @endforeach
            </dl>
        </div>
    @endif

    @if (! empty($avaliacaoLinks))
        <div class="rounded-card border border-linha bg-forno-2 p-4">
            <h2 class="font-display text-xl font-bold">Gostou? Avalie a gente</h2>
            <p class="mt-1 text-sm text-farinha">Sua avaliação ajuda outras pessoas a conhecerem o Empório.</p>
            <div class="mt-4 flex flex-wrap gap-3">
                @foreach ($avaliacaoLinks as $link)
                    <a href="{{ $link['avaliacao_link_url'] }}" target="_blank" rel="noopener noreferrer"
                       class="flex items-center gap-2 rounded-xl border border-linha bg-forno px-3 py-2 text-sm font-semibold hover:border-farinha/50">
                        <img src="{{ $link['avaliacao_link_logo_url'] }}" alt="" width="24" height="24" loading="lazy" class="size-6 rounded-full bg-white object-contain p-0.5">
                        {{ $link['avaliacao_link_nome'] }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <p class="pb-2 text-center text-xs text-farinha/70 lg:col-span-2">Empório da Pizza · Pizzaria &amp; Pastelaria</p>
</footer>
