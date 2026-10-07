<?php

namespace App\Services\MesaCliente;

use App\Models\Mesa;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Collection;

/**
 * QR impresso em cada mesa: aponta para /mesa/{codigo} (o código é aleatório,
 * não o id). Regerar o código no admin invalida uma impressão vazada.
 */
class QrCodeMesaService
{
    /**
     * Sempre pelo APP_URL, não pelo endereço de quem abriu o admin: imprimir
     * o QR acessando por IP interno não pode gerar um link que o cliente não abre.
     */
    public function url(Mesa $mesa): string
    {
        return rtrim((string) config('app.url'), '/').route('mesa-cliente.show', $mesa->mesa_codigo_qr, absolute: false);
    }

    /** PNG em data URI — serve para <img> na tela e no PDF (DomPDF). */
    public function png(Mesa $mesa, int $escala = 10): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'eccLevel' => EccLevel::M,
            'scale' => $escala,
            'outputBase64' => true,
        ])))->render($this->url($mesa));
    }

    /**
     * Folha A4 com os QRs em grade (2 por linha), com o nome da mesa.
     *
     * @param  Collection<int, Mesa>  $mesas
     */
    public function pdf(Collection $mesas): string
    {
        $cartoes = $mesas->map(fn (Mesa $mesa) => [
            'nome' => $mesa->mesa_nome,
            'qr' => $this->png($mesa),
            'url' => $this->url($mesa),
        ])->values();

        return Pdf::loadView('mesa-cliente.qr-pdf', ['cartoes' => $cartoes])
            // O QR vai embutido como data URI: o padrão da config não libera "data://".
            ->setOption('allowed_protocols', config('dompdf.options.allowed_protocols', []) + ['data://' => ['rules' => []]])
            ->setPaper('a4')
            ->output();
    }
}
