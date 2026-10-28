<?php

namespace App\Filament\Pages;

use App\Models\Influenciador;
use App\Models\RegulamentoInfluenciador;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Publica uma nova versão do regulamento do programa de influenciadores.
 * Cada publicação é uma versão nova; quem aceitou uma versão anterior precisa
 * aceitar a nova antes de usar o painel. A permission
 * view:regulamento_influenciadores não é concedida a nenhuma role: só o Admin.
 */
class RegulamentoInfluenciadores extends Page
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Regulamento de influenciadores';

    protected static UnitEnum|string|null $navigationGroup = 'Configurações';

    protected static ?string $title = 'Regulamento do programa de influenciadores';

    protected static ?string $slug = 'regulamento-influenciadores';

    protected string $view = 'filament.pages.regulamento-influenciadores';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['texto' => RegulamentoInfluenciador::atual()?->ri_texto]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Texto do regulamento')
                    ->description('Texto interno, exibido no autocadastro e na tela de aceite. Publicar cria uma versão nova.')
                    ->schema([
                        Textarea::make('texto')
                            ->label('Texto')
                            ->required()
                            ->rows(18)
                            ->minLength(20),
                    ]),
            ])
            ->statePath('data');
    }

    public function publicar(): void
    {
        $texto = trim((string) $this->form->getState()['texto']);

        $versao = DB::transaction(function () use ($texto): int {
            $proxima = (int) RegulamentoInfluenciador::query()->lockForUpdate()->max('ri_versao') + 1;

            RegulamentoInfluenciador::create([
                'ri_versao' => $proxima,
                'ri_texto' => $texto,
                'ri_publicado_em' => now(),
                'ri_user_id' => auth()->id(),
            ]);

            return $proxima;
        });

        Notification::make()
            ->title("Versão {$versao} publicada")
            ->body('Os influenciadores ativos precisarão aceitar a nova versão no próximo acesso.')
            ->success()
            ->send();
    }

    /** @return Collection<int, array{versao: int, publicado_em: string, aceites: int}> */
    public function versoes(): Collection
    {
        return RegulamentoInfluenciador::query()->orderByDesc('ri_versao')->get()->map(fn (RegulamentoInfluenciador $ri): array => [
            'versao' => $ri->ri_versao,
            'publicado_em' => $ri->ri_publicado_em->format('d/m/Y H:i'),
            'aceites' => Influenciador::where('influenciador_regulamento_versao', $ri->ri_versao)->count(),
        ]);
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [
            Action::make('publicar')
                ->label('Publicar nova versão')
                ->requiresConfirmation()
                ->modalDescription('Quem já usa o painel terá de aceitar a nova versão antes de continuar.')
                ->action(fn () => $this->publicar()),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->footer([
                    Actions::make($this->getFormActions())
                        ->alignment(Alignment::Start)
                        ->key('form-actions'),
                ]),
        ]);
    }
}
