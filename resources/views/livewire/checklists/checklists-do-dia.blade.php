{{-- Lista dos checklists do dia, compartilhada pelos painéis garçom e cozinha. --}}
@php
    use App\Enums\Checklist\ExecucaoStatus;
    use App\Services\Checklist\ChecklistsDoDiaService;

    $checklists = $this->checklists;
@endphp

<div class="w-full space-y-6">
    <section class="space-y-3">
        @forelse ($checklists['hoje'] as $cartao)
            @php
                $progresso = $cartao['total'] > 0 ? (int) floor($cartao['respondidos'] / $cartao['total'] * 100) : 0;
                $acao = match (true) {
                    $cartao['execucao_id'] === null => 'Iniciar',
                    $cartao['status'] === ExecucaoStatus::Pendente => 'Iniciar',
                    $cartao['status'] === ExecucaoStatus::Reprovado => 'Corrigir',
                    $cartao['status'] === ExecucaoStatus::EmAndamento => 'Continuar',
                    default => 'Ver',
                };
            @endphp
            <article wire:key="{{ $cartao['chave'] }}"
                     @class([
                         'rounded-2xl bg-white p-4 shadow-sm ring-2 dark:bg-gray-900',
                         'ring-sky-500' => $cartao['situacao'] === ChecklistsDoDiaService::EM_ANDAMENTO,
                         'ring-amber-400' => $cartao['situacao'] === ChecklistsDoDiaService::NA_JANELA,
                         'ring-gray-200 dark:ring-white/10' => in_array($cartao['situacao'], [ChecklistsDoDiaService::FUTURO, ChecklistsDoDiaService::ENCERRADO], true),
                     ])>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-lg font-bold leading-tight text-gray-950 dark:text-white">{{ $cartao['nome'] }}</h2>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                            {{ $cartao['tipo']->getLabel() }} · {{ $cartao['setor']->getLabel() }}
                            @if ($cartao['janela']) · {{ $cartao['janela'] }} @endif
                        </p>
                    </div>
                    <x-filament::badge :color="$cartao['status']->getColor()">{{ $cartao['status']->getLabel() }}</x-filament::badge>
                </div>

                <div class="mt-3 flex items-center gap-3">
                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                        <div class="h-full rounded-full bg-primary-500" style="width: {{ $progresso }}%"></div>
                    </div>
                    <span class="text-sm tabular-nums text-gray-600 dark:text-gray-300">{{ $cartao['respondidos'] }}/{{ $cartao['total'] }}</span>
                </div>

                <div class="mt-4">
                    @if ($cartao['execucao_id'] === null)
                        <button type="button" wire:click="iniciar({{ $cartao['modelo_id'] }})" wire:loading.attr="disabled"
                                class="w-full rounded-xl bg-primary-600 py-3 text-base font-semibold text-white active:scale-[.98] disabled:opacity-60">
                            {{ $acao }}
                        </button>
                    @else
                        <a href="{{ $this->urlExecucao($cartao['execucao_id']) }}" wire:navigate
                           @class([
                               'block w-full rounded-xl py-3 text-center text-base font-semibold active:scale-[.98]',
                               'bg-primary-600 text-white' => $acao !== 'Ver',
                               'bg-gray-100 text-gray-800 dark:bg-white/10 dark:text-gray-100' => $acao === 'Ver',
                           ])>
                            {{ $acao }}
                        </a>
                    @endif
                </div>
            </article>
        @empty
            <div class="rounded-2xl bg-white p-6 text-center text-gray-500 shadow-sm dark:bg-gray-900 dark:text-gray-400">
                Nenhum checklist previsto para você hoje.
            </div>
        @endforelse
    </section>

    @if ($checklists['outros'] !== [])
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Outros checklists</h2>
            @foreach ($checklists['outros'] as $modelo)
                <button type="button" wire:key="outro-{{ $modelo->id }}" wire:click="iniciar({{ $modelo->id }})" wire:loading.attr="disabled"
                        class="flex w-full items-center justify-between gap-3 rounded-2xl bg-white p-4 text-left shadow-sm ring-1 ring-gray-200 active:scale-[.98] dark:bg-gray-900 dark:ring-white/10">
                    <span class="min-w-0">
                        <span class="block truncate text-base font-semibold text-gray-950 dark:text-white">{{ $modelo->nome }}</span>
                        <span class="block text-sm text-gray-500 dark:text-gray-400">{{ $modelo->tipo->getLabel() }} · {{ $modelo->setor->getLabel() }}</span>
                    </span>
                    <span class="shrink-0 text-sm font-semibold text-primary-600 dark:text-primary-400">Iniciar</span>
                </button>
            @endforeach
        </section>
    @endif

    @if ($urlHistorico)
        <a href="{{ $urlHistorico }}" wire:navigate
           class="block text-center text-sm font-semibold text-primary-600 dark:text-primary-400">
            Meu histórico de checklists
        </a>
    @endif
</div>
