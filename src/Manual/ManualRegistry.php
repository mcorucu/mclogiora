<?php
/**
 * Bundled mcLogiora manual registry.
 *
 * @package McLogiora
 */

namespace McLogiora\Manual;

defined( 'ABSPATH' ) || exit;

/**
 * The local, structured, offline-capable knowledge base.
 */
final class ManualRegistry {
	/**
	 * Cached article objects.
	 *
	 * @var ManualArticle[]|null
	 */
	private static $articles = null;

	/**
	 * Returns all bundled manual articles.
	 *
	 * @return ManualArticle[]
	 */
	public static function all() {
		if ( null !== self::$articles ) {
			return self::$articles;
		}

		self::$articles = array();

		foreach ( self::content() as $data ) {
			self::$articles[] = new ManualArticle( $data );
		}

		return self::$articles;
	}

	/**
	 * Finds an article by its safe slug.
	 *
	 * @param string $slug Article slug.
	 * @return ManualArticle|null
	 */
	public static function find( $slug ) {
		$slug = sanitize_key( $slug );

		foreach ( self::all() as $article ) {
			if ( $slug === $article->slug() ) {
				return $article;
			}
		}

		return null;
	}

	/**
	 * Returns category names in registry order.
	 *
	 * @return string[]
	 */
	public static function categories() {
		$categories = array();

		foreach ( self::all() as $article ) {
			if ( ! in_array( $article->category(), $categories, true ) ) {
				$categories[] = $article->category();
			}
		}

		return $categories;
	}

	/**
	 * Structured source content. Text remains source-controlled and is escaped
	 * by the renderer; no Markdown or arbitrary HTML is executed.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function content() {
		return array(
			array(
				'slug'             => 'quick-start',
				'title'            => __( 'Quick Start', 'mclogiora' ),
				'summary'          => __( 'Configure languages and create your first translation in a few focused steps.', 'mclogiora' ),
				'category'         => __( 'Start Here', 'mclogiora' ),
				'keywords'         => array( 'setup', 'first translation', 'getting started' ),
				'sections'         => array(
					array(
						'type'    => 'steps',
						'heading' => __( 'A practical first session', 'mclogiora' ),
						'items'   => array( __( 'Open Setup Wizard and choose the primary language of your existing content.', 'mclogiora' ), __( 'Add one or more translation languages. mcLogiora supplies the locale, URL tag, and direction for each catalog choice.', 'mclogiora' ), __( 'Review language URLs and finish setup.', 'mclogiora' ), __( 'Open Translation Manager, find a page, and use + Language to create a translated draft.', 'mclogiora' ) ),
					),
					array(
						'type'    => 'tip',
						'heading' => __( 'Good to know', 'mclogiora' ),
						'text'    => __( 'Choosing a language does not translate or publish content automatically. You remain in control of every translation draft.', 'mclogiora' ),
					),
				),
				'related_articles' => array( 'choosing-languages', 'first-translation', 'translation-manager' ),
			),
			array(
				'slug'             => 'what-is-mclogiora',
				'title'            => __( 'What is mcLogiora?', 'mclogiora' ),
				'summary'          => __( 'Understand the translation relationships, language-aware URLs, and review workflow mcLogiora provides.', 'mclogiora' ),
				'category'         => __( 'Start Here', 'mclogiora' ),
				'keywords'         => array( 'overview', 'multilingual', 'relationships' ),
				'sections'         => array(
					array(
						'type' => 'paragraph',
						'text' => __( 'mcLogiora connects separate WordPress objects that represent the same content in different languages. It adds language metadata, translation actions, multilingual URLs, a switcher, and review-oriented suggestion tools.', 'mclogiora' ),
					),
					array(
						'type' => 'paragraph',
						'text' => __( 'Existing eligible content can be discovered by Translation Manager. Discovery is not translation: mcLogiora does not duplicate, translate, or publish existing content without an explicit action.', 'mclogiora' ),
					),
				),
				'related_articles' => array( 'translation-manager', 'first-translation' ),
			),
			array(
				'slug'             => 'choosing-languages',
				'title'            => __( 'Choosing languages', 'mclogiora' ),
				'summary'          => __( 'Select a primary language and translation targets without entering locale codes.', 'mclogiora' ),
				'category'         => __( 'Languages', 'mclogiora' ),
				'keywords'         => array( 'primary', 'target', 'locale', 'code', 'tr_TR', 'en_US' ),
				'sections'         => array(
					array(
						'type'    => 'paragraph',
						'heading' => __( 'Primary language', 'mclogiora' ),
						'text'    => __( 'Choose the language most of your existing content is written in. mcLogiora uses it as the starting point for translation relationships. If it can confidently match your WordPress site language, the wizard shows that choice as a suggestion; you still confirm it before saving.', 'mclogiora' ),
					),
					array(
						'type'    => 'paragraph',
						'heading' => __( 'Translation languages', 'mclogiora' ),
						'text'    => __( 'Search by the native name, English name, locale, code, or region, then select one or more targets. The primary language is not offered as a target and duplicate locales are rejected.', 'mclogiora' ),
					),
					array(
						'type'    => 'tip',
						'heading' => __( 'Why does this matter?', 'mclogiora' ),
						'text'    => __( 'The catalog keeps technical values consistent for URLs, hreflang, OpenGraph metadata, and right-to-left presentation while you work with recognizable language names.', 'mclogiora' ),
					),
				),
				'related_articles' => array( 'language-details', 'rtl-languages', 'language-urls' ),
			),
			array(
				'slug'             => 'language-details',
				'title'            => __( 'Language codes and locales', 'mclogiora' ),
				'summary'          => __( 'A gentle explanation of the technical metadata mcLogiora resolves for you.', 'mclogiora' ),
				'category'         => __( 'Languages', 'mclogiora' ),
				'keywords'         => array( 'BCP-47', 'hreflang', 'locale', 'WordPress locale' ),
				'sections'         => array(
					array(
						'type' => 'paragraph',
						'text' => __( 'tr_TR is the WordPress locale for Turkish. You normally do not need to enter this yourself; mcLogiora selects it from the bundled catalog. The catalog also provides the BCP-47 form tr for language attributes and hreflang.', 'mclogiora' ),
					),
					array(
						'type'    => 'list',
						'heading' => __( 'What each value is for', 'mclogiora' ),
						'items'   => array( __( 'Language code: mcLogiora’s stable identifier and URL language key.', 'mclogiora' ), __( 'WordPress locale: the locale convention used by WordPress.', 'mclogiora' ), __( 'Hreflang: the standards-compatible language tag emitted for search engines.', 'mclogiora' ), __( 'Direction: ltr or rtl, selected from the catalog.', 'mclogiora' ) ),
					),
				),
				'related_articles' => array( 'choosing-languages', 'language-urls', 'seo' ),
			),
			array(
				'slug'             => 'adding-and-removing-languages',
				'title'            => __( 'Adding and removing languages', 'mclogiora' ),
				'summary'          => __( 'Manage catalog languages after setup and understand the safety checks around changes.', 'mclogiora' ),
				'category'         => __( 'Languages', 'mclogiora' ),
				'keywords'         => array( 'add language', 'remove', 'disable', 'default' ),
				'sections'         => array(
					array(
						'type'    => 'steps',
						'heading' => __( 'Add a standard language', 'mclogiora' ),
						'items'   => array( __( 'Open mcLogiora → Languages.', 'mclogiora' ), __( 'Use Add Language, search the catalog, and choose a language.', 'mclogiora' ), __( 'Review the name and direction, then select Add Language.', 'mclogiora' ) ),
					),
					array(
						'type' => 'paragraph',
						'text' => __( 'Disabling a language keeps its records but prevents new translation relationships. The default language cannot be disabled. Deleting a referenced language is blocked so existing relationships remain safe.', 'mclogiora' ),
					),
				),
				'related_articles' => array( 'choosing-languages', 'language-details' ),
			),
			array(
				'slug'             => 'rtl-languages',
				'title'            => __( 'Right-to-left languages', 'mclogiora' ),
				'summary'          => __( 'Arabic, Hebrew, Persian, and other RTL choices receive their direction metadata automatically.', 'mclogiora' ),
				'category'         => __( 'Languages', 'mclogiora' ),
				'keywords'         => array( 'RTL', 'Arabic', 'Hebrew', 'Persian', 'direction' ),
				'sections'         => array(
					array(
						'type' => 'paragraph',
						'text' => __( 'Select an RTL language from the catalog just like any other language. mcLogiora stores the direction with the language and exposes it to admin and front-end language context. You do not need to type rtl or maintain a separate direction setting.', 'mclogiora' ),
					),
					array(
						'type'    => 'tip',
						'heading' => __( 'Mixed language sites', 'mclogiora' ),
						'text'    => __( 'A site can use LTR and RTL languages together. The selected language determines the relevant direction; keep translations readable in the editor and preview each public URL.', 'mclogiora' ),
					),
				),
				'related_articles' => array( 'choosing-languages', 'language-urls' ),
			),
			array(
				'slug'             => 'first-translation',
				'title'            => __( 'Create your first translation', 'mclogiora' ),
				'summary'          => __( 'Use the real list-table workflow to create, edit, review, and publish a translated draft.', 'mclogiora' ),
				'category'         => __( 'Workflows', 'mclogiora' ),
				'keywords'         => array( '+ Language', 'draft', 'publish', 'source', 'missing' ),
				'sections'         => array(
					array(
						'type'    => 'steps',
						'heading' => __( 'From a page to a translated draft', 'mclogiora' ),
						'items'   => array( __( 'Open Translation Manager or the Pages list and find the source page.', 'mclogiora' ), __( 'In the Languages column, select + Language for the target you need.', 'mclogiora' ), __( 'mcLogiora creates a separate draft object for that language and opens it for editing.', 'mclogiora' ), __( 'Translate the content, review its status, and publish it when it is ready.', 'mclogiora' ) ),
					),
					array(
						'type' => 'paragraph',
						'text' => __( 'The source and target remain separate WordPress objects connected by a translation relationship. Unlinking a relationship does not delete either object.', 'mclogiora' ),
					),
				),
				'related_articles' => array( 'translation-manager', 'editors', 'language-urls' ),
			),
			array(
				'slug'             => 'translation-manager',
				'title'            => __( 'Understanding Translation Manager', 'mclogiora' ),
				'summary'          => __( 'Find eligible content, see missing translations, and act from one searchable inventory.', 'mclogiora' ),
				'category'         => __( 'Workflows', 'mclogiora' ),
				'keywords'         => array( 'inventory', 'filters', 'source language', 'status', 'missing translation' ),
				'sections'         => array(
					array(
						'type' => 'paragraph',
						'text' => __( 'Translation Manager is a read-first inventory of eligible posts, pages, public custom post types, and supported taxonomies. Objects may appear before a translation relationship exists.', 'mclogiora' ),
					),
					array(
						'type'    => 'list',
						'heading' => __( 'What you can inspect', 'mclogiora' ),
						'items'   => array( __( 'Search by title or object name.', 'mclogiora' ), __( 'Filter by kind, post type, taxonomy, and source language.', 'mclogiora' ), __( 'See existing target translations and missing targets.', 'mclogiora' ), __( 'Open a target draft or use + Language to create one.', 'mclogiora' ) ),
					),
				),
				'related_articles' => array( 'first-translation', 'choosing-languages', 'troubleshooting' ),
			),
			array(
				'slug'             => 'editors',
				'title'            => __( 'Editors and supported content', 'mclogiora' ),
				'summary'          => __( 'Where translation controls appear in Gutenberg, Classic Editor, and supported public content types.', 'mclogiora' ),
				'category'         => __( 'Workflows', 'mclogiora' ),
				'keywords'         => array( 'Gutenberg', 'Classic Editor', 'CPT', 'editor' ),
				'sections'         => array(
					array(
						'type' => 'paragraph',
						'text' => __( 'Gutenberg uses the mcLogiora translation panel. Classic Editor uses the translation metabox. The same relationship and status rules apply whichever editor you use.', 'mclogiora' ),
					),
					array(
						'type' => 'paragraph',
						'text' => __( 'Public custom post types are supported when WordPress exposes them as eligible content. Internal types and excluded WooCommerce objects follow the product compatibility rules shown by the Compatibility screen.', 'mclogiora' ),
					),
				),
				'related_articles' => array( 'first-translation', 'translation-manager' ),
			),
			array(
				'slug'             => 'taxonomies-media-strings',
				'title'            => __( 'Taxonomies, media, and strings', 'mclogiora' ),
				'summary'          => __( 'Learn which specialized translation surfaces are available and where to find them.', 'mclogiora' ),
				'category'         => __( 'Workflows', 'mclogiora' ),
				'keywords'         => array( 'categories', 'tags', 'media', 'strings', 'menus', 'widgets' ),
				'sections'         => array(
					array(
						'type'  => 'list',
						'items' => array( __( 'Categories, tags, and supported public taxonomies use the taxonomy translation workflow.', 'mclogiora' ), __( 'Media keeps one shared attachment while translated title, alt text, caption, and description are stored per language.', 'mclogiora' ), __( 'String Translation, Menus & Widgets provide their own focused admin screens for supported interface content.', 'mclogiora' ) ),
					),
					array(
						'type'    => 'tip',
						'heading' => __( 'Scope matters', 'mclogiora' ),
						'text'    => __( 'The available actions depend on the object type and current integration. The UI is the source of truth; mcLogiora does not claim support for content it cannot safely translate.', 'mclogiora' ),
					),
				),
				'related_articles' => array( 'translation-manager', 'troubleshooting' ),
			),
			array(
				'slug'             => 'language-urls',
				'title'            => __( 'Language URLs and switcher', 'mclogiora' ),
				'summary'          => __( 'Understand directory URLs, default-language behavior, missing translations, and the front-end switcher.', 'mclogiora' ),
				'category'         => __( 'URLs & SEO', 'mclogiora' ),
				'keywords'         => array( 'URL', 'permalink', 'directory', 'switcher', '404', 'prefix' ),
				'sections'         => array(
					array(
						'type' => 'paragraph',
						'text' => __( 'The directory strategy uses paths such as /de/about/. By default the primary language can keep existing root URLs; the setup choice can add its directory as well. The exact pattern is shown in Review and Languages & URLs.', 'mclogiora' ),
					),
					array(
						'type' => 'paragraph',
						'text' => __( 'The switcher only links to translations that exist. If a target is missing, it is not presented as a working URL. After changing permalink settings, refresh WordPress rewrite rules from Permalinks.', 'mclogiora' ),
					),
				),
				'related_articles' => array( 'choosing-languages', 'seo', 'troubleshooting' ),
			),
			array(
				'slug'             => 'seo',
				'title'            => __( 'SEO language metadata', 'mclogiora' ),
				'summary'          => __( 'See how canonical URLs, hreflang, x-default, sitemaps, and OpenGraph locale are handled.', 'mclogiora' ),
				'category'         => __( 'URLs & SEO', 'mclogiora' ),
				'keywords'         => array( 'SEO', 'canonical', 'hreflang', 'x-default', 'sitemap', 'OpenGraph' ),
				'sections'         => array(
					array(
						'type' => 'paragraph',
						'text' => __( 'mcLogiora emits metadata from the same translated URL and language context used by the front end. Hreflang annotations are emitted only for real translated URLs; x-default points to the default-language URL when one exists.', 'mclogiora' ),
					),
					array(
						'type' => 'paragraph',
						'text' => __( 'The plugin also exposes the current OpenGraph locale and alternates where a region is known. Compatible SEO plugins are detected so duplicate concerns can be avoided.', 'mclogiora' ),
					),
				),
				'related_articles' => array( 'language-urls', 'language-details', 'troubleshooting' ),
			),
			array(
				'slug'             => 'suggestions-and-providers',
				'title'            => __( 'Translation Suggestions and providers', 'mclogiora' ),
				'summary'          => __( 'Use optional Generate, Preview, Review, and Apply suggestions with your own provider credentials.', 'mclogiora' ),
				'category'         => __( 'Advanced', 'mclogiora' ),
				'keywords'         => array( 'suggestions', 'provider', 'OpenAI', 'Anthropic', 'Gemini', 'DeepL', 'API key' ),
				'sections'         => array(
					array(
						'type' => 'paragraph',
						'text' => __( 'Translation Suggestions are optional. Manual translation and the normal translation workflow work without any provider. When configured, the supported provider choices are OpenAI, Anthropic, Gemini, and DeepL.', 'mclogiora' ),
					),
					array(
						'type'    => 'steps',
						'heading' => __( 'Safe suggestion workflow', 'mclogiora' ),
						'items'   => array( __( 'Configure a provider and credentials that you control.', 'mclogiora' ), __( 'Generate a suggestion and preview it.', 'mclogiora' ), __( 'Review the proposed content and apply it deliberately.', 'mclogiora' ), __( 'Publish only through the normal WordPress workflow when ready.', 'mclogiora' ) ),
					),
					array(
						'type'    => 'tip',
						'heading' => __( 'Privacy', 'mclogiora' ),
						'text'    => __( 'There is no default telemetry. A provider request is made only when you explicitly use a configured suggestion action; the request may include the selected source content. Review each provider’s terms before sending content.', 'mclogiora' ),
					),
				),
				'related_articles' => array( 'privacy', 'first-translation', 'troubleshooting' ),
			),
			array(
				'slug'             => 'privacy',
				'title'            => __( 'Privacy and external requests', 'mclogiora' ),
				'summary'          => __( 'Know when mcLogiora communicates with an external translation provider.', 'mclogiora' ),
				'category'         => __( 'Advanced', 'mclogiora' ),
				'keywords'         => array( 'privacy', 'telemetry', 'network', 'credentials', 'provider' ),
				'sections'         => array(
					array(
						'type'  => 'list',
						'items' => array( __( 'mcLogiora does not enable telemetry or tracking by default.', 'mclogiora' ), __( 'Language setup, the local manual, and local manual search do not call translation providers.', 'mclogiora' ), __( 'Provider calls happen only after you configure credentials and explicitly request a suggestion.', 'mclogiora' ), __( 'Credentials remain under your WordPress configuration and should be handled as sensitive values.', 'mclogiora' ) ),
					),
				),
				'related_articles' => array( 'suggestions-and-providers', 'troubleshooting' ),
			),
			array(
				'slug'             => 'troubleshooting',
				'title'            => __( 'Troubleshooting', 'mclogiora' ),
				'summary'          => __( 'Resolve common setup, language, URL, translation, and suggestion states.', 'mclogiora' ),
				'category'         => __( 'Help', 'mclogiora' ),
				'keywords'         => array( 'missing', 'empty', 'unassigned', '404', 'credentials', 'disabled', 'review' ),
				'sections'         => array(
					array(
						'type'  => 'list',
						'items' => array( __( 'No languages configured: run Setup Wizard and confirm a primary language.', 'mclogiora' ), __( 'Translation Manager is empty: check that eligible post types or taxonomies exist and review the filters.', 'mclogiora' ), __( 'Content shows Unassigned: assign its source language through the supported content workflow before creating a target.', 'mclogiora' ), __( 'I cannot see + English: confirm English is active, the object is eligible, and a translation does not already exist.', 'mclogiora' ), __( 'A language URL returns 404: save Permalinks again and confirm the translated object is published.', 'mclogiora' ), __( 'Suggestions are disabled: manual translation remains available; configure a provider only if you need suggestions.', 'mclogiora' ), __( 'A translation needs review/update: inspect the source-change status and review the target before publishing.', 'mclogiora' ) ),
					),
				),
				'related_articles' => array( 'translation-manager', 'language-urls', 'suggestions-and-providers', 'system-status' ),
			),
			array(
				'slug'             => 'faq',
				'title'            => __( 'Frequently asked questions', 'mclogiora' ),
				'summary'          => __( 'Short answers to common multilingual workflow questions.', 'mclogiora' ),
				'category'         => __( 'Help', 'mclogiora' ),
				'keywords'         => array( 'FAQ', 'automatic', 'WooCommerce', 'unlink', 'publish' ),
				'sections'         => array(
					array(
						'type'  => 'list',
						'items' => array( __( 'Does mcLogiora translate automatically? No. Suggestions are optional and always review-first.', 'mclogiora' ), __( 'Does creating a language duplicate content? No. It only makes a language available.', 'mclogiora' ), __( 'Can I translate manually? Yes. The normal editor and translation workflow do not require a provider.', 'mclogiora' ), __( 'Can I add languages later? Yes, from Languages.', 'mclogiora' ), __( 'Does unlinking delete content? No. The relationship is removed while the WordPress objects remain.', 'mclogiora' ), __( 'Can I use mcLogiora without providers? Yes.', 'mclogiora' ), __( 'Does it publish translations automatically? No.', 'mclogiora' ), __( 'What about WooCommerce? Use the Compatibility screen and current UI policy; mcLogiora does not claim unsupported WooCommerce objects are translatable.', 'mclogiora' ) ),
					),
				),
				'related_articles' => array( 'first-translation', 'suggestions-and-providers', 'troubleshooting' ),
			),
			array(
				'slug'             => 'categories-and-tags',
				'title'            => __( 'Categories and tags', 'mclogiora' ),
				'summary'          => __( 'Translate supported public taxonomies while keeping term relationships safe.', 'mclogiora' ),
				'category'         => __( 'Workflows', 'mclogiora' ),
				'keywords'         => array( 'taxonomy', 'category', 'tag', 'term' ),
				'sections'         => array(
					array(
						'type'  => 'steps',
						'items' => array( __( 'Open Translation Manager and filter the kind to Taxonomies.', 'mclogiora' ), __( 'Find the source category or tag and choose the missing target language.', 'mclogiora' ), __( 'Review the translated term and its parent relationship before saving.', 'mclogiora' ) ),
					),
					array(
						'type' => 'paragraph',
						'text' => __( 'The workflow avoids attaching a translated term to a parent in another language. Unsupported or internal taxonomies remain excluded.', 'mclogiora' ),
					),
				),
				'related_articles' => array( 'translation-manager', 'first-translation' ),
			),
			array(
				'slug'             => 'media-translation',
				'title'            => __( 'Media translation', 'mclogiora' ),
				'summary'          => __( 'Translate attachment metadata without duplicating the underlying media file.', 'mclogiora' ),
				'category'         => __( 'Workflows', 'mclogiora' ),
				'keywords'         => array( 'media', 'attachment', 'alt text', 'caption' ),
				'sections'         => array(
					array(
						'type' => 'paragraph',
						'text' => __( 'mcLogiora keeps one shared attachment and stores translated title, alt text, caption, and description per language. The binary and URL remain shared.', 'mclogiora' ),
					),
					array(
						'type'    => 'tip',
						'heading' => __( 'Accessibility', 'mclogiora' ),
						'text'    => __( 'Translate alt text as carefully as visible copy. It is part of the language-specific media experience.', 'mclogiora' ),
					),
				),
				'related_articles' => array( 'taxonomies-media-strings', 'troubleshooting' ),
			),
			array(
				'slug'             => 'string-translation',
				'title'            => __( 'String Translation', 'mclogiora' ),
				'summary'          => __( 'Review interface strings discovered from your theme and plugins.', 'mclogiora' ),
				'category'         => __( 'Workflows', 'mclogiora' ),
				'keywords'         => array( 'strings', 'interface', 'theme', 'plugin' ),
				'sections'         => array(
					array(
						'type' => 'paragraph',
						'text' => __( 'String Translation is for supported interface text rather than post content. Scan only when you ask; normal front-end traffic does not trigger a scan.', 'mclogiora' ),
					),
					array(
						'type' => 'paragraph',
						'text' => __( 'Review source context and target language before saving. Provider suggestions are optional and are not required for manual string translation.', 'mclogiora' ),
					),
				),
				'related_articles' => array( 'suggestions-and-providers', 'privacy' ),
			),
			array(
				'slug'             => 'menus-and-widgets',
				'title'            => __( 'Menus and widgets', 'mclogiora' ),
				'summary'          => __( 'Understand the supported navigation and widget translation surfaces.', 'mclogiora' ),
				'category'         => __( 'Workflows', 'mclogiora' ),
				'keywords'         => array( 'menus', 'widgets', 'navigation' ),
				'sections'         => array(
					array(
						'type' => 'paragraph',
						'text' => __( 'Menus & Widgets provides focused actions for supported navigation menus and widget text fields. The front end uses the configured WordPress menu and widget settings.', 'mclogiora' ),
					),
					array(
						'type' => 'paragraph',
						'text' => __( 'If a widget type has no translation adapter, it remains unchanged and is shown as unsupported rather than being guessed at.', 'mclogiora' ),
					),
				),
				'related_articles' => array( 'taxonomies-media-strings', 'troubleshooting' ),
			),
			array(
				'slug'             => 'system-status',
				'title'            => __( 'System Status and diagnostics', 'mclogiora' ),
				'summary'          => __( 'Use read-only diagnostics to understand database, language, routing, and compatibility state.', 'mclogiora' ),
				'category'         => __( 'Advanced', 'mclogiora' ),
				'keywords'         => array( 'diagnostics', 'status', 'health', 'database', 'compatibility' ),
				'sections'         => array(
					array(
						'type' => 'paragraph',
						'text' => __( 'System Status reports configuration and capability checks without changing site data. Use an actionable Settings link first when a finding identifies missing language or routing configuration.', 'mclogiora' ),
					),
					array(
						'type' => 'paragraph',
						'text' => __( 'If a result is unclear, capture the status details and compare the active language records, default language, permalink settings, and current WordPress environment.', 'mclogiora' ),
					),
				),
				'related_articles' => array( 'troubleshooting', 'choosing-languages' ),
			),
			array(
				'slug'             => 'developer-guide',
				'title'            => __( 'Developer Guide', 'mclogiora' ),
				'summary'          => __( 'Verified extension points and interfaces for developers integrating with mcLogiora.', 'mclogiora' ),
				'category'         => __( 'Developers', 'mclogiora' ),
				'keywords'         => array( 'API', 'hooks', 'REST', 'WP-CLI', 'Import/Export', 'catalog filter' ),
				'sections'         => array(
					array(
						'type'  => 'list',
						'items' => array( __( 'PHP Public API: read configured languages, current/default language, translations, URLs, and the switcher.', 'mclogiora' ), __( 'Hooks: use documented mclogiora_* filters and actions; the language catalog extension point is mclogiora_language_catalog.', 'mclogiora' ), __( 'REST API and WP-CLI expose the configured language and translation contracts described in the Developer API document.', 'mclogiora' ), __( 'Import/Export carries canonical language fields and relation records; validate packages before applying them.', 'mclogiora' ), __( 'Diagnostics is read-only and suitable for support evidence.', 'mclogiora' ) ),
					),
					array(
						'type'    => 'tip',
						'heading' => __( 'Catalog extensions', 'mclogiora' ),
						'text'    => __( 'Use the mclogiora_language_catalog filter to add or adjust definitions. Return code, locale, hreflang, native_name, english_name, direction, and optional region; invalid or duplicate entries are ignored.', 'mclogiora' ),
					),
				),
				'related_articles' => array( 'language-details', 'system-status' ),
			),
		);
	}
}
