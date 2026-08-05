<?php

namespace App\Filament\Resources\Compras\Support;

use App\Exceptions\SefazAutenticacaoException;
use App\Exceptions\SefazDocumentoAindaNaoDisponivelException;
use App\Exceptions\SefazIndisponivelException;
use App\Filament\Resources\Compras\CompraResource;
use App\Models\Empresa;
use App\Services\Sefaz\SefazDistribuicaoService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Busca uma NF-e específica direto na SEFAZ, a partir da chave de acesso.
 * Manifesta ciência da operação se necessário (exigência da SEFAZ pra
 * liberar o XML completo) e importa como rascunho de compra pelo mesmo
 * NfeImportService do upload manual (via SefazDistribuicaoService).
 */
class BuscarNfePorChaveAction
{
    public static function make(string $name = 'buscarNfePorChave'): Action
    {
        return Action::make($name)
            ->label('Buscar por chave')
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->visible(fn (): bool => CompraResource::canCreate() && (Empresa::first()?->certificadoConfigurado() ?? false))
            ->modalHeading('Buscar NF-e na SEFAZ')
            ->modalDescription('Informe a chave de acesso de 44 dígitos da nota. O sistema consulta a SEFAZ, manifesta ciência se necessário, e importa como rascunho de compra.')
            ->modalSubmitActionLabel('Buscar')
            ->schema([
                TextInput::make('chave')
                    ->label('Chave de acesso')
                    ->required()
                    ->maxLength(44)
                    ->rule('digits:44')
                    ->helperText('44 dígitos, sem espaços ou pontuação.'),
            ])
            ->action(function (array $data, SefazDistribuicaoService $service, Component $livewire): void {
                $chave = preg_replace('/\D/', '', (string) $data['chave']) ?? '';

                try {
                    $compra = $service->buscarPorChave($chave, auth()->id());
                } catch (SefazDocumentoAindaNaoDisponivelException $e) {
                    Notification::make()
                        ->title('Nota manifestada, aguardando liberação')
                        ->body($e->getMessage())
                        ->warning()
                        ->persistent()
                        ->send();

                    return;
                } catch (SefazAutenticacaoException $e) {
                    Notification::make()
                        ->title('Falha de autenticação com a SEFAZ')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                } catch (SefazIndisponivelException $e) {
                    Notification::make()
                        ->title('Não foi possível consultar a SEFAZ')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Não foi possível importar')
                        ->body(collect($e->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    return;
                } catch (QueryException) {
                    Notification::make()
                        ->title('Esta nota fiscal já foi importada anteriormente.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Compra importada')
                    ->body('Revise os itens e confirme quando estiver tudo certo.')
                    ->success()
                    ->send();

                $livewire->redirect(CompraResource::getUrl('edit', ['record' => $compra]));
            });
    }
}
