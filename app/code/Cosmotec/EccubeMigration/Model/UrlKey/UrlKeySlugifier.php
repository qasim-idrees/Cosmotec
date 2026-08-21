<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\UrlKey;

/**
 * Pure string transform, deliberately with no Magento dependency at all -
 * not `Magento\Catalog\Model\Product\Url::formatUrlKey()`, which only
 * transliterates when the store's "Use Web Server Rewrites"/transliteration
 * config is enabled (`Product::XML_PATH_APPLY_TRANSLITERATION_TO_URL`) and
 * otherwise just replaces whitespace with hyphens and lowercases, leaving
 * any other character (including raw Japanese) untouched. Relying on that
 * would make the migration's URL keys depend on a target store's admin
 * config, which cannot be assumed for a fresh Magento install. This class
 * always behaves the same way regardless of target environment.
 *
 * Also NOT `Magento\Framework\Filter\Translit` - live-checked this session:
 * its conversion table covers Latin diacritics, Cyrillic, Hebrew, Greek and
 * Bengali, but has zero Japanese (hiragana/katakana/kanji) entries. Its own
 * iconv fallback (`ascii//ignore//translit`) silently drops untransliterable
 * characters, which for Japanese-only text produces an empty result - this
 * is exactly the "empty/unusable key" case the caller must detect and give
 * a deterministic fallback for (see UrlKeyResolver).
 */
class UrlKeySlugifier
{
    private const MAX_LENGTH = 200;

    /**
     * @return string a clean lowercase-ascii-hyphenated slug, or '' if the
     *                 input contains no transliterable/ASCII-alphanumeric
     *                 content at all (e.g. Japanese-only text, or a name
     *                 consisting only of symbols) - the caller must supply
     *                 a deterministic fallback for that case.
     */
    public function slugify(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            return '';
        }

        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Transliterate Latin diacritics etc. to plain ASCII; anything that
        // can't be transliterated (Japanese, other CJK, symbols) is dropped
        // rather than left as raw bytes - iconv's own well-known, portable
        // behavior, not something this class implements itself.
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        if ($transliterated !== false) {
            $name = $transliterated;
        }

        $name = mb_strtolower($name, 'UTF-8');
        $name = preg_replace('/[^a-z0-9]+/u', '-', $name) ?? '';
        $name = trim($name, '-');

        if ($name === '') {
            return '';
        }

        if (mb_strlen($name, 'UTF-8') > self::MAX_LENGTH) {
            $name = mb_substr($name, 0, self::MAX_LENGTH, 'UTF-8');
            $name = rtrim($name, '-');
        }

        return $name;
    }
}
