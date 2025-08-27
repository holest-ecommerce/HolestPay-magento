<?php
//HOLESTPAY 2023
if(!defined("HPAY_PRODUCTION_URL")){
	die("Direct access is not allowed");
};

trait HPay_Core_Checkout{
	
	public function displayUserVaults($customer_user_id = null, $hpaymethod = null, $with_other_option = false, $title = null, $skip_non_merchant = false, $no_choose = false, $in_admin = null, $token_values = false){
		$count = 0;
		if(!$customer_user_id){
			$customer_user_id = get_current_user_id();
		}
		
		if(!$customer_user_id)
			return;
		
		$tokens = WC_Payment_Token_HPay::get_hpay_tokens($customer_user_id, $hpaymethod);
		if(!empty($tokens)){
			if($title){
				?>
				<h5 class='hpay-vault-tokens-title'><?php echo esc_attr($title); ?>:</h5>
				<?php
			}
			
			$name_prefix = "hpay";
			if(!$in_admin && $hpaymethod){
				$name_prefix = $hpaymethod->id;
			}
			
			?>
				
				<ul class='hpay-vault-tokens'>
					<?php
					
						if($no_choose){
							$no_choose = " style='display:none;' ";
						}else{
							$no_choose = "";
						}
						
						$default_selected = false;
						foreach($tokens as $token){
							if($skip_non_merchant){
								if($token->vault_onlyforuser() == "1"){
									continue;
								}	
							}
							
							$default_chk = $token->is_default() ? " checked='checked' " : "";
							if($default_chk){
								$default_selected = true;
							}
							echo "<li token_time='" . esc_attr($token->token_time()) . "' token_id='" . esc_attr($token->get_id()) . "' class='hpay-vault " . ($token->is_default()? "hpay-detafult-vault" : "") . "' >";
							echo "<input {$no_choose}{$default_chk} type='radio' value='" . esc_attr($token_values ? $token->vault_token_uid() : $token->get_id()) . "' id='" . esc_attr($name_prefix) . "_vault_token_id_" . esc_attr($token->get_id()) . "' name='" . esc_attr($name_prefix) . "_vault_token_id' />";
							echo " <label for='" . esc_attr($name_prefix) . "_vault_token_id_" . esc_attr($token->get_id()) . "'>&nbsp; <span class='hpay-token-brand hpay-token-brand-" . esc_attr(strtolower($token->vault_card_brand())) . "'>" . esc_attr($token->vault_card_brand()) . "</span>: ";
							echo "" . esc_attr($token->vault_card_umask()) . "</label>";
							echo "<span class='hpay-vault-installment'>";
							$skip_inst = defined('HINSTALLMENTS_PLUGIN') && !is_admin();
							if(!$skip_inst && $hpaymethod){
								echo $hpaymethod->installmentsForVaultHTML($token);
							}
							echo "</span>";
							echo "<span class='hpay-vault-options'><a class='hpay-destroy-vault'>&times; " . __("remove","holestpay") . "</a> <a class='hpay-set-default-vault'>" . __("set default","holestpay") . "</a></span>";
							echo "</li>";
							$count++;
						}
					?>
					
					<?php
					if($with_other_option){
						$default_chk = !$default_selected ? " checked='checked' " : "";
						echo "<li><input id='" . esc_attr($name_prefix) . "_vault_token_id_null' {$default_chk} type='radio' value='' name='" . esc_attr($name_prefix) . "_vault_token_id'/> <label for='" . esc_attr($name_prefix) . "_vault_token_id_null' >"; 
						echo esc_attr(__("Use other ...","holestpay"));
						echo "</label></li>";
					}
					?>
				</ul>
			<?php
		}
		return $count;
	}
	
	public function dispayOrderInfo($order_id, $data, $transaction_pay_status = null, $hmethod = null, $display_layout = null){
		$method_id = "";
		if($hmethod){
			$method_id = $hmethod->id;
		}
		
		$order = null;
		
		if($order_id){
			$order = hpay_get_order($order_id);
		}
		
		$show_payment  = true;
		$show_fiscal   = true;
		$show_shipping = true;
		
		if($display_layout){
			if(is_string($display_layout)){
				$display_layout = array_filter(array_map("trim",explode(",",strtolower($display_layout))),"trim");
			}
			if(!empty($display_layout) && is_array($display_layout)){
				if(!in_array("payment",$display_layout)){
					$show_payment  = false;
				}
				if(!in_array("fiscal",$display_layout)){
					$show_fiscal   = false;
				}
				if(!in_array("shipping",$display_layout)){
					$show_shipping = false;
				}
			}
		}
		
		if($show_payment && $data){
			
			if(!$transaction_pay_status){
				if(isset($data["transaction_pay_status"])){
					$transaction_pay_status = $data["transaction_pay_status"];
				}
			}
			
			$failed = false;
			
			if($transaction_pay_status !== null){
				echo "<h4 class='hpay-user-transaction-info pay-outcome-" . ($transaction_pay_status === false ? "failed" : ( $transaction_pay_status === true ? "success" : "pend")) ."'>";
				if(stripos($transaction_pay_status, "PAID") !== false){
					echo esc_attr__("Payment successful","holestpay");	
				}else if(stripos($transaction_pay_status, "RESERVED") !== false){
					echo esc_attr__("Payment successful, pending amount capture","holestpay");	
				}else if(stripos($transaction_pay_status, "AWAITING") !== false){
					echo esc_attr__("Awaiting payment","holestpay");	
				}else if(stripos($transaction_pay_status, "CANCELED") !== false){
					echo esc_attr__("Payment canceled","holestpay");
				}else if(stripos($transaction_pay_status, "PARTIALLY-REFUNDED") !== false){
					echo esc_attr__("Payment partially refunded","holestpay");	
				}else if(stripos($transaction_pay_status, "REFUNDED") !== false){
					echo esc_attr__("Payment refunded","holestpay");	
				}else if(stripos($transaction_pay_status, "REFUSED") !== false){
					echo esc_attr__("Payment refused","holestpay");	
					$failed = true;
				}else if($transaction_pay_status){
					echo esc_attr__("Payment failed","holestpay");
					$failed = true;
				}
				echo "</h4>";
			}
			
			if($failed){
				echo "<p>".esc_attr__("Payment unsuccessful, your account has not been debited. The most common cause is an incorrect card number, expiration date, or security code. Try again, and in case of repeated errors, contact your bank.","holestpay")."</p>";
			}
			
			if(isset($data["transaction_user_info"])){
				echo "<pre class='hpay-transaction-info method-{$method_id}'>";
				echo str_replace(array('"',"{","}","[","]"),"",json_encode($this->translateKeys($data["transaction_user_info"]),JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
				echo "</pre>";
			}
		}
		
		global $hpay_fiscal_shipping_outputed;
		if(!isset($hpay_fiscal_shipping_outputed))
			$hpay_fiscal_shipping_outputed = array();
		
		if($show_fiscal && $order && !isset($hpay_fiscal_shipping_outputed["{$order_id}_fiscal"])){
			$hpay_fiscal_shipping_outputed["{$order_id}_fiscal"] = true;
			
			$fiscal_user_info   = $order->get_meta('_fiscal_user_info');
			$fiscal_html        = $order->get_meta('_fiscal_html');
			
			if(!$fiscal_html){
				$fiscal_html = "";
			}
			
			if($fiscal_user_info){
				$added_nl = false;
				if(!empty($fiscal_user_info)){
					foreach($fiscal_user_info as $info){
						if(isset($info["info_views"])){
							if(isset($info["info_link"]) && in_array("pdf", $info["info_views"])){
								$filename = sanitize_file_name($info["name"]).".pdf";
								if(!$added_nl){
									$added_nl = true;
									$fiscal_html .= "<p>";
								}
								$fiscal_html .= ("<span class='hpay-download-link'> <a target='_blank' href='" . esc_url(add_query_arg(array('pdf' => 1, "download" => 1), $info["info_link"])) . "'>" . __("Download") . " " . $filename . "...</a> </span>");
							}
						}	
					}
				}
				if($added_nl)
					$fiscal_html .= "</p>";
			}
			
			if($fiscal_html)
				echo "<div class='hpay-fiscal-info'>{$fiscal_html}</div>";
		
		}
		
		if($show_shipping && $order && !isset($hpay_fiscal_shipping_outputed["{$order_id}_shipping"])){
			$hpay_fiscal_shipping_outputed["{$order_id}_shipping"] = true;
			
			$shipping_user_info = $order->get_meta('_shipping_user_info');
			$shipping_html      = $order->get_meta('_shipping_html');
			if(!$shipping_html){
				$shipping_html = "";
			}
				
			if($shipping_user_info){
				$added_nl = false;
				if(!empty($shipping_user_info)){
					foreach(["shipping_user_info"] as $info){
						if(isset($info["info_views"])){
							if(isset($info["info_link"]) && in_array("pdf", $info["info_views"])){
								$filename = sanitize_file_name($info["name"]).".pdf";
								if(!$added_nl){
									$added_nl = true;
									$shipping_html .=  "<p>";
								}
								$shipping_html .= ("<span class='hpay-download-link'> <a target='_blank' href='" . esc_url(add_query_arg(array('pdf' => 1, "download" => 1), $info["info_link"])) . "'>" . __("Download") . " " . $filename . " ...</a> </span>");
							}
						}	
					}
				}
				if($added_nl)
					$shipping_html .= "</p>";
			}
			
			if($shipping_html)
				echo "<div class='hpay-shipping-info'>{$shipping_html}</div>";
		}
	}
	
	public function wc_additional_checkout_fields(){
		$fields = array();
		try{
			
			$is_company_running_val = $this->running_is_company_value();
			
			//is_company MUST BE FIRST if used!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
			if($this->getSetting("is_company","") == 1){
				$fields['billing_is_company'] = array(
					'label'       => __('Ordering as a company?', 'holestpay'),
					'required'    => false,
					'clear'       => false,
					'type'        => is_admin() ? "text" : "radio",
					'options'     => array(
									  "0"              => __( 'Ordering as a individual', 'holestpay' ),
									  "1"              => __( 'Ordering as a company', 'holestpay' )
									),
					'class'       => array('hpay_billing_is_company'),
					'priority'    => 30,
					'value'       => '',
					'default'     => '0'
				);
			}
			
			if($this->getSetting("company_tax_id","") == 1){
				
				$required = $this->getSetting('tax_id_field_required', false) == 1 ? true : false;
				
				$fields['billing_company_tax_id'] = array(
					'label'       => __('Company Tax ID', 'holestpay'),
					'required'    => $required,
					'clear'       => false,
					'type'        => 'text',
					'class'       => array('hpay_billing_company_tax_id', $required ? "hpay-is-required" : "hpay-not-required"),
					'priority'    => 30,
					'value'       => '',
					'default'     => ''
				);
				if($is_company_running_val === 0 || is_admin() ){
					$fields['billing_company_tax_id']["required"] = false;
				}
			}

			if($this->getSetting("company_reg_id","") == 1){
				
				$required = $this->getSetting('reg_id_field_required', false) == 1 ? true : false;
				
				$fields['billing_company_reg_id'] = array(
					'label'       => __('Company Register ID', 'holestpay'),
					'required'    => $required,
					'clear'       => false,
					'type'        => 'text',
					'class'       => array('hpay_billing_company_reg_id', $required ? "hpay-is-required" : "hpay-not-required"),
					'priority'    => 31,
					'value'       => '',
					'default'     => ''
				);
				if($is_company_running_val === 0 || is_admin() ){
					$fields['billing_company_reg_id']["required"] = false;
				}
			}
			
			if($this->getSetting("billing_email_cc","") == 1){
				$fields['billing_email_cc'] = array(
					'label'       => __('Send order notifications also to these email addresses', 'holestpay'),
					'required'    => false,
					'clear'       => false,
					'placeholder' => __("(if multiple split with ,)", 'holestpay'),
					'type'        => 'text',
					'class'       => array('billing_email_cc'),
					'priority'    => 999,
					'value'       => '',
					'default'     => ''
				);
			}
			
			if(is_admin()){
				foreach($fields as $key => $fld){
					$fields[$key]["class"] = implode(" ",$fields[$key]["class"]);
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return $fields;
	}
	
	public function checkout_setup(){
		try{
			add_action( 'woocommerce_checkout_order_processed', array($this,'woocommerce_checkout_order_processed'), 5, 3);
			add_action( 'woocommerce_store_api_checkout_order_processed', array($this,'woocommerce_store_api_checkout_order_processed'), 50, 1);
			add_filter( 'woocommerce_billing_fields', array($this,'woocommerce_billing_fields'),30);
			add_filter( 'woocommerce_admin_billing_fields', array($this,'woocommerce_admin_billing_fields'),30);
			add_filter( 'woocommerce_order_get_formatted_billing_address', array($this,'woocommerce_order_get_formatted_billing_address'), 50, 3 );
			add_filter( 'woocommerce_update_order_review_fragments', array($this,'woocommerce_update_order_review_fragments'), 999, 3 );
			
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	public function woocommerce_update_order_review_fragments($fragments){
		try{
			$fragments["hpaycart"] = $this->getHPayCart();
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return $fragments;
	}
	
	public function woocommerce_store_api_checkout_order_processed($order){
		try{
			$this->woocommerce_checkout_order_processed($order->get_id(), array() , $order);
		}catch(Throwable $bex){
			hpay_write_log("error",$bex);
		}
	}
	
	public function woocommerce_checkout_order_processed($order_id, $posted_data, $order){
		try{
			$add_fileds = $this->wc_additional_checkout_fields();
			$order = null;
			foreach($add_fileds as $key => $fld){
				if(isset($posted_data[$key])){
					if(!$order)
						$order = hpay_get_order( $order_id );
					if($order){
						$order->update_meta_data($key , $posted_data[$key]);
					}
				}
			}
			try{
				$b_data = $this->getAdditionalBillingData();
				if($b_data){
					foreach($b_data as $key => $value){
						if(strpos($key,"_field") === false){
							if($value && isset($b_data[$key."_field"]) && $b_data[$key."_field"]){
								if(!$order)
									$order = hpay_get_order( $order_id );
								
								if($order){
									$m_key = $b_data[$key."_field"];
									if(strpos($m_key,"_billing_") === false){
										$m_key = "_billing_{$m_key}";
									}
									$order->update_meta_data($m_key , $value);
								}
							}
						}
					}
				}
			}catch(Throwable $bex){
				hpay_write_log("error",$bex);
			}
			
			if($order)
				$order->save_meta_data();
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	public function wc_get_order_company_tax_id($order){
		try{
			if(is_numeric($order)){
				$order = hpay_get_order($order);
			}
			if(!$order)
				return null;
			if($this->getSetting("company_tax_id","") == 1){
				return $order->get_meta("_billing_company_tax_id");
			}else if($this->getSetting("tax_id_field","")){
				return $order->get_meta("_" . $this->getSetting("tax_id_field",""));
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return null;
	}
	
	public function wc_get_order_company_reg_id($order){
		try{
			if(is_numeric($order)){
				$order = hpay_get_order($order);
			}
			
			if(!$order)
				return null;
			if($this->getSetting("company_reg_id","") == 1){
				return $order->get_meta("_billing_company_reg_id");
			}else if($this->getSetting("reg_id_field","")){
				return $order->get_meta("_" . $this->getSetting("reg_id_field",""));
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return null;
	}
	
	public function wc_get_order_is_company($order){
		try{
			if(is_numeric($order)){
				$order = hpay_get_order($order);
			}
			if(!$order)
				return null;
			if($this->getSetting("is_company","") == 1){
				return intval($order->get_meta("_billing_is_company"));
			}else if($this->getSetting("is_company_field","")){
				if($this->getSetting("is_company_field","") == "company" || $this->getSetting("is_company_field","") == "billing_company"){
					if(trim($order->get_billing_company())){
						return 1;
					}else{
						return 0;
					}
				}else{
					$val = trim(strtolower($order->get_meta("_" . $this->getSetting("is_company_field",""))));
					if($val == "1" || $val == "on" || $val == "true" || $val == "yes" || strpos($val,"comp") !== false){
						return 1;
					}else{
						return 0;
					}
				}
			}else{
				return (!!$this->wc_get_order_company_tax_id($order)) ? 1 : 0;
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return null;
	}
	
	public function running_is_company_value(){
		$is_company_val = null;
		try{
			$is_company_field = "";
			if($this->getSetting("is_company","") == 1){
				$is_company_field =  "billing_is_company";
			}else if($this->getSetting("is_company_field","")){
				$is_company_field =  "billing_" . $this->getSetting("is_company_field","");
			}
			if($is_company_field){
				$val = hpay_read_request_parm($is_company_field, WC()->checkout->get_value($is_company_field)); 
				if($is_company_field == "company" || $is_company_field == "billing_company"){
					return trim($val) ? 1 : 0; 
				}
				if($val == "1" || $val == "on" || $val == "true" || $val == "yes" || strpos($val,"comp") !== false){
					$is_company_val = 1;
				}else{
					$is_company_val = 0;
				}
			}
		}catch(Throwable $cex){
			//
		}
		return $is_company_val;
	}
	
	public function woocommerce_billing_fields($fields, $admin_order_id = null, $is_mail = false){
		try{
			$order = null;
			
			if(!$admin_order_id && hpay_read_get_parm("page","") == "wc-orders"){
				if(hpay_read_get_parm("action","") == "new"){
					
				}else if(hpay_read_get_parm("action","") == "edit"){
					$admin_order_id = intval(hpay_read_get_parm("id",""));
				}
			}
			
			if($admin_order_id){
				if(is_numeric($admin_order_id)){
					$order = hpay_get_order($admin_order_id);
				}else if(is_a($admin_order_id,"WC_Order")){
					$order = $admin_order_id;
					$admin_order_id = $order->get_id();
				}
			}
			
			$add_fileds = $this->wc_additional_checkout_fields();
			
			$is_company_val = $this->running_is_company_value();
			
			$fileds_n = array();
			$added = false;
			
			foreach($fields as $fname => $flddef){
				
				if($this->getSetting("company_tax_id","") != 1 && $this->getSetting('tax_id_field_required', false) == 1){
					if($this->getSetting("tax_id_field","")){
						if($this->getSetting("tax_id_field","") == $fname){
							if($is_company_val === 0 || is_admin() ){
								$flddef["required"] = false;
							}else{
								$flddef["required"] = true;
							}
						}
					}
				}
				
				if($this->getSetting("company_reg_id","") != 1 && $this->getSetting('reg_id_field_required', false) == 1){
					if($this->getSetting("reg_id_field","")){
						if($this->getSetting("reg_id_field","") == $fname){
							if($is_company_val === 0 || is_admin() ){
								$flddef["required"] = false;
							}else{
								$flddef["required"] = true;
							}
						}
					}
				}
				
				$fileds_n[$fname] = $flddef;
				
				if(!empty($add_fileds)){
					if("billing_company" == $fname || ($admin_order_id && "company" == $fname)){
						if($is_company_val === 0){
							$flddef["required"] = false;
						}
						$company_field = null;
						foreach($add_fileds as $key => $def){
							if($key == "billing_email_cc")
								continue;
							
							if($key == "billing_is_company"){
								$company_field = $fileds_n[$admin_order_id ? "company" : "billing_company"];
								unset($fileds_n[$admin_order_id ? "company" : "billing_company"]);
							}
								
							if(!isset($flddef["priority"]))
								$flddef["priority"] = 10;
							
							$def["priority"] = $flddef["priority"] + 1;
							
							if($key == "billing_is_company"){
								$def["type"] = "select";
								$def["options"] = array(
									""  => __("No","holestpay"),
									"1" => __("Yes","holestpay")
								);
							}	
							
							if($admin_order_id){
								if($order){
									$def["value"] = $order->get_meta("_{$key}");
									//if(trim($def["value"]) == "")
									//	continue;
								}
								if($is_mail){
									$fileds_n[$key] = $def;
								}else{
									$fileds_n[str_replace("billing_","",$key)] = $def;
								}
							}else{
								$fileds_n[$key] = $def;
							}
							
							if($key == "billing_is_company"){
								$fileds_n[ $admin_order_id ? "is_company" : "billing_is_company" ]["priority"] = $company_field["priority"];
								$fileds_n[$admin_order_id ? "company" : "billing_company"] = $company_field;
							}
						}
						$added = true;
					}
				}
			}
				
			if(!$added && !empty($add_fileds)){
				foreach($add_fileds as $key => $def){
					if($key == "billing_email_cc")
						continue;
					
					if($admin_order_id){
						if($order){
							$def["value"] = $order->get_meta("_{$key}");
							if(trim($def["value"]) == "")
								continue;
						}
						if($is_mail){
							$fileds_n[$key] = $def;
						}else{
							$fileds_n[str_replace("billing_","",$key)] = $def;
						}
					}else{
						$fileds_n[$key] = $def;
					}
				}
			}
			
			if(isset($add_fileds["billing_email_cc"])){
				$key = "billing_email_cc";
				
				if($admin_order_id){
					if($order){
						$def["value"] = $order->get_meta("_{$key}");
					}
					
					if($is_mail){
						$fileds_n[$key] = $def;
					}else{
						$fileds_n[str_replace("billing_","",$key)] = $def;
					}
				}else{
					$fileds_n[$key] = $def;
				}
			}
			
			$fields = $fileds_n;
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		
		return $fields;
	}
	
	public function woocommerce_admin_billing_fields($fields){
		try{
			$fileds = $this->woocommerce_billing_fields($fields, get_the_ID());
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return $fileds;
	}
	
	
	public function woocommerce_order_get_formatted_billing_address($address, $raw_address, $order){
		try{
			$fileds = $this->wc_additional_checkout_fields();
			if(!empty($fileds)){
				foreach($fileds as $key => $filed){
					$v = $order->get_meta("_{$key}");
					if(trim($v)){
						$address .= ("<br/>" . $filed["label"] . ": " . $v);
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return $address;
	}
};	