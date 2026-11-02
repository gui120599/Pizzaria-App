<?php

namespace App\Filament\Pages;

use App\Console\Commands\LembrarPendentesPush;
use App\Enums\DestinoAvisoMesaEnum;
use App\Models\Empresa;
use App\Services\Push\DispositivoPushService;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Leandrocfe\FilamentPtbrFormFields\Cep;
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
            'empresa_endereco_rua' => $empresa->empresa_endereco_rua,
            'empresa_endereco_numero_endereco' => $empresa->empresa_endereco_numero_endereco,
            'empresa_endereco_complemento_endereco' => $empresa->empresa_endereco_complemento_endereco,
            'empresa_endereco_bairro' => $empresa->empresa_endereco_bairro,
            'empresa_endereco_cep' => $empresa->empresa_endereco_cep,
            'empresa_google_maps_url' => $empresa->empresa_google_maps_url,
            'empresa_push_destino_mesa' => $empresa->empresa_push_destino_mesa?->value ?? DestinoAvisoMesaEnum::GARCOM_DA_MESA->value,
            'empresa_push_repetir_ate_atender' => (bool) $empresa->empresa_push_repetir_ate_atender,
            'empresa_push_repetir_intervalo_min' => $empresa->empresa_push_repetir_intervalo_min,
            'empresa_push_repetir_max' => $empresa->empresa_push_repetir_max,
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
                Section::make('Endereço da loja')
                    ->description('Aparece em "Onde retirar" na página de acompanhamento dos pedidos de retirada. Cidade e UF seguem no cadastro da empresa.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('empresa_endereco_rua')
                            ->label('Rua')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('empresa_endereco_numero_endereco')
                            ->label('Número')
                            ->maxLength(255),
                        TextInput::make('empresa_endereco_complemento_endereco')
                            ->label('Complemento')
                            ->maxLength(255),
                        TextInput::make('empresa_endereco_bairro')
                            ->label('Bairro')
                            ->maxLength(255),
                        Cep::make('empresa_endereco_cep')
                            ->label('CEP'),
                        TextInput::make('empresa_google_maps_url')
                            ->label('Link da loja no Google Maps')
                            ->url()
                            ->maxLength(500)
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (filled($value) && ! Empresa::urlGoogleMapsValida($value)) {
                                    $fail('Use um link https do Google Maps (google.com, maps.google.com ou maps.app.goo.gl).');
                                }
                            })
                            ->helperText('No Google Maps, abra a loja › Compartilhar › Copiar link. Sem o link, o botão "Como chegar" busca pelo endereço acima.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Notificações do salão')
                    ->description('Avisos no celular dos garçons (chamou, pediu a conta, pedido para aprovar, pedido pronto, quer abrir a mesa). Quem tem o aviso ativado no aparelho recebe mesmo com o painel fechado.')
                    ->visible(fn (): bool => DispositivoPushService::ativo())
                    ->columns(2)
                    ->schema([
                        Text::make('O agendador (cron schedule:run) não rodou nos últimos 5 minutos. O primeiro aviso funciona, mas a repetição e o reenvio de falhas ficam parados até o cron voltar.')
                            ->color('danger')
                            ->visible(fn (): bool => ! LembrarPendentesPush::agendadorAtivo())
                            ->columnSpanFull(),
                        Select::make('empresa_push_destino_mesa')
                            ->label('Quem recebe os avisos da mesa')
                            ->options(DestinoAvisoMesaEnum::opcoes())
                            ->native(false)
                            ->required()
                            ->helperText('O garçom que abriu a mesa sempre recebe. "Quer abrir a mesa" vai sempre para todos com ponto aberto. O ponto aberto vem da folha de ponto: quem não tem cadastro de colaborador não conta como em turno.')
                            ->columnSpanFull(),
                        Toggle::make('empresa_push_repetir_ate_atender')
                            ->label('Repetir o aviso até o chamado ser atendido')
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('empresa_push_repetir_intervalo_min')
                            ->label('Repetir a cada')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(30)
                            ->suffix('minutos')
                            ->required(fn (Get $get): bool => (bool) $get('empresa_push_repetir_ate_atender'))
                            ->visible(fn (Get $get): bool => (bool) $get('empresa_push_repetir_ate_atender')),
                        TextInput::make('empresa_push_repetir_max')
                            ->label('Parar depois de')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(60)
                            ->suffix('repetições')
                            ->required(fn (Get $get): bool => (bool) $get('empresa_push_repetir_ate_atender'))
                            ->visible(fn (Get $get): bool => (bool) $get('empresa_push_repetir_ate_atender'))
                            ->helperText('Limite de segurança: evita repetir a noite toda um chamado esquecido sem "Atendido".'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $push = DispositivoPushService::ativo() ? [
            'empresa_push_destino_mesa' => $state['empresa_push_destino_mesa'],
            'empresa_push_repetir_ate_atender' => (bool) $state['empresa_push_repetir_ate_atender'],
            ...($state['empresa_push_repetir_ate_atender'] ? [
                'empresa_push_repetir_intervalo_min' => (int) $state['empresa_push_repetir_intervalo_min'],
                'empresa_push_repetir_max' => (int) $state['empresa_push_repetir_max'],
            ] : []),
        ] : [];

        Empresa::firstOrFail()->update([
            ...$push,
            'empresa_validade_link_ticket_horas' => (int) $state['empresa_validade_link_ticket_horas'],
            'empresa_endereco_rua' => $state['empresa_endereco_rua'] ?: null,
            'empresa_endereco_numero_endereco' => $state['empresa_endereco_numero_endereco'] ?: null,
            'empresa_endereco_complemento_endereco' => $state['empresa_endereco_complemento_endereco'] ?: null,
            'empresa_endereco_bairro' => $state['empresa_endereco_bairro'] ?: null,
            'empresa_endereco_cep' => $state['empresa_endereco_cep'] ?: null,
            'empresa_google_maps_url' => $state['empresa_google_maps_url'] ?: null,
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
