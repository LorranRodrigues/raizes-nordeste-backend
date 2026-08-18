<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Support\Fidelidade;

/**
 * Pedidos multicanal (APP, TOTEM, BALCAO, PICKUP) e máquina de status.
 *
 * Permissões por transição:
 *   RECEBIDO → EM_PREPARO : ATENDENTE, COZINHEIRO, GERENTE, MATRIZ
 *   EM_PREPARO → PRONTO    : COZINHEIRO, GERENTE, MATRIZ
 *   PRONTO → ENTREGUE      : ATENDENTE, GERENTE, MATRIZ
 *   * → CANCELADO          : GERENTE, MATRIZ (operação sensível, auditada)
 */
final class PedidoController extends Controller
{
    private const PERMISSAO_TRANSICAO = [
        'EM_PREPARO' => ['ATENDENTE', 'COZINHEIRO', 'GERENTE', 'MATRIZ'],
        'PRONTO' => ['COZINHEIRO', 'GERENTE', 'MATRIZ'],
        'ENTREGUE' => ['ATENDENTE', 'GERENTE', 'MATRIZ'],
        'CANCELADO' => ['GERENTE', 'MATRIZ'],
    ];

    public function store(Request $request): void
    {
        $this->authorize($request, ['ATENDENTE', 'GERENTE', 'MATRIZ']);

        // Aceita 'canalPedido' (contrato do roteiro) ou 'canal' como alias.
        $entrada = $request->all();
        if (!isset($entrada['canal']) && isset($entrada['canalPedido'])) {
            $entrada['canal'] = $entrada['canalPedido'];
        }

        $dados = Validator::make($entrada, [
            'unidade_id' => 'required|integer',
            'canal' => 'required|in:APP,TOTEM,BALCAO,PICKUP,WEB',
            'cliente_id' => 'integer',
            'observacao' => 'string|max:255',
            'usar_pontos' => 'boolean',
        ]);

        $itens = $request->input('itens');
        if (!is_array($itens) || $itens === []) {
            throw HttpException::unprocessable('Informe ao menos um item em "itens".');
        }
        foreach ($itens as $i => $item) {
            if (!isset($item['produto_id'], $item['quantidade']) || (int) $item['quantidade'] < 1) {
                throw HttpException::unprocessable("Item #{$i} inválido (precisa de produto_id e quantidade >= 1).");
            }
        }

        // Escopo de unidade: gerente/atendente só operam a própria; matriz informa.
        $unidadeId = $this->unidadeEscopo($request, (int) $dados['unidade_id']);

        // Desconto progressivo de fidelidade (se houver cliente e ele optar por usar).
        $descontoPercentual = 0.0;
        $clienteId = isset($dados['cliente_id']) ? (int) $dados['cliente_id'] : null;
        if ($clienteId !== null) {
            $cliente = (new Cliente())->findOrFail($clienteId);
            if (filter_var($request->input('usar_pontos', false), FILTER_VALIDATE_BOOL)) {
                $descontoPercentual = Fidelidade::descontoPercentual((int) $cliente['pontos_saldo']);
            }
        }

        $model = new Pedido();
        $pedidoId = $model->criar([
            'unidade_id' => $unidadeId,
            'cliente_id' => $clienteId,
            'funcionario_id' => $request->auth('id'),
            'canal' => $dados['canal'],
            'observacao' => $dados['observacao'] ?? null,
            'descontoPercentual' => $descontoPercentual,
        ], $itens);

        $this->audit($request, 'PEDIDO_CRIAR', 'pedidos', $pedidoId, 'Pedido criado pelo canal ' . $dados['canal']);

        Response::success($model->detalhe($pedidoId), 201);
    }

    public function index(Request $request): void
    {
        $this->authorize($request, ['ATENDENTE', 'COZINHEIRO', 'GERENTE', 'MATRIZ']);

        $filtros = [
            'status' => $request->query('status'),
            // Aceita ?canalPedido= (contrato do roteiro) ou ?canal= como alias.
            'canal' => $request->query('canalPedido', $request->query('canal')),
            'unidade_id' => $request->query('unidade_id'),
        ];
        // Não-matriz só enxerga a própria unidade (rastreabilidade/escopo).
        if ($request->auth('papel') !== 'MATRIZ') {
            $filtros['unidade_id'] = $request->auth('unidade_id');
        }

        $p = \App\Support\Pagination::params($request);
        $resultado = (new Pedido())->listar($filtros, $p['page'], $p['limit']);

        Response::success(
            $resultado['data'],
            200,
            \App\Support\Pagination::meta($resultado['total'], $p['page'], $p['limit'])
        );
    }

    public function show(Request $request): void
    {
        $this->authorize($request, ['ATENDENTE', 'COZINHEIRO', 'GERENTE', 'MATRIZ']);
        $pedido = (new Pedido())->detalhe((int) $request->param('id'));
        if ($pedido === null) {
            throw HttpException::notFound('Pedido não encontrado.');
        }
        // Escopo: não-matriz só vê pedido da própria unidade.
        if ($request->auth('papel') !== 'MATRIZ' && (int) $pedido['unidade_id'] !== $request->auth('unidade_id')) {
            throw HttpException::forbidden('Pedido de outra unidade.');
        }
        Response::success($pedido);
    }

    /** Avança/cancela o status conforme a máquina de estados e o papel. */
    public function alterarStatus(Request $request): void
    {
        $dados = Validator::make($request->all(), [
            'status' => 'required|in:EM_PREPARO,PRONTO,ENTREGUE,CANCELADO',
        ]);
        $novo = $dados['status'];

        $this->authorize($request, self::PERMISSAO_TRANSICAO[$novo]);

        $model = new Pedido();
        $pedido = $model->findOrFail((int) $request->param('id'));

        if ($request->auth('papel') !== 'MATRIZ' && (int) $pedido['unidade_id'] !== $request->auth('unidade_id')) {
            throw HttpException::forbidden('Pedido de outra unidade.');
        }

        $atualizado = $model->alterarStatus((int) $pedido['id'], $novo);

        // Cancelamento é operação sensível: auditoria detalhada (exigência da matriz).
        $acao = $novo === 'CANCELADO' ? 'PEDIDO_CANCELAR' : 'PEDIDO_STATUS';
        $this->audit(
            $request,
            $acao,
            'pedidos',
            $pedido['id'],
            "Status {$pedido['status']} → {$novo}",
            ['status' => $pedido['status']],
            ['status' => $novo]
        );

        Response::success($atualizado);
    }
}
