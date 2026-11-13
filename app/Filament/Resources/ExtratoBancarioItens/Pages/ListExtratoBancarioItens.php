<?php

namespace App\Filament\Resources\ExtratoBancarioItens\Pages;

use App\Exceptions\FinanceiroException;
use App\Filament\Resources\ExtratoBancarioItens\ExtratoBancarioItemResource;
use App\Models\ContaFinanceira;
use App\Services\Financeiro\BancoBrasil\ImportacaoExtratoService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListExtratoBancarioItens extends ListRecords
{
    protected static string $resource = ExtratoBancarioItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importarAgora')
                ->label('Importar agora')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->modalDescription('Busca no Banco do Brasil os lançamentos dos últimos dias e concilia os boletos.')
                ->action(function (ImportacaoExtratoService $importacao): void {
                    $contas = ContaFinanceira::query()->where('integracao', ImportacaoExtratoService::INTEGRACAO)->get();

                    if ($contas->isEmpty()) {
                        Notification::make()->title('Nenhuma conta com integração do Banco do Brasil')->warning()->send();

                        return;
                    }

                    foreach ($contas as $conta) {
                        try {
                            $resultado = $importacao->importar($conta);
                            Notification::make()
                                ->title("{$conta->nome}: extrato importado")
                                ->body("{$resultado['novas']} linha(s) nova(s), {$resultado['conciliadas']} boleto(s) conciliado(s).")
                                ->success()->send();
                        } catch (FinanceiroException $e) {
                            Notification::make()->title("{$conta->nome}: falhou")->body($e->getMessage())->danger()->send();
                        }
                    }
                }),
        ];
    }
}
