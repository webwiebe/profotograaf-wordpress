<?php
/**
 * E2E fixture: a Contact Form 7 form on a page, and mail that always "sends".
 *
 * Run inside the WordPress container with `wp eval-file`. Prints the form id
 * and the page URL as JSON.
 *
 * @package Profotograaf
 */

wp_mkdir_p( WPMU_PLUGIN_DIR );
file_put_contents( WPMU_PLUGIN_DIR . '/e2e-mail.php', "<?php\nadd_filter( 'pre_wp_mail', '__return_true' );\n" );

// Start clean so the fixture can run again on the same site.
foreach ( get_posts(
	array(
		'post_type'   => array( 'wpcf7_contact_form', 'page' ),
		'post_status' => 'any',
		'name'        => '',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
) as $old_id ) {
	if ( 'wpcf7_contact_form' === get_post_type( $old_id ) || 'contact' === get_post_field( 'post_name', $old_id ) ) {
		wp_delete_post( $old_id, true );
	}
}

$form = WPCF7_ContactForm::get_template();
$form->set_title( 'Wedding inquiry' );
$form->set_properties(
	array(
		'form' => "<label>Your name [text* your-name]</label>\n"
			. "<label>Your email [email* your-email]</label>\n"
			. "<label>Your phone [tel your-tel]</label>\n"
			. "<label>Wedding date [date wedding-date]</label>\n"
			. "<label>Location [text location]</label>\n"
			. "<label>Your message [textarea your-message]</label>\n"
			. '[submit "Send"]',
		'mail' => array(
			'active'             => true,
			'subject'            => 'Enquiry',
			'sender'             => 'Photos <admin@example.com>',
			'recipient'          => 'admin@example.com',
			'body'               => '[your-message]',
			'additional_headers' => '',
			'attachments'        => '',
			'use_html'           => false,
			'exclude_blank'      => false,
		),
	)
);
$form->save();

$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Contact',
		'post_name'    => 'contact',
		'post_content' => sprintf( '[contact-form-7 id="%s" title="Wedding inquiry"]', method_exists( $form, 'hash' ) ? $form->hash() : $form->id() ),
	)
);

echo wp_json_encode(
	array(
		'form_id' => $form->id(),
		'page'    => get_permalink( $page_id ),
	)
);
