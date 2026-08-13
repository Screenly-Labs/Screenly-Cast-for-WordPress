<?php
/**
 * The signage document.
 *
 * A complete HTML document owned by the plugin. It deliberately does not call
 * get_header() or get_footer(), so the active theme's templates and styles play
 * no part in a signage render and the theme itself is never modified.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

namespace ScreenlyCast;

defined( 'ABSPATH' ) || exit;

$srly_signage = Renderer::current();

if ( ! $srly_signage instanceof Renderer ) {
	return;
}

$srly_has_entry = have_posts();

if ( $srly_has_entry ) {
	the_post();
}

$srly_logo = $srly_signage->logo_markup();
$srly_gate = SRLY_PLUGIN_DIR . 'assets/dist/gate.php';

?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php
	/*
	 * The degraded-mode gate must run before the stylesheet loads, so it goes
	 * ahead of wp_head(). It marks the document when the engine renders below the
	 * supported floor, which a large share of real signage players do.
	 *
	 * It is required as a PHP file rather than read and echoed: the contents are
	 * a build artifact rather than data, so require() both keeps it out of the
	 * output-escaping rules it would otherwise trip and lets opcache hold it.
	 */
	if ( is_readable( $srly_gate ) ) {
		require $srly_gate;
	}

	wp_head();
	?>
</head>
<body <?php body_class( $srly_signage->body_class_names() ); ?>>
<main class="srly-stage">

	<?php if ( $srly_has_entry ) : ?>

		<?php if ( $srly_signage->has_featured_image() ) : ?>
			<figure class="srly-figure">
				<?php echo wp_kses_post( $srly_signage->featured_image_markup() ); ?>
			</figure>
		<?php endif; ?>

		<article class="srly-entry">
			<header class="srly-entry__header">
				<?php
				/*
				 * The eyebrow carries the date for posts and the site name for
				 * anything else. Which one appears is information rather than
				 * ornament: a post has a meaningful publication date, a page or an
				 * attachment does not, so those name their source instead.
				 */
				?>
				<?php if ( 'post' === get_post_type() && $srly_signage->show_date() ) : ?>
					<time
						class="srly-entry__eyebrow"
						datetime="<?php echo esc_attr( (string) get_the_date( 'c' ) ); ?>"
					>
						<?php echo esc_html( (string) get_the_date() ); ?>
					</time>
				<?php else : ?>
					<p class="srly-entry__eyebrow"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
				<?php endif; ?>

				<h1 class="srly-entry__title"><?php echo esc_html( get_the_title() ); ?></h1>
			</header>

			<div class="srly-entry__content" data-srly-fit>
				<?php the_content(); ?>
			</div>
		</article>

	<?php else : ?>

		<article class="srly-entry srly-entry--empty">
			<header class="srly-entry__header">
				<h1 class="srly-entry__title"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
			</header>

			<?php $srly_tagline = get_bloginfo( 'description' ); ?>
			<?php if ( '' !== $srly_tagline ) : ?>
				<div class="srly-entry__content">
					<p><?php echo esc_html( $srly_tagline ); ?></p>
				</div>
			<?php endif; ?>
		</article>

	<?php endif; ?>

	<?php if ( '' !== $srly_logo ) : ?>
		<div class="srly-logo">
			<?php echo wp_kses_post( $srly_logo ); ?>
		</div>
	<?php endif; ?>

</main>
<?php wp_footer(); ?>
</body>
</html>
