<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Cardápio local: vínculo produto×unidade com override de preço,
 * disponibilidade e estoque. Resolve o requisito de variação regional/sazonal
 * sem quebrar a padronização do catálogo da franqueadora.
 */
final class UnidadeProduto extends Model
{
    protected string $table = 'unidade_produtos';
    protected array $fillable = ['unidade_id', 'produto_id', 'preco_local', 'disponivel', 'estoque'];

    /**
     * Cardápio efetivo de uma unidade: junta catálogo global + override local,
     * calculando o preço efetivo (preco_local ?? preco_base).
     *
     * @param bool $apenasDisponiveis quando true, retorna só itens vendáveis
     *                                (disponível, ativo e com estoque) — visão do cliente.
     */
    public function cardapioDaUnidade(int $unidadeId, bool $apenasDisponiveis = false): array
    {
        $sql = 'SELECT p.id            AS produto_id,
                       p.nome,
                       p.descricao,
                       c.nome          AS categoria,
                       p.sazonal,
                       p.preco_base,
                       up.preco_local,
                       COALESCE(up.preco_local, p.preco_base) AS preco_efetivo,
                       up.disponivel,
                       up.estoque
                  FROM unidade_produtos up
                  JOIN produtos  p ON p.id = up.produto_id
                  JOIN categorias c ON c.id = p.categoria_id
                 WHERE up.unidade_id = :unidade_id
                   AND p.ativo = 1';

        if ($apenasDisponiveis) {
            $sql .= ' AND up.disponivel = 1 AND up.estoque > 0';
        }
        $sql .= ' ORDER BY c.nome, p.nome';

        return $this->query($sql, ['unidade_id' => $unidadeId]);
    }

    public function doVinculo(int $unidadeId, int $produtoId): ?array
    {
        return $this->queryOne(
            'SELECT * FROM unidade_produtos WHERE unidade_id = :u AND produto_id = :p',
            ['u' => $unidadeId, 'p' => $produtoId]
        );
    }

    /**
     * Baixa estoque de forma atômica e segura contra concorrência:
     * só decrementa se houver saldo suficiente. Retorna true se baixou.
     * Essencial para horários de pico (evita vender o que não há).
     */
    public function baixarEstoque(int $unidadeId, int $produtoId, int $quantidade): bool
    {
        // Placeholders distintos: prepares nativos do MySQL não reusam o mesmo nome.
        $stmt = $this->db->prepare(
            'UPDATE unidade_produtos
                SET estoque = estoque - :qbaixa
              WHERE unidade_id = :u AND produto_id = :p AND estoque >= :qmin'
        );
        $stmt->execute(['qbaixa' => $quantidade, 'qmin' => $quantidade, 'u' => $unidadeId, 'p' => $produtoId]);
        return $stmt->rowCount() === 1;
    }
}
