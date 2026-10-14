{{-- Chip de status de produção (item ou pedido). $cor: gray|info|warning|success|danger. --}}
@props(['rotulo', 'cor' => 'gray'])

<span {{ $attributes->class([
    'inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase leading-none',
    'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => $cor === 'gray',
    'bg-info-100 text-info-700 dark:bg-info-500/15 dark:text-info-400' => $cor === 'info',
    'bg-warning-100 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400' => $cor === 'warning',
    'bg-success-100 text-success-700 dark:bg-success-500/15 dark:text-success-400' => $cor === 'success',
    'bg-danger-100 text-danger-700 dark:bg-danger-500/15 dark:text-danger-400' => $cor === 'danger',
]) }}>{{ $rotulo }}</span>
