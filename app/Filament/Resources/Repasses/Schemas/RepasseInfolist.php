<?php

namespace App\Filament\Resources\Repasses\Schemas;

use App\Enums\Ponto\TipoItemRepasse;
use App\Filament\Resources\Lancamentos\LancamentoResource;
use App\Models\Repasse;
use App\Models\RepasseItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RepasseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Resumo')
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('colaborador.nome')->label('Colaborador'),
                    TextEntry::make('periodo')->label('Período')->state(fn (Repasse $record): string => $record->periodo->rotulo()),
                    TextEntry::make('status')->label('Situação')->badge(),
                    TextEntry::make('colaborador.vinculo')->label('Vínculo')->badge(),
                    TextEntry::make('total_proventos')->label('Proventos')->money('BRL'),
                    TextEntry::make('total_descontos')->label('Descontos')->money('BRL'),
                    TextEntry::make('valor_liquido')->label('Líquido')->money('BRL')->weight('bold')
                        ->color(fn (Repasse $record): string => (float) $record->valor_liquido < 0 ? 'danger' : 'success'),
                    TextEntry::make('ciente_em')->label('Ciência do colaborador')->dateTime('d/m/Y H:i')->placeholder('Ainda não'),
                ]),
            Section::make('Jornada do período')
                ->columns(5)
                ->columnSpanFull()
                ->schema(collect([
                    'dias_trabalhados' => ['Dias trabalhados', false],
                    'trabalhado' => ['Horas trabalhadas', true],
                    'extra_50' => ['Extra 50%', true],
                    'extra_100' => ['Extra 100%', true],
                    'noturno' => ['Noturno (relógio)', true],
                    'atraso' => ['Atrasos', true],
                    'faltas' => ['Faltas', false],
                    'entregas' => ['Entregas', false],
                ])->map(fn (array $config, string $chave): TextEntry => TextEntry::make("resumo_jornada.{$chave}")
                    ->label($config[0])
                    ->state(fn (Repasse $record): string => self::formatar((int) ($record->resumo_jornada[$chave] ?? 0), $config[1])))
                    ->values()
                    ->all()),
            Section::make('Itens')
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('itens')
                        ->hiddenLabel()
                        ->columns(5)
                        ->schema([
                            TextEntry::make('natureza')->label('Item')->badge(),
                            TextEntry::make('descricao')->label('Descrição')->columnSpan(2),
                            TextEntry::make('automatico')->label('Origem')->formatStateUsing(fn (bool $state): string => $state ? 'Automático' : 'Manual'),
                            TextEntry::make('valor')->label('Valor')
                                ->state(fn (RepasseItem $record): string => ($record->tipo === TipoItemRepasse::Desconto ? '− ' : '+ ').'R$ '.number_format((float) $record->valor, 2, ',', '.'))
                                ->color(fn (RepasseItem $record): string => $record->tipo->getColor()),
                        ]),
                ]),
            Section::make('Contas a pagar')
                ->columns(3)
                ->columnSpanFull()
                ->visible(fn (Repasse $record): bool => $record->lancamento !== null)
                ->schema([
                    TextEntry::make('lancamento.descricao')->label('Título')
                        ->url(fn (Repasse $record): ?string => $record->lancamento ? LancamentoResource::getUrl('edit', ['record' => $record->lancamento]) : null),
                    TextEntry::make('lancamento.status')->label('Situação no financeiro')->badge(),
                    TextEntry::make('lancamento.vencimento')->label('Vencimento')->date('d/m/Y'),
                ]),
            Section::make('Observação')
                ->columnSpanFull()
                ->visible(fn (Repasse $record): bool => filled($record->observacao))
                ->schema([TextEntry::make('observacao')->hiddenLabel()]),
        ]);
    }

    private static function formatar(int $valor, bool $minutos): string
    {
        return $minutos ? sprintf('%d:%02d', intdiv($valor, 60), $valor % 60) : (string) $valor;
    }
}
