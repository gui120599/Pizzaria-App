<?php

namespace App\Filament\Resources\Colaboradores\Schemas;

use App\Enums\Ponto\PeriodicidadeRepasse;
use App\Enums\Ponto\VinculoColaborador;
use App\Enums\TipoChavePix;
use App\Models\Colaborador;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Leandrocfe\FilamentPtbrFormFields\Document;

class ColaboradorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(120),
                    Document::make('cpf')
                        ->label('CPF')
                        ->cpf()
                        // CPF fica criptografado: a unicidade é conferida pelo hash (cpf_hash).
                        ->rule(fn (?Colaborador $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            if (blank($value)) {
                                return;
                            }

                            $repetido = Colaborador::withTrashed()
                                ->where('cpf_hash', Colaborador::hashCpf((string) $value))
                                ->when($record !== null, fn (Builder $query) => $query->whereKeyNot($record->id))
                                ->exists();

                            if ($repetido) {
                                $fail('Já existe colaborador com este CPF.');
                            }
                        }),
                    TextInput::make('cargo')
                        ->label('Cargo')
                        ->maxLength(80),
                    TextInput::make('setor')
                        ->label('Setor')
                        ->maxLength(80),
                    Toggle::make('entregador')
                        ->label('Faz entregas')
                        ->helperText('Conta as entregas do dia na apuração e usa o plano de despesa de entregador.')
                        ->inline(false),
                ]),

            Section::make('Vínculo')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('vinculo')
                        ->label('Vínculo')
                        ->options(VinculoColaborador::class)
                        ->required(),
                    Select::make('periodicidade_repasse')
                        ->label('Periodicidade do repasse')
                        ->options(PeriodicidadeRepasse::class)
                        ->required(),
                    DatePicker::make('data_admissao')
                        ->label('Admissão')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->default(today())
                        ->required(),
                    DatePicker::make('data_desligamento')
                        ->label('Desligamento')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Use a ação "Desligar": ela também encerra as vigências.')
                        ->visible(fn (?Colaborador $record): bool => $record?->data_desligamento !== null),
                ]),

            Section::make('Pagamento (PIX)')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('tipo_chave_pix')
                        ->label('Tipo da chave')
                        ->options(TipoChavePix::class)
                        ->requiredWith('chave_pix'),
                    TextInput::make('chave_pix')
                        ->label('Chave PIX')
                        ->maxLength(140)
                        ->requiredWith('tipo_chave_pix'),
                ]),

            Section::make('Acesso')
                ->description('Login para bater ponto pelo celular (painel do garçom) e PIN para o tablet da loja.')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('user_id')
                        ->label('Usuário')
                        ->relationship('user', 'name', fn (Builder $query, ?Colaborador $record): Builder => $query
                            ->where(fn (Builder $q) => $q
                                ->whereDoesntHave('colaborador')
                                ->when($record?->user_id !== null, fn (Builder $q2) => $q2->orWhere('id', $record->user_id))))
                        ->searchable()
                        ->preload()
                        ->placeholder('Sem login'),
                    TextInput::make('pin_quiosque')
                        ->label('PIN do quiosque')
                        ->password()
                        ->revealable()
                        ->numeric()
                        ->minLength(4)
                        ->maxLength(6)
                        // Gravado com hash: em branco mantém o atual.
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        // O quiosque acha o colaborador só pelo PIN: não pode repetir (nem com desligados, o índice é único).
                        ->rule(fn (?Colaborador $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            if (blank($value)) {
                                return;
                            }

                            $emUso = Colaborador::withTrashed()
                                ->where('pin_lookup', Colaborador::hashPin((string) $value))
                                ->when($record !== null, fn (Builder $query) => $query->whereKeyNot($record->id))
                                ->exists();

                            if ($emUso) {
                                $fail('Este PIN já está em uso. Escolha outro.');
                            }
                        })
                        ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                        ->helperText(fn (?Colaborador $record): string => filled($record?->pin_quiosque)
                            ? 'Já tem PIN. Preencha só para trocar.'
                            : '4 a 6 dígitos.'),
                ]),
        ]);
    }
}
