<?php

namespace App\Filament\Resources\Compras\Support;

use App\Exceptions\NfeXmlInvalidoException;
use App\Filament\Resources\Compras\CompraResource;
use App\Services\Nfe\NfeImportService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Importa um ou mais XML de NF-e, criando um rascunho de Compra por
 * arquivo (via NfeImportService). Cada arquivo é processado numa
 * transação independente — a falha de um não derruba os demais.
 * Com 1 sucesso, vai direto para a edição da compra; com mais de 1,
 * fica na listagem com um resumo de sucessos/erros por arquivo.
 */
class ImportarXmlAction
{
    public static function make(string $name = 'importarXml'): Action
    {
        return Action::make($name)
            ->label('Importar XML')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible(fn (): bool => CompraResource::canCreate())
            ->modalHeading('Importar XML de NF-e')
            ->modalDescription('Envie o(s) XML da nota do fornecedor. Um rascunho de compra é criado para cada nota, para você revisar antes de confirmar.')
            ->modalSubmitActionLabel('Importar')
            ->schema([
                FileUpload::make('arquivos')
                    ->label('Arquivo(s) XML')
                    ->multiple()
                    ->acceptedFileTypes(['text/xml', 'application/xml', '.xml'])
                    ->disk('local')
                    ->directory('compras_xml/tmp')
                    ->required()
                    ->helperText('Aceita um ou mais arquivos .xml de NF-e (modelo 55).'),
            ])
            ->action(function (array $data, NfeImportService $service, Component $livewire): void {
                $importadas = [];
                $falhas = [];

                foreach ((array) ($data['arquivos'] ?? []) as $path) {
                    try {
                        $conteudo = Storage::disk('local')->get($path);
                        if ($conteudo === null) {
                            throw new NfeXmlInvalidoException('Não foi possível ler o arquivo enviado.');
                        }

                        $importadas[] = $service->importar($conteudo, auth()->id());
                    } catch (ValidationException $e) {
                        $falhas[] = collect($e->errors())->flatten()->first();
                    } catch (NfeXmlInvalidoException $e) {
                        $falhas[] = $e->getMessage();
                    } catch (QueryException) {
                        $falhas[] = 'Esta nota fiscal já foi importada anteriormente.';
                    } finally {
                        Storage::disk('local')->delete($path);
                    }
                }

                if (count($importadas) === 1 && $falhas === []) {
                    Notification::make()
                        ->title('Compra importada')
                        ->body('Revise os itens e confirme quando estiver tudo certo.')
                        ->success()
                        ->send();

                    $livewire->redirect(CompraResource::getUrl('edit', ['record' => $importadas[0]]));

                    return;
                }

                if ($importadas !== []) {
                    Notification::make()
                        ->title(count($importadas).' nota(s) importada(s)')
                        ->body('Revise os itens pendentes de mapeamento antes de confirmar cada compra.')
                        ->success()
                        ->send();
                }

                if ($falhas !== []) {
                    Notification::make()
                        ->title(count($falhas).' arquivo(s) não importado(s)')
                        ->body(implode(' | ', array_unique($falhas)))
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }
}
