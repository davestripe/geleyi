<?php

/**
 * WP-Admin users page
 *
 * @author  Matt Gates <http://mgates.me>
 * @package ProductVendor
 */


class PV_Admin_Users
{


	/**
	 * Constructor
	 */
	function __construct()
	{
		if ( !is_admin() ) return;

		add_action( 'edit_user_profile', array( $this, 'show_extra_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_extra_profile_fields' ) );

		add_filter( 'add_menu_classes', array( $this, 'show_pending_number' ) );
		// add_filter( 'get_terms', array( $this, 'get_terms_filter' ), 10, 3 );

		// Displaying users by the Vendor role
		if ( !empty( $_GET[ 'role' ] ) && $_GET[ 'role' ] == 'vendor' ) {
			add_action( 'manage_users_columns', array( $this, 'manage_users_columns' ) );
			add_action( 'manage_users_custom_column', array( $this, 'custom_manage_users_column_value' ), 10, 3 );
			add_filter( 'manage_users_sortable_columns', array( $this, 'custom_sortable' ) );
		}

		// Disabling non-vendor related items on the admin screens
		if ( PV_Vendors::is_vendor( get_current_user_id() ) ) {
			add_filter( 'woocommerce_csv_product_role', array( $this, 'csv_import_suite_compatibility' ) );
			add_filter( 'woocommerce_csv_product_export_args', array( $this, 'csv_import_suite_compatibility_export' ) );

			// Admin page lockdown
			remove_action( 'admin_init', 'woocommerce_prevent_admin_access' );
			add_action( 'admin_init', array( $this, 'prevent_admin_access' ) );

			add_filter( 'woocommerce_prevent_admin_access', array( $this, 'deny_admin_access' ) );


			// WC > Product page fixes
			add_action( 'load-edit.php', array( $this, 'edit_nonvendors' ) );
			add_filter( 'views_edit-product', array( $this, 'hide_nonvendor_links' ) );

			add_action( 'pre_get_posts', array( $this, 'users_own_attachments' ) );
			add_action( 'admin_menu', array( $this, 'remove_menu_page' ), 99 );
			add_action( 'add_meta_boxes', array( $this, 'remove_meta_boxes' ), 99 );
			add_filter( 'product_type_selector', array( $this, 'filter_product_types' ), 99, 2 );
			add_filter( 'product_type_options', array( $this, 'filter_product_type_options' ), 99 );

			add_filter( 'woocommerce_duplicate_product_capability', array( $this, 'add_duplicate_capability' ) );
		}

	}

	// public function get_terms_filter( $terms, $tax, $args )
	// {
	// 	if ( $tax[0] != 'product_type' || ( $tax[0] == 'product_type' && ! empty( $args['include'] ) ) ) {
	// 		return $terms;
	// 	}

	// 	$products = PV_Vendors::get_vendor_products( get_current_user_id() );
	// 	$ids = array();
	// 	foreach ( $products as $product ) {
	// 		$ids[ ] = ( $product->ID );
	// 		$product = get_product( $product )->product_type;
	// 		var_dump($product);exit;
	// 	}

	// 	$args['include'] = $ids;

	// 	var_dump($terms);exit;

	// 	$terms = get_terms( $tax[0], $args);


	// 	return $terms;
	// }

	public function csv_import_suite_compatibility( $capability )
	{
		return 'manage_product';
	}

	public function csv_import_suite_compatibility_export( $args )
	{
		$args[ 'author' ] = get_current_user_id();

		return $args;
	}

	public function add_duplicate_capability( $capability )
	{
		return 'manage_product';
	}


	/**
	 *
	 *
	 * @param unknown $menu
	 *
	 * @return unknown
	 */
	public function show_pending_number( $menu )
	{
		$num_posts = wp_count_posts( 'product', 'readable' );

		$pending_count = !empty( $num_posts->pending ) ? $num_posts->pending : 0;
		$menu_str      = 'edit.php?post_type=product';

		foreach ( $menu as $menu_key => $menu_data ) {
			if ( $menu_str != $menu_data[ 2 ] ) continue;
			$menu[ $menu_key ][ 0 ] .= " <span class='update-plugins count-$pending_count'><span class='plugin-count'>" . number_format_i18n( $pending_count ) . '</span></span>';
		}

		return $menu;
	}


	/**
	 *
	 *
	 * @param unknown $types
	 * @param unknown $product_type
	 *
	 * @return unknown
	 */
	function filter_product_types( $types, $product_type )
	{
		$product_panel = (array) Product_Vendor::$pv_options->get_option( 'hide_product_panel' );
		$product_misc  = (array) Product_Vendor::$pv_options->get_option( 'hide_product_misc' );
		$product_types = (array) Product_Vendor::$pv_options->get_option( 'hide_product_types' );
		$css           = Product_Vendor::$pv_options->get_option( 'product_page_css' );
		$count         = 0;

		foreach ( $product_panel as $key => $value ) {
			if ( $value ) $css .= sprintf( '.%s_tab{display:none !important;}', $key );
		}

		if ( !empty( $product_misc[ 'taxes' ] ) ) {
			$css .= '.form-field._tax_status_field, .form-field._tax_class_field{display:none !important;}';
		}

		unset( $product_misc[ 'taxes' ] );

		foreach ( $product_misc as $key => $value ) {
			if ( $value ) $css .= sprintf( '._%s_field{display:none !important;}', $key );
		}

		foreach ( $product_types as $value ) {
			if ( !$value ) $count++;
		}

		if ( $count === 1 ) {
			$css .= '#product-type{display:none !important;}';
		}

		echo '<style>';
		echo $css;
		echo '</style>';

		foreach ( $types as $key => $value ) {
			if ( !empty( $product_types[ $key ] ) ) {
				unset( $types[ $key ] );
			}
		}

		return $types;
	}


	/**
	 *
	 *
	 * @param unknown $types
	 *
	 * @return unknown
	 */
	function filter_product_type_options( $types )
	{
		$product_options = Product_Vendor::$pv_options->get_option( 'hide_product_type_options' );

		if ( !$product_options ) return $types;

		foreach ( $types as $key => $value ) {
			if ( !empty( $product_options[ $key ] ) ) {
				unset( $types[ $key ] );
			}
		}

		return $types;
	}


	/**
	 * Show attachments only belonging to vendor
	 *
	 * @param object $wp_query_obj
	 */
	function users_own_attachments( $wp_query_obj )
	{
		global $current_user, $pagenow;

		if ( $pagenow == 'upload.php' || ( $pagenow == 'admin-ajax.php' && !empty( $_POST[ 'action' ] ) && $_POST[ 'action' ] == 'query-attachments' ) ) {
			$wp_query_obj->set( 'author', $current_user->ID );
		}
	}


	/**
	 * Allow vendors to access admin when disabled
	 */
	public function prevent_admin_access()
	{
		$permitted_user = ( current_user_can( 'edit_posts' ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'vendor' ) );

		if ( get_option( 'woocommerce_lock_down_admin' ) == 'yes' && !is_ajax() && !$permitted_user ) {
			wp_safe_redirect( get_permalink( woocommerce_get_page_id( 'myaccount' ) ) );
			exit;
		}
	}

	public function deny_admin_access()
	{
		return false;
	}


	/**
	 * Request when load-edit.php
	 */
	public function edit_nonvendors()
	{
		add_action( 'request', array( $this, 'hide_nonvendor_products' ) );
	}


	/**
	 * Hide links that don't matter anymore from vendors
	 *
	 * @param array $views
	 *
	 * @return array
	 */
	public function hide_nonvendor_links( $views )
	{
		return array();
	}


	/**
	 * Hide products that don't belong to the vendor
	 *
	 * @param array $query_vars
	 *
	 * @return array
	 */
	public function hide_nonvendor_products( $query_vars )
	{
		$query_vars[ 'author' ] = get_current_user_id();

		return $query_vars;
	}


	/**
	 * Remove the media library menu
	 */
	public function remove_menu_page()
	{
		global $pagenow;

		remove_menu_page( 'index.php' ); /* Hides Dashboard menu */
		remove_menu_page( 'separator1' ); /* Hides separator under Dashboard menu*/
		remove_all_actions( 'admin_notices' );

		if ( $pagenow == 'index.php' ) {
			wp_redirect( admin_url( 'admin.php?page=woocommerce_reports' ) );
			exit();
		}
	}


	/**
	 *
	 */
	public function remove_meta_boxes()
	{
		remove_meta_box( 'postcustom', 'product', 'normal' );
		remove_meta_box( 'wpseo_meta', 'product', 'normal' );
		remove_meta_box( 'expirationdatediv', 'product', 'side' );
	}


	/**
	 * Update the vendor PayPal email
	 *
	 * @param int $vendor_id
	 *
	 * @return bool
	 */
	public function save_extra_profile_fields( $vendor_id )
	{
		if ( !current_user_can( 'edit_user', $vendor_id ) ) return false;

		$users = get_users( array( 'meta_key' => 'pv_shop_slug', 'meta_value' => sanitize_title( $_POST[ 'pv_shop_name' ] ) ) );
		if ( empty( $users ) || $users[ 0 ]->ID == $vendor_id ) {
			update_user_meta( $vendor_id, 'pv_shop_name', $_POST[ 'pv_shop_name' ] );
			update_user_meta( $vendor_id, 'pv_shop_slug', sanitize_title( $_POST[ 'pv_shop_name' ] ) );
		}

		update_user_meta( $vendor_id, 'pv_paypal', $_POST[ 'pv_paypal' ] );
		update_user_meta( $vendor_id, 'pv_shop_html_enabled', isset( $_POST[ 'pv_shop_html_enabled' ] ) );
		update_user_meta( $vendor_id, 'pv_total_due', $_POST[ 'pv_total_due' ] );
		update_user_meta( $vendor_id, 'pv_shop_description', $_POST[ 'pv_shop_description' ] );
		update_user_meta( $vendor_id, 'pv_seller_info', $_POST[ 'pv_seller_info' ] );

		do_action( 'wc_product_vendor_update_admin_user', $vendor_id );
	}


	/**
	 * Show the PayPal field and commision due table
	 *
	 * @param unknown $user
	 */
	public function show_extra_profile_fields( $user )
	{
		?>
		<h3><?php _e( 'Product Vendor', 'wc_product_vendor' ); ?></h3>
		<table class="form-table">
			<tbody>
			<?php do_action( 'wc_product_vendor_admin_before_shop_html', $user ); ?>
			<tr>
				<th scope="row">Shop HTML</th>
				<td>
					<label for="pv_shop_html_enabled">
						<input name="pv_shop_html_enabled" type="checkbox"
							   id="pv_shop_html_enabled" <?php checked( true, get_user_meta( $user->ID, 'pv_shop_html_enabled', true ), $echo = true ) ?>/>
						<?php _e( 'Enable HTML for the shop description', 'wc_product_vendor' ); ?>
					</label>
				</td>
			</tr>
			<?php do_action( 'wc_product_vendor_admin_after_shop_html', $user ); ?>
			<tr>
				<th><label for="pv_shop_name"><?php _e( 'Shop name', 'wc_product_vendor' ); ?></label></th>
				<td><input type="text" name="pv_shop_name" id="pv_shop_name"
						   value="<?php echo get_user_meta( $user->ID, 'pv_shop_name', true ); ?>" class="regular-text">
				</td>
			</tr>
			<?php do_action( 'wc_product_vendor_admin_after_shop_name', $user ); ?>
			<tr>
				<th><label for="pv_paypal"><?php _e( 'PayPal E-mail', 'wc_product_vendor' ); ?> <span
							class="description">(<?php _e( 'required', 'wc_product_vendor' ); ?>)</span></label></th>
				<td><input type="email" name="pv_paypal" id="pv_paypal"
						   value="<?php echo get_user_meta( $user->ID, 'pv_paypal', true ); ?>" class="regular-text">
				</td>
			</tr>
			<?php do_action( 'wc_product_vendor_admin_after_paypal', $user ); ?>
			<tr>
				<th><label for="pv_total_due"><?php _e( 'Commission due', 'wc_product_vendor' ); ?></label></th>
				<td><input type="number" step="0.01" name="pv_total_due" id="pv_total_due"
						   value="<?php echo get_user_meta( $user->ID, 'pv_total_due', true ); ?>" class="regular-text">
				</td>
			</tr>
			<?php do_action( 'wc_product_vendor_admin_after_commission_due', $user ); ?>
			<tr>
				<th><label for="pv_seller_info"><?php _e( 'Seller info', 'wc_product_vendor' ); ?></label></th>
				<td><?php wp_editor( get_user_meta( $user->ID, 'pv_seller_info', true ), 'pv_seller_info' ); ?></td>
			</tr>
			<?php do_action( 'wc_product_vendor_admin_after_seller_info', $user ); ?>
			<tr>
				<th><label for="pv_shop_description"><?php _e( 'Shop description', 'wc_product_vendor' ); ?></label>
				</th>
				<td><?php wp_editor( get_user_meta( $user->ID, 'pv_shop_description', true ), 'pv_shop_description' ); ?></td>
			</tr>
			<?php do_action( 'wc_product_vendor_admin_after_shop_description', $user ); ?>
			</tbody>
		</table>
	<?php
	}


	/**
	 * Append 'Commision Due' column
	 *
	 * @param array $column_headers
	 *
	 * @return array
	 */
	public function manage_users_columns( $column_headers )
	{
		$column_headers[ 'due_commission' ] = __( 'Commission Due', 'wc_product_vendor' );

		return $column_headers;
	}


	/**
	 * Show the 'Commission Due' column's value for a user
	 *
	 * @param float  $value
	 * @param string $name
	 * @param int    $user_id
	 *
	 * @return float
	 */
	public function custom_manage_users_column_value( $value, $name, $user_id )
	{
		if ( $name == 'due_commission' ) {
			$total_due = get_user_meta( $user_id, 'pv_total_due', true );
			$value     = !empty( $total_due ) ? woocommerce_price( $total_due ) : '';
		}

		return $value;
	}


	/**
	 * Allow sorting the 'Commission Due' column
	 *
	 * @param array $columns
	 *
	 * @return array
	 */
	public function custom_sortable( $columns )
	{
		$columns[ 'due_commission' ] = 'due_commission';

		return $columns;
	}


}
