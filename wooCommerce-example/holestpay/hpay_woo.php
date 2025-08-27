<?php

//HOLESTPAY 2023
if(!defined("HPAY_PRODUCTION_URL")){
	die("Direct access is not allowed");
};



  
trait HPay_Core_Woo {
	
	public function setup_woo(){
		try{
			add_action( "woocommerce_saved_order_items",array($this, "woocommerce_saved_order_items"),99,2);
			add_filter( "woocommerce_order_status_changed", array($this,"wc_order_status_changed"), 1, 4);
			add_filter( "woocommerce_order_status_changed", array($this,"wc_order_status_changed_request_end"), 99999, 4);
			add_filter( "woocommerce_checkout_order_processed", array($this,"wc_checkout_order_processed"), 999, 3);
			add_filter( "woocommerce_payment_complete", array($this,"wc_payment_complete"), 5, 1); 
			add_action( "woocommerce_new_order", array($this,'set_custom_order_key'), 50, 2 );
			add_action( "woocommerce_blocks_loaded", array($this,"setup_checkout_blocks_api"));
			add_filter( 'woocommerce_order_is_partially_refunded',array( $this, 'woocommerce_order_is_partially_refunded'), 99999,3);
			
			add_action( "plugins_loaded", array($this,'plugins_loaded'),20);
			
			add_action("woocommerce_order_refunded", array($this,'woocommerce_order_refunded'),50,2);
			
			add_filter("user_has_cap",  array( $this,'allow_order_pay_without_login'), 9999, 3 );
			
			add_filter("woocommerce_valid_order_statuses_for_payment", array( $this,'woocommerce_valid_order_statuses_for_payment'), 9999, 2 );
			add_filter("woocommerce_order_needs_payment", array( $this,'woocommerce_order_needs_payment'), 9999, 3 );
			
			add_filter( 'wcs_subscription_meta', array( $this, 'prevent_subscription_root_order_meta_copy' ),30,1);
			add_filter( 'wcs_renewal_order_meta', array( $this, 'prevent_subscription_renewal_meta_copy' ),30,1);
			
			add_action( 'before_woocommerce_pay', array( $this,'before_woocommerce_pay'),20,0);
			
			add_action( 'woocommerce_checkout_get_value', array( $this,'woocommerce_checkout_get_value'),20,2);
			
			add_action( 'wp_loaded', array( $this,'woocommerce_subscription_fixes'),20,1);
		
			
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	public function filter_subscription_meta($meta) {
		$filtered_meta = array();
		foreach($meta as $key => $value){
			if(strpos($key,"_hpay_") === 0){
				//skip	
			}else{
				$filtered_meta[$key] = $value;
			}
		}
		return $filtered_meta;
	}
	
	function allow_order_pay_without_login( $allcaps, $caps, $args ) {
	   $allcaps['pay_for_order'] = true;
	   add_filter( 'woocommerce_order_email_verification_required', '__return_false', 9999 );
		
	   if ( isset( $caps[0], $_GET['key'] ) ) {
		  if ( $caps[0] == 'pay_for_order' ) {
			 $order_id = isset( $args[2] ) ? $args[2] : null;
			 $order = hpay_get_order( $order_id );
			 if ( $order ) {
				if(!$order->is_paid() && !$order->has_status(array("completed","refunded","cancelled"))){
					$hpay_status = $order->get_meta("_hpay_status");
					if(!$hpay_status)
						$hpay_status = "";
					if(strpos($hpay_status,"PAID") === false && strpos($hpay_status,"SUCCESS") === false && strpos($hpay_status,"REFUNDED") === false){
						$allcaps['pay_for_order'] = true;
					}
				} 
				/* 
				if(stripos($order->get_payment_method(),"hpaypayment") !== false){
					$allcaps['pay_for_order'] = true;
				}
				*/
			 }
		  }
	   }
	   return $allcaps;
	}
	
	function before_woocommerce_pay(){
		try{
			if(hpay_read_get_parm("pay_for_order","") && hpay_read_get_parm("key","")) {
				$order_id = wc_get_order_id_by_order_key( hpay_read_get_parm("key","") );
				$order = hpay_get_order( $order_id );
				if ( ! $order ) {
					return;
				}
				echo '<h2>' . esc_attr__("Pay for Order No.","holestpay") . ' ' . esc_html( $order->get_order_number() ) . '</h2>';
				wc_get_template( 'order/order-details-customer.php', array( 'order' => $order ) );
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	function woocommerce_valid_order_statuses_for_payment($valid_payment_order_statuses, $order){
		if(!$order->has_status(array("completed","refunded","cancelled"))){
			$valid_payment_order_statuses[] = $order->get_status();	
		}
		return $valid_payment_order_statuses;
	}
	
	function woocommerce_order_needs_payment($needs_payment, $order, $valid_payment_order_statuses){
		return $needs_payment;
	}
	
	//PREVENTS META COPY FROM ROOT ORDER
	public function prevent_subscription_root_order_meta_copy( $meta ) {
		return $this->filter_subscription_meta( $meta );
	}
	
	//PREVENTS META COPY TO RENEWAL ORDER
	public function prevent_subscription_renewal_meta_copy( $meta ) {
		return $this->filter_subscription_meta( $meta );
	}
	
	public function woocommerce_order_refunded($order_id, $refund_id){
		try{
			//FIX NUMI
			$order = wc_get_order( $order_id );
			$order->update_meta_data("_hpay_last_refund_ts",time());
			$order->save_meta_data();
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	public function woocommerce_order_is_partially_refunded($is_partially_refunded, $order_id, $refund_id){
		global $hpay_partial_refunded_orders;
		if(isset($hpay_partial_refunded_orders)){
			if(isset($hpay_partial_refunded_orders[$order_id])){
				$is_partially_refunded = true;
				return $is_partially_refunded;
			}	
		}
		
		$order = hpay_get_order($order_id);
		if($order){
			$hpay_status = $order->get_meta("_hpay_status");
			if($hpay_status){
				if(stripos($hpay_status,"PARTIALLY-REFUNDED") !== false){
					$is_partially_refunded = true;
					return $is_partially_refunded;
				}
			}	
		}
		return $is_partially_refunded;
	}
	
	public function setup_checkout_blocks_api(){
		try{
			if(function_exists('woocommerce_store_api_register_endpoint_data')){
				woocommerce_store_api_register_endpoint_data(
					array(
						'endpoint'        => 'cart',
						'namespace'       => 'hpay',
						'data_callback'   => function() {
							return array(
								"cart" => $this->getHPayCart()
							);
						},
						'schema_callback' => function() {
							return array(
								'properties' => array(
									'cart'    => array(
										'description' => __( 'HPay Cart Data', 'holestpay' ),
										'type'        => array( 'object', 'null' ),
										'context'     => array( 'view', 'edit' ),
										'readonly'    => false
									)
								),
							);
						},
						'schema_type'     => ARRAY_A,
					)
				);
			}
			
			if(function_exists('woocommerce_store_api_register_update_callback')){
				woocommerce_store_api_register_update_callback(
					array( 
						'namespace' => 'hpay',
						'callback'  => function( $data ) {
							
							if($data && isset($data["order_billing"])){
								
								
								$company_reg_id_field = "";
								if($this->getSetting("company_reg_id","") == 1){
									$company_reg_id_field =  "company_reg_id";
								}else if($this->getSetting("reg_id_field","")){
									$company_reg_id_field =  $this->getSetting("reg_id_field","");
								}
								
								
								$company_tax_id_field = "";
								if($this->getSetting("company_tax_id","") == 1){
									$company_tax_id_field =  "company_tax_id";
								}else if($this->getSetting("tax_id_field","")){
									$company_tax_id_field =  $this->getSetting("tax_id_field","");
								}
								
								
								$is_company_field = "";
								if($this->getSetting("is_company","") == 1){
									$is_company_field =  "is_company";
								}else if($this->getSetting("is_company_field","")){
									$is_company_field =  $this->getSetting("is_company_field","");
								}
								
								foreach($data["order_billing"] as $key => $val){
									if($is_company_field && $key == "is_company"){
										WC()->session->set( "__blockc_billing_" . $is_company_field, $val);
									}else if($company_tax_id_field && $key == "company_tax_id"){
										WC()->session->set( "__blockc_billing_" . $company_tax_id_field, $val);
									}else if($company_reg_id_field && $key == "company_reg_id"){
										WC()->session->set( "__blockc_billing_" . $company_reg_id_field, $val);
									}else if($key == "company"){
										try{
											WC()->cart->get_customer()->set_billing_company($val); 
										}catch(Throwable $ex){
											//
										}
									}
								}
							}
						}
					)
				);
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	public function woocommerce_checkout_get_value($value, $input){
		try{
			
			$checkout_post_data_str = hpay_read_post_parm("post_data");
			if($checkout_post_data_str){
				$checkout_post_data = array();
				parse_str($checkout_post_data_str, $checkout_post_data);
				if($checkout_post_data && isset($checkout_post_data[$input])){
					WC()->session->set( "__blockc_" . $input, $checkout_post_data[$input]);
				}
			}
			
			return WC()->session->get( "__blockc_" . $input, $value);
		}catch(Throwable $ex){
			//
		}
		return $value;
	}
	
	
	public function set_custom_order_key( $order_id, $order = null) {
		try{
			if($this->getSetting("order_key_modification","")){
				if(!$order)
					$order = wc_get_order( $order_id );
				
				if(!$order->meta_exists("_hpay_status") && !$order->meta_exists("_hpay_payresponses")){
					$order_key = $order->get_order_key();
					$new_order_key = $order_key;
					
					$already_set = false;
					try{
						if(WC()->session)
							$already_set = WC()->session->get("hpay_key_for_{$order_id}");
					}catch(Throwable $kex){
						hpay_write_log("error",$kex);
					}
					
					if($already_set){
						$new_order_key = $already_set;
					}else if($this->getSetting("order_key_modification","") == "order_id"){
						if($order_key == $order_id){
							return;
						}
						$new_order_key = $order_id;
					}else if($this->getSetting("order_key_modification","") == "embed_order_id"){
						//MAX LEN IS 22!!!
						if(strpos($order_key,"-{$order_id}") !== false){
							return;
						}
						
						$plen = 22 - (strlen("-{$order_id}") + 4);
						$new_order_key = "wco_" . wp_generate_password( $plen, false ) . "-{$order_id}";
					}else if($this->getSetting("order_key_modification","") == "yymmdd-order_id"){
						if(strpos($order_key,date('ymd') . "-{$order_id}") !== false){
							return;
						}
						$new_order_key = date('ymd') . "-{$order_id}";
					}else if($this->getSetting("order_key_modification","") == "yymmddnnn-order_id"){
						if(strpos($order_key,date('ymd')) !== false && strpos($order_key,"-{$order_id}") !== false){
							return;
						}
						$n = intval(get_option("hpay_day_for_keyn_val",0)) + 1;
						if(get_option("hpay_day_for_keyn",'') != date('ymd')){
							update_option("hpay_day_for_keyn",date('ymd'), true);
							$n = 1;
						}
						update_option("hpay_day_for_keyn_val",$n, true);
						$new_order_key = date('ymd') . str_pad($n, 3, "0", STR_PAD_LEFT) . "-{$order_id}";
					}else if($this->getSetting("order_key_modification","") == "yymmdd-order_number"){
						if($order_key == date('ymd')."-".$order->get_order_number()){
							return;
						}
						$new_order_key = date('ymd')."-".$order->get_order_number();
					}else if($this->getSetting("order_key_modification","") == "order_number"){
						if($order_key == $order->get_order_number()){
							return;
						}
						$new_order_key = $order->get_order_number();
					}else{
						return;
					}
					
					try{
						if(WC()->session)
							WC()->session->set( "hpay_key_for_{$order_id}" , $new_order_key);
					}catch(Throwable $kex){
						hpay_write_log("error",$kex);
					}
					
					$order->set_order_key( $new_order_key );
					$order->save();

					$post = get_post( $order_id );
					$post->post_password = $new_order_key;
					wp_update_post( $post );
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	public function plugins_loaded(){
		try{
			if(function_exists("wc_get_order_statuses")){
				global $hpay_plugins_loaded_called;
				if(isset($hpay_plugins_loaded_called))
					return;
				
				$hpay_plugins_loaded_called = true;
				$woo_statuses = wc_get_order_statuses();
				foreach($woo_statuses as $status => $name){
					$new_status = str_replace("wc-","",$status);
					add_action("woocommerce_order_status_{$new_status}", function($order_id) use ($new_status){
						try{
							$order = hpay_get_order($order_id);
							if($order){
								if(HPAY_DEBUG_TRACE)
									hpay_write_log("trace","status set: woocommerce_order_status_{$new_status}:" . $order->get_id());
								HPay_Core::instance()->checkCallFiscalOrIntegrationActions($order,$new_status);
							}else{
								if(HPAY_DEBUG_TRACE)
									hpay_write_log("trace","status no order found: woocommerce_order_status_{$new_status}");
							}
						}catch(Throwable $ex){
							hpay_write_log("error",$ex);
						}
					},1,1);
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	public function woocommerce_saved_order_items($order_id, $items){
		try{
			$order = null;
			if(!is_numeric($order_id)){
				if(is_a($order_id,"WC_Order")){
					$order = $order_id;
					$order_id = $order->get_id();
				}
			}
			if(!$order)
				$order = hpay_get_order($order_id);
			
			if(hpay_woo_order_type($order_id) != "shop_order"){
				return;
			}
			
			if($order->meta_exists("_hpay_status")){
				$hitems = $this->getOrderItems($order);
				$hash = md5(json_encode($hitems));
				if($order->get_meta("_hpay_items_hash") != $hash){
					$order->save_meta_data();	
					$this->store_order($order, null, true);
					$order->update_meta_data("_hpay_items_hash",md5(json_encode($hitems)));
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	public function setOrderStatus($order, $status , $comment = ""){
		try{
			global $hpay_mapped_status_dict;
			global $hpay_mapped_status_set;
			
			if(!isset($hpay_mapped_status_dict))
				$hpay_mapped_status_dict = array();
			
			if($order && $status){
				
				$comment = "{$comment} (hpay)";
				
				if(!isset($hpay_mapped_status_set)){
					$hpay_mapped_status_set = array();
				}
				
				$hpay_mapped_status_set[$order->get_id()] = $status;
				
				$last_refund_ts = $order->get_meta("_hpay_last_refund_ts");
				if(!$last_refund_ts)
					$last_refund_ts = 0;
				
				if($last_refund_ts + 60 < time())//FIX NUMI
					$order->update_status($status, $comment);
				
				if(isset($hpay_mapped_status_dict[$order->get_id()])){
					$hpay_mapped_status_track = $hpay_mapped_status_dict[$order->get_id()];
					if($hpay_mapped_status_track){
						if($hpay_mapped_status_track[1] == $status){
							$statuses_set = $order->get_meta("_hpay_statuses_set");
							if(!$statuses_set){
								$statuses_set = array();
							}
							$statuses_set[$hpay_mapped_status_track[0]] = array(
								"status" => $status,
								"ts"     => time()
							);
							$order->update_meta_data("_hpay_statuses_set",$statuses_set);
							$order->save_meta_data();
						}
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	public function shouldSetStatus($hpay_resp, $order){
		try{
			global $hpay_mapped_status_dict;
			if(!isset($hpay_mapped_status_dict))
				$hpay_mapped_status_dict = array();
			
			$hpay_mapped_status_track = null;
			$hpay_status = "";
			
			if(is_numeric($order)){
				$order = hpay_get_order($order);
			}
			if(!$order)
				return "";
			
			if(is_string($hpay_resp)){
				$hpay_status = $hpay_resp;
			}
			
			if(isset($hpay_resp["status"])){
				$hpay_status = $hpay_resp["status"];
			}
			
			if(isset($hpay_mapped_status_dict[$order->get_id()])){
				$hpay_mapped_status_track = $hpay_mapped_status_dict[$order->get_id()];
			}
			
			/*
			$current_hpay_pay_status = $this->orderHpayPaymentStatus($order);
			
			if(!$current_hpay_pay_status){
				$current_hpay_pay_status = "";
			}else{
				if($current_hpay_pay_status == $this->extractPaymentStatus($hpay_status)){
					return "";
				}
			}
			
			$prev_hpay_pay_status = $this->extractPaymentStatus($order->get_meta("_hpay_status_prev"));
			if($prev_hpay_pay_status && $current_hpay_pay_status == $prev_hpay_pay_status){
				return "";
			}
			*/
			
			$wc_order_status = "";
			if(stripos($hpay_status,"PAID") !== false || stripos($hpay_status,"SUCCESS") !== false){
				$wc_order_status = $this->getSetting("woo_status_map_paid",""); 
				$hpay_mapped_status_track = array("woo_status_map_paid",$wc_order_status);
			}else if(stripos($hpay_status,"RESERVE") !== false){
				$wc_order_status = $this->getSetting("woo_status_map_reserve",""); 
				$hpay_mapped_status_track = array("woo_status_map_reserve",$wc_order_status);
			}else if(stripos($hpay_status,"AWAITING") !== false){
				$wc_order_status = $this->getSetting("woo_status_map_awaiting",""); 
				$hpay_mapped_status_track = array("woo_status_map_awaiting",$wc_order_status);
			}else if(stripos($hpay_status,"VOID") !== false){
				$wc_order_status = $this->getSetting("woo_status_map_void",""); 
				$hpay_mapped_status_track = array("woo_status_map_void",$wc_order_status);
			}else if(stripos($hpay_status,"PARTIALLY-REFUNDED") !== false){
				$wc_order_status = $this->getSetting("woo_status_map_partial_refund",""); 
				$hpay_mapped_status_track = array("woo_status_map_partial_refund",$wc_order_status);
			}else if(stripos($hpay_status,"REFUND") !== false){
				$wc_order_status = $this->getSetting("woo_status_map_refund",""); 
				$hpay_mapped_status_track = array("woo_status_map_refund",$wc_order_status);
			}
			
			if(stripos($hpay_status,"@DELIVERED") !== false){
				if(
					stripos($hpay_status,"@DELIVERY") === false
					&&
					stripos($hpay_status,"@SUBMITED") === false
					&&
					stripos($hpay_status,"@READY") === false
					&&
					stripos($hpay_status,"@PREPARING") === false
				){
					if($this->getSetting("woo_status_map_shipped","")){
						$wc_order_status = $this->getSetting("woo_status_map_shipped",""); 
						$hpay_mapped_status_track = array("woo_status_map_shipped",$wc_order_status);
					}
				}
			}
			
			if(stripos($wc_order_status,"wc-") === 0){
				$wc_order_status = substr($wc_order_status,3);
				if($hpay_mapped_status_track){
					$hpay_mapped_status_track[1] = $wc_order_status;
				}
			}
			
			if($hpay_mapped_status_track){
				$hpay_mapped_status_dict[$order->get_id()] = $hpay_mapped_status_track;
			}
			
			$statuses_set = $order->get_meta("_hpay_statuses_set");
			
			if($statuses_set && $hpay_mapped_status_track){
				$status_set_policy = $this->getSetting("woo_status_set_policy","each"); 
				if($status_set_policy == "once"){
					$hpay_mapped_status_track[1] = "";
					$hpay_mapped_status_dict[$order->get_id()] = $hpay_mapped_status_track;
					return "";
				}else if($status_set_policy == "each"){
					if(isset($statuses_set[$hpay_mapped_status_track[0]])){
						$hpay_mapped_status_track[1] = "";
						$hpay_mapped_status_dict[$order->get_id()] = $hpay_mapped_status_track;
						return "";
					}
				}
			}
			
			if($wc_order_status){
				//hpay_write_log("trace",array($order->get_id() , $current_hpay_pay_status . " -> " . $wc_order_status, debug_backtrace()));
			}
			
			return $wc_order_status;
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
			return "";
		}
	}
	
	public function shouldSetStatusBecauseOfDelivery($hpay_resp, $order){
		try{
			global $hpay_mapped_status_dict;
			if(!isset($hpay_mapped_status_dict))
				$hpay_mapped_status_dict = array();
			
			$hpay_mapped_status_track = null;
			
			if(is_numeric($order)){
				$order = hpay_get_order($order);
			}
			
			if(!$order)
				return false;
			
			if(isset($hpay_mapped_status_dict[$order->get_id()])){
				$hpay_mapped_status_track = $hpay_mapped_status_dict[$order->get_id()];
			}
			
			if($this->getSetting("woo_status_map_shipped","")){
				$hpay_status = "";
			
				if(is_string($hpay_resp)){
					$hpay_status = $hpay_resp;
				}
				
				if(isset($hpay_resp["status"])){
					$hpay_status = $hpay_resp["status"];
				}
				
				$current_hpay_pay_status = $this->orderHpayPaymentStatus($order);
				
				if($current_hpay_pay_status == $hpay_status){
					return false;
				}
				
				if($hpay_status){
					if(stripos($hpay_status,"@DELIVERED") !== false){
						if(
							stripos($hpay_status,"@DELIVERY") === false
							&&
							stripos($hpay_status,"@SUBMITED") === false
							&&
							stripos($hpay_status,"@READY") === false
							&&
							stripos($hpay_status,"@PREPARING") === false
						){
							if($this->getSetting("woo_status_map_shipped","")){
								$wc_order_status = $this->getSetting("woo_status_map_shipped",""); 
								$hpay_mapped_status_track = array("woo_status_map_shipped",$wc_order_status);
								if(stripos($wc_order_status,"wc-") === 0){
									$wc_order_status = substr($wc_order_status,3);
									$hpay_mapped_status_track[1] = $wc_order_status;
								}
								
								$hpay_mapped_status_dict[$order->get_id()] = $hpay_mapped_status_track;
								
								$statuses_set = $order->get_meta("_hpay_statuses_set");
								if($statuses_set && $hpay_mapped_status_track){
									$status_set_policy = $this->getSetting("woo_status_set_policy","each"); 
									if($status_set_policy == "once"){
										$hpay_mapped_status_track[1] = "";
										$hpay_mapped_status_dict[$order->get_id()] = $hpay_mapped_status_track;
										return false;
									}else if($status_set_policy == "each"){
										if(isset($statuses_set[$hpay_mapped_status_track[0]])){
											$hpay_mapped_status_track[1] = "";
											$hpay_mapped_status_dict[$order->get_id()] = $hpay_mapped_status_track;
											return false;
										}
									}
								}
								return $wc_order_status;
							}
						}
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return false;
	}
	
	public function wc_checkout_order_processed($order_id, $posted_data, $order){
		try{
			global $hpay_doing_order_update;
			if($hpay_doing_order_update){
				return;
			}
			
			$store = $this->should_store_wc_order($order);
			if($store){
				$this->store_order($order ? $order: $order_id);
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}		
	}
	
	public function wc_payment_complete($order_id){
		try{
			global $hpay_doing_order_update;
			if($hpay_doing_order_update){
				return;
			}
			
			$store = $this->should_store_wc_order($order_id);
			
			if($store){
				$this->store_order($order_id);
			}	
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	} 
	
	public function wc_order_status_changed( $order_id, $old_status, $new_status, $order = null){
		try{
			global $hpay_doing_order_update;
			if($hpay_doing_order_update){
				return;
			}
			
			if(!$order){
				$order = hpay_get_order($order_id);
			}
			
			if(!$order){
				return;
			}
			
			$is_hpay_pm   =  strpos($order->get_payment_method(),"hpaypayment-") !== false;
			$hpay_status  = null;
			
			if($order->meta_exists("_hpay_status")){
				$hpay_status = $order->get_meta("_hpay_status");
			}
			
			if(!$order->get_payment_method() || !$is_hpay_pm || ($is_hpay_pm && !$hpay_status)){
				$store = $this->should_store_wc_order($order_id, true);
				if($store){
					$this->store_order($order_id);
				}
			} 
			
			if(HPAY_DEBUG_TRACE)
				hpay_write_log("trace","status change trigger checkCallFiscalOrIntegrationActions:" . $order_id . ":" . $new_status);
				
			$this->checkCallFiscalOrIntegrationActions($order, $new_status);
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	function wc_order_status_changed_request_end( $order_id, $old_status, $new_status, $order = null){
		try{
			global $hpay_mapped_status_set;
			if($hpay_mapped_status_set){
				if(!empty($hpay_mapped_status_set)){
					global $wpdb;
					//IF SOME OTHER PLUGIN ALTERS STATUS
					foreach($hpay_mapped_status_set as $order_id => $status){
						try{
							if(stripos($status,"wc-") !== 0){
								$status = "wc-{$status}";
							}
							$status_current = "";
							if(hpay_wchps_enabled()){
								$status_current = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id));
								$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}wc_orders SET status = %s WHERE id = %d", $status, $order_id));
							}else{
								$status_current = $wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->prefix}posts WHERE ID = %d", $order_id));
								$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}posts SET post_status = %s WHERE ID = %d", $status, $order_id));
							}
							
							if($status_current != $status){
								$order = hpay_get_order($order_id, true);
								$order->add_order_note( __("(hpay) Unexpected status change by a external plugin. Corrected back:","holestpay") . " {$status_current} &rarr; {$status}");
							}
							
						}catch(Throwable $ex){
							hpay_write_log("error",$ex);	
						}
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	public function checkCallFiscalOrIntegrationActions($order, $new_status = null){
		try{
			global $hpay__checkCallFiscalOrIntegrationActions;
			if(!isset($hpay__checkCallFiscalOrIntegrationActions))
				$hpay__checkCallFiscalOrIntegrationActions = array();
			
			if(!$new_status){
				$new_status = str_replace("wc-","",$order->get_status());
			}
			
			if(isset($hpay__checkCallFiscalOrIntegrationActions["" . $order->get_id() . "_" . $new_status])){
				if(HPAY_DEBUG_TRACE)
					hpay_write_log("trace","SKIP:" . $order->get_id() . ":" . $new_status .":checkCallFiscalOrIntegrationActions");
				return;
			}
			
			$hpay__checkCallFiscalOrIntegrationActions["" . $order->get_id() . "_" . $new_status] = 1;
			
			$fiscal_methods = $this->getPOSSetting("fiscal",null);
			if(!empty($fiscal_methods)){
				$s = $new_status;
				if(strpos($s,"wc-") !== 0){
					$s = "wc-{$s}";
				}
				foreach($fiscal_methods as $fiscal_method){
					try{
						$trigger_statuses = $this->getSetting("woo_status_trigger_" . $fiscal_method["Uid"],array());
						if(is_string($trigger_statuses)){
							$trigger_statuses = explode(",",$trigger_statuses);
						}
						
						if(HPAY_DEBUG_TRACE)
							hpay_write_log("trace",array("FISCAL TRIGGER CHECKING:" . $order->get_id(), $fiscal_method["Uid"] , $s , $trigger_statuses));
						
						if(!empty($trigger_statuses)){
							if($s && in_array($s, $trigger_statuses)){
								if(HPAY_DEBUG_TRACE)
									hpay_write_log("trace",$order->get_id() .":".$s .":checkCallFiscalOrIntegrationActions");
								$this->callFiscalOrIntegrationDefaultAction($fiscal_method, $order);
							}
						}
					}catch(Throwable $ex){
						hpay_write_log("error",$ex);
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	public function wc_order_status_immediate($order_id){
		try{
			if(is_a($order_id,"WC_Order")){
				$order_id = $order_id->get_id();
			}
			
			$order_id = intval($order_id);
			
			if($order_id){
				global $wpdb;
				$immediate_status = null;
				
				if(hpay_wchps_enabled()){
					$immediate_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id));
				}else{
					$immediate_status = $wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->prefix}posts WHERE ID = %d", $order_id));
				}
				return $immediate_status;
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return null;
	}
	
	public function wc_order_has_status_immediate($order_id, $check_status){
		try{
			if(is_a($order_id,"WC_Order")){
				$order_id = $order_id->get_id();
			}
			$order_id = intval($order_id);
			if($order_id){
				$immediate_status = $this->wc_order_status_immediate($order_id);
				if(is_array($check_status)){
					foreach($check_status as $index => $stat){
						if(str_ireplace("wc-","",$immediate_status) == str_ireplace("wc-","",$stat)){
							return true;
						}
					}
				}else{
					return str_ireplace("wc-","",$immediate_status) == str_ireplace("wc-","",$check_status);
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return false;
	}
	
	public function wc_cartHasSubsription(){
		try{
			if(class_exists("WC_Subscriptions_Cart")){
				if(WC_Subscriptions_Cart::cart_contains_subscription()){
					return "wcs";
				}
			}
			
			if(class_exists("YWSBS_Subscription_Cart")){
				if($this->getSetting("yith_enable",false)){
					if(YWSBS_Subscription_Cart::cart_has_subscriptions()){
						return "yith";
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return false;
	}
	
	public function wc_isSubsription($order_id_or_order){
		try{
			if(function_exists("wcs_order_contains_subscription")){
				if(wcs_order_contains_subscription($order_id_or_order)){
					return "wcs";
				}
			}
			
			if(function_exists("ywsbs_is_an_order_with_subscription")){
				if($this->getSetting("yith_enable",false)){
					if(ywsbs_is_an_order_with_subscription($order_id_or_order) == "parent"){
						return "yith";
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return false;
	}
	
	public function wc_isSubsriptionRenewalOrder($order_id_or_order){
		try{
			if(function_exists("wcs_order_contains_renewal")){
				if(wcs_order_contains_renewal($order_id_or_order)){
					return "wcs";
				}
			}
			
			if(function_exists("ywsbs_is_an_order_with_subscription")){
				if($this->getSetting("yith_enable",false)){
					if(ywsbs_is_an_order_with_subscription($order_id_or_order) == "renew"){
						return "yith";
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return false;
	}
	
	public function wcs_renewal_order_created($renewal_order, $subscription){
		if(!$renewal_order)
			return $renewal_order;
		try{
			if($subscription){
				if(is_a($subscription,"WC_Subscription")){
					if($subscription->get_payment_method()){
						if(strpos($subscription->get_payment_method(),"hpaypayment-") === 0){
							$hmethod = HPay_Core::payment_method_instance($subscription->get_payment_method());
							if($hmethod){
								if($hmethod->supportsOperation("charge")){
									if($this->getSetting("no_auto_charges","") != 1){
										if(hpay_read_post_parm('wc_order_action') == 'wcs_create_pending_renewal'){
											//THIS IS USER ACTION WE WILL NOT CHARGE IMMEDIATLY
											$renewal_order->update_meta_data("_hpay_charge_after_ts", time() + 1200);
											$renewal_order->save();
											return $renewal_order;
										}else{
											ob_start();
											
											$renewal_order->update_meta_data("_hpay_charge_attempt_ts",time());
											$renewal_order->save();
											
											$charge_res = do_action("hpay_do_charge_order", $renewal_order->get_id(), null, $hmethod);
											
											$dump = ob_get_clean();
											return hpay_get_order($renewal_order->get_id());
										}
									}
								}
							}
						}
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return $renewal_order;
	}
	
	public function wc_check_for_subscriptions_for_charge($check_for_order_id = null, $executionlimit_sec = 5){
		$charge_count = 0;
		
		try{
		
			if(empty(HPay_Core::payment_methods_supporting_operation("charge"))){
				return;
			}
			
			global $wpdb;
			$pending_order_charges = $wpdb->get_results($wpdb->prepare("SELECT p.ID as order_id, pm.meta_value as payment_method_id FROM {$wpdb->prefix}posts as p LEFT JOIN {$wpdb->prefix}postmeta as pm ON pm.post_id = p.ID WHERE " . ( $check_for_order_id ? (" p.ID = " . intval($check_for_order_id) . " AND " ) : "" ) . " p.post_type='shop_order' AND p.post_status = %s AND pm.meta_key = '_payment_method' AND pm.meta_value LIKE 'hpaypayment-%' ",'wc-pending'));
			if(hpay_wchps_enabled()){
				try{
					$pending_order_charges = array_merge($pending_order_charges, $wpdb->get_results($wpdb->prepare("SELECT id as order_id,payment_method as payment_method_id FROM {$wpdb->prefix}wc_orders WHERE " . ( $check_for_order_id ? (" id = " . intval($check_for_order_id) . " AND " ) : "" ) . " type='shop_order' AND status = %s AND payment_method LIKE 'hpaypayment-%' ",'wc-pending')));
				}catch(Throwable $mdbtable){
					//
				}
			}
			
			$ts = time();
			foreach($pending_order_charges as $charge){
				try{
					$renewal = $this->wc_isSubsriptionRenewalOrder($charge->order_id);
					
					if($renewal){
						$ts = time();
						
						$order = hpay_get_order($charge->order_id);
						if(!$order)
							continue;
						
						$hpay_charge_after_ts = intval($order->get_meta("_hpay_charge_after_ts"));
						if($hpay_charge_after_ts){
							if(intval($hpay_charge_after_ts)){
								if(intval($hpay_charge_after_ts) > time()){
									continue;
								}
							}
						}else{
							$order->update_meta_data("_hpay_charge_after_ts",time() + 1200);
							$order->save_meta_data();
							continue;
						}
						
						$charge_try = 1;
						if($order->get_meta("_hpay_charge_tries")){
							$charge_try = intval($order->get_meta("_hpay_charge_tries")) + 1;
						}
						
						if($charge_try <= 3){
							
							$order->update_meta_data("_hpay_charge_tries",$charge_try);
							$order->update_meta_data("_hpay_charge_attempt_ts",time());
							
							$order->save_meta_data();
							
							$hpay_payment_status = HPay_Core::instance()->orderHpayPaymentStatus($order);
							if(!in_array($hpay_payment_status,array("PAID","RESERVED","SUCCESS","AWAITING","REFUNDED","PARTIALLY-REFUNDED","VOID"))){
								$hmethod = HPay_Core::payment_method_instance($charge->payment_method_id);
								if($hmethod){
									if($hmethod->supportsOperation("charge")){
										if($this->getSetting("no_auto_charges","") != 1){
											ob_start();
											$charge_res = do_action("hpay_do_charge_order", $charge->order_id, null, $hmethod);
											
											$is_success = false;
											if($charge_res){
												if(isset($charge_res["status"])){
													if(stripos($charge_res["status"],"SUCCESS") !== false
														||
												       stripos($charge_res["status"],"PAID") !== false	
													    ||
												       stripos($charge_res["status"],"RESERVED") !== false){
														   $is_success = true;
													   }	
												}
											}
											
											if(!$is_success){
												if($charge_try == 1){
													$order->update_meta_data("_hpay_charge_after_ts",time() + 3600);
												}else if($charge_try == 2){
													$order->update_meta_data("_hpay_charge_after_ts",time() + 86400);
												}else if($charge_try == 3){
													$order->update_meta_data("_hpay_charge_after_ts",time() + 86400);
												}
												$order->save_meta_data();
											}
											
											$dump = ob_get_clean();
										}
									}
								}
							}
						}
					}
				}catch(Throwable $ex){
					hpay_write_log("error",$ex);
				}
				
				if(time() + $executionlimit_sec < $ts){
					break;
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}

		return $charge_count;	
	}
	
	public function wc_getOrderRest($order_id){
		try{
			add_filter('rest_authentication_errors',"hpay_return_true",99,1);
			add_filter('woocommerce_rest_check_permissions',"hpay_return_true",99,4);

			global $sc_call_rest_server;
			$rest_req = new WP_REST_Request( 'GET', "/wc/v3/orders/{$order_id}");
			$rest_response = rest_do_request( $rest_req );

			if(!isset($sc_call_rest_server)){
				$sc_call_rest_server = rest_get_server();
			}

			if($rest_response->is_error()){
				$response = $sc_call_rest_server->response_to_data( $rest_response, false );
				return array(
					"error" => json_encode($response)
				);
			}

			$order_rest = $sc_call_rest_server->response_to_data( $rest_response, false );
			
			remove_filter('rest_authentication_errors',"hpay_return_true",99);
			remove_filter('woocommerce_rest_check_permissions',"hpay_return_true",99);
			$order_rest = apply_filters("hpay_filter_woocommerce_order_data",$order_rest);
			return $order_rest;
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
			return null;
		}
	}
	
	public function wc_filter_checkout_payment_gateways($gateways){
		try{
			if(is_checkout() || is_cart()){
				
				$settings = $this->getSettings();
				$hide = false;
				
				if($settings["enabled"] == "0"){
					$hide = true;
				}else if($settings["enabled"] == "admin" && function_exists('current_user_can')){
					if(!current_user_can( 'manage_options' )){
						$hide = true;
					}
				}
				
				
				foreach( $gateways as $paymentid => $data){
					if(strpos($paymentid,"hpaypayment-") === 0){
						if($hide){
							unset($gateways[$paymentid]);
						}else{
							$hmethod = HPay_Core::payment_method_instance($paymentid);
							
							if($hmethod && function_exists("WC")){
								if(WC()->cart){
								
									$total = WC()->cart->get_total();
									
									if($hmethod->getHProp("Hidden")){
										unset($gateways[$paymentid]);
										continue;
									}
									
									if($hmethod->getHProp("Minimal Order Amount")){
										if($total < HPay_Core::parsePriceToCartCurrency($hmethod->getHProp("Minimal Order Amount"))){
											unset($gateways[$paymentid]);
											continue;
										}
									}
									
									if($hmethod->getHProp("Maximal Order Amount")){
										if($total > HPay_Core::parsePriceToCartCurrency($hmethod->getHProp("Maximal Order Amount"))){
											unset($gateways[$paymentid]);
											continue;
										}
									}
									
									$country = WC()->customer->get_shipping_country();
									if(!$country){
										$country = WC()->customer->get_billing_country();
									}
									
									if($hmethod->getHProp("Only For Countries")){
										$only_countries = $hmethod->getHProp("Only For Countries");
										if(!empty($only_countries)){
											
											if(!$country){
												unset($gateways[$paymentid]);
												continue;
											}
												
											if(!in_array($country,$only_countries)){
												unset($gateways[$paymentid]);
												continue;
											}
										}
									}
									
									if($hmethod->getHProp("Excluded Countries")){
										$excluded_countries = $hmethod->getHProp("Excluded Countries");
										if(!empty($excluded_countries)){
											if(in_array($country,$only_countries)){
												unset($gateways[$paymentid]);
												continue;
											}
										}
									}
								}
							}
						}
					}
				}
				
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return $gateways;
	}
	
	
	public function wc_filter_shipping_methods($rates){
		try{
			if(is_checkout() || is_cart()){
				$settings = $this->getSettings();
				$hide = false;
				
				if($settings["enabled"] == "0"){
					$hide = true;
				}else if($settings["enabled"] == "admin" && function_exists('current_user_can')){
					if(!current_user_can( 'manage_options' )){
						$hide = true;
					}
				}
				
				
				foreach( $rates as $rate_id => $rate){
					if(strpos($rate->method_id,"hpayshipping-") === 0){
						if($hide){
							unset($rates[$rate_id]);
							continue;
						}else{
							$hmethod = HPay_Core::shipping_method_instance($rate->method_id);
							
							if($hmethod && function_exists("WC")){
								if(WC()->cart){
									$total_no_shipping = WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax();
									
									if($hmethod->getHProp("Minimal Order Amount")){
										if($total_no_shipping < HPay_Core::parsePriceToCartCurrency($hmethod->getHProp("Minimal Order Amount"))){
											unset($rates[$rate_id]);
											continue;
										}
									}
									
									if($hmethod->getHProp("Maximal Order Amount")){
										if($total_no_shipping > HPay_Core::parsePriceToCartCurrency($hmethod->getHProp("Minimal Order Amount"))){
											unset($rates[$rate_id]);
											continue;
										}
									}
									
									$country = WC()->customer->get_shipping_country();
									if(!$country){
										$country = WC()->customer->get_billing_country();
									}
									
									
									if($hmethod->getHProp("Only For Countries")){
										$only_countries = $hmethod->getHProp("Only For Countries");
										if(!empty($only_countries)){
											
											if(!$country){
												unset($rates[$rate_id]);
												continue;
											}
												
											if(!in_array($country,$only_countries)){
												unset($rates[$rate_id]);
												continue;
											}
										}
									}
									
									if($hmethod->getHProp("Excluded Countries")){
										$excluded_countries = $hmethod->getHProp("Excluded Countries");
										if($excluded_countries && !empty($excluded_countries)){
											if(in_array($country,$excluded_countries)){
												unset($rates[$rate_id]);
												continue;
											}
										}
									}
								}
							}
						}
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return $rates;
	}
	
	public function thankyou_page($order_id){
		try{
			$order = null;
			
			if(is_numeric($order_id)){
				$order = hpay_get_order($order_id);
			}else if(is_a($order_id,"WC_Order")){
				$order = $order_id; 
				$order_id = $order->get_id();
			}
			
			if($order){
				global $hpay_fiscal_shipping_outputed;
				if(!isset($hpay_fiscal_shipping_outputed))
					$hpay_fiscal_shipping_outputed = array();
		
				if(stripos($order->get_payment_method(),"hpaypayment-") === false){
					$fiscal_html = $order->get_meta('_fiscal_html');
					$shipping_html = $order->get_meta('_shipping_html');
					
					if($fiscal_html && !isset($hpay_fiscal_shipping_outputed["{$order_id}_fiscal"])){
						echo "<div class='hpay-fiscal-info'>{$fiscal_html}</div>";
						$hpay_fiscal_shipping_outputed["{$order_id}_fiscal"] = true;
					}
					
					if($shipping_html && !isset($hpay_fiscal_shipping_outputed["{$order_id}_shipping"])){
						echo "<div class='hpay-shipping-info'>{$shipping_html}</div>";
						$hpay_fiscal_shipping_outputed["{$order_id}_shipping"] = true;
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	public function woocommerce_subscription_fixes(){
		try{
			if(is_admin()){
				if(hpay_read_post_parm("action","") == "woocommerce_save_variations"){
					if(hpay_read_post_parm("product-type","") == "variable-subscription"){
						$vids = hpay_read_post_parm("variable_post_id");
						if($vids && !empty($vids)){
							$vs_data_arr = array_filter($_POST, function($key) {
								return strpos($key,"variable_subscription_") === 0;
							}, ARRAY_FILTER_USE_KEY);	
							if(!empty($vs_data_arr)){
								foreach($vs_data_arr as $prop => $pdata){
									foreach($pdata as $pindex => $val){
										if(isset($vids[$pindex])){
											$vid = $vids[$pindex];
											update_post_meta($vid, "_" . str_replace("variable_","",$prop), $val);
										}
									}
								}
							}
						}
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
}