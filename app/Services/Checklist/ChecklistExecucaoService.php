<?php

namespace App\Services\Checklist;

use App\Enums\Checklist\ChecklistSetor;
use App\Enums\Checklist\ChecklistTipo;
use App\Enums\Checklist\ExecucaoStatus;
use App\Enums\Checklist\ExigenciaFoto;
use App\Enums\Checklist\NaoConformidadeStatus;
use App\Enums\Checklist\TipoResposta;
use App\Exceptions\ChecklistException;
use App\Exceptions\ChecklistFinalizacaoException;
use App\Models\ChecklistAnexo;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistExecucaoItem;
use App\Models\ChecklistModelo;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Notifications\Checklist\ChecklistAguardandoAprovacaoNotification;
use App\Notifications\Checklist\ChecklistItemCriticoNotification;
use App\Notifications\Checklist\ChecklistNaoRealizadoNotification;
use App\Support\Cozinha\Operador;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ciclo de vida das execuções de checklist: geração diária, início,
 * respostas, fotos, finalização, aprovação e expiração. Toda escrita na
 * execução acontece com a linha travada (lockForUpdate) — vários membros da
 * equipe respondem a mesma execução ao mesmo tempo.
 */
class ChecklistExecucaoService
{
    private const MAX_TEXTO = 5000;

    public function __construct(private ConformidadeAvaliador $avaliador) {}

    /**
     * Cria as execuções pendentes do dia operacional informado para os modelos
     * ativos agendados naquele dia da semana. Idempotente.
     *
     * @return int quantidade de execuções criadas
     */
    public function gerarPendentesDoDia(CarbonInterface $dia): int
    {
        $dia = CarbonImmutable::instance($dia)->startOfDay();
        $criadas = 0;

        foreach (ChecklistModelo::ativos()->agendadosPara($dia)->get() as $modelo) {
            $criada = DB::transaction(function () use ($modelo, $dia): bool {
                ChecklistModelo::whereKey($modelo->id)->lockForUpdate()->first();

                $jaExiste = ChecklistExecucao::withTrashed()
                    ->where('checklist_modelo_id', $modelo->id)
                    ->whereDate('data_referencia', $dia->format('Y-m-d'))
                    ->exists();

                if ($jaExiste) {
                    return false;
                }

                $this->criarExecucao($modelo, $dia, ExecucaoStatus::Pendente);

                return true;
            });

            $criadas += (int) $criada;
        }

        return $criadas;
    }

    /**
     * Abre (ou retoma) a execução do dia operacional para o usuário. Modelo
     * de uma execução por dia: reaproveita a do dia em qualquer status. Modelo
     * de várias execuções: reaproveita uma pendente do dia ou cria outra.
     */
    public function iniciar(ChecklistModelo $modelo, User $user, ?Operador $operador = null): ChecklistExecucao
    {
        $this->garantirExecutor($modelo, $user, $operador);

        if (! $modelo->ativo) {
            throw new ChecklistException('Este checklist está desativado.');
        }

        $dia = ChecklistExecucao::dataOperacional();

        return DB::transaction(function () use ($modelo, $user, $dia): ChecklistExecucao {
            $modelo = ChecklistModelo::whereKey($modelo->id)->lockForUpdate()->firstOrFail();

            $existente = $modelo->permite_multiplas_execucoes_dia
                ? ChecklistExecucao::where('checklist_modelo_id', $modelo->id)
                    ->doDia($dia)
                    ->where('status', ExecucaoStatus::Pendente)
                    ->oldest('id')
                    ->lockForUpdate()
                    ->first()
                : ChecklistExecucao::withTrashed()
                    ->where('chave_unica', ChecklistExecucao::chaveUnica($modelo, $dia))
                    ->lockForUpdate()
                    ->first();

            if ($existente?->trashed()) {
                throw new ChecklistException('A execução de hoje deste checklist foi excluída.');
            }

            if ($existente === null) {
                return $this->criarExecucao($modelo, $dia, ExecucaoStatus::EmAndamento, $user);
            }

            if ($existente->status === ExecucaoStatus::Pendente) {
                $this->marcarIniciada($existente, $user);
                $existente->save();
            }

            return $existente;
        });
    }

    /**
     * Grava a resposta e/ou a observação de um item (autosave). Chaves aceitas
     * em $dados: `valor` (null limpa a resposta) e `observacao`.
     *
     * @param  array{valor?: mixed, observacao?: ?string}  $dados
     */
    public function responder(ChecklistExecucaoItem $item, array $dados, User $user, ?Operador $operador = null): ChecklistExecucaoItem
    {
        $virouCriticoNaoConforme = false;

        $item = DB::transaction(function () use ($item, $dados, $user, $operador, &$virouCriticoNaoConforme): ChecklistExecucaoItem {
            $execucao = $this->travarExecucao($item->checklist_execucao_id);
            $this->garantirAceitaRespostas($execucao, $user, $operador);

            $item = $execucao->itens()->whereKey($item->id)->firstOrFail();
            $conformeAntes = $item->conforme;

            if (array_key_exists('valor', $dados)) {
                $this->aplicarValor($item, $dados['valor'], $user);
            }

            if (array_key_exists('observacao', $dados)) {
                $item->observacao = $this->normalizarObservacao($dados['observacao']);
            }

            $item->conforme = $this->avaliador->avaliar($item);
            $item->save();

            $this->atualizarExecucao($execucao, $user);

            $virouCriticoNaoConforme = $item->critico() && $item->conforme === false && $conformeAntes !== false;

            return $item;
        });

        if ($virouCriticoNaoConforme) {
            Notification::send($this->gestores(), new ChecklistItemCriticoNotification($item));
        }

        return $item;
    }

    public function anexarFoto(ChecklistExecucaoItem $item, UploadedFile $arquivo, User $user, ?Operador $operador = null): ChecklistAnexo
    {
        Validator::make(
            ['foto' => $arquivo],
            ['foto' => ['required', 'image', 'max:'.(int) config('checklists.max_foto_kb')]],
            [
                'foto.image' => 'O arquivo enviado não é uma imagem.',
                'foto.max' => 'A foto passou do tamanho máximo de :max KB.',
            ],
        )->validate();

        $this->garantirAceitaRespostas($item->execucao, $user, $operador);

        $disco = (string) config('checklists.disco');
        $caminho = $arquivo->store('checklists/'.$item->checklist_execucao_id, $disco);

        try {
            return DB::transaction(function () use ($item, $arquivo, $user, $operador, $disco, $caminho): ChecklistAnexo {
                $execucao = $this->travarExecucao($item->checklist_execucao_id);
                $this->garantirAceitaRespostas($execucao, $user, $operador);

                $item = $execucao->itens()->whereKey($item->id)->firstOrFail();

                $anexo = $item->anexos()->create([
                    'disco' => $disco,
                    'caminho' => $caminho,
                    'tamanho_bytes' => (int) $arquivo->getSize(),
                    'enviado_por' => $user->id,
                ]);

                if ($item->tipo_resposta === TipoResposta::Foto) {
                    $item->respondido_por = $user->id;
                    $item->respondido_em = now();
                    $item->conforme = $this->avaliador->avaliar($item);
                    $item->save();
                }

                $this->atualizarExecucao($execucao, $user);

                return $anexo;
            });
        } catch (Throwable $e) {
            Storage::disk($disco)->delete($caminho);

            throw $e;
        }
    }

    public function removerAnexo(ChecklistAnexo $anexo, User $user, ?Operador $operador = null): void
    {
        DB::transaction(function () use ($anexo, $user, $operador): void {
            $item = $anexo->execucaoItem;
            $execucao = $this->travarExecucao($item->checklist_execucao_id);
            $this->garantirAceitaRespostas($execucao, $user, $operador);

            $anexo->delete();

            if ($item->tipo_resposta === TipoResposta::Foto && ! $item->anexos()->exists()) {
                $item->respondido_por = null;
                $item->respondido_em = null;
                $item->conforme = null;
                $item->save();
            }

            $this->atualizarExecucao($execucao, $user);
        });
    }

    /**
     * Pendências que impedem a finalização, por id do item (na ordem do checklist).
     *
     * @return array<int, list<string>>
     */
    public function validarFinalizacao(ChecklistExecucao $execucao): array
    {
        $erros = [];

        foreach ($execucao->itens()->withCount('anexos')->get() as $item) {
            $mensagens = [];

            if ($item->obrigatorio() && ! $item->foiRespondido()) {
                $mensagens[] = $item->tipo_resposta === TipoResposta::Foto
                    ? 'Envie a foto deste item.'
                    : 'Responda este item.';
            }

            if ($item->tipo_resposta !== TipoResposta::Foto && $item->anexos_count === 0) {
                if ($item->exigenciaFoto() === ExigenciaFoto::Sempre) {
                    $mensagens[] = 'Este item exige foto.';
                } elseif ($item->exigenciaFoto() === ExigenciaFoto::SeNaoConforme && $item->conforme === false) {
                    $mensagens[] = 'Anexe uma foto do problema.';
                }
            }

            if ($item->conforme === false && $item->exigeObservacaoNaoConforme() && blank($item->observacao)) {
                $mensagens[] = 'Descreva o problema na observação.';
            }

            if ($mensagens !== []) {
                $erros[$item->id] = $mensagens;
            }
        }

        return $erros;
    }

    /**
     * @throws ChecklistFinalizacaoException quando há pendências nos itens
     */
    public function finalizar(ChecklistExecucao $execucao, User $user, ?string $observacaoGeral = null, ?Operador $operador = null): ChecklistExecucao
    {
        $execucao = DB::transaction(function () use ($execucao, $user, $observacaoGeral, $operador): ChecklistExecucao {
            $execucao = $this->travarExecucao($execucao->id);
            $this->garantirAceitaRespostas($execucao, $user, $operador);

            $erros = $this->validarFinalizacao($execucao);

            if ($erros !== []) {
                throw new ChecklistFinalizacaoException($erros);
            }

            $execucao->recalcularTotais();
            $this->sincronizarNaoConformidades($execucao, $user);

            $this->marcarIniciada($execucao, $user);
            $execucao->status = $execucao->itens_nao_conformes > 0
                ? ExecucaoStatus::ConcluidoComPendencias
                : ExecucaoStatus::Concluido;
            $execucao->concluido_por = $user->id;
            $execucao->concluido_em = now();
            $execucao->observacao_geral = $this->normalizarObservacao($observacaoGeral);
            $execucao->save();

            return $execucao;
        });

        if ($execucao->modelo->exige_aprovacao) {
            Notification::send($this->gestores(), new ChecklistAguardandoAprovacaoNotification($execucao));
        }

        return $execucao;
    }

    public function aprovar(ChecklistExecucao $execucao, User $user): void
    {
        DB::transaction(function () use ($execucao, $user): void {
            $execucao = $this->travarExecucao($execucao->id);
            $this->garantirAguardaAprovacao($execucao);

            $execucao->status = ExecucaoStatus::Aprovado;
            $execucao->aprovado_por = $user->id;
            $execucao->aprovado_em = now();
            $execucao->save();
        });
    }

    /** Reprova e devolve para a equipe, que reabre a execução para corrigir. */
    public function reprovar(ChecklistExecucao $execucao, User $user, string $motivo): void
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw ValidationException::withMessages(['motivo' => 'Informe o motivo da reprovação.']);
        }

        DB::transaction(function () use ($execucao, $user, $motivo): void {
            $execucao = $this->travarExecucao($execucao->id);
            $this->garantirAguardaAprovacao($execucao);

            $execucao->status = ExecucaoStatus::Reprovado;
            $execucao->motivo_reprovacao = $motivo;
            $execucao->aprovado_por = $user->id;
            $execucao->aprovado_em = now();
            $execucao->save();
        });
    }

    /** Execução reprovada volta a aceitar respostas (o motivo continua registrado). */
    public function reabrir(ChecklistExecucao $execucao, User $user, ?Operador $operador = null): ChecklistExecucao
    {
        return DB::transaction(function () use ($execucao, $user, $operador): ChecklistExecucao {
            $execucao = $this->travarExecucao($execucao->id);
            $this->garantirExecutor($execucao->modelo, $user, $operador);

            if ($execucao->status !== ExecucaoStatus::Reprovado) {
                throw new ChecklistException('Só é possível reabrir um checklist reprovado.');
            }

            $execucao->status = ExecucaoStatus::EmAndamento;
            $execucao->concluido_por = null;
            $execucao->concluido_em = null;
            $execucao->aprovado_por = null;
            $execucao->aprovado_em = null;
            $execucao->save();

            return $execucao;
        });
    }

    /**
     * Marca como "não realizado" as execuções pendentes/em andamento cujo fim
     * da janela + tolerância já passou. As respostas parciais são mantidas.
     *
     * @return int quantidade de execuções expiradas
     */
    public function expirarForaDaJanela(CarbonInterface $agora): int
    {
        $candidatas = ChecklistExecucao::emAberto()
            ->whereDate('data_referencia', '<=', $agora->format('Y-m-d'))
            ->whereHas('modelo', fn ($modelo) => $modelo->whereNotNull('janela_fim'))
            ->with('modelo')
            ->get()
            ->filter(fn (ChecklistExecucao $execucao): bool => (bool) $execucao->modelo
                ->prazoExpiracao($execucao->data_referencia)
                ?->lessThan($agora));

        $expiradas = new Collection;

        foreach ($candidatas as $candidata) {
            $expirada = DB::transaction(function () use ($candidata): ?ChecklistExecucao {
                $execucao = $this->travarExecucao($candidata->id);

                if (! in_array($execucao->status, ExecucaoStatus::emAberto(), true)) {
                    return null;
                }

                $execucao->status = ExecucaoStatus::NaoRealizado;
                $execucao->recalcularTotais();
                $execucao->save();

                return $execucao;
            });

            if ($expirada !== null) {
                $expiradas->push($expirada);
            }
        }

        if ($expiradas->isNotEmpty()) {
            $gestores = $this->gestores();

            foreach ($expiradas as $execucao) {
                Notification::send($gestores, new ChecklistNaoRealizadoNotification($execucao));
            }
        }

        return $expiradas->count();
    }

    /**
     * A abertura do setor no dia operacional já foi feita? Cada modelo de
     * abertura ativo e agendado para o dia precisa de uma execução concluída
     * (com ou sem pendências) ou aprovada. Setor sem abertura agendada não
     * bloqueia nada. Consultado pelo módulo de cozinha antes de iniciar a
     * produção, quando o bloqueio estiver ligado.
     */
    public function aberturaConcluida(ChecklistSetor $setor, CarbonInterface $dia): bool
    {
        $dia = CarbonImmutable::instance($dia)->startOfDay();

        return ChecklistModelo::query()
            ->ativos()
            ->agendadosPara($dia)
            ->where('tipo', ChecklistTipo::Abertura)
            ->where('setor', $setor)
            ->whereDoesntHave('execucoes', fn ($execucoes) => $execucoes
                ->doDia($dia)
                ->whereIn('status', [ExecucaoStatus::Concluido, ExecucaoStatus::ConcluidoComPendencias, ExecucaoStatus::Aprovado]))
            ->doesntExist();
    }

    /**
     * Refaz o snapshot de uma execução pendente sem respostas a partir do
     * modelo atual (usado quando o modelo ganha nova versão).
     */
    public function regerarSnapshot(ChecklistExecucao $execucao): void
    {
        DB::transaction(function () use ($execucao): void {
            $execucao = $this->travarExecucao($execucao->id);

            if ($execucao->status !== ExecucaoStatus::Pendente || $execucao->temRespostas()) {
                return;
            }

            $modelo = $execucao->modelo;

            $execucao->itens()->withTrashed()->get()->each->forceDelete();
            $execucao->fill([
                'modelo_versao' => $modelo->versao,
                'modelo_nome' => $modelo->nome,
                'tipo' => $modelo->tipo,
                'setor' => $modelo->setor,
            ]);
            $this->copiarItens($modelo, $execucao);
            $execucao->recalcularTotais();
            $execucao->save();
        });
    }

    private function criarExecucao(ChecklistModelo $modelo, CarbonImmutable $dia, ExecucaoStatus $status, ?User $user = null): ChecklistExecucao
    {
        $execucao = new ChecklistExecucao([
            'checklist_modelo_id' => $modelo->id,
            'modelo_versao' => $modelo->versao,
            'modelo_nome' => $modelo->nome,
            'tipo' => $modelo->tipo,
            'setor' => $modelo->setor,
            'data_referencia' => $dia,
            'status' => $status,
            'chave_unica' => ChecklistExecucao::chaveUnica($modelo, $dia),
        ]);

        if ($user !== null) {
            $this->marcarIniciada($execucao, $user);
        }

        $execucao->save();

        $this->copiarItens($modelo, $execucao);
        $execucao->recalcularTotais();
        $execucao->save();

        return $execucao;
    }

    private function copiarItens(ChecklistModelo $modelo, ChecklistExecucao $execucao): void
    {
        foreach ($modelo->itensOrdenados()->values() as $indice => $item) {
            $execucao->itens()->create([
                ...$item->toSnapshot(),
                'ordem' => $indice + 1,
            ]);
        }
    }

    /** Pendente → em andamento, com quem iniciou e a sessão de caixa aberta dele (se houver). */
    private function marcarIniciada(ChecklistExecucao $execucao, User $user): void
    {
        if ($execucao->status === ExecucaoStatus::Pendente || $execucao->status === null) {
            $execucao->status = ExecucaoStatus::EmAndamento;
        }

        if ($execucao->iniciado_por === null) {
            $execucao->iniciado_por = $user->id;
            $execucao->iniciado_em = now();
        }

        $execucao->sessao_caixa_id ??= SessaoCaixa::where('sessaocaixa_user_id', $user->id)
            ->where('sessaocaixa_status', 'ABERTA')
            ->latest('id')
            ->value('id');
    }

    private function atualizarExecucao(ChecklistExecucao $execucao, User $user): void
    {
        $this->marcarIniciada($execucao, $user);
        $execucao->recalcularTotais();
        $execucao->save();
    }

    private function aplicarValor(ChecklistExecucaoItem $item, mixed $valor, User $user): void
    {
        $tipo = $item->tipo_resposta;

        if ($tipo === TipoResposta::Foto) {
            throw ValidationException::withMessages(['valor' => 'Este item é respondido com uma foto.']);
        }

        $coluna = $tipo->colunaValor();

        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            $item->{$coluna} = null;
            $item->respondido_por = null;
            $item->respondido_em = null;

            return;
        }

        $item->{$coluna} = match ($tipo) {
            TipoResposta::Check, TipoResposta::SimNao => $this->normalizarBooleano($valor),
            TipoResposta::Numero, TipoResposta::Temperatura => $this->normalizarNumero($valor),
            TipoResposta::Texto => $this->normalizarTexto($valor),
            TipoResposta::Selecao => $this->normalizarOpcao($item, $valor),
        };
        $item->respondido_por = $user->id;
        $item->respondido_em = now();
    }

    private function normalizarBooleano(mixed $valor): bool
    {
        $valor = match (is_string($valor) ? mb_strtolower(trim($valor)) : $valor) {
            'sim' => true,
            'nao', 'não' => false,
            default => filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        };

        if ($valor === null) {
            throw ValidationException::withMessages(['valor' => 'Resposta inválida.']);
        }

        return $valor;
    }

    private function normalizarNumero(mixed $valor): string
    {
        $texto = str_replace([' ', ','], ['', '.'], (string) $valor);

        if (! is_numeric($texto) || abs((float) $texto) >= 100_000_000) {
            throw ValidationException::withMessages(['valor' => 'Informe um número válido.']);
        }

        return number_format((float) $texto, 2, '.', '');
    }

    private function normalizarTexto(mixed $valor): string
    {
        $texto = trim((string) $valor);

        if (mb_strlen($texto) > self::MAX_TEXTO) {
            throw ValidationException::withMessages(['valor' => 'O texto passou do limite de '.self::MAX_TEXTO.' caracteres.']);
        }

        return $texto;
    }

    private function normalizarOpcao(ChecklistExecucaoItem $item, mixed $valor): string
    {
        $valores = collect($item->regra('opcoes', []))->pluck('valor')->map(fn ($opcao): string => (string) $opcao);

        if (! $valores->contains((string) $valor)) {
            throw ValidationException::withMessages(['valor' => 'Opção inválida.']);
        }

        return (string) $valor;
    }

    private function normalizarObservacao(?string $observacao): ?string
    {
        $observacao = trim((string) $observacao);

        if (mb_strlen($observacao) > self::MAX_TEXTO) {
            throw ValidationException::withMessages(['observacao' => 'A observação passou do limite de '.self::MAX_TEXTO.' caracteres.']);
        }

        return $observacao === '' ? null : $observacao;
    }

    /**
     * Cria a NC de cada item não conforme e cancela as NCs abertas de itens
     * corrigidos (execução reaberta após reprovação).
     */
    private function sincronizarNaoConformidades(ChecklistExecucao $execucao, User $user): void
    {
        foreach ($execucao->itens()->with('naoConformidade')->get() as $item) {
            $nc = $item->naoConformidade;

            if ($item->conforme === false) {
                if ($nc === null) {
                    $item->naoConformidade()->create([
                        'descricao' => $this->descricaoNaoConformidade($item),
                        'critica' => $item->critico(),
                        'status' => NaoConformidadeStatus::Aberta,
                    ]);
                } else {
                    $nc->update(['descricao' => $this->descricaoNaoConformidade($item)]);
                }

                continue;
            }

            if ($nc !== null && in_array($nc->status, NaoConformidadeStatus::pendentes(), true)) {
                $nc->update([
                    'status' => NaoConformidadeStatus::Cancelada,
                    'resolvida_por' => $user->id,
                    'resolvida_em' => now(),
                    'observacao_resolucao' => 'Item corrigido antes da finalização.',
                ]);
            }
        }
    }

    private function descricaoNaoConformidade(ChecklistExecucaoItem $item): string
    {
        $descricao = $item->titulo.' — resposta: '.($item->respostaFormatada() ?? 'sem resposta');

        if (filled($item->observacao)) {
            $descricao .= '. Obs.: '.$item->observacao;
        }

        return $descricao;
    }

    private function travarExecucao(int $id): ChecklistExecucao
    {
        return ChecklistExecucao::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Com operador (tablet compartilhado), valem os papéis da pessoa, não os
     * da conta da estação.
     */
    private function garantirExecutor(ChecklistModelo $modelo, User $user, ?Operador $operador = null): void
    {
        $executor = $operador === null ? $user : $operador->usuario();

        if ($executor === null) {
            throw new ChecklistException('O operador não tem usuário vinculado para executar checklists.');
        }

        if (! $modelo->podeSerExecutadoPor($executor)) {
            throw new ChecklistException('Você não está atribuído a este checklist.');
        }
    }

    private function garantirAceitaRespostas(ChecklistExecucao $execucao, User $user, ?Operador $operador = null): void
    {
        $this->garantirExecutor($execucao->modelo, $user, $operador);

        if ($execucao->status->aceitaRespostas()) {
            return;
        }

        throw new ChecklistException(match ($execucao->status) {
            ExecucaoStatus::Reprovado => 'Este checklist foi reprovado. Reabra-o para corrigir.',
            ExecucaoStatus::NaoRealizado => 'O prazo deste checklist expirou.',
            default => 'Este checklist já foi finalizado.',
        });
    }

    private function garantirAguardaAprovacao(ChecklistExecucao $execucao): void
    {
        if (! $execucao->status->aguardaAprovacao()) {
            throw new ChecklistException('Só é possível aprovar ou reprovar um checklist concluído.');
        }
    }

    /** @return Collection<int, User> */
    private function gestores(): Collection
    {
        return User::whereHas('roles', fn ($roles) => $roles->whereIn('name', ChecklistModelo::PAPEIS_GESTORES))->get();
    }
}
