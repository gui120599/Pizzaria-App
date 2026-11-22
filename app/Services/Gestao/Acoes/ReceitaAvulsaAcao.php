<?php

namespace App\Services\Gestao\Acoes;

use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Exceptions\FinanceiroException;
use App\Models\Cliente;
use App\Models\ContaFinanceira;
use App\Models\Lancamento;
use App\Models\PlanoReceita;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Receita avulsa pelo chat (aluguel de espaço, evento, venda fora do PDV,
 * reembolso): título a receber com plano de receita, cliente opcional e
 * vencimento — parcelado se for o caso — e, se já recebido, a entrada na
 * conta escolhida. Permissão: criar lançamento; já recebido também exige
 * registrar pagamento (approve:lancamento).
 */
class ReceitaAvulsaAcao extends AcaoBase
{
    use ConfereTitulosFinanceiros;

    public function permitido(User $usuario): bool
    {
        return $this->pode($usuario, 'create:lancamento');
    }

    public function preparar(array $entrada, User $usuario): array
    {
        $dados = Validator::make($entrada, [
            'descricao' => ['required', 'string', 'max:255'],
            'valor' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'plano_receita_id' => ['required', 'integer'],
            'cliente_id' => ['nullable', 'integer'],
            'situacao' => ['required', Rule::in(['recebido', 'a_receber'])],
            'vencimento' => ['nullable', 'date_format:Y-m-d', Rule::requiredIf(($entrada['situacao'] ?? null) === 'a_receber' && blank($entrada['parcelas'] ?? null))],
            'data_recebimento' => ['nullable', 'date_format:Y-m-d'],
            'competencia' => ['nullable', 'date_format:Y-m'],
            'forma_pagamento' => ['nullable', Rule::in(self::formasAceitas())],
            'conta_financeira_id' => ['nullable', 'integer', 'required_if:situacao,recebido'],
            'numero_documento' => ['nullable', 'string', 'max:120'],
            'observacao' => ['nullable', 'string', 'max:1000'],
            ...self::regrasParcelas(),
        ], [
            'vencimento.required' => 'Informe o vencimento (AAAA-MM-DD) da receita a receber.',
            'conta_financeira_id.required_if' => 'Receita já recebida precisa da conta onde o dinheiro entrou (conta_financeira_id).',
            'plano_receita_id.required' => 'Informe o plano de receita (use opcoes_receita).',
        ])->validate();

        $plano = PlanoReceita::query()->findOr($dados['plano_receita_id'], fn () => throw ValidationException::withMessages(['plano_receita_id' => 'Plano de receita não encontrado: use opcoes_receita.']));
        $cliente = filled($dados['cliente_id'] ?? null)
            ? Cliente::query()->findOr($dados['cliente_id'], fn () => throw ValidationException::withMessages(['cliente_id' => 'Cliente não encontrado: use opcoes_receita com busca_cliente.']))
            : null;

        $recebido = $dados['situacao'] === 'recebido';
        $valor = round((float) $dados['valor'], 2);
        $dataRecebimento = $recebido ? Carbon::parse($dados['data_recebimento'] ?? today()->toDateString()) : null;
        $vencimento = Carbon::parse($dados['parcelas'][0]['vencimento'] ?? $dados['vencimento'] ?? $dataRecebimento->toDateString());
        $parcelas = $this->montarParcelas($dados, $valor, $vencimento);
        $vencimento = Carbon::parse($parcelas[0]['vencimento']);

        if ($recebido && count($parcelas) > 1) {
            throw ValidationException::withMessages(['numero_parcelas' => 'Receita já recebida não é parcelada: lance as parcelas a receber e registre o recebimento com preparar_baixa_titulos.']);
        }

        $competencia = filled($dados['competencia'] ?? null) ? Carbon::createFromFormat('Y-m', $dados['competencia'])->startOfMonth() : $vencimento->copy()->startOfMonth();
        $conta = filled($dados['conta_financeira_id'] ?? null) ? ContaFinanceira::query()->find($dados['conta_financeira_id']) : null;
        $alertas = [];

        if ($plano->filhos()->exists()) {
            $alertas[] = $this->alerta('plano_grupo', "\"{$plano->nome}\" é um grupo: escolha uma das contas dentro dele (opcoes_receita).", bloqueia: true);
        }

        if (filled($dados['numero_documento'] ?? null) && Lancamento::query()->where('tipo', TipoLancamento::Receber)->where('numero_documento', $dados['numero_documento'])->exists()) {
            $alertas[] = $this->alerta('documento_duplicado', 'Já existe um título a receber com este número de documento.', bloqueia: true);
        }

        if (Lancamento::query()->where('tipo', TipoLancamento::Receber)->where('descricao', 'like', $dados['descricao'].'%')
            ->whereDate('vencimento', $vencimento)->whereBetween('valor', [$parcelas[0]['valor'] - 0.01, $parcelas[0]['valor'] + 0.01])->exists()) {
            $alertas[] = $this->alerta('possivel_duplicidade', 'Já existe título a receber com a mesma descrição, valor e vencimento — confira se não é o mesmo.');
        }

        if (! $recebido && $vencimento->lt(today())) {
            $alertas[] = $this->alerta('vencida', 'O vencimento já passou: o título nasce vencido.');
        }

        $sessaoId = null;
        if ($recebido) {
            if (! $this->pode($usuario, 'approve:lancamento')) {
                $alertas[] = $this->alerta('sem_permissao_baixa', 'Seu perfil não registra recebimentos: lance como a receber.', bloqueia: true);
            }
            if ($dataRecebimento->gt(today())) {
                $alertas[] = $this->alerta('recebimento_futuro', 'Data de recebimento no futuro: lance como a receber.', bloqueia: true);
            }
            $sessaoId = $this->conferirConta($conta, 0, $dataRecebimento, $alertas);
        }

        $previa = [
            'descricao' => $dados['descricao'],
            'valor' => $valor,
            'plano_receita' => ['plano_receita_id' => $plano->id, 'nome' => trim(($plano->codigo ? "{$plano->codigo} " : '').$plano->nome)],
            'cliente' => $cliente ? ['cliente_id' => $cliente->id, 'nome' => $cliente->cliente_nome] : null,
            'vencimento' => $vencimento->toDateString(),
            'parcelas' => $parcelas,
            'competencia' => $competencia->format('Y-m'),
            'numero_documento' => $dados['numero_documento'] ?? null,
            'recebimento' => [
                'situacao' => $dados['situacao'],
                'data' => $dataRecebimento?->toDateString(),
                'forma_pagamento' => $dados['forma_pagamento'] ?? null,
                'conta_financeira_id' => $conta?->id,
                'conta' => $conta?->nome,
                'sessao_caixa_id' => $sessaoId,
            ],
            'alertas' => $alertas,
        ];
        $previa['resumo_texto'] = $this->resumo($previa);

        return ['entrada' => $dados, 'previa' => $previa];
    }

    public function executar(array $entrada, array $previa, User $usuario): array
    {
        $recebimento = $previa['recebimento'];
        $forma = filled($recebimento['forma_pagamento']) ? FormaPagamento::from($recebimento['forma_pagamento']) : null;
        $observacoes = trim(($entrada['observacao'] ?? '')."\nLançado pelo Claude (chat).");
        $parcelas = $previa['parcelas'];

        try {
            $titulos = collect($parcelas)->map(fn (array $parcela): Lancamento => Lancamento::create([
                'tipo' => TipoLancamento::Receber,
                'descricao' => self::descricaoParcela($previa['descricao'], $parcela['numero'], count($parcelas)),
                'plano_receita_id' => $previa['plano_receita']['plano_receita_id'],
                'cliente_id' => $previa['cliente']['cliente_id'] ?? null,
                'numero_documento' => $previa['numero_documento'],
                'valor' => $parcela['valor'],
                'vencimento' => $parcela['vencimento'],
                'parcela_numero' => count($parcelas) > 1 ? $parcela['numero'] : null,
                'parcela_total' => count($parcelas) > 1 ? count($parcelas) : null,
                'competencia' => $previa['competencia'].'-01',
                'status' => StatusLancamento::Pendente,
                'forma_pagamento' => $forma,
                'observacoes' => $observacoes,
            ]));

            if ($recebimento['situacao'] === 'recebido') {
                $titulos->first()->registrarPagamento(
                    valor: $previa['valor'],
                    data: Carbon::parse($recebimento['data']),
                    forma: $forma,
                    sessaoCaixaId: $recebimento['sessao_caixa_id'],
                    contaFinanceiraId: $recebimento['conta_financeira_id'],
                );
            }
        } catch (FinanceiroException $e) {
            throw ValidationException::withMessages(['recebimento' => $e->getMessage()]);
        }

        return [
            'titulos' => $titulos->map->refresh()->map(fn (Lancamento $t): array => [
                'lancamento_id' => $t->id,
                'valor' => (float) $t->valor,
                'vencimento' => $t->vencimento?->toDateString(),
                'status' => $t->status->getLabel(),
            ])->all(),
        ];
    }

    /** @param  array<string, mixed>  $previa */
    private function resumo(array $previa): string
    {
        $recebimento = $previa['recebimento'];
        $situacao = $recebimento['situacao'] === 'recebido'
            ? 'recebido em '.Carbon::parse($recebimento['data'])->format('d/m/Y').($recebimento['conta'] ? " na conta {$recebimento['conta']}" : '').$this->rotuloForma($recebimento['forma_pagamento'])
            : (count($previa['parcelas']) > 1
                ? 'a receber em '.count($previa['parcelas']).' parcelas'
                : 'a receber, vence '.Carbon::parse($previa['vencimento'])->format('d/m/Y'));

        $linhas = [
            "{$previa['descricao']} · ".$this->moeda($previa['valor'])." · {$situacao}",
            'Cliente: '.($previa['cliente']['nome'] ?? '—')
                ." · Plano: {$previa['plano_receita']['nome']} · Competência ".Carbon::createFromFormat('Y-m', $previa['competencia'])->format('m/Y'),
        ];
        if ($previa['numero_documento']) {
            $linhas[] = "Documento: {$previa['numero_documento']}";
        }
        array_push($linhas, ...$this->linhasParcelas($previa['parcelas']));

        foreach ($previa['alertas'] as $alerta) {
            $linhas[] = ($alerta['bloqueia'] ? '⛔ ' : '⚠️ ').$alerta['mensagem'];
        }

        return implode("\n", $linhas);
    }
}
