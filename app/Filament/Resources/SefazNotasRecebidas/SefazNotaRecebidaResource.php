<?php

namespace App\Filament\Resources\SefazNotasRecebidas;

use App\Filament\Resources\SefazNotasRecebidas\Pages\ManageSefazNotasRecebidas;
use App\Filament\Resources\SefazNotasRecebidas\Tables\SefazNotasRecebidasTable;
use App\Models\Empresa;
use App\Models\SefazNotaRecebida;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Caixa de entrada de NF-e emitidas contra o CNPJ da empresa (Distribuição
 * DFe / NSU) — revisão manual, decoupled do polling 100% automático que já
 * existe (empresa_sefaz_auto_importacao_ativa). Só aparece com certificado
 * digital configurado, mesmo gate usado em BuscarNfePorChaveAction.
 */
class SefazNotaRecebidaResource extends Resource
{
    protected static ?string $model = SefazNotaRecebida::class;

    protected static ?string $slug = 'sefaz-notas-recebidas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static UnitEnum|string|null $navigationGroup = 'Estoque';

    protected static ?string $navigationLabel = 'NF-e recebidas (SEFAZ)';

    protected static ?string $modelLabel = 'Nota recebida';

    protected static ?string $pluralModelLabel = 'Notas recebidas';

    protected static ?int $navigationSort = 21;

    protected static ?string $recordTitleAttribute = 'snr_chave_acesso';

    public static function shouldRegisterNavigation(): bool
    {
        return Empresa::first()?->certificadoConfigurado() ?? false;
    }

    public static function table(Table $table): Table
    {
        return SefazNotasRecebidasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSefazNotasRecebidas::route('/'),
        ];
    }
}
