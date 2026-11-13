<?php

namespace App\Filament\Resources\CompraPendencias\Pages;

use App\Enums\Compras\StatusPendencia;
use App\Enums\Compras\TipoPendenciaCompra;
use App\Filament\Resources\CompraPendencias\CompraPendenciaResource;
use App\Filament\Widgets\ComprasHojeWidget;
use App\Models\CompraPendencia;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCompraPendencias extends ListRecords
{
    protected static string $resource = CompraPendenciaResource::class;

    protected function getHeaderWidgets(): array
    {
        return [ComprasHojeWidget::class];
    }

    /**
     * Abas: sugestões do agente, cada tipo com pendência em aberto, e o
     * histórico. Tipos sem nada em aberto não aparecem.
     */
    public function getTabs(): array
    {
        $porTipo = CompraPendencia::query()->emAberto()
            ->selectRaw('tipo, count(*) as total')
            ->groupBy('tipo')
            ->pluck('total', 'tipo');

        $abas = [
            'abertas' => Tab::make('Em aberto')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->emAberto())
                ->badge($porTipo->sum() ?: null)
                ->badgeColor('warning'),
            'sugeridas' => Tab::make('Sugestões do agente')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', StatusPendencia::Sugerida))
                ->badge(CompraPendencia::query()->where('status', StatusPendencia::Sugerida)->count() ?: null)
                ->badgeColor('info'),
        ];

        foreach (TipoPendenciaCompra::cases() as $tipo) {
            if (! $porTipo->has($tipo->value)) {
                continue;
            }

            $abas[$tipo->value] = Tab::make($tipo->getLabel())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->emAberto()->where('tipo', $tipo))
                ->badge($porTipo[$tipo->value])
                ->badgeColor($tipo->getColor());
        }

        $abas['historico'] = Tab::make('Resolvidas e descartadas')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [StatusPendencia::Resolvida, StatusPendencia::Descartada]));

        return $abas;
    }
}
