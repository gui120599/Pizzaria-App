<?php

namespace App\Services;

use App\Enums\StatusInfluenciadorEnum;
use App\Exceptions\CarteiraInfluenciadorException;
use App\Models\CodigoResgate;
use App\Models\Influenciador;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Prova de identidade do influenciador no caixa: código de 6 dígitos, uso
 * único e 15 minutos de validade. Só o hash (HMAC com a APP_KEY) é guardado;
 * gerar um novo invalida o anterior.
 */
class CodigoResgateService
{
    public const VALIDADE_MINUTOS = 15;

    private const TENTATIVAS_POR_MINUTO = 8;

    /** @return array{codigo: string, expira_em: Carbon} o código em claro só existe aqui, na resposta */
    public function gerar(Influenciador $influenciador): array
    {
        return DB::transaction(function () use ($influenciador): array {
            Influenciador::whereKey($influenciador->id)->lockForUpdate()->firstOrFail();

            // Gerar um novo invalida o anterior.
            CodigoResgate::where('cr_influenciador_id', $influenciador->id)
                ->whereNull('cr_usado_em')
                ->where('cr_expira_em', '>', now())
                ->update(['cr_expira_em' => now()]);

            do {
                $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $hash = self::hash($codigo);
                // O caixa só digita o código: ele precisa ser único entre os vigentes.
                $emUso = CodigoResgate::where('cr_codigo_hash', $hash)->whereNull('cr_usado_em')->where('cr_expira_em', '>', now())->exists();
            } while ($emUso);

            $registro = CodigoResgate::create([
                'cr_influenciador_id' => $influenciador->id,
                'cr_codigo_hash' => $hash,
                'cr_expira_em' => now()->addMinutes(self::VALIDADE_MINUTOS),
            ]);

            return ['codigo' => $codigo, 'expira_em' => $registro->cr_expira_em];
        });
    }

    /**
     * Acha o código vigente (não usado, não vencido, de influenciador ativo).
     * Limita as tentativas por operador contra adivinhação.
     *
     * @throws CarteiraInfluenciadorException
     */
    public function consultar(string $codigo, ?int $operadorId = null): CodigoResgate
    {
        $chave = 'codigo-resgate:'.($operadorId ?? 'anon');

        if (RateLimiter::tooManyAttempts($chave, self::TENTATIVAS_POR_MINUTO)) {
            throw new CarteiraInfluenciadorException('Muitas tentativas. Aguarde um minuto e tente de novo.');
        }

        $codigo = preg_replace('/\D/', '', $codigo);

        $registro = strlen($codigo) === 6
            ? CodigoResgate::with('influenciador')->where('cr_codigo_hash', self::hash($codigo))->latest('id')->first()
            : null;

        if (! $registro || ! $registro->estaVigente() || $registro->influenciador?->influenciador_status !== StatusInfluenciadorEnum::ATIVO) {
            RateLimiter::hit($chave, 60);

            throw new CarteiraInfluenciadorException('Código inválido, vencido ou já usado.');
        }

        RateLimiter::clear($chave);

        return $registro;
    }

    public static function hash(string $codigo): string
    {
        return hash_hmac('sha256', 'resgate:'.$codigo, (string) config('app.key'));
    }
}
