<?php

namespace App\Filament\Concerns;

use App\Enums\AcaoAutorizadaEnum;
use App\Exceptions\AutorizacaoNegadaException;
use App\Services\Garcom\AutorizacaoGerenteService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

/**
 * Campos de autorização de gerente (seletor + PIN) e execução com aviso de
 * erro/sucesso, compartilhados pelo Painel do Garçom e pelo caixa (OperarVenda).
 */
trait AutorizaComPinDeGerente
{
    /**
     * Seletor de gerente + PIN, só para quem não tem a permissão da ação.
     *
     * @return array<int, Component>
     */
    protected function camposAutorizacao(AcaoAutorizadaEnum $acao): array
    {
        $autorizacoes = app(AutorizacaoGerenteService::class);

        if ($autorizacoes->dispensaPin(Auth::user(), $acao)) {
            return [];
        }

        return [
            Select::make('autorizador_id')
                ->label('Gerente que autoriza')
                ->options($autorizacoes->autorizadoresDisponiveis($acao))
                ->required(),
            TextInput::make('pin')
                ->label('PIN do gerente')
                ->password()
                ->required()
                ->extraInputAttributes(['inputmode' => 'numeric', 'autocomplete' => 'off']),
        ];
    }

    protected function executarAutorizado(callable $operacao, string $sucesso): void
    {
        try {
            $operacao();
        } catch (AutorizacaoNegadaException|RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Não foi possível concluir. Tente de novo.')->danger()->send();

            return;
        }

        Notification::make()->title($sucesso)->success()->send();
    }
}
