<?php

namespace App\Notifications;

use App\Enums\FormaPagamentoComissaoEnum;
use App\Models\Influenciador;
use App\Models\PagamentoComissao;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class ComissaoPagaNotification extends Notification
{
    public function __construct(private PagamentoComissao $pagamento) {}

    /** @return array<int, string> */
    public function via(Influenciador $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(Influenciador $notifiable): array
    {
        $valor = number_format((float) $this->pagamento->pgcom_valor_total, 2, ',', '.');

        $mensagem = $this->pagamento->pgcom_forma === FormaPagamentoComissaoEnum::CREDITO_PIZZA
            ? "Sua comissão de R$ {$valor} virou crédito para pizza (código ".($this->pagamento->cupomCredito?->cupom_codigo ?? '—').').'
            : "Pagamento de comissão de R$ {$valor} via Pix realizado.";

        return FilamentNotification::make()
            ->title('Comissão paga')
            ->body($mensagem)
            ->success()
            ->getDatabaseMessage();
    }
}
