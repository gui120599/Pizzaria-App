<?php

namespace App\Filament\Components;

use App\Enums\BaseVencimentoEnum;
use App\Enums\FormaPagamento;
use App\Enums\RegraDataEntradaEnum;
use App\Models\PrazoPagamento;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;

/**
 * Seção "Condições de compra" do fornecedor (relação 1:1 condicaoCompra),
 * usada em Estoque › Fornecedores e em Pessoas › Prestadores. Quem chama
 * decide a visibilidade (ex.: só para a categoria Fornecedor).
 */
class CondicoesCompraSection
{
    public static function make(): Section
    {
        return Section::make('Condições de compra')
            ->description('Sugestões para o modal "Confirmar compra" deste fornecedor. Nada é pago nem confirmado automaticamente.')
            ->icon('heroicon-o-banknotes')
            ->relationship('condicaoCompra')
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                Select::make('fcc_prazo_pagamento_id')
                    ->label('Prazo de pagamento padrão')
                    ->options(fn (): array => PrazoPagamento::query()
                        ->ativos()
                        ->orderBy('prazo_pagamento_nome')
                        ->pluck('prazo_pagamento_nome', 'id')
                        ->all())
                    ->searchable()
                    ->helperText('Vem selecionado ao confirmar a compra. Se a nota trouxer duplicatas, os vencimentos da nota prevalecem.')
                    ->columnSpan(1),

                Select::make('fcc_prazo_base')
                    ->label('O prazo conta a partir da')
                    ->options(BaseVencimentoEnum::class)
                    ->default(BaseVencimentoEnum::Entrada->value)
                    ->required()
                    ->native(false)
                    ->columnSpan(1),

                Select::make('fcc_forma_pagamento')
                    ->label('Forma de pagamento padrão')
                    ->options(FormaPagamento::class)
                    ->native(false)
                    ->helperText('Apenas sugestão: a parcela só é paga quando você marca "Já pago".')
                    ->columnSpan(1),

                ContaFinanceiraSelect::make('fcc_conta_financeira_id')
                    ->label('Conta de origem sugerida')
                    ->semCaixaPdv()
                    ->helperText('Aparece preenchida quando você marca "Já pago" na confirmação da compra.')
                    ->columnSpan(1),

                Select::make('fcc_data_entrada_regra')
                    ->label('Data de entrada no estoque')
                    ->options(RegraDataEntradaEnum::class)
                    ->default(RegraDataEntradaEnum::Hoje->value)
                    ->required()
                    ->native(false)
                    ->helperText('Com "Data de emissão da nota", relatórios por data (ex.: CMV do período) passam a refletir a emissão, não o dia da importação.')
                    ->columnSpanFull(),
            ]);
    }
}
