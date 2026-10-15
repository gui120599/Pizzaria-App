<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\EspelhoPonto;
use App\Models\Colaborador;
use App\Models\PontoMarcacao;
use App\Support\DiaOperacional;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** Quem está com o ponto aberto agora (marcações ímpares no dia operacional, sem considerar ajustes). */
class TrabalhandoAgoraWidget extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return auth()->user()?->can('view_espelho:ponto') ?? false;
    }

    public function table(Table $table): Table
    {
        $hoje = DiaOperacional::de(now())->toDateString();

        return $table
            ->heading('Trabalhando agora')
            ->query(fn (): Builder => Colaborador::query()
                ->ativos()
                ->whereIn('id', PontoMarcacao::query()
                    ->select('colaborador_id')
                    ->whereDate('data_referencia', $hoje)
                    ->groupBy('colaborador_id')
                    ->havingRaw('COUNT(*) % 2 = 1'))
                ->withMax(['marcacoes as ultima_marcacao' => fn ($q) => $q->whereDate('data_referencia', $hoje)], 'registrada_em'))
            ->defaultSort('nome')
            ->paginated(false)
            ->emptyStateHeading('Ninguém com ponto aberto')
            ->recordUrl(fn (): string => EspelhoPonto::getUrl())
            ->columns([
                TextColumn::make('nome')->label('Colaborador')->description(fn (Colaborador $record): ?string => $record->cargo),
                TextColumn::make('ultima_marcacao')->label('Entrou às')->dateTime('H:i'),
            ]);
    }
}
