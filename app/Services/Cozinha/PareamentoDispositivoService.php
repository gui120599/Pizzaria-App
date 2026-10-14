<?php

namespace App\Services\Cozinha;

use App\Exceptions\ProducaoException;
use App\Models\DispositivoCozinha;
use App\Models\User;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Pareamento do tablet por QR de uso único (spec §6.2): o admin gera um
 * token (só o sha256 fica no banco, válido por cozinha.pareamento_minutos);
 * o tablet abre a URL, o token é consumido e o tablet entra na conta técnica
 * da estação (criada aqui na primeira vez, com o papel EstacaoCozinha).
 */
class PareamentoDispositivoService
{
    public const PAPEL_ESTACAO = 'EstacaoCozinha';

    /** Caminho que o tablet abre ao ler o QR (a rota é registrada pelo painel da cozinha). */
    public const CAMINHO_PAREAMENTO = '/cozinha/parear/';

    /** @return string token em texto (vai só no QR; nunca é gravado) */
    public function gerarToken(DispositivoCozinha $dispositivo): string
    {
        $token = Str::random(48);

        DB::transaction(function () use ($dispositivo, $token): void {
            $dispositivo->refresh();
            $this->garantirContaDaEstacao($dispositivo);

            $dispositivo->fill([
                'token_hash' => hash('sha256', $token),
                'token_expira_em' => now()->addMinutes((int) config('cozinha.pareamento_minutos', 10)),
            ])->save();
        });

        return $token;
    }

    /** Consome o token: devolve o dispositivo pareado ou recusa (inválido, usado ou expirado). */
    public function parear(string $token, ?string $ip = null): DispositivoCozinha
    {
        return DB::transaction(function () use ($token, $ip): DispositivoCozinha {
            $dispositivo = DispositivoCozinha::where('token_hash', hash('sha256', $token))->lockForUpdate()->first();

            if ($dispositivo === null || ! $dispositivo->ativo) {
                throw new ProducaoException('Este QR de pareamento é inválido ou já foi usado.');
            }

            if ($dispositivo->token_expira_em === null || $dispositivo->token_expira_em->isPast()) {
                throw new ProducaoException('Este QR de pareamento expirou. Gere outro no painel.');
            }

            $dispositivo->fill([
                'token_hash' => null,
                'token_expira_em' => null,
                'pareado_em' => now(),
                'ultimo_ping_em' => now(),
                'ultimo_ip' => $ip,
            ])->save();

            return $dispositivo;
        });
    }

    /**
     * Revoga o tablet: o cookie dele deixa de valer (o middleware exige ativo
     * e pareado). Para voltar a usar, reative e gere um QR novo.
     */
    public function revogar(DispositivoCozinha $dispositivo): void
    {
        $dispositivo->fill(['ativo' => false, 'pareado_em' => null, 'token_hash' => null, 'token_expira_em' => null])->save();
    }

    /** Sempre pelo APP_URL, como o QR da mesa: o tablet não abre o IP interno de quem gerou. */
    public function urlPareamento(string $token): string
    {
        return rtrim((string) config('app.url'), '/').self::CAMINHO_PAREAMENTO.$token;
    }

    /** PNG em data URI para o modal do admin. */
    public function qrPng(string $token): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'eccLevel' => EccLevel::M,
            'scale' => 8,
            'outputBase64' => true,
        ])))->render($this->urlPareamento($token));
    }

    private function garantirContaDaEstacao(DispositivoCozinha $dispositivo): void
    {
        if ($dispositivo->user_id !== null) {
            return;
        }

        $user = User::create([
            'name' => 'Tablet '.$dispositivo->nome,
            'name_first' => Str::limit('Tablet '.$dispositivo->nome, 50, ''),
            'email' => 'tablet-'.$dispositivo->id.'-'.Str::lower(Str::random(6)).'@cozinha.local',
            'password' => Hash::make(Str::random(40)),
        ]);
        $user->assignRole(self::PAPEL_ESTACAO);

        $dispositivo->user_id = $user->id;
    }
}
