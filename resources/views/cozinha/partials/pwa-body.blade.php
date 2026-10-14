{{--
    Camada do tablet (spec §6.1): wake lock (a tela não apaga), destravar o
    áudio no primeiro toque ("Toque para iniciar o turno"), sons disparados
    pelas páginas com o evento "cozinha-som" e o aviso de sem conexão.
--}}
<div
    x-data="{
        travado: true,
        online: navigator.onLine,
        audio: null,
        wakeLock: null,
        async manterTelaLigada() {
            try {
                if ('wakeLock' in navigator && document.visibilityState === 'visible') {
                    this.wakeLock = await navigator.wakeLock.request('screen');
                }
            } catch (e) {}
        },
        destravar() {
            try {
                this.audio = new (window.AudioContext || window.webkitAudioContext)();
                this.audio.resume();
            } catch (e) {}
            this.travado = false;
            this.manterTelaLigada();
            try { document.documentElement.requestFullscreen?.(); } catch (e) {}
        },
        tocar(tipo) {
            if (! this.audio) return;
            const notas = tipo === 'alerta' ? [660, 440, 660, 440] : (tipo === 'atraso' ? [330, 330] : [880, 1320]);
            notas.forEach((freq, i) => {
                const osc = this.audio.createOscillator();
                const ganho = this.audio.createGain();
                osc.frequency.value = freq;
                ganho.gain.value = 0.25;
                osc.connect(ganho).connect(this.audio.destination);
                osc.start(this.audio.currentTime + i * 0.18);
                osc.stop(this.audio.currentTime + i * 0.18 + 0.15);
            });
        },
    }"
    x-on:visibilitychange.document="manterTelaLigada()"
    x-on:online.window="online = true"
    x-on:offline.window="online = false"
    x-on:cozinha-som.window="tocar($event.detail.tipo ?? 'novo')"
>
    <div
        x-show="travado"
        x-on:click="destravar()"
        class="fixed inset-0 z-[60] flex cursor-pointer flex-col items-center justify-center gap-3 bg-gray-950/95 text-center text-white"
    >
        <x-filament::icon icon="heroicon-o-fire" class="h-16 w-16 text-primary-500" />
        <div class="text-3xl font-bold">Toque para iniciar o turno</div>
        <div class="text-base text-gray-400">Liga o som dos pedidos e mantém a tela acesa.</div>
    </div>

    <div
        x-show="! online"
        x-cloak
        class="fixed inset-x-0 top-0 z-50 bg-danger-600 py-1.5 text-center text-sm font-bold text-white"
    >
        Sem conexão — as ações voltam a funcionar quando a rede voltar.
    </div>
</div>
