<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Attribute;

use Cosmotec\EccubeMigration\Model\UrlKey\UrlKeySlugifier;

/**
 * Deterministic Magento attribute-code naming for EC-CUBE specifications:
 *
 *   ecs_{normalized_english_name}_{eccube_specification_id}
 *
 * The `ecs_` prefix stands for "EC-CUBE Specification". The trailing
 * `dtb_specification.id` is the PERMANENT synchronization identity - it is
 * what SpecificationMap and every importer actually key off internally
 * (see SpecificationMap::getEccubeSpecificationId()). The normalized name
 * is purely a human-readability aid; it is never treated as an identity by
 * any caller, and a later EC-CUBE name edit must never cause a caller to
 * rename an already-created attribute (see AttributeImporter::persist(),
 * which reads the STORED map row's attribute_code, not this resolver,
 * once a specification has been imported once).
 *
 * Reuses UrlKeySlugifier (no new transliteration behavior) for the same
 * reasons already established for category/product URL keys: pure PHP, no
 * Magento config dependency, portable to a fresh install, deterministic
 * Japanese-only-name handling (produces '' rather than mangled bytes).
 * Its hyphen-separated output is converted to underscores here because
 * Magento's attribute-code validator (Magento\Eav\Model\Validator\Attribute\Code)
 * only allows [a-zA-Z0-9_] and requires a leading letter - confirmed by
 * reading that class this round.
 */
class SpecificationAttributeCodeResolver
{
    /**
     * Magento\Eav\Model\Entity\Attribute::ATTRIBUTE_CODE_MAX_LENGTH.
     * Duplicated as a literal rather than importing the EAV module class
     * so this resolver stays usable from the plain, DI-free Specification
     * DTO without pulling in Magento dependencies.
     */
    private const ATTRIBUTE_CODE_MAX_LENGTH = 60;

    /**
     * Public so callers that need to recognize (not generate) an EC-CUBE
     * specification attribute code - e.g. the "clear removed specification
     * values" sweep in ItemAttributeValueImporter/ProductAttributeValueImporter -
     * can match against it without duplicating the literal.
     */
    public const PREFIX = 'ecs_';

    private const FALLBACK_PREFIX = 'ecs_spec_';

    private readonly UrlKeySlugifier $slugifier;

    /**
     * No default value on the constructor parameter deliberately - a
     * `new X()` default expression breaks Magento's compiled DI config
     * generation (`bin/magento setup:di:compile` writes constructor
     * argument metadata via var_export(), which cannot serialize an object
     * expression; confirmed by hitting the resulting
     * "Call to undefined method UrlKeySlugifier::__set_state()" fatal this
     * round). Callers that build this class with `new` outside of DI
     * (e.g. the plain Specification DTO) simply pass `new UrlKeySlugifier()`
     * explicitly at the call site instead.
     */
    public function __construct(?UrlKeySlugifier $slugifier = null)
    {
        $this->slugifier = $slugifier ?? new UrlKeySlugifier();
    }

    public function resolve(int $specificationId, string $nameEn): string
    {
        $fragment = str_replace('-', '_', $this->slugifier->slugify($nameEn));

        if ($fragment === '') {
            return self::FALLBACK_PREFIX . $specificationId;
        }

        $suffix = '_' . $specificationId;
        $available = self::ATTRIBUTE_CODE_MAX_LENGTH - strlen(self::PREFIX) - strlen($suffix);

        if ($available <= 0) {
            return self::FALLBACK_PREFIX . $specificationId;
        }

        if (strlen($fragment) > $available) {
            $fragment = rtrim(substr($fragment, 0, $available), '_');
        }

        if ($fragment === '') {
            return self::FALLBACK_PREFIX . $specificationId;
        }

        return self::PREFIX . $fragment . $suffix;
    }
}
