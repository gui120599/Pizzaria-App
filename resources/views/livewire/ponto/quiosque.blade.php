{{--
    Relógio: o horário vem do servidor (agoraMs) e o Alpine só soma o tempo
    decorrido no aparelho, então o relógio errado do tablet não aparece.
    A marcação em si sempre usa now() do servidor.

    Selfie: a câmera frontal liga na tela de confirmação (getUserMedia, exige
    HTTPS) e desliga ao sair dela. O quadro vai reduzido (640 px, JPEG 70%)
    como data URL no confirmar(); ultimaSelfie fica aqui na raiz para o
    comprovante mostrar a foto sem voltar ao servidor.
--}}
<div class="mx-auto flex min-h-screen max-w-md flex-col px-4 py-6"
    x-data="{
        offset: {{ $agoraMs }} - Date.now(),
        agora: new Date({{ $agoraMs }}),
        ultimaSelfie: null,
        init() { setInterval(() => this.agora = new Date(Date.now() + this.offset), 1000) },
        hora() { return this.agora.toLocaleTimeString('pt-BR', { timeZone: 'America/Sao_Paulo' }) },
        data() { return this.agora.toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo', weekday: 'long', day: '2-digit', month: 'long' }) },
    }">
    <header class="mb-6 text-center">
        <div class="font-mono text-5xl font-bold tabular-nums" x-text="hora()">{{ now()->format('H:i:s') }}</div>
        <div class="mt-1 text-sm text-gray-400 first-letter:uppercase" x-text="data()"></div>
    </header>

    @if ($comprovante)
        {{-- Comprovante: volta sozinho ao teclado depois de alguns segundos. --}}
        <div class="flex flex-1 flex-col items-center justify-center text-center" x-init="setTimeout(() => $wire.reiniciar(), 8000)">
            <template x-if="ultimaSelfie">
                <img x-bind:src="ultimaSelfie" alt="Selfie" class="mb-4 h-28 w-28 -scale-x-100 rounded-full object-cover ring-4 ring-green-600">
            </template>
            <div x-show="! ultimaSelfie" class="mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-green-600 text-5xl">✓</div>
            <div class="text-2xl font-bold">{{ $comprovante['tipo'] }} registrada</div>
            <div class="mt-1 text-lg text-gray-300">{{ $comprovante['nome'] }}</div>
            <dl class="mt-6 w-full space-y-1 rounded-xl bg-white/5 p-4 text-left text-sm">
                <div class="flex justify-between"><dt class="text-gray-400">Data e hora</dt><dd class="font-mono">{{ $comprovante['data_hora'] }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-400">NSR</dt><dd class="font-mono">{{ $comprovante['nsr'] }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-400">Código</dt><dd class="font-mono">{{ $comprovante['hash'] }}</dd></div>
            </dl>
            <button type="button" wire:click="reiniciar" class="mt-8 w-full rounded-xl bg-white/10 py-4 text-lg font-semibold active:bg-white/20">Concluir</button>
        </div>
    @elseif ($colaborador)
        <div class="flex flex-1 flex-col items-center justify-center text-center"
            x-init="setTimeout(() => $wire.reiniciar('Tempo esgotado. Digite o PIN de novo.'), {{ (int) config('ponto.quiosque.expira_identificacao_s', 30) * 1000 }})"
            x-data="{
                exige: @js($exigeSelfie),
                stream: null,
                pronta: false,
                erroCamera: null,
                enviando: false,
                async init() {
                    ultimaSelfie = null;
                    try {
                        this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 } }, audio: false });
                        this.$refs.video.srcObject = this.stream;
                        await this.$refs.video.play();
                        this.pronta = true;
                    } catch (e) {
                        this.erroCamera = 'Câmera indisponível. Libere a câmera do tablet (o sistema precisa abrir por https).';
                    }
                },
                destroy() { this.stream?.getTracks().forEach((faixa) => faixa.stop()); },
                capturar() {
                    const video = this.$refs.video;
                    const escala = Math.min(1, 640 / Math.max(video.videoWidth, video.videoHeight));
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.round(video.videoWidth * escala);
                    canvas.height = Math.round(video.videoHeight * escala);
                    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                    return canvas.toDataURL('image/jpeg', 0.7);
                },
                async registrar() {
                    if (this.enviando || (this.exige && ! this.pronta)) return;
                    this.enviando = true;
                    const foto = this.pronta ? this.capturar() : null;
                    ultimaSelfie = foto;
                    await $wire.confirmar(foto);
                    this.enviando = false;
                },
            }">
            <div class="text-sm uppercase tracking-wide text-gray-400">Olá,</div>
            <div class="mt-1 text-3xl font-bold">{{ $colaborador->nome }}</div>

            <div class="relative mt-6 aspect-square w-64 overflow-hidden rounded-full bg-white/5 ring-4 ring-orange-600/70">
                <video x-ref="video" playsinline muted class="h-full w-full -scale-x-100 object-cover" x-show="pronta"></video>
                <div x-show="! pronta && ! erroCamera" class="absolute inset-0 flex items-center justify-center text-sm text-gray-400">Abrindo a câmera…</div>
            </div>
            <p x-show="erroCamera" x-text="erroCamera" x-cloak class="mt-3 max-w-xs text-sm text-red-400"></p>
            <p x-show="pronta" x-cloak class="mt-3 text-sm text-gray-400">Olhe para a câmera e toque no botão.</p>

            <button type="button" x-on:click="registrar()" x-bind:disabled="enviando || (exige && ! pronta)"
                class="mt-6 w-full rounded-2xl bg-orange-600 py-8 text-3xl font-bold shadow-lg active:bg-orange-700 disabled:opacity-40">
                <span x-show="! enviando">{{ $proximoTipo->getLabel() }}</span>
                <span x-show="enviando" x-cloak>Registrando…</span>
            </button>
            <button type="button" wire:click="reiniciar" class="mt-4 w-full rounded-xl bg-white/10 py-4 text-lg active:bg-white/20">Não sou eu</button>
        </div>
    @else
        <div class="flex flex-1 flex-col">
            <div class="mb-2 text-center text-gray-300">Digite seu PIN</div>
            <div class="mb-3 flex justify-center gap-3" aria-live="polite">
                @for ($i = 0; $i < $tamanhoPin; $i++)
                    <span @class(['h-4 w-4 rounded-full', 'bg-white' => $i < strlen($pin), 'bg-white/20' => $i >= strlen($pin)])></span>
                @endfor
            </div>
            <div class="mb-3 min-h-6 text-center text-sm text-red-400" role="alert">{{ $erro }}</div>

            <div class="grid grid-cols-3 gap-3">
                @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $digito)
                    <button type="button" wire:click="digitar('{{ $digito }}')" class="rounded-2xl bg-white/10 py-6 text-3xl font-semibold active:bg-white/25">{{ $digito }}</button>
                @endforeach
                <button type="button" wire:click="apagar" class="rounded-2xl bg-white/5 py-6 text-lg active:bg-white/20" aria-label="Apagar">⌫</button>
                <button type="button" wire:click="digitar('0')" class="rounded-2xl bg-white/10 py-6 text-3xl font-semibold active:bg-white/25">0</button>
                <button type="button" wire:click="identificar" wire:loading.attr="disabled" @disabled(strlen($pin) < 4)
                    class="rounded-2xl bg-orange-600 py-6 text-lg font-bold active:bg-orange-700 disabled:opacity-40">OK</button>
            </div>
        </div>
    @endif
</div>
