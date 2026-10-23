<?php

namespace App\Services\Financeiro;

use App\Enums\Financeiro\NaturezaMovimentacao;
use App\Enums\FormaPagamento;
use App\Enums\FormaTrocoEnum;
use App\Enums\StatusFechamentoCaixa;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Exceptions\FinanceiroException;
use App\Models\ContaFinanceira;
use App\Models\Empresa;
use App\Models\FechamentoCaixa;
use App\Models\Lancamento;
use App\Models\Maquininha;
use App\Models\MovimentacaoFinanceira;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\OpcoesPagamento;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Services\FechamentoCaixaService;
use App\Services\RelatorioFechamentoCaixaService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Leva o que a sessão de caixa vendeu para as contas financeiras na
 * confirmação do fechamento (ver docs/planos/contabilizacao-vendas-contas-financeiras.md).
 */
class ContabilizacaoFechamentoService
{
    /**
     * O que falta configurar para contabilizar a sessão, em mensagens legíveis
     * (vazio = pode confirmar). Olha só o que a sessão usou: forma sem conta
     * que ninguém vendeu não bloqueia.
     *
     * @return array<int, string>
     */
    public function pendenciasDeConfiguracao(SessaoCaixa $sessao): array
    {
        $pagamentos = $this->pagamentosDaSessao($sessao);

        $opcoes = OpcoesPagamento::withTrashed()->whereIn('id', $pagamentos->pluck('opcao_id')->filter()->unique())->get()->keyBy('id');
        $maquininhas = Maquininha::withTrashed()->with('contaFinanceira')->get()->keyBy('id');
        $pendencias = [];

        foreach ($pagamentos->groupBy('opcao_id') as $opcaoId => $doGrupo) {
            $opcao = $opcoes->get($opcaoId);
            $nome = $doGrupo->first()['forma'];
            $ehDinheiro = $opcao?->opcaopag_desc_nfe === 'cash';

            if ($opcao !== null && $opcao->plano_receita_id === null) {
                $pendencias[] = "A forma de pagamento {$nome} está sem plano de receita.";
            }

            if ($ehDinheiro) {
                if (ContaFinanceira::caixaPdv() === null) {
                    $pendencias[] = 'Não existe a conta Caixa PDV: cadastre uma conta do tipo Caixa PDV.';
                }

                continue;
            }

            if ($doGrupo->first()['tipo'] !== null) {
                foreach ($doGrupo->pluck('maquininha_id')->unique() as $maquininhaId) {
                    $maquininha = $maquininhas->get($maquininhaId);

                    if ($maquininha === null) {
                        $pendencias[] = "Há pagamentos em {$nome} sem maquininha identificada: marque uma maquininha padrão.";
                    } elseif ($maquininha->conta_financeira_id === null || $maquininha->contaFinanceira === null) {
                        $pendencias[] = "A maquininha {$maquininha->nome} está sem conta financeira.";
                    }
                }

                continue;
            }

            if ($opcao?->conta_financeira_id === null || $opcao->contaFinanceira === null) {
                $pendencias[] = "A forma de pagamento {$nome} está sem conta financeira.";
            }
        }

        if ($pagamentos->contains(fn (array $pagamento): bool => $pagamento['troco'] > 0 && $pagamento['forma_troco'] === FormaTrocoEnum::Pix) && $this->contaDoTrocoPix() === null) {
            $pendencias[] = 'Há troco devolvido em Pix, mas nenhuma forma PIX CNPJ tem conta financeira.';
        }

        $empresa = Empresa::query()->first();

        if ($pagamentos->contains(fn (array $pagamento): bool => $pagamento['tipo'] !== null && $pagamento['taxa'] > 0) && $empresa?->empresa_plano_despesa_taxa_cartao_id === null) {
            $pendencias[] = 'A taxa de cartão está sem plano de despesa: configure em Configurações › Certificado SEFAZ.';
        }

        $diferenca = $sessao->fechamentoCaixa?->diferencaDinheiro ?? 0.0;

        if ($diferenca < 0 && $empresa?->empresa_plano_despesa_quebra_caixa_id === null) {
            $pendencias[] = 'A quebra de caixa está sem plano de despesa: configure em Configurações › Certificado SEFAZ.';
        }

        if ($diferenca > 0 && $empresa?->empresa_plano_receita_sobra_caixa_id === null) {
            $pendencias[] = 'A sobra de caixa está sem plano de receita: configure em Configurações › Certificado SEFAZ.';
        }

        return array_values(array_unique($pendencias));
    }

    /** Mensagens de aviso (não bloqueiam) da última contabilização feita por esta instância. */
    public array $avisos = [];

    /** A sessão já tem títulos de contabilização ativos (os estornados ficam Cancelados). */
    public function contabilizada(SessaoCaixa $sessao): bool
    {
        return $this->titulosAtivos($sessao)->exists();
    }

    /** @return HasMany<Lancamento, SessaoCaixa> */
    private function titulosAtivos(SessaoCaixa $sessao)
    {
        return $sessao->lancamentos()->where('status', '!=', StatusLancamento::Cancelado);
    }

    public function podeContabilizar(SessaoCaixa $sessao): bool
    {
        $fechamento = $sessao->fechamentoCaixa;

        if (! $fechamento || $fechamento->status !== StatusFechamentoCaixa::Confirmado) {
            return false;
        }

        if ($this->contabilizada($sessao)) {
            return false;
        }

        return app(FechamentoCaixaService::class)->calcularReceitaVendas($sessao)->sum('total') > 0;
    }

    /**
     * Gera os títulos da sessão e as movimentações nas contas, numa única
     * transação (falha em qualquer conta desfaz tudo). Idempotente: sessão que
     * já tem títulos devolve os existentes sem gravar nada.
     *
     * @return Collection<int, Lancamento>
     *
     * @throws ValidationException fechamento não Confirmado, configuração pendente, troco inválido
     * @throws FinanceiroException a conferência do Caixa PDV não fecha
     */
    public function contabilizar(SessaoCaixa $sessao): Collection
    {
        return DB::transaction(function () use ($sessao): Collection {
            $this->avisos = [];
            $sessao->refresh();
            $fechamento = $sessao->fechamentoCaixa;

            if (! $fechamento || $fechamento->status !== StatusFechamentoCaixa::Confirmado) {
                throw ValidationException::withMessages(['sessao' => 'Só é possível contabilizar sessões com o fechamento de caixa Confirmado.']);
            }

            if ($this->contabilizada($sessao)) {
                return $this->titulosAtivos($sessao)->get();
            }

            $pendencias = $this->pendenciasDeConfiguracao($sessao);

            if ($pendencias !== []) {
                throw ValidationException::withMessages(['sessao' => implode(' ', $pendencias)]);
            }

            if (app(FechamentoCaixaService::class)->calcularReceitaVendas($sessao)->sum('total') <= 0) {
                throw ValidationException::withMessages(['sessao' => 'Esta sessão não tem receita de vendas para contabilizar.']);
            }

            $dataFechamento = ($fechamento->confirmado_em ?? now())->copy();
            $pagamentos = $this->pagamentosDaSessao($sessao);
            $opcoes = OpcoesPagamento::withTrashed()->whereIn('id', $pagamentos->pluck('opcao_id')->filter()->unique())->get()->keyBy('id');
            $caixaPdv = ContaFinanceira::caixaPdv();
            $lancamentos = collect();

            foreach ($pagamentos->groupBy('opcao_id') as $opcaoId => $doGrupo) {
                $opcao = $opcoes->get($opcaoId);

                if ($doGrupo->first()['tipo'] !== null) {
                    $lancamentos = $lancamentos->concat($this->recebiveisDaOpcao($sessao, $opcao, $doGrupo, $dataFechamento));

                    continue;
                }

                $total = round($doGrupo->sum('valor'), 2);
                $ehDinheiro = $opcao->opcaopag_desc_nfe === 'cash';
                $conta = $ehDinheiro ? $caixaPdv : $opcao->contaFinanceira;
                $data = $ehDinheiro ? $dataFechamento : $doGrupo->max('finalizada_em')->copy();

                $lancamentos->push($this->receitaRecebida($sessao, $opcao, $total, $data, $conta));
            }

            $this->lancarTrocoEntreContas($sessao, $pagamentos, $dataFechamento, $fechamento);
            $lancamentos = $lancamentos->concat($this->lancarQuebra($sessao, $fechamento, $caixaPdv, $dataFechamento));
            $this->recolher($sessao, $fechamento, $caixaPdv, $dataFechamento);
            $this->conferirCaixaPdv($sessao, $fechamento, $caixaPdv);

            return $lancamentos->map(fn (Lancamento $lancamento): Lancamento => $lancamento->refresh());
        });
    }

    /**
     * Desfaz a contabilização da sessão: estorna e apaga os títulos, estorna
     * as transferências do fechamento (troco entre contas e recolhimento) e
     * limpa o recolhimento do fechamento. Bloqueia se algum recebível já foi
     * liquidado ou conciliado: a liquidação é estornada antes.
     *
     * @return int quantidade de títulos apagados
     *
     * @throws ValidationException
     */
    public function estornar(SessaoCaixa $sessao, string $motivo): int
    {
        if (mb_strlen(trim($motivo)) < 5) {
            throw ValidationException::withMessages(['motivo' => 'Informe o motivo do estorno (mínimo de 5 caracteres).']);
        }

        return DB::transaction(function () use ($sessao, $motivo): int {
            $lancamentos = $this->titulosAtivos($sessao)->get();

            $liquidados = $lancamentos->filter(fn (Lancamento $lancamento): bool => $lancamento->maquininha_id !== null && $lancamento->pagamentos()->exists());

            if ($liquidados->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'sessao' => 'Há recebíveis de maquininha já liquidados ou conciliados nesta sessão ('
                        .$liquidados->pluck('descricao')->implode(', ')
                        .'). Estorne a liquidação deles antes.',
                ]);
            }

            $usuario = auth()->user();
            $movimentacoes = app(MovimentacaoService::class);

            foreach ($lancamentos as $lancamento) {
                $lancamento->estornarPagamento($motivo);

                // Movimentação é append-only e a FK é restrict: título que já
                // movimentou conta fica Cancelado; o que nunca movimentou (recebível pendente) sai.
                $lancamento->movimentacoesFinanceiras()->exists()
                    ? $lancamento->update(['status' => StatusLancamento::Cancelado])
                    : $lancamento->delete();
            }

            foreach ($this->transferenciasDoFechamento($sessao)->groupBy('transferencia_uuid') as $pernas) {
                $movimentacoes->estornar($pernas->first(), $motivo, $usuario ?? User::query()->orderBy('id')->firstOrFail());
            }

            $sessao->fechamentoCaixa?->forceFill(['valor_recolhido' => null, 'recolhimento_transferencia_uuid' => null])->save();

            return $lancamentos->count();
        });
    }

    /**
     * Pagamentos de venda da sessão, só o que entrou valor.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function pagamentosDaSessao(SessaoCaixa $sessao): Collection
    {
        return (new RelatorioFechamentoCaixaService(['sessoes' => [$sessao->id]]))
            ->pagamentos()
            ->where('origem', 'venda')
            ->filter(fn (array $pagamento): bool => $pagamento['valor'] > 0)
            ->values();
    }

    /** Título a receber já Pago: dinheiro no Caixa PDV, ou forma sem maquininha na conta da opção. */
    private function receitaRecebida(SessaoCaixa $sessao, OpcoesPagamento $opcao, float $total, Carbon $data, ContaFinanceira $conta): Lancamento
    {
        $lancamento = Lancamento::create([
            'tipo' => TipoLancamento::Receber,
            'sessao_caixa_id' => $sessao->id,
            'plano_receita_id' => $opcao->plano_receita_id,
            'descricao' => "Vendas {$opcao->opcaopag_nome} — Sessão #{$sessao->id} ({$data->format('d/m/Y')})",
            'valor' => $total,
            'vencimento' => $data,
            'status' => StatusLancamento::Pendente,
        ]);

        $lancamento->registrarPagamento(
            valor: $total,
            data: $data,
            forma: self::formaPagamentoDe($opcao),
            observacoes: 'Baixa automática — fechamento de caixa.',
            contaFinanceiraId: $conta->id,
            foraDaSessao: true,
        );

        return $lancamento;
    }

    /**
     * Cartão/Pix de maquininha: um título pendente por (maquininha × data
     * prevista × plano), vencendo no repasse. Quem liquida é o comando diário.
     *
     * @param  Collection<int, array<string, mixed>>  $pagamentos
     * @return Collection<int, Lancamento>
     */
    private function recebiveisDaOpcao(SessaoCaixa $sessao, OpcoesPagamento $opcao, Collection $pagamentos, Carbon $dataFechamento): Collection
    {
        $maquininhas = Maquininha::withTrashed()->with('contaFinanceira')->get()->keyBy('id');
        $lancamentos = collect();

        $porRepasse = $pagamentos->groupBy(function (array $pagamento) use ($dataFechamento): string {
            $prazo = $pagamento['prazo'];

            if ($prazo === null) {
                $this->avisos[] = "Pagamento em {$pagamento['forma']} sem prazo de recebimento cadastrado: vencimento no dia do fechamento.";
                Log::warning('Contabilização do fechamento sem prazo de recebimento da maquininha.', ['maquininha_id' => $pagamento['maquininha_id'], 'forma' => $pagamento['forma']]);
            }

            $repasse = $prazo?->dataPrevista($pagamento['finalizada_em']) ?? $dataFechamento->copy()->startOfDay();

            return $pagamento['maquininha_id'].'|'.$repasse->toDateString();
        });

        foreach ($porRepasse as $chave => $doRepasse) {
            [$maquininhaId, $dia] = explode('|', $chave);
            $maquininha = $maquininhas->get((int) $maquininhaId);
            $repasse = Carbon::parse($dia);

            if ($maquininha->contaFinanceira->data_saldo_inicial->gt($repasse)) {
                // O repasse caiu antes do saldo inicial da conta: já está no saldo informado.
                continue;
            }

            $lancamentos->push(Lancamento::create([
                'tipo' => TipoLancamento::Receber,
                'sessao_caixa_id' => $sessao->id,
                'plano_receita_id' => $opcao->plano_receita_id,
                'descricao' => "Vendas {$opcao->opcaopag_nome} ({$maquininha->nome}) — Sessão #{$sessao->id}, repasse {$repasse->format('d/m/Y')}",
                'valor' => round($doRepasse->sum('valor'), 2),
                'vencimento' => $repasse,
                'status' => StatusLancamento::Pendente,
                'maquininha_id' => $maquininha->id,
                'taxa_adquirente' => round($doRepasse->sum('taxa'), 2),
            ]));
        }

        return $lancamentos;
    }

    /**
     * Troco devolvido por uma forma diferente da que o cliente usou: o título
     * guarda só o valor líquido, então a diferença anda entre as contas.
     * Dinheiro com troco em Pix: a conta PIX paga e a gaveta fica com o troco.
     * Pix CNPJ com troco em dinheiro: a gaveta paga e a conta PIX fica com ele.
     *
     * @param  Collection<int, array<string, mixed>>  $pagamentos
     */
    private function lancarTrocoEntreContas(SessaoCaixa $sessao, Collection $pagamentos, Carbon $data, FechamentoCaixa $fechamento): void
    {
        $caixaPdv = ContaFinanceira::caixaPdv();
        $usuario = $this->usuarioDe($fechamento);
        $opcoes = OpcoesPagamento::withTrashed()->with('contaFinanceira')->get()->keyBy('id');
        $paraGaveta = 0.0;
        $daGaveta = [];

        foreach ($pagamentos->where('troco', '>', 0) as $pagamento) {
            $opcao = $opcoes->get($pagamento['opcao_id']);
            $trocoPix = ($pagamento['forma_troco'] ?? FormaTrocoEnum::Dinheiro) === FormaTrocoEnum::Pix;

            if ($opcao?->opcaopag_desc_nfe === 'cash' && $trocoPix) {
                $paraGaveta += $pagamento['troco'];
            } elseif ($opcao?->ehPixCnpj() && ! $trocoPix) {
                $daGaveta[$opcao->conta_financeira_id] = ($daGaveta[$opcao->conta_financeira_id] ?? 0.0) + $pagamento['troco'];
            }
        }

        $movimentacoes = app(MovimentacaoService::class);

        if (round($paraGaveta, 2) > 0) {
            $movimentacoes->transferir($this->contaDoTrocoPix(), $caixaPdv, round($paraGaveta, 2), $data, "Troco em Pix — Sessão #{$sessao->id}", $usuario, $sessao, tocarSessao: false, validarSaldo: false);
        }

        foreach ($daGaveta as $contaId => $valor) {
            $movimentacoes->transferir($caixaPdv, ContaFinanceira::findOrFail($contaId), round($valor, 2), $data, "Troco em dinheiro de Pix — Sessão #{$sessao->id}", $usuario, $sessao, tocarSessao: false, validarSaldo: false);
        }
    }

    /**
     * Diferença do dinheiro contado × esperado: falta é despesa e sobra é
     * receita, ambas pagas/recebidas no Caixa PDV. Diferença de cartão/Pix não
     * gera nada: a liquidação real é que corrige.
     *
     * @return Collection<int, Lancamento>
     */
    private function lancarQuebra(SessaoCaixa $sessao, FechamentoCaixa $fechamento, ContaFinanceira $caixaPdv, Carbon $data): Collection
    {
        $diferenca = $fechamento->diferencaDinheiro;

        if (abs($diferenca) < 0.01) {
            return collect();
        }

        $empresa = Empresa::query()->firstOrFail();

        $falta = $diferenca < 0;
        $lancamento = Lancamento::create([
            'tipo' => $falta ? TipoLancamento::Pagar : TipoLancamento::Receber,
            'sessao_caixa_id' => $sessao->id,
            $falta ? 'plano_despesa_id' : 'plano_receita_id' => $falta ? $empresa->empresa_plano_despesa_quebra_caixa_id : $empresa->empresa_plano_receita_sobra_caixa_id,
            'descricao' => ($falta ? 'Quebra de caixa' : 'Sobra de caixa')." — Sessão #{$sessao->id} ({$data->format('d/m/Y')})",
            'valor' => abs($diferenca),
            'vencimento' => $data,
            'status' => StatusLancamento::Pendente,
        ]);

        $lancamento->registrarPagamento(
            valor: abs($diferenca),
            data: $data,
            forma: FormaPagamento::Dinheiro,
            observacoes: 'Baixa automática — diferença do dinheiro contado no fechamento.',
            contaFinanceiraId: $caixaPdv->id,
            foraDaSessao: true,
        );

        return collect([$lancamento]);
    }

    /** Transferência do dinheiro contado menos o troco que fica na gaveta. */
    private function recolher(SessaoCaixa $sessao, FechamentoCaixa $fechamento, ContaFinanceira $caixaPdv, Carbon $data): void
    {
        if ($fechamento->valor_troco_gaveta === null) {
            return;
        }

        $contado = round($fechamento->totalDinheiroContado, 2);
        $troco = round((float) $fechamento->valor_troco_gaveta, 2);

        if ($troco < 0 || $troco > $contado + 0.001) {
            throw ValidationException::withMessages(['valor_troco_gaveta' => 'O troco que fica no caixa não pode ser negativo nem maior que o dinheiro contado (R$ '.number_format($contado, 2, ',', '.').').']);
        }

        $recolhido = round($contado - $troco, 2);
        $fechamento->forceFill(['valor_recolhido' => $recolhido])->save();

        if ($recolhido <= 0) {
            return;
        }

        if ($fechamento->recolhimento_conta_id === null) {
            throw ValidationException::withMessages(['recolhimento_conta_id' => 'Escolha a conta para onde o dinheiro recolhido vai.']);
        }

        $transferencia = app(MovimentacaoService::class)->transferir(
            $caixaPdv,
            ContaFinanceira::findOrFail($fechamento->recolhimento_conta_id),
            $recolhido,
            $data,
            "Recolhimento — Sessão #{$sessao->id}",
            $this->usuarioDe($fechamento),
            $sessao,
            tocarSessao: false,
            validarSaldo: false,
        );

        $fechamento->forceFill(['recolhimento_transferencia_uuid' => $transferencia['saida']->transferencia_uuid])->save();
    }

    /**
     * O Caixa PDV tem de ter crescido, nesta sessão, exatamente o troco que
     * ficou menos o que a gaveta já tinha na abertura. Se não bate, aborta.
     *
     * @throws FinanceiroException
     */
    private function conferirCaixaPdv(SessaoCaixa $sessao, FechamentoCaixa $fechamento, ContaFinanceira $caixaPdv): void
    {
        if ($fechamento->valor_troco_gaveta === null) {
            return;
        }

        $variacao = MovimentacaoFinanceira::query()
            ->where('conta_financeira_id', $caixaPdv->id)
            ->where('sessao_caixa_id', $sessao->id)
            ->get()
            ->sum(fn (MovimentacaoFinanceira $movimentacao): float => $movimentacao->valorComSinal());

        $esperada = round((float) $fechamento->valor_troco_gaveta - (float) $sessao->sessaocaixa_saldo_inicial, 2);

        if (abs(round($variacao, 2) - $esperada) > 0.01) {
            throw new FinanceiroException(sprintf(
                'A conferência do Caixa PDV não fecha: a sessão #%d movimentou R$ %s e o esperado era R$ %s (troco deixado menos o saldo de abertura). Revise sangrias, despesas e recebimentos da sessão.',
                $sessao->id,
                number_format($variacao, 2, ',', '.'),
                number_format($esperada, 2, ',', '.'),
            ));
        }
    }

    /**
     * Transferências da contabilização (troco entre contas e recolhimento):
     * as da sessão sem linha de sangria/suprimento nela.
     *
     * @return Collection<int, MovimentacaoFinanceira>
     */
    private function transferenciasDoFechamento(SessaoCaixa $sessao): Collection
    {
        $comLinhaNaSessao = MovimentacoesSessaoCaixa::query()->whereNotNull('movimentacao_financeira_id')->pluck('movimentacao_financeira_id');

        return MovimentacaoFinanceira::query()
            ->where('sessao_caixa_id', $sessao->id)
            ->where('natureza', NaturezaMovimentacao::Transferencia)
            ->whereNull('estorno_de_id')
            ->whereNotIn('id', $comLinhaNaSessao)
            ->whereDoesntHave('estorno')
            ->orderBy('id')
            ->get();
    }

    private function contaDoTrocoPix(): ?ContaFinanceira
    {
        return OpcoesPagamento::query()
            ->where('opcaopag_pix_cnpj', true)
            ->whereNotNull('conta_financeira_id')
            ->orderBy('id')
            ->first()?->contaFinanceira;
    }

    private function usuarioDe(FechamentoCaixa $fechamento): User
    {
        return auth()->user() ?? $fechamento->user ?? User::query()->orderBy('id')->firstOrFail();
    }

    private static function formaPagamentoDe(OpcoesPagamento $opcao): ?FormaPagamento
    {
        if ($opcao->ehPixCnpj()) {
            return FormaPagamento::PixCnpj;
        }

        return match ($opcao->opcaopag_desc_nfe) {
            'cash' => FormaPagamento::Dinheiro,
            'debitCard' => FormaPagamento::CartaoDebito,
            'creditCard' => FormaPagamento::CartaoCredito,
            'InstantPayment' => FormaPagamento::Pix,
            default => null,
        };
    }
}
