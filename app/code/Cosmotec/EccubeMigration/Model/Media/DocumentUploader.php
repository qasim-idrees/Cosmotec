<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Media;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\MediaStorage\Model\File\UploaderFactory;

/**
 * Handles the three "EC-CUBE Documents" file uploads (Task 5.1-5.3):
 * Dimension Image, CAD 2D, CAD 3D. Deliberately not the Magento gallery
 * API (these are not catalog images/gallery entries) and not
 * MediaImporter's own copyToModuleMedia() (that stores under
 * pub/media/cosmotec/eccube/, whereas CAD files here must live under
 * pub/media/cad/ per the explicit requirement) - a small, self-contained
 * uploader using Magento's own Uploader/Filesystem primitives rather than
 * raw filesystem paths.
 */
class DocumentUploader
{
    public const SUBDIR_DIMENSION = 'cosmotec/eccube/dimension';
    public const SUBDIR_CAD2D = 'cad/cad2d';
    public const SUBDIR_CAD3D = 'cad/cad3d';

    public function __construct(
        private readonly UploaderFactory $uploaderFactory,
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * @param string[] $allowedExtensions
     *
     * @throws LocalizedException
     */
    public function upload(string $inputName, string $subDir, array $allowedExtensions): string
    {
        $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $absoluteTarget = $mediaDirectory->getAbsolutePath($subDir);

        $uploader = $this->uploaderFactory->create(['fileId' => $inputName]);
        $uploader->setAllowedExtensions($allowedExtensions);
        $uploader->setAllowRenameFiles(true);
        $uploader->setFilesDispersion(false);

        $result = $uploader->save($absoluteTarget);

        if (!$result) {
            throw new LocalizedException(__('Upload failed for "%1".', $inputName));
        }

        return $subDir . '/' . $result['file'];
    }

    /**
     * Copies an already-on-disk source file (EC-CUBE import path) into the
     * target sub-directory, as opposed to upload() which handles an HTTP
     * multipart upload from the admin "replace file" action. Used by
     * MediaImporter; content-addressed by upload_file_id so a repeated
     * import of the same source file is a no-op rather than a duplicate.
     *
     * @throws LocalizedException
     */
    public function copyFile(string $absoluteSourcePath, string $subDir, string $fileName): string
    {
        $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $relativePath = $subDir . '/' . $fileName;

        if (!$mediaDirectory->isExist($relativePath)) {
            $contents = file_get_contents($absoluteSourcePath);

            if ($contents === false) {
                throw new LocalizedException(__('Could not read source file "%1".', $absoluteSourcePath));
            }

            $mediaDirectory->writeFile($relativePath, $contents);
        }

        return $relativePath;
    }

    public function remove(?string $relativePath): void
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return;
        }

        $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);

        if ($mediaDirectory->isExist($relativePath)) {
            $mediaDirectory->delete($relativePath);
        }
    }
}
