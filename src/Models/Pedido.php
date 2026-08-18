<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\HttpException;
use App\Core\Model;

/**
 * Pedido multicanal com máquina de status.
 *
 * A criação é transacional: valida disponibilidade, baixa estoque de forma
 * atômica, grava snapshot de preço por item e calcula os totais. Se qualquer
 * item faltar, faz rollback completo — garantindo consistência em horário de
 * pico (tolerância a falhas / integridade).
 */
final class Pedido extends Model
{
    protected string $table = 'pedidos';

    /** Transições válidas da máquina de estados. */
    public const TRANSICOES = [
        'RECEBIDO' => ['EM_PREPARO', 'CANCELADO'],
        'EM_PREPARO' => ['PRONTO', 'CANCELADO'],
        'PRONTO' => ['ENTREGUE', 'CANCELADO'],
        'ENTREGUE' => [],
        'CANCELADO' => [],
    ];

    /**
     * Cria o pedido e seus itens em uma transação.
     *
     * @param array $cabecalho unidade_id, cliente_id, funcionario_id, canal, observacao, descontoPercentual
     * @param array $itens     lista de ['produto_id' => int, 'quantidade' => int]
     * @return int id do pedido criado
     */
    public function criar(array $cabecalho, array $itens): int
    {
        $unidadeProduto = new UnidadeProduto();

        $this->db->beginTransaction();
        try {
            $subtotal = 0.0;
            $itensCalculados = [];

            foreach ($itens as $item) {
                $produtoId = (int) $item['produto_id'];
                $quantidade = (int) $item['quantidade'];

                $vinculo = $unidadeProduto->doVinculo((int) $cabecalho['unidade_id'], $produtoId);
                if ($vinculo === null || (int) $vinculo['disponivel'] !== 1) {
                    throw HttpException::unprocessable(
                        "Produto {$produtoId} indisponível nesta unidade.",
                        [['field' => "itens.produto_id", 'issue' => "Produto {$produtoId} indisponível nesta unidade."]],
                        'PRODUTO_INDISPONIVEL'
                    );
                }

                // Baixa de estoque atômica: só prossegue se houver saldo.
                if (!$unidadeProduto->baixarEstoque((int) $cabecalho['unidade_id'], $produtoId, $quantidade)) {
                    throw HttpException::conflict(
                        "Estoque insuficiente para o produto {$produtoId}.",
                        'ESTOQUE_INSUFICIENTE'
                    );
                }

                // Snapshot: nome e preço congelados no momento da venda.
                $dados = $this->queryOne(
                    'SELECT p.nome, COALESCE(up.preco_local, p.preco_base) AS preco
                       FROM produtos p
                       JOIN unidade_produtos up ON up.produto_id = p.id AND up.unidade_id = :u
                      WHERE p.id = :p',
                    ['u' => $cabecalho['unidade_id'], 'p' => $produtoId]
                );
                $precoUnit = (float) $dados['preco'];
                $itemSubtotal = round($precoUnit * $quantidade, 2);
                $subtotal += $itemSubtotal;

                $itensCalculados[] = [
                    'produto_id' => $produtoId,
                    'nome_produto' => $dados['nome'],
                    'preco_unitario' => $precoUnit,
                    'quantidade' => $quantidade,
                    'subtotal' => $itemSubtotal,
                ];
            }

            $descontoPercentual = (float) ($cabecalho['descontoPercentual'] ?? 0);
            $desconto = round($subtotal * $descontoPercentual, 2);
            $total = round($subtotal - $desconto, 2);

            $codigo = $this->gerarCodigo();
            $pedidoId = $this->create([
                'codigo' => $codigo,
                'unidade_id' => $cabecalho['unidade_id'],
                'cliente_id' => $cabecalho['cliente_id'] ?? null,
                'funcionario_id' => $cabecalho['funcionario_id'] ?? null,
                'canal' => $cabecalho['canal'],
                'status' => 'RECEBIDO',
                'subtotal' => $subtotal,
                'desconto' => $desconto,
                'total' => $total,
                'observacao' => $cabecalho['observacao'] ?? null,
            ]);

            $stmt = $this->db->prepare(
                'INSERT INTO pedido_itens (pedido_id, produto_id, nome_produto, preco_unitario, quantidade, subtotal)
                 VALUES (:pedido_id, :produto_id, :nome_produto, :preco_unitario, :quantidade, :subtotal)'
            );
            foreach ($itensCalculados as $ic) {
                $stmt->execute(['pedido_id' => $pedidoId] + $ic);
            }

            $this->db->commit();
            return $pedidoId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    protected array $fillable = [
        'codigo', 'unidade_id', 'cliente_id', 'funcionario_id', 'canal',
        'status', 'subtotal', 'desconto', 'total', 'observacao',
    ];

    private function gerarCodigo(): string
    {
        return 'RN-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
    }

    /** Pedido completo: cabeçalho + itens + pagamento mais recente. */
    public function detalhe(int $id): ?array
    {
        $pedido = $this->find($id);
        if ($pedido === null) {
            return null;
        }
        $pedido['canalPedido'] = $pedido['canal']; // alias do contrato (roteiro)
        $pedido['itens'] = $this->query('SELECT * FROM pedido_itens WHERE pedido_id = :id', ['id' => $id]);
        $pedido['pagamento'] = $this->queryOne(
            'SELECT id, metodo, valor, status, gateway_ref, created_at
               FROM pagamentos WHERE pedido_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $id]
        );
        return $pedido;
    }

    /**
     * Lista paginada com filtros opcionais (unidade, status, canalPedido).
     * @return array{data: array, total: int}
     */
    public function listar(array $filtros, int $page = 1, int $limit = 10): array
    {
        $where = ' WHERE 1=1';
        $params = [];
        foreach (['unidade_id' => 'p.unidade_id', 'status' => 'p.status', 'canal' => 'p.canal'] as $key => $col) {
            if (!empty($filtros[$key])) {
                $where .= " AND {$col} = :{$key}";
                $params[$key] = $filtros[$key];
            }
        }

        $total = (int) $this->queryOne("SELECT COUNT(*) AS n FROM pedidos p{$where}", $params)['n'];

        $sql = "SELECT p.*, u.nome AS unidade_nome
                  FROM pedidos p JOIN unidades u ON u.id = p.unidade_id{$where}
                 ORDER BY p.created_at DESC
                 LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, \PDO::PARAM_INT);
        $stmt->execute();

        // Expõe 'canalPedido' (contrato do roteiro) além de 'canal' (coluna).
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['canalPedido'] = $r['canal'];
        }
        return ['data' => $rows, 'total' => $total];
    }

    /**
     * Altera o status validando a máquina de estados. Ao cancelar, devolve o
     * estoque dos itens (compensação). Tudo em transação.
     */
    public function alterarStatus(int $id, string $novoStatus): array
    {
        $pedido = $this->findOrFail($id);
        $atual = $pedido['status'];

        if (!in_array($novoStatus, self::TRANSICOES[$atual] ?? [], true)) {
            $validas = implode(', ', self::TRANSICOES[$atual]) ?: 'nenhuma (estado final)';
            throw HttpException::unprocessable(
                "Transição inválida: {$atual} → {$novoStatus}.",
                [['field' => 'status', 'issue' => "Transições válidas a partir de {$atual}: {$validas}."]],
                'TRANSICAO_INVALIDA'
            );
        }

        $this->db->beginTransaction();
        try {
            if ($novoStatus === 'CANCELADO') {
                $itens = $this->query('SELECT produto_id, quantidade FROM pedido_itens WHERE pedido_id = :id', ['id' => $id]);
                $devolve = $this->db->prepare(
                    'UPDATE unidade_produtos SET estoque = estoque + :q
                      WHERE unidade_id = :u AND produto_id = :p'
                );
                foreach ($itens as $item) {
                    if ($item['produto_id'] !== null) {
                        $devolve->execute(['q' => $item['quantidade'], 'u' => $pedido['unidade_id'], 'p' => $item['produto_id']]);
                    }
                }
            }
            $this->update($id, ['status' => $novoStatus]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->findOrFail($id);
    }
}
