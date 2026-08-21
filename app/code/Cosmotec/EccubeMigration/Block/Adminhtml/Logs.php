<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

class Logs extends Template
{
    private const MAX_LINES = 300;
    private const MAX_TAIL_BYTES = 512000;

    private const LOG_FILES = [
        'Import Log' => 'eccube_import.log',
        'Sync Log' => 'eccube_sync.log',
        'Error Log' => 'eccube_error.log',
    ];

    public function __construct(
        Context $context,
        private readonly Filesystem $filesystem,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<string, array{path: string, exists: bool, content: string}>
     */
    public function getLogs(): array
    {
        $varDirectory = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $result = [];

        foreach (self::LOG_FILES as $label => $fileName) {
            $relativePath = 'log/' . $fileName;
            $exists = $varDirectory->isExist($relativePath);

            $result[$label] = [
                'path' => 'var/' . $relativePath,
                'exists' => $exists,
                'content' => $exists ? $this->tail($varDirectory->getAbsolutePath($relativePath)) : '',
            ];
        }

        return $result;
    }

    /**
     * Reads at most the last MAX_TAIL_BYTES bytes of the file (not the
     * whole thing — these logs can grow large over a long migration) and
     * returns the last MAX_LINES lines of that.
     */
    private function tail(string $absolutePath): string
    {
        $size = @filesize($absolutePath);

        if ($size === false) {
            return '';
        }

        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            return '';
        }

        $readSize = min($size, self::MAX_TAIL_BYTES);
        fseek($handle, -$readSize, SEEK_END);
        $chunk = fread($handle, $readSize);
        fclose($handle);

        if ($chunk === false) {
            return '';
        }

        $lines = explode("\n", $chunk);
        $lines = array_slice($lines, -self::MAX_LINES);

        return implode("\n", $lines);
    }
}
