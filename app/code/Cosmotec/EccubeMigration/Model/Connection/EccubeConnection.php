<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Connection;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Lazily-connecting PDO wrapper around the EC-CUBE MariaDB/MySQL database.
 * Never touches Magento's own DB connection.
 */
class EccubeConnection implements EccubeConnectionInterface
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
        private readonly string $charset,
        private readonly int $timeout
    ) {
    }

    public function fetchAll(string $sql, array $bind = []): array
    {
        $statement = $this->execute($sql, $bind);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    public function fetchOne(string $sql, array $bind = []): ?array
    {
        $statement = $this->execute($sql, $bind);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function fetchScalar(string $sql, array $bind = [])
    {
        $statement = $this->execute($sql, $bind);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    public function ping(): bool
    {
        return $this->fetchScalar('SELECT 1') === '1' || $this->fetchScalar('SELECT 1') === 1;
    }

    /**
     * @param array<string, mixed> $bind
     */
    private function execute(string $sql, array $bind): PDOStatement
    {
        try {
            $statement = $this->getConnection()->prepare($sql);
            $statement->execute($bind);

            return $statement;
        } catch (PDOException $e) {
            throw new EccubeConnectionException(
                __('EC-CUBE query failed: %1', $e->getMessage()),
                $e
            );
        }
    }

    private function getConnection(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->database,
            $this->charset
        );

        try {
            $this->pdo = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => $this->timeout,
                PDO::ATTR_PERSISTENT => false,
            ]);
        } catch (PDOException $e) {
            throw new EccubeConnectionException(
                __(
                    'Could not connect to the EC-CUBE database at %1:%2/%3: %4',
                    $this->host,
                    $this->port,
                    $this->database,
                    $e->getMessage()
                ),
                $e
            );
        }

        return $this->pdo;
    }
}
