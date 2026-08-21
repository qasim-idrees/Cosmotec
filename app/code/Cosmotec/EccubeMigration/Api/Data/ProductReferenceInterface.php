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
 * One dtb_product_reference row - a document name plus reference link
 * belonging to a child product.
 *
 * The admin UI exposes only two slots, but the source is a genuine 1:N
 * relation (25,586 rows). This DTO deliberately carries no slot number:
 * flattening to document_name_1/2 would bake a UI limit into the data
 * model and truncate any product that gains a third reference.
 */
interface ProductReferenceInterface
{
    public function getId(): int;

    public function getProductId(): int;

    public function getName(): ?string;

    public function getLink(): ?string;

    /**
     * Content hash over name+link, used to detect source changes.
     */
    public function getContentHash(): string;
}
