<?php

namespace App\Filament\Widgets;

use App\Models\ProducaoItem;
use App\Services\Cozinha\RelatorioProducaoService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Gate;

/** Retrato da cozinha agora, por setor: fila, em preparo, atrasados e tablets online. */
class CozinhaAgoraWidget extends Widget
{
    protected string $view = 'filament.widgets.cozinha-agora';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', ProducaoItem::class);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['setores' => app(RelatorioProducaoService::class)->agoraPorSetor()];
    }
}
