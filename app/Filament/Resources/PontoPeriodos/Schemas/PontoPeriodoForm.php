<?php

namespace App\Filament\Resources\PontoPeriodos\Schemas;

use App\Enums\Ponto\PeriodicidadeRepasse;
use App\Enums\Ponto\StatusPeriodo;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Só a data de pagamento se edita; limites e status mudam pelo fechamento. */
class PontoPeriodoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(5)
                ->columnSpanFull()
                ->schema([
                    Select::make('periodicidade')->label('Periodicidade')->options(PeriodicidadeRepasse::class)->disabled()->dehydrated(false),
                    DatePicker::make('data_inicio')->label('Início')->native(false)->displayFormat('d/m/Y')->disabled()->dehydrated(false),
                    DatePicker::make('data_fim')->label('Fim')->native(false)->displayFormat('d/m/Y')->disabled()->dehydrated(false),
                    Select::make('status')->label('Situação')->options(StatusPeriodo::class)->disabled()->dehydrated(false),
                    DatePicker::make('data_pagamento')->label('Pagamento')->native(false)->displayFormat('d/m/Y')
                        ->helperText('Vencimento dos títulos dos repasses.')
                        ->required(),
                ]),
        ]);
    }
}
