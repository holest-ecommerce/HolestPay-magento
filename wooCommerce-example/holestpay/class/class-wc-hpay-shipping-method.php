<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_HPay_Shipping_Method extends WC_Shipping_Method {
	
	private $_method_data  = null;
	private $_alias        = null;
	public $hpay_id        = null;
	public $tax_label      = null;    
	public $instance_name  = null;    
	public $description    = null;
	
	
	public function aliasName(){
		return $this->_alias;
	}
	
	public function hpay_method_type(){
		return $this->_method_data["ShippingMethod"];
	}
	
	public function hpay_method_slug(){
		return "hpay-" . $this->_method_data["ShippingMethod"] . "-" . $this->hpay_id;
	}
	
	public function getHProp($name){
		if($this->_method_data){
			if(isset($this->_method_data[$name])){
				return $this->_method_data[$name];
			}
		}
		return null;
	}
	
	/**
	 * Constructor
	 *
	 * @access public
	 * @return void
	 */
	public function __construct( $instance_id = 0 ) {
		
		global $hpay_sm_class_mapper;
		
		if(!isset($hpay_sm_class_mapper[get_class($this)]))
			return;
		
		$second_instance = isset($hpay_sm_class_mapper[get_class($this)]["instance"]);
		
		$this->instance_id        = absint( $instance_id );
		
		$hpay_sm_class_mapper[get_class($this)]["instance"] = $this;
		
		$this->_alias       = $hpay_sm_class_mapper[get_class($this)]["alias"];
		$this->_method_data = $hpay_sm_class_mapper[get_class($this)]["data"];
		
		$this->hpay_id      = $this->_method_data["HPaySiteMethodId"];
		$this->id           =  "hpayshipping-" . $this->hpay_id;
		
		if(!isset($this->_method_data["Name"]))
			$this->_method_data["Name"] = "-";
		
		if(!isset($this->_method_data["Description"]))
			$this->_method_data["Description"] = "-";
		
		$this->title        =  $this->_method_data["Name"];
		$this->description  =  $this->_method_data["Description"];
		
		$this->supports           = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal'
		);
		
		
		$environment = HPay_Core::instance()->getSetting("environment", null);
		
		$hpay_panel_url = "https://" . ($environment == "sandbox" ? "sandbox." : "") . "pay.holest.com";
		
		$this->method_title        =  "HolestPay: " . $this->_method_data["SystemTitle"];
		$this->method_description  =  sprintf(__("Configure this shipping method on %s","holestpay"),  "<a class='hpayopen' href='{$hpay_panel_url}/shippingdetails/{$this->_method_data["HPaySiteMethodId"]}'>" . $hpay_panel_url . "</a>");
		$this->enabled             = $this->_method_data["Enabled"] ? "yes" : "no";
		
		$cost_desc = __( 'Enter a cost (excl. tax) or sum, e.g. <code>10.00 * [qty]</code>.', 'woocommerce' );
		
		$this->instance_form_fields = array(
			'enabled' => array(
				'title' => "<a class='button button-primary hpayopen hpayautoopen' href='{$hpay_panel_url}/shippingdetails/{$this->_method_data["HPaySiteMethodId"]}'>" . __( 'Configure on HolestPay portal', 'holestpay' ) . ( $environment == "sandbox" ? " (sandbox) " : "") . "</a>", 
				'type' => 'hidden',
				'label' => __( 'Change Enabled/Disabled on HolestPay', 'holestpay' ),
				'default' => 'yes'
			),
			'instance_name'       => array(
				'title'             => __( 'Instance name', 'woocommerce' ),
				'type'              => 'text',
				'placeholder'       => __( 'enter instance name', 'woocommerce' ),
				'description'       => __( 'Enter a name for the instance (visible only in backend - admin).', 'woocommerce' ),
				'default'           => "",
				'desc_tip'          => true
			),
			'tax_label' => array(
				'title'   => __( 'Tax label', 'woocommerce' ),
				'type'              => 'text',
				'placeholder'       => __( 'enter tax label', 'woocommerce' ),
				'description'       => __( 'enter fiscal tax label', 'woocommerce' ),
				'default'           => "",
				'desc_tip'          => true
			)
		);
		
		$this->init_settings();
		$this->tax_label           = $this->get_option( 'tax_label' );
		$this->instance_name        = $this->get_option( 'instance_name', "instance " . $this->instance_id);
		
		if(is_admin()){
			$this->title .= " ({$this->instance_name})";
		}
		
		$this->instance_form_fields["instance_name"]["default"] = "instance {$this->instance_id}";
		
		if(!$second_instance){
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
			//add_action( 'woocommerce_after_shipping_rate', array( $this, 'show_description' ), 10, 2 );
			add_action('woocommerce_checkout_update_order_review', array( $this, 'checkout_update_order_review'), 1, 1);
			add_action('woocommerce_checkout_order_processed',array( $this, 'checkout_order_processed'),99, 3);
		}
	}
	
	public function checkout_update_order_review( $post_data = null) {
		try{
			$c_total = WC()->cart->get_total( 'edit' );
			
			$chosen_shipping_methods = WC()->session->get('chosen_shipping_methods');
			if(!empty($chosen_shipping_methods)){
				$chosen_shipping_methods = implode(",",$chosen_shipping_methods);	
			}else{
				$chosen_shipping_methods = "";
			}
			
			$hlast_hash = WC()->session->get( 'hpay_shipping_reset_ord_amt'); 
			
			$hlast_total = 0;
			$hlast_sm = ""; 
			
			if($hlast_hash){
				$hlast_hash = explode(":",$hlast_hash);
				if(count($hlast_hash) == 2){
					$hlast_sm    = $hlast_hash[0]; 
					$hlast_total = floatval($hlast_hash[1]);
				}
			}
			
			if($hlast_sm && $hlast_total && $hlast_sm == $chosen_shipping_methods && floatval($c_total) == $hlast_total){
				return;	
			}
			
			WC()->session->set( 'hpay_shipping_reset_ord_amt', $chosen_shipping_methods .":" . $c_total); 
			$packages = WC()->cart->get_shipping_packages();
			foreach ($packages as $package_key => $package ) {
				 WC()->session->set( 'shipping_for_package_' . $package_key, false ); // Or true
			}
			
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	public function checkout_order_processed($order_id, $posted_data, $order){
		try{
			$this->checkout_update_order_review();
			WC()->cart->calculate_totals();
			WC()->cart->calculate_shipping();	
			
			$chosen_shipping_method = null;
			
			if(WC()->session){
				$chosen_shipping_method = WC()->session->get('chosen_shipping_methods');
				if(!empty($chosen_shipping_method)){
					$chosen_shipping_method = $chosen_shipping_method[0];
					if(stripos($chosen_shipping_method,":") !== false){
						$chosen_shipping_method = explode(":",$chosen_shipping_method);
						$chosen_shipping_method = $chosen_shipping_method[0];
					}
				}else{
					$chosen_shipping_method = null;
				}
			}
			
			if($chosen_shipping_method){
				$hpay_shipping_method = HPay_Core::shipping_method_instance($chosen_shipping_method);
				if($hpay_shipping_method){
					
					$hcart = HPay_Core::instance()->getHPayCart( null );
					$cost     = $this->calculate_hpay_cost($hcart);
					
					foreach ( $order->get_items("shipping") as $item_id => $item ) {
						if(stripos($item->get_method_id(),"hpayshipping-") !== false){
							$item->set_total($cost);
							$item->save();
							$order->calculate_totals();
							break;
						}
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}		
	}
	
	/*
	public function show_description($method, $index){
		if ( ! $method instanceof WC_Shipping_Rate) {
			return;
		}
		
		if($method->get_method_id() != $this->id){
			return;
		}
		
		?>
		<p class="shipping-method-description hpay-info-note">
			<?php echo wp_kses_post( $this->description ); ?>
		</p>
		<?php
	}
	*/
	
	public function calculate_hpay_cost($hcart){
		$cost = 0.00;
		try{
			
			$exchnage_rate = 1.00;
			if(!isset($hcart["error"])){
				$weight = 0;
				$free_above_order_amount = null;
				if(isset($this->_method_data["Free Above Order Amount"])){
					if($this->_method_data["Free Above Order Amount"] != "" && $this->_method_data["Free Above Order Amount"] !== null){
						$free_above_order_amount = floatval($this->_method_data["Free Above Order Amount"]);
					}
				}
				
				if($this->_method_data["ShippingCurrency"] != $hcart["order_currency"] || $free_above_order_amount !== null){
					$exchnage_rate = HPay_Core::getMerchantExchnageRate($hcart["order_currency"], $this->_method_data["ShippingCurrency"]);
					if(!$exchnage_rate)
						$exchnage_rate = 1.00;
				}
				
				$cart_amount_in_currency = $cart_amount * $exchnage_rate;
				$free = false;
				if($free_above_order_amount !== null){
					if(!isset($hcart["cart_amount"]) && isset($hcart["order_amount"])){
						$hcart["cart_amount"] = $hcart["order_amount"];
					}
					
					$cart_amount = floatval($hcart["cart_amount"]);
					if(isset($hcart["order_items"])){
						foreach($hcart["order_items"] as $oitem){
							if(isset($oitem["type"]) && $oitem["type"] == "shipping"){
								if($oitem["subtotal"]){
									$cart_amount -= floatval($oitem["subtotal"]);
								}
							}
						}
					}
					
					
					if($cart_amount_in_currency >= $free_above_cart_amount)
						$free = true;
				}
				
				if(!$free){	
					if(isset($hcart["order_items"])){
						foreach($hcart["order_items"] as $item){
							$qty = intval($item["qty"]);
							if(!$qty)$qty = 1;
							$weight += (intval($item["weight"]) * $qty);
						}
					}
					
					if(isset($this->_method_data["Price Table"])){
						if(!empty($this->_method_data["Price Table"])){
							usort($this->_method_data["Price Table"], function($a, $b){ return intval($a["MaxWeight"]) - intval($b["MaxWeight"]); });
							
							$max_cost   = 0;
							$max_cost_g = 0;
							$cost_found = false;
							foreach($this->_method_data["Price Table"] as $weight_rate){
								if($weight <= intval($weight_rate["MaxWeight"])){
									$cost = floatval($weight_rate["Price"]);
									$cost_found = true;
									break;
								}
								$max_cost   = $weight_rate["Price"];
								$max_cost_g = intval($weight_rate["MaxWeight"]);
							}
							
							if(!$cost_found){
								if(!isset($this->_method_data["After Max Weight Price Per Kg"])){
									$this->_method_data["After Max Weight Price Per Kg"] = 0;
								}
								$cost = $max_cost + ((floatval($weight - $max_cost_g) / 1000) * floatval($this->_method_data["After Max Weight Price Per Kg"]));
							}
							
							if(isset($hcart["order_shipping"]) && isset($hcart["order_shipping"]["is_cod"]) && $hcart["order_shipping"]["is_cod"]){
								if(isset($this->_method_data["COD cost"])){
									if($this->_method_data["COD cost"]){
										if(stripos($this->_method_data["COD cost"],"%") !== false){
											$cost *= (1.00 + floatval(str_replace(array("%", " "),"", $this->_method_data["COD cost"])));
										}else{
											$cost += floatval($this->_method_data["COD cost"]);
										}
									}
								}
							}
							
							if(isset($this->_method_data["Additional cost"])){
								if($this->_method_data["Additional cost"]){
									if(stripos($this->_method_data["Additional cost"],"%") !== false){
										$cost *= (1.00 + floatval(str_replace(array("%", " "),"", $this->_method_data["Additional cost"])));
									}else{
										$cost += floatval($this->_method_data["Additional cost"]);
									}
								}
							}
						}
					}
					
					if(isset($this->_method_data["Price Multiplication"])){
						if(!empty($this->_method_data["Price Multiplication"])){
							usort($this->_method_data["Price Multiplication"], function($a, $b){ return intval($a["MinCartTotal"]) - intval($b["MinCartTotal"]); });
							
							$mul = 1.00;
							foreach($this->_method_data["Price Multiplication"] as $cart_amt_level){
								if($cart_amt_level["MinCartTotal"] == "" || !ctype_digit( str_replace(".","",$cart_amt_level["MinCartTotal"]))){
									continue;
								}
								$cart_amt_level["MinCartTotal"] = floatval($cart_amt_level["MinCartTotal"]);
								if($cart_amt_level["MinCartTotal"] >= $cart_amount_in_currency){
									if($cart_amt_level["Multiplication"] == "" || !ctype_digit( str_replace(".","",$cart_amt_level["Multiplication"]))){
										$mul = floatval($cart_amt_level["Multiplication"]);
									}else{
										$mul = 1.00;
									}
								}
							}
							
							if($mul != 1){
								$cost = $cost * mul;
							}
						}
					}
					
					if($cost){
						$cost = round($cost / $exchnage_rate,2);
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return $cost;
	}
	
	/**
	 * calculate_shipping function.
	 *
	 * @access public
	 * @param array $package
	 * @return void
	 */
	public function calculate_shipping( $package = array() ) {
		try{
			$cost = 0.00;
			
			$hcart = HPay_Core::instance()->getHPayCart( null );
			
			$rate = array(
				'label'    => $this->title,
				'cost'     => $this->calculate_hpay_cost($hcart),
				'calc_tax' => 'per_order'
			);
			
			if(isset($this->_method_data["Prices Include Vat"])){
				if($this->_method_data["Prices Include Vat"]){
					$rate["taxes"] = false;
				}
			}
			
			// Register the rate
			$this->add_rate( $rate );
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
};