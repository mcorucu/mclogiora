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
use McLogiora\Content\ContentInventoryService;
use McLogiora\Content\TranslatableContentType;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Taxonomies\TaxonomyTranslationServiceInterface;
use McLogiora\Taxonomies\TranslatableTaxonomy;
use McLogiora\Admin\SuggestionAdminState;
use McLogiora\Admin\TranslationActionController;
use McLogiora\Suggestions\SuggestionSurface;
use McLogiora\Workflows\TranslationStatusTransitions;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Translation Manager screen.
 */
final class TranslationManager implements ModuleInterface {
	const SUGGESTIONS_HANDLE = 'mclogiora-admin-suggestions';
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
	 * Read-only content and taxonomy inventory.
	 *
	 * @var ContentInventoryService|null
	 */
	private $inventory = null;

	/**
	 * Effective admin capability.
	 *
	 * @var string
	 */
	private $capability = 'manage_options';

	/**
	 * Status transition policy.
	 *
	 * @var TranslationStatusTransitions|null
	 */
	private $transitions = null;

	/**
	 * Suggestion state provider.
	 *
	 * @var SuggestionAdminState|null
	 */
	private $suggestions = null;

	/**
	 * Registers the Translation Manager screen.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$capabilities           = $container->get( CapabilityRegistry::class );
		$this->capability       = $capabilities->resolve( CapabilityRegistry::MANAGE_TRANSLATIONS );
		$this->relation_service = $container->get( TranslationRelationServiceInterface::class );
		$this->language_service = $container->get( LanguageServiceInterface::class );
		$this->content_service  = $container->get( ContentTranslationServiceInterface::class );
		$this->taxonomy_service = $container->get( TaxonomyTranslationServiceInterface::class );
		$this->inventory        = $container->get( ContentInventoryService::class );
		$this->transitions      = $container->get( TranslationStatusTransitions::class );
		$this->suggestions      = $container->get( SuggestionAdminState::class );

		$registry = $container->get( AdminScreenRegistry::class );
		$registry->add(
			new AdminScreen(
				static function () {
					return __( 'mcLogiora Translation Manager', 'mclogiora' );
				},
				static function () {
					return __( 'Translation Manager', 'mclogiora' );
				},
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

		$this->enqueue_suggestions();

		$groups              = $this->relation_service instanceof TranslationRelationServiceInterface ? $this->relation_service->get_placeholder_groups() : array();
		$languages           = $this->language_service instanceof LanguageServiceInterface ? $this->language_service->get_active_languages() : array();
		$codes               = $this->language_codes( $languages );
		$content_types       = $this->content_service instanceof ContentTranslationServiceInterface ? $this->content_service->get_translatable_content_types() : array();
		$taxonomies          = $this->taxonomy_service instanceof TaxonomyTranslationServiceInterface ? $this->taxonomy_service->get_translatable_taxonomies() : array();
		$excluded_types      = $this->content_service instanceof ContentTranslationServiceInterface ? $this->content_service->get_excluded_content_types() : array();
		$excluded_taxonomies = $this->taxonomy_service instanceof TaxonomyTranslationServiceInterface ? $this->taxonomy_service->get_excluded_taxonomies() : array();

		?>
		<div class="wrap mclogiora-admin">
			<section class="mclogiora-panel" aria-labelledby="mclogiora-translation-manager-title">
				<p class="mclogiora-eyebrow"><?php esc_html_e( 'Translation Relations', 'mclogiora' ); ?></p>
				<h1 id="mclogiora-translation-manager-title"><?php esc_html_e( 'Translation Manager', 'mclogiora' ); ?></h1>
				<p class="mclogiora-lede"><?php esc_html_e( 'Review translation relations and run translation actions. Every action is explicit: nothing is translated automatically, and unlinking never deletes content.', 'mclogiora' ); ?></p>

				<?php $this->render_action_notice(); ?>
				<?php $this->render_create_translation_panel( $languages ); ?>

				<?php $this->render_inventory(); ?>

				<div class="mclogiora-status-card mclogiora-status-card--notice">
					<span class="mclogiora-status-card__icon" aria-hidden="true">i</span>
					<div>
						<h2><?php esc_html_e( 'Current content scope', 'mclogiora' ); ?></h2>
						<p><?php esc_html_e( 'mcLogiora manages posts, pages, eligible public post types, categories, tags, and eligible public taxonomies. WooCommerce and LMS content is not included in this site configuration.', 'mclogiora' ); ?></p>
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
					<p id="mclogiora-translation-manager-empty-state"><?php echo empty( $groups ) ? esc_html__( 'Your translation relationships will appear here after you create the first one.', 'mclogiora' ) : esc_html__( 'Review each relationship and use explicit actions to update its status or unlink a translation.', 'mclogiora' ); ?></p>
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
									<th scope="col"><?php esc_html_e( 'Actions', 'mclogiora' ); ?></th>
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
	 * Renders the read-only inventory and explicit inline creation controls.
	 *
	 * The controls are ordinary POST forms so the workflow remains usable with
	 * JavaScript disabled and the server remains the only authority for
	 * capability, nonce, eligibility, duplicate, and language-slot checks.
	 *
	 * @return void
	 */
	private function render_inventory() {
		// phpcs:disable -- The inventory table deliberately uses compact template markup; all values are escaped at the output boundary.
		if ( ! $this->inventory instanceof ContentInventoryService ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters; mutations require the action nonce.
		$kind = isset( $_GET['mclogiora_inventory_kind'] ) && 'term' === sanitize_key( wp_unslash( $_GET['mclogiora_inventory_kind'] ) ) ? 'term' : 'post';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$search = isset( $_GET['mclogiora_inventory_search'] ) ? sanitize_text_field( wp_unslash( $_GET['mclogiora_inventory_search'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$page = isset( $_GET['mclogiora_inventory_page'] ) ? absint( $_GET['mclogiora_inventory_page'] ) : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$post_type = isset( $_GET['mclogiora_inventory_post_type'] ) ? sanitize_key( wp_unslash( $_GET['mclogiora_inventory_post_type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$taxonomy = isset( $_GET['mclogiora_inventory_taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['mclogiora_inventory_taxonomy'] ) ) : '';
		$result   = $this->inventory->query(
			array(
				'kind'      => $kind,
				'search'    => $search,
				'page'      => $page,
				'post_type' => $post_type,
				'taxonomy'  => $taxonomy,
				'per_page'  => 25,
			)
		);
		?>
		<div class="mclogiora-table-card" id="mclogiora-content-inventory">
			<h2><?php esc_html_e( 'Content Inventory', 'mclogiora' ); ?></h2>
			<p><?php esc_html_e( 'Eligible content is listed even before it has a translation relation. Scanning is read-only; creation starts an explicit draft workflow.', 'mclogiora' ); ?></p>
			<form method="get" class="mclogiora-filter-bar" aria-label="<?php esc_attr_e( 'Content inventory filters', 'mclogiora' ); ?>">
				<input type="hidden" name="page" value="mclogiora-translation-manager">
				<label><span><?php esc_html_e( 'Inventory', 'mclogiora' ); ?></span><select name="mclogiora_inventory_kind">
					<option value="post" <?php selected( $kind, 'post' ); ?>><?php esc_html_e( 'Posts, pages, and public content', 'mclogiora' ); ?></option>
					<option value="term" <?php selected( $kind, 'term' ); ?>><?php esc_html_e( 'Categories, tags, and public taxonomies', 'mclogiora' ); ?></option>
				</select></label>
				<label><span><?php esc_html_e( 'Search', 'mclogiora' ); ?></span><input type="search" name="mclogiora_inventory_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search content', 'mclogiora' ); ?>"></label>
				<?php if ( 'post' === $kind ) : ?>
					<label><span><?php esc_html_e( 'Post type', 'mclogiora' ); ?></span><select name="mclogiora_inventory_post_type"><option value=""><?php esc_html_e( 'All eligible types', 'mclogiora' ); ?></option>
					<?php
					foreach ( $this->content_service->get_translatable_content_types() as $type ) :
						?>
						<option value="<?php echo esc_attr( $type->name() ); ?>" <?php selected( $post_type, $type->name() ); ?>><?php echo esc_html( $type->label() ); ?></option><?php endforeach; ?></select></label>
				<?php else : ?>
					<label><span><?php esc_html_e( 'Taxonomy', 'mclogiora' ); ?></span><select name="mclogiora_inventory_taxonomy">
					<?php
					foreach ( $this->taxonomy_service->get_translatable_taxonomies() as $type ) :
						?>
						<option value="<?php echo esc_attr( $type->name() ); ?>" <?php selected( $taxonomy, $type->name() ); ?>><?php echo esc_html( $type->label() ); ?></option><?php endforeach; ?></select></label>
				<?php endif; ?>
				<button class="button" type="submit"><?php esc_html_e( 'Filter', 'mclogiora' ); ?></button>
			</form>
			<div class="mclogiora-table-scroll"><table class="widefat striped mclogiora-language-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Title', 'mclogiora' ); ?></th><th scope="col"><?php esc_html_e( 'Type', 'mclogiora' ); ?></th><th scope="col"><?php esc_html_e( 'Source language', 'mclogiora' ); ?></th><th scope="col"><?php esc_html_e( 'Translations', 'mclogiora' ); ?></th><th scope="col"><?php esc_html_e( 'Missing targets', 'mclogiora' ); ?></th><th scope="col"><?php esc_html_e( 'Actions', 'mclogiora' ); ?></th></tr></thead>
				<tbody>
				<?php
				if ( empty( $result['items'] ) ) :
					?>
					<tr><td colspan="6"><?php esc_html_e( 'No eligible content matches this filter.', 'mclogiora' ); ?></td></tr>
					<?php
else :
	foreach ( $result['items'] as $row ) :
		$this->render_inventory_row( $row );
					endforeach;
endif;
?>
</tbody>
			</table></div>
			<?php
			if ( $result['total_pages'] > 1 ) :
				?>
				<p class="tablenav"><span class="displaying-num"><?php /* translators: %d: number of inventory items. */ echo esc_html( sprintf( _n( '%d item', '%d items', $result['total'], 'mclogiora' ), $result['total'] ) ); ?></span> 
				<?php
				for ( $i = 1; $i <= $result['total_pages']; $i++ ) :
					?>
				<a class="button <?php echo $i === $result['page'] ? 'button-primary' : ''; ?>" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page'                     => 'mclogiora-translation-manager',
								'mclogiora_inventory_kind' => $kind,
								'mclogiora_inventory_search' => $search,
								'mclogiora_inventory_post_type' => $post_type,
								'mclogiora_inventory_taxonomy' => $taxonomy,
								'mclogiora_inventory_page' => $i,
							)
						)
					);
					?>
				"><?php echo esc_html( (string) $i ); ?></a> <?php endfor; ?></p><?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders one inventory row.
	 *
	 * @param array<string,mixed> $row Inventory row.
	 * @return void
	 */
	private function render_inventory_row( array $row ) {
		$title = (string) $row['title'];
		$term  = 'term' === $row['kind'];
		?>
		<tr><td><a href="<?php echo esc_url( $row['edit_url'] ); ?>"><strong><?php echo esc_html( $title ); ?></strong></a><br><code><?php echo esc_html( (string) $row['object_id'] ); ?></code></td><td><?php echo esc_html( (string) $row['object_subtype'] ); ?></td><td><?php echo esc_html( '' !== $row['source_language'] ? strtoupper( $row['source_language'] ) : __( 'Unassigned', 'mclogiora' ) ); ?></td><td>
		<?php
		foreach ( $row['targets'] as $code => $target ) :
			?>
			<a class="mclogiora-pill mclogiora-pill--active" href="<?php echo esc_url( $target['edit_url'] ); ?>"><?php echo esc_html( strtoupper( $code ) ); ?></a> 
			<?php
endforeach; if ( empty( $row['targets'] ) ) :
			?>
			<span class="mclogiora-muted-line"><?php esc_html_e( 'None', 'mclogiora' ); ?></span><?php endif; ?></td><td>
			<?php
			foreach ( $row['missing'] as $code ) :
				?>
					<span class="mclogiora-pill"><?php echo esc_html( strtoupper( $code ) ); ?></span> 
					<?php
endforeach; if ( empty( $row['missing'] ) ) :
				?>
						<span class="mclogiora-pill mclogiora-pill--active"><?php esc_html_e( 'Complete', 'mclogiora' ); ?></span><?php endif; ?></td><td><div class="mclogiora-action-row">
						<?php
						foreach ( $row['missing'] as $code ) :
												$this->render_inventory_action( $row, $code, $term );
endforeach;
						?>
</div></td></tr>
		<?php
	}

	/**
	 * Renders a no-JS action form for one missing target language.
	 *
	 * @param array<string,mixed> $row Inventory row.
	 * @param string              $code Target language code.
	 * @param bool                $term Whether this is a term.
	 * @return void
	 */
	private function render_inventory_action( array $row, $code, $term ) {
		/* translators: 1: target language code, 2: source title. */
		$label = sprintf( __( 'Add %1$s translation for %2$s', 'mclogiora' ), strtoupper( $code ), $row['title'] );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mclogiora-inline-form"><input type="hidden" name="action" value="<?php echo esc_attr( $term ? 'mclogiora_create_term_translation' : 'mclogiora_create_translation' ); ?>"><input type="hidden" name="source_id" value="<?php echo esc_attr( (string) $row['object_id'] ); ?>">
		<?php
		if ( $term ) :
			?>
			<input type="hidden" name="taxonomy" value="<?php echo esc_attr( (string) $row['object_subtype'] ); ?>"><input type="hidden" name="translated_name" value="<?php echo esc_attr( (string) $row['title'] ); ?>"><input type="hidden" name="translated_description" value=""><?php endif; ?><input type="hidden" name="target_language" value="<?php echo esc_attr( $code ); ?>"><?php wp_nonce_field( TranslationActionController::NONCE_ACTION, TranslationActionController::NONCE_NAME ); ?><button type="submit" class="button" aria-label="<?php echo esc_attr( $label ); ?>">+ <?php echo esc_html( strtoupper( $code ) ); ?></button></form>
		<?php
	}
		// phpcs:enable

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
			<p><?php esc_html_e( 'These content types can be used in translation relationships on this site.', 'mclogiora' ); ?></p>
			<ul class="mclogiora-inline-list">
				<?php foreach ( $content_types as $type ) : ?>
					<?php if ( $type instanceof TranslatableContentType ) : ?>
						<li><?php echo esc_html( $type->label() . ' (' . $type->name() . ')' ); ?></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! empty( $excluded_types ) ) : ?>
				<p class="mclogiora-muted-line">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of excluded content types. */
							_n( '%d excluded content type detected.', '%d excluded content types detected.', count( $excluded_types ), 'mclogiora' ),
							count( $excluded_types )
						)
					);
					?>
				</p>
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
			<p><?php esc_html_e( 'These taxonomies can be used in translation relationships on this site.', 'mclogiora' ); ?></p>
			<ul class="mclogiora-inline-list">
				<?php foreach ( $taxonomies as $taxonomy ) : ?>
					<?php if ( $taxonomy instanceof TranslatableTaxonomy ) : ?>
						<li><?php echo esc_html( $taxonomy->label() . ' (' . $taxonomy->name() . ')' ); ?></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! empty( $excluded_taxonomies ) ) : ?>
				<p class="mclogiora-muted-line">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of excluded taxonomies. */
							_n( '%d excluded taxonomy detected.', '%d excluded taxonomies detected.', count( $excluded_taxonomies ), 'mclogiora' ),
							count( $excluded_taxonomies )
						)
					);
					?>
				</p>
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
		$original = $group->original();
		$targets  = $group->targets();
		$missing  = $this->relation_service instanceof TranslationRelationServiceInterface ? $this->relation_service->determine_missing_languages_placeholder( $group, $language_codes ) : array();
		$outdated = $this->relation_service instanceof TranslationRelationServiceInterface ? $this->relation_service->determine_outdated_translations_placeholder( $group ) : array();
		$type     = $original instanceof TranslationItem ? $original->content_type() : __( 'Unknown', 'mclogiora' );
		$source   = $original instanceof TranslationItem ? $original->language_code() . ':' . $original->object_key() : __( 'No source', 'mclogiora' );

		?>
		<tr>
			<td><code><?php echo esc_html( $group->group_key() ); ?></code></td>
			<td><?php echo esc_html( ucfirst( $type ) ); ?></td>
			<td><?php echo esc_html( $source ); ?></td>
			<td><?php $this->render_item_pills( $targets ); ?></td>
			<td><?php $this->render_code_pills( $missing, __( 'None', 'mclogiora' ) ); ?></td>
			<td><?php $this->render_item_pills( $outdated, __( 'None', 'mclogiora' ) ); ?></td>
			<td>
				<div class="mclogiora-action-row" aria-label="<?php esc_attr_e( 'Translation actions', 'mclogiora' ); ?>">
					<?php foreach ( $targets as $target ) : ?>
						<?php $this->render_target_actions( $target ); ?>
					<?php endforeach; ?>
					<?php if ( empty( $targets ) ) : ?>
						<span class="mclogiora-muted-line"><?php esc_html_e( 'No translations yet.', 'mclogiora' ); ?></span>
					<?php endif; ?>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders the available actions for one translation item.
	 *
	 * Only transitions the status policy actually allows are offered, so the
	 * screen never presents an action that will be rejected. The policy is
	 * re-checked server side, because a hidden control is not a security
	 * boundary.
	 *
	 * @param TranslationItem $item Translation item.
	 * @return void
	 */
	private function render_target_actions( TranslationItem $item ) {
		$object_type = $item->object_type();
		$object_id   = $item->object_id();
		$language    = $item->language_code();
		$allowed     = $this->transitions instanceof TranslationStatusTransitions
			? $this->transitions->allowed_from( $item->status() )
			: array();

		echo '<div class="mclogiora-action-group">';
		echo '<span class="mclogiora-pill">' . esc_html( strtoupper( $language ) ) . '</span> ';

		foreach ( array( TranslationStatus::NEEDS_REVIEW, TranslationStatus::TRANSLATED ) as $status ) {
			if ( ! in_array( $status, $allowed, true ) ) {
				continue;
			}

			$this->render_action_form(
				'mclogiora_change_translation_status',
				TranslationStatus::TRANSLATED === $status
					? __( 'Mark Translated', 'mclogiora' )
					: __( 'Mark Needs Review', 'mclogiora' ),
				array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'language'    => $language,
					'status'      => $status,
				)
			);
		}

		$this->render_action_form(
			ContentType::TERM === $object_type ? 'mclogiora_unlink_term_translation' : 'mclogiora_unlink_translation',
			__( 'Unlink', 'mclogiora' ),
			array(
				'object_id' => $object_id,
				'language'  => $language,
			)
		);

		if ( ContentType::TERM === $object_type ) {
			$this->render_term_suggestions( $item );
		}

		echo '</div>';
	}


	/**
	 * Loads the shared suggestion script for this screen only.
	 *
	 * Enqueued from render(), so the asset never reaches another admin page and
	 * never reaches the front end. Nothing is shipped when the feature is
	 * unavailable: the controls already explain why, and handing the browser an
	 * action list for something it may not do would be worse than useless.
	 *
	 * @return void
	 */
	private function enqueue_suggestions() {
		if ( ! $this->suggestions instanceof SuggestionAdminState ) {
			return;
		}

		$state = $this->suggestions->current();

		if ( empty( $state['available'] ) ) {
			return;
		}

		$path = MCLOGIORA_PATH . 'assets/js/admin-suggestions.js';

		wp_enqueue_script(
			self::SUGGESTIONS_HANDLE,
			MCLOGIORA_URL . 'assets/js/admin-suggestions.js',
			array( 'wp-i18n' ),
			file_exists( $path ) ? (string) filemtime( $path ) : MCLOGIORA_VERSION,
			true
		);

		wp_set_script_translations( self::SUGGESTIONS_HANDLE, 'mclogiora', MCLOGIORA_PATH . 'languages' );

		wp_add_inline_script(
			self::SUGGESTIONS_HANDLE,
			'window.mcLogioraAdminSuggestions = ' . wp_json_encode(
				array(
					'ajaxUrl'       => $state['ajaxUrl'],
					'actions'       => $state['actions'],
					'nonce'         => $state['nonce'],
					'providerLabel' => $state['providerLabel'],
					'modelLabel'    => $state['modelLabel'],
				)
			) . ';',
			'before'
		);

		wp_enqueue_style(
			self::SUGGESTIONS_HANDLE,
			MCLOGIORA_URL . 'assets/css/editor-panel.css',
			array(),
			MCLOGIORA_VERSION
		);
	}

	/**
	 * Renders the suggestion controls for one translated term.
	 *
	 * Only offered for terms, and only for the two fields the apply service is
	 * willing to write. A slug is deliberately absent: a machine-translated slug
	 * would change every URL the term owns, silently.
	 *
	 * The controls carry the target term's id and language, never any text. The
	 * endpoint resolves the relation, finds the source term and reads the
	 * authoritative value itself, so the browser cannot choose what the owner
	 * pays to translate.
	 *
	 * No form is opened. This cell already contains the status and unlink forms,
	 * and a control that submitted one of those would do something else entirely.
	 *
	 * @param TranslationItem $item Translation item.
	 * @return void
	 */
	private function render_term_suggestions( TranslationItem $item ) {
		if ( ! $this->suggestions instanceof SuggestionAdminState ) {
			return;
		}

		$state = $this->suggestions->current();

		echo '<div class="mclogiora-editor__suggestions">';

		printf( '<h4>%s</h4>', esc_html__( 'Translation Suggestions', 'mclogiora' ) );

		if ( empty( $state['available'] ) ) {
			printf( '<p class="mclogiora-editor__meta">%s</p>', esc_html( $state['reason'] ) );

			if ( ! empty( $state['settingsUrl'] ) ) {
				printf(
					'<p><a href="%1$s">%2$s</a></p>',
					esc_url( $state['settingsUrl'] ),
					esc_html__( 'Translation Suggestions settings', 'mclogiora' )
				);
			}

			echo '</div>';

			return;
		}

		$this->render_term_suggestion_field(
			$item,
			SuggestionSurface::TERM_NAME,
			__( 'Term name', 'mclogiora' ),
			__( 'Generate Term name suggestion', 'mclogiora' )
		);

		$this->render_term_suggestion_field(
			$item,
			SuggestionSurface::TERM_DESCRIPTION,
			__( 'Term description', 'mclogiora' ),
			__( 'Generate Term description suggestion', 'mclogiora' )
		);

		echo '</div>';
	}

	/**
	 * Renders one term field's suggestion control.
	 *
	 * @param TranslationItem $item Translation item.
	 * @param string          $surface Suggestion surface.
	 * @param string          $label Visible field label.
	 * @param string          $accessible_label Accessible name for the generate button.
	 * @return void
	 */
	private function render_term_suggestion_field( TranslationItem $item, $surface, $label, $accessible_label ) {
		printf(
			'<div class="mclogiora-editor__row" data-mclogiora-suggest data-surface="%1$s" data-object="%2$s" data-language="%3$s" data-field-label="%4$s">',
			esc_attr( $surface ),
			esc_attr( (string) $item->object_id() ),
			esc_attr( (string) $item->language_code() ),
			esc_attr( $label )
		);

		echo '<div class="mclogiora-editor__row-head">';

		printf( '<strong>%s</strong>', esc_html( $label ) );

		printf(
			'<button type="button" class="button button-secondary" data-mclogiora-generate aria-label="%1$s">%2$s</button>',
			esc_attr( $accessible_label ),
			esc_html__( 'Generate suggestion', 'mclogiora' )
		);

		echo '</div>';

		echo '<div class="mclogiora-editor__feedback" data-mclogiora-feedback></div>';

		echo '</div>';
	}

	/**
	 * Renders one secured action form.
	 *
	 * @param string               $action Admin post action.
	 * @param string               $label Button label.
	 * @param array<string,string> $fields Hidden fields.
	 * @return void
	 */
	private function render_action_form( $action, $label, array $fields ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mclogiora-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<?php wp_nonce_field( TranslationActionController::NONCE_ACTION, TranslationActionController::NONCE_NAME ); ?>
			<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Renders the create and link translation panel.
	 *
	 * @param Language[] $languages Active languages.
	 * @return void
	 */
	private function render_create_translation_panel( array $languages ) {
		if ( empty( $languages ) ) {
			?>
			<div class="mclogiora-empty-state" role="status">
				<div>
					<h2><?php esc_html_e( 'Translations need a configured language', 'mclogiora' ); ?></h2>
					<p><?php esc_html_e( 'Add and activate at least one language before creating or reviewing translation relationships.', 'mclogiora' ); ?></p>
				</div>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=mclogiora-languages' ) ); ?>"><?php esc_html_e( 'Configure Languages', 'mclogiora' ); ?></a>
			</div>
			<?php

			return;
		}

		?>
		<div class="mclogiora-card-grid mclogiora-card-grid--two">
			<article class="mclogiora-info-card">
				<h2><?php esc_html_e( 'Create Translation', 'mclogiora' ); ?></h2>
				<p><?php esc_html_e( 'Creates a new draft in the target language and links it to the source. The draft starts from the source title, content, and excerpt.', 'mclogiora' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mclogiora_create_translation">
					<?php wp_nonce_field( TranslationActionController::NONCE_ACTION, TranslationActionController::NONCE_NAME ); ?>
					<label>
						<span><?php esc_html_e( 'Source content ID', 'mclogiora' ); ?></span>
						<input type="number" name="source_id" min="1" step="1" required>
					</label>
					<label>
						<span><?php esc_html_e( 'Target language', 'mclogiora' ); ?></span>
						<select name="target_language" required>
							<?php foreach ( $languages as $language ) : ?>
								<?php if ( $language instanceof Language ) : ?>
									<option value="<?php echo esc_attr( $language->code() ); ?>"><?php echo esc_html( $language->native_name() ); ?></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
					</label>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Translation', 'mclogiora' ); ?></button>
				</form>
			</article>
			<article class="mclogiora-info-card">
				<h2><?php esc_html_e( 'Link Existing Translation', 'mclogiora' ); ?></h2>
				<p><?php esc_html_e( 'Connects content that is already translated. No content is copied or changed; only the relation is recorded.', 'mclogiora' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mclogiora_link_translation">
					<?php wp_nonce_field( TranslationActionController::NONCE_ACTION, TranslationActionController::NONCE_NAME ); ?>
					<label>
						<span><?php esc_html_e( 'Source content ID', 'mclogiora' ); ?></span>
						<input type="number" name="source_id" min="1" step="1" required>
					</label>
					<label>
						<span><?php esc_html_e( 'Existing translation ID', 'mclogiora' ); ?></span>
						<input type="number" name="target_id" min="1" step="1" required>
					</label>
					<label>
						<span><?php esc_html_e( 'Target language', 'mclogiora' ); ?></span>
						<select name="target_language" required>
							<?php foreach ( $languages as $language ) : ?>
								<?php if ( $language instanceof Language ) : ?>
									<option value="<?php echo esc_attr( $language->code() ); ?>"><?php echo esc_html( $language->native_name() ); ?></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
					</label>
					<button type="submit" class="button"><?php esc_html_e( 'Link Existing', 'mclogiora' ); ?></button>
				</form>
			</article>
		</div>
		<?php
	}

	/**
	 * Renders the admin notice for the last action, when present.
	 *
	 * @return void
	 */
	private function render_action_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect feedback, no state is changed here.
		$notice = isset( $_GET['mclogiora_notice'] ) ? sanitize_key( wp_unslash( $_GET['mclogiora_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		if ( 'error' === $notice ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect feedback, no state is changed here.
			$message = isset( $_GET['mclogiora_message'] ) ? sanitize_text_field( wp_unslash( $_GET['mclogiora_message'] ) ) : '';

			if ( '' === $message ) {
				$message = __( 'The translation action could not be completed.', 'mclogiora' );
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $message )
			);

			return;
		}

		$messages = array(
			'created'        => __( 'The translation was created as a draft.', 'mclogiora' ),
			'linked'         => __( 'The existing content was linked as a translation.', 'mclogiora' ),
			'unlinked'       => __( 'The translation was unlinked. The content itself was not changed.', 'mclogiora' ),
			'status_changed' => __( 'The translation status was updated.', 'mclogiora' ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success"><p>%s</p></div>',
			esc_html( $messages[ $notice ] )
		);
	}

	/**
	 * Renders item status pills.
	 *
	 * @param TranslationItem[] $items Translation items.
	 * @param string            $empty Empty label.
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
