<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Connection;

/**
 * Dedicated read-only connection to the EC-CUBE database. This is
 * intentionally separate from Magento's own DB connection(s) — EC-CUBE is
 * always a distinct database/server, and repositories must never reuse
 * Magento's \Magento\Framework\App\ResourceConnection for it.
 */
interface EccubeConnectionInterface
{
    /**
     * Run a SELECT and return all rows as associative arrays.
     *
     * @param string $sql
     * @param array<string, mixed> $bind
     * @return array<int, array<string, mixed>>
     * @throws EccubeConnectionException
     */
    public function fetchAll(string $sql, array $bind = []): array;

    /**
     * Run a SELECT and return the first row, or null if there isn't one.
     *
     * @param string $sql
     * @param array<string, mixed> $bind
     * @return array<string, mixed>|null
     * @throws EccubeConnectionException
     */
    public function fetchOne(string $sql, array $bind = []): ?array;

    /**
     * Run a SELECT and return a single scalar value, or null.
     *
     * @param string $sql
     * @param array<string, mixed> $bind
     * @return mixed
     * @throws EccubeConnectionException
     */
    public function fetchScalar(string $sql, array $bind = []);

    /**
     * Verify connectivity (used by cosmotec:eccube:test-connection).
     *
     * @throws EccubeConnectionException
     */
    public function ping(): bool;
}
