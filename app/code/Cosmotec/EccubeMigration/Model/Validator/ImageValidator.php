<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Validator;

use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;
use Cosmotec\EccubeMigration\Api\Data\ImageInterface;

class ImageValidator implements ValidatorInterface
{
    public function __construct(
        private readonly EccubeConfigProviderInterface $config
    ) {
    }

    public function validate(object $source): ValidationResult
    {
        if (!$source instanceof ImageInterface) {
            return ValidationResult::failure([
                sprintf('Expected %s, got %s', ImageInterface::class, get_debug_type($source)),
            ]);
        }

        $errors = [];

        if (trim($source->getFileName()) === '') {
            $errors[] = sprintf('Image id=%d has an empty file_name', $source->getId());

            return ValidationResult::failure($errors);
        }

        $imageFolder = $this->config->getImageFolder();

        if ($imageFolder === null) {
            $errors[] = 'EC-CUBE Image Folder Path is not configured (Stores > Configuration > Cosmotec > EC-CUBE Migration).';

            return ValidationResult::failure($errors);
        }

        $absolutePath = rtrim($imageFolder, '/') . '/' . ltrim($source->getFileName(), '/');

        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            $errors[] = sprintf(
                'Image id=%d: file "%s" does not exist or is not readable',
                $source->getId(),
                $absolutePath
            );
        }

        return $errors === [] ? ValidationResult::success() : ValidationResult::failure($errors);
    }
}
