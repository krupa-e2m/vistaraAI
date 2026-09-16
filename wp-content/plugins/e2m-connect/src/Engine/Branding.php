<?php
/**
 * Rebrands all visible "E2M Connect"/"E2M …" text coming from the vendored
 * engine to "E2M Connect", without editing hundreds of source strings.
 *
 * Every engine string uses the `e2mconnect` text domain, so we intercept its
 * translations (gettext / context / plural) and string-replace the brand.
 *
 * @package E2M\Connect
 */

namespace E2M\Connect\Engine;

class Branding {

    const DOMAIN = 'e2mconnect';

    public static function init(): void {
        add_filter('gettext', [self::class, 'filter'], 20, 3);
        add_filter('gettext_with_context', [self::class, 'filterCtx'], 20, 4);
        add_filter('ngettext', [self::class, 'filterN'], 20, 5);
    }

    public static function filter($translation, $text, $domain) {
        return $domain === self::DOMAIN ? self::rebrand($translation) : $translation;
    }

    public static function filterCtx($translation, $text, $context, $domain) {
        return $domain === self::DOMAIN ? self::rebrand($translation) : $translation;
    }

    public static function filterN($translation, $single, $plural, $number, $domain) {
        return $domain === self::DOMAIN ? self::rebrand($translation) : $translation;
    }

    private static function rebrand(string $s): string {
        if (stripos($s, 'e2m') === false) {
            return $s;
        }
        // Order matters: most specific first.
        $s = str_ireplace(['E2M Connect MCP', 'E2M Connect', 'E2M Connect', 'E2M AI', 'E2M MCP'], 'E2M Connect', $s);
        // Brand-prefixed labels like "E2M Content", "E2M Elementor".
        $s = preg_replace('/\bE2M\s+/u', 'E2M ', $s);
        // Any remaining standalone "E2M".
        $s = preg_replace('/\bE2M\b/u', 'E2M Connect', $s);
        return $s;
    }
}
