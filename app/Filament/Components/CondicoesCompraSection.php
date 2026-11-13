<?php

namespace App\Filament\Components;

use App\Enums\BaseVencimentoEnum;
use App\Enums\Compras\AutonomiaAgente;
use App\Enums\Compras\RegraBaixaCompra;
use App\Enums\FormaPagamento;
use App\Enums\RegraDataEntradaEnum;
use App\Models\PlanoDespesa;
use App\Models\PrazoPagamento;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

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
            ->description('Sugestões para o modal "Confirmar compra" deste fornecedor. Os campos de "Automação" valem só para as notas importadas automaticamente da SEFAZ.')
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
                    ->helperText('Na confirmação manual é só sugestão. Na automação, é a forma usada na baixa automática.')
                    ->columnSpan(1),

                ContaFinanceiraSelect::make('fcc_conta_financeira_id')
                    ->label('Conta de origem sugerida')
                    ->semCaixaPdv()
                    ->helperText('Aparece preenchida quando você marca "Já pago" na confirmação da compra. Na automação, é de onde sai o dinheiro da baixa automática.')
                    ->columnSpan(1),

                Select::make('fcc_data_entrada_regra')
                    ->label('Data de entrada no estoque')
                    ->options(RegraDataEntradaEnum::class)
                    ->default(RegraDataEntradaEnum::Hoje->value)
                    ->required()
                    ->native(false)
                    ->helperText('Com "Data de emissão da nota", relatórios por data (ex.: CMV do período) passam a refletir a emissão, não o dia da importação.')
                    ->columnSpanFull(),

                self::automacao(),
            ]);
    }

    /**
     * Parâmetros da automação de compras (docs/planos/compras-automatizadas.md).
     * Só Admin edita (permissão configurar:automacao_compra).
     */
    private static function automacao(): Fieldset
    {
        $bloqueado = fn (): bool => ! (auth()->user()?->can('configurar:automacao_compra') ?? false);

        return Fieldset::make('Automação (notas importadas da SEFAZ)')
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                Actions::make([
                    Action::make('perfilPixAVista')
                        ->label('Aplicar perfil: PIX à vista')
                        ->icon('heroicon-o-bolt')
                        ->color('gray')
                        ->disabled($bloqueado)
                        ->action(function (Set $set): void {
                            $set('fcc_regra_baixa', RegraBaixaCompra::AutomaticaNaEmissao->value);
                            $set('fcc_forma_pagamento', FormaPagamento::Pix->value);
                            $set('fcc_prazo_pagamento_id', null);
                        }),
                    Action::make('perfilBoletoAPrazo')
                        ->label('Aplicar perfil: boleto a prazo')
                        ->icon('heroicon-o-document-text')
                        ->color('gray')
                        ->disabled($bloqueado)
                        ->action(function (Set $set): void {
                            $set('fcc_regra_baixa', RegraBaixaCompra::Manual->value);
                            $set('fcc_forma_pagamento', FormaPagamento::Boleto->value);
                        }),
                ])->columnSpanFull(),

                Select::make('fcc_regra_baixa')
                    ->label('Regra de baixa')
                    ->options(RegraBaixaCompra::class)
                    ->placeholder('Manual (confirmação em lote)')
                    ->native(false)
                    ->disabled($bloqueado)
                    ->helperText(fn (Get $get): ?string => self::regraBaixa($get('fcc_regra_baixa'))?->getDescription()
                        ?? 'Sem regra, as parcelas ficam abertas para confirmação manual.')
                    ->live()
                    ->columnSpan(1),

                Select::make('fcc_plano_despesa_id')
                    ->label('Plano de despesa padrão')
                    ->options(fn (): array => PlanoDespesa::query()->whereNotNull('pai_id')->orderBy('nome')->pluck('nome', 'id')->all())
                    ->searchable()
                    ->disabled($bloqueado)
                    ->helperText('Usado nos itens da nota que ainda não têm produto (ou cujo produto não tem plano).')
                    ->columnSpan(1),

                Toggle::make('fcc_entrada_automatica')
                    ->label('Entrada automática no estoque')
                    ->helperText('Confirma a compra sozinha quando todos os itens estiverem identificados e a nota for uma compra comum.')
                    ->disabled($bloqueado)
                    ->live()
                    ->columnSpan(1),

                TextInput::make('fcc_entrada_dias_apos_emissao')
                    ->label('Dias após a emissão')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(60)
                    ->default(0)
                    ->suffix('dias')
                    ->helperText('0 = entra no mesmo dia da emissão da nota.')
                    ->dehydrateStateUsing(fn (mixed $state): int => max(0, (int) $state))
                    ->visible(fn (Get $get): bool => (bool) $get('fcc_entrada_automatica'))
                    ->disabled($bloqueado)
                    ->columnSpan(1),

                TextInput::make('fcc_tolerancia_preco_pct')
                    ->label('Alerta de variação de custo')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(999)
                    ->default(15)
                    ->suffix('%')
                    ->helperText('Abre um alerta (sem bloquear) quando o custo unitário variar mais que isso em relação à última compra.')
                    ->dehydrateStateUsing(fn (mixed $state): float => blank($state) ? 15.0 : (float) $state)
                    ->disabled($bloqueado)
                    ->columnSpan(1),

                Select::make('fcc_autonomia_agente')
                    ->label('Autonomia do agente')
                    ->options(AutonomiaAgente::class)
                    ->placeholder(AutonomiaAgente::PADRAO->getLabel().' (padrão)')
                    ->native(false)
                    ->disabled($bloqueado)
                    ->helperText('Usado quando o agente de compras estiver ativo.')
                    ->columnSpan(1),

                Textarea::make('fcc_observacoes')
                    ->label('Observações')
                    ->rows(2)
                    ->disabled($bloqueado)
                    ->columnSpanFull(),
            ]);
    }

    private static function regraBaixa(mixed $estado): ?RegraBaixaCompra
    {
        return $estado instanceof RegraBaixaCompra ? $estado : RegraBaixaCompra::tryFrom((string) $estado);
    }
}
