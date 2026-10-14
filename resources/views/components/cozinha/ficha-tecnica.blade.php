{{--
    Ficha técnica viva (spec §6.5): abas Ingredientes / Modo de preparo /
    Apresentação e o modo passo a passo. Mesma view na pré-visualização do
    admin e no modal do tablet. Navegação, timer e bip em Alpine, sem ida ao
    servidor.

    @var \App\Support\Cozinha\FichaTecnicaView $ficha
--}}
@props(['ficha', 'grande' => false])

@php
    $grupos = $ficha->ingredientesPorGrupo();
    $passos = collect($ficha->passos)->map(fn ($p) => [
        'titulo' => $p->titulo,
        'descricao' => $p->descricao,
        'foto' => $p->foto,
        'tempo' => $p->tempoSegundos,
        'temperatura' => $p->temperaturaAlvo,
        'critico' => $p->pontoCritico,
    ])->values();
    $temApresentacao = $ficha->fotoApresentacao || $ficha->videoUrl;
@endphp

<div
    x-data="{
        aba: 'ingredientes',
        passoAPasso: false,
        passo: 0,
        passos: @js($passos),
        restante: null,
        timer: null,
        iniciarTimer(segundos) {
            clearInterval(this.timer);
            this.restante = segundos;
            this.timer = setInterval(() => {
                this.restante--;
                if (this.restante <= 0) {
                    clearInterval(this.timer);
                    this.restante = 0;
                    this.bip();
                }
            }, 1000);
        },
        pararTimer() { clearInterval(this.timer); this.restante = null; },
        destroy() { clearInterval(this.timer); },
        irPara(indice) { this.pararTimer(); this.passo = Math.max(0, Math.min(this.passos.length - 1, indice)); },
        formatar(s) { return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); },
        bip() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                [0, 0.35, 0.7].forEach((t) => {
                    const osc = ctx.createOscillator();
                    osc.frequency.value = 880;
                    osc.connect(ctx.destination);
                    osc.start(ctx.currentTime + t);
                    osc.stop(ctx.currentTime + t + 0.2);
                });
            } catch (e) {}
        },
    }"
    @class(['space-y-4 text-gray-950 dark:text-white', 'text-lg' => $grande])
>
    {{-- Cabeçalho --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="text-xl font-bold">{{ $ficha->titulo }}</div>
            @if ($ficha->subtitulo)
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $ficha->subtitulo }}</div>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2 text-xs">
            @if ($ficha->tempoPreparoMin)
                <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-1 font-semibold dark:bg-white/10">
                    <x-heroicon-m-clock class="h-3.5 w-3.5" /> {{ $ficha->tempoPreparoMin }} min
                </span>
            @endif
            @foreach ($ficha->alergenos as $alergeno)
                <span class="rounded-full bg-red-100 px-2 py-1 font-semibold text-red-700 dark:bg-red-500/15 dark:text-red-400">{{ $alergeno }}</span>
            @endforeach
        </div>
    </div>

    @if (! $ficha->temFicha)
        <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-500 dark:bg-white/5 dark:text-gray-400">
            Ficha técnica não cadastrada para este produto.
        </div>
    @endif

    @if ($ficha->observacao || $ficha->remocoesSemLinha !== [] || $ficha->respostas !== [])
        <div class="space-y-1 rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
            @foreach ($ficha->respostas as $resposta)
                <div>{{ $resposta }}</div>
            @endforeach
            @foreach ($ficha->remocoesSemLinha as $remocao)
                <div class="uppercase">Sem {{ $remocao }}</div>
            @endforeach
            @if ($ficha->observacao)
                <div>OBS: {{ $ficha->observacao }}</div>
            @endif
        </div>
    @endif

    @if ($ficha->pontosCriticos)
        <div class="flex gap-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-300">
            <x-heroicon-m-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" />
            <span class="whitespace-pre-line">{{ $ficha->pontosCriticos }}</span>
        </div>
    @endif

    {{-- Abas --}}
    <div x-show="! passoAPasso" class="flex flex-wrap items-center gap-1 border-b border-gray-200 dark:border-white/10">
        @foreach (['ingredientes' => 'Ingredientes', 'preparo' => 'Modo de preparo', 'apresentacao' => 'Apresentação'] as $chave => $rotulo)
            @if ($chave !== 'apresentacao' || $temApresentacao)
                <button
                    type="button"
                    x-on:click="aba = '{{ $chave }}'"
                    x-bind:class="aba === '{{ $chave }}' ? 'border-primary-600 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 dark:text-gray-400'"
                    class="-mb-px border-b-2 px-3 py-2 text-sm font-semibold"
                >
                    {{ $rotulo }}
                </button>
            @endif
        @endforeach

        @if ($passos->isNotEmpty())
            <button
                type="button"
                x-on:click="passoAPasso = true; irPara(0)"
                class="ml-auto mb-1 inline-flex items-center gap-1 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-500"
            >
                <x-heroicon-m-play class="h-4 w-4" /> Passo a passo
            </button>
        @endif
    </div>

    {{-- Ingredientes --}}
    <div x-show="! passoAPasso && aba === 'ingredientes'" class="space-y-3">
        @forelse ($grupos as $grupo => $ingredientes)
            <div>
                <div class="mb-1 text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $grupo }}</div>
                <ul class="divide-y divide-gray-100 rounded-lg ring-1 ring-gray-200 dark:divide-white/5 dark:ring-white/10">
                    @foreach ($ingredientes as $ingrediente)
                        <li @class(['flex items-center gap-3 px-3 py-2', 'bg-red-50 dark:bg-red-500/10' => $ingrediente->removido])>
                            @if ($ingrediente->fotoPorcao)
                                <img src="{{ $ingrediente->fotoPorcao }}" alt="" class="h-12 w-12 shrink-0 rounded-md object-cover" loading="lazy">
                            @endif
                            <div class="min-w-0 flex-1">
                                <div @class(['font-semibold', 'line-through text-gray-400' => $ingrediente->removido])>
                                    {{ $ingrediente->nome }}
                                    @if ($ingrediente->subfichaProdutoId)
                                        <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-600 dark:bg-white/10 dark:text-gray-300">subficha</span>
                                    @endif
                                </div>
                                @if ($ingrediente->observacao)
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $ingrediente->observacao }}</div>
                                @endif
                            </div>
                            @if ($ingrediente->removido)
                                <span class="shrink-0 rounded bg-red-600 px-2 py-0.5 text-xs font-bold text-white">SEM</span>
                            @else
                                <span class="shrink-0 font-bold tabular-nums">{{ $ingrediente->quantidadeFormatada }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <div class="text-sm text-gray-500 dark:text-gray-400">Nenhum ingrediente cadastrado.</div>
        @endforelse

        @if ($ficha->adicionais !== [])
            <div>
                <div class="mb-1 text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Adicionais</div>
                <ul class="divide-y divide-gray-100 rounded-lg ring-1 ring-primary-200 dark:divide-white/5 dark:ring-primary-500/30">
                    @foreach ($ficha->adicionais as $adicional)
                        <li class="flex items-center gap-3 px-3 py-2">
                            <div class="min-w-0 flex-1">
                                <div class="font-semibold text-primary-700 dark:text-primary-400">{{ $adicional->nome }}</div>
                                @if ($adicional->observacao)
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $adicional->observacao }}</div>
                                @endif
                            </div>
                            <span class="shrink-0 font-bold tabular-nums">{{ $adicional->quantidadeFormatada }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    {{-- Modo de preparo --}}
    <div x-show="! passoAPasso && aba === 'preparo'" x-cloak>
        @if ($passos->isEmpty())
            <div class="text-sm text-gray-500 dark:text-gray-400">Nenhum passo de preparo para este setor.</div>
        @else
            <ol class="space-y-2">
                @foreach ($ficha->passos as $indice => $passo)
                    <li @class([
                        'flex gap-3 rounded-lg px-3 py-2 ring-1',
                        'ring-gray-200 dark:ring-white/10' => ! $passo->pontoCritico,
                        'bg-red-50 ring-red-300 dark:bg-red-500/10 dark:ring-red-500/40' => $passo->pontoCritico,
                    ])>
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-900 text-sm font-bold text-white dark:bg-white dark:text-gray-900">{{ $indice + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold">
                                {{ $passo->titulo }}
                                @if ($passo->pontoCritico)
                                    <span class="ml-1 text-xs font-bold uppercase text-red-600 dark:text-red-400">ponto crítico</span>
                                @endif
                            </div>
                            @if ($passo->descricao)
                                <div class="whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ $passo->descricao }}</div>
                            @endif
                            <div class="mt-1 flex flex-wrap gap-2 text-xs text-gray-500 dark:text-gray-400">
                                @if ($passo->tempoSegundos)
                                    <span class="inline-flex items-center gap-1"><x-heroicon-m-clock class="h-3.5 w-3.5" /> {{ gmdate($passo->tempoSegundos >= 3600 ? 'H:i:s' : 'i:s', $passo->tempoSegundos) }}</span>
                                @endif
                                @if ($passo->temperaturaAlvo)
                                    <span class="inline-flex items-center gap-1"><x-heroicon-m-fire class="h-3.5 w-3.5" /> {{ $passo->temperaturaAlvo }}</span>
                                @endif
                            </div>
                        </div>
                        @if ($passo->foto)
                            <img src="{{ $passo->foto }}" alt="" class="h-16 w-16 shrink-0 rounded-md object-cover" loading="lazy">
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </div>

    {{-- Apresentação --}}
    @if ($temApresentacao)
        <div x-show="! passoAPasso && aba === 'apresentacao'" x-cloak class="space-y-3">
            @if ($ficha->fotoApresentacao)
                <img src="{{ $ficha->fotoApresentacao }}" alt="Apresentação" class="max-h-96 w-full rounded-lg object-contain">
            @endif
            @if ($ficha->videoUrl)
                <a href="{{ $ficha->videoUrl }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-sm font-semibold text-primary-600 dark:text-primary-400">
                    <x-heroicon-m-play-circle class="h-5 w-5" /> Ver vídeo do preparo
                </a>
            @endif
        </div>
    @endif

    {{-- Passo a passo: um passo por tela --}}
    @if ($passos->isNotEmpty())
        <div x-show="passoAPasso" x-cloak class="space-y-4">
            <div class="flex items-center justify-between text-sm font-semibold text-gray-500 dark:text-gray-400">
                <span x-text="'Passo ' + (passo + 1) + ' de ' + passos.length"></span>
                <button type="button" x-on:click="passoAPasso = false; pararTimer()" class="inline-flex items-center gap-1 hover:text-gray-900 dark:hover:text-white">
                    <x-heroicon-m-x-mark class="h-4 w-4" /> Sair do passo a passo
                </button>
            </div>

            <div
                class="rounded-xl px-5 py-6 ring-2"
                x-bind:class="passos[passo].critico ? 'bg-red-50 ring-red-400 dark:bg-red-500/10' : 'ring-gray-200 dark:ring-white/10'"
            >
                <div x-show="passos[passo].critico" class="mb-2 flex items-center gap-1 text-sm font-bold uppercase text-red-600 dark:text-red-400"><x-heroicon-m-exclamation-triangle class="h-4 w-4" /> Ponto crítico</div>
                <div class="text-3xl font-bold leading-tight" x-text="passos[passo].titulo"></div>
                <div class="mt-3 whitespace-pre-line text-xl text-gray-700 dark:text-gray-200" x-text="passos[passo].descricao"></div>
                <div x-show="passos[passo].temperatura" class="mt-3 flex items-center gap-1.5 text-lg font-semibold"><x-heroicon-m-fire class="h-5 w-5" /> <span x-text="passos[passo].temperatura"></span></div>
                <template x-if="passos[passo].foto">
                    <img x-bind:src="passos[passo].foto" alt="" class="mt-4 max-h-72 rounded-lg object-contain">
                </template>

                <div x-show="passos[passo].tempo" class="mt-5 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        x-show="restante === null"
                        x-on:click="iniciarTimer(passos[passo].tempo)"
                        class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-3 text-lg font-bold text-white dark:bg-white dark:text-gray-900"
                    >
                        <x-heroicon-m-clock class="h-6 w-6" /> <span x-text="'Iniciar timer ' + formatar(passos[passo].tempo)"></span>
                    </button>
                    <div
                        x-show="restante !== null"
                        class="rounded-lg px-4 py-3 text-4xl font-bold tabular-nums"
                        x-bind:class="restante === 0 ? 'bg-red-600 text-white animate-pulse' : 'bg-gray-100 dark:bg-white/10'"
                        x-text="restante === 0 ? 'Tempo!' : formatar(restante)"
                    ></div>
                    <button type="button" x-show="restante !== null" x-on:click="pararTimer()" class="text-sm font-semibold text-gray-500 dark:text-gray-400">Parar</button>
                </div>
            </div>

            <div class="flex gap-3">
                <button
                    type="button"
                    x-on:click="irPara(passo - 1)"
                    x-bind:disabled="passo === 0"
                    class="flex h-16 flex-1 items-center justify-center rounded-xl text-lg font-bold ring-1 ring-gray-300 disabled:opacity-40 dark:ring-white/20"
                >
                    <x-heroicon-m-arrow-left class="h-6 w-6" /> Anterior
                </button>
                <button
                    type="button"
                    x-on:click="passo === passos.length - 1 ? (passoAPasso = false, pararTimer()) : irPara(passo + 1)"
                    class="flex h-16 flex-1 items-center justify-center rounded-xl bg-primary-600 text-lg font-bold text-white"
                >
                    <span x-text="passo === passos.length - 1 ? 'Concluir' : 'Próximo'"></span> <x-heroicon-m-arrow-right class="h-6 w-6" />
                </button>
            </div>
        </div>
    @endif
</div>
