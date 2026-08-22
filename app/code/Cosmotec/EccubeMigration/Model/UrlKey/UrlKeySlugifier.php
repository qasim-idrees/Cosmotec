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
 * not `Magento\Catalog\Model\Product\Url::formatUrlKey()` (config-gated
 * transliteration; live-confirmed this project's own environment actually
 * has `catalog/seo/product_url_transliteration` = 1, Magento's own real
 * config.xml default, not a store-specific override) or
 * `Magento\Framework\Filter\Translit` directly - both drop Japanese to an
 * empty string via their shared iconv `ascii//ignore//translit` fallback
 * (zero Japanese entries in the conversion table), which for Japanese-only
 * text is exactly the "empty/unusable result" case a caller must detect
 * and give a deterministic fallback for.
 *
 * Currently used only by SpecificationAttributeCodeResolver, for EC-CUBE
 * specification attribute-code normalization - NOT for url_key generation,
 * which is deliberately left entirely to Magento's own native mechanism
 * (see CategoryImporter/ItemImporter/ProductImporter).
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
