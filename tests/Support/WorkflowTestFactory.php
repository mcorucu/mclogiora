<?php
/**
 * Builds wired workflow objects for tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Content\ContentTypeRegistryInterface;
use McLogiora\Content\TranslatableContentType;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\TranslationRelationService;
use McLogiora\Relations\MetadataNeedsUpdateDetector;
use McLogiora\Taxonomies\TaxonomyRegistryInterface;
use McLogiora\Taxonomies\TranslatableTaxonomy;
use McLogiora\Workflows\ContentTranslationWorkflow;
use McLogiora\Workflows\TaxonomyTranslationWorkflow;
use McLogiora\Workflows\TranslationStatusTransitions;
use McLogiora\Workflows\TranslationWorkflowService;
use McLogiora\Workflows\TranslationWorkflowValidator;

/**
 * Assembles the real workflow classes over in-memory doubles.
 *
 * Tests exercise production wiring; only the WordPress and database edges are
 * replaced.
 */
final class WorkflowTestFactory {
	/**
	 * Content gateway.
	 *
	 * @var FakeContentGateway
	 */
	public $gateway;

	/**
	 * Relation repository.
	 *
	 * @var FakeRelationRepository
	 */
	public $repository;

	/**
	 * Language service.
	 *
	 * @var LanguageServiceInterface
	 */
	public $languages;

	/**
	 * Workflow service.
	 *
	 * @var TranslationWorkflowService
	 */
	public $workflows;

	/**
	 * Content workflow.
	 *
	 * @var ContentTranslationWorkflow
	 */
	public $content;

	/**
	 * Taxonomy workflow.
	 *
	 * @var TaxonomyTranslationWorkflow
	 */
	public $taxonomy;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->gateway    = new FakeContentGateway();
		$this->repository = new FakeRelationRepository();
		$this->languages  = new FakeLanguageService(
			array(
				new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, true ),
				new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ),
				new Language( 'fr', 'fr_FR', 'Francais', 'French', 'ltr', LanguageStatus::ACTIVE, 2, false ),
				new Language( 'de', 'de_DE', 'Deutsch', 'German', 'ltr', LanguageStatus::INACTIVE, 3, false ),
			)
		);

		$relation_service = new TranslationRelationService(
			$this->repository,
			new MetadataNeedsUpdateDetector(),
			$this->languages
		);

		$validator = new TranslationWorkflowValidator(
			$this->gateway,
			$this->languages,
			$this->repository,
			$this->content_types(),
			$this->taxonomies(),
			new CapabilityRegistry()
		);

		$transitions = new TranslationStatusTransitions();

		$this->content = new ContentTranslationWorkflow(
			$this->gateway,
			$relation_service,
			$this->languages,
			$validator
		);

		$this->taxonomy = new TaxonomyTranslationWorkflow(
			$this->gateway,
			$relation_service,
			$this->languages,
			$validator
		);

		$this->workflows = new TranslationWorkflowService(
			$this->content,
			$this->taxonomy,
			$transitions,
			$this->repository,
			$relation_service,
			$validator
		);
	}

	/**
	 * Returns a content type registry allowing post and page only.
	 *
	 * @return ContentTypeRegistryInterface
	 */
	private function content_types() {
		return new FakeContentTypeRegistry(
			array(
				new TranslatableContentType( 'post', 'Posts', true, true, true ),
				new TranslatableContentType( 'page', 'Pages', true, true, true ),
			),
			array(
				new TranslatableContentType( 'product', 'Products', true, false, false, 'WooCommerce content is planned for a future free compatibility module.' ),
			)
		);
	}

	/**
	 * Returns a taxonomy registry allowing category and post_tag only.
	 *
	 * @return TaxonomyRegistryInterface
	 */
	private function taxonomies() {
		return new FakeTaxonomyRegistry(
			array(
				new TranslatableTaxonomy( 'category', 'Categories', true, true, true ),
				new TranslatableTaxonomy( 'post_tag', 'Tags', true, true, true ),
			),
			array(
				new TranslatableTaxonomy( 'product_cat', 'Product categories', true, false, false, 'WooCommerce taxonomies are planned for a future free compatibility module.' ),
			)
		);
	}
}
