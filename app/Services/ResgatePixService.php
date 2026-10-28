<?php

namespace App\Services;

use App\Enums\FormaPagamento;
use App\Enums\PrestadorCategoriaEnum;
use App\Enums\PrestadorTipoEnum;
use App\Enums\StatusLancamento;
use App\Enums\TipoChavePix;
use App\Enums\TipoLancamento;
use App\Enums\TipoMovimentoCarteiraEnum;
use App\Exceptions\CarteiraInfluenciadorException;
use App\Exceptions\FinanceiroException;
use App\Models\Empresa;
use App\Models\Influenciador;
use App\Models\Lancamento;
use App\Models\Prestador;
use App\Models\ResgateCarteira;
use App\Models\User;
use App\Notifications\ResgatePagoNotification;
use Illuminate\Support\Facades\DB;

/**
 * Resgate do saldo da carteira em Pix: despesa de marketing paga no Financeiro
 * (favorecido = Prestador do influenciador) + débito da carteira + registro do
 * resgate, tudo numa transação. O débito trava o influenciador, então clique
 * duplo ou dois operadores não pagam o mesmo saldo duas vezes.
 */
class ResgatePixService
{
    public function __construct(private readonly CarteiraInfluenciadorService $carteira) {}

    /**
     * @param  string|null  $comprovantePath  caminho no disco local (privado)
     *
     * @throws CarteiraInfluenciadorException configuração ou dados do influenciador incompletos, saldo insuficiente
     * @throws FinanceiroException conta de saída sem saldo
     */
    public function pagar(Influenciador $influenciador, float $valor, User $usuario, ?string $comprovantePath = null): ResgateCarteira
    {
        $valor = round($valor, 2);

        if ($valor <= 0) {
            throw new CarteiraInfluenciadorException('Informe um valor maior que zero.');
        }

        $empresa = Empresa::first();

        if (! $empresa || ! $empresa->empresa_influ_plano_despesa_id || ! $empresa->empresa_influ_conta_pix_id) {
            throw new CarteiraInfluenciadorException('Configure o plano de despesa e a conta de saída do Pix em Configurações › Influenciadores.');
        }

        $resgate = DB::transaction(function () use ($influenciador, $valor, $usuario, $comprovantePath, $empresa): ResgateCarteira {
            Influenciador::whereKey($influenciador->id)->lockForUpdate()->firstOrFail();
            $influenciador->refresh();

            $prestador = $this->prestadorDe($influenciador);

            $resgate = ResgateCarteira::create([
                'rc_influenciador_id' => $influenciador->id,
                'rc_valor' => $valor,
                'rc_comprovante_path' => $comprovantePath,
                'rc_user_id' => $usuario->id,
                'rc_pago_em' => now(),
            ]);

            // Confere o saldo (com a trava) e debita antes de mexer no Financeiro.
            $this->carteira->debitar($influenciador, TipoMovimentoCarteiraEnum::RESGATE_PIX, $valor, [
                'resgate_id' => $resgate->id,
                'user_id' => $usuario->id,
                'observacao' => 'Resgate em Pix',
            ]);

            $titulo = Lancamento::create([
                'tipo' => TipoLancamento::Pagar,
                'descricao' => "Comissão de influenciador — {$influenciador->influenciador_nome} (resgate #{$resgate->id})",
                'plano_despesa_id' => $empresa->empresa_influ_plano_despesa_id,
                'favorecido_id' => $prestador->id,
                'valor' => $valor,
                'vencimento' => today()->toDateString(),
                'competencia' => today()->startOfMonth()->toDateString(),
                'status' => StatusLancamento::Pendente,
            ]);

            $titulo->registrarPagamento(
                valor: $valor,
                forma: FormaPagamento::Pix,
                observacoes: "Resgate #{$resgate->id} da carteira do influenciador.",
                contaFinanceiraId: $empresa->empresa_influ_conta_pix_id,
            );

            $resgate->update(['rc_lancamento_id' => $titulo->id]);

            return $resgate;
        });

        DB::afterCommit(fn () => $influenciador->notify(new ResgatePagoNotification($resgate)));

        return $resgate;
    }

    /**
     * Favorecido (PF autônomo) do influenciador no Financeiro, criado na
     * primeira vez com nome, CPF e chave Pix.
     *
     * @throws CarteiraInfluenciadorException
     */
    private function prestadorDe(Influenciador $influenciador): Prestador
    {
        if ($influenciador->prestador) {
            return $influenciador->prestador;
        }

        if (blank($influenciador->influenciador_cpf) || blank($influenciador->influenciador_chave_pix)) {
            throw new CarteiraInfluenciadorException('O influenciador precisa ter CPF e chave Pix cadastrados para receber um resgate.');
        }

        $prestador = Prestador::create([
            'tipo' => PrestadorTipoEnum::PF,
            'categoria' => PrestadorCategoriaEnum::AUTONOMO,
            'nome' => $influenciador->influenciador_nome,
            'cpf_cnpj' => $influenciador->influenciador_cpf,
            'email' => $influenciador->email,
            'celular' => $influenciador->influenciador_whatsapp,
            'tipo_chave_pix' => $this->tipoDaChave($influenciador->influenciador_chave_pix),
            'chave_pix' => $influenciador->influenciador_chave_pix,
            'observacoes' => 'Criado a partir do cadastro de influenciador.',
        ]);

        $influenciador->forceFill(['influenciador_prestador_id' => $prestador->id])->save();

        return $prestador;
    }

    private function tipoDaChave(string $chave): TipoChavePix
    {
        $digitos = preg_replace('/\D/', '', $chave);

        return match (true) {
            str_contains($chave, '@') => TipoChavePix::Email,
            strlen($digitos) === 11 && $digitos === $chave => TipoChavePix::Cpf,
            strlen($digitos) === 14 && $digitos === $chave => TipoChavePix::Cnpj,
            str_starts_with($chave, '+') || (strlen($digitos) >= 10 && strlen($digitos) <= 13 && $digitos === preg_replace('/[\s()-]/', '', $chave)) => TipoChavePix::Telefone,
            default => TipoChavePix::Aleatoria,
        };
    }
}
