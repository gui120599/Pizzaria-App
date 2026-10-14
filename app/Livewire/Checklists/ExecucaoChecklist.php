<?php

namespace App\Livewire\Checklists;

use App\Enums\Checklist\TipoResposta;
use App\Exceptions\ChecklistException;
use App\Exceptions\ChecklistFinalizacaoException;
use App\Models\ChecklistAnexo;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistExecucaoItem;
use App\Models\ChecklistNaoConformidade;
use App\Models\User;
use App\Services\Checklist\ChecklistExecucaoService;
use App\Services\Checklist\ChecklistsDoDiaService;
use App\Support\Cozinha\Operador;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Execução de um checklist, compartilhada pelos painéis garçom (layout
 * "celular") e cozinha (layout "tablet"). Cada resposta é salva na hora
 * (autosave) pelo ChecklistExecucaoService — este componente só orquestra.
 * Com operador (tablet compartilhado), valem os papéis da pessoa, não os da
 * conta logada.
 */
class ExecucaoChecklist extends Component
{
    use WithFileUploads;

    public const LAYOUT_CELULAR = 'celular';

    public const LAYOUT_TABLET = 'tablet';

    #[Locked]
    public int $execucaoId;

    #[Locked]
    public string $layout = self::LAYOUT_CELULAR;

    #[Locked]
    public ?Operador $operador = null;

    /** Destino do "Voltar aos checklists" (a lista do painel que hospeda). */
    #[Locked]
    public string $urlLista;

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

    /** As props (execucaoId, urlLista, layout, operador) o Livewire atribui antes do mount. */
    public function mount(): void
    {
        if (! in_array($this->layout, [self::LAYOUT_CELULAR, self::LAYOUT_TABLET], true)) {
            throw new InvalidArgumentException("Layout de checklist desconhecido: {$this->layout}.");
        }

        $execucao = $this->execucao;

        abort_unless($this->podeExecutar || Gate::allows('view', $execucao), 403);

        $this->observacaoGeral = $execucao->observacao_geral;

        foreach ($execucao->itens as $item) {
            $this->respostas[$item->id] = match ($item->tipo_resposta) {
                TipoResposta::Numero, TipoResposta::Temperatura => $item->valor_numero,
                TipoResposta::Texto => $item->valor_texto,
                default => null,
            };
            $this->observacoes[$item->id] = $item->observacao;
        }
    }

    public function render(): View
    {
        return view('livewire.checklists.execucao-checklist');
    }

    #[Computed]
    public function execucao(): ChecklistExecucao
    {
        return ChecklistExecucao::with(['itens.anexos', 'itens.respondidoPor', 'modelo', 'concluidoPor'])->findOrFail($this->execucaoId);
    }

    /** @return Collection<string, Collection<int, ChecklistExecucaoItem>> */
    #[Computed]
    public function itensPorSecao(): Collection
    {
        return $this->execucao->itens->groupBy(fn (ChecklistExecucaoItem $item): string => $item->secao_titulo ?? 'Itens');
    }

    /** Quem executa pode responder, finalizar e corrigir após reprovação. */
    #[Computed]
    public function podeExecutar(): bool
    {
        $executor = $this->executor();

        return $executor !== null && Gate::forUser($executor)->allows('executar', $this->execucao);
    }

    #[Computed]
    public function podeResponder(): bool
    {
        return $this->execucao->status->aceitaRespostas() && $this->podeExecutar;
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
            ->responder($item, ['valor' => $valor], Auth::user(), $this->operador), "respostas.{$itemId}");
    }

    /** Número, temperatura e texto (wire:model.blur). */
    public function updatedRespostas(mixed $valor, int|string $itemId): void
    {
        $this->responder((int) $itemId, $valor);
    }

    public function updatedObservacoes(mixed $valor, int|string $itemId): void
    {
        $this->gravar((int) $itemId, fn (ChecklistExecucaoItem $item) => app(ChecklistExecucaoService::class)
            ->responder($item, ['observacao' => $valor], Auth::user(), $this->operador), "observacoes.{$itemId}");
    }

    /** Foto já comprimida no navegador (ver a view) e enviada por $wire.upload(). */
    public function updatedFotos(mixed $arquivo, int|string $itemId): void
    {
        $itemId = (int) $itemId;

        if ($arquivo instanceof TemporaryUploadedFile) {
            $this->gravar($itemId, fn (ChecklistExecucaoItem $item) => app(ChecklistExecucaoService::class)
                ->anexarFoto($item, $arquivo, Auth::user(), $this->operador), "fotos.{$itemId}");
        }

        unset($this->fotos[$itemId]);
    }

    public function removerFoto(int $anexoId): void
    {
        $anexo = ChecklistAnexo::whereKey($anexoId)
            ->whereHas('execucaoItem', fn ($itens) => $itens->where('checklist_execucao_id', $this->execucaoId))
            ->firstOrFail();

        $this->gravar($anexo->checklist_execucao_item_id, fn () => app(ChecklistExecucaoService::class)
            ->removerAnexo($anexo, Auth::user(), $this->operador), "fotos.{$anexo->checklist_execucao_item_id}");
    }

    public function finalizar(): void
    {
        try {
            app(ChecklistExecucaoService::class)->finalizar($this->execucao, Auth::user(), $this->observacaoGeral, $this->operador);
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
        $this->esquecerExecucao();

        Notification::make()->title('Checklist finalizado')->success()->send();
    }

    /** Execução reprovada pela gerência volta a aceitar respostas. */
    public function reabrir(): void
    {
        try {
            app(ChecklistExecucaoService::class)->reabrir($this->execucao, Auth::user(), $this->operador);
        } catch (ChecklistException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->esquecerExecucao();
    }

    private function executor(): ?User
    {
        return $this->operador === null ? Auth::user() : $this->operador->usuario();
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
            $this->esquecerExecucao();
        }

        unset($this->erros[$itemId]);
    }

    private function esquecerExecucao(): void
    {
        unset($this->execucao, $this->itensPorSecao, $this->podeExecutar, $this->podeResponder);
    }
}
