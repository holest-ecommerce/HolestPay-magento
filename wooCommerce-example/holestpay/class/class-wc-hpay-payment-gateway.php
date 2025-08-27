<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . DIRECTORY_SEPARATOR . "class-wc-hpay-vault-token.php";
/**
 * WC_Gateway_HPayPayment - when HPAY is integrated with wooCommerce.
 *
 */
class WC_Gateway_HPayPayment extends WC_Payment_Gateway {
	private $_method_data = null;
	private $_alias       = null;
	public $hpay_id       = null;
	
	
	public function aliasName(){
		return $this->_alias;
	}
	
	public function hpay_method_type(){
		return $this->_method_data["PaymentMethod"];
	}
	
	public function hpay_method_slug(){
		return "hpay-" . $this->_method_data["PaymentMethod"] . "-" . $this->hpay_id;
	}
	
	public function getHProp($name){
		if($this->_method_data){
			if(isset($this->_method_data[$name])){
				return $this->_method_data[$name];
			}
		}
		return null;
	}
	
	public function tokenisation_disallowed(){
		$no_tokens = false;
		if(isset($this->_method_data["No Card Tokenization"])){
			if($this->_method_data["No Card Tokenization"]){
				$no_tokens = true;
			}
		}
		return $no_tokens;
	}
	
	public function methodSupports($support_capability){
		return in_array($support_capability, $this->supports);
	}
	
	public function supportsOperation($operation){
		if(!isset($this->_method_data["POps"])){
			return false;
		}
		return stripos(",{$this->_method_data["POps"]},",",{$operation},") !== false;
	}
	
	public function __construct(){
		
		global $hpay_pm_class_mapper;
		
		if(!isset($hpay_pm_class_mapper[get_class($this)]))
			return;
		
		$second_instance = isset($hpay_pm_class_mapper[get_class($this)]["instance"]);
		
		$hpay_pm_class_mapper[get_class($this)]["instance"] = $this;
		
		$this->_alias       = $hpay_pm_class_mapper[get_class($this)]["alias"];
		$this->_method_data = $hpay_pm_class_mapper[get_class($this)]["data"];
		
		$this->hpay_id      = $this->_method_data["HPaySiteMethodId"];
		$this->id           =  "hpaypayment-" . $this->hpay_id;
		$this->has_fields   = true;
		
		$this->supports  = array(
							 'products'
						   );

		
		if($this->supportsOperation("refund")){
			$this->supports[] = 'refunds';
		}
				
		if(HPay_Core::instance()->getPOSSetting("SubscriptionsUnlocked", null)){
			
			if(!isset($this->_method_data["SubsciptionsType"])){
				$this->_method_data["SubsciptionsType"] = "";
			}
			
			$true_support = stripos($this->_method_data["SubsciptionsType"],"cof") !== false || stripos($this->_method_data["SubsciptionsType"],"recurring") !== false || stripos($this->_method_data["SubsciptionsType"],"mit") !== false;  
			$subscriptions_only_true_capable = HPay_Core::instance()->getSetting("subscriptions_only_true_capable", null) == 1;
			
			if($true_support || !$subscriptions_only_true_capable){
				$this->supports = array_merge($this->supports, array(
						'subscriptions',
						'subscription_suspension',
						'subscription_reactivation',
						'multiple_subscriptions',
						'subscription_date_changes',
						'subscription_cancellation',
						'subscription_payment_method_change',
						'subscription_payment_method_change_customer',
						'subscription_payment_method_change_admin'
				));
			}
			
			if(stripos($this->_method_data["SubsciptionsType"],"cof") !== false || stripos($this->_method_data["SubsciptionsType"],"mit") !== false){
				if($this->_method_data["SubsciptionsType"] == "cof-tokenization"){
					$this->supports[] = 'tokenization';
					$this->supports[] = 'subscription_amount_changes';
				}
			}
		}				   
		
		if(!isset($this->_method_data["Name"]))
			$this->_method_data["Name"] = "-";
		
		if(!isset($this->_method_data["Description"]))
			$this->_method_data["Description"] = "-";
		
		
		$this->title        =  $this->_method_data["Name"];
		$this->description  =  $this->_method_data["Description"];
		
		if(isset($this->_method_data["localized"])){
			if(isset($this->_method_data["localized"][HPay_Core::hpaylang()])){
				if(isset($this->_method_data["localized"][HPay_Core::hpaylang()]["Name"])){
					$this->title = $this->_method_data["localized"][HPay_Core::hpaylang()]["Name"];
				}
				if(isset($this->_method_data["localized"][HPay_Core::hpaylang()]["Description"])){
					$this->description  = $this->_method_data["localized"][HPay_Core::hpaylang()]["Description"];
				}	
			}
		}
		
		$environment = HPay_Core::instance()->getSetting("environment", null);
		
		$hpay_panel_url = "https://" . ($environment == "sandbox" ? "sandbox." : "") . "pay.holest.com";
		
		$this->method_title        =  "HolestPay: " . $this->_method_data["SystemTitle"];
		$this->method_description  =  sprintf(__("Configure this payment method on %s","holestpay"),  "<a class='hpayopen' href='{$hpay_panel_url}/paymentdetails/{$this->_method_data["HPaySiteMethodId"]}'>" . $hpay_panel_url . "</a>");
		$this->enabled             = $this->_method_data["Enabled"] ? "yes" : "no";
		
		$this->form_fields = array(
			'enabled' => array(
				'title' => "<a class='button button-primary hpayopen hpayautoopen' href='{$hpay_panel_url}/paymentdetails/{$this->_method_data["HPaySiteMethodId"]}'>" . __( 'Configure on HolestPay portal', 'holestpay' ) . ( $environment == "sandbox" ? " (sandbox) " : "") . "</a>", 
				'type' => 'hidden',
				'label' => __( 'Change Enabled/Disabled on HolestPay', 'holestpay' ),
				'default' => 'yes'
			),
			'hpaymethod_replaced_form' => array(
				'title' => "", 
				'type' => 'hidden',
				'label' => "",
				'default' => ''
			),
		);
		
		add_action( 'woocommerce_thankyou', array( $this, 'thankyou_global' ),1 );
	}
	
	
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		if(HPay_Core::instance()->getSetting("enable_log","") == 1){
			global $hpay_log_file;
			if(!$hpay_log_file){
				if(!file_exists(WP_CONTENT_DIR . "/uploads/hpay-logs")){
					@mkdir(WP_CONTENT_DIR . "/uploads/hpay-logs",0775,true);
				}
				$order_id = $order_id;
				$hpay_log_file = WP_CONTENT_DIR . "/uploads/hpay-logs/H" . date("YmdHis") . "_refund_{$order_id}_" . rand(10000,99999) . ".log";
			}
		}				
		$order = hpay_get_order($order_id);
		if($order){
			$hmethod = $hpay_paymethod = HPay_Core::payment_method_instance($order->get_payment_method()); 
			if($hmethod){
				try{
					
					if(!HPay_Core::instance()->lockHOrderUpdate($order->get_order_key())){
						$hpay_payment_status = HPay_Core::instance()->orderHpayPaymentStatus( hpay_get_order($order_id, true) );
						return strpos($hpay_payment_status,"REFUND") !== false;
					}
					
					$rurl = "handlers/payment/" . HPay_Core::instance()->getSetting("merchant_site_uid","") . "/" . $hmethod->hpay_id . "/" . $order->get_order_key() . "/refundRequest"; 
					
					$result = HPay_Core::instance()->hpayClientApiCall($rurl, array(
						'order_uid'   => $order->get_order_key(),
						'rand' 	      => md5($order->get_order_key() . rand(1000000,9999999)),
						'order_total' => floatval($amount),
						'order_items' => HPay_Core::instance()->getOrderItems($order)
					));
					
					global $hpay_log_file;
					if($hpay_log_file)
						hpay_write_log($hpay_log_file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
					
					HPay_Core::instance()->unlockHOrderUpdate($order->get_order_key());
					
					if($result && isset($result["status"]) && !isset($result["error"])){
						global $hpay_site_refund_ongoing;
						$hpay_site_refund_ongoing = true;
						
						if(HPay_Core::instance()->resultAlreadyReceived($result)){
							if(strpos($result["status"],"REFUND") !== false){
								return true;
							}else{
								return false;
							}
						}
						
						if(strpos($result["status"],"REFUND") !== false){
							$refunds = $order->get_meta("_hpay_refunds");
							if(!$refunds){
								$refunds = array();
							}
							
							$wrefunds = $order->get_refunds();
							if(!empty($wrefunds)){
								$rid = 0;
								foreach($wrefunds as $wrefund){
									if($wrefund->get_id() > $rid){
										$rid = $wrefund->get_id();
									}
								}
								if(!$rid){
									$rid = "REFUND";
								}
								$refunds[$result["transaction_uid"]] = $rid;
							}else{
								$refunds[$result["transaction_uid"]] = "REFUND";
							}
							
							$order->update_meta_data("_hpay_refunds",$refunds);
							$order->save_meta_data();
						}
						
						$udp = HPay_Core::instance()->onOrderUpdate($result, $order);
						
						$hpay_site_refund_ongoing = false;
						
						return true;
					}
				}catch(Throwable $ex){
					hpay_write_log("error",$ex);
					if(HPay_Core::instance()->getSetting("enable_log","") == 1){
						global $hpay_log_file;
						@file_put_contents($hpay_log_file,date("Y-m-d H:i:s") . ": " . $ex->getMessage(),FILE_APPEND);
					}
				}
			}
		}
		return false;
	}
	
	public function payment_fields(){
		echo "<div class='hpay-payment-method-description'>" . wp_kses($this->description,'post') . "<div>";
		
		?>
		<input type='hidden' id="<?php echo esc_attr($this->id) ?>_payresult" name="<?php echo esc_attr($this->id) ?>_payresult" value="" />
		<input type='hidden' id="<?php echo esc_attr($this->id) ?>_is_user_input" name="<?php echo esc_attr($this->id) ?>_is_user_input" value="1"  />
		<?php
		
		if(get_current_user_id() && in_array("tokenization",$this->supports)){
			
			$no_tokens = false;
			if(isset($this->_method_data["No Card Tokenization"])){
				if($this->_method_data["No Card Tokenization"]){
					$no_tokens = true;
				}
			}
			
			if(!$no_tokens){
				HPay_Core::instance()->displayUserVaults(get_current_user_id(), $this, true, esc_attr__("Use saved payment token vault reference","holestpay"), false, false, null, true);
			}
		}
		?>
		<div data-hpay-dock-pmethod="<?php echo esc_attr($this->hpay_id);?>" data-hpay-dock-ptokenref-selector="input[id^='hpaypayment-{$pmid}_vault_token_id_']:checked" ></div>
		<?php
	}
	
	public function get_presentable_transaction($order_id){
		if(is_numeric($order_id)){
			//
		}else if(is_a($order_id,"WC_Order")){
			$order_id = $order_id->get_id();
		}
		return HPay_Core::instance()->get_presentable_transaction($order_id);
	}
	
	public function thankyou_global($order_id){
		$hpay_payment_status = HPay_Core::instance()->orderHpayPaymentStatus($order_id);
		
		if(!in_array($hpay_payment_status,array("PAID","RESERVED","SUCCESS","AWAITING","PARTIALLY-REFUNDED","REFUNDED","VOID"))){
			
			$order = hpay_get_order( $order_id );
			
			global $hpay_thankyou_pay_button;
			if(!$hpay_thankyou_pay_button){
				?>
				<p><a href="<?php echo esc_url($order->get_checkout_payment_url()); ?>" class="button alt button-primary"><?php echo __("Pay","holestpay"); ?></a></p>
				<?php
				$hpay_thankyou_pay_button = true;
			}
			
			if($order->get_meta("_hpay_thank_you_page_payment")){
				$hpaymethod_id = $order->get_meta("_hpay_thank_you_page_payment");
				
				if($hpaymethod_id == $this->hpay_id){
					$subscription_uid = "";
					$is_subscription = HPay_Core::instance()->wc_isSubsription($order);
					if($is_subscription){
						$no_tokens = false;
					}
					
					$cof = "none";
					if(in_array("tokenization",$this->supports)){
						$cof = "optional";
						
						if($no_tokens){
							$cof = "none";
						}
						
						if($is_subscription){
							$cof = "required";
						}
						
						if(HPay_Core::instance()->getSetting("always_require_vault","")){
							$cof = "required";
						}
					}
					
					$result_accept = array(
						"url" => admin_url('admin-ajax.php') . "?action=hpay-webhook&topic=payresult&pos_pm_id=" . $this->id
					);
					
					$vault_token_uid = "";
					if($order->get_meta("_hpay_thank_you_vault_token_id")){
						$token = WC_Payment_Tokens::get(intval($order->get_meta("_hpay_thank_you_vault_token_id")));
						if($token){
							$vault_token_uid = $token->vault_token_uid();
						}
					}
					
					///////////////////////////////////////////////////////////////////////////////////////////////////////////
					$pay_request = HPay_Core::instance()->generateHPayRequest($order, $hpaymethod_id, $cof, $vault_token_uid, $subscription_uid);
					echo '<script type="text/javascript"> 
							let __callHPayPayment  = function(){ HPayInit(HolestPayCheckout.merchant_site_uid, HolestPayCheckout.hpaylang).then(r => {  window.hpay_method_wcapi =' . json_encode($result_accept) . ';window.hpay_pay_wc_order_id=' . intval($order_id) . ';window.hpay_last_pay_req = ' . json_encode($pay_request) . ';  presentHPayPayForm(window.hpay_last_pay_req); }); };
							if(typeof HPayInit !== "undefined"){
								__callHPayPayment();
							}else{
								document.addEventListener("onHpayScriptLoad", function(){
								  __callHPayPayment();
								});		
							}				
						  </script>';
				}
			}
		}
	}
	
	public function thankyou($order_id){
		
	}
	
	public function installmentsForVault($token){
		try{
			if($this->supportsOperation("charge_installmets") && $this->_method_data["Installments BIN Table"] && !empty($this->_method_data["Installments BIN Table"])){
				$capable = false;
				foreach($this->_method_data as $name => $value){
					if(stripos($name,"Installments Capable") !== false && $value){
						$capable = true;
						break;	
					}	
				}
				if($capable){
					$data = array();
					$payment_total = null;
					
					if(!is_admin()){
						if(function_exists("WC")){
							$cart_data = null;
							if(WC()->cart)
								$cart_data = WC()->cart;
							if($cart_data){
								if(!$cart_data->is_empty()){
									$order_total    = wc_prices_include_tax() ? $cart_data->get_cart_contents_total() + $cart_data->get_cart_contents_tax() : $cart_data->get_cart_contents_total();
									$exchnage_rate = 1.00;
									$order_currency = esc_attr(hpay_get_wc_order_currency(null));
									if(isset($this->_method_data["PaymentCurrency"]) && $this->_method_data["PaymentCurrency"] && $this->_method_data["PaymentCurrency"] != $order_currency){
										$exchnage_rate = HPay_Core::getMerchantExchnageRate($order_currency, $this->_method_data["PaymentCurrency"]);
									}
									$payment_total = $order_total * $exchnage_rate;
								}
							}
						}
					}
					
					foreach($this->_method_data["Installments BIN Table"] as $bin_rule){
						if(!isset($bin_rule["BINs"]) || !isset($bin_rule["Available installments"]))
							continue;
						
						if(!$bin_rule["BINs"] || !$bin_rule["Available installments"])
							continue;
						
						if(!is_admin()){
							if($payment_total && $payment_total > 0){
								if(isset($bin_rule["Minimal Total"])){
									$min_total = floatval($bin_rule["Minimal Total"]);
									if($min_total > 0){
										if($payment_total < $min_total){
											continue;
										}
									}
								}
							}
						}
						
						$B6 = substr($token->vault_card_umask(),0,6);
						if(strpos($bin_rule["BINs"],$B6) !== false){
							$ranges = explode(",",$bin_rule["Available installments"]);
							foreach($ranges as $range){
								if(stripos($range,"-")){
									$r = explode("-",$range);
									if(count($r) == 2){
										$r[0] = intval($r[0]);
										$r[1] = intval($r[1]);
										if($r[0] && $r[1] && $r[1] > $r[0]){
											for($i = $r[0]; $i <= $r[1]; $i++){
												if($i >= 2){
													$data[$i] = $i;
												}	 
											}	
										}
									}
								}else{
									if(intval($range) > 2){
										$data[$range] = $range;	 
									}
								}
							}
						}
					}
					return array_keys($data);
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return array();
	}
	
	public function installmentsForVaultHTML($token){
		$available_installments = $this->installmentsForVault($token);
		if(!empty($available_installments)){
			echo "&nbsp;&nbsp;&nbsp;| " . __("Installments","holestpay") . ": <select id='" . esc_attr($this->id) . "_monthly_installments' name='" . esc_attr($this->id) . "_monthly_installments'><option value=''>--</option>";
					foreach($available_installments as $index => $installments){
						echo "<option value='" . esc_attr($installments) . "'>{$installments}</option>";	
					}
			echo "</select>";
		}
		return "";
	}
	
	public function acceptResult($order, $result){
		return HPay_Core::instance()->acceptResult($order, $result, $this->id);
	}
	
	function process_payment( $order_id ) {
		$is_user_input  = hpay_read_request_parm("{$this->id}_is_user_input", null);
		$vault_token_id = hpay_read_request_parm($this->id . "_vault_token_id","");
		$payresult      = hpay_read_request_parm("{$this->id}_payresult", null);
		
		global $hpay_monthly_installments_in_request;
		$hpay_monthly_installments_in_request = "";
		
		if(defined('REST_REQUEST')){
			$rest_request = file_get_contents('php://input');
			if($rest_request){
				$rest_request = json_decode($rest_request, true);
				if($rest_request){
					if(isset($rest_request["payment_data"])){
						$payment_data = array();
						foreach($rest_request["payment_data"] as $item){
							$payment_data[$item["key"]] = $item["value"];
						}
						
						if(isset($payment_data["{$this->id}_is_user_input"])){
							$is_user_input  = $payment_data["{$this->id}_is_user_input"];
						}
						
						if(isset($payment_data[$this->id . "_vault_token_id"])){
							$vault_token_id = $payment_data[$this->id . "_vault_token_id"];
						}
						
						if(isset($payment_data["{$this->id}_payresult"])){
							$payresult      = $payment_data["{$this->id}_payresult"];
						}
						
						if(isset($payment_data["{$this->id}_monthly_installments"])){
							if($payment_data["{$this->id}_monthly_installments"])
								$hpay_monthly_installments_in_request = $payment_data["{$this->id}_monthly_installments"];
						}
					}
				}
			}
		}
		
		global $woocommerce;
		$order = hpay_get_order( $order_id );
		
		if(!$order){
			return array(
				'result' => 'error',
				'messages' => __("Order not found",'holestpay')
			);
		}
		
		$no_tokens = false;
		if(isset($this->_method_data["No Card Tokenization"])){
			if($this->_method_data["No Card Tokenization"]){
				$no_tokens = true;
			}
		}
		
		$subscription_uid = "";
		$is_subscription = HPay_Core::instance()->wc_isSubsription($order);
		if($is_subscription){
			$no_tokens = false;
		}
				
		$return_result = false;
		$clear_cart = false;
		
		$rejected   = null;
		$vault_token_uid = "";
		
		if($is_user_input){
			if($payresult){
				$result = json_decode($payresult, true);
				if(isset($result["status"]) && isset($result["transaction_uid"])){
					$res = $this->acceptResult($order, $result);
					if($res !== true){
						$rejected = $res;
					}
				}
			}
			
			if($rejected){
				wp_send_json(array(
					'result'   => 'error',
					'messages' => array("<div class='woocommerce-error' >" . esc_attr($rejected) . "</div>"),
					'reload'   => false
				),200);
				return;
			}
			
			
			
			$vault_token_uid = "";
			if($vault_token_id){
				if(!ctype_digit("{$vault_token_id}")){
					$token = WC_Payment_Token_HPay::has_hpay_vault_token_uid($vault_token_id);
					if($token){
						$vault_token_uid = $vault_token_id;
						$vault_token_id = $token->get_id();
					}else{
						$vault_token_uid = "";
						$vault_token_id = null;
					}
				}else{
					$token = WC_Payment_Tokens::get(intval($vault_token_id));
					if($token){
						$vault_token_uid = $token->vault_token_uid();
					}else{
						$vault_token_id = null;
					}
				}
			}
			
			if(!$return_result && !$order->is_paid()){
				
				if(is_checkout()){
					$order_status = $order->get_status();
					$new_status   = apply_filters( 'hpay_before_payment_order_status', $order_status , $order ,$this);
					if($new_status && $new_status != $order_status){
						$order->update_status($new_status);
					}
					
					if(HPay_Core::instance()->getSetting("thank_you_page_payment","")){
						//$order = hpay_get_order( $order_id );
						$order->update_meta_data("_hpay_thank_you_page_payment", $this->hpay_id);
						$order->update_meta_data("_hpay_thank_you_vault_token_id",$vault_token_id);
						$order->save_meta_data();
						return array(
							'result'   => 'success',
							'redirect' => $this->get_return_url( $order ),
						);
					}
				}
				
				$cof = "none";
				if(in_array("tokenization",$this->supports)){
					$cof = "optional";
					
					if($no_tokens){
						$cof = "none";
					}
					
					if($is_subscription){
						$cof = "required";
					}
					
					if(HPay_Core::instance()->getSetting("always_require_vault","")){
						$cof = "required";
					}
				}
				
				$result_accept = array(
					"url" => admin_url('admin-ajax.php') . "?action=hpay-webhook&topic=payresult&pos_pm_id=" . $this->id
				);
				
				///////////////////////////////////////////////////////////////////////////////////////////////////////////
				
				$pay_request = HPay_Core::instance()->generateHPayRequest($order, $this->id, $cof, $vault_token_uid, $subscription_uid);
								
				return array(
					'result'   => 'success',
					'messages' => '<script>window.hpay_method_wcapi =' . json_encode($result_accept) . ';window.hpay_pay_wc_order_id=' . intval($order_id) . '; presentHPayPayForm(' . json_encode($pay_request) . ');</script>',
					'reload'   => false
				);
			}
		}else{
			HPay_Core::instance()->admin()->chargeOrder($order, null, $this->hpay_id);
			$order = hpay_get_order( $order_id );
			
			if($order->is_paid()){
				$respond = array(
					'result' => 'success',
					'redirect' => $this->get_return_url( $order )
				);
				$respond["order_user_url"] = $respond['redirect'];
				return $respond;
			}else{
				return array(
					'result'   => 'error',
					'reload'   => false
				);	
			}
		}
		
		if($is_user_input){
			// Remove cart
			if($clear_cart && isset($woocommerce) && isset($woocommerce->cart))
				$woocommerce->cart->empty_cart();
		}

		$respond = array(
			'result' => 'success',
			'redirect' => $this->get_return_url( $order )
		);
		$respond["order_user_url"] = $respond['redirect'];
		return $respond;
	}
	
};