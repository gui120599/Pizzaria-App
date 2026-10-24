<?php

namespace App\Filament\Support;

use App\Enums\FormaTrocoEnum;
use App\Exceptions\CorrecaoPagamentoException;
use App\Models\CartoesPagamento;
use App\Models\Maquininha;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Venda;
use App\Services\CorrecaoPagamentoService;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
use Leandrocfe\FilamentPtbrFormFields\Money;

/**
 * Formulário e execução da correção de pagamento de uma venda finalizada
 * (trocar forma, dividir, forma do troco), compartilhados pelo Relatório de
 * Sessão de Caixa e pela tabela de vendas. A regra fica em CorrecaoPagamentoService.
 */
class CorrecaoPagamentoFormulario
{
    /**
     * Pagamentos da venda no shape do formulário (id, forma, valor, troco).
     *
     * @return array<int, array{id: int, forma: string, valor: float, troco: float}>
     */
    public static function pagamentosDaVenda(Venda $venda): array
    {
        return $venda->pagamentos()
            ->with(['opcaoPagamento' => fn ($query) => $query->withTrashed()])
            ->orderBy('id')
            ->get()
            ->map(fn (PagamentosVenda $pagamento): array => [
                'id' => $pagamento->id,
                'forma' => (string) ($pagamento->opcaoPagamento?->opcaopag_nome ?? 'Forma #'.$pagamento->pg_venda_opcaopagamento_id),
                'valor' => (float) $pagamento->pg_venda_valor_pagamento,
                'troco' => (float) $pagamento->pg_venda_valor_troco,
            ])
            ->all();
    }

    /**
     * @param  array<int, array{id: int, forma: string, valor: float, troco: float}>  $pagamentos
     * @param  (callable(Get): bool)|null  $visivelQuando  condição extra de visibilidade do conjunto (ex.: aba escolhida)
     * @return array<int, mixed>
     */
    public static function schema(array $pagamentos, ?callable $visivelQuando = null): array
    {
        $visivel = fn (Get $get, callable $condicao): bool => ($visivelQuando === null || $visivelQuando($get)) && $condicao($get);

        return [
            Select::make('pagamento_id')
                ->label('Pagamento')
                ->options(collect($pagamentos)->mapWithKeys(fn (array $pagamento): array => [
                    $pagamento['id'] => $pagamento['forma'].' — R$ '.number_format($pagamento['valor'], 2, ',', '.')
                        .($pagamento['troco'] > 0 ? ' (troco R$ '.number_format($pagamento['troco'], 2, ',', '.').')' : ''),
                ])->all())
                ->default(count($pagamentos) === 1 ? $pagamentos[0]['id'] : null)
                ->required(fn (Get $get): bool => $visivel($get, fn (): bool => true))
                ->visible(fn (Get $get): bool => $visivel($get, fn (): bool => true))
                ->live(),

            ToggleButtons::make('tipo')
                ->label('Correção')
                ->options(function (Get $get) use ($pagamentos): array {
                    $pagamento = collect($pagamentos)->firstWhere('id', (int) $get('pagamento_id'));

                    return [
                        'forma' => 'Trocar a forma',
                        'divisao' => 'Dividir em formas',
                        ...($pagamento && $pagamento['troco'] > 0 ? ['forma_troco' => 'Forma do troco'] : []),
                    ];
                })
                ->default('forma')
                ->required(fn (Get $get): bool => $visivel($get, fn (): bool => true))
                ->visible(fn (Get $get): bool => $visivel($get, fn (): bool => true))
                ->inline()
                ->live(),

            Select::make('opcao_id')
                ->label('Forma correta')
                ->options(self::opcoesSemTaxa())
                ->required(fn (Get $get): bool => $visivel($get, fn (Get $get): bool => $get('tipo') === 'forma'))
                ->live()
                ->visible(fn (Get $get): bool => $visivel($get, fn (Get $get): bool => $get('tipo') === 'forma')),
            Select::make('maquininha_id')
                ->label('Maquininha')
                ->options(self::maquininhas())
                ->default(fn (): ?int => Maquininha::padrao()?->id)
                ->visible(fn (Get $get): bool => $visivel($get, fn (Get $get): bool => $get('tipo') === 'forma' && self::usaMaquininha($get('opcao_id')))),
            Select::make('cartao_id')
                ->label('Bandeira')
                ->options(self::bandeiras())
                ->visible(fn (Get $get): bool => $visivel($get, fn (Get $get): bool => $get('tipo') === 'forma' && self::usaBandeira($get('opcao_id')))),

            Repeater::make('partes')
                ->label('Partes (a soma tem que dar o valor do pagamento)')
                ->schema([
                    Select::make('opcao_id')
                        ->label('Forma')
                        ->options(self::opcoesSemTaxa())
                        ->required()
                        ->live(),
                    Money::make('valor')
                        ->label('Valor')
                        ->required(),
                    Select::make('maquininha_id')
                        ->label('Maquininha')
                        ->options(self::maquininhas())
                        ->default(fn (): ?int => Maquininha::padrao()?->id)
                        ->visible(fn (Get $get): bool => self::usaMaquininha($get('opcao_id'))),
                    Select::make('cartao_id')
                        ->label('Bandeira')
                        ->options(self::bandeiras())
                        ->visible(fn (Get $get): bool => self::usaBandeira($get('opcao_id'))),
                ])
                ->columns(4)
                ->defaultItems(2)
                ->minItems(2)
                ->addActionLabel('Adicionar parte')
                ->visible(fn (Get $get): bool => $visivel($get, fn (Get $get): bool => $get('tipo') === 'divisao')),

            ToggleButtons::make('forma_troco')
                ->label('O troco saiu em')
                ->options(FormaTrocoEnum::class)
                ->required(fn (Get $get): bool => $visivel($get, fn (Get $get): bool => $get('tipo') === 'forma_troco'))
                ->inline()
                ->visible(fn (Get $get): bool => $visivel($get, fn (Get $get): bool => $get('tipo') === 'forma_troco')),

            Textarea::make('justificativa')
                ->label('Justificativa')
                ->placeholder('Ex.: passou no débito da Stone, foi lançado como crédito')
                ->rows(2)
                ->visible(fn (Get $get): bool => $visivel($get, fn (): bool => true)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CorrecaoPagamentoException
     */
    public static function aplicar(int $vendaId, array $data, CorrecaoPagamentoService $service): void
    {
        $pagamento = PagamentosVenda::query()
            ->where('pg_venda_venda_id', $vendaId)
            ->findOrFail($data['pagamento_id']);
        $justificativa = filled($data['justificativa'] ?? null) ? $data['justificativa'] : null;

        match ($data['tipo']) {
            'forma' => $service->trocarForma($pagamento, (int) $data['opcao_id'], self::idOuNulo($data['maquininha_id'] ?? null), self::idOuNulo($data['cartao_id'] ?? null), $justificativa),
            'divisao' => $service->dividir($pagamento, $data['partes'] ?? [], $justificativa),
            'forma_troco' => $service->trocarFormaTroco(
                $pagamento,
                $data['forma_troco'] instanceof FormaTrocoEnum ? $data['forma_troco'] : FormaTrocoEnum::from($data['forma_troco']),
                $justificativa,
            ),
        };
    }

    /** @return array<int, string> */
    public static function opcoesSemTaxa(): array
    {
        return OpcoesPagamento::query()
            ->where(fn ($query) => $query
                ->whereNotIn('opcaopag_tipo_taxa', ['ACRESCENTAR', 'DESCONTAR'])
                ->orWhereNull('opcaopag_tipo_taxa')
                ->orWhere('opcaopag_valor_percentual_taxa', '<=', 0)
                ->orWhereNull('opcaopag_valor_percentual_taxa'))
            ->orderBy('opcaopag_nome')
            ->pluck('opcaopag_nome', 'id')
            ->all();
    }

    /** @return array<int, string> */
    public static function maquininhas(): array
    {
        return Maquininha::query()->orderBy('nome')->pluck('nome', 'id')->all();
    }

    /** @return array<int, string> */
    public static function bandeiras(): array
    {
        return CartoesPagamento::query()->orderBy('cartao_bandeira')->pluck('cartao_bandeira', 'id')->all();
    }

    public static function usaMaquininha(mixed $opcaoId): bool
    {
        return filled($opcaoId) && OpcoesPagamento::find($opcaoId)?->tipoMaquininha() !== null;
    }

    public static function usaBandeira(mixed $opcaoId): bool
    {
        return filled($opcaoId) && (bool) OpcoesPagamento::find($opcaoId)?->opcaopag_requer_bandeira;
    }

    private static function idOuNulo(mixed $id): ?int
    {
        return filled($id) ? (int) $id : null;
    }
}
