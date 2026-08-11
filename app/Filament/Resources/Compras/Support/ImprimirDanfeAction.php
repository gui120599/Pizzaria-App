<?php

namespace App\Filament\Resources\Compras\Support;

use App\Exceptions\DanfeGeracaoException;
use App\Models\Compra;
use App\Services\Nfe\DanfeService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gera e baixa o DANFE (PDF) a partir do XML original armazenado na
 * importação (compra_xml_path) — só disponível pra compras vindas de XML
 * (upload manual, busca por chave ou caixa de entrada da SEFAZ).
 */
class ImprimirDanfeAction
{
    public static function make(string $name = 'imprimirDanfe'): Action
    {
        return Action::make($name)
            ->label('Imprimir DANFE')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->visible(fn (Compra $record): bool => filled($record->compra_xml_path))
            ->action(function (Compra $record, DanfeService $service): ?StreamedResponse {
                $xml = Storage::disk('local')->get((string) $record->compra_xml_path);

                if ($xml === null) {
                    Notification::make()
                        ->title('XML da nota não encontrado')
                        ->body('O arquivo original não está mais disponível no armazenamento.')
                        ->danger()
                        ->send();

                    return null;
                }

                try {
                    $pdf = $service->gerar($xml);
                } catch (DanfeGeracaoException $e) {
                    Notification::make()
                        ->title('Não foi possível gerar o DANFE')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return null;
                }

                return response()->streamDownload(
                    fn () => print ($pdf),
                    "DANFE-{$record->compra_chave_nfe}.pdf",
                    ['Content-Type' => 'application/pdf'],
                );
            });
    }
}
