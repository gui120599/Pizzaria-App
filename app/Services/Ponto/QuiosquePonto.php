<?php

namespace App\Services\Ponto;

use App\Enums\Ponto\OrigemMarcacao;
use App\Exceptions\PontoException;
use App\Models\Colaborador;
use App\Models\PontoMarcacao;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Tablet da loja (quiosque): o colaborador se identifica só pelo PIN. O
 * tablet precisa estar pareado (token de config('ponto.quiosque.tokens') em
 * cookie) e erros de PIN são limitados por tablet.
 */
class QuiosquePonto
{
    public const COOKIE = 'ponto_quiosque';

    /** Selfie já vem reduzida pelo tablet (~50 KB); acima disso não é foto da câmera. */
    private const SELFIE_MAX_BYTES = 1_048_576;

    private const SELFIE_TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(private readonly PontoService $ponto) {}

    public function tokenValido(?string $token): bool
    {
        if (blank($token)) {
            return false;
        }

        foreach ((array) config('ponto.quiosque.tokens', []) as $autorizado) {
            if (hash_equals((string) $autorizado, (string) $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws PontoException PIN errado, tablet bloqueado por tentativas ou colaborador sem PIN cadastrado
     */
    public function identificar(string $pin, string $token): Colaborador
    {
        $chave = self::chaveLimite($token);
        $tentativas = (int) config('ponto.quiosque.tentativas_pin', 5);

        if (RateLimiter::tooManyAttempts($chave, $tentativas)) {
            $minutos = (int) ceil(RateLimiter::availableIn($chave) / 60);

            throw new PontoException("Muitas tentativas erradas. Aguarde {$minutos} min ou chame o gerente.");
        }

        $pin = preg_replace('/\D/', '', $pin) ?? '';
        $colaborador = $pin === '' ? null : Colaborador::doPin($pin);

        if ($colaborador === null) {
            RateLimiter::hit($chave, (int) config('ponto.quiosque.bloqueio_minutos', 5) * 60);
            $restantes = max(0, $tentativas - RateLimiter::attempts($chave));

            throw new PontoException($restantes > 0
                ? "PIN não reconhecido. Restam {$restantes} tentativa(s)."
                : 'PIN não reconhecido. Tablet bloqueado por alguns minutos.');
        }

        RateLimiter::clear($chave);

        return $colaborador;
    }

    /**
     * Marca pelo quiosque. A selfie segue config('ponto.quiosque.exigir_selfie'),
     * não a regra do celular.
     *
     * @throws PontoException
     */
    public function registrar(Colaborador $colaborador, ?string $ip, ?string $userAgent, ?UploadedFile $selfie = null): PontoMarcacao
    {
        return $this->ponto->registrarMarcacao($colaborador, OrigemMarcacao::Quiosque, [
            'ip' => $ip,
            'user_agent' => $userAgent,
            'foto' => $selfie,
            'exigir_selfie' => (bool) config('ponto.quiosque.exigir_selfie'),
        ]);
    }

    /**
     * Converte o data URL do canvas do tablet num arquivo temporário. O tipo é
     * conferido pelo conteúdo (finfo), não pelo cabeçalho do data URL. Quem
     * chama apaga o arquivo depois de registrar.
     *
     * @throws PontoException
     */
    public function selfieDeDataUrl(string $dataUrl): UploadedFile
    {
        $base64 = preg_match('/^data:image\/[a-z]+;base64,(.+)$/s', $dataUrl, $partes) === 1 ? $partes[1] : null;
        $bytes = $base64 === null ? false : base64_decode($base64, true);

        if ($bytes === false || $bytes === '') {
            throw new PontoException('A selfie não chegou. Tente de novo.');
        }

        if (strlen($bytes) > self::SELFIE_MAX_BYTES) {
            throw new PontoException('A selfie ficou grande demais. Tente de novo.');
        }

        $tipo = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! isset(self::SELFIE_TIPOS[$tipo])) {
            throw new PontoException('A selfie não é uma imagem válida. Tente de novo.');
        }

        $caminho = tempnam(sys_get_temp_dir(), 'selfie');
        file_put_contents($caminho, $bytes);

        return new UploadedFile($caminho, 'selfie.'.self::SELFIE_TIPOS[$tipo], $tipo, null, true);
    }

    private static function chaveLimite(string $token): string
    {
        return 'ponto-quiosque:'.hash('sha256', $token);
    }
}
