@php
    use App\Enums\Ponto\SituacaoDia;
    use App\Services\Ponto\EspelhoPontoService;
@endphp

<x-filament-panels::page>
    @php
        $grade = $this->grade;
        $periodo = $this->periodoAtual;
        $cores = [
            'success' => 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30',
            'warning' => 'bg-amber-50 text-amber-800 ring-amber-300 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',
            'danger' => 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/30',
            'info' => 'bg-sky-50 text-sky-800 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/30',
            'gray' => 'bg-gray-50 text-gray-500 ring-gray-200 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10',
        ];
    @endphp

    <div class="flex flex-wrap items-end gap-4">
        <div class="min-w-72 flex-1">
            <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Período</label>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="periodo">
                    @foreach ($this->opcoesPeriodo as $id => $rotulo)
                        <option value="{{ $id }}">{{ $rotulo }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        <div class="min-w-56">
            <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Setor</label>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="setor">
                    <option value="">Todos</option>
                    @foreach ($this->opcoesSetor as $setor)
                        <option value="{{ $setor }}">{{ $setor }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        @if ($periodo?->estaTravado())
            <x-filament::badge color="warning" icon="heroicon-o-lock-closed">
                Período {{ mb_strtolower($periodo->status->getLabel()) }}: correções bloqueadas
            </x-filament::badge>
        @endif
    </div>

    @if ($periodo === null)
        <div class="rounded-xl bg-white p-10 text-center text-sm text-gray-400 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            Nenhum período cadastrado. Abra um em Ponto e Repasses › Períodos.
        </div>
    @else
        <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <table class="w-full border-separate border-spacing-0 text-xs">
                <thead>
                    <tr class="text-gray-500 dark:text-gray-400">
                        <th class="sticky left-0 z-10 min-w-44 bg-white px-3 py-2 text-left font-medium dark:bg-gray-900">Colaborador</th>
                        @foreach ($grade['dias'] as $dia)
                            <th @class(['min-w-16 px-1 py-2 text-center font-medium', 'text-gray-400 dark:text-gray-500' => $dia->isWeekend()])>
                                <div>{{ $dia->translatedFormat('D') }}</div>
                                <div class="text-gray-950 dark:text-white">{{ $dia->format('d/m') }}</div>
                            </th>
                        @endforeach
                        <th class="min-w-20 px-2 py-2 text-right font-medium">Trabalhado</th>
                        <th class="min-w-16 px-2 py-2 text-right font-medium">Extra</th>
                        <th class="min-w-16 px-2 py-2 text-right font-medium">Noturno</th>
                        <th class="min-w-14 px-2 py-2 text-right font-medium">Faltas</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($grade['linhas'] as $linha)
                        <tr wire:key="espelho-linha-{{ $linha['colaborador']->id }}" class="border-t border-gray-100 dark:border-white/5">
                            <td class="sticky left-0 z-10 border-t border-gray-100 bg-white px-3 py-1.5 dark:border-white/5 dark:bg-gray-900">
                                <div class="font-medium text-gray-950 dark:text-white">{{ $linha['colaborador']->nome }}</div>
                                <div class="text-gray-400">{{ $linha['colaborador']->cargo }}</div>
                            </td>
                            @foreach ($linha['celulas'] as $data => $dia)
                                <td class="border-t border-gray-100 p-0.5 text-center dark:border-white/5">
                                    <button type="button"
                                        wire:click="mountAction('dia', { colaborador: {{ $linha['colaborador']->id }}, dia: '{{ $data }}' })"
                                        @class([
                                            'relative flex h-9 w-full items-center justify-center rounded-md ring-1 transition hover:ring-2',
                                            $cores[$dia?->situacao->getColor() ?? 'gray'],
                                        ])
                                        title="{{ $dia?->situacao->getLabel() ?? 'Sem apuração' }}{{ $dia && $dia->inconsistencias ? ' — '.implode(', ', $dia->rotulosInconsistencias()) : '' }}">
                                        <span class="font-medium">{{ EspelhoPontoService::rotuloCelula($dia) ?: '·' }}</span>
                                        @if ($dia && in_array($dia->situacao, [SituacaoDia::Incompleto, SituacaoDia::Inconsistente], true))
                                            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="absolute right-0.5 top-0.5 h-3 w-3 text-amber-500" />
                                        @endif
                                        @if (isset($linha['corrigidos'][$data]))
                                            <x-filament::icon icon="heroicon-m-pencil" class="absolute bottom-0.5 right-0.5 h-3 w-3 text-sky-500" />
                                        @endif
                                    </button>
                                </td>
                            @endforeach
                            <td class="border-t border-gray-100 px-2 text-right font-medium text-gray-950 dark:border-white/5 dark:text-white">{{ EspelhoPontoService::horas($linha['totais']['trabalhado']) }}</td>
                            <td class="border-t border-gray-100 px-2 text-right dark:border-white/5">{{ EspelhoPontoService::horas($linha['totais']['extra_50'] + $linha['totais']['extra_100']) }}</td>
                            <td class="border-t border-gray-100 px-2 text-right dark:border-white/5">{{ EspelhoPontoService::horas($linha['totais']['noturno']) }}</td>
                            <td @class(['border-t border-gray-100 px-2 text-right dark:border-white/5', 'font-semibold text-red-600' => $linha['totais']['faltas'] > 0])>{{ $linha['totais']['faltas'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($grade['dias']) + 5 }}" class="p-10 text-center text-gray-400">Nenhum colaborador nesta periodicidade.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap gap-3 text-xs text-gray-500 dark:text-gray-400">
            @foreach (SituacaoDia::cases() as $situacao)
                <span class="inline-flex items-center gap-1.5">
                    <span @class(['inline-block h-3 w-3 rounded ring-1', $cores[$situacao->getColor()]])></span>{{ $situacao->getLabel() }}
                </span>
            @endforeach
            <span class="inline-flex items-center gap-1"><x-filament::icon icon="heroicon-m-pencil" class="h-3 w-3 text-sky-500" /> corrigido pelo gestor</span>
            <span class="inline-flex items-center gap-1"><x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-3 w-3 text-amber-500" /> inconsistência</span>
        </div>
    @endif

</x-filament-panels::page>
