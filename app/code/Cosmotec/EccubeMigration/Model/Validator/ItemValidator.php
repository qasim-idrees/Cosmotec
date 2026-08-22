<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Validator;

use Cosmotec\EccubeMigration\Api\Data\ItemInterface;

class ItemValidator implements ValidatorInterface
{
    private const VALID_DISPLAY_STATUS_IDS = [1, 2];

    public function validate(object $source): ValidationResult
    {
        if (!$source instanceof ItemInterface) {
            return ValidationResult::failure([
                sprintf('Expected %s, got %s', ItemInterface::class, get_debug_type($source)),
            ]);
        }

        $errors = [];

        // See ProductValidator for the same language-policy fallback
        // (CLAUDE.md "Language") - only a genuine error when BOTH
        // languages are empty, since GroupedProductStrategy::resolveName()
        // can legitimately use the Japanese name.
        if (trim($source->getNameEn()) === '' && trim($source->getName()) === '') {
            $errors[] = sprintf('Item id=%d has no usable name in either language (name_en and name both empty)', $source->getId());
        }

        $displayStatusId = $source->getDisplayStatusId();

        if ($displayStatusId !== null && !in_array($displayStatusId, self::VALID_DISPLAY_STATUS_IDS, true)) {
            $errors[] = sprintf(
                'Item id=%d has an unrecognized display_status_id=%d (expected 1=show or 2=hide)',
                $source->getId(),
                $displayStatusId
            );
        }

        return $errors === [] ? ValidationResult::success() : ValidationResult::failure($errors);
    }
}
