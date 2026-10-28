<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Recibo de resgate #{{ $resgate->id }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 13px; color: #222; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .muted { color: #666; }
        .box { border: 1px solid #ccc; padding: 14px; margin-top: 18px; }
        .assinatura { margin-top: 60px; border-top: 1px solid #222; width: 60%; padding-top: 4px; }
    </style>
</head>
<body>
    <h1>Recibo de pagamento de comissão</h1>
    <p class="muted">{{ $empresa?->empresa_razao_social }} @if($empresa?->empresa_cnpj) — CNPJ {{ $empresa->empresa_cnpj }} @endif</p>

    <div class="box">
        <p>Recebi de <strong>{{ $empresa?->empresa_razao_social ?? 'a empresa' }}</strong> a quantia de
            <strong>R$ {{ number_format((float) $resgate->rc_valor, 2, ',', '.') }}</strong>,
            referente a comissões do programa de influenciadores, paga por Pix em
            {{ $resgate->rc_pago_em->format('d/m/Y H:i') }}.</p>
        <p>Beneficiário: <strong>{{ $resgate->influenciador->influenciador_nome }}</strong>
            @if($resgate->influenciador->influenciador_cpf) — CPF {{ $resgate->influenciador->influenciador_cpf }} @endif</p>
        <p class="muted">Resgate nº {{ $resgate->id }} @if($resgate->rc_lancamento_id) · título financeiro nº {{ $resgate->rc_lancamento_id }} @endif</p>
    </div>

    <div class="assinatura">{{ $resgate->influenciador->influenciador_nome }}</div>
    <p class="muted" style="margin-top:30px">Pagamento a pessoa física pode exigir recibo/RPA e retenções: confirme com o contador.</p>
</body>
</html>
