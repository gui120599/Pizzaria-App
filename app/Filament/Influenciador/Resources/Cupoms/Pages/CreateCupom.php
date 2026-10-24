<?php

namespace App\Filament\Influenciador\Resources\Cupoms\Pages;

use App\Enums\OrigemCupomEnum;
use App\Filament\Influenciador\Resources\Cupoms\CupomResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateCupom extends CreateRecord
{
    protected static string $resource = CupomResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['cupom_influenciador_id'] = Auth::guard('influenciador')->id();
        $data['cupom_origem'] = OrigemCupomEnum::INFLUENCIADOR->value;

        return $data;
    }
}
