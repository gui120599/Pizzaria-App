{{-- Operador ativo no topo: no tablet da estação, toque para trocar; com login próprio, só o nome. --}}
@if ($this->precisaEscolherOperador)
    {{ $this->trocarOperadorAction }}
@elseif ($this->operador)
    <span class="inline-flex items-center gap-1.5 rounded-lg bg-gray-800 px-3 py-2 text-sm font-semibold text-gray-300 ring-1 ring-white/10">
        <x-filament::icon icon="heroicon-o-user-circle" class="h-5 w-5" />
        {{ $this->operador->nome }}
    </span>
@endif
