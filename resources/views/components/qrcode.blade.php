@props(['data', 'size' => 220])

@php
    $src = (new \chillerlan\QRCode\QRCode(new \chillerlan\QRCode\QROptions([
        'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
        'imageBase64' => true,
        'scale' => 6,
    ])))->render($data);
@endphp

<img src="{{ $src }}" {{ $attributes->merge(['width' => $size, 'height' => $size, 'alt' => 'QR Code']) }}>
