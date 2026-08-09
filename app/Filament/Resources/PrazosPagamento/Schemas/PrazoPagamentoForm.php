<?php

namespace App\Filament\Resources\PrazosPagamento\Schemas;

use Closure;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class PrazoPagamentoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Prazo de pagamento')
                ->columns(2)
                ->schema([
                    TextInput::make('prazo_pagamento_nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Ex.: 30/60/90, 7/15/20 dias, 10x com juros')
                        ->columnSpanFull(),
                    Toggle::make('prazo_pagamento_ativo')
                        ->label('Ativo')
                        ->default(true),
                    Textarea::make('prazo_pagamento_observacoes')
                        ->label('Observações')
                        ->columnSpanFull(),
                ]),

            Section::make('Parcelas')
                ->description('Dias corridos a partir da data-base (vencimento do boleto/entrada da compra) e percentual do valor total. A soma dos percentuais não precisa fechar em 100% — parcelamento com juros embutido soma mais.')
                ->schema([
                    Repeater::make('parcelas')
                        ->relationship()
                        ->label('')
                        ->schema(self::parcelasSchema())
                        ->columns(2)
                        ->orderColumn('parcela_numero')
                        ->addActionLabel('Adicionar parcela')
                        ->minItems(1)
                        ->collapsible()
                        ->live()
                        ->itemLabel(fn (array $state): ?string => (isset($state['parcela_dias']) && isset($state['parcela_percentual']))
                            ? "{$state['parcela_dias']} dias — {$state['parcela_percentual']}%"
                            : 'Nova parcela')
                        // Precisa ser validação de campo (roda antes de mutateFormDataBeforeCreate/Save):
                        // por ser ->relationship(), 'parcelas' já é removido do $data principal antes
                        // desses hooks rodarem, então uma checagem lá nunca veria os itens.
                        ->rule(self::regraSomaPercentuais())
                        ->columnSpanFull(),
                    Placeholder::make('soma_percentuais')
                        ->label('Soma dos percentuais')
                        ->content(fn (Get $get): string => number_format(
                            collect($get('parcelas') ?? [])->sum(fn (array $p): float => (float) ($p['parcela_percentual'] ?? 0)),
                            2,
                            ',',
                            '.'
                        ).'%'),
                ])
                ->columnSpanFull(),
        ]);
    }

    /**
     * Campos mínimos (nome + parcelas) para cadastro rápido a partir de um "+" num
     * Select de outro form (ex.: ConfirmarCompraAction) — sem as Sections/campos
     * secundários do CRUD completo. O Repeater aqui não é ->relationship() (o
     * registro pai ainda não existe nesse ponto); quem chama persiste manualmente
     * via createOptionUsing().
     */
    public static function camposRapidos(): array
    {
        return [
            TextInput::make('prazo_pagamento_nome')
                ->label('Nome')
                ->required()
                ->maxLength(255)
                ->placeholder('Ex.: 30/60/90, 7/15/20 dias, 10x com juros'),
            Repeater::make('parcelas')
                ->label('Parcelas')
                ->schema(self::parcelasSchema())
                ->columns(2)
                ->addActionLabel('Adicionar parcela')
                ->minItems(1)
                ->reorderable(false)
                ->columnSpanFull(),
        ];
    }

    /**
     * Bloqueia salvar um prazo onde a soma dos percentuais das parcelas é 0% — isso
     * não tem sentido de negócio (diferente de somar mais/menos que 100%, que é válido
     * pra parcelamento com juros embutido ou entrada+parcelas desiguais). Evita
     * cadastrar por engano um prazo "vazio" que geraria parcelas de R$ 0,00 nas compras.
     *
     * Usado fora do CRUD completo (ex.: createOptionUsing() do botão "+" na confirmação
     * de compra), onde o Repeater não é ->relationship() e $data['parcelas'] chega
     * intacto. Pro CRUD completo (Repeater ->relationship()), use regraSomaPercentuais().
     *
     * @param  array<int, array{parcela_percentual?: float|string|null}>  $parcelas
     */
    public static function validarSomaPercentuais(array $parcelas, string $errorKey = 'data.parcelas'): void
    {
        if (self::somaPercentuais($parcelas) <= 0) {
            throw ValidationException::withMessages([
                $errorKey => self::mensagemSomaZero(),
            ]);
        }
    }

    /**
     * Mesma regra que validarSomaPercentuais(), como Closure de validação de campo —
     * pro Repeater ->relationship() do CRUD completo, onde 'parcelas' já não está mais
     * em $data no momento de mutateFormDataBeforeCreate/Save (ver comentário no rule()).
     */
    private static function regraSomaPercentuais(): Closure
    {
        return function (): Closure {
            return function (string $attribute, mixed $value, Closure $fail): void {
                if (self::somaPercentuais(is_array($value) ? $value : []) <= 0) {
                    $fail(self::mensagemSomaZero());
                }
            };
        };
    }

    private static function somaPercentuais(array $parcelas): float
    {
        return collect($parcelas)->sum(fn (array $p): float => (float) ($p['parcela_percentual'] ?? 0));
    }

    private static function mensagemSomaZero(): string
    {
        return 'A soma dos percentuais das parcelas não pode ser 0%. Preencha ao menos uma parcela com percentual maior que zero.';
    }

    /** Campos de uma linha de parcela (dias + percentual), reaproveitados no CRUD completo e no cadastro rápido. */
    private static function parcelasSchema(): array
    {
        return [
            TextInput::make('parcela_dias')
                ->label('Dias')
                ->numeric()
                ->minValue(0)
                ->required(),
            TextInput::make('parcela_percentual')
                ->label('Percentual')
                ->numeric()
                ->minValue(0)
                ->step(0.0001)
                ->suffix('%')
                ->required(),
        ];
    }
}
