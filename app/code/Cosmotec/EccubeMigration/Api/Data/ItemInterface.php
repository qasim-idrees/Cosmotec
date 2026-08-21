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
 * Maps 1:1 to dtb_item. dtb_item is the parent entity of dtb_product
 * (dtb_product.item_id -> dtb_item.id) and is confirmed to map to Magento
 * Grouped Product.
 *
 * Note: dtb_item has NO update_date column in this schema, unlike
 * dtb_category / dtb_product. Incremental sync (Milestone 8) for items
 * cannot rely on a timestamp here and must either hash content or derive
 * "changed" state from the most recent update_date among its child
 * dtb_product rows.
 */
interface ItemInterface
{
    public function getId(): int;

    public function getName(): string;

    public function getNameEn(): string;

    public function getShortName(): string;

    public function getShortNameEn(): string;

    public function getDescription(): ?string;

    public function getDescriptionEn(): ?string;

    /**
     * References mtb_display_status: 1 = DISPLAY_SHOW, 2 = DISPLAY_HIDE.
     */
    public function getDisplayStatusId(): ?int;
}
