<?php

namespace App\Filament\Pages;

use App\Models\Empresa;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
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
 * Página singleton (mesmo padrão de ConfiguracaoSefaz) para as opções de
 * pedido que só o Admin altera. Opera sobre Empresa::firstOrFail().
 *
 * A permission view:configuracao_pedidos não é concedida a nenhuma role: só o
 * Admin (super_admin via Gate) abre a página.
 */
class ConfiguracaoPedidos extends Page
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Pedidos';

    protected static UnitEnum|string|null $navigationGroup = 'Configurações';

    protected static ?string $title = 'Configurações de pedidos';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $empresa = Empresa::firstOrFail();

        $this->form->fill([
            'empresa_validade_link_ticket_horas' => $empresa->empresa_validade_link_ticket_horas,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ticket de impressão')
                    ->columns(1)
                    ->schema([
                        TextInput::make('empresa_validade_link_ticket_horas')
                            ->label('Validade do link do ticket (horas)')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(720)
                            ->required()
                            ->suffix('horas')
                            ->helperText('O ticket impresso ou compartilhado por link só abre dentro deste prazo. Tickets já impressos ou compartilhados deixam de abrir depois dele. Quem está logado no sistema abre o ticket sem depender do prazo.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        Empresa::firstOrFail()->update([
            'empresa_validade_link_ticket_horas' => (int) $state['empresa_validade_link_ticket_horas'],
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
