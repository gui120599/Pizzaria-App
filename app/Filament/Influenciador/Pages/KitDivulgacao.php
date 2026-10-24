<?php

namespace App\Filament\Influenciador\Pages;

use App\Models\Cupom;
use App\Models\MaterialDivulgacao;
use BackedEnum;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class KitDivulgacao extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $navigationLabel = 'Kit de Divulgação';

    protected static ?string $title = 'Kit de Divulgação';

    protected string $view = 'filament.influenciador.pages.kit-divulgacao';

    public ?int $cupomSelecionadoId = null;

    public function mount(): void
    {
        $this->cupomSelecionadoId = $this->cupons()->first()?->id;
    }

    /** Link rastreável /r/{slug}, sempre pelo APP_URL (o link compartilhado não pode depender de quem abriu o painel). */
    public function linkRastreavel(): ?string
    {
        $slug = Auth::guard('influenciador')->user()?->influenciador_slug;

        return $slug ? rtrim((string) config('app.url'), '/').route('referencia.influenciador', $slug, absolute: false) : null;
    }

    /** QR do link rastreável (que já abre o cardápio com o cupom principal), em data URI. */
    public function qrDoLink(): ?string
    {
        $link = $this->linkRastreavel();

        if ($link === null) {
            return null;
        }

        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'eccLevel' => EccLevel::M,
            'scale' => 6,
            'outputBase64' => true,
        ])))->render($link);
    }

    public function cupomPrincipal(): ?Cupom
    {
        return Auth::guard('influenciador')->user()?->cupomPrincipal;
    }

    public function materiais(): Collection
    {
        return MaterialDivulgacao::ativos()->get();
    }

    public function cupons(): Collection
    {
        return Cupom::where('cupom_influenciador_id', Auth::guard('influenciador')->id())
            ->where('cupom_ativo', true)
            ->get();
    }

    public function legendaPara(MaterialDivulgacao $material): string
    {
        if (! $material->material_conteudo_texto) {
            return '';
        }

        $codigo = $this->cupons()->firstWhere('id', $this->cupomSelecionadoId)?->cupom_codigo ?? '';

        return str_replace('{codigo}', $codigo, $material->material_conteudo_texto);
    }
}
