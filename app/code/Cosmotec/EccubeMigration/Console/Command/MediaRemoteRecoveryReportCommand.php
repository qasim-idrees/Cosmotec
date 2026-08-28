<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Console\Command;

use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;
use Cosmotec\EccubeMigration\Model\Media\MediaRelationType;
use Cosmotec\EccubeMigration\Model\Media\RemoteMediaLocator;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only bulk reporting tool: for every current eccube_media_map
 * needs_review row, checks local filesystem existence (instant) and then
 * remote EC-CUBE S3/CloudFront existence (network), and reports a
 * per-relation-type breakdown of Local found / Remote found / Confirmed
 * missing / Remote errors - see docs task "10. Dry Run Full Dataset".
 *
 * Never writes to eccube_media_map, never downloads a file, never
 * touches Magento or MediaImporter - purely a read-only survey using the
 * exact same RemoteMediaLocator URL convention the real pipeline uses,
 * so its numbers are a faithful preview of what --execute would do.
 *
 * At 40,000+ rows, one request per file sequentially would take hours
 * (network round-trip bound) - remote checks run over a bounded pool of
 * concurrent cURL handles (curl_multi), isolated to this reporting tool
 * only. The real per-file import path (MediaImporter/RemoteMediaResolver)
 * remains a single Magento Curl request per file, unaffected by this
 * class - concurrency here is a reporting-scale concern, not a change to
 * how an actual import behaves.
 */
class MediaRemoteRecoveryReportCommand extends Command
{
    private const OPT_TYPE = 'type';
    private const OPT_CONCURRENCY = 'concurrency';
    private const OPT_LIMIT = 'limit';

    private const DEFAULT_CONCURRENCY = 20;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly RemoteMediaLocator $locator,
        private readonly EccubeConfigProviderInterface $config,
        private readonly State $appState
    ) {
        parent::__construct('cosmotec:eccube:media:remote-recovery-report');
    }

    protected function configure(): void
    {
        $this->setDescription(
            'Read-only report: for every needs_review media row, checks local + remote (EC-CUBE S3/CloudFront) '
            . 'existence and prints a per-relation-type breakdown. Never writes anything - see import:images to '
            . 'actually recover files after reviewing this report.'
        );
        $this->addOption(self::OPT_TYPE, null, InputOption::VALUE_REQUIRED, 'all|product|dimension|cad2d|cad3d|item|catalog|category (default: all)');
        $this->addOption(self::OPT_CONCURRENCY, null, InputOption::VALUE_REQUIRED, 'Concurrent remote checks (default: 20).');
        $this->addOption(self::OPT_LIMIT, null, InputOption::VALUE_REQUIRED, 'Cap records scanned per relation type (for a quick partial preview).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Throwable) {
            $this->appState->setAreaCode('adminhtml');
        }

        if (!$this->config->isEnabled()) {
            $output->writeln('<error>The EC-CUBE Migration module is disabled in Stores > Configuration.</error>');

            return Command::FAILURE;
        }

        $baseUrl = $this->config->getRemoteMediaBaseUrl();

        if ($baseUrl === null) {
            $output->writeln('<error>Remote Media Base URL is not configured (Stores > Configuration > Cosmotec > EC-CUBE Migration > Remote Media). Configure it before running this report.</error>');

            return Command::FAILURE;
        }

        $localFolder = $this->config->getImageFolder();
        $timeout = $this->config->getRemoteMediaTimeout();
        $concurrency = max(1, (int) ($input->getOption(self::OPT_CONCURRENCY) ?? self::DEFAULT_CONCURRENCY));
        $limit = $input->getOption(self::OPT_LIMIT) !== null ? (int) $input->getOption(self::OPT_LIMIT) : null;
        $typeFilter = $input->getOption(self::OPT_TYPE);

        $relationTypes = $typeFilter !== null && $typeFilter !== 'all'
            ? [MediaRelationType::from($typeFilter)]
            : MediaRelationType::all();

        $output->writeln(sprintf(
            'Media remote-recovery report (read-only) - base URL: %s, local folder: %s, concurrency: %d%s',
            $baseUrl,
            $localFolder ?? '(not configured)',
            $concurrency,
            $limit !== null ? sprintf(', limit=%d per type', $limit) : ''
        ));
        $output->writeln('');

        $grandTotals = ['total' => 0, 'local' => 0, 'remote' => 0, 'missing' => 0, 'errors' => 0];
        $rows = [];

        foreach ($relationTypes as $relationType) {
            $records = $this->fetchNeedsReview($relationType, $limit);
            $total = count($records);

            if ($total === 0) {
                $rows[] = [$relationType->value, 0, 0, 0, 0, 0];

                continue;
            }

            $output->writeln(sprintf('Scanning %s: %d record(s)...', $relationType->value, $total));

            [$localCount, $stillMissing] = $this->splitLocal($records, $relationType, $localFolder);

            [$remoteFound, $confirmedMissing, $remoteErrors] = $this->checkRemoteConcurrently(
                $stillMissing,
                $relationType,
                $baseUrl,
                $timeout,
                $concurrency,
                $output
            );

            $rows[] = [$relationType->value, $total, $localCount, $remoteFound, $confirmedMissing, $remoteErrors];

            $grandTotals['total'] += $total;
            $grandTotals['local'] += $localCount;
            $grandTotals['remote'] += $remoteFound;
            $grandTotals['missing'] += $confirmedMissing;
            $grandTotals['errors'] += $remoteErrors;
        }

        $output->writeln('');
        $this->renderTable($output, $rows, $grandTotals);

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array{eccube_upload_file_id:int, eccube_owner_id:int, source_file_name:string}>
     */
    private function fetchNeedsReview(MediaRelationType $relationType, ?int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('eccube_media_map'),
                ['eccube_upload_file_id', 'eccube_owner_id', 'source_file_name']
            )
            ->where('status = ?', 'needs_review')
            ->where('relation_type = ?', $relationType->value)
            ->order('eccube_upload_file_id ASC');

        if ($limit !== null) {
            $select->limit($limit);
        }

        return $connection->fetchAll($select);
    }

    /**
     * Splits records into "found locally right now" vs "still needs a
     * remote check" - cheap, no network, and catches any file that was
     * added to the local folder since the row was last marked
     * needs_review.
     *
     * @param array<int, array{eccube_upload_file_id:int, eccube_owner_id:int, source_file_name:string}> $records
     * @return array{0:int, 1:array<int, array{eccube_upload_file_id:int, eccube_owner_id:int, source_file_name:string}>}
     */
    private function splitLocal(array $records, MediaRelationType $relationType, ?string $localFolder): array
    {
        if ($localFolder === null) {
            return [0, $records];
        }

        $localCount = 0;
        $stillMissing = [];

        foreach ($records as $record) {
            $path = rtrim($localFolder, '/') . '/' . ltrim((string) $record['source_file_name'], '/');

            if (is_file($path)) {
                $localCount++;
            } else {
                $stillMissing[] = $record;
            }
        }

        return [$localCount, $stillMissing];
    }

    /**
     * @param array<int, array{eccube_upload_file_id:int, eccube_owner_id:int, source_file_name:string}> $records
     * @return array{0:int, 1:int, 2:int} [remoteFound, confirmedMissing, remoteErrors]
     */
    private function checkRemoteConcurrently(
        array $records,
        MediaRelationType $relationType,
        string $baseUrl,
        int $timeout,
        int $concurrency,
        OutputInterface $output
    ): array {
        if ($records === []) {
            return [0, 0, 0];
        }

        $remoteFound = 0;
        $confirmedMissing = 0;
        $remoteErrors = 0;
        $processed = 0;
        $total = count($records);
        $queue = $records;

        $multiHandle = curl_multi_init();
        /** @var array<int, resource|\CurlHandle> $active */
        $active = [];

        $startNext = function () use (&$queue, &$active, $multiHandle, $relationType, $baseUrl, $timeout): void {
            if ($queue === []) {
                return;
            }

            $record = array_shift($queue);
            $url = $this->locator->buildOriginalUrl($baseUrl, $relationType, (string) $record['source_file_name']);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            curl_multi_add_handle($multiHandle, $ch);
            $active[(int) $ch] = $ch;
        };

        for ($i = 0; $i < $concurrency; $i++) {
            $startNext();
        }

        $running = null;

        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle, 1.0);

            while (($info = curl_multi_info_read($multiHandle)) !== false) {
                $ch = $info['handle'];
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErrno = curl_errno($ch);

                if ($curlErrno !== 0 || $status === 0) {
                    $remoteErrors++;
                } elseif ($status === 200 || $status === 206) {
                    $remoteFound++;
                } elseif ($status === 403 || $status === 404) {
                    $confirmedMissing++;
                } else {
                    $remoteErrors++;
                }

                curl_multi_remove_handle($multiHandle, $ch);
                curl_close($ch);
                unset($active[(int) $ch]);
                $processed++;

                if ($processed % 500 === 0 || $processed === $total) {
                    $output->writeln(sprintf('  %s: %d / %d checked', $relationType->value, $processed, $total));
                }

                $startNext();
            }
        } while ($running > 0 || $queue !== [] || count($active) > 0);

        curl_multi_close($multiHandle);

        return [$remoteFound, $confirmedMissing, $remoteErrors];
    }

    /**
     * @param array<int, array{0:string,1:int,2:int,3:int,4:int,5:int}> $rows
     * @param array{total:int, local:int, remote:int, missing:int, errors:int} $totals
     */
    private function renderTable(OutputInterface $output, array $rows, array $totals): void
    {
        $header = ['Relation type', 'Total', 'Local found', 'Remote found', 'Confirmed missing', 'Remote errors'];
        $widths = array_map('mb_strlen', $header);

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], mb_strlen((string) $cell));
            }
        }

        $formatRow = static function (array $cells) use ($widths): string {
            $parts = [];

            foreach ($cells as $i => $cell) {
                $parts[] = str_pad((string) $cell, $widths[$i]);
            }

            return '| ' . implode(' | ', $parts) . ' |';
        };

        $separator = '+-' . implode('-+-', array_map(static fn (int $w): string => str_repeat('-', $w), $widths)) . '-+';

        $output->writeln($separator);
        $output->writeln($formatRow($header));
        $output->writeln($separator);

        foreach ($rows as $row) {
            $output->writeln($formatRow($row));
        }

        $output->writeln($separator);
        $output->writeln('');
        $output->writeln(sprintf('Total needs_review before: %d', $totals['total']));
        $output->writeln(sprintf('Local recoverable: %d', $totals['local']));
        $output->writeln(sprintf('Remote recoverable: %d', $totals['remote']));
        $output->writeln(sprintf('Genuinely missing (confirmed 403/404 both sources): %d', $totals['missing']));
        $output->writeln(sprintf('Remote check failures (inconclusive - timeout/5xx/connection error): %d', $totals['errors']));
        $output->writeln(sprintf('Total recoverable (local + remote): %d', $totals['local'] + $totals['remote']));
    }
}
