<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Connection;

use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;

/**
 * Builds the single EccubeConnection instance used throughout a request /
 * CLI invocation from the admin-configured connection settings. Kept as a
 * factory (rather than a di.xml virtualType with argument injection) so the
 * connection can be built lazily and its parameters can later be overridden
 * per-command (e.g. a future --host override flag) without touching di.xml.
 */
class EccubeConnectionFactory
{
    private ?EccubeConnectionInterface $instance = null;

    public function __construct(
        private readonly EccubeConfigProviderInterface $config
    ) {
    }

    /**
     * @param array<string, mixed> $overrides Optional overrides: host, port, database, username, password, charset, timeout
     */
    public function create(array $overrides = []): EccubeConnectionInterface
    {
        if ($overrides === [] && $this->instance instanceof EccubeConnectionInterface) {
            return $this->instance;
        }

        $connection = new EccubeConnection(
            (string) ($overrides['host'] ?? $this->config->getHost()),
            (int) ($overrides['port'] ?? $this->config->getPort()),
            (string) ($overrides['database'] ?? $this->config->getDatabase()),
            (string) ($overrides['username'] ?? $this->config->getUsername()),
            (string) ($overrides['password'] ?? $this->config->getPassword()),
            (string) ($overrides['charset'] ?? $this->config->getCharset()),
            (int) ($overrides['timeout'] ?? $this->config->getTimeout())
        );

        if ($overrides === []) {
            $this->instance = $connection;
        }

        return $connection;
    }
}
