<?php

namespace App\Filament\Resources\SessoesCaixa\Schemas;

use App\Enums\StatusSessaoCaixa;
use App\Models\SessaoCaixa;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class SessaoCaixaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Abertura')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Select::make('sessaocaixa_caixa_id')
                        ->label('Caixa')
                        ->relationship('caixa', 'caixa_nome')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->disabled(fn (?SessaoCaixa $record): bool => $record?->exists ?? false)
                        ->dehydrated(),

                    Select::make('sessaocaixa_user_id')
                        ->label('Funcionário')
                        ->relationship('user', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->default(fn (): ?int => auth()->id())
                        ->disabled(fn (?SessaoCaixa $record): bool => $record?->exists ?? false)
                        ->dehydrated()
                        ->rule(self::semSessaoAbertaRule()),

                    Money::make('sessaocaixa_saldo_inicial')
                        ->label('Saldo inicial')
                        ->required()
                        ->minValue(0)
                        ->disabled(fn (?SessaoCaixa $record): bool => $record?->exists ?? false)
                        ->dehydrated(),

                    Text::make(fn (SessaoCaixa $record): string => sprintf(
                        'Status: %s — Saldo final: R$ %s',
                        StatusSessaoCaixa::from($record->sessaocaixa_status)->getLabel(),
                        number_format((float) $record->sessaocaixa_saldo_final, 2, ',', '.'),
                    ))
                        ->visible(fn (?SessaoCaixa $record): bool => $record?->exists ?? false),
                ]),

            Section::make('Observações')
                ->columnSpanFull()
                ->schema([
                    Textarea::make('sessaocaixa_observacoes')
                        ->label('Observações')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** Um funcionário não pode ter duas sessões ABERTA ao mesmo tempo — mesma regra de SessaoCaixaController::store. */
    private static function semSessaoAbertaRule(): Closure
    {
        return function (?SessaoCaixa $record) {
            return function (string $attribute, $value, Closure $fail) use ($record): void {
                if ($record?->exists) {
                    return;
                }

                $existeSessaoAberta = SessaoCaixa::query()
                    ->where('sessaocaixa_user_id', $value)
                    ->where('sessaocaixa_status', 'ABERTA')
                    ->exists();

                if ($existeSessaoAberta) {
                    $fail('Este funcionário já possui uma sessão de caixa aberta.');
                }
            };
        };
    }
}
