<?php

namespace App\Filament\Pages;

use App\Enums\TipoDescontoEnum;
use App\Filament\Components\ContaFinanceiraSelect;
use App\Models\Empresa;
use App\Models\PlanoDespesa;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Página singleton (mesmo padrão de ConfiguracaoPedidos) com os tetos do cupom
 * criado pelo influenciador, a comissão padrão e o plano/conta do financeiro.
 * A permission view:configuracao_influenciadores não é concedida a nenhuma
 * role: só o Admin (super_admin via Gate) abre a página.
 */
class ConfiguracaoInfluenciadores extends Page
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = 'Influenciadores';

    protected static UnitEnum|string|null $navigationGroup = 'Configurações';

    protected static ?string $title = 'Configurações do programa de influenciadores';

    protected static ?string $slug = 'configuracao-influenciadores';

    private const CAMPOS = [
        'empresa_influ_teto_percentual',
        'empresa_influ_teto_valor_fixo',
        'empresa_influ_teto_usos_total',
        'empresa_influ_comissao_tipo',
        'empresa_influ_comissao_valor',
        'empresa_influ_plano_despesa_id',
        'empresa_influ_conta_pix_id',
    ];

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(Empresa::firstOrFail()->only(self::CAMPOS));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Teto do cupom do influenciador')
                    ->description('Limites que o influenciador não ultrapassa ao criar o próprio cupom. Cupons criados pela equipe (internos) não têm teto.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('empresa_influ_teto_percentual')
                            ->label('Desconto percentual máximo')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->suffix('%')
                            ->required(),
                        TextInput::make('empresa_influ_teto_valor_fixo')
                            ->label('Desconto em valor fixo máximo')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->prefix('R$')
                            ->required(),
                        TextInput::make('empresa_influ_teto_usos_total')
                            ->label('Limite de usos por cupom')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->helperText('Vazio = sem teto de usos.'),
                    ]),

                Section::make('Comissão padrão')
                    ->description('Aplicada na aprovação do influenciador quando a comissão dele estiver zerada.')
                    ->columns(2)
                    ->schema([
                        Select::make('empresa_influ_comissao_tipo')
                            ->label('Tipo')
                            ->options([
                                TipoDescontoEnum::PERCENTUAL->value => TipoDescontoEnum::PERCENTUAL->label(),
                                TipoDescontoEnum::VALOR_FIXO->value => TipoDescontoEnum::VALOR_FIXO->label(),
                            ])
                            ->native(false)
                            ->required(),
                        TextInput::make('empresa_influ_comissao_valor')
                            ->label('Valor')
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->required(),
                    ]),

                Section::make('Financeiro')
                    ->description('Classificam o pagamento de comissão (despesa de marketing) e a conta de onde sai o Pix.')
                    ->columns(2)
                    ->schema([
                        Select::make('empresa_influ_plano_despesa_id')
                            ->label('Plano de despesa: comissões de influenciadores')
                            ->options(fn (): array => PlanoDespesa::query()->whereNotNull('pai_id')->orderBy('nome')->pluck('nome', 'id')->all())
                            ->searchable(),
                        Select::make('empresa_influ_conta_pix_id')
                            ->label('Conta de saída do Pix')
                            ->options(fn (): array => ContaFinanceiraSelect::opcoes(semCaixaPdv: true))
                            ->searchable(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        Empresa::firstOrFail()->update([
            'empresa_influ_teto_percentual' => (float) $state['empresa_influ_teto_percentual'],
            'empresa_influ_teto_valor_fixo' => (float) $state['empresa_influ_teto_valor_fixo'],
            'empresa_influ_teto_usos_total' => filled($state['empresa_influ_teto_usos_total']) ? (int) $state['empresa_influ_teto_usos_total'] : null,
            'empresa_influ_comissao_tipo' => $state['empresa_influ_comissao_tipo'],
            'empresa_influ_comissao_valor' => (float) $state['empresa_influ_comissao_valor'],
            'empresa_influ_plano_despesa_id' => $state['empresa_influ_plano_despesa_id'] ?: null,
            'empresa_influ_conta_pix_id' => $state['empresa_influ_conta_pix_id'] ?: null,
        ]);

        Notification::make()
            ->title('Configuração salva')
            ->success()
            ->send();
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Salvar')
                ->submit('save'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make($this->getFormActions())
                        ->alignment(Alignment::Start)
                        ->key('form-actions'),
                ]),
        ]);
    }
}
