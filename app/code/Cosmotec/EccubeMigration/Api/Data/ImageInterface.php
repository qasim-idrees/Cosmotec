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
 * Maps 1:1 to dtb_product_image. file_name is the raw EC-CUBE filename;
 * resolving it to an absolute path against the configured image folder is
 * the Image Reader/Importer's job (Milestone 6), not this DTO's.
 */
interface ImageInterface
{
    public function getId(): int;

    public function getProductId(): ?int;

    public function getFileName(): string;

    public function getSortNo(): int;

    public function getCreateDate(): \DateTimeImmutable;
}
