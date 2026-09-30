{{--
    Painel de Pedidos — Kanban operacional.

    Um único wire:poll para o board inteiro (a tela legada tinha cinco $.ajax
    desencontrados, e só a primeira coluna se atualizava sozinha). O método
    atualizar() compara uma assinatura e chama skipRender() quando nada mudou,
    então o ciclo normal não gera DOM diff nem as queries de card.
--}}
<x-filament-panels::page>
    <div
        wire:poll.10s="atualizar"
        x-data="painelPedidos()"
        @novo-pedido.window="alertar()"
    >
        {{-- Barra superior: navegação entre colunas, som e pedidos fora do turno --}}
        <div class="mb-3 flex flex-wrap items-center gap-2">
            {{-- Chips que rolam até a coluna: navegação rápida no tablet sem
                 precisar de tabs (que exigiriam condicionar a árvore inteira). --}}
            <div class="flex flex-1 flex-wrap items-center gap-1.5 xl:hidden">
                @foreach ($this->colunasKanban as $coluna)
                    <button
                        type="button"
                        @click="irPara('coluna-{{ Str::slug($coluna->value) }}')"
                        class="rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10"
                    >
                        {{ $coluna->label() }}
                    </button>
                @endforeach
            </div>

            @if ($this->totalForaDoTurno > 0)
                <button
                    type="button"
                    wire:click="alternarForaDoTurno"
                    @class([
                        'flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                        'bg-amber-100 text-amber-800 hover:bg-amber-200 dark:bg-amber-500/15 dark:text-amber-400' => ! $mostrarForaDoTurno,
                        'bg-amber-600 text-white hover:bg-amber-500' => $mostrarForaDoTurno,
                    ])
                >
                    <x-heroicon-m-exclamation-triangle class="h-4 w-4" />
                    {{ $this->totalForaDoTurno }}
                    {{ Str::plural('pedido', $this->totalForaDoTurno) }}
                    de turnos anteriores
                    <span class="font-normal opacity-75">
                        ({{ $mostrarForaDoTurno ? 'ocultar' : 'mostrar' }})
                    </span>
                </button>
            @endif

            {{-- Navegador bloqueia áudio sem um gesto do usuário: este botão é o
                 gesto que destrava o alerta pelo resto da sessão. --}}
            <button
                type="button"
                @click="alternarSom()"
                x-text="somAtivo ? '🔔 Som ligado' : '🔕 Ativar som'"
                class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10"
            ></button>
        </div>

        @if ($mostrarForaDoTurno)
            <div class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/30">
                Mostrando também pedidos abertos antes do turno atual. Eles ficaram presos em alguma etapa — vale conferir se devem ser concluídos ou cancelados.
            </div>
        @endif

        {{-- Faixa de colunas: rola na horizontal com snap até xl, onde vira grid.
             O número de colunas é dinâmico (a coluna Em Transporte sai quando a
             config desliga o estágio), então grid-template-columns vem de uma
             custom property — Tailwind não gera classe a partir de valor
             calculado em runtime. --}}
        <div
            class="flex snap-x snap-mandatory gap-2 overflow-x-auto overscroll-x-contain pb-2 xl:grid xl:snap-none xl:overflow-visible xl:[grid-template-columns:repeat(var(--painel-colunas),minmax(0,1fr))]"
            style="--painel-colunas: {{ count($this->colunasKanban) }}"
        >
            @foreach ($this->colunasKanban as $coluna)
                @include('filament.pages.painel-pedidos.coluna', [
                    'status' => $coluna,
                    'pedidos' => $coluna === \App\Enums\StatusPedidoEnum::ENTREGUE
                        ? $this->entregues
                        : ($this->colunas[$coluna->value] ?? collect()),
                    'usaTransporte' => $this->usaEstagioTransporte(),
                ])
            @endforeach
        </div>
    </div>

    <x-filament-actions::modals />

    @script
        <script>
            Alpine.data('painelPedidos', () => ({
                somAtivo: false,

                init() {
                    try {
                        this.somAtivo = localStorage.getItem('painel-pedidos-som') === '1';
                    } catch (_) {
                        // Aba privada / storage bloqueado: segue sem preferência.
                    }
                },

                alternarSom() {
                    this.somAtivo = ! this.somAtivo;

                    try {
                        localStorage.setItem('painel-pedidos-som', this.somAtivo ? '1' : '0');
                    } catch (_) {}

                    // Toca uma vez ao ligar: além de confirmar o volume, é o
                    // gesto do usuário que destrava o AudioContext.
                    if (this.somAtivo) {
                        this.alertar();
                    }
                },

                irPara(id) {
                    document.getElementById(id)?.scrollIntoView({
                        behavior: 'smooth',
                        inline: 'start',
                        block: 'nearest',
                    });
                },

                // Mesmo beep sintetizado de ConfirmacoesPedidos — sem asset de
                // áudio para carregar.
                alertar() {
                    if (! this.somAtivo) {
                        return;
                    }

                    try {
                        const ctx = new (window.AudioContext || window.webkitAudioContext)();
                        ctx.resume?.();

                        [0, 150, 300].forEach((atraso, i) => {
                            const osc = ctx.createOscillator();
                            const gain = ctx.createGain();
                            osc.connect(gain);
                            gain.connect(ctx.destination);
                            osc.frequency.value = i === 1 ? 1100 : 880;
                            gain.gain.setValueAtTime(0.3, ctx.currentTime + atraso / 1000);
                            gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + atraso / 1000 + 0.2);
                            osc.start(ctx.currentTime + atraso / 1000);
                            osc.stop(ctx.currentTime + atraso / 1000 + 0.2);
                        });
                    } catch (_) {
                        // Autoplay bloqueado: o card já mostra a fila, o som é extra.
                    }
                },
            }));
        </script>
    @endscript
</x-filament-panels::page>
