<?php
/*
   Plugin Name: WooCommerce Regional Pricing
   Plugin URI: http://wordpress.org/extend/plugins/woocommerce-regional-pricing/
   Version: 0.1
   Author: Ray Flores
   Description: Determine discounts per region, backend input, frontend dynamic pricing.
   Text Domain: woocommerce-regional-pricing
   License: GPLv3
*/

class WCRP_Admin {
	/**
 	 * Option key, and option page slug
 	 * @var string
 	 */
	private $key = 'wcrp_options';
	/**
 	 * Options page metabox id
 	 * @var string
 	 */
	private $metabox_id = 'wcrp_option_metabox';
	/**
	 * Options Page title
	 * @var string
	 */
	protected $title = '';
	/**
	 * Options Page hook
	 * @var string
	 */
	protected $options_page = '';
	/**
	 * Holds an instance of the object
	 *
	 * @var Myprefix_Admin
	 **/
	private static $instance = null;
	
	private $price_had = false;
	/**
	 * Constructor
	 * @since 0.1.0
	 */
	private function __construct() {
		// Set our title
		$this->title = __( 'Discounts By Region', 'wcrp' );
		register_activation_hook( __FILE__, array( $this, 'cmb2_plugin_activate' ) );

		// ADD ACTIONS HERE
		add_action('wp_enqueue_scripts',array ($this, 'wcrp_enqueue_scripts') );
		if (!is_admin()){
		add_action('wp_footer', array ( $this, 'add_modal_to_footer') );
		//add_action('woocommerce_after_shop_loop_item', array( $this, 'wcrp_show_price_or_button'), 11, 2 );
		add_filter('woocommerce_get_price_html', array( $this, 'wcrp_show_price_or_button'), 10, 2 );
		add_filter('woocommerce_variation_price_html', array($this, 'wcrp_get_variation_prices'), 10, 2); // the variation prices
		
		
		add_action('woocommerce_cart_loaded_from_session', array($this, 'wcrp_apply_discounts'), 200, 1);
		// Change prices and totals in cart
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'wcrp_set_org_price'), 200, 1 );
		
		
		add_filter('woocommerce_cart_item_price', array($this, 'wcrp_display_custom_cart_price'), 200, 3);
		add_filter('woocommerce_cart_item_subtotal', array($this, 'wcrp_display_discounted_item_subtotal'), 10, 3 );
		add_filter( 'woocommerce_cart_subtotal', array($this, 'wcrp_modify_cart_subtotal'),10, 3); 
		
		//  Alter the display and the serve of the total tax amount. $taxes['amount']
		add_filter( 'woocommerce_cart_tax_totals', array($this, 'wcrp_modify_cart_display_taxes'), 10, 2);

		// Allow plugins to hook and alter totals before final total is calculated
		add_action('woocommerce_calculate_totals', array($this, 'wcrp_modify_cart_totals'), 10, 1);
		}

		// Add new product fields
		// Display Fields
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'wcrp_add_custom_general_fields' ) );
		// Save Fields
		add_action( 'woocommerce_process_product_meta', array( $this, 'wcrp_add_custom_general_fields_save' ), 10, 2 );
		
		// set and get postal code cookie
		add_action( 'wp_ajax_rf_update_price', array($this, 'rf_update_price_on_ajax' ) );
		add_action( 'wp_ajax_nopriv_rf_update_price', array($this, 'rf_update_price_on_ajax') );
		add_action('woocommerce_ajax_added_to_cart', array($this, 'wcrp_refresh_the_mini_cart') );
		
		//testing
		add_action('woocommerce_before_cart_table', array($this, 'wcrp_before_cart_table'));
		// add_action('woocommerce_before_mini_cart', array($this, 'hereinhere') );
		
	}
	function wcrp_before_cart_table(){
		foreach (WC()->cart->cart_contents as $cart_item_key => $cart_item){
			$product = $cart_item['data'];
			$product_id = isset($cart_item['data']->variation_id) ? $cart_item['data']->parent->id : $cart_item['data']->product_id;
			$optedout = get_post_meta($product_id, '_wcrp_checkbox', true);
			//print_r($optedout);
			
		}
	}
	//  change the display of the taxes in cart
	// @ woocommerce_cart_tax_totals hook
	public function wcrp_modify_cart_display_taxes( $tax_totals, $cart ){
		$tax_rates = array();
		$total_items_total = 0;
		$cart_tax_totals = 0;
		$reg_cart_tax_totals = 0;
		$cart_shipping_taxes = $cart->shipping_tax_total;
		foreach ($cart->cart_contents as $cart_item_key => $cart_item){
			
			$product_id = isset($cart_item['data']->variation_id) ? $cart_item['data']->parent->id : $cart_item['data']->product_id;
			$optedout = get_post_meta($product_id, '_wcrp_checkbox', true);
			
			if ($optedout === 'yes' ){
				$org_price = $cart_item['data']->price;
				$item_quantity = $cart_item['quantity'];
				$reg_item_subtotal = $org_price * $item_quantity;
				// taxes
				$tax_rates[ $cart_item['data']->get_tax_class() ] = WC_Tax::get_rates( $cart_item['data']->get_tax_class() );
				$item_tax_rates = $tax_rates[ $cart_item['data']->get_tax_class() ];
				$tax_result  = WC_Tax::calc_tax( $reg_item_subtotal, $item_tax_rates );
				$reg_line_subtotal_tax     = array_sum( $tax_result );
				$reg_cart_tax_totals += $reg_line_subtotal_tax;
						 
			} else {
					
			
				$percentage = $this->percentage_get();
				$item_quantity = $cart_item['quantity'];
				$product_id = isset($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
				$product = wc_get_product($product_id);
				//$item_tax_rates = $tax_rates[ $product->get_tax_class() ];
				$rp_discounted_price = $cart_item['data']->price; // 94  
				$wcrp_org_price = $cart_item['wcrp_org_price']; // 104
				$discount = $wcrp_org_price - $rp_discounted_price; // 10
				$wcrp_price   = $wcrp_org_price - ($wcrp_org_price*($percentage/100)); // 89.02
				$wcrp_adjusted_price = $wcrp_price - $discount; // 79.02
				
				$item_subtotal = $wcrp_adjusted_price * $item_quantity;
				$total_items_total += $item_subtotal;
				
				// taxes
				$tax_rates[ $cart_item['data']->get_tax_class() ] = WC_Tax::get_rates( $cart_item['data']->get_tax_class() );
				$item_tax_rates = $tax_rates[ $cart_item['data']->get_tax_class() ];
				$tax_result  = WC_Tax::calc_tax( $item_subtotal, $item_tax_rates );
				$line_subtotal_tax     = array_sum( $tax_result );
				$cart_tax_totals += $line_subtotal_tax;
			}
		
		
		$taxes      = $cart->get_taxes();
		$tax_totals = array();

			foreach ( $taxes as $key => $tax ) {
				$code = WC_Tax::get_rate_code( $key );

				if ( $code || $key === apply_filters( 'woocommerce_cart_remove_taxes_zero_rate_id', 'zero-rated' ) ) {
					if ( ! isset( $tax_totals[ $code ] ) ) {
						$tax_totals[ $code ] = new stdClass();
						$tax_totals[ $code ]->amount = 0;
					}
					$tax_totals[ $code ]->tax_rate_id       = $key;
					$tax_totals[ $code ]->is_compound       = WC_Tax::is_compound( $key );
					$tax_totals[ $code ]->label             = WC_Tax::get_rate_label( $key );
					$tax_totals[ $code ]->amount           += wc_round_tax_total( $cart_tax_totals + $reg_cart_tax_totals + $cart_shipping_taxes ); // here
					$tax_totals[ $code ]->formatted_amount  = wc_price( wc_round_tax_total( $tax_totals[ $code ]->amount ) );
				}
			}

			if ( apply_filters( 'woocommerce_cart_hide_zero_taxes', true ) ) {
				$amounts    = array_filter( wp_list_pluck( $tax_totals, 'amount' ) );
				$tax_totals = array_intersect_key( $tax_totals, $amounts );
			}
		}
		
		return $tax_totals ;
	}
	// Allow plugins to hook and alter totals before final total is calculated
	// @ woocommerce_calculate_totals  hook
	public function wcrp_modify_cart_totals( $cart ){
		//print_r($cart->cart_contents);
		$tax_rates = array();
		$total_items_total = 0;
		$cart_tax_totals = 0;
		$reg_cart_tax_totals = 0;
		$reg_line_subtotal_tax = 0;
		$reg_total_items_total = 0;
		foreach ($cart->cart_contents as $cart_item_key => $cart_item){
			
			
			$product_id = isset($cart_item['product_id']->variation_id) ? $cart_item['product_id'] : $cart_item['data']->id;
			$optedout = get_post_meta($product_id, '_wcrp_checkbox', true);
			if ($optedout === 'yes'){

				$reg_item_subtotal = $cart_item['data']->price * $cart_item['quantity'];
				
				$reg_total_items_total += $reg_item_subtotal;
				
				// taxes
				$tax_rates[ $cart_item['data']->get_tax_class() ] = WC_Tax::get_rates( $cart_item['data']->get_tax_class() );
				$item_tax_rates = $tax_rates[ $cart_item['data']->get_tax_class() ];
				$tax_result  = WC_Tax::calc_tax( $reg_item_subtotal, $item_tax_rates );
				$reg_line_subtotal_tax     = array_sum( $tax_result );
				$reg_cart_tax_totals += $reg_line_subtotal_tax; // 28.6
			} else {

				$percentage = $this->percentage_get();
				$item_quantity = $cart_item['quantity'];
				$product_id = isset($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
				$product = wc_get_product($product_id);
				//$item_tax_rates = $tax_rates[ $product->get_tax_class() ];
				$rp_discounted_price = $cart_item['data']->price; // 94  
				$wcrp_org_price = $cart_item['wcrp_org_price']; // 104
				$discount = $wcrp_org_price - $rp_discounted_price; // 10
				$wcrp_price   = $wcrp_org_price - ($wcrp_org_price*($percentage/100)); // 89.02
				$wcrp_adjusted_price = $wcrp_price - $discount; // 79.02
				
				$item_subtotal = $wcrp_adjusted_price * $item_quantity;
				$total_items_total += $item_subtotal;
				
				// taxes
				$tax_rates[ $cart_item['data']->get_tax_class() ] = WC_Tax::get_rates( $cart_item['data']->get_tax_class() );
				$item_tax_rates = $tax_rates[ $cart_item['data']->get_tax_class() ];
				$tax_result  = WC_Tax::calc_tax( $item_subtotal, $item_tax_rates );
				$line_subtotal_tax     = array_sum( $tax_result );
				$cart_tax_totals += $line_subtotal_tax;
				//print_r($cart_tax_totals);
			}
			
		}
		
		
		$cart->tax_total = $cart_tax_totals + $reg_cart_tax_totals ;//+ $cart->shipping_tax_total;
		
		$cart->cart_contents_total = $reg_total_items_total + $total_items_total;
		
	}
	
	// cart session AFTER discounts applied priorty 200
	// @ woocommerce_cart_loaded_from_session hook
	public function wcrp_apply_discounts( $cart ){
		foreach($cart->cart_contents as $cart_item_key => $cart_item){
			$new_price = $cart_item['data']->price;
			//$cart_item['data']->set_price($new_price);
		}
		
	}
		
	// change the display of the item subtotal to our new total
	// @ woocommerce_cart_item_subtotal hook
	public function wcrp_display_discounted_item_subtotal( $item_subtotal, $cart_item, $cart_item_key ){
		//print_r($cart_item);
		$product_id = isset($cart_item['product_id']) ? $cart_item['product_id'] : $cart_item['variation_id'];
			$optedout = get_post_meta($product_id, '_wcrp_checkbox', true);
	
			//print_r($optedout);
			if ($optedout === 'yes'){
				 return $item_subtotal; 
			} else { 
				//$item_subtotal = 0;
				$percentage = $this->percentage_get();
				$item_quantity = $cart_item['quantity'];
				$rp_discounted_price = $cart_item['data']->price; // 94  something whacky here
				$wcrp_org_price = isset($cart_item['rp_wcdpd']['original_price']) ? $cart_item['rp_wcdpd']['original_price'] : $cart_item['wcrp_org_price']; // 104
				$discount = $wcrp_org_price - $rp_discounted_price; // 10
				$wcrp_price   = $wcrp_org_price - ($wcrp_org_price*($percentage/100)); // 89.02
				$wcrp_adjusted_price = $wcrp_price - $discount; // 79.02
				$wcrp_item_subtotal = $wcrp_adjusted_price * $item_quantity;
				$item_subtotal = wc_price($wcrp_item_subtotal);
				//print_r($cart_item);
				 return $item_subtotal;
			}
		
		//return $item_subtotal;
	}
	
	// change the display of the cart subtotal to our new subtotal
	// @ woocommerce_cart_subtotal  hook 
	public function wcrp_modify_cart_subtotal( $cart_subtotal, $compound, $cart ){
	
		$percentage = $this->percentage_get();
		$bulk_cart_subtotal = 0;
		$wcrp_cart_subtotal = 0;
		foreach ($cart->cart_contents as $cart_item_key => $cart_item){
			$product_id = isset($cart_item['product_id']) ? $cart_item['product_id'] : $cart_item['variation_id'];
			$optedout = get_post_meta($product_id, '_wcrp_checkbox', true);
			
			//print_r($cart_item);
			
			if ($optedout === 'yes'){
				$item_quantity = $cart_item['quantity'];
				$wcrp_org_price = $cart_item['wcrp_org_price']; // 104
				$bulk_cart_subtotal += $item_quantity * $wcrp_org_price; 
				
			} else {
			
				$item_quantity = $cart_item['quantity'];
				$rp_discounted_price = $cart_item['data']->price; // 94  something whacky here
				$wcrp_org_price = isset($cart_item['rp_wcdpd']['original_price']) ? $cart_item['rp_wcdpd']['original_price'] : $cart_item['wcrp_org_price']; // 104
				$discount = $wcrp_org_price - $rp_discounted_price; // 10
				$wcrp_price   = $wcrp_org_price - ($wcrp_org_price*($percentage/100)); // 89.02
				$wcrp_adjusted_price = $wcrp_price - $discount; // 79.02
				$item_subtotal = $wcrp_adjusted_price * $item_quantity;
				
				$wcrp_cart_subtotal += $item_subtotal;
			}
		}
		$new_cart_subtotal = $bulk_cart_subtotal + $wcrp_cart_subtotal;
		return wc_price($new_cart_subtotal);
	}
	
	// set orginal price BEFORE calculations 
	// woocommerce_before_calculate_totals
	public function wcrp_set_org_price( $cart ) {
		
		$cart_contents_total = '';
		$percentage = $this->percentage_get();
		foreach ($cart->cart_contents as $cart_item_key => $cart_item ){

					$wcrp_price = isset($cart->cart_contents[$cart_item_key]['wcrp_org_price']) ? $cart->cart_contents[$cart_item_key]['wcrp_org_price'] : $cart_item['data']->price;
					$rp_price = isset($cart_item['rp_wcdpd']['original_price']) ? $cart_item['rp_wcdpd']['original_price'] : 0; //$cart_item['data']->price;
					$cart->cart_contents[$cart_item_key]['wcrp_org_price'] = $wcrp_price; // first price 104

				}
				
				// set subtotals here if first time? 	
				//$cart->cart_contents_total = $cart->cart_contents_total + $cart_contents_total;
   
	}
	
	// change the display of the price of the cart item to our new price
	// @ woocommerce_cart_item_price hook
	public function wcrp_display_custom_cart_price($item_price, $cart_item, $cart_item_key) {  
			$product_id = isset($cart_item['data']->variation_id) ? $cart_item['data']->parent->id : $cart_item['data']->product_id;
			$optedout = get_post_meta($product_id, '_wcrp_checkbox', true);
	
			if ($optedout === 'yes'){
					return wc_price($cart_item['data']->price); 
				} 
			if (!is_cart()){	
			$discounted_price = $cart_item['data']->price; // 94
			} else {
			$discounted_price = $cart_item['data']->price; // 104
			}
			$percentage = $this->percentage_get();
	
            $old_price = isset($cart_item['rp_wcdpd']['original_price']) ? $cart_item['rp_wcdpd']['original_price'] : $cart_item['data']->price; // 104

			$total_discount = $old_price - $discounted_price; // 10
			$custom_price   = $old_price - ($old_price*($percentage/100)); // 89.02
			$new_price_discounted = $custom_price - $total_discount; // 79.02
            // Format price to display
			if (is_cart()){
				$price_to_display = wc_price($new_price_discounted);
			}else {
				$price_to_display = wc_price($new_price_discounted);
			}
            $original_price_to_display = wc_price($custom_price);
			if ($cart_item['quantity'] > 1){
            $item_price = '<span class="wcrp_cart_price_2"><del>' . $original_price_to_display . '</del> <ins>' . $price_to_display . '</ins></span>';
			} else {
				$item_price = '<span class="wcrp_cart_price_1"><ins>' . $price_to_display . '</ins></span>';
			}

            return $item_price;

	
	}
	
	// change the display of the item on loop and single pages
	// @ woocommerce_variation_price_html hook
	public function wcrp_get_variation_prices($price, $product){

		$product_id = isset($product->id) ? $product->id : $product->product_id;
		$optedout = get_post_meta($product->id, '_wcrp_checkbox', true);
		
		if ($optedout === 'yes'){
			$price = $product->get_price();
			return wc_price($price); 
		} else {
		$org_price = $product->get_price();
		$percentage = $this->percentage_get();
		$minus = ($price * ($percentage/100));
		$new_price = ($org_price - ($org_price * ($percentage/100)));
		return wc_price($new_price);
		}
	}
	public function check_for_opted_out($product){
		$product_id = isset($product->variation_id) ? $product->parent->id : $product->product_id;
			$optedout = get_post_meta($product_id, '_wcrp_checkbox', true);

		if ($optedout == 'yes'){
			return true; 
		} 
			return false;
	}
	public function percentage_get(){
		$postal_code = isset($_COOKIE['wcrp_postalcode']) ? $_COOKIE['wcrp_postalcode'] : '1';
		$all_regional_data = wcrp_get_option('regional_repeat_group');			
		$thekey = $this->wcrp_get_key_where_postal_code_found($all_regional_data,'postal_codes',$postal_code);
		if ($thekey !== 'no' ){
				$percentage = $all_regional_data[$thekey]['region_discount'];
			} else {
				$percentage = 1;
		}
		return $percentage;
	}
	/**
	* This is where the magic happens
	* @ woocommerce_after_shop_loop_item hook
	* @ woocommerce_get_price_html
	*/
	public function wcrp_show_price_or_button($price, $product){
		$product_id = isset($product->id) ? $product->id : $product->post->ID;
		$optedout = get_post_meta($product_id, '_wcrp_checkbox', true);

		$org_price = $product->get_price();
		if ($optedout === 'yes'){

			$price = $org_price;
		} else {
			// adjust the price here
			$postal_code = isset($_COOKIE['wcrp_postalcode']) ? $_COOKIE['wcrp_postalcode'] : '1';
			$all_regional_data = wcrp_get_option('regional_repeat_group');			
			$thekey = $this->wcrp_get_key_where_postal_code_found($all_regional_data,'postal_codes',$postal_code);
			if ($thekey !== 'no' ){
					$percentage = $all_regional_data[$thekey]['region_discount'];
				} else {
					$percentage = 1;
			}
		$custom_price   = $org_price - ($org_price*($percentage/100));

		$price = $custom_price;
		
		}
		
		$code = isset($_GET['postal_code']) ? preg_replace('/\s+/', '', $_GET['postal_code']) : null;
		if ($code){
			$code = strtoupper($code);
		}
		
		$postal_cookie = isset($_COOKIE['wcrp_postalcode']) ? $_COOKIE['wcrp_postalcode']: $code;
		
			if ( ( $code != $postal_cookie ) && ( isset($code) ) ) {
?><script>
		jQuery(document).ready(function () {

			jQuery('#matchpostal').popup('show');

		});
		</script>
<?php 		
				$postal_code = null;
			} else {
				$postal_code = $postal_cookie;
			}
			
		if (!is_admin()){
			if( isset($_GET['postal_code']) || isset($postal_code) ){
				
				if ($optedout === 'yes'){
					
					return wc_price($price); 
				} 
				// set shipping postal code
				
				if (is_user_logged_in()) {
					$customer = wp_get_current_user();
				} else {
					$customer = new WC_Customer	();
				}
				
				$pcode = str_replace("+", " ",$postal_cookie);
				//$customer->set_postcode($_GET['postal_code']);     //for setting billing postcode
				$customer->set_shipping_postcode($pcode);    //for setting shipping postcode
			
			
			$price = wc_price($price);
			
	
			return $price;
				
			} else {
				remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_single_add_to_cart', 1);
				$price = '';
			return '<a class="initialism fadeandscale_open btn btn-success button" href="#fadeandscale">Order Now</a>'; // button for modal
		
			}
		}
	}

	public function get_custom_product_price($price){
		
		$percentage = $this->percentage_get();
		
		$custom_price   = $price - ($price*($percentage/100));

		$price = $custom_price;

		return $price;
		
	}
	
	public function rf_update_price_on_ajax(){
		global $wpdb;
		$code = preg_replace('/\s+/', '', $_POST['code']);
		$code = strtoupper($code);
        wc_setcookie('wcrp_postalcode', $code, 0);
		
		//setcookie('wcrp_postalcode', $code, 0);
			
        echo json_encode( array( 'success' => true, 'msg' => esc_html__(' - Prices changed successfully', 'contempo'), 'price' => $code ) );
        wp_die();
	}
	public function wcrp_add_custom_general_fields(){
		global $woocommerce, $post;
		echo '<div class="options_group">';
			woocommerce_wp_checkbox( 
				array( 
					'id'            => '_wcrp_checkbox', 
					'wrapper_class' => 'show_if_simple show_if_variable', 
					'label'         => __('Do not apply Regional Discount?', 'woocommerce' ), 
					'description'   => __( 'if checked, regional discount will not apply for this product', 'woocommerce' ) 
					)
				);
		echo '</div>';
	}
	
	public function wcrp_add_custom_general_fields_save($post_id, $post){
		// Checkbox
		$woocommerce_checkbox = isset( $_POST['_wcrp_checkbox'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_wcrp_checkbox', $woocommerce_checkbox );
	}

		
	function cmb2_plugin_activate(){

		// Require cmb2 plugin
		if ( ! is_plugin_active( 'cmb2/init.php' ) and current_user_can( 'activate_plugins' ) ) {
			// Stop activation redirect and show error
			wp_die('Sorry, but this plugin requires the CMB2 to be installed and active. <br><a href="' . admin_url( 'plugins.php' ) . '">&laquo; Return to Plugins</a> and "Add New", then search for "CMB2" <a href="https://wordpress.org/plugins/cmb2/">or visit CMB2 page</a>');
		}
	}

	/**
	* enqueue the scripts
	*
	*/
	public function wcrp_enqueue_scripts(){
		wp_register_script( 'wcrp-script', plugins_url( 'js/wcrp-script.js', __FILE__ ) , '', '', true );
		wp_register_script( 'js-cookie', plugins_url( 'js/js.cookie.js', __FILE__ ) , array('jquery'), '', true );
		wp_register_script( 'overlay-js', plugins_url( 'js/jquery.popupoverlay.js', __FILE__ ) , array('jquery'), '', true );
		//wp_enqueue_script( 'wcrp-script' );
		wp_enqueue_script( 'overlay-js' );
		wp_localize_script('overlay-js', 'OverAjax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
	}
	/**
	* Add modal to footer
	*/
	public function add_modal_to_footer(){
	?>
			<!-- Fade & scale -->
		
		<div id="fadeandscale" class="well">
			<h4>Enter Postal Code</h4>
		<form id="postal_check_top" action="">
		<input type="text" name="postal_code" id="postal_code" class="wcrp_postalcode"/>
		
		<input type="submit" class="btn btn-default pc_submit"  id="pc_submit" name="pc_submit" value="submit"/>
		</form>
		</div>
		<div id="matchpostal" class="well">
		<p class="mismatch">Looks like you have entered a different postal code.  Please resubmit for changes to take effect.</p>
			<h4>Enter Postal Code</h4>
		<form id="postal_check" action="">
		<input type="text" name="postal_code" id="postal_code" />
		
		<input type="submit" class="btn btn-default" id="pc_submit2" name="pc_submit2" value="submit2"/>
		</form>
		</div>

		<script>
		jQuery(document).ready(function () {
			jQuery('#matchpostal').hide();
			jQuery('#fadeandscale').popup({
				pagecontainer: '.wrap',
				transition: 'all 0.3s',

			});
			
			
			//var postal_code = jQuery('.wcrp_postalcode').val();
			var ajaxurl = OverAjax.ajaxurl;
			jQuery('.pc_submit').click(function(e)
    {
		var postal_code = jQuery('.wcrp_postalcode').val();
		console.log(postal_code);
					e.preventDefault();
				jQuery.ajax({ 
							url: OverAjax.ajaxurl,
							data: { 
								'action': 'rf_update_price',
								'code': postal_code,
							},
							method: 'POST',
							dataType: 'JSON',
							beforeSend: function () {
								jQuery('.price span.amount').css('border','1px solid greenyellow');
								
							},
							success: function (response) {
								if (response.success) {
									console.log(response);
									
								}
							},
							error: function (xhr, status, error) {
								var err = eval("(" + xhr.responseText + ")");
								console.log(err.Message);
							},
							complete: function () {
								jQuery('.price span.amount').css('border','none');
								jQuery('.price span.amount').addClass('updated');
								jQuery('#postal_check_top').submit();
							}
					});
			});
		});
		</script>

		<style>
		#fadeandscale, #matchpostal {
			background:#fff;
			border-radius:10px;
			padding:20px;
			-webkit-transform: scale(0.8);
			   -moz-transform: scale(0.8);
				-ms-transform: scale(0.8);
					transform: scale(0.8);
		}
		.popup_visible #fadeandscale, .popup_visible #matchpostal {
			-webkit-transform: scale(1);
			   -moz-transform: scale(1);
				-ms-transform: scale(1);
					transform: scale(1);
		}
		</style>
	<?php 	
	}
	function wcrp_get_key_where_postal_code_found($all_regions, $postal_code, $value){
		foreach($all_regions as $key => $region){
			//$postal_codes = array();
				$regional_info = preg_replace('/\s*/m', '', $region['postal_codes']);
				$regional_info = str_replace("\r\n", '', $regional_info);
				$postal_codes = explode(',', $regional_info );
				if ( in_array($value,$postal_codes) ){
					return $key;
				}
				

					}
		return 'no';
	}
	/**
	 * Returns the running object
	 *
	 * @return Myprefix_Admin
	 **/
	public static function get_instance() {
		if( is_null( self::$instance ) ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}
	/**
	 * Initiate our hooks
	 * @since 0.1.0
	 */
	public function hooks() {
		add_action( 'admin_init', array( $this, 'init' ) );
		add_action( 'admin_menu', array( $this, 'add_options_page' ) );
		add_action( 'cmb2_admin_init', array( $this, 'add_options_page_metabox' ) );
		
	}
	/**
	 * Register our setting to WP
	 * @since  0.1.0
	 */
	public function init() {
		register_setting( $this->key, $this->key );
	}
	/**
	 * Add menu options page
	 * @since 0.1.0
	 */
	public function add_options_page() {
		$this->options_page = add_menu_page( $this->title, $this->title, 'manage_options', $this->key, array( $this, 'admin_page_display' ) );
		// Include CMB CSS in the head to avoid FOUC
		add_action( "admin_print_styles-{$this->options_page}", array( 'CMB2_hookup', 'enqueue_cmb_css' ) );
	}
	/**
	 * Admin page markup. Mostly handled by CMB2
	 * @since  0.1.0
	 */
	public function admin_page_display() {
		?>
		<div class="wrap cmb2-options-page <?php echo $this->key; ?>">
			<h2><?php echo esc_html( get_admin_page_title() ); ?></h2>
			<?php cmb2_metabox_form( $this->metabox_id, $this->key ); ?>
		</div>
		<?php
	}
	/**
	 * Add the options metabox to the array of metaboxes
	 * @since  0.1.0
	 */
	function add_options_page_metabox() {
		// hook in our save notices
		add_action( "cmb2_save_options-page_fields_{$this->metabox_id}", array( $this, 'settings_notices' ), 10, 2 );
		$cmb = new_cmb2_box( array(
			'id'         => $this->metabox_id,
			'hookup'     => false,
			'cmb_styles' => false,
			'show_on'    => array(
				// These are important, don't remove
				'key'   => 'options-page',
				'value' => array( $this->key, )
			),
		) );
		$group_field_id = $cmb->add_field( array(
			'id'          => 'regional_repeat_group',
			'type'        => 'group',
			'description' => __( 'All regional information', 'cmb2' ),
			 'repeatable'  => true, // use false if you want non-repeatable group
			'options'     => array(
				'group_title'   => __( 'Region {#}', 'cmb2' ), // since version 1.1.4, {#} gets replaced by row number
				'add_button'    => __( 'Add Another Entry', 'cmb2' ),
				'remove_button' => __( 'Remove Entry', 'cmb2' ),
				'sortable'      => false, // beta
				// 'closed'     => true, // true to have the groups closed by default
			),
		) );
		// Set our CMB2 fields
		$cmb->add_group_field( $group_field_id, array(
			'name' => __( 'Region', 'wcrp' ),
			'desc' => __( 'Add Region Name Here', 'wcrp' ),
			'id'   => 'region',
			'type' => 'text_medium',
			'default' => '',
		) );
		$cmb->add_group_field( $group_field_id, array(
			'name'    => __( 'Postal Codes', 'wcrp' ),
			'desc'    => __( 'Add comma separated postal codes here', 'wcrp' ),
			'id'      => 'postal_codes',
			'type'    => 'textarea',
			'default' => '',
		) );
		$cmb->add_group_field( $group_field_id, array(
			'name'    => __( 'Region Discount', 'wcrp' ),
			'desc'    => __( 'discount applied for this region', 'wcrp' ),
			'id'      => 'region_discount',
			'type'    => 'text_medium',
			'default' => '',
		) );
	}
	/**
	 * Register settings notices for display
	 *
	 * @since  0.1.0
	 * @param  int   $object_id Option key
	 * @param  array $updated   Array of updated fields
	 * @return void
	 */
	public function settings_notices( $object_id, $updated ) {
		if ( $object_id !== $this->key || empty( $updated ) ) {
			return;
		}
		add_settings_error( $this->key . '-notices', '', __( 'Settings updated.', 'wcrp' ), 'updated' );
		settings_errors( $this->key . '-notices' );
	}
	/**
	 * Public getter method for retrieving protected/private variables
	 * @since  0.1.0
	 * @param  string  $field Field to retrieve
	 * @return mixed          Field value or exception is thrown
	 */
	public function __get( $field ) {
		// Allowed fields to retrieve
		if ( in_array( $field, array( 'key', 'metabox_id', 'title', 'options_page' ), true ) ) {
			return $this->{$field};
		}
		throw new Exception( 'Invalid property: ' . $field );
	}
}
/**
 * Helper function to get/return the Myprefix_Admin object
 * @since  0.1.0
 * @return Myprefix_Admin object
 */
function wcrp_admin() {
	return WCRP_Admin::get_instance();
}
/**
 * Wrapper function around cmb2_get_option
 * @since  0.1.0
 * @param  string  $key Options array key
 * @return mixed        Option value
 */
function wcrp_get_option( $key = '' ) {
	return cmb2_get_option( wcrp_admin()->key, $key );
}
// Get it started
wcrp_admin();