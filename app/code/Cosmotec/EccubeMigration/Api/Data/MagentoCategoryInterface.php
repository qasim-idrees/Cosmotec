<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api\Data;

/**
 * The Magento-target shape produced by Model/Mapper/CategoryMapper from an
 * EC-CUBE CategoryInterface. Importer/Sync classes consume this, never the
 * EC-CUBE-side CategoryInterface directly, keeping the "what EC-CUBE looks
 * like" and "what Magento needs" concerns fully separated.
 */
interface MagentoCategoryInterface
{
    public function getEccubeCategoryId(): int;

    public function getName(): string;

    /**
     * Resolved Magento parent category entity_id: either the configured
     * root category (for EC-CUBE top-level categories) or the
     * already-imported Magento id of the EC-CUBE parent category.
     */
    public function getMagentoParentId(): int;

    public function isActive(): bool;

    public function isIncludeInMenu(): bool;

    public function getPosition(): int;

    public function getDescription(): ?string;

    public function getUrlKey(): ?string;

    /**
     * Hash of every field above, used by CategorySync (Milestone 8) to
     * detect whether the source record actually changed before writing.
     */
    public function getContentHash(): string;
}
