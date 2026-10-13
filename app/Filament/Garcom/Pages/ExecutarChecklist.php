<?php

namespace App\Filament\Garcom\Pages;

use App\Enums\Checklist\TipoResposta;
use App\Exceptions\ChecklistException;
use App\Exceptions\ChecklistFinalizacaoException;
use App\Models\ChecklistAnexo;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistExecucaoItem;
use App\Models\ChecklistNaoConformidade;
use App\Services\Checklist\ChecklistExecucaoService;
use App\Services\Checklist\ChecklistsDoDiaService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Execução do checklist no celular. Cada resposta é salva na hora
 * (autosave) pelo ChecklistExecucaoService — este componente só orquestra.
 */
class ExecutarChecklist extends Page
{
    use WithFileUploads;

    protected string $view = 'filament.garcom.pages.executar-checklist';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'checklists/{execucao}';

    #[Locked]
    public int $execucaoId;

    /**
     * Respostas digitadas (número, temperatura, texto), por id do item.
     *
     * @var array<int|string, mixed>
     */
    public array $respostas = [];

    /** @var array<int|string, ?string> */
    public array $observacoes = [];

    /** @var array<int|string, TemporaryUploadedFile|null> */
    public array $fotos = [];

    public ?string $observacaoGeral = null;

    /**
     * Pendências da última tentativa de finalizar, por id do item.
     *
     * @var array<int|string, list<string>>
     */
    public array $erros = [];

    public function mount(int|string $execucao): void
    {
        $registro = ChecklistExecucao::findOrFail($execucao);

        abort_unless(Gate::allows('executar', $registro) || Gate::allows('view', $registro), 403);

        $this->execucaoId = $registro->id;
        $this->observacaoGeral = $registro->observacao_geral;

        foreach ($registro->itens as $item) {
            $this->respostas[$item->id] = match ($item->tipo_resposta) {
                TipoResposta::Numero, TipoResposta::Temperatura => $item->valor_numero,
                TipoResposta::Texto => $item->valor_texto,
                default => null,
            };
            $this->observacoes[$item->id] = $item->observacao;
        }
    }

    public function getTitle(): string|Htmlable
    {
        return $this->execucao->modelo_nome;
    }

    #[Computed]
    public function execucao(): ChecklistExecucao
    {
        return ChecklistExecucao::with(['itens.anexos', 'itens.respondidoPor', 'modelo'])->findOrFail($this->execucaoId);
    }

    /** @return Collection<string, Collection<int, ChecklistExecucaoItem>> */
    #[Computed]
    public function itensPorSecao(): Collection
    {
        return $this->execucao->itens->groupBy(fn (ChecklistExecucaoItem $item): string => $item->secao_titulo ?? 'Itens');
    }

    #[Computed]
    public function podeResponder(): bool
    {
        return $this->execucao->status->aceitaRespostas() && Gate::allows('executar', $this->execucao);
    }

    /** @return array{fechamento: ?ChecklistExecucao, naoConformidades: \Illuminate\Database\Eloquent\Collection<int, ChecklistNaoConformidade>, recados: list<string>} */
    #[Computed]
    public function pendenciasDoFechamento(): array
    {
        return app(ChecklistsDoDiaService::class)->pendenciasDoUltimoFechamento($this->execucao);
    }

    /** Resposta por toque (check, sim/não, seleção). */
    public function responder(int $itemId, mixed $valor): void
    {
        $this->gravar($itemId, fn (ChecklistExecucaoItem $item) => app(ChecklistExecucaoService::class)
            ->responder($item, ['valor' => $valor], Auth::user()), "respostas.{$itemId}");
    }

    /** Número, temperatura e texto (wire:model.blur). */
    public function updatedRespostas(mixed $valor, int|string $itemId): void
    {
        $this->responder((int) $itemId, $valor);
    }

    public function updatedObservacoes(mixed $valor, int|string $itemId): void
    {
        $this->gravar((int) $itemId, fn (ChecklistExecucaoItem $item) => app(ChecklistExecucaoService::class)
            ->responder($item, ['observacao' => $valor], Auth::user()), "observacoes.{$itemId}");
    }

    /** Foto já comprimida no navegador (ver a view) e enviada por $wire.upload(). */
    public function updatedFotos(mixed $arquivo, int|string $itemId): void
    {
        $itemId = (int) $itemId;

        if ($arquivo instanceof TemporaryUploadedFile) {
            $this->gravar($itemId, fn (ChecklistExecucaoItem $item) => app(ChecklistExecucaoService::class)
                ->anexarFoto($item, $arquivo, Auth::user()), "fotos.{$itemId}");
        }

        unset($this->fotos[$itemId]);
    }

    public function removerFoto(int $anexoId): void
    {
        $anexo = ChecklistAnexo::whereKey($anexoId)
            ->whereHas('execucaoItem', fn ($itens) => $itens->where('checklist_execucao_id', $this->execucaoId))
            ->firstOrFail();

        $this->gravar($anexo->checklist_execucao_item_id, fn () => app(ChecklistExecucaoService::class)
            ->removerAnexo($anexo, Auth::user()), "fotos.{$anexo->checklist_execucao_item_id}");
    }

    public function finalizar(): void
    {
        try {
            app(ChecklistExecucaoService::class)->finalizar($this->execucao, Auth::user(), $this->observacaoGeral);
        } catch (ChecklistFinalizacaoException $e) {
            $this->erros = $e->erros;
            $this->dispatch('checklist-rolar-para', itemId: $e->primeiroItemId());

            Notification::make()
                ->title('Faltam '.count($e->erros).' item(ns) para finalizar')
                ->warning()
                ->send();

            return;
        } catch (ChecklistException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        } catch (ValidationException $e) {
            $this->addError('observacaoGeral', collect($e->errors())->flatten()->first());

            return;
        }

        $this->erros = [];
        unset($this->execucao, $this->podeResponder);

        Notification::make()->title('Checklist finalizado')->success()->send();
    }

    /** Execução reprovada pela gerência volta a aceitar respostas. */
    public function reabrir(): void
    {
        try {
            app(ChecklistExecucaoService::class)->reabrir($this->execucao, Auth::user());
        } catch (ChecklistException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        unset($this->execucao, $this->podeResponder);
    }

    /** Executa a gravação do item e traduz os erros para a tela. */
    private function gravar(int $itemId, callable $gravacao, string $campo): void
    {
        $item = $this->execucao->itens->firstWhere('id', $itemId);
        abort_if($item === null, 404);

        $this->resetErrorBag($campo);

        try {
            $gravacao($item);
        } catch (ValidationException $e) {
            $this->addError($campo, collect($e->errors())->flatten()->first());

            return;
        } catch (ChecklistException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        } finally {
            unset($this->execucao, $this->itensPorSecao, $this->podeResponder);
        }

        unset($this->erros[$itemId]);
    }
}
