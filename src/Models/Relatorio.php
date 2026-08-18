<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Consultas analíticas para a matriz (não mapeia uma tabela única).
 * Pedidos CANCELADOS são excluídos das vendas; o faturamento considera apenas
 * pedidos com pagamento APROVADO (visão financeira realista).
 */
final class Relatorio extends Model
{
    protected string $table = 'pedidos';

    /** Filtro de período reutilizável sobre pedidos.created_at. */
    private function periodo(array $f, string $alias = 'p'): array
    {
        $sql = '';
        $params = [];
        if (!empty($f['data_inicio'])) {
            $sql .= " AND {$alias}.created_at >= :ini";
            $params['ini'] = $f['data_inicio'] . ' 00:00:00';
        }
        if (!empty($f['data_fim'])) {
            $sql .= " AND {$alias}.created_at <= :fim";
            $params['fim'] = $f['data_fim'] . ' 23:59:59';
        }
        return [$sql, $params];
    }

    /** Vendas por unidade (qtd de pedidos e total vendido). */
    public function vendasPorUnidade(array $f): array
    {
        [$periodo, $params] = $this->periodo($f);
        return $this->query(
            "SELECT u.id AS unidade_id, u.nome AS unidade, u.cidade, u.estado,
                    COUNT(p.id) AS qtd_pedidos,
                    COALESCE(SUM(p.total), 0) AS total_vendido
               FROM unidades u
               LEFT JOIN pedidos p ON p.unidade_id = u.id AND p.status <> 'CANCELADO' {$periodo}
              GROUP BY u.id, u.nome, u.cidade, u.estado
              ORDER BY total_vendido DESC",
            $params
        );
    }

    /** Vendas consolidadas por região. */
    public function vendasPorRegiao(array $f): array
    {
        [$periodo, $params] = $this->periodo($f);
        return $this->query(
            "SELECT r.id AS regiao_id, r.nome AS regiao,
                    COUNT(p.id) AS qtd_pedidos,
                    COALESCE(SUM(p.total), 0) AS total_vendido
               FROM regioes r
               LEFT JOIN unidades u ON u.regiao_id = r.id
               LEFT JOIN pedidos p ON p.unidade_id = u.id AND p.status <> 'CANCELADO' {$periodo}
              GROUP BY r.id, r.nome
              ORDER BY total_vendido DESC",
            $params
        );
    }

    /** Produtos mais consumidos (quantidade e receita). */
    public function produtosMaisVendidos(array $f, int $limite = 10): array
    {
        [$periodo, $params] = $this->periodo($f);
        $params['lim'] = $limite;
        $sql = "SELECT pi.nome_produto,
                       SUM(pi.quantidade) AS quantidade,
                       SUM(pi.subtotal)   AS receita
                  FROM pedido_itens pi
                  JOIN pedidos p ON p.id = pi.pedido_id AND p.status <> 'CANCELADO' {$periodo}
                 GROUP BY pi.nome_produto
                 ORDER BY quantidade DESC
                 LIMIT :lim";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, $k === 'lim' ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Resumo financeiro: faturamento aprovado, descontos, ticket médio, por canal. */
    public function financeiro(array $f): array
    {
        [$periodo, $params] = $this->periodo($f);

        $totais = $this->queryOne(
            "SELECT COUNT(DISTINCT p.id) AS pedidos_pagos,
                    COALESCE(SUM(p.total), 0)    AS faturamento,
                    COALESCE(SUM(p.desconto), 0) AS descontos_concedidos,
                    COALESCE(AVG(p.total), 0)    AS ticket_medio
               FROM pedidos p
               JOIN pagamentos pg ON pg.pedido_id = p.id AND pg.status = 'APROVADO'
              WHERE 1=1 {$periodo}",
            $params
        );

        $porCanal = $this->query(
            "SELECT p.canal,
                    COUNT(DISTINCT p.id) AS pedidos,
                    COALESCE(SUM(p.total), 0) AS faturamento
               FROM pedidos p
               JOIN pagamentos pg ON pg.pedido_id = p.id AND pg.status = 'APROVADO'
              WHERE 1=1 {$periodo}
              GROUP BY p.canal
              ORDER BY faturamento DESC",
            $params
        );

        return ['totais' => $totais, 'por_canal' => $porCanal];
    }
}
