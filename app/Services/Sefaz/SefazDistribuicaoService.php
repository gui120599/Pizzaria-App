<?php

namespace App\Services\Sefaz;

use App\Exceptions\NfeXmlInvalidoException;
use App\Exceptions\SefazDocumentoAindaNaoDisponivelException;
use App\Exceptions\SefazIndisponivelException;
use App\Models\Compra;
use App\Models\Empresa;
use App\Models\SefazDocumentoPendente;
use App\Services\Nfe\NfeImportService;
use App\Services\Sefaz\Contracts\SefazClient;
use App\Services\Sefaz\Dto\SefazDocumentoCompleto;
use App\Services\Sefaz\Dto\SefazPollingResultado;
use App\Services\Sefaz\Dto\SefazResumoDocumento;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Orquestrador da integração SEFAZ: busca avulsa por chave e polling
 * agendado por NSU. Só produz XML — quem persiste Compra/CompraItem
 * continua sendo o NfeImportService já existente (nenhuma lógica de
 * fornecedor/produto/estoque é duplicada aqui).
 */
class SefazDistribuicaoService
{
    public function __construct(
        private SefazClient $client,
        private NfeImportService $importador,
    ) {}

    /**
     * @throws SefazDocumentoAindaNaoDisponivelException se a SEFAZ não liberar o XML completo a tempo
     * @throws SefazIndisponivelException
     */
    public function buscarPorChave(string $chave, ?int $userId = null): Compra
    {
        $resultado = $this->client->consultarPorChave($chave);

        if ($resultado instanceof SefazDocumentoCompleto) {
            return $this->importador->importar($resultado->xmlCompleto, $userId);
        }

        // Só veio resumo: manifesta ciência e tenta de novo com retry curto
        // (ação síncrona disparada por usuário — aceitável aguardar um pouco).
        $this->client->manifestarCiencia($chave);

        foreach ($this->intervalosRetry() as $segundos) {
            sleep($segundos);

            $resultado = $this->client->consultarPorChave($chave);
            if ($resultado instanceof SefazDocumentoCompleto) {
                return $this->importador->importar($resultado->xmlCompleto, $userId);
            }
        }

        throw new SefazDocumentoAindaNaoDisponivelException(
            'Nota manifestada, mas a SEFAZ ainda não liberou o XML completo. Tente novamente em alguns minutos.',
        );
    }

    /** Polling agendado: reprocessa pendências, depois consulta novidades por NSU. */
    public function executarPolling(?int $userId = null): SefazPollingResultado
    {
        $empresa = Empresa::firstOrFail();
        $resultado = new SefazPollingResultado;
        $cap = (int) config('sefaz.max_documentos_por_execucao', 50);
        $processados = 0;

        foreach (SefazDocumentoPendente::query()->cursor() as $pendente) {
            if ($processados >= $cap) {
                break;
            }
            $resultado = $this->reprocessarPendente($pendente, $userId, $resultado);
            $processados++;
        }

        $ultNsu = (int) $empresa->empresa_sefaz_ultimo_nsu;

        do {
            $lote = $this->client->consultarPorNsu($ultNsu);

            foreach ($lote->itens as $item) {
                if ($processados >= $cap) {
                    break 2;
                }
                $resultado = $this->processarResumo($item, $userId, $resultado);
                $processados++;
            }

            $ultNsu = $lote->ultNsuRetornado;
        } while ($lote->temMais() && $processados < $cap);

        // Só avança o cursor depois de processar o lote inteiro sem exceção
        // fatal — um erro de infraestrutura no meio do caminho não perde NSU.
        $empresa->forceFill([
            'empresa_sefaz_ultimo_nsu' => $ultNsu,
            'empresa_sefaz_ultima_consulta_em' => now(),
        ])->save();

        return $resultado;
    }

    private function processarResumo(SefazResumoDocumento $item, ?int $userId, SefazPollingResultado $resultado): SefazPollingResultado
    {
        if ($item->isEvento) {
            return $resultado->comIncremento('puladas');
        }

        if (Compra::where('compra_chave_nfe', $item->chaveAcesso)->exists()) {
            return $resultado->comIncremento('puladas');
        }

        try {
            $this->client->manifestarCiencia($item->chaveAcesso);
            $completo = $this->client->consultarPorChave($item->chaveAcesso);

            if ($completo instanceof SefazDocumentoCompleto) {
                $this->importador->importar($completo->xmlCompleto, $userId);

                return $resultado->comIncremento('importadas');
            }

            $this->registrarPendente($item->chaveAcesso, $item->cnpjEmitente);

            return $resultado->comIncremento('pendentes');
        } catch (SefazIndisponivelException $e) {
            Log::channel('sefaz')->warning("Polling: erro pontual na chave {$item->chaveAcesso} — {$e->getMessage()}");

            return $resultado->comIncremento('erros');
        } catch (NfeXmlInvalidoException|ValidationException $e) {
            Log::channel('sefaz')->warning("Polling: nota {$item->chaveAcesso} rejeitada pelo importador — {$e->getMessage()}");

            return $resultado->comIncremento('erros');
        }
    }

    private function reprocessarPendente(SefazDocumentoPendente $pendente, ?int $userId, SefazPollingResultado $resultado): SefazPollingResultado
    {
        try {
            $completo = $this->client->consultarPorChave($pendente->sdp_chave_acesso);

            if ($completo instanceof SefazDocumentoCompleto) {
                $this->importador->importar($completo->xmlCompleto, $userId);
                $pendente->delete();

                return $resultado->comIncremento('importadas');
            }

            $pendente->forceFill([
                'sdp_tentativas' => $pendente->sdp_tentativas + 1,
                'sdp_ultima_tentativa_em' => now(),
            ])->save();

            return $resultado->comIncremento('pendentes');
        } catch (SefazIndisponivelException|NfeXmlInvalidoException|ValidationException $e) {
            Log::channel('sefaz')->warning("Polling: erro reprocessando pendente {$pendente->sdp_chave_acesso} — {$e->getMessage()}");

            return $resultado->comIncremento('erros');
        }
    }

    private function registrarPendente(string $chave, ?string $cnpjEmitente): void
    {
        SefazDocumentoPendente::updateOrCreate(
            ['sdp_chave_acesso' => $chave],
            [
                'sdp_cnpj_emitente' => $cnpjEmitente,
                'sdp_manifestado_em' => now(),
            ],
        );
    }

    /** @return int[] */
    private function intervalosRetry(): array
    {
        return config('sefaz.intervalo_pos_manifestacao', [2, 5, 10]);
    }
}
