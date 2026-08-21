<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionFactory;
use Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionInterface;

/**
 * Common row -> scalar hydration helpers shared by every EC-CUBE repository.
 * SQL itself still lives only in the concrete repository classes.
 */
abstract class AbstractEccubeRepository
{
    protected readonly EccubeConnectionInterface $connection;

    public function __construct(EccubeConnectionFactory $connectionFactory)
    {
        $this->connection = $connectionFactory->create();
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function toDateTimeImmutable(array $row, string $column): \DateTimeImmutable
    {
        $value = $row[$column] ?? null;

        if ($value === null || $value === '') {
            return new \DateTimeImmutable('@0');
        }

        return new \DateTimeImmutable((string) $value);
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function toNullableDateTimeImmutable(array $row, string $column): ?\DateTimeImmutable
    {
        $value = $row[$column] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return new \DateTimeImmutable((string) $value);
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function toNullableString(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function toNullableInt(array $row, string $column): ?int
    {
        $value = $row[$column] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function toBool(array $row, string $column): bool
    {
        return (bool) ($row[$column] ?? false);
    }
}
