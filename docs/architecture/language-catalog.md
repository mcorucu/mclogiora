# Language catalog

mcLogiora stores language configuration in the existing
wp_mclogiora_languages table using the existing Language value object. The
bundled LanguageCatalog is a read-only selection layer for setup and the
Languages screen; it does not create a second language identity store.

Each catalog definition supplies:

- an internal code used by mcLogiora;
- a WordPress locale such as tr_TR or en_US;
- a BCP-47 tag for lang and hreflang;
- native and English display names;
- ltr or rtl direction metadata;
- an optional region label for locale variants.

The baseline is version-controlled and offline. It is normalized from common
ISO language, BCP-47, and WordPress locale conventions. A catalog choice is
converted to the existing persistence fields before LanguageService and the
repository validate and save it. SEO continues to derive tags through
LanguageTag, so persisted records remain backward-compatible.

Extensions may add or adjust definitions through the
mclogiora_language_catalog filter:

    add_filter(
        'mclogiora_language_catalog',
        static function ( $definitions ) {
            $definitions[] = array(
                'code'         => 'xx',
                'locale'       => 'xx_XX',
                'hreflang'     => 'xx-XX',
                'native_name'  => 'Example',
                'english_name' => 'Example',
                'direction'    => 'ltr',
                'region'       => 'Example region',
            );

            return $definitions;
        }
    );

The catalog validates non-empty names, locale structure, BCP-47 shape,
direction, and duplicate code/locale identities before any UI or persistence
path receives the result. The filter is a configuration extension point, not a
remote service and not a telemetry hook.
