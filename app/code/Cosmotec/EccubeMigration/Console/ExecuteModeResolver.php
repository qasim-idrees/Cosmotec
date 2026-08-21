<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Console;

/**
 * Single source of truth for the dry-run/execute decision shared by every
 * --execute-required command (import:attributes, import:attribute-sets,
 * import:item-attribute-values, import:product-attribute-values,
 * import:related-products, import:connection-parts, and their sync:*
 * counterparts).
 *
 * Bug this class fixes: those commands previously OR'd the module's
 * admin-configured "Dry Run by Default" setting into the decision
 * alongside --execute:
 *
 *     $dryRun = !$execute || $dryRunFlag || $config->isDryRunByDefault();
 *
 * When that admin setting is Yes, the OR made it impossible for
 * --execute to ever actually execute - a real, live-reproduced bug
 * (--execute silently stayed in dry-run mode with no error or warning).
 *
 * Fix: for this --execute-required command family, the admin default is
 * deliberately NOT consulted at all - matching the already-working
 * import:images (ImportImagesCommand), which never referenced it either.
 * Dry-run is already the unconditional, hard default for this family
 * (per each command's own description: "Dry-run unless --execute is
 * passed"), so the admin setting has nothing to add and only reintroduces
 * the silent-override bug if consulted. The admin setting itself is left
 * entirely in place (system.xml, ModuleConfig::isDryRunByDefault()) and
 * continues to govern the older, separate command family that has no
 * --execute option at all (import:categories, sync:categories,
 * import:inventory, etc.) - unrelated to this fix and not touched by it.
 *
 * Precedence, safest first (identical semantics to ImportImagesCommand,
 * now made explicit and shared instead of duplicated 12 times):
 *   1. --dry-run always wins, even alongside --execute.
 *   2. --execute enables writing.
 *   3. Neither flag: dry run, always - never inferred from omission.
 */
class ExecuteModeResolver
{
    public function isDryRun(bool $dryRunFlag, bool $executeFlag): bool
    {
        return $dryRunFlag || !$executeFlag;
    }
}
