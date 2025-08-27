<?php
//HOLESTPAY 2023
if(!defined("HPAY_PRODUCTION_URL")){
	die("Direct access is not allowed");
};

trait HPay_Core_WooGUI {
	public static function sanitize_price( $price ) {
		return filter_var( sanitize_text_field( $price ), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION );
	}
	
	public function setup_woo_gui(){
		try{
			add_filter( 'manage_edit-shop_order_columns', array($this,'wc_shop_order_column_headers'), 20, 1 );
			add_action( 'woocommerce_shop_order_list_table_columns' , array($this,'wc_shop_order_column_headers'), 20, 1 );
			
			add_action( 'manage_shop_order_posts_custom_column' , array($this,'wc_shop_order_column_content'), 20, 2 );
			add_action( 'woocommerce_shop_order_list_table_custom_column' , array($this,'wc_shop_order_column_content'), 20, 2 );
			
			add_filter( 'woocommerce_shop_order_search_fields', array($this,'wc_shop_order_search_fields'),999,1);
			add_filter( "woocommerce_subscription_periods",array($this,"woocommerce_subscription_periods"),1,99);
			add_filter( 'woocommerce_is_subscription', array($this,"woocommerce_is_subscription") ,59,3);
			add_filter( 'product_type_options', array($this,"wc_product_type_options"), 99,1);
			add_action( 'woocommerce_variation_options',array($this,"woocommerce_variation_options"), 20,3);
			add_action( 'woocommerce_product_options_pricing',array($this,"woocommerce_product_options_pricing"), 1);
			add_action( 'woocommerce_variation_options_pricing',array($this,"woocommerce_variation_options_pricing"), 1,3);
			add_action( 'woocommerce_admin_process_product_object',array($this,"wc_save_product"), 20,1 );
			add_action( 'woocommerce_admin_process_variation_object',array($this,"wc_save_variantion"), 20,2); 
			
			//register_meta( 'post', '_hpay_subscription', array("single" => true, "type" => "string", "default" => "0", "show_in_rest" => true));
			register_meta( 'post', '_hpay_column_layout', array("single" => true, "type" => "string", "default" => "0", "show_in_rest" => true));
			register_meta( 'post', '_hpay_name_price', array("single" => true, "type" => "string", "default" => "0", "show_in_rest" => true));
			
			add_filter( 'woocommerce_add_cart_item_data', array( $this, 'woocommerce_add_cart_item_data' ), 999 );
			add_filter( 'woocommerce_get_cart_contents', array( $this, 'woocommerce_get_cart_contents' ), 999, 1 );
			add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'woocommerce_loop_add_to_cart_link' ), 999, 2 );
			add_filter( 'woocommerce_get_price_html', array( $this, 'woocommerce_get_price_html' ), 999, 2 );
			
			add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'woocommerce_before_add_to_cart_button' ), 999 );
			add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'woocommerce_add_to_cart_validation' ), 999, 2 );
			add_action( 'woocommerce_single_product_summary', array( $this, 'woocommerce_single_product_summary' ), 30);
			add_action( 'woocommerce_after_single_product_summary', array( $this, 'woocommerce_after_single_product_summary' ), 3);
			
			add_filter( 'woocommerce_available_variation', array( $this, 'woocommerce_available_variation' ), 99, 3);
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	function woocommerce_add_cart_item_data($cart_item_data){
		try{
			if ( hpay_read_request_parm("hpay_name_your_price","")) {
				$cart_item_data['hpay_name_your_price'] = self::sanitize_price( hpay_read_request_parm("hpay_name_your_price",""));
				unset( $_REQUEST['hpay_name_your_price'] );
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return $cart_item_data;
	}
	
	function woocommerce_get_cart_contents($cart_contents){
		try{
			foreach ( $cart_contents as $cart_item ) {
				if ( ! isset( $cart_item['hpay_name_your_price'] ) ) {
					continue;
				}
				$final_value = $cart_item['hpay_name_your_price'];
				$cart_item['data']->set_price( $final_value );
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return $cart_contents;
	}
	
	function woocommerce_loop_add_to_cart_link( $link, $product ) {
		try{
			$product_id    = $product->get_id();
			if(get_post_meta( $product_id, '_hpay_name_price', true ) == "yes"){
				return "";
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return $link;
	}
	
	function woocommerce_get_price_html( $price, $product ) {
		try{
			if ( is_admin() ) {
				return $price;
			}
			$product_id    = $product->get_id();
			if(get_post_meta( $product_id, '_hpay_name_price', true ) == "yes"){
				return esc_attr( sprintf( esc_attr__( 'Usual amount %s %s', 'holestpay' ), $product->get_sale_price(),  get_woocommerce_currency_symbol() ) );
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return $price;
	}
	
	function get_price_html( $item ) {
		try{
			if ( '' === $item->get_price() ) {
				$price = apply_filters( 'woocommerce_empty_price_html', '', $item );
			} elseif ( $item->is_on_sale() ) {
				$price = wc_format_sale_price( wc_get_price_to_display( $item, array( 'price' => $item->get_regular_price() ) ), wc_get_price_to_display( $item ) ) . $item->get_price_suffix();
			} else {
				$price = wc_price( wc_get_price_to_display( $item ) ) . $item->get_price_suffix();
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return apply_filters( 'woocommerce_get_price_html', $price, $item);
	}	
	
	function woocommerce_available_variation($data, $product, $variation){
		try{
			if(is_a($variation,'WC_Product_Subscription_Variation')){
				if(!trim(strip_tags($data["price_html"]))){
					$data["price_html"] = $this->get_price_html( $variation );
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return $data;
	}
	
	function woocommerce_after_single_product_summary(){
		try{
			global $product;
			if($product){
				$product_id    = $product->get_id();
				if($product->get_meta("_hpay_name_price")){
					
				}
			}	
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	function woocommerce_single_product_summary(){
		try{
			global $product;
			if($product){
				$product_id    = $product->get_id();
				if($product->get_meta("_hpay_column_layout") == "yes"){
					if($product->has_child()){
						$variations = $product->get_available_variations();
						$attributes = $product->get_attributes();
						?>
						<div class='hpay-variant-columns-layout'>
							<?php
								foreach($variations as $variation ){
									$var = new WC_Product_Variation($variation["variation_id"]);
									
									$disabled = !$variation["is_purchasable"] ? " item-disabled " : "";
									
									$image_src = wp_get_attachment_image_src($variation['image_id'], "thumbnail" );
									if(!empty($image_src))
										$image_src = $image_src[0];
									else
										$image_src = "";
									
									if($image_src){
										$image_src = "background-image:url(" . esc_url($image_src) . ")";
									}
									
									?>
									<div style="<?php echo esc_attr($image_src); ?>" variation_id="<?php echo esc_attr($variation["variation_id"]); ?>" class='hpay-variant-columns-layout-item <?php echo esc_attr($disabled); ?>'>
										<div class='attr'>
										<?php foreach ( $variation["attributes"] as $attribute_name => $value ) : ?>
											<p label="<?php 
												$attr_label = wc_attribute_label($attribute_name,$product);
												if(stripos($attr_label,"attribute_") === 0){
													if(isset($attributes[str_ireplace("attribute_","",$attr_label)])){
														$attr_label = $attributes[str_ireplace("attribute_","",$attr_label)]->get_name();
													}
												}
												echo esc_attr($attr_label);
											?>">
												<?php
												if(is_array($value)){
													echo esc_attr(implode(", ",$value));
												}else{
													echo esc_attr($value); 	
												}
												?>
											</p>
										<?php endforeach; ?>
										</div>
										<div class='price'>
										<?php
											echo ($variation["price_html"]);
										?>
										</div>
										<div class='desc'>
											<?php 
												echo esc_html($variation['variation_description']);
											?>
										</div>
									</div>
									<?php
								}
							?>
						</div>
						<script type="text/javascript">
							jQuery(document).ready(function(){
								hpay_column_layout_init(<?php echo json_encode($variations); ?>);
							});
						</script>
						<?php
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	function woocommerce_before_add_to_cart_button(){
		try{
			global $product;
			if($product){
				$product_id    = $product->get_id();
				
				$enter_price_data = array();
				
				$exchange_rate = 1;
				if(hpay_get_wc_order_currency(null) != hpay_get_wc_currency()){
					$exchange_rate = HPay_Core::getMerchantExchnageRate(hpay_get_wc_currency(), hpay_get_wc_order_currency(null));
				}
				
				if($product->has_child()){
					$variations = $product->get_available_variations();
					foreach ( $variations as $key => $value ) {
						$variant_id = $value["variation_id"];
						if(get_post_meta($variant_id, '_hpay_name_price', true ) == "yes"){
							if(!isset($enter_price_data["variants"])){
								$enter_price_data["variants"] = array();
							}
							
							$value = self::sanitize_price( $value["display_price"]);
							
							$min = get_post_meta( $variant_id, '_hpay_name_price_min', true );
							$max = get_post_meta( $variant_id, '_hpay_name_price_max', true );
							
							if(floatval($min)){
								$min *= $exchange_rate;
							}
							
							if(floatval($max)){
								$max *= $exchange_rate;
							}
							
							if($min && floatval($value) < floatval($min)){
								$value =  self::sanitize_price($min);
							}
							
							if($max && floatval($value) > floatval($max)){
								$value =  self::sanitize_price($max);
							}
						
							$enter_price_data["variants"][$variant_id] = array(
								"id"  => $variant_id,
								"value" => $value,
								"min" => $min,
								"max" => $max
							);
						}
					}
				}else{
					if($product->get_meta("_hpay_name_price")){
						$value = self::sanitize_price( $product->get_price() );
						$min = get_post_meta( $product_id, '_hpay_name_price_min', true );
						$max = get_post_meta( $product_id, '_hpay_name_price_max', true );
						
						if(floatval($min)){
							$min *= $exchange_rate;
						}
						
						if(floatval($max)){
							$max *= $exchange_rate;
						}
						
						if($min && floatval($value) < floatval($min)){
							$value =  self::sanitize_price($min);
						}
						
						if($max && floatval($value) > floatval($max)){
							$value =  self::sanitize_price($max);
						}
					
						$enter_price_data["product"] = array(
							"id"    => $product_id,
							"value" => $value,
							"min"   => $min,
							"max"   => $max
						);
					}
				}
				
				if(!empty($enter_price_data)){
					$enter_price_data["currency_symbol"] = get_woocommerce_currency_symbol();
					?>
					<div id="hpay_name_price_panel" style="display:none" class="hpay-name-price-panel">
						<label for="hpay_name_price_input">
							<?php echo esc_attr( sprintf( esc_attr__( 'Enter amount (%s)', 'holestpay' ), get_woocommerce_currency_symbol() ) ); ?>
						</label>
						<input type="number" id="hpay_name_price_input" placeholder="<?php echo esc_attr__( 'amount', 'holestpay' ); ?>" class="hpay-name-price-input" name="hpay_name_your_price" value="" title="<?php echo esc_attr__( 'Enter Amount', 'holestpay' ); ?>" size="4"/>
						<p id="hpay_name_price_panel_min_max" style='font-size:80%;font-style:italic;display:none;'></p>
					</div>
					<div style="clear:both">&nbsp;</div>
					<script type="text/javascript">
						jQuery(document).ready(function(){
							hpay_enter_price_init(<?php echo json_encode($enter_price_data); ?>);
						});
					</script>
					<?php
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	function woocommerce_add_to_cart_validation($passed, $product_id ){
		try{
			if ( hpay_read_request_parm("hpay_name_your_price","")) {
				
				$value = self::sanitize_price( hpay_read_request_parm("hpay_name_your_price",""));
				
				$min = trim(get_post_meta( $product_id, '_hpay_name_price_min', true ));
				$max = trim(get_post_meta( $product_id, '_hpay_name_price_max', true ));
				
				$exchange_rate = 1;
				if(hpay_get_wc_order_currency(null) != hpay_get_wc_currency()){
					$exchange_rate = HPay_Core::getMerchantExchnageRate(hpay_get_wc_currency(), hpay_get_wc_order_currency(null));
				}
				
				if(floatval($min)){
					$min *= $exchange_rate;
				}
				
				if(floatval($max)){
					$max *= $exchange_rate;
				}
				
				if(!$min)
					$min = 0;
				
				if($value == 0 || ($min && floatval($value) < floatval($min))){
					wc_add_notice( esc_attr__( "We can't accept amount you specified!", 'holestpay' ), 'error' );
					return false;
				}
				
				if($max && floatval($value) > floatval($max)){
					wc_add_notice( esc_attr__( "We can't accept amount you specified!", 'holestpay' ), 'error' );
					return false;
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return $passed;
	}
	
	function wc_product_type_options($options){
		try{
			/*
			$options["hpay_subscription"] = array(
				"id"            => "_hpay_subscription",
				"wrapper_class" => "show_if_simple hide_if_subscription",
				"label"         => __("Is HPay Subscription","holestpay"),
				"description"   => __("Enables HolestPay subscription functionality for the item","holestpay"),
				"default"       => "no"
			);
			*/

			$options["hpay_column_layout"] = array(
				"id"            => "_hpay_column_layout",
				"wrapper_class" => "show_if_variable",
				"label"         => __("HPay column layout","holestpay"),
				"description"   => __("Enables HolestPay column layout for variable products","holestpay"),
				"default"       => "no",
			);
			
			$options["hpay_name_price"] = array(
				"id"            => "_hpay_name_price",
				"wrapper_class" => "show_if_simple",
				"label"         => __("Client names the price","holestpay"),
				"description"   => __("Enables HolestPay 'name your price' feature","holestpay"),
				"default"       => "no"
			);
 		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return $options;
	}
	
	function wc_save_product($product){
		ob_start();
		try{
			$hopts = $this->wc_product_type_options(array());
			foreach($hopts as $key => $def){
				$post_val = strtolower(hpay_read_request_parm($def["id"], ""));
				if($post_val == 1 || $post_val == "on" || $post_val == "true"){
					$post_val = "yes";
				}
				$product->update_meta_data($def["id"],$post_val );
			}
			$hprops = array("_hpay_name_price_min","_hpay_name_price_max");
			foreach($hprops as $index => $key){
				$post_val = hpay_read_request_parm($key, "");
				$product->update_meta_data($key, $post_val);
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		ob_get_clean();
	}
	
	function wc_save_variantion($variation, $variation_index){
		try{
			$fields_bool = array("_hpay_subscription","_hpay_name_price","_hpay_name_price_min","_hpay_name_price_max");
			
			foreach($fields_bool as $field){
				$post_val_var = ""; 
				$post_val = hpay_read_request_parm("variable{$field}", array());
				if(!empty($post_val)){
					if(isset($post_val[$variation_index])){
						$post_val_var = $post_val[$variation_index]; 
					}
				}
				
				if($post_val_var == 1 || $post_val_var == "on" || $post_val_var == "true"){
					$post_val = "yes";
				}
				
				$variation->update_meta_data($field,$post_val_var);
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	function quick_buy_button($product_id, $variant_id, $is_variant){
		?>
		    <div class="hpay_quick_buy" style="padding:8px;">
				<img src="<?php echo esc_attr__(HPAY_PLUGIN_URL .'/assets/icon-18.png'); ?>" />
				<button is_variant="<?php echo $is_variant ? 1 :0; ?>" product_id="<?php echo esc_attr($product_id); ?>" variant_id="<?php echo esc_attr($variant_id ? $variant_id : ""); ?>" class="button button-primary cmd-hpay-quick-buy"><?php echo esc_attr__("Send quick-buy link to the customer...","holestpay"); ?></button>
			</div>
		<?php
	} 
	
	function woocommerce_product_options_pricing(){
		try{
		$hidden = get_post_meta(get_the_ID(), "_hpay_name_price", true) == "yes" ? "display:block" : "display:none";
		
		?>
		<p style="<?php esc_attr_e($hidden); ?>" class="form-field _hpay_name_price_minmax_field">
			<label for="_hpay_name_price"><?php esc_html_e( 'Client names the price - min/max', 'holestpay' ); ?></label>
			
			<input style="float:none;max-width:120px;" type="number" placeholder="<?php esc_attr_e( 'min', 'holestpay' ); ?>" class="checkbox _hpay_name_price_min" value="<?php echo esc_attr( get_post_meta(get_the_ID(), "_hpay_name_price_min", true) ); ?>" name="_hpay_name_price_min"  />
			- <input style="float:none;max-width:120px;" type="number" placeholder="<?php esc_attr_e( 'max', 'holestpay' ); ?>" class="checkbox _hpay_name_price_max" value="<?php echo esc_attr( get_post_meta(get_the_ID(), "_hpay_name_price_max", true) ); ?>" name="_hpay_name_price_max"  /> 
		</p>
		<script type='text/javascript'>
			jQuery(document).on("change","#_hpay_name_price",function(e){
				jQuery("._hpay_name_price_minmax_field")[0].style.display = this.checked ? "block" : "none";
			});
		</script>
		<?php
		
		$this->quick_buy_button(get_the_ID(),null, false);
		
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	function woocommerce_variation_options_pricing($loop, $variation_data, $variation_post){
		try{
		$hidden = get_post_meta($variation_post->ID, "_hpay_name_price", true) == "yes" ? "display:block" : "display:none";
		?>
		<label id="variable_hpay_name_price_minmax<?php echo esc_attr( $loop ); ?>" style="<?php esc_attr_e($hidden); ?>" class="tips" data-tip="<?php esc_attr_e( "Minimum/Maximum for HolestPay 'name your price' feature", 'holestpay' ); ?>">
			<?php esc_html_e( 'Client names the price - min/max', 'holestpay' ); ?>
			<input style="float:none;max-width:120px;" type="number" placeholder="<?php esc_attr_e( 'min', 'holestpay' ); ?>" class="checkbox variable_hpay_name_price_min" value="<?php echo esc_attr( get_post_meta($variation_post->ID, "_hpay_name_price_min", true) ); ?>" name="variable_hpay_name_price_min[<?php echo esc_attr( $loop ); ?>]"  />
			- <input style="float:none;max-width:120px;" type="number" placeholder="<?php esc_attr_e( 'max', 'holestpay' ); ?>" class="checkbox variable_hpay_name_price_max" value="<?php echo esc_attr( get_post_meta($variation_post->ID, "_hpay_name_price_max", true) ); ?>" name="variable_hpay_name_price_max[<?php echo esc_attr( $loop ); ?>]"  />
		</label>
		<?php
		
		$this->quick_buy_button(get_the_ID(),$variation_post->ID, true);
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	function woocommerce_variation_options($loop, $variation_data, $variation_post){
		try{
		
		//$is_hpay_subscription = get_post_meta($variation_post->ID, "_hpay_subscription", true) == "yes";
		$is_hpay_name_price = get_post_meta($variation_post->ID, "_hpay_name_price", true) == "yes";
		/*
		?>
		<label class="tips hide_if_subscription" data-tip="<?php esc_attr_e( 'Enables HolestPay subscription functionality for the item', 'holestpay' ); ?>">
			<?php esc_html_e( 'Is HPay Subscription', 'holestpay' ); ?>
			<input type="checkbox" class="checkbox variable_is_hpay_subscription" value="yes" name="variable_is_hpay_subscription[<?php echo esc_attr( $loop ); ?>]" <?php checked( $is_hpay_subscription, true, true ); ?> />
		</label>
		<?php
		*/
		
		?>
		<label class="tips" data-tip="<?php esc_attr_e( "Enables HolestPay 'name your price' feature", 'holestpay' ); ?>">
			<?php esc_html_e( 'Client names the price', 'holestpay' ); ?>
			<input onchange="variable_hpay_name_price_minmax<?php echo esc_attr( $loop ); ?>.style.display = this.checked ? 'block' : 'none';" type="checkbox" class="checkbox variable_hpay_name_price" value="yes" name="variable_hpay_name_price[<?php echo esc_attr( $loop ); ?>]" <?php checked( $is_hpay_name_price, true, true ); ?> />
		</label>
		<?php
		
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		
	}
	
	function woocommerce_is_subscription($is_subscription, $product_id, $product){
		try{
			if(is_admin() && in_array(hpay_read_request_parm("action",""),array("edit","woocommerce_load_variations"))){
				return $is_subscription;
			}
			
			if(!$product && $product_id){
				$product = wc_get_product($product_id);
			}
			
			if($product && $product->get_meta('_subscription_period') == "one-time"){
				return false;
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return $is_subscription;
	}
	
	function woocommerce_subscription_periods($periods){
		$periods["one-time"] = __("one-time payment (added by HolestPay)","holestpay");
		return $periods;
	}
	
	public function wc_shop_order_search_fields( $search_fields ) {
		$search_fields[] = '_hpay_status';
		return $search_fields;
	}

	public function wc_shop_order_column_headers($columns){
		try{
			$reordered_columns = array();
			foreach( $columns as $key => $column){
				$reordered_columns[$key] = $column;
				if( $key == 'order_status' ){
					// Inserting after "Status" column
					$reordered_columns['hpay_status'] = __( 'HPay Status','holestpay');
				}
			}
			return $reordered_columns;
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return $columns;
	}
	
	public function wc_shop_order_column_content_status_item($stat_item){
		try{
			$stat_item = explode(":",$stat_item);
			if(count($stat_item) == 1){
				return "<span><b>" . esc_attr($stat_item[0]) . "</b></span>";	
			}else{
				$l = $stat_item[0];
				return "<span>" . esc_attr($l) . ": <b>" . esc_attr(str_replace(",",", ",$stat_item[1])) . "</b></span>";	
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return "";
	}
	
	public function wc_shop_order_column_content($column, $post_id ){
		try{
			switch ( $column )
			{
				case 'hpay_status' :
					// Get custom post meta data
					$order = null;
					if(is_a($post_id,"WC_Order")){
						$order = $post_id;
					}
					
					if(!$order)
						$order = hpay_get_order($post_id);
					
					if($order){
						$hpay_status = $order->get_meta('_hpay_status');
						if($hpay_status){
							$class = stripos($hpay_status,"RESERVED") !== false ? "hpay-blind" : "";
							if(stripos($hpay_status,"ERROR") !== false){
								$class = trim("{$class} hpay-status-with-error");
							}
							$hpay_status = explode(" ",trim($hpay_status));
							echo "<span class='" . esc_attr($class) . "' order_id=" . esc_attr($order->get_id()) . " hpay_order_list_info='";
							echo $order->get_order_key();
							echo "' >" . implode(" ",array_map(array($this,"wc_shop_order_column_content_status_item"), $hpay_status )) . "</span>";
						}else{
							echo "<span class='hpay_store_order hpay-push' order_id=" . esc_attr($order->get_id()) . " >&rarr;</span>";
						}
					}
				break;
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
};