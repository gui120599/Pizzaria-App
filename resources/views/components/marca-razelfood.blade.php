@props(['modulo' => null])

{{--
    Marca RazelFood nos painéis internos: símbolo + nome (+ módulo). O nome é
    texto, não imagem, para valer no modo claro e no escuro. Estilos inline /
    <style> próprio porque nem todo painel tem tema compilado com estas classes.
--}}
@once
    <style>
        .rf-marca { display: inline-flex; align-items: center; gap: .6rem; height: 100%; line-height: 1; }
        .rf-marca img { height: 100%; width: auto; aspect-ratio: 1; flex: none; }
        .rf-marca-nome { display: flex; font-weight: 800; font-size: 1.2rem; letter-spacing: -.015em; }
        .rf-razel { background: linear-gradient(180deg, #1B8FB8, #0B3C5C); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .rf-food { background: linear-gradient(180deg, #FBBF24, #F97316); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .dark .rf-razel { background-image: linear-gradient(180deg, #A5E3F7, #38BDF8); }
        .rf-modulo { margin-top: .22rem; font-size: .6rem; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; color: #6B7280; }
        .dark .rf-modulo { color: #9CA3AF; }
    </style>
@endonce

<span class="rf-marca">
    <img src="{{ asset('img/razelfood/icon-192.png') }}" alt="RazelFood">
    <span style="display: flex; flex-direction: column; justify-content: center;">
        <span class="rf-marca-nome"><span class="rf-razel">Razel</span><span class="rf-food">Food</span></span>
        @if ($modulo)
            <span class="rf-modulo">{{ $modulo }}</span>
        @endif
    </span>
</span>
