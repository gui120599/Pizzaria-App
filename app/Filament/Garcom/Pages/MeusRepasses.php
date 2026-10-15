<?php

namespace App\Filament\Garcom\Pages;

use App\Enums\Ponto\StatusRepasse;
use App\Exceptions\PontoException;
use App\Filament\Garcom\Concerns\DoColaborador;
use App\Models\Repasse;
use App\Services\Ponto\RepasseService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * Repasses do próprio colaborador (spec §7): só aprovados/pagos, com os
 * itens, o recibo e o "Li e confere" (ciente_em/ciente_ip).
 */
class MeusRepasses extends Page
{
    use DoColaborador;

    protected string $view = 'filament.garcom.pages.meus-repasses';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Meus repasses';

    protected static ?string $title = 'Meus repasses';

    protected static ?string $slug = 'meus-repasses';

    protected static ?int $navigationSort = -8;

    public static function getNavigationBadge(): ?string
    {
        $colaborador = Auth::user()?->colaborador;

        if ($colaborador === null) {
            return null;
        }

        $semCiencia = $colaborador->repasses()
            ->whereIn('status', [StatusRepasse::Aprovado, StatusRepasse::Pago])
            ->whereNull('ciente_em')
            ->count();

        return $semCiencia > 0 ? (string) $semCiencia : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return Collection<int, Repasse> */
    #[Computed]
    public function repasses(): Collection
    {
        return $this->colaborador()->repasses()
            ->with(['periodo', 'itens', 'lancamento'])
            ->whereIn('status', [StatusRepasse::Aprovado, StatusRepasse::Pago])
            ->orderByDesc('aprovado_em')
            ->limit(24)
            ->get();
    }

    public function itensAction(): Action
    {
        return Action::make('itens')
            ->modalHeading(fn (array $arguments): string => 'Repasse '.$this->repasseDe($arguments)->periodo->rotulo())
            ->modalContent(fn (array $arguments) => view('filament.garcom.pages.partials.repasse-itens', ['repasse' => $this->repasseDe($arguments)]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar');
    }

    public function cienciaAction(): Action
    {
        return Action::make('ciencia')
            ->label('Li e confere')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->requiresConfirmation()
            ->modalHeading('Li e confere')
            ->modalDescription('Você confirma que conferiu os itens e o valor deste repasse. Fica registrado com data, hora e IP.')
            ->action(function (array $arguments, Action $action): void {
                try {
                    app(RepasseService::class)->darCiencia($this->repasseDe($arguments), Auth::user(), request()->ip());
                } catch (PontoException $e) {
                    Notification::make()->title('Ciência não registrada')->body($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Ciência registrada')->success()->send();
                unset($this->repasses);
            });
    }

    /** @param  array<string, mixed>  $arguments */
    private function repasseDe(array $arguments): Repasse
    {
        $repasse = Repasse::query()->with(['periodo', 'itens', 'colaborador'])->findOrFail($arguments['repasse'] ?? 0);

        abort_unless(Gate::allows('view', $repasse), 403);

        return $repasse;
    }
}
