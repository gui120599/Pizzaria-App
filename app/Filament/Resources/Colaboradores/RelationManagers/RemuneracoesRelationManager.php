<?php

namespace App\Filament\Resources\Colaboradores\RelationManagers;

use App\Enums\Ponto\ModalidadeRemuneracao;
use App\Exceptions\PontoException;
use App\Filament\Resources\ContasFinanceiras\Support\ValorMonetario;
use App\Models\Colaborador;
use App\Models\ColaboradorRemuneracao;
use App\Services\Ponto\CadastroColaboradorService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Leandrocfe\FilamentPtbrFormFields\Money;

/**
 * Vigências de remuneração. Não se edita uma vigência: a nova encerra a
 * anterior na véspera (CadastroColaboradorService). Só aparece para quem
 * pode ver remuneração (o Gerente não vê, por padrão).
 */
class RemuneracoesRelationManager extends RelationManager
{
    protected static string $relationship = 'remuneracoes';

    protected static ?string $title = 'Remunerações';

    protected static ?string $modelLabel = 'remuneração';

    protected static ?string $pluralModelLabel = 'remunerações';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('verRemuneracao', $ownerRecord) ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('modalidade')
                ->label('Modalidade')
                ->options(ModalidadeRemuneracao::class)
                ->required()
                ->live(),
            Money::make('valor_base')
                ->label(fn (Get $get): string => match (self::modalidade($get)) {
                    ModalidadeRemuneracao::Mensal => 'Salário mensal',
                    ModalidadeRemuneracao::PorHora => 'Valor da hora',
                    ModalidadeRemuneracao::PorDiaria => 'Valor da diária',
                    ModalidadeRemuneracao::PorTurno => 'Valor do turno',
                    default => 'Valor base',
                })
                ->default('0.00')
                ->required(),
            Money::make('valor_por_entrega')
                ->label('Valor por entrega')
                ->default('0.00'),
            TextInput::make('carga_diaria_minutos')
                ->label('Carga diária')
                ->numeric()
                ->integer()
                ->suffix('min')
                ->helperText('Diarista: define o valor da hora para hora extra.'),
            TextInput::make('carga_semanal_minutos')
                ->label('Carga semanal')
                ->numeric()
                ->integer()
                ->suffix('min')
                ->helperText('Mensal: 2640 (44 h) dá o divisor 220.'),
            DatePicker::make('vigente_desde')
                ->label('Vigente desde')
                ->native(false)
                ->displayFormat('d/m/Y')
                ->default(today())
                ->required(),
            TextInput::make('percentual_extra_util')->label('Extra dia útil')->numeric()->suffix('%')->default(50)->required(),
            TextInput::make('percentual_extra_domingo_feriado')->label('Extra domingo/feriado')->numeric()->suffix('%')->default(100)->required(),
            TextInput::make('percentual_adicional_noturno')->label('Adicional noturno')->numeric()->suffix('%')->default(20)->required(),
            Toggle::make('considera_hora_noturna_reduzida')
                ->label('Hora noturna reduzida (52 min 30 s)')
                ->default(true)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('vigente_desde', 'desc')
            ->columns([
                TextColumn::make('modalidade')->label('Modalidade')->badge(),
                TextColumn::make('valor_base')->label('Valor base')->money('BRL'),
                TextColumn::make('valor_por_entrega')->label('Por entrega')->money('BRL')->placeholder('—'),
                TextColumn::make('carga_diaria_minutos')->label('Carga diária')->suffix(' min')->placeholder('—'),
                TextColumn::make('vigente_desde')->label('Desde')->date('d/m/Y'),
                TextColumn::make('vigente_ate')->label('Até')->date('d/m/Y')->placeholder('Em vigor'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Nova vigência')
                    ->modalHeading('Nova remuneração')
                    ->modalDescription('A vigência em aberto é encerrada na véspera da nova.')
                    ->using(function (array $data, CreateAction $action): ColaboradorRemuneracao {
                        /** @var Colaborador $colaborador */
                        $colaborador = $this->getOwnerRecord();
                        $desde = Carbon::parse($data['vigente_desde']);
                        unset($data['vigente_desde']);
                        $data['valor_base'] = ValorMonetario::normalizar($data['valor_base'] ?? null);
                        $data['valor_por_entrega'] = ValorMonetario::normalizar($data['valor_por_entrega'] ?? null);

                        try {
                            return app(CadastroColaboradorService::class)->novaRemuneracao($colaborador, $data, $desde);
                        } catch (PontoException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }
                    }),
            ])
            ->recordActions([
                DeleteAction::make(),
            ]);
    }

    private static function modalidade(Get $get): ?ModalidadeRemuneracao
    {
        $modalidade = $get('modalidade');

        return $modalidade instanceof ModalidadeRemuneracao ? $modalidade : ModalidadeRemuneracao::tryFrom((string) $modalidade);
    }
}
