{{-- PWA da cozinha: tela cheia, paisagem, cor do tema (spec §6.1). --}}
<link rel="manifest" href="{{ route('filament.cozinha.manifest') }}">
<meta name="theme-color" content="#f97316">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<script>
    // Diferença entre o relógio do servidor e o do tablet: os timers dos cards
    // contam a partir da hora do servidor, não da do aparelho.
    window.cozinhaOffsetMs = {{ now()->getTimestampMs() }} - Date.now();
</script>
<script>
    // Um relógio só para todos os cards (um setInterval por tela, não por card).
    document.addEventListener('alpine:init', () => {
        Alpine.store('relogio', { agora: Date.now() + window.cozinhaOffsetMs });
        setInterval(() => { Alpine.store('relogio').agora = Date.now() + window.cozinhaOffsetMs; }, 1000);

        // Idade do card desde que entrou na fila e a cor do timer (spec §5.3).
        Alpine.data('cozinhaTimer', (liberadoEmMs, alertaMin, alvoMin, multiplicador) => ({
            get segundos() {
                return liberadoEmMs ? Math.max(0, Math.floor((this.$store.relogio.agora - liberadoEmMs) / 1000)) : 0;
            },
            get idade() {
                const s = this.segundos;
                const h = Math.floor(s / 3600);
                const m = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
                const ss = String(s % 60).padStart(2, '0');
                return h > 0 ? `${h}:${m}:${ss}` : `${m}:${ss}`;
            },
            get cor() {
                const min = this.segundos / 60;
                if (! liberadoEmMs) return 'cinza';
                if (min >= alvoMin * multiplicador) return 'atrasado';
                if (min >= alvoMin) return 'vermelho';
                if (min >= alertaMin) return 'amarelo';
                return 'verde';
            },
        }));
    });
</script>
