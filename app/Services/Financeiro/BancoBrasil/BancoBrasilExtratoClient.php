<?php

namespace App\Services\Financeiro\BancoBrasil;

use App\Exceptions\FinanceiroException;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Cliente da API Extratos do Banco do Brasil (OAuth client credentials +
 * consulta paginada do extrato da conta corrente). Endereços, escopo,
 * parâmetros e certificado vêm de config/bancos.php — conferir no Portal
 * Developers BB antes de ativar.
 */
class BancoBrasilExtratoClient
{
    private const CACHE_TOKEN = 'bancos.bb.token';

    public function configurado(): bool
    {
        return filled($this->config('client_id')) && filled($this->config('client_secret')) && filled($this->config('developer_app_key'));
    }

    /**
     * Lançamentos de $de até $ate (inclusive), todas as páginas, sem as linhas de saldo.
     *
     * @return Collection<int, LancamentoExtrato>
     *
     * @throws FinanceiroException
     */
    public function extrato(string $agencia, string $conta, Carbon $de, Carbon $ate): Collection
    {
        if (! $this->configurado()) {
            throw new FinanceiroException('Integração com o Banco do Brasil não configurada (BB_CLIENT_ID, BB_CLIENT_SECRET, BB_DEVELOPER_APP_KEY).');
        }

        $url = rtrim($this->url('api'), '/').'/conta-corrente/agencia/'.ltrim($agencia, '0').'/conta/'.ltrim($conta, '0');
        $lancamentos = collect();
        $pagina = 1;

        do {
            $resposta = $this->requisicao()
                ->withToken($this->token())
                ->get($url, [
                    $this->config('parametro_app_key') => $this->config('developer_app_key'),
                    'dataInicioSolicitacao' => $this->data($de),
                    'dataFimSolicitacao' => $this->data($ate),
                    'numeroPaginaSolicitacao' => $pagina,
                    'quantidadeRegistroPaginaSolicitacao' => $this->config('registros_por_pagina', 200),
                ]);

            if ($resposta->status() === 401) {
                Cache::forget(self::CACHE_TOKEN);
            }

            $this->garantirSucesso($resposta->status(), (string) $resposta->body());

            foreach ((array) $resposta->json('listaLancamento', []) as $linha) {
                $lancamento = $this->normalizar((array) $linha);
                if ($lancamento !== null) {
                    $lancamentos->push($lancamento);
                }
            }

            $proxima = (int) $resposta->json('numeroPaginaProximo', 0);
            $pagina = $proxima > $pagina ? $proxima : 0;
        } while ($pagina > 0);

        return $lancamentos;
    }

    /** @throws FinanceiroException */
    private function token(): string
    {
        return Cache::remember(self::CACHE_TOKEN, now()->addMinutes(9), function (): string {
            try {
                $resposta = $this->requisicao()
                    ->asForm()
                    ->withBasicAuth((string) $this->config('client_id'), (string) $this->config('client_secret'))
                    ->post($this->url('token'), [
                        'grant_type' => 'client_credentials',
                        'scope' => $this->config('escopo'),
                    ])
                    ->throw();
            } catch (RequestException $e) {
                throw new FinanceiroException('Banco do Brasil recusou a autenticação (HTTP '.$e->response->status().'). Confira client_id, client_secret e escopo.');
            }

            $token = (string) $resposta->json('access_token');
            if ($token === '') {
                throw new FinanceiroException('Banco do Brasil não devolveu o token de acesso.');
            }

            return $token;
        });
    }

    private function requisicao(): PendingRequest
    {
        $opcoes = [];
        $certificado = $this->config('mtls.certificado');

        if (filled($certificado)) {
            $senha = $this->config('mtls.senha');
            $opcoes['cert'] = filled($senha) ? [storage_path('app/'.$certificado), $senha] : storage_path('app/'.$certificado);

            if (filled($this->config('mtls.chave'))) {
                $opcoes['ssl_key'] = storage_path('app/'.$this->config('mtls.chave'));
            }
        }

        return Http::timeout((int) $this->config('timeout', 30))->acceptJson()->withOptions($opcoes);
    }

    /** @param  array<string, mixed>  $linha */
    private function normalizar(array $linha): ?LancamentoExtrato
    {
        $historico = trim((string) ($linha['textoDescricaoHistorico'] ?? ''));
        $data = $this->lerData($linha['dataLancamento'] ?? null);
        $valor = abs((float) ($linha['valorLancamento'] ?? 0));

        $ignorados = array_map('mb_strtoupper', (array) $this->config('historicos_ignorados', []));
        if ($data === null || $valor <= 0 || in_array(mb_strtoupper($historico), $ignorados, true)) {
            return null;
        }

        $natureza = mb_strtoupper(trim((string) ($linha['indicadorSinalLancamento'] ?? '')));

        return new LancamentoExtrato(
            dataLancamento: $data,
            dataMovimento: $this->lerData($linha['dataMovimento'] ?? null),
            valor: round($valor, 2),
            natureza: $natureza === 'C' ? 'C' : 'D',
            historico: mb_substr($historico, 0, 120),
            documento: filled($linha['numeroDocumento'] ?? null) ? mb_substr((string) $linha['numeroDocumento'], 0, 40) : null,
            infoComplementar: filled($linha['textoInformacaoComplementar'] ?? null) ? mb_substr(trim((string) $linha['textoInformacaoComplementar']), 0, 255) : null,
            payload: $linha,
        );
    }

    /** DDMMAAAA (inteiro ou texto, com ou sem zero à esquerda no dia). */
    private function lerData(mixed $valor): ?Carbon
    {
        $texto = preg_replace('/\D/', '', (string) $valor) ?? '';
        if ($texto === '' || (int) $texto === 0) {
            return null;
        }

        $texto = str_pad($texto, 8, '0', STR_PAD_LEFT);

        try {
            return Carbon::createFromFormat('dmY', $texto)->startOfDay();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private function data(Carbon $data): string
    {
        $texto = $data->format('dmY');

        return $this->config('data_sem_zero_a_esquerda', true) ? ltrim($texto, '0') : $texto;
    }

    /** @throws FinanceiroException */
    private function garantirSucesso(int $status, string $corpo): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }

        throw new FinanceiroException("API Extratos do Banco do Brasil respondeu HTTP {$status}: ".mb_substr($corpo, 0, 300));
    }

    private function url(string $tipo): string
    {
        $ambiente = $this->config('ambiente') === 'producao' ? 'producao' : 'homologacao';

        return (string) $this->config("urls.{$ambiente}.{$tipo}");
    }

    private function config(string $chave, mixed $padrao = null): mixed
    {
        return config("bancos.banco_brasil.{$chave}", $padrao);
    }
}
