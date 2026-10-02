{{-- "Lançando para": pessoas da mesa para separar comandas. Tocar escolhe quem
     recebe os próximos itens; "+ Pessoa" busca, cadastra ou cria nome livre. --}}
@php $pessoas = $this->clientesDaMesa(); @endphp
<div class="space-y-1.5">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Lançando para</p>
    <div class="flex gap-2 overflow-x-auto pb-1">
        <button type="button" wire:click="lancarPara(null)"
                @class([
                    'shrink-0 rounded-full px-4 py-2 text-sm font-semibold ring-1 transition active:scale-95',
                    'bg-gray-900 text-white ring-gray-900 dark:bg-white dark:text-gray-900' => $clientePadraoId === null,
                    'bg-white text-gray-700 ring-gray-300 dark:bg-gray-800 dark:text-gray-200 dark:ring-white/10' => $clientePadraoId !== null,
                ])>
            Mesa (geral)
        </button>
        @foreach ($pessoas as $pessoa)
            <span wire:key="pessoa-{{ $pessoa['id'] }}"
                  @class([
                      'flex shrink-0 items-center rounded-full ring-1 transition',
                      'bg-primary-600 text-white ring-primary-600' => $clientePadraoId === $pessoa['id'],
                      'bg-white text-gray-700 ring-gray-300 dark:bg-gray-800 dark:text-gray-200 dark:ring-white/10' => $clientePadraoId !== $pessoa['id'],
                  ])>
                <button type="button" wire:click="lancarPara({{ $pessoa['id'] }})" class="py-2 pl-4 pr-2 text-sm font-semibold active:scale-95">
                    {{ $pessoa['nome'] }}
                </button>
                {{-- Só sai da mesa quem ainda não tem itens (o service recusa e avisa). --}}
                <button type="button" wire:click="removerPessoa({{ $pessoa['id'] }})" wire:confirm="Tirar {{ $pessoa['nome'] }} da mesa?"
                        class="py-2 pl-1 pr-3 text-sm opacity-60" aria-label="Tirar {{ $pessoa['nome'] }} da mesa">&times;</button>
            </span>
        @endforeach
        <button type="button" wire:click="mountAction('adicionarPessoa')"
                class="shrink-0 rounded-full px-4 py-2 text-sm font-semibold text-primary-600 ring-1 ring-dashed ring-primary-400 active:scale-95 dark:text-primary-400">
            + Pessoa
        </button>
    </div>
</div>
