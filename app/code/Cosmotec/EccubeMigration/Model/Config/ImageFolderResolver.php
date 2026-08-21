<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Config;

use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;

/**
 * Validates and resolves the configured EC-CUBE image root.
 *
 * EC-CUBE stores only the bare filename in dtb_upload_file; the directory
 * (conventionally <eccube-root>/html/upload/save_image) comes from module
 * configuration. That configured value must therefore be an ABSOLUTE path
 * on the Magento server - a relative value such as
 * "html/upload/save_image" resolves against the CLI working directory and
 * silently fails for every file.
 *
 * No path is ever guessed or hardcoded here: the configured value is
 * validated and reported, never substituted.
 */
class ImageFolderResolver
{
    public function __construct(
        private readonly EccubeConfigProviderInterface $config
    ) {
    }

    /**
     * @return string|null the validated absolute directory, or null
     */
    public function resolve(): ?string
    {
        $configured = $this->config->getImageFolder();

        if ($configured === null || trim($configured) === '') {
            return null;
        }

        $path = rtrim(trim($configured), '/');

        return $path === '' ? null : $path;
    }

    /**
     * Human-readable reason the configured folder cannot be used, or null
     * when it is usable. Checked once before an import rather than
     * producing one error per file.
     */
    public function validate(): ?string
    {
        $configured = $this->config->getImageFolder();

        if ($configured === null || trim($configured) === '') {
            return 'EC-CUBE Image Folder Path is not configured '
                . '(Stores > Configuration > Cosmotec > EC-CUBE Migration).';
        }

        $path = rtrim(trim($configured), '/');

        if (!str_starts_with($path, '/')) {
            return sprintf(
                'EC-CUBE Image Folder Path must be an absolute path on this server, got "%s". '
                . 'A relative value resolves against the CLI working directory. '
                . 'Configure the absolute path to the EC-CUBE image directory '
                . '(normally <eccube-root>/html/upload/save_image).',
                $configured
            );
        }

        if (!is_dir($path)) {
            return sprintf('EC-CUBE Image Folder Path is invalid or unreadable: %s (not a directory)', $path);
        }

        if (!is_readable($path)) {
            return sprintf(
                'EC-CUBE Image Folder Path is invalid or unreadable: %s '
                . '(directory exists but is not readable by the PHP user)',
                $path
            );
        }

        return null;
    }
}
