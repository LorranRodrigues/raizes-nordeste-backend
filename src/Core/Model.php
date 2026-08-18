<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Modelo base (Active Record simplificado). Cada modelo concreto define a
 * tabela e as colunas preenchíveis. Centraliza o acesso a dados via PDO com
 * prepared statements, evitando repetição e reduzindo risco de SQL Injection.
 */
abstract class Model
{
    /** Nome da tabela no banco. */
    protected string $table;

    /** Colunas que podem ser inseridas/atualizadas em massa. */
    protected array $fillable = [];

    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /** Retorna todos os registros, com filtros simples (coluna => valor). */
    public function all(array $where = [], string $orderBy = 'id DESC', ?int $limit = null): array
    {
        $sql = "SELECT * FROM `{$this->table}`";
        $params = [];

        if ($where !== []) {
            $clauses = [];
            foreach ($where as $col => $val) {
                $clauses[] = "`{$col}` = :{$col}";
                $params[$col] = $val;
            }
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        $sql .= " ORDER BY {$orderBy}";
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Listagem paginada de uma tabela simples.
     * @return array{data: array, total: int}
     */
    public function paginate(array $where = [], string $orderBy = 'id DESC', int $page = 1, int $limit = 10): array
    {
        $clauses = '';
        $params = [];
        if ($where !== []) {
            $parts = [];
            foreach ($where as $col => $val) {
                $parts[] = "`{$col}` = :{$col}";
                $params[$col] = $val;
            }
            $clauses = ' WHERE ' . implode(' AND ', $parts);
        }

        $totalStmt = $this->db->prepare("SELECT COUNT(*) AS n FROM `{$this->table}`{$clauses}");
        $totalStmt->execute($params);
        $total = (int) $totalStmt->fetch()['n'];

        $offset = ($page - 1) * $limit;
        $stmt = $this->db->prepare(
            "SELECT * FROM `{$this->table}`{$clauses} ORDER BY {$orderBy} LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return ['data' => $stmt->fetchAll(), 'total' => $total];
    }

    /** Busca por id; retorna null se não existir. */
    public function find(int|string $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM `{$this->table}` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Busca por id ou lança 404. */
    public function findOrFail(int|string $id): array
    {
        $row = $this->find($id);
        if ($row === null) {
            throw HttpException::notFound("Registro não encontrado em {$this->table}.");
        }
        return $row;
    }

    /** Primeiro registro que casa com os filtros. */
    public function firstWhere(array $where): ?array
    {
        $rows = $this->all($where, 'id DESC', 1);
        return $rows[0] ?? null;
    }

    /** Insere respeitando $fillable e retorna o id gerado. */
    public function create(array $data): int
    {
        $data = $this->onlyFillable($data);
        $cols = array_keys($data);
        $placeholders = array_map(static fn ($c) => ":{$c}", $cols);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->table,
            '`' . implode('`, `', $cols) . '`',
            implode(', ', $placeholders)
        );
        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);
        return (int) $this->db->lastInsertId();
    }

    /** Atualiza por id respeitando $fillable. Retorna linhas afetadas. */
    public function update(int|string $id, array $data): int
    {
        $data = $this->onlyFillable($data);
        if ($data === []) {
            return 0;
        }
        $sets = array_map(static fn ($c) => "`{$c}` = :{$c}", array_keys($data));
        $sql = sprintf('UPDATE `%s` SET %s WHERE `id` = :id', $this->table, implode(', ', $sets));
        $data['id'] = $id;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);
        return $stmt->rowCount();
    }

    /** Remove por id. Retorna linhas afetadas. */
    public function delete(int|string $id): int
    {
        $stmt = $this->db->prepare("DELETE FROM `{$this->table}` WHERE `id` = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount();
    }

    /** Executa uma consulta arbitrária preparada e devolve todas as linhas. */
    protected function query(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Executa uma consulta que retorna uma única linha (ou null). */
    protected function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function onlyFillable(array $data): array
    {
        if ($this->fillable === []) {
            return $data;
        }
        return array_intersect_key($data, array_flip($this->fillable));
    }

    public function pdo(): PDO
    {
        return $this->db;
    }
}
