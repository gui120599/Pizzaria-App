<?php

namespace App\Filament\Resources\ChecklistModelos\Schemas;

use App\Enums\Checklist\ChecklistSetor;
use App\Enums\Checklist\ChecklistTipo;
use App\Enums\Checklist\ExigenciaFoto;
use App\Enums\Checklist\TipoResposta;
use App\Models\ChecklistModelo;
use App\Models\SetorProducao;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ChecklistModeloForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificação')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(120),
                    Toggle::make('ativo')
                        ->label('Ativo')
                        ->default(true)
                        ->inline(false),
                    Select::make('tipo')
                        ->label('Tipo')
                        ->options(ChecklistTipo::class)
                        ->default(ChecklistTipo::Abertura)
                        ->required(),
                    Select::make('setor')
                        ->label('Setor')
                        ->options(ChecklistSetor::class)
                        ->default(ChecklistSetor::Geral)
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state): void {
                            if (! in_array($state instanceof ChecklistSetor ? $state->value : (string) $state, ChecklistModelo::setoresDaCozinha(), true)) {
                                $set('setor_producao_id', null);
                            }
                        })
                        ->required(),
                    Select::make('setor_producao_id')
                        ->label('Tablet / setor de produção')
                        ->options(fn (): array => SetorProducao::ativos()->ordenados()->pluck('nome', 'id')->all())
                        ->placeholder('Todos os tablets da cozinha')
                        ->helperText('Vazio = aparece em todos os tablets da cozinha. Escolhido, só nos tablets desse setor.')
                        ->visible(fn (Get $get): bool => in_array(
                            $get('setor') instanceof ChecklistSetor ? $get('setor')->value : (string) $get('setor'),
                            ChecklistModelo::setoresDaCozinha(),
                            true,
                        ))
                        // Escondido (setor fora da cozinha) grava null: senão o setor antigo continuaria mandando o modelo para o tablet.
                        ->dehydratedWhenHidden(),
                    Textarea::make('descricao')
                        ->label('Descrição')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make('Agendamento')
                ->description('Sem dias marcados, o checklist fica sob demanda: não é gerado sozinho e não expira.')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    CheckboxList::make('dias_semana')
                        ->label('Dias da semana')
                        ->options(ChecklistModelo::DIAS_SEMANA)
                        ->columns(7)
                        ->bulkToggleable()
                        ->columnSpanFull(),
                    TimePicker::make('janela_inicio')
                        ->label('Janela: início')
                        ->seconds(false)
                        ->requiredWith('janela_fim'),
                    TimePicker::make('janela_fim')
                        ->label('Janela: fim')
                        ->seconds(false)
                        ->requiredWith('janela_inicio')
                        ->helperText('Pode passar da meia-noite (ex.: 22:30 às 01:30).'),
                    TextInput::make('tolerancia_minutos')
                        ->label('Tolerância após o fim')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(720)
                        ->suffix('min')
                        ->default(fn (): int => (int) config('checklists.tolerancia_padrao'))
                        ->required(),
                    Toggle::make('permite_multiplas_execucoes_dia')
                        ->label('Permite várias execuções no mesmo dia')
                        ->columnSpanFull(),
                ]),

            Section::make('Atribuição e controle')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('roles')
                        ->label('Papéis que executam')
                        ->relationship('roles', 'name', fn (Builder $query): Builder => $query->where('name', '!=', 'Cliente'))
                        ->multiple()
                        ->preload()
                        ->helperText('Admin e Gerente executam todos os checklists.'),
                    Toggle::make('exige_aprovacao')
                        ->label('Exige aprovação do gerente')
                        ->inline(false),
                ]),

            Section::make('Itens')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('secoes')
                        ->label('Seções')
                        ->relationship()
                        ->orderColumn('ordem')
                        ->reorderable()
                        ->collapsible()
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addActionLabel('Adicionar seção')
                        ->itemLabel(fn (array $state): ?string => $state['titulo'] ?? null)
                        ->schema([
                            TextInput::make('titulo')
                                ->label('Título da seção')
                                ->required()
                                ->maxLength(120),
                            Repeater::make('itens')
                                ->label('Itens')
                                ->relationship()
                                ->orderColumn('ordem')
                                ->reorderable()
                                ->collapsible()
                                ->collapsed()
                                ->minItems(1)
                                ->defaultItems(1)
                                ->addActionLabel('Adicionar item')
                                ->itemLabel(fn (array $state): string => self::rotuloDoItem($state))
                                ->columns(2)
                                ->schema(self::camposDoItem()),
                        ]),
                ]),
        ]);
    }

    /** @return array<int, mixed> */
    private static function camposDoItem(): array
    {
        return [
            TextInput::make('titulo')
                ->label('Item')
                ->required()
                ->maxLength(200)
                ->columnSpanFull(),
            Select::make('tipo_resposta')
                ->label('Tipo de resposta')
                ->options(TipoResposta::class)
                ->default(TipoResposta::Check)
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    if (self::tipo($state) === TipoResposta::Temperatura) {
                        $set('unidade', '°C');
                    }
                }),
            Select::make('exige_foto')
                ->label('Foto como evidência')
                ->options(ExigenciaFoto::class)
                ->default(ExigenciaFoto::Nunca)
                ->required()
                ->visible(fn (Get $get): bool => self::tipo($get('tipo_resposta')) !== TipoResposta::Foto),
            TextInput::make('valor_minimo')
                ->label('Valor mínimo')
                ->numeric()
                ->step(0.1)
                ->rules([fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    $maximo = $get('valor_maximo');

                    if (filled($value) && filled($maximo) && (float) $value > (float) $maximo) {
                        $fail('O mínimo não pode ser maior que o máximo.');
                    }
                }])
                ->visible(fn (Get $get): bool => (bool) self::tipo($get('tipo_resposta'))?->usaFaixa()),
            TextInput::make('valor_maximo')
                ->label('Valor máximo')
                ->numeric()
                ->step(0.1)
                ->visible(fn (Get $get): bool => (bool) self::tipo($get('tipo_resposta'))?->usaFaixa()),
            TextInput::make('unidade')
                ->label('Unidade')
                ->maxLength(10)
                ->placeholder('°C, kg, un, %')
                ->visible(fn (Get $get): bool => (bool) self::tipo($get('tipo_resposta'))?->usaFaixa()),
            Select::make('resposta_esperada')
                ->label('Resposta esperada')
                ->options(['sim' => 'Sim', 'nao' => 'Não'])
                ->placeholder('Qualquer resposta')
                ->visible(fn (Get $get): bool => self::tipo($get('tipo_resposta')) === TipoResposta::SimNao),
            Repeater::make('opcoes')
                ->label('Opções')
                ->minItems(2)
                ->defaultItems(2)
                ->addActionLabel('Adicionar opção')
                ->columns(2)
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => self::tipo($get('tipo_resposta')) === TipoResposta::Selecao)
                ->schema([
                    Hidden::make('valor'),
                    TextInput::make('rotulo')
                        ->label('Rótulo')
                        ->required()
                        ->maxLength(50)
                        ->distinct(),
                    Toggle::make('conforme')
                        ->label('Conforme')
                        ->default(true)
                        ->inline(false),
                ]),
            Textarea::make('instrucao')
                ->label('Instrução')
                ->rows(2)
                ->columnSpanFull(),
            Toggle::make('obrigatorio')
                ->label('Obrigatório')
                ->default(true),
            Toggle::make('critico')
                ->label('Crítico')
                ->helperText('Avisa a gerência na hora se ficar não conforme.')
                ->default(false),
            Toggle::make('exige_observacao_nao_conforme')
                ->label('Exige observação quando não conforme')
                ->default(true)
                ->visible(fn (Get $get): bool => ! in_array(self::tipo($get('tipo_resposta')), [TipoResposta::Texto, TipoResposta::Foto], true))
                ->columnSpanFull(),
        ];
    }

    /** O Select ligado ao enum pode entregar a instância ou o valor cru. */
    private static function tipo(mixed $estado): ?TipoResposta
    {
        return $estado instanceof TipoResposta ? $estado : TipoResposta::tryFrom((string) $estado);
    }

    /** @param  array<string, mixed>  $estado */
    private static function rotuloDoItem(array $estado): string
    {
        $titulo = filled($estado['titulo'] ?? null) ? $estado['titulo'] : 'Novo item';
        $marcas = array_filter([
            self::tipo($estado['tipo_resposta'] ?? null)?->getLabel(),
            ($estado['critico'] ?? false) ? 'crítico' : null,
        ]);

        return $marcas === [] ? $titulo : $titulo.' ('.implode(', ', $marcas).')';
    }
}
