<?php
//HOLESTPAY 2023
if(!defined("HPAY_PRODUCTION_URL")){
	die("Direct access is not allowed");
};


trait HPay_Core_Data {
	
	
	public function getAdditionalBillingData($cart_data = null){
		
		$data = array(
			"is_company_field" => "",
			"company_reg_id_field" => "",
			"company_tax_id_field" => "",
			"is_company" => "",
			"company_reg_id" => "",
			"company_tax_id" => ""
		);
		
		try{
			if(!function_exists("WC"))
				return $data;
			
			if(!$cart_data && function_exists('WC')){
				if(WC()->cart)
					$cart_data = WC()->cart;
			}
			
			if(!isset($cart_data))
				return $data;
				
			if($cart_data->is_empty()){
				return $data;
			}
			
			if($this->getSetting("company_reg_id","") == 1){
				$data["company_reg_id_field"] =  "company_reg_id";
			}else if($this->getSetting("reg_id_field","")){
				$data["company_reg_id_field"] =  $this->getSetting("reg_id_field","");
			}
			
			if($this->getSetting("company_tax_id","") == 1){
				$data["company_tax_id_field"] =  "company_tax_id";
			}else if($this->getSetting("tax_id_field","")){
				$data["company_tax_id_field"] =  $this->getSetting("tax_id_field","");
			}
			
			if($this->getSetting("is_company","") == 1){
				$data["is_company_field"] =  "is_company";
			}else if($this->getSetting("is_company_field","")){
				$data["is_company_field"] =  $this->getSetting("is_company_field","");
			}
			
			if(WC()->checkout){
				if($data["company_tax_id_field"])
					$data["company_tax_id"] = WC()->checkout->get_value('billing_' .$data["company_tax_id_field"]);
				if($data["company_reg_id_field"])
					$data["company_reg_id"] = WC()->checkout->get_value('billing_' .$data["company_reg_id_field"]);
				if($data["is_company_field"]){
					$val = WC()->checkout->get_value('billing_' .$data["is_company_field"]);
					if($val == "1" || $val == "on" || $val == "true" || $val == "yes" || strpos($val,"comp") !== false){
						$data["is_company"] = 1;
					}else{
						$data["is_company"] = 0;
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);	
		}
		return $data;
	}
	
	public function getHPayCart($cart_data = null){
		try{
			if(!function_exists("WC"))
				return null;
			
			if(!$cart_data && function_exists('WC')){
				if(WC()->cart)
					$cart_data = WC()->cart;
			}
			
			if(!isset($cart_data))
				return null;
				
			if($cart_data->is_empty()){
				return null;
			}
			
			$weight_unit    = get_option('woocommerce_weight_unit',"kg");
			$dimension_unit = get_option('woocommerce_dimension_unit',"cm");
			
			$company = "";
			
			$company_reg_id       = "";
			$company_reg_id_field = "";
			$company_tax_id       = "";
			$company_tax_id_field = "";
			$is_company           = 0;
			$is_company_field     = "";
			
			try{
				$b_data = $this->getAdditionalBillingData($cart_data);
				if($b_data){
					extract($b_data);	
				}
			}catch(Throwable $bex){
				hpay_write_log("error",$bex);
			}
			
			if(WC()->checkout){
				$company = $cart_data->get_customer()->get_billing_company();
				if(!$company){
					$company = WC()->checkout->get_value('billing_company');
				}
			}
			
			$chosen_shipping_method = null;
			$chosen_payment_method =  null;
			
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
				$chosen_payment_method = WC()->session->get('chosen_payment_method');
			}
			
			$hpayment_method_id  = null;
			$hshipping_method_id = null;
			
			if($chosen_payment_method){
				$hpay_paymethod = HPay_Core::payment_method_instance($chosen_payment_method);
				if($hpay_paymethod){
					$hpayment_method_id = $hpay_paymethod->hpay_id;
				}
			}
			
			$default_item_weight = null;
			$hpay_shipping_method = null;
			
			if($chosen_shipping_method){
				$hpay_shipping_method = HPay_Core::shipping_method_instance($chosen_shipping_method);
				if($hpay_shipping_method && is_object($hpay_shipping_method) && method_exists($hpay_shipping_method,"getHProp")){
					$hshipping_method_id = $hpay_shipping_method->hpay_id;
					$dweight = $hpay_shipping_method->getHProp("Default Item Weight Grams");
					if(intval($dweight)){
						$default_item_weight = intval($dweight);
					}
				}
			}
			
			$order_items = array();
			
			foreach ( $cart_data->get_cart() as $cart_item_key => $cart_item ) {
			   $product = @$cart_item['data'];
			   
			   $item_id   = @$cart_item['variation_id'] ? @$cart_item['variation_id'] : @$cart_item['product_id'];
			   $sku       = get_post_meta( $item_id, '_sku', true );
			   $quantity  = 1;
			   $tax_class = "";
			   
			   if(isset($cart_item['quantity'])){
				   $quantity  = intval($cart_item['quantity']);
			   }
			   
			   if(isset($cart_item['tax_class'])){
				   $tax_class = $cart_item['tax_class'];
			   }
			   
			   if(!$quantity)
				   $quantity = 1;
			   
			   $length  = "";
			   $width   = "";
			   $height  = "";
			   $weight  = "";
			   $split_pay_uid = "";
			   $virtual = ""; 
			   $title   = ""; 
			   $tax_class = "";
			   
			   if($item_id){
				   $product_or_variant = wc_get_product($item_id);
				   
				   if($product_or_variant){
					   $title = $product_or_variant->get_title();
					   $tax_class = $product_or_variant->get_tax_class();
					   
					   if(!$product_or_variant->is_virtual()){
						   $shippable = true;
						   $virtual   = false;
					   }else{
						   $shippable = false;
						   $virtual   = true;
					   }
					   
					   if($product_or_variant->has_dimensions()){
						   $length  = HPay_Core::convertToCM($product_or_variant->get_length(),$dimension_unit);
						   $width   = HPay_Core::convertToCM($product_or_variant->get_width(),$dimension_unit);
						   $height  = HPay_Core::convertToCM($product_or_variant->get_height(),$dimension_unit);
					   }
					  
					   if($product_or_variant->has_weight()){
						   $weight  = HPay_Core::convertToGrams($product_or_variant->get_weight(),$weight_unit);
						   if(!$weight && $default_item_weight){
							   $weight  = $default_item_weight;
						   }
					   }
					   
					   if($shippable && !$weight && $default_item_weight){
						   $weight  = $default_item_weight;
					   }
					   
					   $split_pay_uid = get_post_meta($product_or_variant->get_id(),"split_pay_uid",true);
				   }
			   }
			   
			   $itm = array(
					"posuid"        => $item_id,
					"type"          => "product",
					"name"          => $title,
					"sku"           => $sku,
					"qty"           => $quantity,
					"price"         => wc_get_price_including_tax( $product ) ,
					"subtotal"      => wc_get_price_including_tax( $product ) * $quantity,
					"tax_label"     => $tax_class,
					"length"        => $length,
					"width"         => $width,
					"height"        => $height, 
					"weight"        => $weight,
					"split_pay_uid" => $split_pay_uid,
					"virtual"       => $virtual
			   );
			   
			   if($shippable){
					$itm["warehouse"] = "";
			   }
			   $order_items[] = $itm;
			}
			
			$fees = array();
			
			$states_map_b = array(); 
			$states_map_s = array(); 
			
			if(function_exists('WC')){
				if(WC()->cart)
					$fees = WC()->cart->get_fees();
				
				try{
					if(WC()->countries){
						$states_map_b = WC()->countries->get_states( $cart_data->get_customer()->get_billing_country() );
					}	
				}catch(Throwable $stateex){
					//
				}
				
				try{
					if(WC()->countries){
						$states_map_s = WC()->countries->get_states( $cart_data->get_customer()->get_shipping_country() );
					}	
				}catch(Throwable $stateex){
					//
				}
			}			
			
			if(!empty($fees)){
				foreach($fees as $feeuid => $fee){
					$fee = (array)$fee;
					$itm = array(
						"posuid"        => $feeuid,
						"type"          => "fee",
						"name"          => @$fee["name"],
						"sku"           => @$fee["name"],
						"qty"           => 1,
						"price"         => floatval(@$fee["amount"]),
						"subtotal"      => floatval(@$fee["amount"]),
						"tax_label"     => @$fee["tax_class"],
						"virtual"       => true
				   );
				   $order_items[] = $itm;
				}
			}
			
			$hpaylang = HPay_Core::hpaylang();
			
			$state_b = "";
			$state_s = "";
			try{
				$state_b = $cart_data->get_customer()->get_billing_state();
				$state_s = $cart_data->get_customer()->get_shipping_state();
				
				if(isset($states_map_b[$state_b])){
					$state_b = $states_map_b[$state_b];
				}
				
				if(isset($states_map_s[$state_s])){
					$state_s = $states_map_b[$state_s];
				}
			}catch(Throwable $stateex){
				//
			}
			
			$cart = array(
				"cart_amount"       => wc_prices_include_tax() ? $cart_data->get_cart_contents_total() + $cart_data->get_cart_contents_tax() : $cart_data->get_cart_contents_total(),
				"order_amount"      => WC()->cart->get_total('raw'),
				"order_currency"    => esc_attr(hpay_get_wc_order_currency(null)),
				"order_items"       => $order_items,
				"order_billing" => array(
					"email"           => esc_attr($cart_data->get_customer()->get_billing_email()),
					"first_name"      => esc_attr($cart_data->get_customer()->get_billing_first_name()),
					"last_name"       => esc_attr($cart_data->get_customer()->get_billing_last_name()),
					"phone"           => esc_attr($cart_data->get_customer()->get_billing_phone()),
					"is_company"      => intval($is_company),
					"company"         => esc_attr($company),
					"company_tax_id"  => esc_attr($company_tax_id),
					"company_reg_id"  => esc_attr($company_reg_id),
					"address"         => esc_attr($cart_data->get_customer()->get_billing_address_1()),
					"address2"        => esc_attr($cart_data->get_customer()->get_billing_address_2()),
					"city"            => esc_attr($cart_data->get_customer()->get_billing_city()),
					"country"         => esc_attr($cart_data->get_customer()->get_billing_country()),
					"state"           => esc_attr($state_b),
					"postcode"        => esc_attr($cart_data->get_customer()->get_billing_postcode()),
					"lang"            => esc_attr(get_locale())
				),
				"order_shipping" => array(
					"shippable"   => $cart_data->needs_shipping(),
					"is_cod"      => $chosen_payment_method == "cod",
					"first_name"  => esc_attr($cart_data->get_customer()->get_shipping_first_name()),
					"last_name"   => esc_attr($cart_data->get_customer()->get_shipping_last_name()),
					"phone"       => esc_attr($cart_data->get_customer()->get_shipping_phone()),
					"company"     => esc_attr($cart_data->get_customer()->get_shipping_company()),
					"address"     => esc_attr($cart_data->get_customer()->get_shipping_address_1()),
					"address2"    => esc_attr($cart_data->get_customer()->get_shipping_address_2()),
					"city"        => esc_attr($cart_data->get_customer()->get_shipping_city()),
					"country"     => esc_attr($cart_data->get_customer()->get_shipping_country()),
					"state"       => esc_attr($state_s),
					"postcode"    => esc_attr($cart_data->get_customer()->get_shipping_postcode())
				),
				"payment_method"   => $hpayment_method_id,
				"shipping_method"  => $hshipping_method_id,
				"hpaylang"         => $hpaylang,
				"UI"               => array(
					"checkout_fields" => array(
						"company" => "#billing_company",
						"company_reg_id" => $company_reg_id_field ? ("#billing_" . $company_reg_id_field) : "",
						"company_tax_id" => $company_tax_id_field ? ("#billing_" . $company_tax_id_field) : "",
						"is_company" => $is_company_field ? ("input[name='billing_" . $is_company_field . "'],select[name='billing_" . $is_company_field . "']") : ""
					)
				)
			);
			
			try{
				if(HPay_Core::instance()->getSetting("always_require_vault","")){
					$cart["cof"] = "required"; 
				}else if(HPay_Core::instance()->wc_cartHasSubsription()){
					$cart["cof"] = "required";
				}
			}catch(Throwable $ex){
				
			}
			
			try{
				$hinstallments = apply_filters('hinstallments_installments',0);
				if($hinstallments && intval($hinstallments) > 0){//1 must be allowed for lock
					$cart["monthly_installments"] = intval($hinstallments);
				}
			}catch(Throwable $fex){
				hpay_write_log("error",$fex);
			}
			
			try{
				if(WC()->session){
					$hcsd = WC()->session->get( "hpay_checkout_sessiom_data", '');
					if($hcsd){
						$hcsd = json_decode($hcsd, true);
						if($hcsd){
							if(isset($hcsd["order_shipping"]) && isset($hcsd["order_shipping"]["dispenser_method_id"])){
								if(!$cart["shipping_method"] || $hcsd["order_shipping"]["dispenser_method_id"] != $cart["shipping_method"]){
									unset($hcsd["order_shipping"]["dispenser"]);
									unset($hcsd["order_shipping"]["dispenser_method_id"]);
									unset($hcsd["order_shipping"]["dispenser_desc"]);
								}
							}
							foreach($hcsd as $key => $value){
								if(is_array($value)){
									if(isset($cart[$key]) && $cart[$key]){
										foreach($value as $k => $v){
											$cart[$key][$k] = $v;
											if(!$cart[$key][$k]) unset($cart[$key][$k]);
										}
									}else{
										$cart[$key] = $value; 
										if(!$cart[$key]) unset($cart[$key]);
									}
								}else{
									$cart[$key] = $value; 
									if(!$cart[$key]) unset($cart[$key]);
								}	
							}
						}
					}
				}
			}catch(Throwable $sesex){
				hpay_write_log("error",$sesex);
			}	
			
			
			try{
				$cart_order_items = apply_filters( "hpay_cart_order_items", $order_items, $cart, $hpay_shipping_method);
				if($cart_order_items && !empty($cart_order_items) && is_array($cart_order_items)){
					$cart["order_items"] = $cart_order_items;
				}
			}catch(Throwable $fex){
				hpay_write_log("error",$fex);
			}
			
			return $cart;
		}catch(Throwable $ex){
			
			hpay_write_log("error",$ex);
			
			return array(
				"error" => $ex->getMessage()
			);
		}
	}
	
	public function getOrderItems($order, $hshipping_method = null, $itemids = false){
		
		$order_items = array();
		try{
			
			if(!$hshipping_method){
				$shipping_method_id  = null;  
				$shipping_method = @array_shift($order->get_shipping_methods());
				if($shipping_method){
					if(isset($shipping_method['method_id']))
						$shipping_method_id = $shipping_method['method_id'];
				}
				
				if($shipping_method_id){
					$hshipping_method = HPay_Core::shipping_method_instance($shipping_method_id);
				}
			}
			
			$default_item_weight = null;
			
			if($hshipping_method && is_object($hshipping_method) && method_exists($hshipping_method,"getHProp")){
				$dweight = $hshipping_method->getHProp("Default Item Weight Grams");
				if(intval($dweight)){
					$default_item_weight = intval($dweight);
				}
			}
			
			$dimension_unit = get_option('woocommerce_dimension_unit',"cm");
			$weight_unit    = get_option('woocommerce_weight_unit',"kg");
			$shippable      = false;
			
			foreach ( $order->get_items() as $item_id => $item ) {
					   
			   $id   = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			   $sku       = get_post_meta( $id, '_sku', true );
			   $quantity  = $item->get_quantity();
			   $tax_class = str_ireplace("inherit","",$item->get_tax_class());
			   
			   if(!$quantity)
				   $quantity = 1;
			   
			   $length  = "";
			   $width   = "";
			   $height  = "";
			   $weight  = "";
			   $split_pay_uid = "";
			   $virtual = ""; 
			   
			   if($id){
				   $product_or_variant = wc_get_product($id);
				   if($product_or_variant){
					   
					   if(!$product_or_variant->is_virtual()){
						   $shippable = true;
						   $virtual   = false;
					   }else{
						   $shippable = false;
						   $virtual   = true;
					   }
					   
					   if($product_or_variant->has_dimensions()){
						   $length  = HPay_Core::convertToCM($product_or_variant->get_length(),$dimension_unit);
						   $width   = HPay_Core::convertToCM($product_or_variant->get_width(),$dimension_unit);
						   $height  = HPay_Core::convertToCM($product_or_variant->get_height(),$dimension_unit);
					   }
					  
					   if($product_or_variant->has_weight()){
						   $weight  = HPay_Core::convertToGrams($product_or_variant->get_weight(),$weight_unit);
						   if(!$weight && $default_item_weight){
							   $weight  = $default_item_weight;
						   }
					   }
					   
					   if($shippable && !$weight && $default_item_weight){
						   $weight  = $default_item_weight;
					   }
					   
					   $split_pay_uid = get_post_meta($product_or_variant->get_id(),"split_pay_uid",true);
				   }
			   }
			   
			   $refunded = abs(floatval($order->get_total_refunded_for_item( $item_id )));
			   $refunded_qty = abs(floatval($order->get_qty_refunded_for_item( $item_id )));
			   
			   if($refunded){
					foreach ( $order->get_refunds() as $refund ) {
						foreach ( $refund->get_items('line_item') as $refunded_item ) {
							$refunded_item_id = (int) $refunded_item->get_meta( '_refunded_item_id' );
							if ( $refunded_item_id === $item_id ) {
								$taxes  = $refunded_item->get_taxes();
								if($taxes && isset($taxes["subtotal"])){
									if(!empty($taxes["subtotal"])){
										$refunded += abs(array_sum($taxes["subtotal"]));
									}
								}
								break;
							}
						}
					}
			   }
			   
			   $itm = array(
					"posuid"        => $id,
					"type"          => "product",
					"name"          => $item->get_name(),
					"sku"           => $sku,
					"qty"           => $quantity - intval($refunded_qty),
					"price"         => floatval($order->get_line_total($item, true, true)) / $quantity,
					"subtotal"      => floatval($order->get_line_total($item, true, true)),
					"refunded"      => $refunded,
					"refunded_qty"  => $refunded_qty,
					"tax_label"     => $tax_class,
					"tax_amount"    => floatval($item->get_total_tax()),
					"length"        => $length,
					"width"         => $width,
					"height"        => $height, 
					"weight"        => $weight,
					"split_pay_uid" => $split_pay_uid,
					"virtual"       => $virtual
			   );
			   
			   if($shippable){
					$itm["warehouse"] = "";
			   }
			   
			   if($itemids){
				  $itm["posoitemuid"] = $item_id; 
				  $order_items[$item_id] = $itm; 
			   }else
				  $order_items[] = $itm;
			}
			
			foreach ( $order->get_items("shipping") as $item_id => $item ) {
				$tax_class = str_ireplace("inherit","",$item->get_tax_class());
				
				$refunded = abs(floatval($order->get_total_refunded_for_item( $item_id, "shipping" )));
				$refunded_qty = abs(floatval($order->get_qty_refunded_for_item( $item_id )));
				
				if($refunded){
					foreach ( $order->get_refunds() as $refund ) {
						foreach ( $refund->get_items("shipping") as $refunded_item ) {
							$refunded_item_id = (int) $refunded_item->get_meta( '_refunded_item_id' );
							if ( $refunded_item_id === $item_id ) {
								$taxes  = $refunded_item->get_taxes();
								if($taxes && isset($taxes["subtotal"])){
									if(!empty($taxes["subtotal"])){
										$refunded += abs(array_sum($taxes["subtotal"]));
									}
								}
								break;
							}
						}
					}
				}
				
				$itm = array(
					"posuid"        => $item->get_method_id(),
					"type"          => "shipping",
					"name"          => $item->get_name(),
					"sku"           => $item->get_method_id(),
					"qty"           => 1 - intval($refunded_qty),
					"price"         => floatval($order->get_line_total($item, true, true)),
					"subtotal"      => floatval($order->get_line_total($item, true, true)),
					"refunded"      => $refunded,
					"refunded_qty"  => $refunded_qty,
					"tax_label"     => $tax_class,
					"tax_amount"    => floatval($item->get_total_tax()),
					"virtual"       => true
			   );
			   
			   if($itemids){
				   $itm["posoitemuid"] = $item_id;
				   $order_items[$item_id] = $itm; 
			   }else
				   $order_items[] = $itm;
			}
			
			foreach ( $order->get_items("fee") as $item_id => $item ) {
				$tax_class = str_ireplace("inherit","",$item->get_tax_class());
				
				$refunded = abs(floatval($order->get_total_refunded_for_item( $item_id, "fee" )));
				$refunded_qty = abs(floatval($order->get_qty_refunded_for_item( $item_id )));
				
				if($refunded){
					foreach ( $order->get_refunds() as $refund ) {
						foreach ( $refund->get_items("fee") as $refunded_item ) {
							$refunded_item_id = (int) $refunded_item->get_meta( '_refunded_item_id' );
							if ( $refunded_item_id === $item_id ) {
								$taxes  = $refunded_item->get_taxes();
								if($taxes && isset($taxes["subtotal"])){
									if(!empty($taxes["subtotal"])){
										$refunded += abs(array_sum($taxes["subtotal"]));
									}
								}
								break;
							}
						}
					}
				}
				
				$itm = array(
					"posuid"        => $item_id,
					"type"          => "fee",
					"name"          => $item->get_name(),
					"sku"           => "fee{$item_id}",
					"qty"           => 1 - intval($refunded_qty),
					"price"         => floatval($order->get_line_total($item, true, true)),
					"subtotal"      => floatval($order->get_line_total($item, true, true)),
					"refunded"      => floatval($order->get_total_refunded_for_item( $item_id, "fee" )),
					"refunded_qty"  => floatval($order->get_qty_refunded_for_item( $item_id, "fee" )),
					"tax_label"     => $tax_class,
					"tax_amount"    => floatval($item->get_total_tax()),
					"virtual"       => true
			   );
			   
			   if($itemids){
				   $itm["posoitemuid"] = $item_id;
				   $order_items[$item_id] = $itm; 
			   }else
				   $order_items[] = $itm;
			}
			
			foreach ( $order->get_items("coupon") as $item_id => $item ) {
				$tax_class = str_ireplace("inherit","",$item->get_tax_class());
				$itm = array(
					"posuid"        => $item->get_code(),
					"type"          => "coupon",
					"name"          => __('Coupon','woocommerce') ." -" . $item->get_discount() . " " . hpay_get_wc_order_currency($order),
					"sku"           => $item->get_code(),
					"qty"           => 1,
					"price"         => 0,
					"subtotal"      => 0,
					"tax_label"     => $tax_class,
					"tax_amount"    => 0,
					"virtual"       => true,
					"refunded"      => 0,
					"refunded_qty"  => 0
			   );
			   if($itemids){
				   $itm["posoitemuid"] = $item_id;
				   $order_items[$item_id] = $itm; 
			   }else
				   $order_items[] = $itm;
			}
			
			try{
				$_order_items = apply_filters( "hpay_order_items", $order_items, $order, $hshipping_method);
				if($_order_items && !empty($_order_items) && is_array($_order_items)){
					$order_items = $_order_items;
				}
			}catch(Throwable $fex){
				hpay_write_log("error",$fex);
			}
			
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		
		return $order_items;
	}
	
	public function matchOrderItems($order_items_1, $order_items_2){
		$matches = array();
		if(empty($order_items_1) && empty($order_items_2)){
			return $matches;
		}else if(!empty($order_items_1) && empty($order_items_2)){
			foreach($order_items_1 as $key => $val){
				$matches[] = array($val,null);
			}
		}else if(empty($order_items_1) && !empty($order_items_2)){
			return $matches;
		}else{
			foreach($order_items_1 as $key1 => $val1){
				foreach($order_items_2 as $key2 => $val2){
					if(isset($val1["posoitemuid"]) && isset($val2["posoitemuid"])){
						if($val1["posoitemuid"] == $val2["posoitemuid"]){
							$matches[] = array($val1,$val2);
							unset($order_items_2[$key2]);
							break;
						}
					}
					
					if(isset($val1["sku"]) && isset($val2["sku"])){
						if($val1["sku"] == $val2["sku"]){
							$matches[] = array($val1,$val2);
							unset($order_items_2[$key2]);
							break;
						}
					}
					
					if(isset($val1["name"]) && isset($val2["name"])){
						if($val1["name"] == $val2["name"]){
							$matches[] = array($val1,$val2);
							unset($order_items_2[$key2]);
							break;
						}
					}
					
					if(isset($val1["posuid"]) && isset($val2["posuid"])){
						if($val1["posuid"] == $val2["posuid"]){
							$matches[] = array($val1,$val2);
							unset($order_items_2[$key2]);
							break;
						}
					}
				}
			}
		}
		return $matches;
	}
	
	public function generateHUpdateRequest($order){
		try{
			if(!$order)
				return null;
			
			if(is_numeric($order)){
				$order = hpay_get_order($order);
			}
			
			if(!is_a($order,"WC_Order")){
				return null;
			}
			
			$shipping_method_id  = null;  
			$shipping_method = @array_shift($order->get_shipping_methods());
			if($shipping_method){
				if(isset($shipping_method['method_id']))
					$shipping_method_id = $shipping_method['method_id'];
			}
			
			if($shipping_method_id){
				$shipping_method = HPay_Core::shipping_method_instance($shipping_method_id);
			}
			
			$order_items = $this->getOrderItems($order, $shipping_method);
			$shippable = false;
			
			foreach($order_items as $index => $order_item){
				if(!$order_item["virtual"] || isset($order_item["warehouse"])){
					$shippable = true;
				}
			}
			
			$company_tax_id = "";
			$company_reg_id = "";
			
			try{
				$company_tax_id = $this->wc_get_order_company_tax_id($order);
			}catch(Throwable $taxex){
				hpay_write_log("error",$taxex);
			}
			
			try{
				$company_reg_id = $this->wc_get_order_company_reg_id($order);
			}catch(Throwable $regex){
				hpay_write_log("error",$regex);
			}
			
			$states_map_b = array(); 
			$states_map_s = array(); 
			
			if(function_exists('WC')){
				try{
					if(WC()->countries){
						$states_map_b = WC()->countries->get_states( $order->get_billing_country() );
					}	
				}catch(Throwable $stateex){
					//
				}
				
				try{
					if(WC()->countries){
						$states_map_s = WC()->countries->get_states( $order->get_shipping_country() );
					}	
				}catch(Throwable $stateex){
					//
				}
			}
			
			$state_b = "";
			$state_s = "";
			try{
				$state_b = $order->get_billing_state();
				$state_s = $order->get_shipping_state();
				
				if(isset($states_map_b[$state_b])){
					$state_b = $states_map_b[$state_b];
				}
				
				if(isset($states_map_s[$state_s])){
					$state_s = $states_map_b[$state_s];
				}
			}catch(Throwable $stateex){
				//
			}
			
			$this->set_custom_order_key($order->get_id(), $order);
			
			$order_name = esc_attr($order->get_order_number());
			if($order_name == $order->get_id()){
				$order_name = "#" . esc_attr($order->get_id());
			}
			
			$pay_request = array(
				"merchant_site_uid" => esc_attr($this->getSetting("merchant_site_uid","")),
				"order_uid"         => esc_attr($order->get_order_key()),
				"order_name"        => $order_name,
				"order_amount"      => esc_attr($order->get_total()),
				"order_currency"    => esc_attr(hpay_get_wc_order_currency($order)),
				"order_items"       => $order_items,
				"order_billing" => array(
					"email"           => esc_attr($order->get_billing_email()),
					"first_name"      => esc_attr($order->get_billing_first_name()),
					"last_name"       => esc_attr($order->get_billing_last_name()),
					"phone"           => esc_attr($order->get_billing_phone()),
					"is_company"      => intval($this->wc_get_order_is_company($order)),
 					"company"         => esc_attr($order->get_billing_company()),
					"company_tax_id"  => esc_attr($company_tax_id),
					"company_reg_id"  => esc_attr($company_reg_id),
					"address"         => esc_attr($order->get_billing_address_1()),
					"address2"        => esc_attr($order->get_billing_address_2()),
					"city"            => esc_attr($order->get_billing_city()),
					"country"         => esc_attr($order->get_billing_country()),
					"state"           => esc_attr($state_b),
					"postcode"        => esc_attr($order->get_billing_postcode()),
					"lang"            => esc_attr(get_locale())
				),
				"order_shipping" => array(
					"shippable"   => $shippable,
					"is_cod"      => $order->get_payment_method() == "cod",
					"first_name"  => esc_attr($order->get_shipping_first_name()),
					"last_name"   => esc_attr($order->get_shipping_last_name()),
					"phone"       => esc_attr($order->get_shipping_phone()),
					"company"     => esc_attr($order->get_shipping_company()),
					"address"     => esc_attr($order->get_shipping_address_1()),
					"address2"    => esc_attr($order->get_shipping_address_2()),
					"city"        => esc_attr($order->get_shipping_city()),
					"country"     => esc_attr($order->get_shipping_country()),
					"state"       => esc_attr($state_s),
					"postcode"    => esc_attr($order->get_shipping_postcode())
				),
				"order_sitedata" => array(
					"id"                 => $order->get_id(),
					"customer_id"        => $order->get_customer_id(),
					"payment_method_id"  => $order->get_payment_method(),
					"shipping_method_id" => $shipping_method_id
				)
			);
			
			if($this->getSetting("billing_email_cc","") == 1){
				$email_cc = $order->get_meta("_billing_email_cc");
				if($email_cc){
					if(strpos($email_cc,"@") !== false){
						$email_cc = str_replace(" ","",$email_cc);
						$email_cc = str_replace(";",",",$email_cc);
						$email_cc = explode(",",$email_cc);
						$pay_request["order_billing"]["email_cc"] = implode(",",array_filter(array_map('trim',$email_cc),function($eml){
							if(strpos($eml,"@") !== false && strpos($eml,".") !== false){
								return true;
							}
							return false;
						}));
					}
				}
			}
			
			if(strpos($this->getSetting("billing_email_bcc",""),"@") !== false){
				$pay_request["order_billing"]["email_bcc"] = $this->getSetting("billing_email_bcc","");
			}
			
			if($shipping_method){
				$hpay_status = $order->get_meta("_hpay_status");
				if(!$hpay_status)
					$hpay_status = "";
				if(stripos($hpay_status,"SHIPPING") === false){
					$pay_request["shipping_method"] = $shipping_method->hpay_id;
				}
			}
			
			try{
				if(WC()->session){
					$hcsd = WC()->session->get( "hpay_checkout_sessiom_data", '');
					if($hcsd){
						$hcsd = json_decode($hcsd, true);
						if($hcsd && (!isset($hcsd["_order_id"]) || $hcsd["_order_id"] == $order->get_id())){
							if(isset($hcsd["order_shipping"]) && isset($hcsd["order_shipping"]["dispenser_method_id"])){
								if(!$pay_request["shipping_method"] || $hcsd["order_shipping"]["dispenser_method_id"] != $pay_request["shipping_method"]){
									unset($hcsd["order_shipping"]["dispenser"]);
									unset($hcsd["order_shipping"]["dispenser_method_id"]);
									unset($hcsd["order_shipping"]["dispenser_desc"]);
								}
							}
							foreach($hcsd as $key => $value){
								if(strpos($key,"_") === 0)
									continue;
								if(is_array($value)){
									if(isset($pay_request[$key]) && $pay_request[$key]){
										foreach($value as $k => $v){
											if(strpos($k,"_") === 0)
												continue;
											$pay_request[$key][$k] = $v;
											if(!$pay_request[$key][$k]) unset($pay_request[$key][$k]);
										}
									}else{
										$pay_request[$key] = $value; 
										if(!$pay_request[$key]) unset($pay_request[$key]);
									}
								}else{
									$pay_request[$key] = $value; 
									if(!$pay_request[$key]) unset($pay_request[$key]);
								}	
							}
							if(!isset($hcsd["_order_id"])){
								$hcsd["_order_id"] = $order->get_id();
								WC()->session->set( "hpay_checkout_sessiom_data", json_encode($hcsd,JSON_UNESCAPED_UNICODE));
							}	
						}
					}
				}
			}catch(Throwable $sesex){
				hpay_write_log("error",$sesex);
			}
			
			try{
				
				$fupd_request = apply_filters( "hpay_store_order_filter", $pay_request, $order);
				
				if($fupd_request){
					$pay_request = $fupd_request; 
				}
				
			}catch(Throwable $fex){
				hpay_write_log("error",$fex);
			}
			
			return $pay_request;
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
			return null;
		}
	}
	
	public function generateHPayRequest($order, $pmethod_id = 'external', $cof = 'none', $vault_token_uid = '', $subscription_uid = ''){
		if(!$order)
			return null;
		
		try{
			
			if(is_numeric($order)){
				$order = hpay_get_order($order);
			}
			
			if(!is_a($order,"WC_Order")){
				return null;
			}
			
			$hpayment_method_id = "0";
			
			$hpay_paymethod = null;
			
			if($pmethod_id && $pmethod_id != 'external')
				$hpay_paymethod = HPay_Core::payment_method_instance($pmethod_id);
			
			if($hpay_paymethod){
				$hpayment_method_id = $hpay_paymethod->hpay_id;
				$pmethod_id = $hpay_paymethod->id; 
			}
			
			
				
			$return_url = $order->get_checkout_order_received_url();
			$company_tax_id = "";
			$company_reg_id = "";
			$is_company     = "";
			
			try{
				$company_tax_id = $this->wc_get_order_company_tax_id($order);
			}catch(Throwable $taxex){
				hpay_write_log("error",$taxex);
			}
			
			try{
				$company_reg_id = $this->wc_get_order_company_reg_id($order);
			}catch(Throwable $regex){
				hpay_write_log("error",$regex);	
			}
			
			try{
				$is_company = intval($this->wc_get_order_is_company($order));
			}catch(Throwable $icex){
				hpay_write_log("error",$icex);	
			}
			
			$shipping_method_id  = null;  
			$hshipping_method_id = "0";
			
			$shipping_method = @array_shift($order->get_shipping_methods());
			if($shipping_method){
				if(isset($shipping_method['method_id']))
					$shipping_method_id = $shipping_method['method_id'];
			}
			
			if($shipping_method_id){
				$shipping_method = HPay_Core::shipping_method_instance($shipping_method_id);
				if($shipping_method){
					$hshipping_method_id = $shipping_method->hpay_id;
				}
			}
			
			$order_items = $this->getOrderItems($order, $shipping_method);
			
			$shippable = false;
			
			foreach($order_items as $index => $order_item){
				if(!$order_item["virtual"] || isset($order_item["warehouse"])){
					$shippable = true;
				}
			}
			
			$hpaylang = HPay_Core::hpaylang();
			
			$this->set_custom_order_key($order->get_id(), $order);
			
			$order_name = esc_attr($order->get_order_number());
			if($order_name == $order->get_id()){
				$order_name = "#" . esc_attr($order->get_id());
			}
			
			$pay_request = array(
				"merchant_site_uid" => esc_attr($this->getSetting("merchant_site_uid","")),
				"order_uid"         => esc_attr($order->get_order_key()),
				"order_name"        => $order_name,
				"order_amount"      => esc_attr($order->get_total()),
				"order_currency"    => esc_attr(hpay_get_wc_order_currency($order)),
				"order_items"       => $order_items,
				"order_billing" => array(
					"email"           => esc_attr($order->get_billing_email()),
					"first_name"      => esc_attr($order->get_billing_first_name()),
					"last_name"       => esc_attr($order->get_billing_last_name()),
					"phone"           => esc_attr($order->get_billing_phone()),
					"is_company"      => $is_company,
					"company"         => esc_attr($order->get_billing_company()),
					"company_tax_id"  => esc_attr($company_tax_id),
					"company_reg_id"  => esc_attr($company_reg_id),
					"address"         => esc_attr($order->get_billing_address_1()),
					"address2"        => esc_attr($order->get_billing_address_2()),
					"city"            => esc_attr($order->get_billing_city()),
					"country"         => esc_attr($order->get_billing_country()),
					"state"           => esc_attr($order->get_billing_state()),
					"postcode"        => esc_attr($order->get_billing_postcode()),
					"lang"            => esc_attr(get_locale())
				),
				"order_shipping" => array(
					"shippable"   => $shippable,
					"is_cod"      => $order->get_payment_method() == "cod",
					"first_name"  => esc_attr($order->get_shipping_first_name()),
					"last_name"   => esc_attr($order->get_shipping_last_name()),
					"phone"       => esc_attr($order->get_shipping_phone()),
					"company"     => esc_attr($order->get_shipping_company()),
					"address"     => esc_attr($order->get_shipping_address_1()),
					"address2"    => esc_attr($order->get_shipping_address_2()),
					"city"        => esc_attr($order->get_shipping_city()),
					"country"     => esc_attr($order->get_shipping_country()),
					"state"       => esc_attr($order->get_shipping_state()),
					"postcode"    => esc_attr($order->get_shipping_postcode())
				),
				"order_sitedata" => array(
					"id"                 => $order->get_id(),
					"customer_id"        => $order->get_customer_id(),
					"payment_method_id"  => $order->get_payment_method(),
					"shipping_method_id" => $shipping_method_id
				),
				"order_user_url"   => apply_filters( 'woocommerce_get_return_url', $return_url, $order ),
				"vault_token_uid"  => $vault_token_uid,
				"cof"              => $cof,
				"payment_method"   => $hpayment_method_id,
				"shipping_method"  => $hshipping_method_id,
				"notify_url"       => admin_url('admin-ajax.php') . "?action=hpay-webhook&topic=payresult&pos_pm_id=" . $pmethod_id,
				"hpaylang"         => $hpaylang
			);
			
			global $hpay_monthly_installments_in_request;
			if(hpay_read_post_parm("hpay_merchant_charge_installments") || ($hpay_paymethod && hpay_read_post_parm("{$pmethod_id}_monthly_installments") && hpay_read_post_parm("{$pmethod_id}_vault_token_id"))){
				$hinstallments = hpay_read_post_parm("hpay_merchant_charge_installments",hpay_read_post_parm("{$pmethod_id}_monthly_installments"));
				if($hinstallments && intval($hinstallments) >= 2){
					$pay_request["monthly_installments"] = intval($hinstallments);
				}
			}else if(isset($hpay_monthly_installments_in_request) && $hpay_monthly_installments_in_request && $hpay_monthly_installments_in_request > 1){
				$pay_request["monthly_installments"] = intval($hpay_monthly_installments_in_request);
			}
			
			try{
			
				$hinstallments = apply_filters('hinstallments_installments',0);
				if($hinstallments && intval($hinstallments) > 0){//1 must be allowed for lock
					$pay_request["monthly_installments"] = intval($hinstallments);
				}
			
			}catch(Throwable $fex){
				hpay_write_log("error",$fex);
			}
			
			if($this->getSetting("billing_email_cc","") == 1){
				$email_cc = $order->get_meta("_billing_email_cc");
				if($email_cc){
					if(strpos($email_cc,"@") !== false){
						$email_cc = str_replace(" ","",$email_cc);
						$email_cc = str_replace(";",",",$email_cc);
						$email_cc = explode(",",$email_cc);
						$pay_request["order_billing"]["email_cc"] = implode(",",array_filter(array_map('trim',$email_cc),function($eml){
							if(strpos($eml,"@") !== false && strpos($eml,".") !== false){
								return true;
							}
							return false;
						}));
					}
				}
			}
			
			if(strpos($this->getSetting("billing_email_bcc",""),"@") !== false){
				$pay_request["order_billing"]["email_bcc"] = $this->getSetting("billing_email_bcc","");
			}
			
			try{
				if(WC()->session){
					$hcsd = WC()->session->get( "hpay_checkout_sessiom_data", '');
					if($hcsd){
						$hcsd = json_decode($hcsd, true);
						if($hcsd && (!isset($hcsd["_order_id"]) || $hcsd["_order_id"] == $order->get_id())){
							if(isset($hcsd["order_shipping"]) && isset($hcsd["order_shipping"]["dispenser_method_id"])){
								if(!$pay_request["shipping_method"] || $hcsd["order_shipping"]["dispenser_method_id"] != $pay_request["shipping_method"]){
									unset($hcsd["order_shipping"]["dispenser"]);
									unset($hcsd["order_shipping"]["dispenser_method_id"]);
									unset($hcsd["order_shipping"]["dispenser_desc"]);
								}
							}
							foreach($hcsd as $key => $value){
								if(strpos($key,"_") === 0)
									continue;
								if(is_array($value)){
									if(isset($pay_request[$key]) && $pay_request[$key]){
										foreach($value as $k => $v){
											if(strpos($k,"_") === 0)
												continue;
											$pay_request[$key][$k] = $v;
											if(!$pay_request[$key][$k]) unset($pay_request[$key][$k]);
										}
									}else{
										$pay_request[$key] = $value; 
										if(!$pay_request[$key]) unset($pay_request[$key]);
									}
								}else{
									$pay_request[$key] = $value; 
									if(!$pay_request[$key]) unset($pay_request[$key]);
								}	
							}
							if(!isset($hcsd["_order_id"])){
								$hcsd["_order_id"] = $order->get_id();
								WC()->session->set( "hpay_checkout_sessiom_data", json_encode($hcsd,JSON_UNESCAPED_UNICODE));
							}
						}
					}
				}
			}catch(Throwable $sesex){
				hpay_write_log("error",$sesex);
			}
			
			try{
				$fpay_request = apply_filters( "hpay_request_filter", $pay_request, $order);
			}catch(Throwable $fex){
				hpay_write_log("error",$fex);
			}
			
			$customer_user_id = $order->get_customer_id();
			
			if($customer_user_id){
				$customer_vtokens = WC_Payment_Token_HPay::get_hpay_tokens($customer_user_id, null, $this->getSetting("merchant_site_uid",""));
				if(!empty($customer_vtokens)){
					$pay_request["customer_vtokens"] = array();
					foreach($customer_vtokens as $ctok){
						$pay_request["customer_vtokens"][$ctok->vault_token_uid()] = $ctok->vault_card_umask();
					}
				}
			}
			
			if($fpay_request){
				$pay_request = $fpay_request; 
			}
		
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		
		try{
				
			$fpay_request = apply_filters( "hpay_pay_request_filter", $pay_request, $order);
			
			if($fpay_request && !empty($fpay_request)){
				$pay_request = $fpay_request; 
			}
			
		}catch(Throwable $fex){
			hpay_write_log("error",$fex);
		}
		
		if(isset($pay_request["subscription_uid"])){
			 $subscription_uid = $pay_request["subscription_uid"];
		}
		
		$pay_request["verificationhash"] = esc_attr($this->payRequestSignatureHash("","",$pay_request["order_uid"],$pay_request["order_amount"], $pay_request["order_currency"],$pay_request["vault_token_uid"], $subscription_uid));
		return $pay_request;
	}
	
}