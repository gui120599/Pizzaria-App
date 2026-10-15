{{--
    Bater ponto no celular. O horário exibido vem do servidor (agoraMs + tempo
    decorrido no aparelho); a marcação usa sempre now() do servidor.
    Localização: até 8 s; negada/indisponível → registra sem coordenadas.
    Selfie: redimensionada para 800 px e JPEG 70% no navegador antes do upload.
--}}
<x-filament-panels::page>
    <div class="mx-auto w-full max-w-md space-y-5"
        x-data="{
            offset: {{ $agoraMs }} - Date.now(),
            agora: new Date({{ $agoraMs }}),
            exigeSelfie: @js($exigeSelfie),
            enviando: false,
            enviandoFoto: false,
            previa: null,
            aviso: null,
            init() { setInterval(() => this.agora = new Date(Date.now() + this.offset), 1000) },
            hora() { return this.agora.toLocaleTimeString('pt-BR', { timeZone: 'America/Sao_Paulo' }) },
            localizar() {
                return new Promise((resolve) => {
                    if (! navigator.geolocation) { resolve(null); return; }
                    navigator.geolocation.getCurrentPosition(
                        (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, precisao: Math.round(p.coords.accuracy) }),
                        () => resolve(null),
                        { enableHighAccuracy: true, timeout: 8000, maximumAge: 30000 },
                    );
                });
            },
            async comprimir(arquivo) {
                const imagem = await createImageBitmap(arquivo);
                const escala = Math.min(1, 800 / Math.max(imagem.width, imagem.height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(imagem.width * escala);
                canvas.height = Math.round(imagem.height * escala);
                canvas.getContext('2d').drawImage(imagem, 0, 0, canvas.width, canvas.height);
                return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.7));
            },
            async escolherFoto(evento) {
                const arquivo = evento.target.files[0];
                if (! arquivo) return;
                this.enviandoFoto = true;
                try {
                    const blob = await this.comprimir(arquivo);
                    $wire.upload('selfie', new File([blob], 'selfie.jpg', { type: 'image/jpeg' }),
                        () => { this.previa = URL.createObjectURL(blob); this.enviandoFoto = false },
                        () => { this.aviso = 'Não foi possível enviar a foto. Tente de novo.'; this.enviandoFoto = false });
                } catch (e) {
                    this.aviso = 'Não foi possível ler a foto.';
                    this.enviandoFoto = false;
                }
                evento.target.value = '';
            },
            async bater() {
                if (this.exigeSelfie && ! this.previa) { this.aviso = 'Tire a selfie antes de registrar.'; return; }
                this.aviso = null;
                this.enviando = true;
                const posicao = await this.localizar();
                await $wire.bater(posicao?.lat ?? null, posicao?.lng ?? null, posicao?.precisao ?? null);
                this.enviando = false;
                this.previa = null;
            },
        }">
        @include('filament.garcom.partials.sem-conexao')

        <div class="text-center">
            <div class="font-mono text-5xl font-bold tabular-nums text-gray-950 dark:text-white" x-text="hora()">{{ now()->format('H:i:s') }}</div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $colaborador->nome }} · dia {{ $hoje->format('d/m') }}</div>
        </div>

        @if ($comprovante)
            <div class="rounded-2xl bg-green-50 p-5 text-center ring-1 ring-green-600/20 dark:bg-green-500/10">
                <div class="text-4xl">✓</div>
                <div class="mt-1 text-xl font-bold text-green-800 dark:text-green-300">{{ $comprovante['tipo'] }} registrada</div>
                <dl class="mt-4 space-y-1 text-left text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500">Data e hora</dt><dd class="font-mono">{{ $comprovante['data_hora'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">NSR</dt><dd class="font-mono">{{ $comprovante['nsr'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Código</dt><dd class="font-mono">{{ $comprovante['hash'] }}</dd></div>
                </dl>
                @if ($comprovante['sem_localizacao'])
                    <p class="mt-3 text-xs text-warning-700 dark:text-warning-400">Registrada sem localização (permissão negada ou GPS indisponível).</p>
                @endif
                @if ($comprovante['fora_da_cerca'])
                    <p class="mt-3 text-xs text-warning-700 dark:text-warning-400">Você parece estar fora da loja. O gerente verá este aviso no espelho.</p>
                @endif
                <x-filament::button class="mt-4 w-full" color="gray" wire:click="fecharComprovante">Concluir</x-filament::button>
            </div>
        @elseif ($semEscala)
            <div class="rounded-2xl bg-warning-50 p-5 text-center text-sm text-warning-800 ring-1 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-300">
                Você está sem escala vigente e não pode bater ponto. Fale com o gerente.
            </div>
        @else
            <button type="button" x-on:click="bater()" x-bind:disabled="enviando || enviandoFoto"
                class="w-full rounded-3xl bg-primary-600 px-4 py-10 text-3xl font-bold text-white shadow-lg transition active:scale-[.98] active:bg-primary-700 disabled:opacity-60">
                <span x-show="! enviando">{{ $proximoTipo->getLabel() }}</span>
                <span x-show="enviando" x-cloak>Registrando…</span>
            </button>

            <div class="flex items-center justify-center gap-3">
                <label class="inline-flex cursor-pointer items-center gap-2 rounded-xl bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">
                    <x-filament::icon icon="heroicon-o-camera" class="h-5 w-5" />
                    <span x-text="previa ? 'Trocar selfie' : '{{ $exigeSelfie ? 'Tirar selfie (obrigatória)' : 'Selfie (opcional)' }}'"></span>
                    <input type="file" accept="image/*" capture="user" class="hidden" x-on:change="escolherFoto($event)">
                </label>
                <template x-if="previa"><img x-bind:src="previa" alt="Selfie" class="h-12 w-12 rounded-full object-cover ring-2 ring-primary-500"></template>
                <span x-show="enviandoFoto" x-cloak class="text-xs text-gray-500">enviando…</span>
            </div>

            <p x-show="aviso" x-text="aviso" x-cloak class="text-center text-sm text-danger-600"></p>
            @if ($cercaConfigurada)
                <p class="text-center text-xs text-gray-400">A localização é conferida com a da loja.</p>
            @endif
        @endif

        <div>
            <h3 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">Marcações de hoje</h3>
            <div class="divide-y divide-gray-100 rounded-xl bg-white ring-1 ring-gray-950/5 dark:divide-white/5 dark:bg-gray-900 dark:ring-white/10">
                @forelse ($marcacoesHoje as $indice => $efetiva)
                    <div class="flex items-center justify-between px-4 py-2.5 text-sm">
                        <span class="text-gray-600 dark:text-gray-300">{{ \App\Enums\Ponto\TipoMarcacao::sugerido($indice)->getLabel() }}</span>
                        <span class="flex items-center gap-2">
                            @if ($efetiva->corrigida())
                                <x-filament::badge size="sm" color="info">corrigida</x-filament::badge>
                            @elseif ($efetiva->foraDaCerca())
                                <x-filament::badge size="sm" color="warning">fora da loja</x-filament::badge>
                            @endif
                            <span class="font-mono font-semibold text-gray-950 dark:text-white">{{ $efetiva->momento->format('H:i') }}</span>
                        </span>
                    </div>
                @empty
                    <div class="px-4 py-4 text-center text-sm text-gray-400">Nenhuma marcação hoje.</div>
                @endforelse
            </div>
        </div>
    </div>
</x-filament-panels::page>
