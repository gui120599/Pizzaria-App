<?php

namespace App\Services\Gestao\Acoes;

use App\Enums\FormaPagamento;
use App\Enums\PrestadorCategoriaEnum;
use App\Enums\PrestadorTipoEnum;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Exceptions\FinanceiroException;
use App\Models\ContaFinanceira;
use App\Models\Lancamento;
use App\Models\PlanoDespesa;
use App\Models\Prestador;
use App\Models\User;
use App\Services\Compras\NormalizadorDescricao;
use App\Services\Financeiro\CaixaPdvBridge;
use App\Services\Financeiro\DespesaCaixaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Despesa avulsa pelo chat (foto do boleto, conta de luz/gás, recibo): um
 * título a pagar com favorecido, plano de despesa e vencimento — e, se já
 * foi paga, a baixa na conta escolhida (Caixa PDV vira retirada da sessão,
 * como o "Pagamento de despesa" do caixa). Permissão: criar lançamento; para
 * já pago, também registrar pagamento (approve:lancamento). A pagar pode
 * ser parcelada (parcelas mensais iguais ou a lista de vencimentos).
 */
class DespesaAvulsaAcao extends AcaoBase
{
    use ConfereTitulosFinanceiros;

    public function __construct(
        private readonly CaixaPdvBridge $caixa,
        private readonly DespesaCaixaService $despesaCaixa,
    ) {}

    public function permitido(User $usuario): bool
    {
        return $this->pode($usuario, 'create:lancamento');
    }

    public function preparar(array $entrada, User $usuario): array
    {
        $dados = Validator::make($entrada, [
            'descricao' => ['required', 'string', 'max:255'],
            'valor' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'plano_despesa_id' => ['required', 'integer'],
            'favorecido_id' => ['nullable', 'integer'],
            'favorecido_novo' => ['nullable', 'array'],
            'favorecido_novo.nome' => ['required_with:favorecido_novo', 'string', 'max:150'],
            'favorecido_novo.telefone' => ['nullable', 'string', 'max:30'],
            'situacao_pagamento' => ['required', Rule::in(['pago', 'a_pagar'])],
            'vencimento' => ['nullable', 'date_format:Y-m-d', Rule::requiredIf(($entrada['situacao_pagamento'] ?? null) === 'a_pagar' && blank($entrada['parcelas'] ?? null))],
            'data_pagamento' => ['nullable', 'date_format:Y-m-d'],
            'competencia' => ['nullable', 'date_format:Y-m'],
            'forma_pagamento' => ['nullable', Rule::in(self::formasAceitas())],
            'conta_financeira_id' => ['nullable', 'integer', 'required_if:situacao_pagamento,pago'],
            'numero_documento' => ['nullable', 'string', 'max:120'],
            'observacao' => ['nullable', 'string', 'max:1000'],
            ...self::regrasParcelas(),
        ], [
            'vencimento.required' => 'Informe o vencimento (AAAA-MM-DD) da despesa a pagar.',
            'conta_financeira_id.required_if' => 'Despesa já paga precisa da conta de onde saiu o dinheiro (conta_financeira_id).',
            'plano_despesa_id.required' => 'Informe o plano de despesa (use opcoes_despesa).',
        ])->validate();

        if (filled($dados['favorecido_id'] ?? null) && filled($dados['favorecido_novo'] ?? null)) {
            throw ValidationException::withMessages(['favorecido_id' => 'Informe favorecido_id OU favorecido_novo, não os dois.']);
        }

        $plano = PlanoDespesa::query()->findOr($dados['plano_despesa_id'], fn () => throw ValidationException::withMessages(['plano_despesa_id' => 'Plano de despesa não encontrado: use opcoes_despesa.']));
        $favorecido = filled($dados['favorecido_id'] ?? null)
            ? Prestador::query()->favorecidos()->findOr($dados['favorecido_id'], fn () => throw ValidationException::withMessages(['favorecido_id' => 'Favorecido não encontrado: use opcoes_despesa com busca_favorecido.']))
            : null;

        $pago = $dados['situacao_pagamento'] === 'pago';
        $valor = round((float) $dados['valor'], 2);
        $dataPagamento = $pago ? Carbon::parse($dados['data_pagamento'] ?? today()->toDateString()) : null;
        $vencimento = Carbon::parse($dados['parcelas'][0]['vencimento'] ?? $dados['vencimento'] ?? $dataPagamento->toDateString());
        $parcelas = $this->montarParcelas($dados, $valor, $vencimento);
        $vencimento = Carbon::parse($parcelas[0]['vencimento']);

        if ($pago && count($parcelas) > 1) {
            throw ValidationException::withMessages(['numero_parcelas' => 'Despesa já paga não é parcelada: lance as parcelas como a pagar e registre o pagamento da primeira com preparar_baixa_titulos.']);
        }
        $competencia = filled($dados['competencia'] ?? null) ? Carbon::createFromFormat('Y-m', $dados['competencia'])->startOfMonth() : $vencimento->copy()->startOfMonth();
        $conta = filled($dados['conta_financeira_id'] ?? null) ? ContaFinanceira::query()->find($dados['conta_financeira_id']) : null;
        $alertas = [];

        if ($plano->filhos()->exists()) {
            $alertas[] = $this->alerta('plano_grupo', "\"{$plano->nome}\" é um grupo: escolha uma das contas dentro dele (opcoes_despesa).", bloqueia: true);
        }

        if (filled($dados['numero_documento'] ?? null) && Lancamento::query()->where('tipo', TipoLancamento::Pagar)->where('numero_documento', $dados['numero_documento'])->exists()) {
            $alertas[] = $this->alerta('documento_duplicado', 'Já existe um título a pagar com este número de documento/código de barras.', bloqueia: true);
        }

        if ($favorecido !== null && Lancamento::query()->where('tipo', TipoLancamento::Pagar)->where('favorecido_id', $favorecido->id)
            ->whereDate('vencimento', $vencimento)->whereBetween('valor', [$valor - 0.01, $valor + 0.01])->exists()) {
            $alertas[] = $this->alerta('possivel_duplicidade', 'Já existe título deste favorecido com o mesmo valor e vencimento — confira se não é a mesma conta.');
        }

        if (filled($dados['favorecido_novo'] ?? null) && ($parecidos = $this->favorecidosParecidos($dados['favorecido_novo']['nome'])) !== []) {
            $alertas[] = $this->alerta('favorecido_parecido', 'Já existem favorecidos com nome parecido: '.collect($parecidos)->map(fn (array $p): string => "{$p['id']} = {$p['nome']}")->implode('; ').'. Se for um deles, use favorecido_id.');
        }

        if (! $pago && $vencimento->lt(today())) {
            $alertas[] = $this->alerta('vencida', 'O vencimento já passou: o título nasce vencido.');
        }

        $sessaoId = null;
        if ($pago) {
            if (! $this->pode($usuario, 'approve:lancamento')) {
                $alertas[] = $this->alerta('sem_permissao_baixa', 'Seu perfil não registra pagamentos: lance como a pagar.', bloqueia: true);
            }
            if ($dataPagamento->gt(today())) {
                $alertas[] = $this->alerta('pagamento_futuro', 'Data de pagamento no futuro: lance como a pagar.', bloqueia: true);
            }
            $sessaoId = $this->conferirConta($conta, $valor, $dataPagamento, $alertas);
            if ($conta !== null && ! $conta->ehCaixaPdv() && $dataPagamento->lt(today()->subDays(60))) {
                $alertas[] = $this->alerta('pagamento_antigo', 'Data de pagamento com mais de 60 dias: confira.');
            }
        }

        $previa = [
            'descricao' => $dados['descricao'],
            'valor' => $valor,
            'plano_despesa' => ['plano_despesa_id' => $plano->id, 'nome' => trim(($plano->codigo ? "{$plano->codigo} " : '').$plano->nome)],
            'favorecido' => $favorecido
                ? ['favorecido_id' => $favorecido->id, 'nome' => $favorecido->nome_exibicao, 'novo' => false]
                : (filled($dados['favorecido_novo'] ?? null) ? ['favorecido_id' => null, 'nome' => $dados['favorecido_novo']['nome'], 'novo' => true] : null),
            'vencimento' => $vencimento->toDateString(),
            'parcelas' => $parcelas,
            'competencia' => $competencia->format('Y-m'),
            'numero_documento' => $dados['numero_documento'] ?? null,
            'pagamento' => [
                'situacao' => $dados['situacao_pagamento'],
                'data' => $dataPagamento?->toDateString(),
                'forma_pagamento' => $dados['forma_pagamento'] ?? null,
                'conta_financeira_id' => $conta?->id,
                'conta' => $conta?->nome,
                'caixa_pdv' => (bool) $conta?->ehCaixaPdv(),
                'sessao_caixa_id' => $sessaoId,
            ],
            'alertas' => $alertas,
        ];
        $previa['resumo_texto'] = $this->resumo($previa);

        return ['entrada' => $dados, 'previa' => $previa];
    }

    public function executar(array $entrada, array $previa, User $usuario): array
    {
        $favorecidoId = $previa['favorecido']['favorecido_id'] ?? null;
        if (($previa['favorecido']['novo'] ?? false) === true) {
            $favorecidoId = Prestador::query()->create([
                'tipo' => PrestadorTipoEnum::PF,
                'categoria' => PrestadorCategoriaEnum::FORNECEDOR,
                'avulso' => true,
                'nome' => $entrada['favorecido_novo']['nome'],
                'celular' => $entrada['favorecido_novo']['telefone'] ?? null,
            ])->id;
        }

        $pagamento = $previa['pagamento'];
        $forma = filled($pagamento['forma_pagamento']) ? FormaPagamento::from($pagamento['forma_pagamento']) : null;
        $observacoes = trim(($entrada['observacao'] ?? '')."\nLançado pelo Claude (chat).");

        try {
            if ($pagamento['situacao'] === 'pago' && $pagamento['caixa_pdv']) {
                $sessao = $this->caixa->sessaoParaMovimentar();
                $baixa = $this->despesaCaixa->pagar($sessao, $previa['valor'], novoTitulo: [
                    'descricao' => $previa['descricao'],
                    'plano_despesa_id' => $previa['plano_despesa']['plano_despesa_id'],
                    'favorecido_id' => $favorecidoId,
                    'observacoes' => $observacoes,
                ]);
                $titulo = $baixa->lancamento;
                $titulo->forceFill(['numero_documento' => $previa['numero_documento'], 'competencia' => $previa['competencia'].'-01'])->save();
            } else {
                $parcelas = $previa['parcelas'] ?? [['numero' => 1, 'vencimento' => $previa['vencimento'], 'valor' => $previa['valor']]];
                $titulos = collect($parcelas)->map(fn (array $parcela): Lancamento => Lancamento::create([
                    'tipo' => TipoLancamento::Pagar,
                    'descricao' => self::descricaoParcela($previa['descricao'], $parcela['numero'], count($parcelas)),
                    'plano_despesa_id' => $previa['plano_despesa']['plano_despesa_id'],
                    'favorecido_id' => $favorecidoId,
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
                $titulo = $titulos->first();

                if ($pagamento['situacao'] === 'pago') {
                    $titulo->registrarPagamento(
                        valor: $previa['valor'],
                        data: Carbon::parse($pagamento['data']),
                        forma: $forma,
                        contaFinanceiraId: $pagamento['conta_financeira_id'],
                    );
                }
            }
        } catch (FinanceiroException $e) {
            throw ValidationException::withMessages(['pagamento' => $e->getMessage()]);
        }

        $titulos = isset($titulos) ? $titulos->map->refresh() : collect([$titulo->refresh()]);

        return [
            'lancamento_id' => $titulos->first()->id,
            'titulos' => $titulos->map(fn (Lancamento $t): array => [
                'lancamento_id' => $t->id,
                'valor' => (float) $t->valor,
                'vencimento' => $t->vencimento?->toDateString(),
                'status' => $t->status->getLabel(),
            ])->all(),
            'favorecido_id' => $favorecidoId,
        ];
    }

    /** @return list<array{id: int, nome: string}> */
    private function favorecidosParecidos(string $nome): array
    {
        $chave = NormalizadorDescricao::normalizar($nome);
        $primeira = explode(' ', $chave)[0] ?? '';
        if (mb_strlen($primeira) < 3) {
            return [];
        }

        return Prestador::query()->favorecidos()
            ->where(fn ($q) => $q->where('nome', 'like', "%{$primeira}%")->orWhere('nome_fantasia', 'like', "%{$primeira}%")->orWhere('razao_social', 'like', "%{$primeira}%"))
            ->limit(5)->get()
            ->map(fn (Prestador $p): array => ['id' => $p->id, 'nome' => $p->nome_exibicao])
            ->all();
    }

    /** @param  array<string, mixed>  $previa */
    private function resumo(array $previa): string
    {
        $pagamento = $previa['pagamento'];
        $situacao = $pagamento['situacao'] === 'pago'
            ? 'pago em '.Carbon::parse($pagamento['data'])->format('d/m/Y').($pagamento['conta'] ? " de {$pagamento['conta']}" : '')
                .$this->rotuloForma($pagamento['forma_pagamento'])
            : (count($previa['parcelas']) > 1
                ? 'a pagar em '.count($previa['parcelas']).' parcelas'
                : 'a pagar, vence '.Carbon::parse($previa['vencimento'])->format('d/m/Y'));

        $linhas = [
            "{$previa['descricao']} · ".$this->moeda($previa['valor'])." · {$situacao}",
            'Favorecido: '.($previa['favorecido'] ? $previa['favorecido']['nome'].($previa['favorecido']['novo'] ? ' (será cadastrado)' : '') : '—')
                ." · Plano: {$previa['plano_despesa']['nome']} · Competência ".Carbon::createFromFormat('Y-m', $previa['competencia'])->format('m/Y'),
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
