{{--
    Plantão do dia dos garçons freelancers: o gerente marca quem está
    trabalhando. A marcação vale até a virada do dia operacional.
--}}
<x-filament-panels::page>
    <div class="mx-auto w-full max-w-2xl space-y-3">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Marque quem está trabalhando hoje: essas pessoas passam a receber os avisos do salão
            (por exemplo, &quot;quer abrir a mesa&quot;) sem bater ponto. A marcação vale até a virada do dia, às
            {{ $this->viradaDoDia() }}, e depois some sozinha.
        </p>

        @forelse ($this->freelancers as $freelancer)
            <div wire:key="freelancer-{{ $freelancer['id'] }}"
                 @class([
                     'flex items-center justify-between gap-3 rounded-2xl p-4 ring-1',
                     'bg-emerald-50 ring-emerald-200 dark:bg-emerald-500/10 dark:ring-emerald-500/30' => $freelancer['trabalhando'],
                     'bg-white ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10' => ! $freelancer['trabalhando'],
                 ])>
                <div class="min-w-0">
                    <p class="truncate text-base font-semibold text-gray-950 dark:text-white">{{ $freelancer['nome'] }}</p>
                    @if ($freelancer['trabalhando'] && ! $freelancer['recebe_notificacoes'])
                        <p class="text-xs font-medium text-amber-700 dark:text-amber-300">Sem notificações ativas no momento: peça para entrar no painel e ativar.</p>
                    @elseif ($freelancer['trabalhando'])
                        <p class="text-xs font-medium text-emerald-700 dark:text-emerald-300">No plantão de hoje</p>
                    @else
                        <p class="text-xs text-gray-500 dark:text-gray-400">Fora do plantão</p>
                    @endif
                </div>
                <button type="button" wire:click="alternar({{ $freelancer['id'] }})" wire:loading.attr="disabled"
                        @class([
                            'shrink-0 rounded-xl px-5 py-3 text-sm font-semibold active:scale-95',
                            'bg-primary-600 text-white' => ! $freelancer['trabalhando'],
                            'bg-white text-gray-800 ring-1 ring-gray-300 dark:bg-white/10 dark:text-gray-100 dark:ring-white/20' => $freelancer['trabalhando'],
                        ])>
                    {{ $freelancer['trabalhando'] ? 'Tirar do plantão' : 'Trabalhando hoje' }}
                </button>
            </div>
        @empty
            <div class="rounded-2xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-white/10">
                Nenhum garçom freelancer cadastrado. No painel administrativo, em Usuários, ligue
                &quot;Garçom freelancer (sem ponto)&quot; no cadastro da pessoa.
            </div>
        @endforelse
    </div>
</x-filament-panels::page>
