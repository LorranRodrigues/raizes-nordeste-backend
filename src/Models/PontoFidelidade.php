<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Livro-razão (ledger) de pontos de fidelidade. Cada movimento é imutável;
 * o saldo do cliente é mantido em cache (clientes.pontos_saldo) e atualizado
 * de forma transacional junto com o lançamento.
 */
final class PontoFidelidade extends Model
{
    protected string $table = 'pontos_fidelidade';
    protected array $fillable = ['cliente_id', 'pedido_id', 'tipo', 'pontos', 'descricao'];

    /**
     * Lança pontos (CREDITO/DEBITO) e atualiza o saldo do cliente atomicamente.
     * Pode participar de uma transação externa (não abre transação própria se já houver).
     */
    public function lancar(int $clienteId, string $tipo, int $pontos, ?int $pedidoId, ?string $descricao): void
    {
        $sinal = $tipo === 'CREDITO' ? 1 : -1;

        $this->create([
            'cliente_id' => $clienteId,
            'pedido_id' => $pedidoId,
            'tipo' => $tipo,
            'pontos' => $pontos,
            'descricao' => $descricao,
        ]);

        $stmt = $this->db->prepare(
            'UPDATE clientes SET pontos_saldo = pontos_saldo + :delta WHERE id = :id'
        );
        $stmt->execute(['delta' => $sinal * $pontos, 'id' => $clienteId]);
    }

    /** Extrato de movimentações do cliente. */
    public function extrato(int $clienteId): array
    {
        return $this->all(['cliente_id' => $clienteId], 'created_at DESC, id DESC');
    }
}
