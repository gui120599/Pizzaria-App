<?php

namespace App\Filament\Garcom\Pages;

use App\Exceptions\AutorizacaoNegadaException;
use App\Models\User;
use App\Services\Garcom\PlantaoFreelancerService;
use App\Services\Push\DispositivoPushService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * O gerente marca quais garçons freelancers estão trabalhando hoje. Quem está
 * marcado conta como "em turno" para os avisos do salão (push) até a virada do
 * dia operacional, sem bater ponto.
 */
class PlantaoFreelancers extends Page
{
    protected string $view = 'filament.garcom.pages.plantao-freelancers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Plantão';

    protected static ?string $title = 'Plantão de freelancers';

    protected static ?string $slug = 'plantao';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return DispositivoPushService::ativo() && (Auth::user()?->ehGerencia() ?? false);
    }

    /**
     * @return Collection<int, array{id: int, nome: string, trabalhando: bool, recebe_notificacoes: bool}>
     */
    #[Computed]
    public function freelancers(): Collection
    {
        return app(PlantaoFreelancerService::class)->freelancers();
    }

    public function viradaDoDia(): string
    {
        return (string) config('operacao.virada_dia', '05:00');
    }

    public function alternar(int $usuarioId, PlantaoFreelancerService $plantao): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var User $gerente */
        $gerente = Auth::user();
        $freelancer = User::query()->where('user_freelancer', true)->findOrFail($usuarioId);
        $nome = $freelancer->name_first ?: $freelancer->name;

        try {
            if (in_array($freelancer->id, $plantao->marcadosHoje(), true)) {
                $plantao->desmarcar($freelancer, $gerente);
                Notification::make()->title("{$nome} fora do plantão de hoje")->send();
            } else {
                $plantao->marcar($freelancer, $gerente);
                Notification::make()->success()->title("{$nome} no plantão de hoje")->send();
            }
        } catch (AutorizacaoNegadaException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }

        unset($this->freelancers);
    }
}
