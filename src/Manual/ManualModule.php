<?php
/**
 * Local mcLogiora manual admin module.
 *
 * @package McLogiora
 */

namespace McLogiora\Manual;

use McLogiora\Admin\AdminScreen;
use McLogiora\Admin\AdminScreenRegistry;
use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a read-only, offline-capable manual screen.
 */
final class ManualModule implements ModuleInterface {
	const PAGE_SLUG = 'mclogiora-manual';

	/**
	 * Effective admin capability.
	 *
	 * @var string
	 */
	private $capability = 'manage_options';

	/**
	 * Registers the manual screen.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->capability = $container->get( CapabilityRegistry::class )->resolve( CapabilityRegistry::MANAGE );

		$container->get( AdminScreenRegistry::class )->add(
			new AdminScreen(
				static function () {
					return __( 'mcLogiora Manual', 'mclogiora' ); },
				static function () {
					return __( 'Manual', 'mclogiora' ); },
				$this->capability,
				self::PAGE_SLUG,
				array( $this, 'render' )
			)
		);
	}

	/**
	 * Renders the manual home or a validated article.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mclogiora' ) );
		}

		// These are read-only navigation/search parameters; the manual has no mutation path.
		$query        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only manual search.
		$article_slug = isset( $_GET['article'] ) ? sanitize_key( wp_unslash( $_GET['article'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only manual navigation.
		$article      = ManualRegistry::find( $article_slug );
		$results      = ManualSearch::search( ManualRegistry::all(), $query );

		?>
		<div class="wrap mclogiora-admin mclogiora-manual">
			<section class="mclogiora-panel" aria-labelledby="mclogiora-manual-title">
				<p class="mclogiora-eyebrow"><?php esc_html_e( 'Help & Guide', 'mclogiora' ); ?></p>
				<h1 id="mclogiora-manual-title"><?php esc_html_e( 'mcLogiora Manual', 'mclogiora' ); ?></h1>
				<p class="mclogiora-lede"><?php esc_html_e( 'Find practical guidance for languages, translations, URLs, suggestions, and diagnostics without leaving WordPress.', 'mclogiora' ); ?></p>
				<form class="mclogiora-manual-search" method="get" role="search">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
					<label for="mclogiora-manual-search-input"><?php esc_html_e( 'How can we help?', 'mclogiora' ); ?></label>
					<div class="mclogiora-manual-search__row"><input id="mclogiora-manual-search-input" type="search" name="s" value="<?php echo esc_attr( $query ); ?>" placeholder="<?php esc_attr_e( 'Search language, missing, + English, hreflang, provider, 404…', 'mclogiora' ); ?>"><button class="button button-primary" type="submit"><?php esc_html_e( 'Search', 'mclogiora' ); ?></button></div>
				</form>

				<?php if ( $article instanceof ManualArticle ) : ?>
					<?php $this->render_article( $article ); ?>
				<?php elseif ( '' !== $query ) : ?>
					<?php /* translators: %d: number of matching manual articles. */ ?>
					<div class="mclogiora-manual-results" aria-live="polite"><h2><?php echo esc_html( sprintf( _n( '%d result', '%d results', count( $results ), 'mclogiora' ), count( $results ) ) ); ?></h2><?php $this->render_article_cards( $results ); ?></div>
				<?php else : ?>
					<div class="mclogiora-manual-layout">
						<div><h2><?php esc_html_e( 'Start here', 'mclogiora' ); ?></h2><?php $this->render_article_cards( $this->by_slugs( array( 'quick-start', 'choosing-languages', 'first-translation', 'translation-manager' ) ) ); ?></div>
						<aside class="mclogiora-manual-categories" aria-label="<?php esc_attr_e( 'Manual categories', 'mclogiora' ); ?>"><h2><?php esc_html_e( 'Browse by topic', 'mclogiora' ); ?></h2><ul>
						<?php
						foreach ( ManualRegistry::categories() as $category ) :
							?>
							<li><a href="<?php echo esc_url( $this->category_url( $category ) ); ?>"><?php echo esc_html( $category ); ?></a></li><?php endforeach; ?></ul></aside>
					</div>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders article summary cards.
	 *
	 * @param ManualArticle[] $articles Articles.
	 * @return void
	 */
	private function render_article_cards( array $articles ) {
		if ( empty( $articles ) ) {
			echo '<p class="mclogiora-muted-line">' . esc_html__( 'No matching articles. Try a language name, locale, missing, URL, or provider search.', 'mclogiora' ) . '</p>';
			return;
		}

		?>
		<div class="mclogiora-manual-card-grid">
			<?php foreach ( $articles as $item ) : ?>
				<article class="mclogiora-info-card mclogiora-manual-card"><p class="mclogiora-eyebrow"><?php echo esc_html( $item->category() ); ?></p><h3><a href="<?php echo esc_url( $this->article_url( $item->slug() ) ); ?>"><?php echo esc_html( $item->title() ); ?></a></h3><p><?php echo esc_html( $item->summary() ); ?></p></article>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Renders one manual article.
	 *
	 * @param ManualArticle $article Article.
	 * @return void
	 */
	private function render_article( ManualArticle $article ) {
		$related = $this->by_slugs( $article->related_articles() );

		?>
		<nav class="mclogiora-manual-breadcrumbs" aria-label="<?php esc_attr_e( 'Manual breadcrumbs', 'mclogiora' ); ?>"><a href="<?php echo esc_url( $this->article_url( '' ) ); ?>"><?php esc_html_e( 'Manual', 'mclogiora' ); ?></a><span aria-hidden="true"> / </span><span><?php echo esc_html( $article->title() ); ?></span></nav>
		<article class="mclogiora-manual-article" aria-labelledby="mclogiora-article-title"><p class="mclogiora-eyebrow"><?php echo esc_html( $article->category() ); ?></p><h2 id="mclogiora-article-title"><?php echo esc_html( $article->title() ); ?></h2><p class="mclogiora-lede"><?php echo esc_html( $article->summary() ); ?></p>
			<?php
			foreach ( $article->sections() as $section ) :
				$this->render_section( $section );
endforeach;
			$this->render_media( $article );
			?>
		</article>
		<?php
		if ( ! empty( $related ) ) :
			?>
			<div class="mclogiora-manual-related"><h3><?php esc_html_e( 'Related articles', 'mclogiora' ); ?></h3><?php $this->render_article_cards( $related ); ?></div><?php endif; ?>
		<?php
	}

	/**
	 * Renders curated, locally bundled screenshots attached to an article.
	 *
	 * @param ManualArticle $article Article.
	 * @return void
	 */
	private function render_media( ManualArticle $article ) {
		foreach ( $article->media() as $media ) {
			if ( ! is_array( $media ) || empty( $media['file'] ) || empty( $media['alt'] ) ) {
				continue;
			}

			$file = sanitize_file_name( $media['file'] );
			$path = MCLOGIORA_PATH . 'assets/manual/' . $file;
			if ( ! file_exists( $path ) ) {
				continue;
			}

			echo '<figure class="mclogiora-manual-media">';
			printf(
				'<img src="%1$s" alt="%2$s" loading="lazy" width="%3$d" height="%4$d">',
				esc_url( MCLOGIORA_URL . 'assets/manual/' . $file ),
				esc_attr( $media['alt'] ),
				(int) ( $media['width'] ?? 960 ),
				(int) ( $media['height'] ?? 600 )
			);
			if ( ! empty( $media['caption'] ) ) {
				printf( '<figcaption>%s</figcaption>', esc_html( $media['caption'] ) );
			}
			echo '</figure>';
		}
	}

	/**
	 * Renders one trusted structured section.
	 *
	 * @param array<string,mixed> $section Section data.
	 * @return void
	 */
	private function render_section( array $section ) {
		$type = isset( $section['type'] ) ? sanitize_key( $section['type'] ) : 'paragraph';

		if ( isset( $section['heading'] ) ) {
			echo '<h3>' . esc_html( $section['heading'] ) . '</h3>';
		}

		if ( isset( $section['text'] ) && 'tip' !== $type ) {
			echo '<p>' . esc_html( $section['text'] ) . '</p>';
		}

		if ( in_array( $type, array( 'list', 'steps' ), true ) && isset( $section['items'] ) && is_array( $section['items'] ) ) {
			echo 'steps' === $type ? '<ol class="mclogiora-manual-list">' : '<ul class="mclogiora-manual-list">';
			foreach ( $section['items'] as $item ) {
				echo '<li>' . esc_html( $item ) . '</li>';
			}
			echo 'steps' === $type ? '</ol>' : '</ul>';
		}

		if ( 'tip' === $type ) {
			echo '<div class="mclogiora-manual-tip" role="note"><strong>' . esc_html( isset( $section['heading'] ) ? $section['heading'] : __( 'Tip', 'mclogiora' ) ) . '</strong>';
			if ( isset( $section['text'] ) ) {
				echo '<p>' . esc_html( $section['text'] ) . '</p>';
			}
			echo '</div>';
		}
	}

	/**
	 * Finds a set of related articles.
	 *
	 * @param string[] $slugs Article slugs.
	 * @return ManualArticle[]
	 */
	private function by_slugs( array $slugs ) {
		$articles = array();
		foreach ( $slugs as $slug ) {
			$article = ManualRegistry::find( $slug );
			if ( $article instanceof ManualArticle ) {
				$articles[] = $article;
			}
		}
		return $articles;
	}

	/**
	 * Builds a safe article URL.
	 *
	 * @param string $slug Article slug.
	 * @return string
	 */
	private function article_url( $slug ) {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG . ( '' !== $slug ? '&article=' . rawurlencode( sanitize_key( $slug ) ) : '' ) );
	}

	/**
	 * Builds a category search URL.
	 *
	 * @param string $category Category name.
	 * @return string
	 */
	private function category_url( $category ) {
		return $this->article_url( '' ) . '&s=' . rawurlencode( $category );
	}
}
