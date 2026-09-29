<?php

namespace App\Livewire;

use App\Models\SessaoCaixa;
use App\Models\Venda;
use App\Services\SessaoCaixaService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Botão + modal "vendas sem sessão de caixa" — vendas FINALIZADA que
 * nasceram sem sessão vinculada (recebidas pela maquininha Stone antes de
 * alguém abrir o caixa, quando havia 0 ou 2+ sessões ABERTA — ver
 * StoneVendaAutomaticaService::resolverOuCriarVenda) e ficam disponíveis
 * pra qualquer sessão ABERTA reivindicar.
 *
 * Dois pontos de uso (embutido no Blade legado, mesmo padrão de
 * MesaStoneCobranca):
 *  - resources/views/app/sessao_caixa/index.blade.php — abre sozinho
 *    ($autoAbrir=true) logo após abrir uma sessão nova, se houver órfãs.
 *  - resources/views/app/sessao_caixa/vendas.blade.php — acessível a
 *    qualquer momento enquanto a sessão estiver ABERTA (botão, sem auto-abrir).
 */
class VendasSemSessaoCaixa extends Component
{
    public int $sessaoCaixaId;

    public bool $autoAbrir = false;

    public bool $modalAberta = false;

    /** @var array<int, int> */
    public array $selecionadas = [];

    public ?string $mensagemSucesso = null;

    public function mount(int $sessaoCaixaId, bool $autoAbrir = false): void
    {
        $this->sessaoCaixaId = $sessaoCaixaId;
        $this->autoAbrir = $autoAbrir;

        if ($autoAbrir && $this->vendasOrfas->isNotEmpty()) {
            $this->modalAberta = true;
            // Pré-marca todas: no caso comum, todas as vendas órfãs pertencem
            // ao turno que está abrindo agora — o operador só desmarca as que
            // não forem.
            $this->selecionadas = $this->vendasOrfas->pluck('id')->all();
        }
    }

    #[Computed]
    public function vendasOrfas()
    {
        return Venda::whereNull('venda_sessao_caixa_id')
            ->where('venda_status', 'FINALIZADA')
            ->with('cliente')
            ->oldest('venda_datahora_finalizada')
            ->get();
    }

    public function abrirModal(): void
    {
        $this->modalAberta = true;
        $this->selecionadas = $this->vendasOrfas->pluck('id')->all();
    }

    public function dispensar(): void
    {
        $this->modalAberta = false;
    }

    public function vincular(): void
    {
        $sessao = SessaoCaixa::find($this->sessaoCaixaId);
        if (! $sessao) {
            $this->dispensar();

            return;
        }

        $totalVinculado = Venda::whereIn('id', $this->selecionadas)->sum('venda_valor_pago');

        try {
            $qtd = app(SessaoCaixaService::class)->vincularVendasOrfas($sessao, $this->selecionadas);
        } catch (ValidationException $e) {
            $this->addError('sessao', $e->getMessage());

            return;
        }

        $this->modalAberta = false;
        $this->selecionadas = [];
        unset($this->vendasOrfas);

        $this->mensagemSucesso = $qtd > 0
            ? "{$qtd} venda(s) vinculada(s) a este caixa (R$ ".number_format((float) $totalVinculado, 2, ',', '.').').'
            : 'Nenhuma venda foi vinculada.';
    }

    public function render()
    {
        return view('livewire.vendas-sem-sessao-caixa');
    }
}
