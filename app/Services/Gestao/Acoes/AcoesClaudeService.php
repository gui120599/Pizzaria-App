<?php

namespace App\Services\Gestao\Acoes;

use App\Enums\Gestao\StatusAcaoClaude;
use App\Enums\Gestao\TipoAcaoClaude;
use App\Models\ClaudeAcao;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ações do chat do Claude (roteiro claude-mcp-gestao.md, G3), no padrão da
 * compra avulsa: preparar (prévia, nada gravado) → o usuário lê o resumo →
 * confirmar (grava). Só quem preparou confirma, dentro da validade, e a
 * permissão é conferida de novo na confirmação.
 */
class AcoesClaudeService
{
    public const PERMISSAO = 'executar_acoes:claude_gestao';

    public function acao(TipoAcaoClaude $tipo): AcaoConfirmavel
    {
        return app(match ($tipo) {
            TipoAcaoClaude::Despesa => DespesaAvulsaAcao::class,
            TipoAcaoClaude::PausaProduto => PausaProdutoAcao::class,
            TipoAcaoClaude::Promocao => PromocaoRelampagoAcao::class,
            TipoAcaoClaude::AprovarSugestoes => AprovarSugestoesAcao::class,
            TipoAcaoClaude::ContagemEstoque => ContagemEstoqueAcao::class,
        });
    }

    public function permitido(TipoAcaoClaude $tipo, User $usuario): bool
    {
        return ! $usuario->hasRole('Agente')
            && Gate::forUser($usuario)->allows(self::PERMISSAO)
            && $this->acao($tipo)->permitido($usuario);
    }

    /**
     * Monta (ou refaz, com o mesmo uuid) a prévia da ação.
     *
     * @param  array<string, mixed>  $entrada
     *
     * @throws AuthorizationException|ValidationException
     */
    public function preparar(TipoAcaoClaude $tipo, array $entrada, User $usuario, ?string $uuid = null): ClaudeAcao
    {
        $this->exigirPermissao($tipo, $usuario);

        $existente = filled($uuid) ? $this->abertaDoUsuario((string) $uuid, $usuario, $tipo) : null;
        ['entrada' => $entrada, 'previa' => $previa] = $this->acao($tipo)->preparar($entrada, $usuario);

        $alertas = $previa['alertas'] ?? [];
        $previa['pode_confirmar'] = collect($alertas)->doesntContain(fn (array $alerta): bool => $alerta['bloqueia']);
        $previa['alertas'] = $alertas;

        $dados = [
            'entrada' => $entrada,
            'previa' => $previa,
            'expira_em' => now()->addMinutes((int) config('gestao.acoes.validade_minutos', 30)),
        ];

        if ($existente !== null) {
            $existente->update($dados);

            return $existente;
        }

        return ClaudeAcao::create($dados + [
            'uuid' => (string) Str::uuid(),
            'user_id' => $usuario->id,
            'tipo' => $tipo,
            'status' => StatusAcaoClaude::Aberta,
        ]);
    }

    /**
     * Executa a ação preparada. Confirmar de novo devolve o mesmo resultado.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function confirmar(string $uuid, User $usuario): ClaudeAcao
    {
        // Fora da transação: a marcação de expirada não pode ser desfeita junto com o erro.
        $previa = $this->doUsuario($uuid, $usuario);
        if ($previa->status === StatusAcaoClaude::Aberta && $previa->expirou()) {
            $previa->update(['status' => StatusAcaoClaude::Expirada]);

            throw ValidationException::withMessages(['acao_uuid' => 'A prévia expirou. Prepare de novo para conferir os dados atuais.']);
        }

        return DB::transaction(function () use ($uuid, $usuario): ClaudeAcao {
            $acao = $this->doUsuario($uuid, $usuario, lock: true);

            if ($acao->status === StatusAcaoClaude::Confirmada) {
                return $acao;
            }

            if ($acao->status !== StatusAcaoClaude::Aberta) {
                throw ValidationException::withMessages(['acao_uuid' => 'Esta ação não está mais aberta ('.$acao->status->getLabel().'). Prepare de novo.']);
            }

            $this->exigirPermissao($acao->tipo, $usuario);

            if (! ($acao->previa['pode_confirmar'] ?? false)) {
                throw ValidationException::withMessages(['acao_uuid' => 'A prévia tem alertas que impedem a confirmação. Corrija e prepare de novo.']);
            }

            $resultado = $this->acao($acao->tipo)->executar($acao->entrada, $acao->previa, $usuario);

            $acao->update([
                'status' => StatusAcaoClaude::Confirmada,
                'confirmada_em' => now(),
                'resultado' => $resultado,
            ]);

            return $acao;
        });
    }

    /** @throws AuthorizationException|ValidationException */
    public function descartar(string $uuid, User $usuario): ClaudeAcao
    {
        $acao = $this->doUsuario($uuid, $usuario);

        if ($acao->status === StatusAcaoClaude::Aberta) {
            $acao->update(['status' => StatusAcaoClaude::Descartada]);
        }

        return $acao;
    }

    /** Marca como expiradas as prévias abertas vencidas. */
    public function expirarVencidas(): int
    {
        return ClaudeAcao::query()
            ->where('status', StatusAcaoClaude::Aberta)
            ->where('expira_em', '<', now())
            ->update(['status' => StatusAcaoClaude::Expirada]);
    }

    /** @throws AuthorizationException */
    private function exigirPermissao(TipoAcaoClaude $tipo, User $usuario): void
    {
        if (! $this->permitido($tipo, $usuario)) {
            throw new AuthorizationException('Seu perfil não pode fazer esta ação ('.$tipo->getLabel().') no painel.');
        }
    }

    /** @throws AuthorizationException|ValidationException */
    private function abertaDoUsuario(string $uuid, User $usuario, TipoAcaoClaude $tipo): ClaudeAcao
    {
        $acao = $this->doUsuario($uuid, $usuario);

        if ($acao->tipo !== $tipo || $acao->status !== StatusAcaoClaude::Aberta) {
            throw ValidationException::withMessages(['acao_uuid' => 'Esta prévia não pode ser refeita (outro tipo ou já encerrada). Prepare sem acao_uuid.']);
        }

        return $acao;
    }

    /** @throws AuthorizationException|ValidationException */
    private function doUsuario(string $uuid, User $usuario, bool $lock = false): ClaudeAcao
    {
        $acao = ClaudeAcao::query()->where('uuid', $uuid)->when($lock, fn ($q) => $q->lockForUpdate())->first()
            ?? throw ValidationException::withMessages(['acao_uuid' => 'Ação não encontrada.']);

        if ((int) $acao->user_id !== (int) $usuario->id) {
            throw new AuthorizationException('Esta ação foi preparada por outro usuário.');
        }

        return $acao;
    }
}
