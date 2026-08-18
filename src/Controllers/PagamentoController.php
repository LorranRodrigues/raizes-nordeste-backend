<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Pagamento;
use App\Models\Pedido;
use App\Models\PontoFidelidade;
use App\Support\Fidelidade;
use App\Support\GatewaySimulado;

/**
 * Pagamento desacoplado.
 *
 * Fluxo (arquitetura de integração):
 *   1. solicitar()  → cria pagamento PENDENTE e pede uma cobrança ao gateway;
 *   2. webhook()     → o gateway notifica APROVADO/RECUSADO (assíncrono);
 *                      a aplicação só registra o resultado e atualiza o pedido.
 *
 * A rede NUNCA processa dados de cartão — apenas solicita e recebe confirmação.
 */
final class PagamentoController extends Controller
{
    /** Passo 1: solicita a cobrança ao provedor externo. */
    public function solicitar(Request $request): void
    {
        $this->authorize($request, ['ATENDENTE', 'GERENTE', 'MATRIZ']);

        $dados = Validator::make($request->all(), [
            'metodo' => 'required|in:PIX,CARTAO_CREDITO,CARTAO_DEBITO,DINHEIRO',
        ]);

        $pedidoModel = new Pedido();
        $pedido = $pedidoModel->findOrFail((int) $request->param('id'));

        if (in_array($pedido['status'], ['CANCELADO', 'ENTREGUE'], true)) {
            throw HttpException::conflict('Pedido não está em estado pagável.');
        }

        $pagModel = new Pagamento();
        $existente = $pagModel->firstWhere(['pedido_id' => $pedido['id'], 'status' => 'APROVADO']);
        if ($existente !== null) {
            throw HttpException::conflict('Pedido já possui pagamento aprovado.');
        }

        // Chama o gateway (simulado) e registra a transação como PENDENTE.
        $cobranca = GatewaySimulado::solicitarCobranca($dados['metodo'], (float) $pedido['total'], $pedido['codigo']);

        $pagId = $pagModel->create([
            'pedido_id' => $pedido['id'],
            'metodo' => $dados['metodo'],
            'valor' => $pedido['total'],
            'status' => 'PENDENTE',
            'gateway_ref' => $cobranca['gateway_ref'],
            'payload_retorno' => null,
        ]);
        $this->audit($request, 'PAGAMENTO_SOLICITAR', 'pagamentos', $pagId, 'Cobrança solicitada ao gateway');

        Response::success([
            'pagamento_id' => $pagId,
            'status' => 'PENDENTE',
            'gateway_ref' => $cobranca['gateway_ref'],
            'instrucao' => 'Aguardando confirmação do provedor via webhook.',
        ], 201);
    }

    /**
     * Passo 2: webhook do gateway (chamado por sistema externo, sem login).
     * Autenticado por assinatura HMAC do corpo. Idempotente.
     */
    public function webhook(Request $request): void
    {
        $rawBody = file_get_contents('php://input') ?: '';
        $assinatura = $request->header('X-Webhook-Signature') ?? '';

        if (!GatewaySimulado::assinaturaValida($rawBody, $assinatura)) {
            throw HttpException::unauthorized('Assinatura do webhook inválida.');
        }

        $dados = Validator::make($request->all(), [
            'gateway_ref' => 'required|string',
            'status' => 'required|in:APROVADO,RECUSADO',
        ]);

        $pagModel = new Pagamento();
        $pagamento = $pagModel->porGatewayRef($dados['gateway_ref']);
        if ($pagamento === null) {
            throw HttpException::notFound('Transação não encontrada.');
        }

        // Idempotência: se já foi processado, responde OK sem reprocessar.
        if ($pagamento['status'] !== 'PENDENTE') {
            Response::success(['status' => $pagamento['status'], 'reprocessado' => false]);
        }

        $pagModel->update((int) $pagamento['id'], [
            'status' => $dados['status'],
            'payload_retorno' => $rawBody,
        ]);

        // Atualiza o pedido conforme o resultado e credita pontos se aprovado.
        if ($dados['status'] === 'APROVADO') {
            $this->aposAprovacao((int) $pagamento['pedido_id']);
        }

        (new \App\Models\Auditoria())->registrar(
            null,
            'PAGAMENTO_WEBHOOK',
            'pagamentos',
            $pagamento['id'],
            'Webhook do gateway: ' . $dados['status'],
            ['status' => 'PENDENTE'],
            ['status' => $dados['status']]
        );

        Response::success(['status' => $dados['status'], 'processado' => true]);
    }

    /** Lista pagamentos de um pedido. */
    public function index(Request $request): void
    {
        $this->authorize($request, ['ATENDENTE', 'GERENTE', 'MATRIZ']);
        $pedido = (new Pedido())->findOrFail((int) $request->param('id'));
        Response::success((new Pagamento())->all(['pedido_id' => $pedido['id']], 'id DESC'));
    }

    /**
     * Pós-aprovação: avança o pedido para preparo (se ainda RECEBIDO) e credita
     * pontos de fidelidade ao cliente (1 ponto por real do total).
     */
    private function aposAprovacao(int $pedidoId): void
    {
        $pedidoModel = new Pedido();
        $pedido = $pedidoModel->findOrFail($pedidoId);

        if ($pedido['status'] === 'RECEBIDO') {
            $pedidoModel->update($pedidoId, ['status' => 'EM_PREPARO']);
        }

        if ($pedido['cliente_id'] !== null) {
            $pontos = Fidelidade::pontosPorValor((float) $pedido['total']);
            if ($pontos > 0) {
                (new PontoFidelidade())->lancar(
                    (int) $pedido['cliente_id'],
                    'CREDITO',
                    $pontos,
                    $pedidoId,
                    'Pontos pelo pedido ' . $pedido['codigo']
                );
            }
        }
    }
}
