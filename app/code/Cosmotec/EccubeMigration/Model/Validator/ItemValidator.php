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

        if (trim($source->getNameEn()) === '') {
            $errors[] = sprintf('Item id=%d has an empty name_en', $source->getId());
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
