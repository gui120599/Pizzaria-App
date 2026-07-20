<?php

namespace App\Filament\Resources\Balancos\Pages;

use App\Filament\Resources\Balancos\BalancoResource;
use App\Models\MovimentacaoBalanco;
use App\Models\Produto;
use App\Services\BalancoEstoqueService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateBalanco extends CreateRecord
{
    protected static string $resource = BalancoResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    protected function handleRecordCreation(array $data): Model
    {
        $produto = Produto::findOrFail($data['mbal_produto_id']);

        $balanco = app(BalancoEstoqueService::class)->realizarBalanco(
            produto: $produto,
            quantidadeFisica: (float) $data['mbal_quantidade_balanco'],
            observacao: $data['mbal_observacao'] ?? null,
            loteCodigo: $data['lote_codigo'] ?? null,
            marcaId: $data['marca_id'] ?? null,
            validade: $data['validade'] ?? null,
        );

        if ($balanco === null) {
            throw ValidationException::withMessages([
                'mbal_quantidade_balanco' => 'O saldo físico informado é igual ao registrado no sistema. Nenhum ajuste foi gerado.',
            ]);
        }

        return $balanco;
    }

    protected function getCreatedNotification(): ?Notification
    {
        $record = $this->record;

        if (! $record instanceof MovimentacaoBalanco) {
            return null;
        }

        $tipo = $record->mbal_tipo_movimentacao->label();
        $ajuste = number_format((float) $record->mbal_quantidade_ajuste, 3, ',', '.');

        return Notification::make()
            ->title('Balanço registrado')
            ->body("Ajuste de {$tipo}: {$ajuste} unidade(s).")
            ->success();
    }
}
