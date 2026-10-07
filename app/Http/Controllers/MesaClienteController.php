<?php

namespace App\Http\Controllers;

use App\Enums\TipoChamadoMesaEnum;
use App\Exceptions\ComboSaboresInvalidoException;
use App\Exceptions\EstoqueInsuficienteException;
use App\Exceptions\ItemIndisponivelException;
use App\Exceptions\PerguntaNaoRespondidaException;
use App\Exceptions\PromocaoIndisponivelException;
use App\Http\Requests\MesaCliente\ChamadoMesaRequest;
use App\Http\Requests\MesaCliente\EntrarMesaRequest;
use App\Http\Requests\MesaCliente\EnviarPedidoMesaRequest;
use App\Models\Mesa;
use App\Models\MesaParticipante;
use App\Services\CardapioService;
use App\Services\MesaCliente\EstadoMesaCliente;
use App\Services\MesaCliente\MesaChamadoService;
use App\Services\MesaCliente\ParticipanteMesaService;
use App\Services\MesaCliente\PedidoMesaClienteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Página pública do QR da mesa (/mesa/{codigo}): o cliente vê o cardápio,
 * se identifica e pede pelo celular, sem login. Controller fino — as regras
 * ficam em App\Services\MesaCliente.
 */
class MesaClienteController extends Controller
{
    /** Token do celular na conta da mesa (só o hash fica no banco). */
    public const COOKIE = 'mesa_cliente';

    /** Validade do cookie; o token morre antes, quando a conta fecha. */
    private const COOKIE_MINUTOS = 12 * 60;

    public function __construct(private EstadoMesaCliente $estado) {}

    public function show(Request $request, Mesa $mesa, CardapioService $cardapio): View|RedirectResponse
    {
        $situacao = $this->estado->situacao($mesa, $request->cookie(self::COOKIE));

        if ($situacao['estado'] === EstadoMesaCliente::TROCADA && $situacao['codigo_mesa_atual']) {
            return redirect()->route('mesa-cliente.show', $situacao['codigo_mesa_atual']);
        }

        return view('cardapio', $cardapio->dados() + [
            'mesaCliente' => [
                'codigo' => $mesa->mesa_codigo_qr,
                'estado' => $this->estado->paraTela($mesa, $situacao),
                'rotas' => [
                    'estado' => route('mesa-cliente.estado', $mesa->mesa_codigo_qr),
                    'entrar' => route('mesa-cliente.entrar', $mesa->mesa_codigo_qr),
                    'abertura' => route('mesa-cliente.abertura', $mesa->mesa_codigo_qr),
                    'pedidos' => route('mesa-cliente.pedidos', $mesa->mesa_codigo_qr),
                    'chamados' => route('mesa-cliente.chamados', $mesa->mesa_codigo_qr),
                ],
            ],
        ]);
    }

    public function estado(Request $request, Mesa $mesa): JsonResponse
    {
        $situacao = $this->estado->situacao($mesa, $request->cookie(self::COOKIE));

        return response()->json($this->estado->paraTela($mesa, $situacao));
    }

    public function entrar(EntrarMesaRequest $request, Mesa $mesa, ParticipanteMesaService $participantes): JsonResponse
    {
        try {
            ['participante' => $participante, 'token' => $token] = $participantes->entrar(
                $mesa,
                $request->validated('nome'),
                $request->validated('celular'),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()
            ->json($this->estado->paraTela($mesa, $this->situacaoPedindo($participante)))
            ->withCookie($this->cookie($token));
    }

    /** Mesa fechada: o cliente pede para o garçom vir abrir. */
    public function abertura(Request $request, Mesa $mesa, MesaChamadoService $chamados, ParticipanteMesaService $participantes): JsonResponse
    {
        if (! $participantes->pedidoPeloCelularLigado($mesa)) {
            return response()->json(['message' => 'O pedido pelo celular não está disponível nesta mesa. Chame o garçom.'], 403);
        }

        try {
            $chamados->abrir($mesa, TipoChamadoMesaEnum::ABRIR_MESA, ip: $request->ip());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Avisamos o garçom. Já já ele abre a mesa para você.']);
    }

    public function pedidos(EnviarPedidoMesaRequest $request, Mesa $mesa, PedidoMesaClienteService $pedidos): JsonResponse
    {
        $participante = $this->participante($request);

        try {
            $pedido = $pedidos->enviar($participante, $request->itens(), $request->validated('chave'));
        } catch (EstoqueInsuficienteException $e) {
            return response()->json(['message' => 'Item sem estoque suficiente: '.$e->getMessage()], 422);
        } catch (ItemIndisponivelException|ComboSaboresInvalidoException|PerguntaNaoRespondidaException|PromocaoIndisponivelException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $pedido->aguardandoAprovacao()
                ? 'Pedido recebido! O garçom confere e manda para a cozinha.'
                : 'Pedido enviado para a cozinha!',
            'estado' => $this->estado->paraTela($mesa, $this->situacaoPedindo($participante)),
        ]);
    }

    public function chamados(ChamadoMesaRequest $request, Mesa $mesa, MesaChamadoService $chamados): JsonResponse
    {
        $participante = $this->participante($request);

        try {
            $chamados->abrir($mesa, $request->tipo(), $participante, $request->ip());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $request->tipo() === TipoChamadoMesaEnum::PEDIR_CONTA
                ? 'Pedimos a conta. O garçom já vem.'
                : 'Chamamos o garçom. Ele já vem.',
            'estado' => $this->estado->paraTela($mesa, $this->situacaoPedindo($participante)),
        ]);
    }

    /** Resolvido pelo middleware ResolverParticipanteMesa. */
    private function participante(Request $request): MesaParticipante
    {
        return $request->attributes->get('participante');
    }

    /** @return array{estado: string, participante: MesaParticipante, mensagem: null, codigo_mesa_atual: null} */
    private function situacaoPedindo(MesaParticipante $participante): array
    {
        return ['estado' => EstadoMesaCliente::PEDINDO, 'participante' => $participante, 'mensagem' => null, 'codigo_mesa_atual' => null];
    }

    private function cookie(string $token): Cookie
    {
        return cookie(
            self::COOKIE,
            $token,
            self::COOKIE_MINUTOS,
            path: '/mesa',
            secure: config('session.secure'),
            httpOnly: true,
            sameSite: 'lax',
        );
    }
}
