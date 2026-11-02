{{-- PWA do Painel do Garçom (push): manifest, cor do tema e ícone do iOS. Só com PIZZARIA_PUSH_ATIVO. --}}
<link rel="manifest" href="{{ route('filament.garcom.manifest') }}">
<meta name="theme-color" content="#C2410C">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Salão">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="{{ asset('img/marca/apple-touch-icon.png') }}">
