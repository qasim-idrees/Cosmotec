<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Mapper\Strategy;

class ProductTypeStrategyPool
{
    /**
     * @param ProductTypeStrategyInterface[] $strategies Keyed by type_id, injected via etc/di.xml
     */
    public function __construct(
        private readonly array $strategies,
        private readonly string $defaultTypeId = 'grouped'
    ) {
    }

    public function get(?string $typeId = null): ProductTypeStrategyInterface
    {
        $typeId ??= $this->defaultTypeId;

        if (!isset($this->strategies[$typeId])) {
            throw new \InvalidArgumentException(sprintf(
                'No ProductTypeStrategyInterface registered for type_id "%s". Registered: %s',
                $typeId,
                implode(', ', array_keys($this->strategies))
            ));
        }

        $strategy = $this->strategies[$typeId];

        if (!$strategy instanceof ProductTypeStrategyInterface) {
            throw new \InvalidArgumentException(sprintf(
                'Strategy registered for type_id "%s" does not implement %s',
                $typeId,
                ProductTypeStrategyInterface::class
            ));
        }

        return $strategy;
    }
}
