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
 * One dtb_item_additional_information row - a database-driven HTML tab
 * (Catalog, Assembly method, Pressing tools, etc.) belonging to a parent
 * item. Genuinely 1:N per item (up to 3 tabs observed); tab names are not
 * enumerable ahead of time (89 distinct names, source-confirmed), so this
 * DTO carries the tab name as data rather than the tab being a fixed
 * field.
 */
interface ItemAdditionalContentInterface
{
    public function getId(): int;

    public function getItemId(): int;

    public function getTabNameEn(): ?string;

    public function getTabNameJa(): ?string;

    public function getHtmlContent(): ?string;

    /**
     * Content hash over tab name (both languages) + HTML content, used to
     * detect source changes.
     */
    public function getContentHash(): string;
}
