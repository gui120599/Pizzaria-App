<?php

namespace App\Services\Nfe;

use App\Exceptions\NfeIoException;
use App\Models\Empresa;
use App\Models\NfEmissao;
use App\Models\NfNumeracao;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Numeração própria da NFC-e (modelo 65), independente do id da venda. Antes
 * o número era o venda.id, o que deixava lacunas fiscais sempre que uma venda
 * não emitia nota. Agora o número só é consumido quando a venda é de fato
 * enviada à NFe.io, e a reserva acontece sob lockForUpdate na linha da
 * sequência — vários caixas emitindo ao mesmo tempo nunca recebem o mesmo
 * número (e o unique (numeracao_id, numero) de nf_emissoes é a 2ª barreira).
 */
class NfNumeracaoService
{
    public const SERIE_PADRAO = 1;

    /**
     * Reserva (ou reaproveita) o número de NF da venda. Reenvio de nota
     * rejeitada/com falha devolve a mesma reserva, mantendo o número; só uma
     * nota cancelada libera a venda para um número novo.
     *
     * @param  NfEmissao::DECISAO_*  $decisao
     */
    public function reservarPara(Venda $venda, string $decisao, int $serie = self::SERIE_PADRAO): NfEmissao
    {
        return DB::transaction(function () use ($venda, $decisao, $serie) {
            // Serializa reservas da mesma venda (ex.: duplo clique) antes de olhar a reserva existente.
            Venda::whereKey($venda->id)->lockForUpdate()->first();

            $existente = NfEmissao::where('venda_id', $venda->id)->latest('id')->first();

            if ($existente?->status === NfEmissao::STATUS_AUTORIZADA) {
                throw new NfeIoException("A NFC-e nº {$existente->numero} desta venda já foi autorizada.");
            }

            if ($existente && ! $existente->numeroConsumido()) {
                return $existente;
            }

            $numeracao = $this->sequenciaComLock($serie);
            $numeracao->ultimo_numero++;
            $numeracao->save();

            return NfEmissao::create([
                'venda_id' => $venda->id,
                'numeracao_id' => $numeracao->id,
                'modelo' => $numeracao->modelo,
                'serie' => $numeracao->serie,
                'numero' => $numeracao->ultimo_numero,
                'status' => NfEmissao::STATUS_RESERVADO,
                'decisao_envio' => $decisao,
                'user_id' => Auth::id(),
            ]);
        });
    }

    /** Próximo número da sequência, sem reservar (preview do payload). */
    public function proximoNumero(int $serie = self::SERIE_PADRAO): int
    {
        $numeracao = $this->buscarSequencia($serie)->first();

        return ($numeracao->ultimo_numero ?? 0) + 1;
    }

    /**
     * Ajuste manual do último número usado (ex.: continuar a sequência de
     * produção). Não deixa retroceder abaixo de um número já reservado, o que
     * geraria número duplicado.
     */
    public function definirUltimoNumero(int $ultimoNumero, int $serie = self::SERIE_PADRAO): NfNumeracao
    {
        if ($ultimoNumero < 0) {
            throw new InvalidArgumentException('O último número não pode ser negativo.');
        }

        return DB::transaction(function () use ($ultimoNumero, $serie) {
            $numeracao = $this->sequenciaComLock($serie);
            $maiorReservado = (int) NfEmissao::withTrashed()->where('numeracao_id', $numeracao->id)->max('numero');

            if ($ultimoNumero < $maiorReservado) {
                throw new InvalidArgumentException("O número {$maiorReservado} já foi reservado nesta sequência; o último número não pode ser menor que ele.");
            }

            $numeracao->update(['ultimo_numero' => $ultimoNumero]);

            return $numeracao;
        });
    }

    private function sequenciaComLock(int $serie): NfNumeracao
    {
        ['cnpj' => $cnpj, 'ambiente' => $ambiente] = $this->emitente();

        // Cria a sequência zerada se ainda não existir; o unique faz o insert
        // concorrente virar no-op em vez de duplicar a linha.
        NfNumeracao::query()->insertOrIgnore([
            'emitente_cnpj' => $cnpj,
            'ambiente' => $ambiente,
            'modelo' => NfNumeracao::MODELO_NFCE,
            'serie' => $serie,
            'ultimo_numero' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->buscarSequencia($serie)->lockForUpdate()->firstOrFail();
    }

    /**
     * @return Builder<NfNumeracao>
     */
    private function buscarSequencia(int $serie)
    {
        ['cnpj' => $cnpj, 'ambiente' => $ambiente] = $this->emitente();

        return NfNumeracao::withTrashed()
            ->where('emitente_cnpj', $cnpj)
            ->where('ambiente', $ambiente)
            ->where('modelo', NfNumeracao::MODELO_NFCE)
            ->where('serie', $serie);
    }

    /**
     * @return array{cnpj: string, ambiente: string}
     */
    private function emitente(): array
    {
        $empresa = Empresa::first();
        $cnpj = preg_replace('/\D/', '', (string) $empresa?->empresa_cnpj);

        if ($cnpj === '') {
            throw new NfeIoException('CNPJ do emitente não cadastrado na empresa — necessário para controlar a numeração da NFC-e.');
        }

        return ['cnpj' => $cnpj, 'ambiente' => $empresa->empresa_api_nfeio_ambiente ?? 'test'];
    }
}
