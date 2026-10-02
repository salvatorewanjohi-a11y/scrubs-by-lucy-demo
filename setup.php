<?php
/**
 * Builds the Scrubs by Lucy store inside WordPress Playground.
 * Run by the blueprint after WooCommerce and the theme are installed.
 * Product photos are read from /wordpress/sbl-images.
 */
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// The photos are already web-sized, so each one is copied straight into uploads and registered with its
// size. (media_handle_sideload opens every image, which made the demo slow to build.)
function sbl_sideload( $tmp, $name, $parent, $title ) {
	$up   = wp_upload_dir();
	$file = wp_unique_filename( $up['path'], $name );
	$dest = trailingslashit( $up['path'] ) . $file;
	if ( ! @rename( $tmp, $dest ) && ! copy( $tmp, $dest ) ) {
		return new WP_Error( 'sbl_copy', 'Could not copy ' . $name );
	}
	$type = wp_check_filetype( $file );
	$size = @getimagesize( $dest ) ?: [ 0, 0 ];
	$id   = wp_insert_attachment( [ 'post_mime_type' => $type['type'], 'post_title' => $title, 'post_status' => 'inherit', 'guid' => trailingslashit( $up['url'] ) . $file ], $dest, $parent, true, false );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	wp_update_attachment_metadata( $id, [ 'width' => $size[0], 'height' => $size[1], 'file' => _wp_relative_upload_path( $dest ), 'sizes' => [], 'image_meta' => [] ] );
	return $id;
}

// One transaction for the whole import: SQLite otherwise commits (and syncs to disk) after every query.
wp_defer_term_counting( true );
wp_suspend_cache_invalidation( true );
$wpdb->query( 'START TRANSACTION' );

// Fast demo build: the photos are already web-sized, so skip making thumbnails of each one.
// (Real hosting can regenerate thumbnails later.)
if ( defined( 'SBL_FAST' ) && SBL_FAST ) {
	add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
	add_filter( 'big_image_size_threshold', '__return_false' );
	add_filter( 'woocommerce_background_image_regeneration', '__return_false' );
	add_filter( 'woocommerce_resize_images', '__return_false' );
}

// Store settings.
foreach ( [
	'blogname'                               => 'Scrubs by Lucy',
	'blogdescription'                        => 'Medical scrubs, lab coats and nurse uniforms, Nairobi',
	'timezone_string'                        => 'Africa/Nairobi',
	'woocommerce_currency'                   => 'KES',
	'woocommerce_currency_pos'               => 'left_space',
	'woocommerce_price_num_decimals'         => '0',
	'woocommerce_price_thousand_sep'         => ',',
	'woocommerce_default_country'            => 'KE:KE30',
	'woocommerce_store_address'              => 'The Bazaar, 1st Floor, Room B05, Moi Avenue',
	'woocommerce_store_city'                 => 'Nairobi',
	'woocommerce_manage_stock'               => 'yes',
	'woocommerce_notify_low_stock_amount'    => '3',
	'woocommerce_notify_no_stock_amount'     => '0',
	'woocommerce_allowed_countries'          => 'specific',
	'woocommerce_specific_allowed_countries' => [ 'KE' ],
	'woocommerce_ship_to_countries'          => '',
	'woocommerce_enable_reviews'             => 'yes',
	'woocommerce_review_rating_verification_label' => 'yes',
	'woocommerce_onboarding_profile'         => [ 'skipped' => true ],
	'woocommerce_task_list_hidden'           => 'yes',
	'woocommerce_coming_soon'                => 'no',
	'woocommerce_checkout_phone_field'       => 'required',
	'woocommerce_enable_coupons'             => 'yes',
] as $k => $v ) {
	update_option( $k, $v );
}

// Remove sample content.
foreach ( get_posts( [ 'post_type' => [ 'post', 'page' ], 'name' => 'hello-world', 'numberposts' => 1 ] ) as $p ) wp_delete_post( $p->ID, true );
$sample = get_page_by_path( 'sample-page' );
if ( $sample ) wp_delete_post( $sample->ID, true );

// Colour and size attributes (global, so the shop can filter by them).
$attr_ids = [];
foreach ( [ 'colour' => 'Colour', 'size' => 'Size' ] as $slug => $label ) {
	$id = wc_attribute_taxonomy_id_by_name( $slug );
	if ( ! $id ) {
		$id = wc_create_attribute( [ 'name' => $label, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => true ] );
	}
	$attr_ids[ $slug ] = $id;
	register_taxonomy( "pa_$slug", 'product', [ 'hierarchical' => false, 'rewrite' => [ 'slug' => $slug ], 'public' => true, 'query_var' => true ] );
}
delete_transient( 'wc_attribute_taxonomies' );

$size_order = [ 'XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '37', '38', '39', '40', '41', '42', '43', '44' ];
foreach ( $size_order as $i => $s ) {
	$t = term_exists( $s, 'pa_size' ) ?: wp_insert_term( $s, 'pa_size', [ 'slug' => strtolower( $s ) ] );
	update_term_meta( (int) $t['term_id'], 'order', $i );
}

function sbl_term_ids( $tax, array $names ) {
	$ids = [];
	foreach ( $names as $n ) {
		$t = term_exists( $n, $tax ) ?: wp_insert_term( $n, $tax );
		$ids[] = (int) $t['term_id'];
	}
	return $ids;
}

function sbl_attribute( $tax_slug, $attr_id, array $names, $position ) {
	$a = new WC_Product_Attribute();
	$a->set_id( $attr_id );
	$a->set_name( "pa_$tax_slug" );
	$a->set_options( sbl_term_ids( "pa_$tax_slug", $names ) );
	$a->set_position( $position );
	$a->set_visible( true );
	$a->set_variation( false );
	return $a;
}

// Categories.
$cat = [];
foreach ( [
	'scrubs'              => 'Scrubs',
	'scrub-tops'          => 'Scrub Tops',
	'scrub-jackets'       => 'Scrub Jackets',
	'lab-coats'           => 'Lab Coats',
	'nurse-uniforms'      => 'Nurse Uniforms',
	'underscrubs'         => 'Underscrubs',
	'theatre-caps'        => 'Theatre Caps',
	'footwear'            => 'Footwear',
	'medical-accessories' => 'Medical Accessories',
] as $slug => $name ) {
	$t = term_exists( $slug, 'product_cat' ) ?: wp_insert_term( $name, 'product_cat', [ 'slug' => $slug ] );
	$cat[ $slug ] = (int) $t['term_id'];
}

// "Shop by role" tags.
$role = [];
foreach ( [
	'doctors-surgeons'     => 'Doctors & Surgeons',
	'nurses-midwives'      => 'Nurses & Midwives',
	'clinical-students'    => 'Clinical Students',
	'dental-allied-health' => 'Dental & Allied Health',
	'lab-diagnostics'      => 'Lab & Diagnostics',
] as $slug => $name ) {
	$t = term_exists( $slug, 'product_tag' ) ?: wp_insert_term( $name, 'product_tag', [ 'slug' => $slug ] );
	$role[ $slug ] = (int) $t['term_id'];
}
$ALL_ROLES = array_keys( $role );

// Delivery.
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'Nairobi' );
$zone->add_location( 'KE:KE30', 'state' );
$zone->save();
$id = $zone->add_shipping_method( 'flat_rate' );
update_option( "woocommerce_flat_rate_{$id}_settings", [ 'title' => 'Express Nairobi Dispatch (same day)', 'cost' => '300', 'tax_status' => 'none' ] );
$id = $zone->add_shipping_method( 'free_shipping' );
update_option( "woocommerce_free_shipping_{$id}_settings", [ 'title' => 'Free Nairobi Express Delivery', 'requires' => 'min_amount', 'min_amount' => '6000' ] );

$rest = new WC_Shipping_Zone();
$rest->set_zone_name( 'Rest of Kenya' );
$rest->add_location( 'KE', 'country' );
$rest->save();
$id = $rest->add_shipping_method( 'flat_rate' );
update_option( "woocommerce_flat_rate_{$id}_settings", [ 'title' => 'Standard Upcountry Courier (1–3 days)', 'cost' => '500', 'tax_status' => 'none' ] );

update_option( 'woocommerce_pickup_location_settings', [ 'enabled' => 'yes', 'title' => 'In-Store Pickup (free)', 'tax_status' => 'none', 'cost' => '' ] );
update_option( 'pickup_location_pickup_locations', [ [
	'name'    => 'Scrubs by Lucy – The Bazaar, 1st Floor B05',
	'address' => [ 'address_1' => 'The Bazaar, 1st Floor, Room B05, Moi Avenue (next to Bihi Towers)', 'city' => 'Nairobi CBD', 'state' => 'KE30', 'postcode' => '', 'country' => 'KE' ],
	'details' => 'Mon–Sat 8:30 AM – 6:30 PM. We will call you when your order is ready, and you can try it on before you leave.',
	'enabled' => true,
] ] );

// Payment: pay on delivery or at pickup until M-Pesa is connected.
update_option( 'woocommerce_cod_settings', [
	'enabled'            => 'yes',
	'title'              => 'Pay on delivery or pickup (M-Pesa or cash)',
	'description'        => 'Pay by M-Pesa or cash when your order arrives, or when you collect it from the shop.',
	'instructions'       => 'We will call you to confirm your order and delivery time.',
	'enable_for_methods' => [],
	'enable_for_virtual' => 'yes',
] );

// Newsletter welcome coupon.
$c = new WC_Coupon();
$c->set_code( 'MEDCLUB500' );
$c->set_discount_type( 'fixed_cart' );
$c->set_amount( 500 );
$c->set_minimum_amount( 2500 );
$c->set_individual_use( true );
$c->set_usage_limit_per_user( 1 );
$c->set_description( 'Med-Club newsletter welcome offer' );
$c->save();

// Products, from the shop's WhatsApp catalogue.
$CLOTHES   = [ 'XS', 'S', 'M', 'L', 'XL', '2XL' ];
$JACKETS   = [ 'XS', 'S', 'M', 'L', 'XL', '2XL', '3XL' ];
$SHOES     = [ '37', '38', '39', '40', '41', '42', '43', '44' ];
$SCRUB_CARE = "Wash inside out at 30–40°C with similar colours. No bleach. Hang to dry and warm iron if needed; iron embroidery from the reverse side.";

$products = [
	[
		'slug' => 'cherokee-scrubs', 'colour_img' => [ 'Royal Blue' => 0, 'Purple' => 1, 'Surgical Green' => 2 ], 'name' => 'Cherokee Scrub Set – Top & Trousers', 'cat' => 'scrubs', 'price' => 2000,
		'colours' => [ 'Royal Blue', 'Purple', 'Surgical Green', 'Navy Blue', 'Wine', 'Ceil Blue', 'Black', 'Charcoal' ], 'sizes' => $CLOTHES,
		'roles' => [ 'doctors-surgeons', 'nurses-midwives', 'clinical-students', 'dental-allied-health' ], 'spec' => '20+ Colours', 'featured' => true,
		'desc' => 'Our everyday unisex scrub set: a V-neck top and matching drawstring trousers in a soft cotton–polyester blend that stays smart through long shifts. Available in more than 20 colours, so you can match your hospital or department. Ask us on WhatsApp for any colour not listed.',
		'fabric' => "35% cotton, 65% polyester. Unisex fit.\n\n$SCRUB_CARE",
		'upsells' => [ 'infinity-scrub-jacket', 'underscrubs', 'theatre-cap-plain' ],
	],
	[
		'slug' => 'white-stretch-scrubs', 'name' => 'ScrubsByLucy White Stretch Scrubs', 'cat' => 'scrubs', 'price' => 2200,
		'colours' => [ 'White' ], 'sizes' => $CLOTHES,
		'roles' => [ 'doctors-surgeons', 'clinical-students', 'dental-allied-health' ], 'spec' => 'Spandex Stretch', 'featured' => true,
		'desc' => 'Crisp white scrubs made by ScrubsByLucy in a stretchy spandex fabric that moves with you. Unisex cut with an elastic waist and multiple pockets for pens, torches and your phone.',
		'fabric' => "Spandex stretch fabric, made by ScrubsByLucy. Elastic waist, multiple pockets. Unisex fit.\n\nWhite can be washed at 60°C to keep it bright. No chlorine bleach on the stretch fabric. Hang to dry.",
		'upsells' => [ 'lab-coat', 'white-scrub-top', 'dental-cap' ],
	],
	[
		'slug' => 'navy-blue-scrubs', 'name' => 'Navy Blue Scrub Set', 'cat' => 'scrubs', 'price' => 2200, 'sale' => 1800,
		'colours' => [ 'Navy Blue' ], 'sizes' => $CLOTHES,
		'roles' => $ALL_ROLES, 'spec' => 'Multiple Pockets', 'featured' => true,
		'desc' => 'A classic navy scrub set in cotton–polyester: unisex, with an elastic waist and multiple pockets. Smart enough for the ward, easy enough to wash every day.',
		'fabric' => "Cotton–polyester blend. Unisex fit, elastic waist, multiple pockets.\n\n$SCRUB_CARE",
		'upsells' => [ 'infinity-scrub-jacket', 'underscrubs' ],
	],
	[
		'slug' => 'magenta-scrubs', 'name' => 'Magenta Scrub Set – Mint Fabric', 'cat' => 'scrubs', 'price' => 2500,
		'colours' => [ 'Magenta' ], 'sizes' => $CLOTHES,
		'roles' => [ 'nurses-midwives', 'dental-allied-health', 'clinical-students' ], 'spec' => 'Mint Fabric',
		'desc' => 'Stand out on shift in bold magenta. Made from our smooth "mint" fabric, with a relaxed fit, elastic waist and plenty of pockets.',
		'fabric' => "Mint fabric: smooth, lightweight and quick-drying. Elastic waist, multiple pockets.\n\n$SCRUB_CARE",
		'upsells' => [ 'theatre-cap-floral', 'underscrubs' ],
	],
	[
		'slug' => 'coral-pink-scrubs', 'name' => 'Coral Pink Scrub Set – Mint Fabric', 'cat' => 'scrubs', 'price' => 2500,
		'colours' => [ 'Coral Pink' ], 'sizes' => $CLOTHES,
		'roles' => [ 'nurses-midwives', 'dental-allied-health', 'clinical-students' ], 'spec' => 'Mint Fabric', 'featured' => true,
		'desc' => 'A soft coral pink scrub set in our lightweight mint fabric. Elastic waist, multiple pockets and a flattering unisex cut.',
		'fabric' => "Mint fabric: smooth, lightweight and quick-drying. Elastic waist, multiple pockets.\n\n$SCRUB_CARE",
		'upsells' => [ 'theatre-cap-floral', 'anno-clogs' ],
	],
	[
		'slug' => 'floral-scrub-top', 'name' => 'Printed Scrub Top', 'cat' => 'scrub-tops',
		'colours' => [ 'Printed' ], 'sizes' => $CLOTHES,
		'options' => [ 'label' => 'Style', 'choices' => [ 'Top only' => 1500, 'Top + trousers' => 2600 ] ],
		'roles' => [ 'nurses-midwives', 'dental-allied-health' ], 'spec' => 'Fun Prints', 'featured' => true,
		'desc' => 'Cheerful printed scrub tops that brighten up the ward, and the patients. Prints change often, so WhatsApp us to see what is in stock today. Buy the top on its own or as a set with plain trousers.',
		'fabric' => "Cotton–polyester print.\n\n$SCRUB_CARE",
		'upsells' => [ 'theatre-cap-floral', 'navy-blue-scrubs' ],
	],
	[
		'slug' => 'white-scrub-top', 'name' => 'White Scrub Top', 'cat' => 'scrub-tops', 'price' => 1100,
		'colours' => [ 'White' ], 'sizes' => $CLOTHES,
		'roles' => [ 'doctors-surgeons', 'clinical-students', 'dental-allied-health', 'lab-diagnostics' ], 'spec' => 'Student Favourite',
		'desc' => 'A simple, affordable white V-neck scrub top with a chest pocket. Popular with clinical students and dental teams.',
		'fabric' => "Cotton–polyester.\n\nWhite can be washed at 60°C. Hang to dry.",
		'upsells' => [ 'white-stretch-scrubs', 'lab-coat' ],
	],
	[
		'slug' => 'infinity-scrub-jacket', 'colour_img' => [ 'Royal Blue' => 0, 'Black' => 2, 'Charcoal' => 3, 'Hunter Green' => 4, 'Red' => 5, 'Maroon' => 6, 'Ceil Blue' => 7, 'White' => 8 ], 'name' => 'Infinity Scrub Jacket', 'cat' => 'scrub-jackets', 'price' => 1500,
		'colours' => [ 'Royal Blue', 'Black', 'Charcoal', 'Hunter Green', 'Red', 'Maroon', 'Ceil Blue', 'White' ], 'sizes' => $JACKETS,
		'roles' => $ALL_ROLES, 'spec' => 'Stretch Back', 'featured' => true,
		'desc' => 'Our bestselling warm-up jacket. A stretchy back panel lets you reach and lift freely, the full front zip goes on over any scrubs, and zipped pockets keep your phone and keys safe on the ward. Unisex, sizes XS to 3XL.',
		'fabric' => "Stretch knit with an elastic back. Front zip, zipped pockets. Unisex fit, XS–3XL.\n\nMachine wash cold, inside out. Do not tumble dry. Iron embroidery from the reverse side.",
		'upsells' => [ 'cherokee-scrubs', 'underscrubs' ],
	],
	[
		'slug' => 'extra-warm-scrub-jacket', 'name' => "Member's Mark Extra-Warm Scrub Jacket", 'cat' => 'scrub-jackets', 'price' => 1500,
		'colours' => [ 'Black', 'Teal', 'Orange' ], 'sizes' => [ 'S', 'M', 'L', 'XL', '2XL' ],
		'roles' => [ 'doctors-surgeons', 'nurses-midwives', 'lab-diagnostics' ], 'spec' => 'Extra Warm',
		'desc' => 'A heavier, extra-warm scrub jacket for cold theatres and night shifts. Full zip front and deep pockets.',
		'fabric' => "Brushed warm knit. Full zip.\n\nMachine wash cold, inside out. Do not tumble dry.",
		'upsells' => [ 'underscrubs', 'cherokee-scrubs' ],
	],
	[
		'slug' => 'underscrubs', 'colour_img' => [ 'Red' => 0, 'Black' => 1, 'White' => 2, 'Magenta' => 2, 'Teal' => 3 ], 'name' => 'Long-Sleeve Underscrub', 'cat' => 'underscrubs', 'price' => 1000,
		'colours' => [ 'Black', 'White', 'Red', 'Magenta', 'Teal' ], 'sizes' => [ 'S', 'M', 'L', 'XL', '2XL' ],
		'roles' => $ALL_ROLES, 'spec' => 'Stretch Fit', 'featured' => true,
		'desc' => 'A fitted, stretchy long-sleeve layer worn under your scrubs for warmth and modesty. Unisex, stylish and easy to move in.',
		'fabric' => "Stretch jersey. Unisex fitted cut.\n\nMachine wash cold with similar colours. Do not tumble dry.",
		'upsells' => [ 'cherokee-scrubs', 'infinity-scrub-jacket' ],
	],
	[
		'slug' => 'nurse-uniform', 'name' => 'NCK Nurse Uniform – White & Navy', 'cat' => 'nurse-uniforms',
		'colours' => [ 'White & Navy' ], 'sizes' => $CLOTHES,
		'options' => [ 'label' => 'Style', 'choices' => [ 'Full set (top + trousers)' => 2700, 'Top only' => 1500, 'Trousers only' => 1200 ] ],
		'roles' => [ 'nurses-midwives', 'clinical-students' ], 'spec' => 'NCK Approved', 'featured' => true,
		'desc' => 'The white-and-navy nurse uniform in the NCK-approved style: a white tunic with navy trim and a pocket, with navy trousers. Buy the full set, or the top or trousers on their own. The tunic fabric does not stretch, so choose your usual size or one up.',
		'fabric' => "Polyester–cotton tunic (non-stretch) with navy trim; navy trousers.\n\nWash at 40°C. Wash the white tunic separately from the navy trousers.",
		'upsells' => [ 'nurse-fob-watch', 'student-stethoscope', 'pen-torch' ],
	],
	[
		'slug' => 'lab-coat', 'name' => 'Classic White Lab Coat', 'cat' => 'lab-coats', 'price' => 1700,
		'colours' => [ 'White' ], 'sizes' => [ 'XS', 'S', 'M', 'L', 'XL' ],
		'roles' => [ 'doctors-surgeons', 'clinical-students', 'lab-diagnostics', 'dental-allied-health' ], 'spec' => 'XS–XL', 'featured' => true,
		'desc' => 'A crisp, knee-length white lab coat with a notched collar, button front and three pockets. Ideal for ward rounds, labs and your white-coat ceremony. Add your name and title embroidery.',
		'fabric' => "Polyester–cotton twill. Button front, three pockets.\n\nWash at 60°C to keep it white. Iron on medium.",
		'upsells' => [ 'student-stethoscope', 'pen-torch', 'white-scrub-top' ],
	],
	[
		'slug' => 'theatre-cap-floral', 'name' => 'Printed Theatre Cap', 'cat' => 'theatre-caps',
		'colours' => [ 'Printed' ],
		'options' => [ 'label' => 'Cap size', 'choices' => [ 'Small – short hair' => 650, 'Large – long hair & braids' => 750 ] ],
		'roles' => [ 'doctors-surgeons', 'nurses-midwives', 'dental-allied-health' ], 'spec' => 'Many Prints', 'featured' => true,
		'desc' => 'Fun printed theatre caps in two sizes: small for short hair, and large (bouffant style) for long hair, braids and locs. Ties at the back, with buttons for your mask loops. Prints change often; WhatsApp us to see today\'s selection.',
		'fabric' => "Cotton print. Back ties, mask buttons.\n\nHand or machine wash warm. Air dry.",
		'upsells' => [ 'cherokee-scrubs', 'magenta-scrubs' ],
	],
	[
		'slug' => 'theatre-cap-plain', 'colour_img' => [ 'Navy Blue' => 0, 'Teal' => 1 ], 'name' => 'Plain Theatre Cap', 'cat' => 'theatre-caps',
		'colours' => [ 'Navy Blue', 'Teal', 'Royal Blue', 'Black', 'Wine' ],
		'options' => [ 'label' => 'Cap size', 'choices' => [ 'Small – short hair' => 650, 'Large – long hair & braids' => 750 ] ],
		'roles' => [ 'doctors-surgeons', 'nurses-midwives', 'dental-allied-health' ], 'spec' => 'Match Your Scrubs',
		'desc' => 'Solid-colour theatre caps to match your scrubs. Small for short hair, large for long hair and braids. Back ties and mask buttons.',
		'fabric' => "Cotton. Back ties, mask buttons.\n\nMachine wash warm with similar colours. Air dry.",
		'upsells' => [ 'cherokee-scrubs' ],
	],
	[
		'slug' => 'dental-cap', 'name' => 'Dental Cap', 'cat' => 'theatre-caps', 'price' => 750,
		'colours' => [ 'Printed' ],
		'roles' => [ 'dental-allied-health', 'doctors-surgeons' ], 'spec' => 'Unisex',
		'desc' => 'A snug, unisex dental cap with a black band lining the edge for a neat fit under loupes and face shields.',
		'fabric' => "Cotton with a black-lined band.\n\nMachine wash warm. Air dry.",
		'upsells' => [ 'white-stretch-scrubs' ],
	],
	[
		'slug' => 'black-clogs', 'name' => 'Black Anti-Skid Clogs', 'cat' => 'footwear', 'price' => 2200,
		'colours' => [ 'Black' ], 'sizes' => $SHOES,
		'roles' => [ 'doctors-surgeons', 'nurses-midwives', 'lab-diagnostics' ], 'spec' => 'Anti-Skid', 'featured' => true,
		'desc' => 'Imported black clogs with an anti-skid sole and a comfortable cushioned inner sole for long hours on your feet. Easy to wipe clean. EU sizes 37–44.',
		'fabric' => "Moulded, wipe-clean upper. Anti-skid sole, cushioned insole. EU sizes 37–44.\n\nRinse or wipe with warm soapy water. Keep out of direct heat.",
		'upsells' => [ 'cherokee-scrubs' ],
	],
	[
		'slug' => 'anno-clogs', 'colour_img' => [ 'Pink' => 0, 'Mint' => 1 ], 'name' => 'ANNO Theatre Clogs', 'cat' => 'footwear', 'price' => 1600,
		'colours' => [ 'Pink', 'Mint' ], 'sizes' => $SHOES,
		'roles' => [ 'nurses-midwives', 'doctors-surgeons', 'dental-allied-health' ], 'spec' => 'Lightweight',
		'desc' => 'Light, closed-toe ANNO clogs with ventilation holes, in soft pink and mint. Comfortable for theatre and ward shifts.',
		'fabric' => "Lightweight moulded clog with ventilation holes.\n\nRinse with warm soapy water. Keep out of direct heat.",
		'upsells' => [ 'coral-pink-scrubs' ],
	],
	[
		'slug' => 'theatre-boots', 'name' => 'White Theatre Boots', 'cat' => 'footwear', 'price' => 1100,
		'colours' => [ 'White' ], 'sizes' => $SHOES,
		'roles' => [ 'doctors-surgeons', 'nurses-midwives' ], 'spec' => 'Theatre Wear',
		'desc' => 'Knee-high white rubber boots for theatre, maternity and wet areas. Waterproof and easy to disinfect.',
		'fabric' => "Waterproof PVC.\n\nWash and disinfect as per your facility's protocol.",
		'upsells' => [ 'theatre-cap-plain' ],
	],
	[
		'slug' => 'student-stethoscope', 'colour_img' => [ 'Black' => 0, 'Orange' => 1 ], 'name' => 'Student Stethoscope', 'cat' => 'medical-accessories', 'price' => 900,
		'colours' => [ 'Black', 'Orange', 'Blue', 'Purple', 'Gray' ],
		'roles' => [ 'clinical-students', 'nurses-midwives' ], 'spec' => 'Student Pick', 'featured' => true,
		'desc' => 'A lightweight dual-head stethoscope for students starting their clinical rotations. Available in five colours.',
		'fabric' => "Dual-head chest piece, single tube.\n\nWipe with an alcohol swab. Keep the tubing away from oils and direct heat.",
		'upsells' => [ 'pen-torch', 'tape-measure', 'lab-coat' ],
	],
	[
		'slug' => 'double-tube-stethoscope', 'name' => 'Double Tube Stethoscope', 'cat' => 'medical-accessories', 'price' => 2500,
		'colours' => [ 'Black', 'Sky Blue', 'Orange', 'Galaxy Blue', 'Pink', 'Green', 'Maroon', 'Navy Blue', 'Purple', 'Red' ],
		'roles' => [ 'doctors-surgeons', 'nurses-midwives', 'clinical-students' ], 'spec' => 'With Accessories',
		'desc' => 'A double-tube stethoscope for clearer sound, supplied with spare accessories. Choose from ten colours.',
		'fabric' => "Double tube, dual-head chest piece, spare ear tips and diaphragm.\n\nWipe with an alcohol swab. Keep the tubing away from oils and direct heat.",
		'upsells' => [ 'rechargeable-pen-torch', 'bp-machine' ],
	],
	[
		'slug' => 'pen-torch', 'name' => 'Pen Torch with Pupil Gauge', 'cat' => 'medical-accessories', 'price' => 650,
		'roles' => [ 'clinical-students', 'nurses-midwives', 'doctors-surgeons' ], 'spec' => 'Pupil Gauge',
		'desc' => 'A reliable pocket pen torch with a pupil gauge printed on the barrel. Runs on AAA batteries.',
		'fabric' => "Aluminium body, pocket clip, pupil gauge. Uses AAA batteries.",
		'upsells' => [ 'student-stethoscope', 'trauma-shears' ],
	],
	[
		'slug' => 'rechargeable-pen-torch', 'name' => 'Rechargeable Pen Torch', 'cat' => 'medical-accessories', 'price' => 900,
		'colours' => [ 'Black', 'Silver' ],
		'roles' => [ 'clinical-students', 'nurses-midwives', 'doctors-surgeons' ], 'spec' => 'USB Rechargeable',
		'desc' => 'A USB-rechargeable pen torch with a warm and a white light mode, so there are no batteries to replace.',
		'fabric' => "Metal body, pocket clip, USB charging.",
		'upsells' => [ 'double-tube-stethoscope' ],
	],
	[
		'slug' => 'nurse-fob-watch', 'name' => 'Metallic Nurse Fob Watch', 'cat' => 'medical-accessories', 'price' => 500,
		'colours' => [ 'Gold', 'Silver' ],
		'roles' => [ 'nurses-midwives', 'clinical-students' ], 'spec' => 'Pin-On',
		'desc' => 'A classic pin-on fob watch with a clear face and second hand for taking pulses. Metallic strap in gold or silver.',
		'fabric' => "Metal case and strap, pin-on clip, second hand.",
		'upsells' => [ 'nurse-uniform' ],
	],
	[
		'slug' => 'fetal-doppler', 'name' => 'Fetal Doppler', 'cat' => 'medical-accessories', 'price' => 6000,
		'roles' => [ 'nurses-midwives', 'doctors-surgeons' ], 'spec' => 'Digital Display',
		'desc' => 'A handheld fetal Doppler with a digital heart-rate display and built-in speaker, for antenatal checks.',
		'fabric' => "Handheld unit with probe and digital display.\n\nClean the probe after each use; do not immerse the unit.",
		'upsells' => [ 'bp-machine' ],
	],
	[
		'slug' => 'trauma-shears', 'colour_img' => [ 'Purple' => 0, 'Black' => 1 ], 'name' => 'Bandage Scissors / Trauma Shears', 'cat' => 'medical-accessories', 'price' => 550,
		'colours' => [ 'Purple', 'Black' ],
		'roles' => [ 'nurses-midwives', 'clinical-students', 'doctors-surgeons' ], 'spec' => 'Angled Blade',
		'desc' => 'Angled bandage scissors with a blunt tip for safely cutting dressings, tape and clothing.',
		'fabric' => "Stainless steel blades, plastic handles.\n\nClean and disinfect after use.",
		'upsells' => [ 'pen-torch' ],
	],
	[
		'slug' => 'tape-measure', 'name' => 'Medical Tape Measure', 'cat' => 'medical-accessories', 'price' => 200,
		'colours' => [ 'Blue', 'Green' ],
		'roles' => [ 'nurses-midwives', 'clinical-students' ], 'spec' => 'cm & inches',
		'desc' => 'A lightweight retractable tape measure with a plastic case and fibre tape, marked in centimetres and inches. Easy to clean.',
		'fabric' => "Plastic casing, fibre tape, cm and inches.",
		'upsells' => [ 'student-stethoscope' ],
	],
	[
		'slug' => 'bp-machine', 'name' => 'Digital Blood Pressure Machine', 'cat' => 'medical-accessories', 'price' => 3000,
		'roles' => [ 'nurses-midwives', 'doctors-surgeons', 'clinical-students' ], 'spec' => 'Battery Powered',
		'desc' => 'An automatic upper-arm blood pressure monitor with an English-language readout. Runs on batteries, so it works anywhere.',
		'fabric' => "Automatic upper-arm monitor with cuff. Runs on batteries.",
		'upsells' => [ 'double-tube-stethoscope' ],
	],
];

function sbl_attach_images( $pid, $slug, $name ) {
	$files = glob( "/wordpress/sbl-images/$slug*.webp" ) ?: [];
	$files = array_values( array_filter( $files, fn( $f ) => preg_match( '#/' . preg_quote( $slug, '#' ) . '(-\d+)?\.webp$#', $f ) ) );
	// slug.webp is the main photo, then slug-2.webp, slug-3.webp…
	$num = fn( $f ) => preg_match( '#-(\d+)\.webp$#', substr( $f, strlen( $slug ) ), $m ) ? (int) $m[1] : 1;
	usort( $files, fn( $a, $b ) => $num( basename( $a ) ) <=> $num( basename( $b ) ) );
	$ids = [];
	foreach ( $files as $i => $file ) {
		$base = basename( $file );
		$tmp  = wp_tempnam( $base );
		copy( $file, $tmp );
		$att = sbl_sideload( $tmp, $base, $pid, $name . ( $i ? ' – photo ' . ( $i + 1 ) : '' ) );
		if ( ! is_wp_error( $att ) ) $ids[] = $att;
	}
	return $ids;
}

$by_slug = [];
foreach ( $products as $order => $d ) {
	$is_var = ! empty( $d['options'] );
	$p = $is_var ? new WC_Product_Variable() : new WC_Product_Simple();
	$p->set_name( $d['name'] );
	$p->set_slug( $d['slug'] );
	$p->set_status( 'publish' );
	$p->set_menu_order( $order );
	$p->set_description( $d['desc'] );
	$p->set_short_description( $d['desc'] );
	$p->set_category_ids( [ $cat[ $d['cat'] ] ] );
	$p->set_tag_ids( array_map( fn( $r ) => $role[ $r ], $d['roles'] ) );
	$p->set_featured( ! empty( $d['featured'] ) );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 10 );
	if ( ! $is_var ) {
		$p->set_regular_price( $d['price'] );
		if ( ! empty( $d['sale'] ) ) $p->set_sale_price( $d['sale'] );
	}

	$attrs = [];
	$pos = 0;
	if ( ! empty( $d['colours'] ) ) $attrs[] = sbl_attribute( 'colour', $attr_ids['colour'], $d['colours'], $pos++ );
	if ( ! empty( $d['sizes'] ) ) $attrs[] = sbl_attribute( 'size', $attr_ids['size'], $d['sizes'], $pos++ );
	if ( $is_var ) {
		$a = new WC_Product_Attribute();
		$a->set_name( $d['options']['label'] );
		$a->set_options( array_keys( $d['options']['choices'] ) );
		$a->set_position( $pos++ );
		$a->set_visible( true );
		$a->set_variation( true );
		$attrs[] = $a;
	}
	$p->set_attributes( $attrs );
	$p->update_meta_data( '_sbl_spec', $d['spec'] );
	$p->update_meta_data( '_sbl_fabric', $d['fabric'] );
	if ( ! empty( $d['colour_img'] ) ) $p->update_meta_data( '_sbl_colour_images', $d['colour_img'] );
	$pid = $p->save();

	if ( $is_var ) {
		$key = sanitize_title( $d['options']['label'] );
		$first = true;
		foreach ( $d['options']['choices'] as $label => $price ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $pid );
			$v->set_attributes( [ $key => $label ] );
			$v->set_regular_price( $price );
			$v->set_manage_stock( false ); // Uses the parent product's stock.
			$v->set_status( 'publish' );
			$v->save();
			if ( $first ) {
				$p->set_default_attributes( [ $key => $label ] );
				$first = false;
			}
		}
		$p->save();
		WC_Product_Variable::sync( $pid );
	}

	$imgs = sbl_attach_images( $pid, $d['slug'], $d['name'] );
	if ( $imgs ) {
		set_post_thumbnail( $pid, array_shift( $imgs ) );
		// Written as meta: a second full product save here slowed the import.
		if ( $imgs ) update_post_meta( $pid, '_product_image_gallery', implode( ',', $imgs ) );
	}
	$by_slug[ $d['slug'] ] = $pid;
}

// "Complete the set" suggestions.
foreach ( $products as $d ) {
	if ( empty( $d['upsells'] ) ) continue;
	// Written as meta: a full product save per product here slowed the import.
	update_post_meta( $by_slug[ $d['slug'] ], '_upsell_ids', array_values( array_filter( array_map( fn( $s ) => $by_slug[ $s ] ?? 0, $d['upsells'] ) ) ) );
}

// Pages.
function sbl_page( $slug, $title, $content ) {
	$existing = get_page_by_path( $slug );
	if ( $existing ) return $existing->ID;
	return wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content ] );
}
sbl_page( 'wishlist', 'Your wishlist', "<!-- wp:shortcode -->\n[sbl_wishlist]\n<!-- /wp:shortcode -->" );
sbl_page( 'size-guide', 'Size guide', "<!-- wp:shortcode -->\n[sbl_size_guide]\n<!-- /wp:shortcode -->" );
sbl_page( 'sale', 'Sale', '<!-- wp:paragraph --><p>Limited-time prices on selected scrubs and accessories, while stocks last.</p><!-- /wp:paragraph -->
<!-- wp:woocommerce/product-collection {"queryId":21,"query":{"perPage":12,"pages":1,"offset":0,"postType":"product","order":"asc","orderBy":"menu_order","search":"","exclude":[],"inherit":false,"taxQuery":[],"isProductCollectionBlock":true,"woocommerceOnSale":true,"woocommerceStockStatus":["instock","outofstock","onbackorder"],"woocommerceAttributes":[],"woocommerceHandPickedProducts":[]},"tagName":"div","displayLayout":{"type":"flex","columns":4,"shrinkColumns":true},"dimensions":{"widthType":"fill"},"collection":"woocommerce/product-collection/on-sale","hideControls":["inherit","on-sale"],"queryContextIncludes":["collection"],"align":"wide"} -->
<div class="wp-block-woocommerce-product-collection alignwide">
<!-- wp:woocommerce/product-template -->
<!-- wp:woocommerce/product-image {"showSaleBadge":false,"isDescendentOfQueryLoop":true,"aspectRatio":"4/5","scale":"cover"} -->
<!-- wp:woocommerce/product-sale-badge {"isDescendentOfQueryLoop":true,"align":"right"} /-->
<!-- /wp:woocommerce/product-image -->
<!-- wp:post-title {"level":3,"isLink":true,"style":{"spacing":{"margin":{"bottom":"0.2rem","top":"0.7rem"}},"typography":{"fontSize":"1rem","lineHeight":"1.3","fontWeight":"700"}},"__woocommerceNamespace":"woocommerce/product-collection/product-title"} /-->
<!-- wp:woocommerce/product-price {"isDescendentOfQueryLoop":true,"fontSize":"medium"} /-->
<!-- wp:woocommerce/product-button {"isDescendentOfQueryLoop":true,"fontSize":"small"} /-->
<!-- /wp:woocommerce/product-template -->
</div>
<!-- /wp:woocommerce/product-collection -->' );

update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

// Skip WooCommerce's first-run redirect and setup checklist so the admin opens on the store itself.
delete_transient( '_wc_activation_redirect' );
update_option( 'woocommerce_task_list_hidden_lists', [ 'setup', 'extended' ] );
update_option( 'woocommerce_task_list_complete', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
update_option( 'woocommerce_admin_install_timestamp', time() - WEEK_IN_SECONDS );

$wpdb->query( 'COMMIT' );
wp_suspend_cache_invalidation( false );
wp_defer_term_counting( false );
