<x-filament-widgets::widget>
    @php $debito = $this->debito(); @endphp

    @if ($debito)
        <div class="overflow-hidden rounded-xl shadow-sm ring-1 ring-danger-600/20 dark:ring-danger-400/30">
            <div class="bg-danger-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
                Débitos em aberto
            </div>
            <x-debitos-cliente :debito="$debito" />
        </div>
    @endif
</x-filament-widgets::widget>
