<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Logger\Diagnostics;

use Magento\Framework\Exception\AbstractAggregateException;
use Magento\Framework\Exception\LocalizedException;

/**
 * Renders an exception chain into a single diagnostic line.
 *
 * Magento frequently reports a generic outer message such as
 * "The product can't be saved." while the real cause sits further down the
 * chain, so stopping at getMessage() hides the actual failure.
 */
class ExceptionFormatter
{
    private const MAX_DEPTH = 10;

    /**
     * @param array<string, scalar|null> $context
     */
    public function format(\Throwable $e, array $context = []): string
    {
        $parts = [];

        foreach ($context as $key => $value) {
            $parts[] = sprintf('%s=%s', $key, $value === null ? '(null)' : (string) $value);
        }

        $parts[] = 'exception=' . $this->describe($e);

        $previous = $e->getPrevious();
        $depth = 0;

        while ($previous !== null && $depth < self::MAX_DEPTH) {
            $parts[] = sprintf('previous[%d]=%s', $depth, $this->describe($previous));
            $previous = $previous->getPrevious();
            $depth++;
        }

        $parts[] = sprintf('origin=%s:%d', $e->getFile(), $e->getLine());

        return implode(' ', $parts);
    }

    private function describe(\Throwable $e): string
    {
        $description = sprintf('%s("%s")', $e::class, $this->sanitize($e->getMessage()));

        // Magento aggregates per-field validation failures separately from
        // the top-level message; without these the real reason a product
        // was rejected is invisible.
        if ($e instanceof AbstractAggregateException) {
            $errors = [];

            foreach ($e->getErrors() as $error) {
                $errors[] = $this->sanitize($error->getMessage());
            }

            if ($errors !== []) {
                $description .= ' errors=[' . implode(' | ', $errors) . ']';
            }
        } elseif ($e instanceof LocalizedException) {
            $raw = $this->sanitize((string) $e->getRawMessage());

            if ($raw !== '' && $raw !== $this->sanitize($e->getMessage())) {
                $description .= sprintf(' raw="%s"', $raw);
            }
        }

        return $description;
    }

    /**
     * Keeps log lines single-line and bounded; no credentials or payloads
     * are ever logged, only messages and class names.
     */
    private function sanitize(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;
        $message = trim($message);

        return mb_strlen($message) > 500 ? mb_substr($message, 0, 499) . '…' : $message;
    }
}
