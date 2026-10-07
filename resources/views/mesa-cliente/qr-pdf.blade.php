{{-- QRs das mesas para imprimir (DomPDF): 2 por linha, 3 linhas por folha A4. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 12mm; }
        body { font-family: DejaVu Sans, sans-serif; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        td { width: 50%; height: 78mm; text-align: center; vertical-align: middle; border: 1px dashed #bbb; padding: 4mm; }
        .nome { font-size: 22pt; font-weight: bold; margin-bottom: 2mm; }
        .chamada { font-size: 11pt; margin-bottom: 3mm; }
        img { width: 55mm; height: 55mm; }
        .url { font-size: 7pt; color: #666; margin-top: 2mm; }
        .quebra { page-break-after: always; }
    </style>
</head>
<body>
    @foreach ($cartoes->chunk(6) as $folha)
        <table @class(['quebra' => ! $loop->last])>
            @foreach ($folha->chunk(2) as $linha)
                <tr>
                    @foreach ($linha as $cartao)
                        <td>
                            <div class="nome">{{ $cartao['nome'] }}</div>
                            <div class="chamada">Aponte a câmera e peça pelo celular</div>
                            <img src="{{ $cartao['qr'] }}" alt="QR {{ $cartao['nome'] }}">
                            <div class="url">{{ $cartao['url'] }}</div>
                        </td>
                    @endforeach
                    @if ($linha->count() === 1)
                        <td></td>
                    @endif
                </tr>
            @endforeach
        </table>
    @endforeach
</body>
</html>
