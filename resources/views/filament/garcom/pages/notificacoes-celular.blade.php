{{--
    Notificações no celular (Web Push). O estado do aparelho vem do navegador
    (window.GarcomPush); o do servidor, de $servidorAtivo. A permissão só é
    pedida no toque em "Ativar notificações".
--}}
<x-filament-panels::page>
    <div class="mx-auto w-full max-w-2xl space-y-4"
         x-data="{
            carregando: true,
            ocupado: false,
            suporte: 'pendente',
            permissao: 'default',
            inscrito: false,
            erro: null,
            falha: null,
            detalhes: '',
            get ativo() { return this.inscrito && this.permissao === 'granted' && $wire.servidorAtivo; },
            atualizarDetalhes() {
                this.detalhes = window.GarcomPush
                    ? JSON.stringify(window.GarcomPush.diagnostico(), null, 2)
                    : 'O recurso de notificações (script) não carregou neste aparelho.';
            },
            async atualizar() {
                try {
                    this.suporte = window.GarcomPush.suporte();
                    this.permissao = window.GarcomPush.permissao();
                    this.inscrito = Boolean(this.suporte === 'ok' && await window.GarcomPush.inscricaoAtual());
                    this.falha = null;
                } catch (e) {
                    this.falha = e.message;
                } finally {
                    this.carregando = false;
                    this.atualizarDetalhes();
                }
            },
            vigiar() {
                setTimeout(() => {
                    if (this.carregando) {
                        this.carregando = false;
                        this.falha = window.GarcomPush ? 'a verificação demorou demais' : 'o recurso de notificações não carregou';
                        this.atualizarDetalhes();
                    }
                }, 14000);
            },
            refazer() {
                this.carregando = true;
                this.falha = null;
                this.vigiar();
                this.atualizar();
            },
            iniciar() {
                this.vigiar();
                const rodar = () => this.atualizar();
                window.GarcomPush ? rodar() : window.addEventListener('garcom-push:pronto', rodar, { once: true });
            },
            async ativar() {
                this.erro = null;
                this.ocupado = true;
                try {
                    const inscricao = await window.GarcomPush.ativar();
                    await $wire.registrar(inscricao, window.GarcomPush.instalado());
                } catch (e) {
                    this.erro = e.message === 'negado' ? 'negado' : (e.message === 'dispensado' ? 'dispensado' : e.message);
                }
                await this.atualizar();
                this.ocupado = false;
            },
            async desativar() {
                this.ocupado = true;
                await window.GarcomPush.desinscrever();
                await $wire.desativar();
                await this.atualizar();
                this.ocupado = false;
            },
         }"
         x-init="iniciar()">

        <div x-show="carregando" class="rounded-2xl bg-white p-5 text-sm text-gray-500 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            Verificando este aparelho…
        </div>

        {{-- O app instalado no iPhone não tem botão de recarregar: a tela precisa sair sozinha do "Verificando". --}}
        <div x-show="falha" x-cloak class="rounded-2xl bg-amber-50 p-5 text-sm text-amber-900 ring-1 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-200">
            <div class="font-semibold">Não foi possível verificar este aparelho</div>
            <p class="mt-1">Motivo: <span x-text="falha"></span>.</p>
            <div class="mt-3 grid grid-cols-2 gap-3">
                <button type="button" x-on:click="refazer()"
                        class="rounded-xl bg-primary-600 py-3 text-sm font-semibold text-white active:scale-[.98]">
                    Tentar de novo
                </button>
                <button type="button" x-on:click="window.location.reload()"
                        class="rounded-xl bg-white py-3 text-sm font-semibold text-gray-800 ring-1 ring-gray-300 active:scale-[.98] dark:bg-white/10 dark:text-gray-100 dark:ring-white/20">
                    Recarregar a tela
                </button>
            </div>
        </div>

        <div x-show="! carregando" x-cloak class="space-y-4">

            {{-- iPhone fora da Tela de Início --}}
            <template x-if="suporte === 'ios_instalar'">
                <div class="rounded-2xl bg-white p-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-base font-semibold text-gray-950 dark:text-white">Instale o Salão na Tela de Início</div>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">No iPhone, as notificações só funcionam com o painel instalado (iOS 16.4 ou mais novo):</p>
                    <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-200">
                        <li>Toque no ícone de <strong>Compartilhar</strong> (quadrado com seta) do Safari ou do Chrome.</li>
                        <li>Escolha <strong>Adicionar à Tela de Início</strong>.</li>
                        <li>Abra o <strong>Salão</strong> pelo novo ícone e entre com seu PIN.</li>
                        <li>Volte a esta tela e toque em <strong>Ativar notificações</strong>.</li>
                    </ol>
                </div>
            </template>

            <template x-if="suporte === 'ios_antigo'">
                <div class="rounded-2xl bg-amber-50 p-5 text-sm text-amber-900 ring-1 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-200">
                    Seu iPhone precisa do <strong>iOS 16.4 ou mais novo</strong> para receber notificações do painel.
                </div>
            </template>

            <template x-if="suporte === 'embutido'">
                <div class="rounded-2xl bg-amber-50 p-5 text-sm text-amber-900 ring-1 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-200">
                    Este navegador não recebe notificações. Abra este endereço no <strong>Google Chrome</strong>:
                    <div class="mt-2 break-all font-mono text-xs" x-text="window.location.href"></div>
                    <button type="button" class="mt-2 font-semibold underline" x-on:click="navigator.clipboard.writeText(window.location.href)">Copiar link</button>
                </div>
            </template>

            <template x-if="suporte === 'indisponivel'">
                <div class="rounded-2xl bg-amber-50 p-5 text-sm text-amber-900 ring-1 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-200">
                    Este aparelho ou navegador não suporta notificações do painel. Os avisos dentro do painel continuam funcionando normalmente. Use o <strong>Google Chrome</strong> atualizado.
                </div>
            </template>

            <template x-if="suporte === 'ok'">
                <div class="space-y-4">
                    {{-- Permissão negada --}}
                    <template x-if="permissao === 'denied'">
                        <div class="rounded-2xl bg-red-50 p-5 text-sm text-red-900 ring-1 ring-red-600/20 dark:bg-red-500/10 dark:text-red-200">
                            <div class="font-semibold">Notificações bloqueadas neste aparelho</div>
                            <p class="mt-1">Libere nas configurações e volte aqui:</p>
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                <li><strong>Android (Chrome):</strong> toque no cadeado ao lado do endereço → Permissões → Notificações → Permitir.</li>
                                <li><strong>iPhone:</strong> Ajustes → Notificações → Salão → Permitir notificações.</li>
                            </ul>
                        </div>
                    </template>

                    {{-- Ativar --}}
                    <template x-if="! ativo && permissao !== 'denied'">
                        <div class="rounded-2xl bg-white p-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                            <div class="text-base font-semibold text-gray-950 dark:text-white">Receba os chamados mesmo com o celular no bolso</div>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                Você será avisado quando uma mesa chamar, pedir a conta, fizer um pedido para aprovar ou tiver pedido pronto.
                                O navegador vai pedir a sua permissão.
                            </p>
                            <p x-show="erro === 'dispensado'" class="mt-2 text-sm text-amber-700 dark:text-amber-300">Você não confirmou a permissão. Toque em Ativar para tentar de novo.</p>
                            <p x-show="erro && erro !== 'dispensado' && erro !== 'negado'" class="mt-2 text-sm text-red-700 dark:text-red-300" x-text="erro"></p>
                            <button type="button" x-on:click="ativar()" x-bind:disabled="ocupado"
                                    class="mt-4 w-full rounded-xl bg-primary-600 py-4 text-base font-semibold text-white active:scale-[.98] disabled:opacity-60">
                                Ativar notificações
                            </button>
                        </div>
                    </template>

                    {{-- Ativado --}}
                    <template x-if="ativo">
                        <div class="space-y-4">
                            <div class="rounded-2xl bg-white p-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                <div class="text-base font-semibold text-green-700 dark:text-green-400">✅ Ativado neste aparelho</div>
                                @if ($pausadoAte)
                                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">Pausado até {{ $pausadoAte }}.</p>
                                @endif
                                <div class="mt-4 grid grid-cols-2 gap-3">
                                    <button type="button" wire:click="testar" wire:loading.attr="disabled"
                                            class="rounded-xl bg-primary-600 py-3 text-sm font-semibold text-white active:scale-[.98]">
                                        Enviar teste
                                    </button>
                                    @if ($pausadoAte)
                                        <button type="button" wire:click="retomar"
                                                class="rounded-xl bg-gray-100 py-3 text-sm font-semibold text-gray-800 active:scale-[.98] dark:bg-white/10 dark:text-gray-100">
                                            Retomar avisos
                                        </button>
                                    @else
                                        <button type="button" wire:click="pausar(4)"
                                                class="rounded-xl bg-gray-100 py-3 text-sm font-semibold text-gray-800 active:scale-[.98] dark:bg-white/10 dark:text-gray-100">
                                            Pausar por 4 h
                                        </button>
                                    @endif
                                </div>
                                <details class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                                    <summary class="cursor-pointer font-medium">Não chegou — o que fazer</summary>
                                    <ul class="mt-2 list-disc space-y-1 pl-5">
                                        <li>Confira se o celular está com internet e fora do modo Não Perturbe / Foco.</li>
                                        <li><strong>Android:</strong> em Configurações → Apps → Chrome → Bateria, escolha "Sem restrições" e confirme as notificações do Chrome.</li>
                                        <li><strong>iPhone:</strong> abra o Salão pelo ícone da Tela de Início e libere o Salão no seu Foco de trabalho.</li>
                                        <li>Se continuar sem chegar, toque em "Desativar neste aparelho" e ative de novo.</li>
                                    </ul>
                                </details>
                            </div>

                            <div class="rounded-2xl bg-white p-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                <div class="text-sm font-semibold text-gray-950 dark:text-white">Quais avisos receber</div>
                                <div class="mt-3 space-y-3">
                                    @foreach ($this->eventos() as $evento)
                                        <label class="flex items-center justify-between gap-3 text-sm text-gray-800 dark:text-gray-100">
                                            <span>{{ $evento->label() }}</span>
                                            <input type="checkbox" wire:model.live="preferencias.{{ $evento->value }}"
                                                   class="size-5 rounded border-gray-300 text-primary-600">
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <button type="button" x-on:click="desativar()" x-bind:disabled="ocupado"
                                    class="w-full rounded-xl py-3 text-sm font-semibold text-red-700 dark:text-red-400">
                                Desativar neste aparelho
                            </button>
                        </div>
                    </template>
                </div>
            </template>
        </div>

        <div class="space-y-2 pt-2 text-center">
            <button type="button" x-on:click="window.location.reload()"
                    class="text-sm font-medium text-gray-500 underline dark:text-gray-400">
                Recarregar esta tela
            </button>
            <details class="text-left text-xs text-gray-500 dark:text-gray-400">
                <summary class="cursor-pointer text-center">Detalhes técnicos</summary>
                <pre class="mt-2 whitespace-pre-wrap break-all rounded-xl bg-gray-100 p-3 dark:bg-white/5" x-text="detalhes"></pre>
            </details>
        </div>
    </div>
</x-filament-panels::page>
