{{-- Vibra e toca um bipe quando o servidor avisa rodada pronta (evento garcom-pedido-pronto). --}}
<div x-data="{
        alertar() {
            try { navigator.vibrate?.([200, 100, 200]); } catch (e) {}
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                osc.frequency.value = 880;
                osc.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.25);
            } catch (e) {}
        },
     }"
     x-on:garcom-pedido-pronto.window="alertar()"
     class="hidden"></div>
