<?php

namespace App\Filament\Support;

use App\Rules\TelefoneBrasileiro;
use App\Support\Telefone;
use Filament\Support\RawJs;
use Leandrocfe\FilamentPtbrFormFields\PhoneNumber;

/**
 * Campo de celular/telefone dos formulários Filament, com a mesma máscara dos
 * campos Blade/Livewire (App\Support\Telefone::MASCARA_ALPINE): "(62) 99999-9999"
 * ou "(62) 3333-4444", aceita colar com +55 ou zero do DDD. Exibe o valor
 * gravado já formatado (cadastro antigo sem o nono dígito aparece com ele) e
 * só aceita número brasileiro reconhecível — a gravação canônica fica com o
 * ClienteObserver.
 */
final class CampoTelefone
{
    public static function make(string $nome): PhoneNumber
    {
        return PhoneNumber::make($nome)
            ->tel()
            ->mask(RawJs::make(Telefone::MASCARA_ALPINE))
            ->placeholder('(62) 99999-9999')
            ->formatStateUsing(fn (?string $state): ?string => Telefone::formatar($state))
            ->rule(new TelefoneBrasileiro);
    }
}
