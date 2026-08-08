<?php
/**
 * Translation Manager module.
 *
 * @package McLogiora
 */

namespace McLogiora\Relations;

use McLogiora\Admin\AdminScreen;
use McLogiora\Admin\AdminScreenRegistry;
use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Content\ContentTranslationServiceInterface;
use McLogiora\Content\TranslatableContentType;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Taxonomies\TaxonomyTranslationServiceInterface;
use McLogiora\Taxonomies\TranslatableTaxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Translation Manager screen.
 */
final class TranslationManager implements ModuleInterface {
	/**
	 * Relation service.
	 *
	 * @var TranslationRelationServiceInterface|null
	 */
	private $relation_service = null;

	/**
	 * Language service.
	 *
	 * @var LanguageServiceInterface|null
	 */
	private $language_service = null;

	/**
	 * Content translation foundation service.
	 *
	 * @var ContentTranslationServiceInterface|null
	 */
	private $content_service = null;

	/**
	 * Taxonomy translation foundation service.
	 *
	 * @var TaxonomyTranslationServiceInterface|null
	 */
	private $taxonomy_service = null;

	/**
	 * Effective admin capability.
	 *
	 * @var string
	 */
	private $capability = 'manage_options';

	/**
	 * Registers the Translation Manager screen.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$capabilities           = $container->get( CapabilityRegistry::class );
		$this->capability      = $capabilities->resolve( CapabilityRegistry::MANAGE_TRANSLATIONS );
		$this->relation_service = $container->get( TranslationRelationServiceInterface::class );
		$this->language_service = $container->get( LanguageServiceInterface::class );
		$this->content_service  = $container->get( ContentTranslationServiceInterface::class );
		$this->taxonomy_service = $container->get( TaxonomyTranslationServiceInterface::class );

		$registry = $container->get( AdminScreenRegistry::class );
		$registry->add(
			new AdminScreen(
				__( 'mcLogiora Translation Manager', 'mclogiora' ),
				__( 'Translation Manager', 'mclogiora' ),
				$this->capability,
				'mclogiora-translation-manager',
				array( $this, 'render' )
			)
		);
	}

	/**
	 * Renders the Translation Manager.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mclogiora' ) );
		}

		$groups    = $this->relation_service instanceof TranslationRelationServiceInterface ? $this->relation_service->get_placeholder_groups() : array();
		$languages = $this->language_service instanceof LanguageServiceInterface ? $this->language_service->get_active_languages() : array();
		$codes     = $this->language_codes( $languages );
		$content_types = $this->content_service instanceof ContentTranslationServiceInterface ? $this->content_service->get_translatable_content_types() : array();
		$taxonomies    = $this->taxonomy_service instanceof TaxonomyTranslationServiceInterface ? $this->taxonomy_service->get_translatable_taxonomies() : array();
		$excluded_types = $this->content_service instanceof ContentTranslationServiceInterface ? $this->content_service->get_excluded_content_types() : array();
		$excluded_taxonomies = $this->taxonomy_service instanceof TaxonomyTranslationServiceInterface ? $this->taxonomy_service->get_excluded_taxonomies() : array();

		?>
		<div class="wrap mclogiora-admin">
			<section class="mclogiora-panel" aria-labelledby="mclogiora-translation-manager-title">
				<p class="mclogiora-eyebrow"><?php esc_html_e( 'Translation Relations', 'mclogiora' ); ?></p>
				<h1 id="mclogiora-translation-manager-title"><?php esc_html_e( 'Translation Manager', 'mclogiora' ); ?></h1>
				<p class="mclogiora-lede"><?php esc_html_e( 'Review database-backed translation relation records. This screen does not create or change WordPress content.', 'mclogiora' ); ?></p>

				<div class="mclogiora-filter-bar" aria-label="<?php esc_attr_e( 'Translation manager filters', 'mclogiora' ); ?>">
					<label>
						<span><?php esc_html_e( 'Content type', 'mclogiora' ); ?></span>
						<select disabled>
							<option><?php esc_html_e( 'All content types', 'mclogiora' ); ?></option>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( 'Taxonomy', 'mclogiora' ); ?></span>
						<select disabled>
							<option><?php esc_html_e( 'All taxonomies', 'mclogiora' ); ?></option>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( 'Source language', 'mclogiora' ); ?></span>
						<select disabled>
							<option><?php esc_html_e( 'Any source', 'mclogiora' ); ?></option>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( 'Target language', 'mclogiora' ); ?></span>
						<select disabled>
							<option><?php esc_html_e( 'Any target', 'mclogiora' ); ?></option>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( 'Status', 'mclogiora' ); ?></span>
						<select disabled>
							<option><?php esc_html_e( 'Any status', 'mclogiora' ); ?></option>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( 'Search', 'mclogiora' ); ?></span>
						<input type="search" placeholder="<?php esc_attr_e( 'Search relations later', 'mclogiora' ); ?>" disabled>
					</label>
				</div>

				<div class="mclogiora-status-card mclogiora-status-card--notice">
					<span class="mclogiora-status-card__icon" aria-hidden="true">i</span>
					<div>
						<h2><?php esc_html_e( 'Excluded Integrations', 'mclogiora' ); ?></h2>
						<p><?php esc_html_e( 'WooCommerce and LMS support will be handled by future premium add-ons. This free foundation focuses on posts, pages, public custom post types, categories, tags, and public custom taxonomies.', 'mclogiora' ); ?></p>
					</div>
				</div>

				<div class="mclogiora-card-grid mclogiora-card-grid--two">
					<?php $this->render_content_support_card( $content_types, $excluded_types ); ?>
					<?php $this->render_taxonomy_support_card( $taxonomies, $excluded_taxonomies ); ?>
				</div>

				<div class="mclogiora-card-grid mclogiora-card-grid--two">
					<article class="mclogiora-info-card">
						<h2><?php esc_html_e( 'Relation Groups', 'mclogiora' ); ?></h2>
						<p class="mclogiora-card-value"><?php echo esc_html( (string) count( $groups ) ); ?></p>
						<p><?php esc_html_e( 'Active plugin-owned relation groups currently available to the manager.', 'mclogiora' ); ?></p>
					</article>
					<article class="mclogiora-info-card">
						<h2><?php esc_html_e( 'Active Languages', 'mclogiora' ); ?></h2>
						<p class="mclogiora-card-value"><?php echo esc_html( (string) count( $codes ) ); ?></p>
						<p><?php esc_html_e( 'Used to calculate missing languages for each relation group.', 'mclogiora' ); ?></p>
					</article>
				</div>

				<div class="mclogiora-table-card">
					<h2><?php esc_html_e( 'Translation Status Table', 'mclogiora' ); ?></h2>
					<p><?php esc_html_e( 'Relation records are read from the persistence layer. Actions remain placeholders until reviewed admin write flows are introduced.', 'mclogiora' ); ?></p>
					<div class="mclogiora-table-scroll">
						<table class="widefat striped mclogiora-language-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Group', 'mclogiora' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Content Type', 'mclogiora' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Source', 'mclogiora' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Targets', 'mclogiora' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Missing Languages', 'mclogiora' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Outdated', 'mclogiora' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Future Actions', 'mclogiora' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php if ( empty( $groups ) ) : ?>
									<tr>
										<td colspan="7"><?php esc_html_e( 'No translation relation groups have been created yet.', 'mclogiora' ); ?></td>
									</tr>
								<?php else : ?>
									<?php foreach ( $groups as $group ) : ?>
										<?php $this->render_group_row( $group, $codes ); ?>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders content support overview.
	 *
	 * @param TranslatableContentType[] $content_types Translatable content types.
	 * @param TranslatableContentType[] $excluded_types Excluded content types.
	 * @return void
	 */
	private function render_content_support_card( array $content_types, array $excluded_types ) {
		?>
		<article class="mclogiora-info-card">
			<h2><?php esc_html_e( 'Post/Page/CPT Support Overview', 'mclogiora' ); ?></h2>
			<p><?php esc_html_e( 'These content types are prepared for future translation workflows.', 'mclogiora' ); ?></p>
			<ul class="mclogiora-inline-list">
				<?php foreach ( $content_types as $type ) : ?>
					<?php if ( $type instanceof TranslatableContentType ) : ?>
						<li><?php echo esc_html( $type->label() . ' (' . $type->name() . ')' ); ?></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! empty( $excluded_types ) ) : ?>
				<p class="mclogiora-muted-line"><?php echo esc_html( sprintf( _n( '%d excluded content type detected.', '%d excluded content types detected.', count( $excluded_types ), 'mclogiora' ), count( $excluded_types ) ) ); ?></p>
			<?php endif; ?>
		</article>
		<?php
	}

	/**
	 * Renders taxonomy support overview.
	 *
	 * @param TranslatableTaxonomy[] $taxonomies Translatable taxonomies.
	 * @param TranslatableTaxonomy[] $excluded_taxonomies Excluded taxonomies.
	 * @return void
	 */
	private function render_taxonomy_support_card( array $taxonomies, array $excluded_taxonomies ) {
		?>
		<article class="mclogiora-info-card">
			<h2><?php esc_html_e( 'Taxonomy Support Overview', 'mclogiora' ); ?></h2>
			<p><?php esc_html_e( 'These taxonomies are prepared for future translation workflows.', 'mclogiora' ); ?></p>
			<ul class="mclogiora-inline-list">
				<?php foreach ( $taxonomies as $taxonomy ) : ?>
					<?php if ( $taxonomy instanceof TranslatableTaxonomy ) : ?>
						<li><?php echo esc_html( $taxonomy->label() . ' (' . $taxonomy->name() . ')' ); ?></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! empty( $excluded_taxonomies ) ) : ?>
				<p class="mclogiora-muted-line"><?php echo esc_html( sprintf( _n( '%d excluded taxonomy detected.', '%d excluded taxonomies detected.', count( $excluded_taxonomies ), 'mclogiora' ), count( $excluded_taxonomies ) ) ); ?></p>
			<?php endif; ?>
		</article>
		<?php
	}

	/**
	 * Renders a relation group table row.
	 *
	 * @param TranslationGroup $group Translation group.
	 * @param string[]         $language_codes Active language codes.
	 * @return void
	 */
	private function render_group_row( TranslationGroup $group, array $language_codes ) {
		$original  = $group->original();
		$targets   = $group->targets();
		$missing   = $this->relation_service instanceof TranslationRelationServiceInterface ? $this->relation_service->determine_missing_languages_placeholder( $group, $language_codes ) : array();
		$outdated  = $this->relation_service instanceof TranslationRelationServiceInterface ? $this->relation_service->determine_outdated_translations_placeholder( $group ) : array();
		$type      = $original instanceof TranslationItem ? $original->content_type() : __( 'Unknown', 'mclogiora' );
		$source    = $original instanceof TranslationItem ? $original->language_code() . ':' . $original->object_key() : __( 'No source', 'mclogiora' );

		?>
		<tr>
			<td><code><?php echo esc_html( $group->group_key() ); ?></code></td>
			<td><?php echo esc_html( ucfirst( $type ) ); ?></td>
			<td><?php echo esc_html( $source ); ?></td>
			<td><?php $this->render_item_pills( $targets ); ?></td>
			<td><?php $this->render_code_pills( $missing, __( 'None', 'mclogiora' ) ); ?></td>
			<td><?php $this->render_item_pills( $outdated, __( 'None', 'mclogiora' ) ); ?></td>
			<td>
				<div class="mclogiora-action-row" aria-label="<?php esc_attr_e( 'Future translation actions', 'mclogiora' ); ?>">
					<button type="button" class="button" disabled><?php esc_html_e( 'Attach Existing Object', 'mclogiora' ); ?></button>
					<button type="button" class="button" disabled><?php esc_html_e( 'Mark Translated', 'mclogiora' ); ?></button>
					<button type="button" class="button" disabled><?php esc_html_e( 'Mark Reviewed', 'mclogiora' ); ?></button>
					<button type="button" class="button" disabled><?php esc_html_e( 'Archive Group', 'mclogiora' ); ?></button>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders item status pills.
	 *
	 * @param TranslationItem[] $items Translation items.
	 * @param string           $empty Empty label.
	 * @return void
	 */
	private function render_item_pills( array $items, $empty = '' ) {
		if ( empty( $items ) ) {
			echo '<span class="mclogiora-pill">' . esc_html( $empty ) . '</span>';
			return;
		}

		foreach ( $items as $item ) {
			$class = TranslationStatus::NEEDS_UPDATE === $item->status() ? 'mclogiora-pill mclogiora-pill--warning' : 'mclogiora-pill mclogiora-pill--active';
			echo '<span class="' . esc_attr( $class ) . '">' . esc_html( $item->language_code() . ' / ' . $item->status() ) . '</span> ';
		}
	}

	/**
	 * Renders language code pills.
	 *
	 * @param string[] $codes Language codes.
	 * @param string   $empty Empty label.
	 * @return void
	 */
	private function render_code_pills( array $codes, $empty ) {
		if ( empty( $codes ) ) {
			echo '<span class="mclogiora-pill mclogiora-pill--active">' . esc_html( $empty ) . '</span>';
			return;
		}

		foreach ( $codes as $code ) {
			echo '<span class="mclogiora-pill">' . esc_html( $code ) . '</span> ';
		}
	}

	/**
	 * Returns language codes from language objects.
	 *
	 * @param Language[] $languages Languages.
	 * @return string[]
	 */
	private function language_codes( array $languages ) {
		$codes = array();

		foreach ( $languages as $language ) {
			if ( $language instanceof Language ) {
				$codes[] = $language->code();
			}
		}

		return $codes;
	}
}
