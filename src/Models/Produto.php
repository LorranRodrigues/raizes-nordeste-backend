<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Produto extends Model
{
    protected string $table = 'produtos';
    protected array $fillable = [
        'categoria_id', 'nome', 'descricao', 'preco_base', 'sazonal', 'ativo',
    ];

    /**
     * Catálogo global com nome da categoria, paginado.
     * @return array{data: array, total: int}
     */
    public function comCategoria(array $where = [], int $page = 1, int $limit = 10): array
    {
        $filtro = '';
        $params = [];
        if (isset($where['categoria_id'])) {
            $filtro = ' WHERE p.categoria_id = :categoria_id';
            $params['categoria_id'] = $where['categoria_id'];
        }

        $total = (int) $this->queryOne(
            "SELECT COUNT(*) AS n FROM produtos p{$filtro}",
            $params
        )['n'];

        $sql = "SELECT p.*, c.nome AS categoria_nome
                  FROM produtos p
                  JOIN categorias c ON c.id = p.categoria_id{$filtro}
                 ORDER BY c.nome, p.nome
                 LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return ['data' => $stmt->fetchAll(), 'total' => $total];
    }
}
