<?php

namespace App\Filament\Concerns;

use App\Enums\AcaoAutorizadaEnum;
use App\Models\ItensPedido;
use App\Models\User;
use App\Services\CortesiaService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Auth;

/**
 * Ação "Cortesia" de um item de pedido (liga/desliga) com motivo e, para quem
 * não tem a permissão, PIN de gerente. A página que usa define de qual
 * pedido ela aceita itens (itemDeCortesia()) e usa AutorizaComPinDeGerente.
 */
trait ConcedeCortesiaNoItem
{
    /** O item de pedido desta tela, ou falha (404) se não pertencer a ela. */
    abstract protected function itemDeCortesia(int $itemId): ItensPedido;

    public function cortesiaItemAction(): Action
    {
        return Action::make('cortesiaItem')
            ->modalHeading(function (array $arguments): string {
                $item = ItensPedido::find($arguments['item'] ?? null);

                return ($item?->item_pedido_cortesia ? 'Desfazer cortesia de ' : 'Cortesia: ').($item?->nomeProduto() ?? 'item');
            })
            ->modalDescription(fn (array $arguments): string => ItensPedido::find($arguments['item'] ?? null)?->item_pedido_cortesia
                ? 'O item volta a ser cobrado.'
                : 'O item sai do estoque, mas não é cobrado e não entra na nota fiscal.')
            ->modalSubmitActionLabel('Confirmar')
            ->modalWidth('sm')
            ->schema(fn (): array => [
                TextInput::make('motivo')->label('Motivo')->required()->maxLength(255),
                ...$this->camposAutorizacao(AcaoAutorizadaEnum::CONCEDER_CORTESIA),
            ])
            ->action(function (array $data, array $arguments): void {
                $item = $this->itemDeCortesia((int) ($arguments['item'] ?? 0));
                $cortesia = ! $item->item_pedido_cortesia;

                $this->executarAutorizado(function () use ($item, $data, $cortesia): void {
                    $servico = app(CortesiaService::class);
                    $argumentos = [$item, $this->usuarioCortesia(), $data['autorizador_id'] ?? null, $data['pin'] ?? null, $data['motivo']];

                    $cortesia ? $servico->concederItem(...$argumentos) : $servico->desfazerItem(...$argumentos);
                }, $cortesia ? 'Cortesia concedida.' : 'Cortesia desfeita.');

                $this->aposMudarCortesia();
            });
    }

    /** Gancho da página para reler itens/totais depois da mudança. */
    protected function aposMudarCortesia(): void {}

    private function usuarioCortesia(): User
    {
        /** @var User */
        return Auth::user();
    }
}
