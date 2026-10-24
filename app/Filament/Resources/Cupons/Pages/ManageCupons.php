<?php

namespace App\Filament\Resources\Cupons\Pages;

use App\Enums\OrigemCupomEnum;
use App\Filament\Resources\Cupons\CupomResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCupons extends ManageRecords
{
    protected static string $resource = CupomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Novo cupom interno')
                ->mutateDataUsing(function (array $data): array {
                    $data['cupom_origem'] = OrigemCupomEnum::INTERNO->value;
                    $data['cupom_influenciador_id'] = null;

                    return $data;
                }),
        ];
    }
}
