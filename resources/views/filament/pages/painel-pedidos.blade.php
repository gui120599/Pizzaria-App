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
        {{-- Barra de filtros --}}
        <div class="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-2.5 dark:border-white/10 dark:bg-gray-900">
            <input
                type="search"
                wire:model.live.debounce.400ms="busca"
                placeholder="Buscar pedido, cliente ou celular..."
                class="fi-input min-w-[180px] flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
            />

            <select wire:model.live="filtroRapido" class="fi-select rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                <option value="todos">Todos</option>
                <option value="delivery">Delivery</option>
                <option value="retirada">Retirada</option>
                <option value="mesa">Mesa</option>
                <option value="novos">Novos</option>
                <option value="preparando">Preparando</option>
                <option value="prontos">Prontos</option>
                <option value="em_entrega">Em entrega</option>
                <option value="finalizados">Finalizados</option>
            </select>

            @if ($this->entregadores->isNotEmpty())
                <select wire:model.live="entregadorId" class="fi-select rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                    <option value="">Todos entregadores</option>
                    @foreach ($this->entregadores as $id => $nome)
                        <option value="{{ $id }}">{{ $nome }}</option>
                    @endforeach
                </select>
            @endif

            @if ($this->linhasProducaoOpcoes->isNotEmpty())
                <select wire:model.live="linhaProducaoId" class="fi-select rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                    <option value="">Todas as linhas</option>
                    @foreach ($this->linhasProducaoOpcoes as $id => $nome)
                        <option value="{{ $id }}">{{ $nome }}</option>
                    @endforeach
                </select>
            @endif

            <div class="flex items-center gap-1">
                <input type="date" wire:model.live="periodoDe" class="fi-input w-32 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                <span class="text-xs text-gray-400">até</span>
                <input type="date" wire:model.live="periodoAte" class="fi-input w-32 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
            </div>

            <button
                type="button"
                wire:click="$toggle('somenteAtrasados')"
                @class([
                    'shrink-0 rounded-lg border px-3 py-2 text-xs font-semibold',
                    'border-danger-300 bg-danger-50 text-danger-700 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-400' => $somenteAtrasados,
                    'border-gray-300 text-gray-600 dark:border-gray-600 dark:text-gray-300' => ! $somenteAtrasados,
                ])
            >
                ⚠ Só atrasados
            </button>

            <button
                type="button"
                wire:click="$toggle('mostrarCancelados')"
                @class([
                    'shrink-0 rounded-lg border px-3 py-2 text-xs font-semibold',
                    'border-gray-400 bg-gray-100 text-gray-700 dark:border-gray-500 dark:bg-white/10 dark:text-gray-200' => $mostrarCancelados,
                    'border-gray-300 text-gray-600 dark:border-gray-600 dark:text-gray-300' => ! $mostrarCancelados,
                ])
            >
                {{ $mostrarCancelados ? 'Ocultar cancelados' : 'Ver cancelados' }}
            </button>

            {{-- Não dá pra mostrar isto via servidor: o poll usa skipRender()
                 quando nada mudou, então um label renderizado no request nunca
                 avançaria sozinho. Alpine escuta o hook de commit do Livewire e
                 atualiza a cada resposta do poll, mudança ou não. --}}
            <span class="ml-auto shrink-0 text-xs text-gray-400" x-text="`🔄 Atualizado às ${ultimaAtualizacao}`"></span>
        </div>

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

        {{-- Cancelados do turno --}}
        @if ($mostrarCancelados)
            <div class="mt-3 rounded-xl border border-danger-200 bg-danger-50/50 p-3 dark:border-danger-500/20 dark:bg-danger-500/5">
                <div class="mb-3 flex items-center justify-between px-1">
                    <span class="text-sm font-bold text-danger-700 dark:text-danger-400">Cancelados neste turno</span>
                    <span class="rounded-full bg-danger-100 px-2 py-0.5 text-xs font-semibold text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
                        {{ $this->cancelados->count() }}
                    </span>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @forelse ($this->cancelados as $pedido)
                        @include('filament.pages.painel-pedidos.cancelado-card', ['pedido' => $pedido])
                    @empty
                        <p class="px-1 text-xs text-gray-400">Nenhum pedido cancelado neste turno.</p>
                    @endforelse
                </div>
            </div>
        @endif
    </div>

    {{-- NÃO chamar <x-filament-actions::modals /> aqui: o layout de
         <x-filament-panels::page> (vendor/filament/filament/.../page/index.blade.php)
         já inclui isso automaticamente logo após o slot. Uma segunda chamada
         aqui colide com o guard $this->hasActionsModalRendered — só uma das
         duas cópias recebe o conteúdo real, e ficou sendo a de baixo (vazia),
         quebrando o conteúdo de QUALQUER modal desta página (verDetalhes,
         linkEntrega, os modais de motivo em cancelar/rejeitar etc.). --}}

    @script
        <script>
            Alpine.data('painelPedidos', () => ({
                somAtivo: false,
                ultimaAtualizacao: new Date().toLocaleTimeString('pt-BR').slice(0, 8),

                init() {
                    try {
                        this.somAtivo = localStorage.getItem('painel-pedidos-som') === '1';
                    } catch (_) {
                        // Aba privada / storage bloqueado: segue sem preferência.
                    }

                    // O poll usa skipRender() quando nada mudou (é o ponto todo
                    // da assinatura — ver PainelPedidos::atualizar()), então o
                    // "Atualizado às" não pode vir do servidor: ele teria que
                    // re-renderizar pra mudar, e não é isso que queremos. O hook
                    // de commit do Livewire dispara a cada resposta do poll,
                    // mudança ou não, o que é exatamente "a tela está viva". Sem
                    // filtro por componente: esta página não tem Livewire filho,
                    // então qualquer commit aqui já é o dela.
                    Livewire.hook('commit', ({ succeed }) => {
                        succeed(() => {
                            this.ultimaAtualizacao = new Date().toLocaleTimeString('pt-BR').slice(0, 8);
                        });
                    });
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
