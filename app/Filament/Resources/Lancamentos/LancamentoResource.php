<?php

namespace App\Filament\Resources\Lancamentos;

use App\Enums\StatusLancamento;
use App\Filament\Resources\Lancamentos\Pages\CreateLancamento;
use App\Filament\Resources\Lancamentos\Pages\EditLancamento;
use App\Filament\Resources\Lancamentos\Pages\ListLancamentos;
use App\Filament\Resources\Lancamentos\Schemas\LancamentoForm;
use App\Filament\Resources\Lancamentos\Tables\LancamentosTable;
use App\Models\Lancamento;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class LancamentoResource extends Resource
{
    protected static ?string $model = Lancamento::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Contas a Pagar/Receber';

    protected static ?string $modelLabel = 'lançamento';

    protected static ?string $pluralModelLabel = 'lançamentos';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'descricao';

    public static function form(Schema $schema): Schema
    {
        return LancamentoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LancamentosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLancamentos::route('/'),
            'create' => CreateLancamento::route('/create'),
            'edit' => EditLancamento::route('/{record}/edit'),
        ];
    }

    /**
     * Lançamento Pago fica travado para edição direta (valor/plano não podem mudar
     * depois da baixa sem deixar rastro). Para corrigir, usa-se "Estornar pagamento"
     * (volta a Pendente) e então edita normalmente.
     */
    public static function canEdit(Model $record): bool
    {
        /** @var Lancamento $record */
        return $record->status !== StatusLancamento::Pago;
    }

    public static function canDelete(Model $record): bool
    {
        /** @var Lancamento $record */
        return $record->status !== StatusLancamento::Pago;
    }
}
