<?php
/**
 * Page template "Profotograaf full page" for classic themes: the page content
 * without the theme header, footer or sidebar.
 *
 * @package Profotograaf
 */

defined( 'ABSPATH' ) || exit;

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'profotograaf-full-page' ); ?>>
<?php wp_body_open(); ?>
<main class="profotograaf-full-page-main" style="margin:0;padding:0">
	<?php
	while ( have_posts() ) {
		the_post();
		the_content();
	}
	?>
</main>
<?php wp_footer(); ?>
</body>
</html>
