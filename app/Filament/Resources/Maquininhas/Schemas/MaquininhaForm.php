<?php

namespace App\Filament\Resources\Maquininhas\Schemas;

use App\Filament\Support\MaquininhaQuickCreateForm;
use Filament\Schemas\Schema;

class MaquininhaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(MaquininhaQuickCreateForm::schema());
    }
}
