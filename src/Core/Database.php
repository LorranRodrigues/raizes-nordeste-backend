<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/**
 * Conexão única (singleton) com o banco via PDO.
 *
 * PDO com prepared statements é a base da defesa contra SQL Injection
 * (requisito de segurança do estudo de caso). A conexão é reaproveitada
 * dentro da mesma requisição para reduzir overhead.
 */
final class Database
{
    private static ?PDO $instance = null;

    private function __construct()
    {
    }

    public static function connection(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $config = (require __DIR__ . '/../../config/config.php')['db'];

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        try {
            self::$instance = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            // Não vaza credenciais; o front controller decide o que exibir.
            throw new HttpException('Falha ao conectar ao banco de dados.', 500, [
                'pdo' => $e->getMessage(),
            ]);
        }

        return self::$instance;
    }

    /** Reinicia a conexão (útil em testes). */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
