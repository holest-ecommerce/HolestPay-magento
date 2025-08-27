<?php
//HOLESTPAY 2023
if(!defined("HPAY_PRODUCTION_URL")){
	die("Direct access is not allowed");
};

class HPay_Core {
	
	use HPay_Core_Static;
	use HPay_Core_Conversion;
	use HPay_Core_Update;
	use HPay_Core_Data;
	use HPay_Core_Woo;
	use HPay_Core_WooGUI;
	use HPay_Core_Maintain;
	use HPay_Core_Checkout;
	use HPay_Core_Email;
	
	private $FRONT = null;
	private $ADMIN = null;
	
	public  $plugin           = HPAY_PLUGIN;
	public  $plugin_name      = "holestpay";
	public  $plugin_url       = HPAY_PLUGIN_URL;
	private $_plugin_data     = null;
	private $_settings        = null;
	private $_possible_hpay_pay_statuses = array("SUCCESS","PAID","PAYING","OVERDUE","AWAITING", "REFUNDED", "PARTIALLY-REFUNDED","VOID", "RESERVED", "EXPIRED","CANCELED", "OBLIGATED", "REFUSED");
	
	private $default_settings = array(
	    "enabled"                     => 1,
		"workmode"                    => "woocommerce", 
		"woo_status_map_paid"         => "wc-completed",
		"woo_status_map_reserve"      => "wc-on-hold",
		"woo_status_map_awaiting"     => "wc-on-hold",
		"woo_status_map_void"         => "wc-cancelled",
		"woo_status_map_refund"       => "wc-refunded",
		"woo_status_map_partial_refund" => "",
		"woo_status_map_shipped"      => "",
		"woo_status_set_policy"       => "each",
		"manage_all_orders"           => 1 
	);
	
	private function __construct(){
		global $hpay_core_instance;
		$hpay_core_instance = $this;
		
		$this->ADMIN = new HPay_Admin($this);
		$this->FRONT = new HPay_Front($this);
		
		add_action( 'plugins_loaded', array($this,'onPluginsLoaded'),1);
		add_action( 'wp_loaded', array($this,'checkResponses'),99);
		
		add_action( 'wp_ajax_nopriv_hpay-webhook', array( $this, 'webhookHandler' ));
		add_action( 'wp_ajax_hpay-webhook', array( $this, 'webhookHandler' ));
		
		add_filter( 'woocommerce_available_payment_gateways', array( $this,'wc_filter_checkout_payment_gateways'), 50 ,1);
		add_filter( 'woocommerce_package_rates', array( $this,'wc_filter_shipping_methods'), 50 ,1);
		
		add_action( 'wp_loaded', array($this,'check_schedules'));
		add_action( 'hpay_do_charge_order', array($this,'do_charge_order_action'), 1, 3);
		
		add_action( 'hpay_15min_run', array( $this,'wc_check_for_subscriptions_for_charge'), 99, 0);
		
		add_action( 'wp_ajax_nopriv_hpay-call-run-1h', array( $this, 'call_run_1h' ));
		add_action( 'wp_ajax_nopriv_hpay-call-run-15min', array( $this, 'call_run_15min' ));
		
		add_action( 'wp_ajax_hpay-call-run-1h', array( $this, 'call_run_1h' ));
		add_action( 'wp_ajax_hpay-call-run-15min', array( $this, 'call_run_15min' ));
		
		add_action( 'wcs_renewal_order_created', array( $this,'wcs_renewal_order_created'), 99, 2); 
		 
		add_action( 'woocommerce_thankyou', array( $this, 'thankyou_page' ),30 );
		add_action( 'wp_footer',  array( $this, 'footer_branding'), 999);
		
		add_action( 'init',  array( $this, 'onInit'), 999);
		
		try{
			$this->upgrade_setup();
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		
		if(!hpay_read_get_parm("__hpay_only_load_upgrade__")){
			try{
				$this->setup_woo();	
			}catch(Throwable $ex){
				hpay_write_log("error",$ex);
			}
			
			try{
				$this->setup_woo_gui();
			}catch(Throwable $ex){
				hpay_write_log("error",$ex);
			}
			
			try{
				$this->checkout_setup();
			}catch(Throwable $ex){
				hpay_write_log("error",$ex);
			}
			
			try{
				$this->setup_mailing();
			}catch(Throwable $ex){
				hpay_write_log("error",$ex);
			}
			
			try{
				$this->maintain_run();
			}catch(Throwable $ex){
				hpay_write_log("error",$ex);
			}
		}
	}
	
	public function call_run_15min(){
		if($this->getSetting("enable_log","") == 1){
			if(!file_exists(WP_CONTENT_DIR . "/uploads/hpay-logs")){
				@mkdir(WP_CONTENT_DIR . "/uploads/hpay-logs",0775,true);
			}
		}
			
		try{
			do_action("hpay_15min_run");
			hpay_write_log('schedule',"DONE 15min RUN");
			echo "DONE 15min";
		}catch(Throwable $ex){
			hpay_write_log('schedule_exception',$ex);
		}	
		die;
	}
	
	public function call_run_1h(){
		
		try{
			$this->maintainCleanLocks();
		}catch(Throwable $ex){
			//
		}
		
		if($this->getSetting("enable_log","") == 1){
			if(!file_exists(WP_CONTENT_DIR . "/uploads/hpay-logs")){
				@mkdir(WP_CONTENT_DIR . "/uploads/hpay-logs",0775,true);
			}
		}
		try{
			do_action("hpay_hourly_run");
			hpay_write_log('schedule',"DONE 1h RUN");
			echo "DONE 1h";
		}catch(Throwable $ex){
			hpay_write_log('schedule_exception',$ex);		
		}
		die;
	}
	
	public function check_schedules( $schedules = null) {
		if ( hpay_read_server_parm('REQUEST_METHOD','') == 'GET') {
			$ts = intval(get_option("hpay_hourly_run_ts",0));
			if($ts + 3600 < time()){
				update_option("hpay_hourly_run_ts",time(), true);
				try{
					$response = wp_remote_get( admin_url( 'admin-ajax.php' ) . "?action=hpay-call-run-1h"  , array(
							'method'      => 'GET',
							'timeout'     => 5,
							'redirection' => 5,
							'httpversion' => '1.0',
							'blocking'    => false
						)
					);
				}catch(Throwable $ex){
					hpay_write_log("error",$ex);
				}
			}
			
			$ts = intval(get_option("hpay_15min_run_ts",0));
			if($ts + 900 < time()){
				update_option("hpay_15min_run_ts",time(), true);
				try{
					$response = wp_remote_get( admin_url( 'admin-ajax.php' ) . "?action=hpay-call-run-15min"  , array(
							'method'      => 'GET',
							'timeout'     => 5,
							'redirection' => 5,
							'httpversion' => '1.0',
							'blocking'    => false
						)
					);
				}catch(Throwable $ex){
					hpay_write_log("error",$ex);
				}
			}
		}
	}
	
	public function onInit(){
		try{
			
			$translation_loaded = false;
			
			$locale = get_locale();
			
			if(file_exists(WP_LANG_DIR . "/holestpay/plugins/holestpay-{$locale}.po"))
				$translation_loaded = load_plugin_textdomain( 'holestpay', false, WP_LANG_DIR . "/holestpay" );
			
			if(!!$translation_loaded && file_exists(WP_LANG_DIR . "/plugins/holestpay-{$locale}.po"))
				$translation_loaded = load_plugin_textdomain( 'holestpay', false, WP_LANG_DIR . "/plugins" );
			
			if(!$translation_loaded)
				$translation_loaded = load_plugin_textdomain( 'holestpay', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );
			
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		
	}
	
	public function onPluginsLoaded(){
		try{
			
			if(is_admin() || current_user_can( 'administrator' )){
				$this->ADMIN->init();
			}else if(!is_admin()){
				$this->FRONT->init();
			}
			
			HPay_init();
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	public function checkResponses(){
		try{
			$str_resp = hpay_read_request_parm('hpay_forwarded_payment_response','');
			
			if(!$str_resp){
				$str_resp = get_transient('hpay_forwarded_payment_response' . session_id(),'');//IF REDIRECT HAPPENED
				if($str_resp){
					delete_transient('hpay_forwarded_payment_response' . session_id());
				}
			}
						
			if($str_resp){
				$result = json_decode($str_resp, true);
				if(!$result){
					$result = json_decode(stripslashes($str_resp), true);
				}
				
				$hmethod = null;
				if(isset($result["order_uid"])){
					$order_id = wc_get_order_id_by_order_key($result["order_uid"]);
					if($order_id){
						$order = hpay_get_order($order_id);
						if($order){		
							if(isset($result["payment_method"])){
								$hmethod = HPay_Core::payment_method_instance(intval($result["payment_method"]));
							}
							
							if(!$hmethod){
								if($order->get_payment_method()){
									$hmethod = HPay_Core::payment_method_instance(intval($result["payment_method"]));	
								}
							}
							
							if(!$hmethod){
								$hmths = HPay_Core::payment_methods_enabled();
								if(!empty($hmths)){
									$hmethod = $hmths[0];
								}
							}
							
							if($hmethod){
								if(isset($result["status"]) && isset($result["transaction_uid"])){
									$res = $hmethod->acceptResult($order, $result, $hmethod->id);
									
									if($res === true){
										$return_url = apply_filters( 'woocommerce_get_return_url', $order->get_checkout_order_received_url(), $order );
										if(hpay_read_request_parm('hpay_local_request','')){
											wp_send_json(array("received" => "OK", "accept_result" => "ACCEPTED", "order_user_url" => $return_url),200);
										}else{
											wp_redirect( $return_url );
										}
										die;
									}
									
									return;
								}
							}else{
								$order->add_order_note(__('HPAY aborted payment response', 'holestpay'));
							}
						}
					}
				}
				
				//IF RESULT IS NOT ACCEPTED
				if(hpay_read_request_parm('hpay_local_request','')){
					wp_send_json(array("received" => "NO", "accept_result" => "REFUSED"),200);
					exit;
				}else{
					if ( wp_redirect( wc_get_cart_url())){
						exit;
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
	}
	
	public function updateSettings($settings){
		try{
			if($settings){
				if(is_array($settings)){
					if(isset($settings["environment"])){
						
						$environ = $settings["environment"];
						if(isset($settings["{$environ}POS"])){
							if(isset($settings["{$environ}POS"]["payment"])){
								if(!empty($settings["{$environ}POS"]["payment"])){
									foreach($settings["{$environ}POS"]["payment"] as $index => $pm){
										update_option("woocommerce_hpaypayment-{$pm["HPaySiteMethodId"]}_settings", array(
											"enabled" => $pm["Enabled"] ? "yes": "no"
										),true);
									}
								}
							}	
						}	
						
						if(update_option("holestpay_settings", $settings, true)){
							$this->_settings = $settings;
						}
						
						return true;
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}
		return false;
	}
	
	private function applyDefaultSettings(& $settings){
		if(!$settings)
			return $settings;
		
		foreach($this->default_settings as $key => $val){
			if(!isset($settings[$key])){
				$settings[$key] = $val;
			}
		}
		return $settings;
	}
	
	public function getSettings($force_reload = false){
		if(isset($this->_settings) && !$force_reload){
			return $this->_settings;
		}
		
		$this->_settings = array_merge(array(
			"enabled"           => 0, 
			"workmode"          => "woocommerce",
			"manage_all_orders" => 1
		), get_option("holestpay_settings", array()));
		
		$this->applyDefaultSettings($this->_settings);
		
		if(!$this->_settings){
			$this->_settings = array("enabled" => 0);
		}
		
		if(!is_array($this->_settings)){
			$this->_settings = array("enabled" => 0);
		}
		
		$this->_settings["mode"] = "woocommerce";//OVDE FOR NOW
		
		return $this->_settings;
	}
	
	public function getSetting($name, $default = null){
		$settings = $this->getSettings();
		if(isset($settings[$name])){
			return $settings[$name];
		}
		
		if($name != "environment"){
			$environment = $this->getSetting("environment", null);
			if($environment){
				if(isset($settings["{$environment}"])){
					if(isset($settings["{$environment}"][$name])){
						return $settings["{$environment}"][$name];
					}
				}
			}
		}
		
		return $default;
	}
	
	public function getHPayURL($path = ""){
		$url = $this->getSetting("environment",null) == "production" ? HPAY_PRODUCTION_URL : HPAY_SANDBOX_URL;
		if($path){
			return rtrim($url,"/") . "/" . ltrim($path,"/");	
		}
		return $url;
	}
	
	/*
	$transaction_uid: - empty for pay request
	$status: - empty for pay request
	$order_uid: order uniqe identifier as in request - we use woo order_key for this plugin
	$amount: amount - total amount in order currency, 
	$currency: 3 letter order currency code like EUR, USD, CHF ... 
	$vault_token_uid: If new token it will be 'new'. On subsequent changes it will have value assigned back from HolestPay.
	$subscription_uid: if subscripion then subscripion_uid is used. Value used should be as in current request.  
	*/
	public function payRequestSignatureHash($transaction_uid, $status, $order_uid, $amount, $currency, $vault_token_uid = "", $subscription_uid = "", $rand = ""){
		if(!trim($order_uid))
			$order_uid = "";
		else
			$order_uid = trim($order_uid);
		
		if($amount === null || trim($amount) === ""){
			$amount = 0;
		}
		
		$amount = number_format($amount,8,".","");//12 decimals , . is decimal separator , no thousand separator
		
		if($currency && strlen($currency) !== 3)
			return null;
		
		if(!$currency){
			$currency = "";
		}else{
			$currency = trim($currency);
		}
		
		if(!$subscription_uid)
			$subscription_uid = "";
		else 
			$subscription_uid = trim($subscription_uid);
		
		if(!$vault_token_uid)
			$vault_token_uid = "";
		else 
			$vault_token_uid = trim($vault_token_uid);
		
		if(!$transaction_uid)
			$transaction_uid = "";
		
		if(!$rand)
			$rand = "";
		
		if(!$status)
			$status          = "";
		
		$merchant_site_uid = $this->getSetting("merchant_site_uid","undefined");
		$secret_token = $this->getSetting("secret_token","undefined");
		
		$srcstr = "{$transaction_uid}|{$status}|{$order_uid}|{$amount}|{$currency}|{$vault_token_uid}|{$subscription_uid}{$rand}";
		$srcstrmd5 = md5($srcstr . $merchant_site_uid);
		
		return strtolower(hash("sha512", $srcstrmd5 . $secret_token));
	}

	public function signRequestData(& $data){
		if(!$data)
			$data = array();

		$status = null;
		$order_uid = null;
		$amount = null;
		$currency = null;
		$vault_token_uid = null;
		$subscription_uid = null;
		$rand = null;

		if(isset($data["status"])) $status = $data["status"];
		if(isset($data["order_uid"])) $order_uid = $data["order_uid"];
		if(isset($data["order_amount"])) $amount = $data["order_amount"];
		if(isset($data["order_currency"])) $currency = $data["order_currency"];
		if(isset($data["vault_token_uid"])) $vault_token_uid = $data["vault_token_uid"];
		if(isset($data["subscription_uid"])) $subscription_uid = $data["subscription_uid"];
		if(isset($data["rand"])) $rand = $data["rand"];

		if(!$rand){
			$rand = uniqid("rnd");
			$data["rand"] = $rand;
		}
		
	
		$data["verificationhash"] = $this->payRequestSignatureHash(null, $status, $order_uid, $amount, $currency, $vault_token_uid, $subscription_uid, $rand);
	}
	
	/*
	$returned_vhash: hash to validate
	$transaction_uid as in response or ""
	$status  as in response or ""
	$order_uid: order uniqe identifier as in response
	$amount: amount - total amount as in response
	$currency: 3 letter order currency code like EUR, USD, CHF ... as in response
	$vault_token_uid: as in response or ""
	$subscription_uid:  as in response or ""
	$rand: extra security string
	*/
	public function payResponseVerifyHash($returned_vhash, $transaction_uid, $status, $order_uid, $amount, $currency, $vault_token_uid = "", $subscription_uid = "", $rand = ""){
		
		if(!trim($order_uid)){
			return null;
		}else{
			$order_uid = trim($order_uid);
		}
		
		if($amount === null){
			$amount = 0;
		}
		
		$amount = number_format($amount,8,".","");//8 decimals , . is decimal separator , no thousand separator
		
		if($currency && strlen($currency) !== 3)
			return null;
		
		if(!$rand)
			$rand = "";
		
		if(!$subscription_uid)
			$subscription_uid = "";
		else 
			$subscription_uid = trim($subscription_uid);
		
		if(!$vault_token_uid)
			$vault_token_uid = "";
		else 
			$vault_token_uid = trim($vault_token_uid);
		
		if(!$transaction_uid)
			$transaction_uid = "";
		else 
			$transaction_uid = trim($transaction_uid);
		
		if(!$status)
			$status = "";
		else 
			$status = trim($status);
		
		
		$merchant_site_uid = $this->getSetting("merchant_site_uid","undefined");
		$secret_token = $this->getSetting("secret_token","undefined");
		
		$srcstr    = "{$transaction_uid}|{$status}|{$order_uid}|{$amount}|{$currency}|{$vault_token_uid}|{$subscription_uid}{$rand}";
		$srcstrmd5 = md5($srcstr . $merchant_site_uid);
		$computed  = strtolower(hash("sha512", $srcstrmd5 . $secret_token));
		
		return $computed == strtolower($returned_vhash);
	}
	
	public function verifyResponse($response){
		if(!$response)
			return false;
		
		if(!isset($response["vhash"])){
			return false;
		}
		
		if(!isset($response["transaction_uid"])){
			$response["transaction_uid"] = "";
		}
		
		if(!isset($response["status"])){
			$response["status"] = "";
		}
		
		if(!isset($response["order_uid"])){
			$response["order_uid"] = "";
		}
		
		if(!isset($response["order_amount"])){
			$response["order_amount"] = "0";
		}
		
		if(!isset($response["order_currency"])){
			$response["order_currency"] = "";
		}
		
		if(!isset($response["vault_token_uid"])){
			$response["vault_token_uid"] = "";
		}
		
		if(!isset($response["subscription_uid"])){
			$response["subscription_uid"] = "";
		}
		
		if(!isset($response["rand"])){
			$response["rand"] = "";
		}
		
		if(!isset($response["vhash"])){
			return false;
		}
		
		try{
			return $this->payResponseVerifyHash(
				$response["vhash"],
				$response["transaction_uid"],
				$response["status"],
				$response["order_uid"],
				$response["order_amount"],
				$response["order_currency"],
				$response["vault_token_uid"],
				$response["subscription_uid"],
				$response["rand"]
			);
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
			return false;
		}
	}
	
	public function translateKeys($data){
		if(!$data){
			$data = array("---" => __("Failed: no valid payment info response",'holestpay'));
		}
		$tdata = array();
		if($data){
			foreach($data as $key => $val){
				if(is_object($val) || is_array($val)){
					$tdata[__($key,"holestpay")] = $this->translateKeys($val);
				}else{
					$tdata[__($key,"holestpay")] = $val;
				}
			}
		}
		return $tdata;
	}
	
	public function get_presentable_transaction($order_id, $hpay_method_id = null){
		$hpay_responses = HPay_Core::instance()->getHPayPayResponses($order_id);
		if(!empty($order_id)){
			$last = null;
			$transaction_pay_status = null;
			
			foreach($hpay_responses as $index => $resp){
				$transaction_info = null;
				
				if($hpay_method_id){
					if($resp["payment_method"] != $hpay_method_id)
						continue;
				}
				
				if(isset($resp["transaction_user_info"])){
					if($resp["transaction_user_info"]){
						$transaction_info = $resp["transaction_user_info"];
					}
				}
				
				if(!$transaction_info){
					if(isset($resp["gateway_resp"])){
						$transaction_info = $resp["gateway_resp"];
					}
				}
				
				
				if(isset($resp["status"])){
					if(stripos($resp["status"],"SUCCESS") !== false){
						$transaction_pay_status =  "PAID";
					}else if(stripos($resp["status"],"PAID") !== false){
						$transaction_pay_status =  "PAID";
					}else if(stripos($resp["status"],"RESERVED") !== false){
						$transaction_pay_status =  "RESERVED";
					}else if(stripos($resp["status"],"AWAITING") !== false){
						$transaction_pay_status =  "AWAITING";
					}else if(stripos($resp["status"],"VOID") !== false){
						$transaction_pay_status =  "CANCELED";
					}else if(stripos($resp["status"],"PARTIALLY-REFUNDED") !== false){
						$transaction_pay_status =  "PARTIALLY-REFUNDED";
					}else if(stripos($resp["status"],"REFUND") !== false){
						$transaction_pay_status =  "REFUNDED";
					}
				}
				
				if($transaction_info){
					$last = array(
						"transaction_user_info"   => $transaction_info,
						"transaction_pay_status"  => $transaction_pay_status,
						"status"                  => $resp["status"]
					);
				}	
			}
			
			return $last;
		}
		return null;
	}
	
	public function getHPayPayResponses($order){
		if(is_numeric($order)){
			$order = hpay_get_order($order);	
		}
		
		$hpay_responses = false;
		if($order)
			$hpay_responses = $order->get_meta("_hpay_payresponses");
		
		if(!$hpay_responses){
			$hpay_responses = array();
		}else if(is_string($hpay_responses)){
			$hpay_responses = json_decode($hpay_responses, true);
			if(!$hpay_responses){
				$hpay_responses = array();
			}else{
				foreach($hpay_responses as $index => $resp){
					if(!$resp || !is_array($resp)){
						unset($hpay_responses[$index]);
					}
				}
			}
		}
		return $hpay_responses;
	}
	
	public function setHPayPayResponses($order, $data, $save = true){
		
		if(is_numeric($order)){
			$order = hpay_get_order($order);
		}
		
		if($order){
			
			foreach($data as $index => $resp){
				if(!$resp || !is_array($resp)){
					unset($data[$index]);
				}
			}
			
			$order->update_meta_data("_hpay_payresponses", $data );
			if($save)
				$order->save_meta_data();
		}
	}
	
	public function do_charge_order_action($order_id, $token = null, $payment_method = null){
		try{
			$order = null;
			if(is_a($order_id,"WC_Order")){
				$order = $order_id;
				$order_id = $order->get_id();
			}else{
				$order = hpay_get_order($order_id);
			}
			
			$renewal = $this->wc_isSubsriptionRenewalOrder($order);
			
			if(!$order){
				return false;
			}
			
			if(!$order->get_customer_id()){
				return false;
			}
			
			$usetoken = null;
			if($token){
				if(is_a($order_id,"WC_Payment_Token_HPay")){
					$usetoken = $token;
				}else if(intval($token)){
					$usetoken = WC_Payment_Tokens::get( $token );
				}else if(is_string($token)){
					$usetoken = WC_Payment_Token_HPay::has_hpay_vault_token_uid($token, $order->get_customer_id(), null , $this->getSetting("merchant_site_uid",""));
				}
			}
			
			$hpay_method_id = null;
			
			if($payment_method){
				if(is_object($payment_method)){
					$hpay_method_id = $payment_method->hpay_id;
				}else if(intval($payment_method) || is_string($payment_method)){
					$hmet = HPay_Core::payment_method_instance($method_id);
					if($hmet){
						$hpay_method_id = $hmet->hpay_id;
					}
				}
			}
			
			$charge_res = $this->admin()->chargeOrder($order, $usetoken, $hpay_method_id);
			
			if($charge_res){
				if(isset($charge_res["skip"]))
					return false;
				
				if($renewal){
					if(isset($charge_res["status"])){
						if(strpos($charge_res["status"],"SUCCESS") !== false || strpos($charge_res["status"],"PAID") !== false){
							if($renewal == "wsc"){
								 WC_Subscriptions_Manager::process_subscription_payments_on_order(hpay_get_order($charge->order_id));
							}else if($renewal == "wsc"){
								//	
							}else{
								//	
							}
						}else if(strpos($charge_res["status"],"RESERVED") !== false || strpos($charge_res["status"],"AWAITING") !== false){
							if($renewal == "wsc"){
								
							}else if($renewal == "wsc"){
								//	
							}else{
								//	
							}
						}else if(strpos($charge_res["status"],"FAILED") !== false || strpos($charge_res["status"],"CANCELED") !== false){
							if($renewal == "wsc"){
								WC_Subscriptions_Manager::process_subscription_payment_failure_on_order(hpay_get_order($charge->order_id));
							}else if($renewal == "wsc"){
								//	
							}else{
								//	
							}
						}
					}
				}
			}
			return $charge_res;
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
			return false;
		}
	}
	
	public function should_store_wc_order($order_id, $is_status_change = false){
		$order = null;
		if(is_a($order_id,"WC_Order")){
			$order = $order_id;
			$order_id = $order->get_id();
		}else{
			$order = hpay_get_order($order_id);
		}
		
		if(hpay_woo_order_type($order_id) != "shop_order"){
			return false;
		}
		
		$store = false;
		if(stripos($order->get_payment_method(),"hpaypayment-") === false || $is_status_change){
			$store = $this->getSetting("manage_all_orders","") == 1;
			if(!$store){
				$fiscal_methods = $this->getPOSSetting("fiscal",null);
				if(!empty($fiscal_methods)){
					foreach($fiscal_methods as $fm){
						if($fm["Enabled"]){
							$store = true;
							break;
						}
					}
				}
				if(!$store){
					$shipping_method = @array_shift($order->get_shipping_methods());
					if($shipping_method){
						if(isset($shipping_method['method_id'])){
							$shipping_method_id = $shipping_method['method_id'];
							if(stripos($shipping_method_id,"hpayshipping-") !== false){
								$store = true;
							}
						}
					}
				}
			}
		}
		return $store;
	}
	
	public function callFiscalOrIntegrationDefaultAction($h_fi_method_info, $order){
		try{
			$order_id = null;
			if(is_numeric($order)){
				$order = hpay_get_order($order);
				$order_id = $order->get_id();
			}else{
				$order_id = $order->get_id();
			}
			
			if(HPAY_DEBUG_TRACE)
				hpay_write_log("trace", array($order_id, "callFiscalOrIntegrationDefaultAction","enter"));
			
			$triggered_for = $order->get_meta("_hpay_fi_{$h_fi_method_info["Uid"]}_default_action");
			if(!$triggered_for){
				$triggered_for = "";
			}
			
			$for_each_status = $this->getSetting("woo_status_trigger_" . $h_fi_method_info["Uid"] ."_each",false) == 1 ? true : false;
			
			$order_status = $order->get_status();
			
			if(strpos($order_status,"wc-") !== 0){
				$order_status = "wc-{$order_status}";
			}
			
			if(!$for_each_status && $triggered_for){
				if(HPAY_DEBUG_TRACE)
					hpay_write_log("trace", array($order_id, "callFiscalOrIntegrationDefaultAction","skip1"));
				return null;
			}else if($for_each_status && stripos(",{$triggered_for},",",{$order_status},") !== false){
				if(HPAY_DEBUG_TRACE)
					hpay_write_log("trace", array($order_id, "callFiscalOrIntegrationDefaultAction","skip2"));
				return null;
			}
			
			if(!$order->meta_exists("_hpay_status")){
				$this->store_order($order);
			}
			
			$callurl = "handlers/fiscal/" . $this->getSetting("merchant_site_uid","") . "/" . $h_fi_method_info["Uid"] . "/" . $order->get_order_key() . "/defaultAction";
			
			if(HPAY_DEBUG_TRACE)
				hpay_write_log("trace", array($order_id, "hpayClientApiCall BEFORE CALL",$callurl));
			
			$resp = $this->hpayClientApiCall($callurl,array("order_uid" => $order->get_order_key()));
			if(!isset($resp["error"])){
				if(HPAY_DEBUG_TRACE)
					hpay_write_log("trace", array($order_id, "hpayClientApiCall NO ERROR",$resp));
				
				$order->update_meta_data("_hpay_fi_{$h_fi_method_info["Uid"]}_default_action", $triggered_for . ($triggered_for ? "," : "") . $order_status);
				$upd_res = $this->onOrderUpdate($resp, $order);
				if($upd_res){
					$order->save();
				}
				return $upd_res;
			}else{
				$order->add_order_note( __('HPAY Fiscal/Integration defaultAction call', 'holestpay') . ": {$resp["error_code"]} {$resp["error"]}");
			}
		}catch(Throwable $ex){
			hpay_write_log("error", $ex);
		}
	}
	
	public function store_order($order_id, $with_status = null, $noresultawait = false){
		$order = null;
		
		if(is_a($order_id,"WC_Order")){
			$order = $order_id;
			$order_id = $order->get_id();
		}else{
			$order = hpay_get_order($order_id);
		}
		
		if(!$order){
			return false;
		}
		
		try{
			$pay_request = $this->generateHUpdateRequest($order);
			if(!$pay_request){
				throw new Exception('HPay request genarate failed for ' . $order_id);
			}
			
			if(!isset($pay_request["order_uid"])){
				hpay_write_log("error",$pay_request);
				throw new Exception('Generated request with un-identified order ' . $order_id);
			}
			
			$pay_request["merchant_site_uid"] = $this->getSetting("merchant_site_uid","");
			
			if(!$pay_request["merchant_site_uid"]){
				return false;
			}
			
			$hpay_method = null;
			if($order->get_payment_method()){
				$hpay_method = HPay_Core::payment_method_instance($order->get_payment_method());
			}
			
			if(!$hpay_method && !$with_status){
				$sset = false;
				
				if(count($order->get_refunds())){
					if($order->has_status('refunded')){
						$pay_request["status"] = "PAYMENT:REFUNDED";
						$sset = true;
					}else{
						$pay_request["status"] = "PAYMENT:PARTIALLY-REFUNDED";
						$sset = true;
					}
				}
				
				if(!$sset && $order->is_paid()){
					//$pay_request["status"] = "PAYMENT:PAID";
					//$sset = true;
				}
				
				if($order->has_status('cancelled')){
					$pay_request["status"] = "PAYMENT:CANCELED";
					$sset = true;
				}
				
				if($order->has_status('failed')){
					$pay_request["status"] = "PAYMENT:FAILED";
					$sset = true;
				}
				
				if(!$sset){
					$order_status = str_replace("wc-","",$order->get_status());
					if($order_status == "completed"){
						$pay_request["status"] = "PAYMENT:PAID";
					}else if($this->getSetting("woo_nonhpay_paid_status_wc-{$order_status}","") == 1){
						$pay_request["status"] = "PAYMENT:PAID";
					}
				}
			}
			
			if($with_status){
				$pay_request["status"] = $with_status;
			}
			
			//hpay_write_log("trace",array("store_order",$pay_request));
			
			$resp = $this->hpayClientApiCall("store", array("request_data" => $pay_request), !$noresultawait);
			
			if($noresultawait){
				return $resp;
			}
			
			$stored = false;
			if(isset($resp["error"])){
				hpay_write_log("error",$resp);
				
				$order->add_order_note( __('HPAY store order error', 'holestpay') . "<br/>" . $resp["error_code"] . ": " . $resp["error"]);
				return false;
			}else{
				if(!$resp){
					$order->add_order_note( __('HPAY store order error response', 'holestpay') . ": {$response}");
				}else if(!isset($resp["status"]) || !isset($resp["request_time"])){
					$order->add_order_note( __('HPAY store order error response object', 'holestpay') . ": " . json_encode($resp));
					hpay_write_log("error",$resp);
				}else if(!$this->verifyResponse($resp)){
					$order->add_order_note( __('HPAY store order response rejected due incorrect verification string!', 'holestpay'));
					hpay_write_log("error",$resp);
					return false;	
				}else{
					global $hpay_doing_order_store;
					$hpay_doing_order_store = true;
					$stored = $this->acceptResult($order, $resp, $order->get_payment_method());
					$hpay_doing_order_store = false;
				}
				return $resp;	
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
			global $hpay_doing_order_store;
			$hpay_doing_order_store = false;
			if($order)
				$order->add_order_note( __('Error exception on HPAY store order try', 'holestpay') . "<br/>" . $ex->getMessage());
			return false;
		}
	}

	public function hpayClientApiCall($endpoint_path, $data, $blocking = true){
		try{
			if(!$data){
				return false;
			}
			
			$call_url = "";
			
			if(stripos($endpoint_path,'clientpay/') === false){
				if($this->getSetting("environment", null) == "sandbox"){
					$call_url = HPAY_SANDBOX_URL;
				}else{
					$call_url = HPAY_PRODUCTION_URL;
				}
				$call_url .= ("/clientpay/" . ltrim($endpoint_path,"/"));
			}else{
				$call_url = $endpoint_path;
			}
			
			if(isset($data["request_data"])){
				$this->signRequestData($data["request_data"]);
			}else{
				$this->signRequestData($data);
			}
			
			if(!$blocking){
				$call_url = add_query_arg("no_result_call","1",$call_url);
			}
			
			$response = wp_remote_post( $call_url, array(
					'method'      => 'POST',
					'timeout'     => 29,
					'redirection' => 5,
					'httpversion' => '1.0',
					'blocking'    => $blocking,
					'sslverify'   => false,
					'data_format' => 'body',
					'headers'     => array(
						'Content-Type' => 'application/json'
					),
					'body'        => wp_json_encode($data)
				)
			);
			
			if(!$blocking)
				return true;
			
			if ( is_wp_error( $response ) ) {
				return array(
					"error" => $response->get_error_message(),
					"error_code" => wp_remote_retrieve_response_code($response)
				);
			} else {
				$result = json_decode(wp_remote_retrieve_body( $response ),true);
				return $result;
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
			return array(
				"error" => $ex->getMessage(),
				"error_code" => 500
			);
		}
	} 
	
	public function charge($pay_request, $hpay_method_id, $vault_token_uid){
		global $__charge_call;
		if(!isset($__charge_call))
			$__charge_call = array();
		
		$order = null;
		try{
			
			if(!$pay_request)
				return false;
			
			if(!$hpay_method_id)
				return false;
			
			if(!$vault_token_uid)
				return false;
			
			if(strlen($vault_token_uid) < 10)
				return false;
			
			if(!isset($pay_request["order_uid"]))
				return false;
			
			if(!isset($pay_request["order_amount"]))
				return false;
			
			if(!isset($pay_request["order_currency"]))
				return false;
			
			if(!isset($pay_request["subscription_uid"])){
				$pay_request["subscription_uid"] = "";
			}
			
			$order_id = wc_get_order_id_by_order_key($pay_request["order_uid"]);
			
			if($order_id){
				$order = hpay_get_order( $order_id );
			}
			
			if(!$order){
				return false;
			}
			
			if(hpay_woo_order_type($order) != "shop_order"){
				return false;
			}
			
			if(isset($__charge_call[$order_id])){
				return false;
			}
			
			$__charge_call[$order_id] = true;
			
			if($order->is_paid()){
				$order->add_order_note( __('HPAY skiped charge because order is already paid', 'holestpay'));
				return false;
			}
			
			$hpay_payment_status = HPay_Core::instance()->orderHpayPaymentStatus($order);
			if($hpay_payment_status == "PAID" || $hpay_payment_status == "SUCCESS"){
				$order->add_order_note( __('HPAY skiped charge because order is already paid', 'holestpay'));
				return false;
			}
			
			$pay_request["merchant_site_uid"] = $this->getSetting("merchant_site_uid","");
			if(!$pay_request["merchant_site_uid"]){
				return false;
			}
			
			$hpaymehod = HPay_Core::payment_method_instance($hpay_method_id);
			
			if(!$hpaymehod){
				return false;
			}
			
			if(!$hpaymehod->methodSupports("tokenization")){
				return false;
			}
			
			$return_url = $order->get_checkout_order_received_url();
			
			$pay_request["payment_method"]    = $hpaymehod->hpay_id;
			$pay_request["order_user_url"]    = apply_filters( 'woocommerce_get_return_url', $return_url, $order );
			$pay_request["notify_url"]        = admin_url('admin-ajax.php') . "?action=hpay-webhook&topic=payresult&pos_pm_id=" . $hpaymehod->id;
			$pay_request["merchant_site_uid"] = $this->getSetting("merchant_site_uid","");
			$pay_request["vault_token_uid"]   = $vault_token_uid;
			$pay_request["verificationhash"]  = $this->payRequestSignatureHash("","",$pay_request["order_uid"], $pay_request["order_amount"], $pay_request["order_currency"], $vault_token_uid,$pay_request["subscription_uid"],"");
			
			$call_url = "";
			if($this->getSetting("environment", null) == "sandbox"){
				$call_url = HPAY_SANDBOX_URL;
			}else{
				$call_url = HPAY_PRODUCTION_URL;
			}
			
			$call_url .= "/clientpay/charge";
			
			$response = wp_remote_post( $call_url, array(
					'method'      => 'POST',
					'timeout'     => 29,
					'redirection' => 5,
					'httpversion' => '1.0',
					'blocking'    => true,
					'sslverify'   => false,
					'data_format' => 'body',
					'headers'     => array(
						'Content-Type' => 'application/json'
					),
					'body'        => wp_json_encode(array(
						"request_data" => $pay_request
					))
				)
			);
			
			if ( is_wp_error( $response ) ) {
				$order->add_order_note( __('HPAY charge error', 'holestpay') . "<br/>" . wp_remote_retrieve_response_code($response) . ": " . $response->get_error_message());
				return false;
			} else {
				$result = json_decode(wp_remote_retrieve_body( $response ),true);
				
				if(!$result){
					$order->add_order_note( __('HPAY charge error response', 'holestpay') . ": " . json_encode($response));
				}else if(!isset($result["status"]) || !isset($result["request_time"])){
					$order->add_order_note( __('HPAY charge error response object', 'holestpay') . ": " . json_encode($result));
				}else if(!$this->verifyResponse($result)){
					$order->add_order_note( __('HPAY charge response rejected due incorrect verification string!', 'holestpay'));
					return false;	
				}else{
					/*
					$already_received = false;
					
					if($this->resultAlreadyReceived($result)){
						$already_received = true;
					}
					
					$hpay_responses = HPay_Core::instance()->getHPayPayResponses($order);
					
					$is_duplicate_response = false;
					if(isset($result["transaction_uid"])){
						foreach($hpay_responses as $prev_result){
							if(isset($prev_result["transaction_uid"])){
								if($prev_result["transaction_uid"] == $result["transaction_uid"]){
									$is_duplicate_response = true;
									break;
								}
							}
						}
					}else{
						$is_duplicate_response = false;
						$result["transaction_uid"] = "";
						foreach($hpay_responses as $ind => $prev_resp){
							if(isset($prev_resp["transaction_uid"])){
								if($prev_resp["transaction_uid"]){
									continue;
								}
							}
							unset($hpay_responses[$ind]);
						}
					}
					
					if(!$is_duplicate_response){
						$hpay_responses[] = $result;
						HPay_Core::instance()->setHPayPayResponses($order, $hpay_responses, false);
						$this->acceptResponseFiscalAndShipping($order_id,$result);
						
						if($already_received){
							return $result;
						}
						
						$return_result = true;
						if(strpos($result["status"],"SUCCESS") !== false || strpos($result["status"],"PAID") !== false || strpos($result["status"],"RESERVED") !== false || strpos($result["status"], "AWAITING") !== false){
							if(stripos($order->get_payment_method(),"hpaypayment-") !== false){
								$order->add_order_note( __('HPAY charge completed', 'holestpay') . " " . $result["transaction_uid"] );
								$wc_ostat = $this->shouldSetStatus($result, $order);
								if($wc_ostat){
									if(!$this->wc_order_has_status_immediate($order->get_id(), $wc_ostat))
										$this->setOrderStatus($order,$wc_ostat);	
								}
								if(strpos($result["status"],"SUCCESS") !== false || strpos($result["status"],"PAID") !== false){
									//payment_complete must be called after status set!!!
									$order->payment_complete($result["transaction_uid"]);
								}else if (strpos($result["status"],"RESERVED") !== false || strpos($result["status"], "AWAITING") !== false){
									//
								}
							}else{
								$wc_ostat = $this->shouldSetStatusBecauseOfDelivery($result, $order);
								if($wc_ostat){
									if(!$order->has_status($wc_ostat) && !$this->wc_order_has_status_immediate($order->get_id(), $wc_ostat))
										$this->setOrderStatus($order,$wc_ostat);	
								}
							}
						}else{
							if(stripos($order->get_payment_method(),"hpaypayment-") !== false){
								if(!$this->wc_order_has_status_immediate($order->get_id(), 'failed')){
									$this->setOrderStatus($order,'failed', __( 'HPAY charge failed', 'holestpay' ) . " " . $result["transaction_uid"]);
								}
							}
						}
					}else{
						if(!$order->is_paid() && (strpos($result["status"],"SUCCESS") !== false || strpos($result["status"],"PAID") !== false)){
							if(stripos($order->get_payment_method(),"hpaypayment-") !== false){
								$wc_ostat = $this->shouldSetStatus($result, $order);
								if($wc_ostat){
									if(!$this->wc_order_has_status_immediate($order->get_id(), $wc_ostat))
										$this->setOrderStatus($order,$wc_ostat);	
								}
								//payment_complete must be called after status set!!!
								$order->payment_complete($result["transaction_uid"]);
							}else{
								$wc_ostat = $this->shouldSetStatusBecauseOfDelivery($result, $order);
								if($wc_ostat){
									if(!$order->has_status($wc_ostat) && !$this->wc_order_has_status_immediate($order->get_id(), $wc_ostat))
										$this->setOrderStatus($order,$wc_ostat);	
								}
							}
						}
					}
					$order->save();
					*/
				}
				return $result;
			}
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
			if($order){
				$order->add_order_note( __('Error exception on HPAY charge try', 'holestpay') . "<br/>" . $ex->getMessage());
			}
			return false;
		}
	}
	
	public function destroyVaultToken($vault_token_uid, $blocking = false){
		/*
		if(!$vault_token_uid)
			return false;
		
		if(strlen($vault_token_uid) < 10)
			return false;
		
		if(!$this->getSetting("environment"))
			return false;
		
		if(!$this->getSetting("merchant_site_uid"))
			return false;
		
		$call_url = "";
		if($this->getSetting("environment", null) == "sandbox"){
			$call_url = HPAY_SANDBOX_URL;
		}else{
			$call_url = HPAY_PRODUCTION_URL;
		}
		
		$call_url .= "/clientpay/destroyvault";
		try{
			$response = wp_remote_post( $call_url, array(
					'method'      => 'POST',
					'timeout'     => 29,
					'redirection' => 5,
					'httpversion' => '1.0',
					'blocking'    => $blocking,
					'headers'     => array(),
					'body'        => array(
						"request_data" => array(
							"merchant_site_uid" => $this->getSetting("merchant_site_uid"),
							"vault_token_uid"   => $vault_token_uid,
							"verificationhash"  => $this->payRequestSignatureHash("","","", "", "", $vault_token_uid,"")
						)
					)
				)
			);
			
			if(!$blocking)
				return true;
			
			if ( is_wp_error( $response ) ) {
				throw new Exception(wp_remote_retrieve_response_code($response) . ": " . $response->get_error_message());
			} else {
				$resp = wp_remote_retrieve_body( $response );
				if(!$this->verifyResponse($resp)){
					throw new Exception(__("Destroy vault token response rejected - signature could not be verified!","holestpay"));	
				}
				return resp;
			}
			
		}catch(Throwable $ex){
			throw $ex;
		}
		*/
	}
	
	public function getPOSSetting($name, $default = null, $environment = null){
		$settings = $this->getSettings();
		
		if(!$environment){
			$environment = $this->getSetting("environment", null);
			if(!$environment)
				return $default;
		}
		
		if(isset($settings["{$environment}POS"])){
			if(isset($settings["{$environment}POS"][$name])){
				return $settings["{$environment}POS"][$name];
			}
		}
		
		return $default;
	}
	
	public function getPluginData(){
		if(!isset($this->_plugin_data)){
			$this->_plugin_data = get_plugin_data( HPAY_PLUGIN_FILE );
		}
		return $this->_plugin_data;
	}
	
	public function front(){
		return $this->FRONT;
	}
	
	public function admin(){
		return $this->ADMIN;
	}
	
	public function availableCOFMethods(){
		$result = false;
		$paymentm = $this->getPOSSetting("payment",null);
		if($paymentm){
			$result = array_filter($paymentm,function($pm){ 
												return stripos($pm["SubsciptionsType"],"tokenization") !== false || stripos($pm["SubsciptionsType"],"recurring");
											});
			if(empty($result))
				return false;
		}
		return $result;
	}
	
	public function orderHpayPaymentStatus($order){
		
		if(is_numeric($order)){
			$order = hpay_get_order($order);
		}
		
		if($order){
			$current_hpay_status = $order->get_meta("_hpay_status");
			if($current_hpay_status){
				$pstatus = $this->extractPaymentStatus($current_hpay_status);
				return $pstatus;
			}
		}
		
		return "";
	}
	
	public function extractPaymentStatus($status_string){
		
		if($status_string){
			$status_string = strtoupper(trim("".$status_string));
			if(stripos($status_string,"PAYMENT:") !== false){
				$pstatus = explode("PAYMENT:",strtoupper(trim($status_string)));
				$pstatus = @$pstatus[1];
				$pstatus = explode(" ",$pstatus);
				$pstatus = @$pstatus[0];
				return str_ireplace("SUCCESS","PAID",$pstatus);
			}
			
			if(in_array($status_string, $this->_possible_hpay_pay_statuses)){
				if($status_string == "SUCCESS"){
					$status_string = "PAID";
				}
				return strtoupper(trim($status_string));
			}
		}
		
		return "";
	}
	
	public function acceptResult($order, $result, $pmethod_id = null, $is_webhook = false){
		global $hpay_doing_order_update;
		global $hpay_log_file;
		
		if(!$order)
			return;
		
		$order_id = $order->get_id();
		
		$hpay_log_file = "H" . date("YmdHis") . "_{$order_id}_result_accept_" . rand(10000,99999);
		
		if(!$is_webhook){
			hpay_write_log($hpay_log_file,json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		}
		
		$hpay_doing_order_update = true;
		
		
		if(!$result || is_string($result)){
			$hpay_doing_order_update = false;
			return __('HPAY bad response', 'holestpay') . ": {$result}";
		}else if(!isset($result["status"]) || !isset($result["request_time"])){
			$hpay_doing_order_update = false;
			return __('HPAY bad response object', 'holestpay') . ": " . json_encode($result);
		}
		
		
		if($this->verifyResponse($result)){
			
			$reshash = null;
			if(isset($result["vhash"])){
				if($result["vhash"])
					$reshash = md5($result["vhash"]);
			}
				
			$already_received = false;
			if($this->resultAlreadyReceived($result)){
				$already_received = true;
			}
			
			$has_transaction_uid      = false;
			
			if(!$this->lockHOrderUpdate($result["order_uid"])){
				$error = __('HPAY can not lock the order!', 'holestpay');
				$order->add_order_note( $error  );
				$hpay_doing_order_update = false;
				return $error;
			}
			
			if(isset($result["status"])){
				$order->update_meta_data("_hpay_status_prev",$order->get_meta("_hpay_status"));
				$order->update_meta_data("_hpay_status", $result["status"]);
			}
			
			$hpay_responses = $this->getHPayPayResponses($order);
			
			if(!$is_webhook){
				hpay_write_log($hpay_log_file,"\r\n<!-- VERIFIED -->\r\n");
			}
			
			$is_duplicate_response = false;
			if(isset($result["transaction_uid"])){
				if($result["transaction_uid"]){
					$has_transaction_uid = true;
				}
				
				foreach($hpay_responses as $prev_result){
					if(isset($prev_result["transaction_uid"])){
						if($prev_result["transaction_uid"] == $result["transaction_uid"]){
							$is_duplicate_response = true;
							break;
						}
					}
				}
			}else{
				$is_duplicate_response = false;
				$result["transaction_uid"] = "";
				foreach($hpay_responses as $ind => $prev_resp){
					if(isset($prev_resp["transaction_uid"])){
						if($prev_resp["transaction_uid"]){
							continue;
						}
					}
					unset($hpay_responses[$ind]);
				}
			}
			
			$hmethod = HPay_Core::payment_method_instance($pmethod_id);
		
			$no_tokens = false;
			if($hmethod){
				$no_tokens = $hmethod->tokenisation_disallowed();
			}
			
			if($is_duplicate_response){
				hpay_write_log($hpay_log_file,"<!-- DUPLICATE PREV RESPONSES: " . json_encode($hpay_responses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
			}
			
			$order->set_payment_method($hmethod ? $hmethod : $pmethod_id);
			$order->save();
			
			//hpay_write_log("trace", array($order_id, "acceptResult:acceptResponseFiscalAndShipping",$result));
				
			$has_integ_or_ship_update = $this->acceptResponseFiscalAndShipping($order_id,$result);
			
			if($already_received && !$order->has_status( 'pending' )){
				$this->unlockHOrderUpdate($result["order_uid"]);
				return true;
			}
			
			if(!$is_duplicate_response){
				if(!$is_webhook){
					hpay_write_log($hpay_log_file, "\r\n<!-- ACCEPTED STORAGE -->\r\n", FILE_APPEND);
				}
				
				if($has_integ_or_ship_update || $has_transaction_uid){
					$hpay_responses[] = $result;
					$this->setHPayPayResponses($order, $hpay_responses, false);
				}
				
				$return_result = true;
				if(strpos($result["status"],"SUCCESS") !== false || strpos($result["status"],"PAID") !== false || strpos($result["status"],"RESERVED") !== false || strpos($result["status"], "AWAITING") !== false){
					
					if(!$is_webhook){
						hpay_write_log($hpay_log_file, "\r\n<!-- acceptResult PAID/SUCCESS/RESERVED/AWAITING -->\r\n", FILE_APPEND);
					}
				
					$clear_cart = true; 
					if(!$no_tokens && isset($result["vault_token_uid"])){
						if($result["vault_token_uid"]){
							if(strlen($result["vault_token_uid"]) >= 10){
								$customer_user_id  = $order->get_user_id();
								$merchant_site_uid = $this->getSetting("merchant_site_uid","");
								
								$tlng = "en";
								if(isset($result["hpaylang"])){
									$tlng = $result["hpaylang"];
								}
								if($hmethod){
									WC_Payment_Token_HPay::create_hpay_token($customer_user_id, $merchant_site_uid, $hmethod->hpay_method_type(), $result["vault_card_brand"], $result["vault_card_umask"], $result["vault_token_uid"], $result["vault_scope"], $result["vault_onlyforuser"], $tlng);
								}
							}
						}
					}
					
					$order->add_order_note( __('HPAY payment completed', 'holestpay') . " " . $result["transaction_uid"] );
					
					global $hpay_doing_order_store;
					if(!$hpay_doing_order_store){
						if(stripos($order->get_payment_method(),"hpaypayment-") !== false){
							
							if(!$is_webhook){
								hpay_write_log($hpay_log_file, "\r\n<!-- maybe set status | hpay payment method resp: {$result["status"]}-->\r\n", FILE_APPEND);
							}
							
							$wc_ostat = $this->shouldSetStatus($result, $order);
							if($wc_ostat){
								if(!$order->has_status($wc_ostat) && !$this->wc_order_has_status_immediate($order->get_id(), $wc_ostat)){
									if(!$is_webhook){
										hpay_write_log($hpay_log_file, "\r\n<!-- status set for hpay payment method resp: {$wc_ostat}-->\r\n", FILE_APPEND);
									}
									$this->setOrderStatus($order,$wc_ostat);	
								}
							}
							
							if(strpos($result["status"],"SUCCESS") !== false || strpos($result["status"],"PAID") !== false){
								//payment_complete must be called after status set!!!
								$order->payment_complete($result["transaction_uid"]);
							}else if (strpos($result["status"],"RESERVED") !== false || strpos($result["status"],"AWAITING") !== false){
								//
							}
						}else{
							if(!$is_webhook){
								hpay_write_log($hpay_log_file, "\r\n<!-- maybe set status | non-hpay payment method resp: {$result["status"]}-->\r\n", FILE_APPEND);
							}
							$wc_ostat = $this->shouldSetStatusBecauseOfDelivery($result, $order);
							if($wc_ostat){
								if(!$order->has_status($wc_ostat) && !$this->wc_order_has_status_immediate($order->get_id(), $wc_ostat)){
									if(!$is_webhook){
										hpay_write_log($hpay_log_file, "\r\n<!-- status set for non-hpay payment method resp: {$wc_ostat}-->\r\n", FILE_APPEND);
									}
									$this->setOrderStatus($order,$wc_ostat);	
								}
							}
						}
					}
				}else{
					global $hpay_doing_order_store;
					if(!$hpay_doing_order_store){
						if(stripos($order->get_payment_method(),"hpaypayment-") !== false){
							$wc_ostat = $this->shouldSetStatus($result, $order);
							if(!$order->has_status($wc_ostat) && !$this->wc_order_has_status_immediate($order->get_id(), $wc_ostat)){
								$this->setOrderStatus($order,'failed', __( 'HPAY payment failed', 'holestpay' ) . " " . $result["transaction_uid"]);
							}
						}
					}
				}
			}else{
				global $hpay_doing_order_store;
				if(!$hpay_doing_order_store){
					if(stripos($order->get_payment_method(),"hpaypayment-") !== false){
						$wc_ostat = $this->shouldSetStatus($result, $order);
						if($wc_ostat){
							if(!$order->has_status($wc_ostat) && !$this->wc_order_has_status_immediate($order->get_id(), $wc_ostat))
								$this->setOrderStatus($order,$wc_ostat);	
						}
						if(!$order->is_paid() && (strpos($result["status"],"SUCCESS") !== false || strpos($result["status"],"PAID") !== false)){
							//payment_complete must be called after status set!!!
							$order->payment_complete($result["transaction_uid"]);
						}
					}else{
						$wc_ostat = $this->shouldSetStatusBecauseOfDelivery($result, $order);
						if($wc_ostat){
							if(!$order->has_status($wc_ostat) && !$this->wc_order_has_status_immediate($order->get_id(), $wc_ostat))
								$this->setOrderStatus($order,$wc_ostat);	
						}
					}
				}
			}
			
			$order->save_meta_data();
			
			$this->unlockHOrderUpdate($result["order_uid"]);
			$hpay_doing_order_update = false;
			
			if($reshash){
				$result = array(
					'success'           => true,
					"order_id"          => $order->get_id(),
					'order_site_status' => $this->wc_order_status_immediate($order->get_id())
				);
				try{
					if(function_exists('set_transient'))
						set_transient("hpayresp_" . $reshash, $result, 300);
				}catch(Throwable $tex){
					hpay_write_log("error", $tex);
				}
			}
			return true;
		}else{
			$error = __('HPAY response rejected due incorrect verification string!', 'holestpay') . " REF: " . $result["transaction_uid"];
			$order->add_order_note( $error  );
			$hpay_doing_order_update = false;
			return $error;
		}
	}
	
	public function mergeMethodsOutputs($new_output, $existing_output){
		if(!trim($existing_output))
			return $new_output;
		
		$new_arr      = explode("<!-- METHOD_HTML_START:",$new_output);
		$existing_arr = explode("<!-- METHOD_HTML_START:",$existing_output);
		
		$new_dict      = array();
		$existing_dict = array(); 
		
		foreach($new_arr as $msection){
			if(stripos($msection,'<!-- METHOD_HTML_END') === false){
				continue;
			}
			
			$muid = explode(" -->",substr($msection,0,128));
			$muid = trim($muid[0]);
			$new_dict[$muid] = "<!-- METHOD_HTML_START:" . $msection;
		}
		
		foreach($existing_arr as $msection){
			if(stripos($msection,'<!-- METHOD_HTML_END') === false){
				continue;
			}
			
			$muid = explode(" -->",substr($msection,0,128));
			$muid = trim($muid[0]);
			$existing_dict[$muid] = "<!-- METHOD_HTML_START:" . $msection;
		}
		
		foreach($new_dict as $muid => $msection){
			$existing_dict[$muid] = $msection;
		}
		
		$html = "";
		
		foreach($existing_dict as $muid => $msection){
			$html .= ("\n" . $msection);
		}
		
		return $html;
	}
	
	public function acceptResponseFiscalAndShipping($order_id, & $resp){
		
		if(!$resp)
			return false;
		
		$order = hpay_get_order($order_id, true);
		
		$update_data_exists = false;
		
		$save = false;
		
		if(isset($resp["fiscal_user_info"])){
			//MAY BE SINGLE OR ARRAY!
			
			$update_data_exists = true;
			
			if(!array_is_list($resp["fiscal_user_info"])){
				$resp["fiscal_user_info"] = array($resp["fiscal_user_info"]);
			}
			
			$fiscal_user_info = $order->get_meta("_fiscal_user_info");
			if(empty($fiscal_user_info)){
				$save = true;
				$order->update_meta_data("_fiscal_user_info", $resp["fiscal_user_info"]);
				//hpay_write_log("trace", array($order_id, "SET_fiscal_user_info",$resp["fiscal_user_info"]));
			}else{
				if(!array_is_list($fiscal_user_info)){
					$fiscal_user_info = array($fiscal_user_info);
				}
				$fmethods_existing = array();
				foreach($fiscal_user_info as $index => $fi){
					if(isset($fi["method_uid"])){
						$fmethods_existing[$fi["method_uid"]] = $index;
					}else{
						$fmethods_existing[""] = $index;
					}
				}
				
				foreach($resp["fiscal_user_info"] as $fi){
					$method_uid = "";
					if(isset($fi["method_uid"])){
						$method_uid = $fi["method_uid"];
					}
					if(isset($fmethods_existing[$method_uid])){
						$fiscal_user_info[$fmethods_existing[$method_uid]] = $fi;
					}else{
						$fiscal_user_info[] = $fi;
					}
				}
				$save = true;
				$order->update_meta_data("_fiscal_user_info", $fiscal_user_info);
				//hpay_write_log("trace", array($order_id, "SET_fiscal_user_info",$fiscal_user_info));
			}
		}
		
		if(isset($resp["shipping_user_info"])){
			//MAY BE SINGLE OR ARRAY!
			$update_data_exists = true;
			
			
			if(!array_is_list($resp["shipping_user_info"])){
				$resp["shipping_user_info"] = array($resp["shipping_user_info"]);
			}
			
			$shipping_user_info = $order->get_meta("_shipping_user_info");
			if(empty($shipping_user_info)){
				$save = true;
				$order->update_meta_data("_shipping_user_info", $resp["shipping_user_info"]);
			}else{
				
				if(!array_is_list($shipping_user_info)){
					$shipping_user_info = array($shipping_user_info);
				}
				
				$smethods_existing = array();
				foreach($shipping_user_info as $index => $fi){
					if(isset($fi["method_uid"])){
						$smethods_existing[$fi["method_uid"]] = $index;
					}else{
						$smethods_existing[""] = $index;
					}
				}
				
				foreach($resp["shipping_user_info"] as $fi){
					$method_uid = "";
					if(isset($fi["method_uid"])){
						$method_uid = $fi["method_uid"];
					}
					if(isset($smethods_existing[$method_uid])){
						$shipping_user_info[$smethods_existing[$method_uid]] = $fi;
					}else{
						$shipping_user_info[] = $fi;
					}
				}
				$save = true;
				$order->update_meta_data("_shipping_user_info", $shipping_user_info);
			}
		}
		
		$existing_fhtml = $order->get_meta("_fiscal_html");
		$existing_shtml = $order->get_meta("_shipping_html");
		
		if(!$existing_fhtml){
			$existing_fhtml = "";
		}
		
		if(!$existing_shtml){
			$existing_shtml = "";
		}
		
		if(isset($resp["fiscal_user_info"])){
			unset($resp["fiscal_user_info"]);
		}
		
		if(isset($resp["shipping_user_info"])){
			unset($resp["shipping_user_info"]);
		}
		
		
		if(isset($resp["fiscal_html"])){
			//hpay_write_log("trace", array($order_id, "store_fiscal_user_info",$resp["fiscal_html"]));
			$save = true;
			$order->update_meta_data("_fiscal_html", $this->mergeMethodsOutputs($resp["fiscal_html"],$existing_fhtml));
			unset($resp["fiscal_html"]);
		}
		
		if(isset($resp["shipping_html"])){
			$save = true;
			$order->update_meta_data("_shipping_html", $this->mergeMethodsOutputs($resp["shipping_html"],$existing_shtml));
			unset($resp["shipping_html"]);
		}
		
		if($save)
			$order->save_meta_data();
		
		return $save;
	}
	
	public function lockHOrderUpdate($order_uid){
		try{
			global $wpdb;
			
			$ts = time();
			
			$locked = true;
			try{
				ob_start();
				$prev_insert_id = $wpdb->insert_id;
				@$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_id, option_name, option_value, autoload) VALUES (NULL, %s, %d, %s)",
					"_hpayorderlock_{$order_uid}", $ts,"no"));
				
				$locked = $wpdb->insert_id && ($wpdb->insert_id != $prev_insert_id);
			}catch(Throwable $ex){
				$locked = false;
			}
			ob_end_clean();
			
			if($locked){
				return true;
			}
			
			$lts = get_option("_hpayorderlock_{$order_uid}", 0);
			
			if($lts !== 0 && $lts + 16 < $ts){
				return true;
			}
				
			for($i = 0; $i < 16; $i++){
				sleep(1);
				$ts = time();
				
				$locked = true;
				try{
					ob_start();
					
					$prev_insert_id = $wpdb->insert_id;
					@$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_id, option_name, option_value, autoload) VALUES (NULL, %s, %d, %s)",
						"_hpayorderlock_{$order_uid}", $ts,"no"));
					
					$locked = $wpdb->insert_id && ($wpdb->insert_id != $prev_insert_id);
				}catch(Throwable $ex){
					$locked = false;
				}
				ob_end_clean();
				
				if($locked){
					return true;
				}else{
					$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}options WHERE option_name = %s AND option_value < %d","_hpayorderlock_{$order_uid}", $ts - 30));//30s
				}
			}
			return false;
		}catch(Throwable $ex){
			hpay_write_log("error", $ex);
			return false;
		}
	}
	
	public function unlockHOrderUpdate($order_uid){
		try{
			global $wpdb;
			
			return $wpdb->delete(
				$wpdb->prefix.'options', 
				array(
					'option_name' => "_hpayorderlock_{$order_uid}"
				),
				array(
					'%s'
				)
			);
		}catch(Throwable $ex){
			hpay_write_log("error", $ex);
		}
	}
	
	public function resultAlreadyReceived($resp){
		try{
			global $wpdb;
			
			if(!$resp){
				return null;
			}
			
			if(!isset($resp["vhash"])){
				return null;
			}
			
			if(!$resp["vhash"]){
				return null;
			}
			
			$ts = time();
			$mdhash = md5($resp["vhash"]);
			$res = true;
			try{
				ob_start();
				
				$prev_insert_id = $wpdb->insert_id;
				
				@$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_id, option_name, option_value, autoload) VALUES (NULL, %s, %d, %s)",
					"_hpayresultrec_{$mdhash}", $ts,"no"));
				
				$inserted = $wpdb->insert_id && ($wpdb->insert_id != $prev_insert_id);
				$res = !$inserted;
				
			}catch(Throwable $ex){
				//
			}
			ob_end_clean();
			return $res;
		}catch(Throwable $ex){
			hpay_write_log("error", $ex);
			return null;
		}
	}
	
	public function maintainCleanLocks(){
		try{
			global $wpdb;
			$ts = time();
			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}options WHERE (option_name LIKE '%hpayorderlock%' OR option_name LIKE '%hpayresultrec%') AND option_value < %d", $ts - 900));//15min
		}catch(Throwable $ex){
			hpay_write_log("error", $ex);
		}
	}
	
	public function onOrderUpdate($resp, $order = null){
		global $hpay_doing_order_update;
		
		if(isset($hpay_doing_order_update) && $hpay_doing_order_update)
			return null;
		
		$hpay_doing_order_update = true;
		
		try{
		
			if(!$resp){
				$hpay_doing_order_update = false;
				return array(
					'success' => false,
					'message' => __('EMPTY_OUTCOME_DATA','holestpay')
				);
			}
			
			if(isset($resp["result"])){
				if(is_string($resp["result"])){
					$r = $resp["result"];
					foreach($resp["result"] as $prop => $val){
						if($val && !isset($resp[$prop]))
							$resp[$prop] = $val;
					}	
					unset($resp["result"]);
				}
			}
			
			if(!isset($resp["status"]) || !isset($resp["order_uid"])){
				$hpay_doing_order_update = false;
				return array(
					'success' => false,
					'message' => __('BAD_ORDER_DATA','holestpay')
				);
			}
			
			if($this->verifyResponse($resp)){
				$reshash = null;
				if(isset($resp["vhash"])){
					if($resp["vhash"])
						$reshash = md5($resp["vhash"]);
				}
				
				$order_id = null;
				if($order){
					$order_id = $order->get_id();
				}
				
				if(!$order_id)
					$order_id = wc_get_order_id_by_order_key($resp["order_uid"]);
				
				if(!$order_id){
					//OVDE PREDVIDETI KREIRANJE
					$hpay_doing_order_update = false;
					return array(
						'success' => false,
						'message' => __('ORDER_ID_NOT_FOUND','holestpay')
					);
				}else{
					
					if(!$this->lockHOrderUpdate($resp["order_uid"])){
						if(!$order)
							$order = hpay_get_order($order_id);		
						
						if($order){
							return array(
								'success' => false,
								'message' => __('CANNOT_GET_ORDER_LOCK','holestpay'),
								"order_id"          => $order->get_id(),
								"order_site_status" => $this->wc_order_status_immediate($order->get_id())
							);
						}else{
							return array(
								'success' => false,
								'message' => __('CANNOT_GET_ORDER_LOCK','holestpay')
							);
						}
					}
					
					if(!$order)
						$order = hpay_get_order($order_id);
					
					$already_received = false;
					if($this->resultAlreadyReceived($resp)){
						$already_received = true;
					}
					
					if(!$order){
						$hpay_doing_order_update = false;
						$this->unlockHOrderUpdate($resp["order_uid"]);
						return array(
							'success' => false,
							'message' => __('ORDER_NOT_FOUND','holestpay')
						);
					}
					
					$hpay_responses          = $this->getHPayPayResponses($order);
					$hpay_responses_dirty = false;
					
					$hpay_order = $resp["order"];
					$hpay_operation = "";
					
					$restock = null;
					if(isset($resp["restock"])){
						if($resp["restock"]){
							$restock = true;
						}
					}

					if(isset($resp["hpay_operation"])){
						$hpay_operation = $resp["hpay_operation"];
					}	
						
					if(isset($resp["order"])){	
						unset($resp["order"]);
					}
					
					if(isset($resp["result"])){
						if(is_string($resp["result"])){
							unset($resp["result"]);
						}
					}
					
					$transaction = null; 
					$hpay_responses_tranuids = array();
					
					$is_duplicate_response    = false;
					$has_transaction_uid      = false;
					
					$has_integ_or_ship_update = $this->acceptResponseFiscalAndShipping($order_id,$resp); 
					
					$return_after_tran_sync = false;
					
					if($already_received && !$order->has_status( 'pending' )){
						if($reshash){
							try{
								$result_existing = get_transient("hpayresp_" . $reshash);
								if(!$result_existing){
									sleep(1);
									$result_existing = get_transient("hpayresp_" . $reshash);
								}
								if($result_existing){
									$result_existing["processed_already"] = true;
									$this->unlockHOrderUpdate($resp["order_uid"]);
									return $result_existing;
								}
							}catch(Throwable $tex){
								hpay_write_log("error", $tex);
							}
						}
						$return_after_tran_sync = true;
					}
					
					if(isset($resp)){
						if(isset($resp["transaction_uid"])){
							
							if($resp["transaction_uid"]){
								$has_transaction_uid = true;
							}
							
							$is_set = false;
							foreach($hpay_responses as $index => $prev_result){
								if(isset($prev_result["transaction_uid"])){
									if($prev_result["transaction_uid"] == $resp["transaction_uid"]){
										$hpay_responses[$index] = $resp;
										$is_set = true;
										$hpay_responses_dirty = true;
										$is_duplicate_response = true;
										break;
									}
								}
							}
							
							if(!$is_set){
								$hpay_responses[] = $resp;
								$hpay_responses_dirty = true;
							}
						}else{
							if($has_integ_or_ship_update){
								$resp["transaction_uid"] = "";
								foreach($hpay_responses as $ind => $prev_resp){
									if(isset($prev_resp["transaction_uid"])){
										if($prev_resp["transaction_uid"]){
											continue;
										}
									}
									unset($hpay_responses[$ind]);
								}
								$hpay_responses[] = $resp;	
								$hpay_responses_dirty = true;
							}
						}
					}
					
					foreach($hpay_responses as $index => $prev_result){
						if(isset($prev_result["transaction_uid"])){
							$hpay_responses_tranuids[] = $prev_result["transaction_uid"];
						}			
					}
					
					if(isset($hpay_order["Transactions"])){
						usort($hpay_order["Transactions"], function($a, $b){
							return intval($a["id"]) - intval($b["id"]);
						});
						
						foreach($hpay_order["Transactions"] as $trans){
							if($trans){
								if(isset($trans["Data"])){
									if(is_string($trans["Data"])){
										$trans["Data"] = json_decode($trans["Data"], true);
									}
									
									if(!in_array($trans["Uid"],$hpay_responses_tranuids)){
										if(isset($trans["Data"]["result"])){
											$hpay_responses[] = $trans["Data"]["result"];
											$hpay_responses_dirty = true;
										}
									}
									
									if(!$transaction){
										$transaction = $trans;
									}else if($transaction["id"] < $trans["id"]){
										$transaction = $trans;
									}
								}
							}
						}
					}
					
					if($hpay_responses_dirty){
						$this->setHPayPayResponses($order, $hpay_responses, false);
					}
					
					
					
					if($return_after_tran_sync){
						if($hpay_responses_dirty){
							$order->save_meta_data();
						}
						$this->unlockHOrderUpdate($resp["order_uid"]);
						return array(
							'success' => "",
							'message' => __('RESULT_ALREADY_ACCEPTED','holestpay'),
							"order_id"          => $order->get_id(),
							"order_site_status" => $this->wc_order_status_immediate($order->get_id())
						);	
					}
					
					$order->update_meta_data("_hpay_status_prev",$order->get_meta("_hpay_status"));
					$order->update_meta_data("_hpay_status",$resp["status"]);
					
					if(strpos($resp["status"],"PAYMENT:PAID") !== false || strpos($resp["status"],"PAYMENT:SUCCESS") !== false){
						if(stripos($order->get_payment_method(),"hpaypayment-") !== false){
							$wc_ostat = $this->shouldSetStatus($resp, $order);
							$do_set_status = null;
							if($wc_ostat){
								if ( !$order->has_status($wc_ostat) && !$this->wc_order_has_status_immediate($order->get_id(), $wc_ostat) ) {
									$do_set_status = $wc_ostat;
								}
							}
							
							if($hpay_operation == "capture"){
								try{
									if($hpay_order){
										if(isset($hpay_order["Data"])){
											if(isset($hpay_order["Data"]["items"])){
												
												$current_items = $this->getOrderItems($order, null, true);
												$items_matches = $this->matchOrderItems($current_items, $hpay_order["Data"]["items"]);
												
												$refund_items = array();
												$rsum         = 0; 				
												foreach($items_matches as $match){
													if($match[0] && $match[1]){
														
														$rqty = 0;
														$ramt = 0;
														$rtax = 0;
														
														if(!isset($match[1]["captured"])){
															continue;
														}
														
														if(@$match[0]["qty"] != @$match[1]["captured_qty"]){
															$rqty = @$match[0]["qty"] - @$match[1]["captured_qty"];
															if($rqty < 0){
																$rqty = 0;
															}
														}
														
														if(@$match[0]["subtotal"] > @$match[1]["captured"]){
															$ramt = @$match[1]["subtotal"] - @$match[1]["captured"];
															
															if(abs($ramt) < 0.3){
																$ramt = 0;
															}
															
															$rsum += $ramt;
															
															if(@$match[0]["tax_amount"]){
																$trat = @$match[0]["tax_amount"] / @$match[0]["subtotal"];
																if($trat > 0){
																	$rtax = $ramt * $trat;
																	$ramt -= $rtax;
																}
															}
														}
														
														if($rqty > 0 || $ramt > 0){
															$refund_items[$match[0]["posoitemuid"]] = array(
																"qty"          => $rqty,
																"refund_total" => $ramt,
																"refund_tax"   => $rtax
																//,"restock"   => true|false umesto generalnog restock_items je ok
															);
														}
													}	
												}
												
												if(!empty($refund_items)){
													$refund_args = array(
														"amount"   => $rsum,
														"order_id" => $order->get_id(),
														"reason"   => __("Partial reserved amount capture/post-authorization","holestpay")
													);
															
													$refund_args["line_items"] = $refund_items;
													if($restock){
														$refund_args["restock_items"] = true;
													}
													
													try{
														remove_all_actions('woocommerce_order_partially_refunded');
														remove_all_actions('woocommerce_refund_created');
														remove_all_actions('woocommerce_order_refunded');
														$refund = wc_create_refund($refund_args);
													}catch(Throwable $trex){
														hpay_write_log("error",$trex);
													}
												}
											}
										}	
									}
								}catch(Throwable $crex){
									hpay_write_log("error",$crex);
								}
							}
							
							if($do_set_status){
								$this->setOrderStatus($order,$do_set_status);
							}
							
							if(!$order->is_paid()){
								//payment_complete must be called after status set!!!
								$order->payment_complete($transaction["Uid"]);
							}
						}else{
							$wc_ostat = $this->shouldSetStatusBecauseOfDelivery($result, $order);
							if($wc_ostat){
								if(!$order->has_status($wc_ostat) && !$this->wc_order_has_status_immediate($order->get_id(), $wc_ostat))
									$this->setOrderStatus($order,$wc_ostat);	
							}
						}	
					}else if(strpos($resp["status"],"PAYMENT:PARTIALLY-REFUNDED") !== false){
						if(stripos($order->get_payment_method(),"hpaypayment-") !== false){
							$wc_ostat = $this->shouldSetStatus($resp, $order);
							
							if(isset($resp["refunded_amount"]) && isset($resp["payment_amount"]) && isset($resp["order_amount"])){
								try{
									$r_amt = 0;
									if(isset($resp["refunded_order_amount"])){
										$r_amt = floatval($resp["refunded_order_amount"]);
									}
									
									if(!$r_amt){
										if($hpay_order){
											if(isset($hpay_order["Data"])){
												if(isset($hpay_order["Data"]["exchange_rates"])){
													foreach($hpay_order["Data"]["exchange_rates"] as $pair => $rate_data){
														if(isset($rate_data["rate"])){
															$r_amt = floatval($resp["refunded_amount"]) / floatval($rate_data["rate"]);
														}
													}
												}
											}
										}	
										if(!$r_amt){
											$r_amt = floatval($resp["refunded_amount"]);
										}
									}
									
									if($r_amt){
										$r_amt = round($r_amt, 2);
									}
									
									global $hpay_site_refund_ongoing;
									
									if(!$hpay_site_refund_ongoing && isset($resp["transaction_uid"]) && !$is_duplicate_response){
										try{
											$refunds = $order->get_meta("_hpay_refunds");
											if(!$refunds){
												$refunds = array();
											}
											if(!isset($refunds[$resp["transaction_uid"]])){	
												
												$refund_args = array(
														"amount"   => $r_amt,
														"order_id" => $order->get_id(),
														"reason"   => __("Partial refund","holestpay")
												);
												
												try{
													
													if($hpay_order){
														if(isset($hpay_order["Data"])){
															if(isset($hpay_order["Data"]["items"])){
																
																$current_items = $this->getOrderItems($order, null, true);
																$items_matches = $this->matchOrderItems($current_items, $hpay_order["Data"]["items"]);
																
																$refund_items = array();
																
																foreach($items_matches as $match){
																	if($match[0] && $match[1]){
																		$rqty = 0;
																		$ramt = 0;
																		$rtax = 0;
																		
																		if(@$match[0]["qty"] != @$match[1]["qty"]){
																			$rqty = @$match[0]["qty"] - @$match[1]["qty"];
																			if($rqty < 0){
																				$rqty = 0;
																			}
																		}
																		
																		if(@$match[0]["refunded"] != @$match[1]["refunded"]){
																			$ramt = @$match[1]["refunded"] - @$match[0]["refunded"];
																			
																			if(@$match[0]["tax_amount"]){
																				$trat = @$match[0]["tax_amount"] / @$match[0]["subtotal"];
																				if($trat > 0){
																					$rtax = $ramt * $trat;
																					$ramt -= $rtax;
																				}
																			}
																		}
																		
																		if($rqty > 0 || $ramt > 0){
																			$refund_items[$match[0]["posoitemuid"]] = array(
																				"qty"          => $rqty,
																				"refund_total" => $ramt,
																				"refund_tax"   => $rtax
																				//,"restock"   => true|false umesto generalnog restock_items je ok
																			);
																		}
																	}	
																}
																
																if(!empty($refund_items)){
																	$refund_args["line_items"] = $refund_items;
																	if($restock){
																		$refund_args["restock_items"] = true;
																	}
																}
															}
														}	
													}
												}catch(Throwable $rrex){
													hpay_write_log("error",$rrex);
												}
												
												$refund = null;
												try{
													global $hpay_partial_refunded_orders;
													if(!isset($hpay_partial_refunded_orders))
														$hpay_partial_refunded_orders = array();
													$hpay_partial_refunded_orders[$order->get_id()] = true;
													
													// remove_all_actions('woocommerce_order_partially_refunded');
													// remove_all_actions('woocommerce_refund_created');
													// remove_all_actions('woocommerce_order_refunded');
													
													$refund = wc_create_refund($refund_args);
												}catch(Throwable $trex){
													if(isset($refund_args["line_items"])){
														unset($refund_args["line_items"]);
														if(isset($refund_args["restock_items"]))
															unset($refund_args["restock_items"]);
														
														$refund = wc_create_refund($refund_args);
													}else{
														throw $trex;
													}
												}
														
												if($refund){
													if(!is_wp_error($refund)){
														$refunds[$resp["transaction_uid"]] = $refund->get_id();
														$order->update_meta_data("_hpay_refunds",$refunds);
													}else{
														hpay_write_log("error","WP_Error on wc_create_refund");
														hpay_write_log("error",$refund->get_error_message());
													}
												}
											}
										}catch(Throwable $rex){
											hpay_write_log("error",$rex);
										}
									}
								}catch(Throwable $zdivex){
									hpay_write_log("error", $zdivex);
								}
							}
							
							if($wc_ostat){
								if ( !$order->has_status($wc_ostat) && !$this->wc_order_has_status_immediate($order->get_id(), $wc_ostat)) {
									$this->setOrderStatus($order,$wc_ostat);	
								}
							}
						}else{
							$wc_ostat = $this->shouldSetStatusBecauseOfDelivery($result, $order);
							if($wc_ostat){
								if(!$order->has_status($wc_ostat) && !$this->wc_order_has_status_immediate($order->get_id(), $wc_ostat))
									$this->setOrderStatus($order,$wc_ostat);	
							}
						}
					}else if(strpos($resp["status"],"PAYMENT:VOID") !== false || strpos($resp["status"],"PAYMENT:REFUND") !== false){
						
						if(stripos($order->get_payment_method(),"hpaypayment-") !== false){
							
							if(strpos($resp["status"],"PAYMENT:REFUND") !== false){
								
								global $hpay_site_refund_ongoing;
								if(!$hpay_site_refund_ongoing && isset($resp["transaction_uid"]) && !$is_duplicate_response){
									try{
										$refunds = $order->get_meta("_hpay_refunds");
										if(!$refunds){
											$refunds = array();
										}
										if(!isset($refunds[$resp["transaction_uid"]])){	
											$refund_args = array(
													"amount"   => $order->get_remaining_refund_amount(),
													"order_id" => $order->get_id(),
													"reason"   => __("Full refund","holestpay")
											);
											
											try{
												if($hpay_order){
													if(isset($hpay_order["Data"])){
														if(isset($hpay_order["Data"]["items"])){
															
															$current_items = $this->getOrderItems($order, null, true);
															$refund_items = array();
															
															foreach($current_items as $oitem_id => $item){
																$refund_items[$oitem_id] = array(
																	"qty"          => $item["qty"],
																	"refund_total" => $item["subtotal"] - $item["tax_amount"],
																	"refund_tax"   => $item["tax_amount"]
																	//,"restock"   => true|false umesto generalnog restock_items je ok
																);
															}
															
															if(!empty($refund_items)){
																$refund_args["line_items"] = $refund_items;
																if($restock){
																	$refund_args["restock_items"] = true;
																}
															}
														}
													}	
												}
											}catch(Throwable $rrex){
												hpay_write_log("error",$rrex);
											}
											
											$refund = null;
											try{
												// remove_all_actions('woocommerce_order_partially_refunded');
												// remove_all_actions('woocommerce_refund_created');
												// remove_all_actions('woocommerce_order_refunded');
														
												$refund = wc_create_refund($refund_args);
											}catch(Throwable $trex){
												if(isset($refund_args["line_items"])){
													unset($refund_args["line_items"]);
													if(isset($refund_args["restock_items"]))
														unset($refund_args["restock_items"]);
													$refund = wc_create_refund($refund_args);
												}else{
													throw $trex;
												}
											}
											
											if($refund){
												if(!is_wp_error($refund)){
													$refunds[$resp["transaction_uid"]] = $refund->get_id();
													$order->update_meta_data("_hpay_refunds",$refunds);
												}else{
													hpay_write_log("error","WP_Error on wc_create_refund");
													hpay_write_log("error",$refund->get_error_message());
												}
											}
										}
									}catch(Throwable $rex){
										hpay_write_log("error", $rex);
									}
								}
							}
							
							$wc_ostat = $this->shouldSetStatus($resp, $order);
							if($wc_ostat){
								if ( !$order->has_status($wc_ostat) && !$this->wc_order_has_status_immediate($order->get_id(), $wc_ostat) ) {
									$this->setOrderStatus($order,$wc_ostat);	
								}
							}
						}
					}else if(strpos($resp["status"],"PAYMENT:RESERVED") !== false || strpos($resp["status"],"PAYMENT:AWAITING") !== false){
						
						if(stripos($order->get_payment_method(),"hpaypayment-") !== false){
							$wc_ostat = $this->shouldSetStatus($resp, $order);
							if($wc_ostat){
								if ( !$order->has_status($wc_ostat) && !$this->wc_order_has_status_immediate($order->get_id(), $wc_ostat)) {
									$this->setOrderStatus($order,$wc_ostat);	
								}
							}
						}else{
							$wc_ostat = $this->shouldSetStatusBecauseOfDelivery($result, $order);
							if($wc_ostat){
								if(!$order->has_status($wc_ostat) && !$this->wc_order_has_status_immediate($order->get_id(), $wc_ostat))
									$this->setOrderStatus($order,$wc_ostat);	
							}
						}
					}
					
					$order->save_meta_data();
					
					$this->unlockHOrderUpdate($resp["order_uid"]);
				}
			}else{
				$hpay_doing_order_update = false;
				return array(
						'success' => false,
						'message' => __('UNVERIFIED_RESULT','holestpay'),
						"order_id"          => $order->get_id(),
						"order_site_status" => $this->wc_order_status_immediate($order->get_id())
					);
			}
			
			$hpay_doing_order_update = false;	
			$result = array(
				'success'           => true,
				"order_id"          => $order->get_id(),
				'order_site_status' => $this->wc_order_status_immediate($order->get_id())
			);
			
			if($reshash){
				try{
					if(function_exists('set_transient'))
						set_transient("hpayresp_" . $reshash, $result, 300);
				}catch(Throwable $tex){
					hpay_write_log("error", $tex);
				}
			}
			
			return $result;
		}catch(Throwable $ex){
			hpay_write_log("error", $ex);
			$hpay_doing_order_update = false;
			$data = array(
				'success'   => false,
				'message'   => __('ERROR_EXCEPTION','holestpay'),
				'exception' => $ex->getMessage()
			);
			
			if($order){
				$data["order_id"]          = $order->get_id();
				$data["order_site_status"] = $this->wc_order_status_immediate($order->get_id());
			}
			
			return $data;
		}
	}
	
	public function webhookHandler(){
		try{
			
			
			$data = json_decode( file_get_contents('php://input'), true);
			if($data){
				
				global $hpay_log_file;
				
				if($this->getSetting("enable_log","") == 1){
					$topic = "unknown";
					
					if(isset($_GET["topic"])){
						if($_GET["topic"]){
							$topic = $_GET["topic"];
						}
					}
					
					if(!$hpay_log_file){
						if(!file_exists(WP_CONTENT_DIR . "/uploads/hpay-logs")){
							@mkdir(WP_CONTENT_DIR . "/uploads/hpay-logs",0775,true);
						}
						if(isset($data["order_uid"])){
							$order_id = wc_get_order_id_by_order_key($data["order_uid"]);
							$hpay_log_file = "H" . date("YmdHis") . "_webhook_{$order_id}_" . $topic . "_" . rand(10000,99999) . ".log";
						}else{
							$hpay_log_file = "H" . date("YmdHis") . "_webhook_" . $topic . "_" . rand(10000,99999) . ".log";
						}
					}
					hpay_write_log($hpay_log_file, "<!-- " . $_SERVER["REQUEST_URI"] . " -->\r\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),FILE_APPEND);
				}
				
				if(isset($_GET["topic"])){
					if($_GET["topic"] == "payresult"){
						if(isset($data["order_uid"])){
							
							$pmethod_id = null;
							$hmethod    = null;
							
							if(isset($_GET["pos_pm_id"])){
								$pmethod_id = $_GET["pos_pm_id"];
								$hmethod    = HPay_Core::payment_method_instance($pmethod_id);
							}
							
							if(!$hmethod){
								if(isset($data["payment_method"])){
									if($data["payment_method"] != "0"){
										$hmethod    = HPay_Core::payment_method_instance(intval($data["payment_method"]));
										if($hmethod){
											$pmethod_id = $hmethod->id;
										}
									}
								}
							}
							
							$order_id = wc_get_order_id_by_order_key($data["order_uid"]);
							if($order_id){
								$order = hpay_get_order( $order_id );
								$res = null;
								if($hpay_log_file){
									hpay_write_log($hpay_log_file, "\r\n\r\nSITE RESULT:\r\n" . json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),FILE_APPEND);
								}
								
								if($order)
									$res = $this->acceptResult($order, $data, $pmethod_id, true);
								
								if($res === true){
									$return_url = $order->get_checkout_order_received_url();
									wp_send_json(array("received" => "OK", "accept_result" => "ACCEPTED", "order_user_url" => apply_filters( 'woocommerce_get_return_url', $return_url, $order )),200);
								}else{
									wp_send_json(array("rejected" => $res, "error" => "REJECTED", "error_code" => 406, "order_user_url" => apply_filters( 'woocommerce_get_return_url', $return_url, $order )),406);
								}
								die;
							}
							
							wp_send_json(array("received" => "NO", "accept_result" => "NOT RECOGNISED", "error_code" => 404, "rdiff" => rand(100000,999999)), 404);
							die;
						}
					}else if($_GET["topic"] == "orderupdate"){
						if(isset($data["order_uid"])){
							if($hpay_log_file){
								hpay_write_log($hpay_log_file, "\r\n\r\nSITE RESULT:\r\n" . json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),FILE_APPEND);
							}
							
							$res = $this->onOrderUpdate($data);
							
							if($res){
								if($res["success"]){
									wp_send_json(array("received" => "OK", "accept_result" => "ACCEPTED", "info" => $res),200);
								}else{
									wp_send_json(array("rejected" => $res, "error" => "REJECTED", "error_code" => 406),406);
								}
							}else{
								wp_send_json(array("rejected" => "", "error" => "BUSY", "error_code" => 503),503);
							}
							die;
						}
					}else if($_GET["topic"] == "posconfig-updated"){
						if(isset($data["environment"]) && isset($data["merchant_site_uid"]) && isset($data["POS"]) && isset($data["checkstr"])){
							$settings = $this->getSettings();
							if($data["environment"] == @$settings["environment"] && isset($settings[$data["environment"]])){
								if($data["merchant_site_uid"] == @$settings[$data["environment"]]["merchant_site_uid"]){
									if($data["checkstr"] == md5(@$settings[$data["environment"]]["merchant_site_uid"] . @$settings[$data["environment"]]["secret_token"])){
										$settings[$data["environment"] . "POS"] = $data["POS"];
										$this->updateSettings($settings);
										wp_send_json(array("received" => "OK", "accept_result" => "POS_CONFIG_UPDATED"),200);
										die;
									}	
								}
							}
						}
						wp_send_json(array("rejected" => 1, "error" => "REJECTED", "error_code" => 406),406);
						die;
					}else if($_GET["topic"] == "pos-error-logs"){
						header("Access-Control-Allow-Origin:*");
						if(isset($data["environment"]) && isset($data["merchant_site_uid"]) && isset($data["checkstr"])){
							$settings = $this->getSettings();
							if($data["environment"] == @$settings["environment"] && isset($settings[$data["environment"]])){
								if($data["merchant_site_uid"] == @$settings[$data["environment"]]["merchant_site_uid"]){
									if($data["checkstr"] == md5(@$settings[$data["environment"]]["merchant_site_uid"] . @$settings[$data["environment"]]["secret_token"])){
										if(function_exists('hpay_get_error_logs'))
											wp_send_json(array("received" => "OK", "logs" => hpay_get_error_logs()),200);
										die;
									}	
								}
							}
						}
						wp_send_json(array("rejected" => 1, "error" => "REJECTED", "error_code" => 406),406);
						die;
					}else{
						wp_send_json(array("received" => date("Y-m-d H:i:s"), "error" => "NOT_HANDLED" , "ts" => time() , "topic" => $_GET["topic"]),200);
						die;
					}
				}
			}
			wp_send_json(array("error" => "BAD DATA" , "error_code" => 406), 406);
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
			wp_send_json(array("error" => "ERROR" , "error_code" => 500), 500);
		}
	}
	
	public static function parsePriceToCartCurrency($str){
		$curr = null;
		$price = floatval($str);
		preg_match('/[a-zA-Z]{3}/', $str, $curr);
		if(!empty($curr)){
			$curr = strtoupper(trim($curr[0]));
		}
		if($curr){
			if(get_woocommerce_currency() != $curr){
				$rate = HPay_Core::getMerchantExchnageRate($curr, get_woocommerce_currency());
				return $price * $rate;
			}
		}
		return $price;
	}
	
	public function footer_branding(){
		$footer_template = $this->getSetting("footer_template","");
		
		if($footer_template){
			$source_dir = __DIR__ ."/assets/footer_branding";
			$source_url = HPAY_PLUGIN_URL . "/assets/footer_branding";
			
			if(get_option("hpay_footer_template","-") != $footer_template){
				if(!file_exists(WP_CONTENT_DIR . "/uploads/hpay-assets/footer_branding")){
					@mkdir(WP_CONTENT_DIR . "/uploads/hpay-assets/footer_branding",0775,true);
				}
				if(file_exists(WP_CONTENT_DIR . "/uploads/hpay-assets/footer_branding")){
					try{
						require_once(__DIR__ . '/../../../wp-admin/includes/file.php');
						WP_Filesystem();
						if(true === copy_dir(__DIR__ ."/assets/footer_branding/{$footer_template}", WP_CONTENT_DIR . "/uploads/hpay-assets/footer_branding/{$footer_template}")){
							$source_dir = WP_CONTENT_DIR . "/uploads/hpay-assets/footer_branding";
							$source_url = content_url("/uploads/hpay-assets/footer_branding");
						}	
					}catch(Throwable $ex){}
				}
				update_option("hpay_footer_template",$footer_template, true);
			}else{
				if(file_exists(WP_CONTENT_DIR . "/uploads/hpay-assets/footer_branding/{$footer_template}")){
					$source_dir = WP_CONTENT_DIR . "/uploads/hpay-assets/footer_branding";
					$source_url = content_url("/uploads/hpay-assets/footer_branding");
				}
			}
			
			if(file_exists("{$source_dir}/{$footer_template}")){
				
				?>
				<div class="hpay_footer_branding" style='display:flex;justify-content:center;padding:4px 0;'>
					<div class="hpay-footer-branding-cards" style='display:flex'>
						<?php 
						echo implode(" ",array_map(function($img_path) use($footer_template, $source_url ){
							$imgname = basename( $img_path );
							if(stripos($imgname,"_") === 0)
								return "";
							$img_url = "{$source_url}/{$footer_template}/cards/" . $imgname; 
							return "<img style='height:30px;' src='{$img_url}' alt='{$imgname}' />";
						}, glob("{$source_dir}/{$footer_template}/cards/*.{jpg,png,gif,jgeg,svg}" ,GLOB_BRACE)));
						?>
					</div>
					<div style='padding: 0 25px;'>&nbsp;</div>
					<div class="hpay-footer-branding-bank" style='display:flex'>
						<?php
							$banks = glob("{$source_dir}/{$footer_template}/bank/*");
							foreach($banks as $bank_path){
								if(is_dir($bank_path)){
									$bank = basename($bank_path);
									
									if(stripos($bank,"_") === 0)
										continue;
								
									$bimgs = glob("{$source_dir}/{$footer_template}/bank/{$bank}/*.{jpg,png,gif,jgeg,svg}" ,GLOB_BRACE);
									if(!empty($bimgs)){
										
										foreach($bimgs as $bimg){
											if(strpos(basename($bimg),"_") === 0){
												continue;
											}
											
											$bimg = "{$source_url}/{$footer_template}/bank/{$bank}/" . basename($bimg);
											$link = "";
											if(file_exists("{$source_dir}/{$footer_template}/bank/{$bank}/link.txt")){
												$link = trim(file_get_contents("{$source_dir}/{$footer_template}/bank/{$bank}/link.txt"));
											}
											echo "<a style='padding:0 5px;' href='{$link}' target='_blank' ><img style='height:32px;' src='{$bimg}' /></a>";
										}
									}
								}
							}
						?>
					</div>
					<div style='padding: 0 10px;'>&nbsp;</div>
					<div class="hpay-footer-branding-3ds" style='display:flex'>
						<?php
						echo implode(" ",array_map(function($path) use($footer_template, $source_url, $source_dir){
							if(is_dir($path)){
								$s3ds_method = basename($path);
								
								if(stripos($s3ds_method,"_") === 0)
									return "";
								
								$s3ds_imgs = glob("{$source_dir}/{$footer_template}/s3ds/{$s3ds_method}/*.{jpg,png,gif,jgeg,svg}" ,GLOB_BRACE);
								if(!empty($s3ds_imgs)){
									$out = "";
									foreach($s3ds_imgs as $s3ds_img){
										
										if(strpos(basename($s3ds_img),"_") === 0){
											continue;
										}
										
										$s3ds_img = "{$source_url}/{$footer_template}/s3ds/{$s3ds_method}/" . basename($s3ds_img);
										$link = "";
										if(file_exists($path . "/link.txt")){
											$link = trim(file_get_contents($path . "/link.txt"));
										}
										$out .= "<a href='{$link}' target='_blank' ><img style='height:32px;' src='{$s3ds_img}' /></a> ";
									}
									return $out;
								}
							}else{
								return "";
							}
						}, glob("{$source_dir}/{$footer_template}/s3ds/*" ,GLOB_BRACE)));
						?>
					</div>
				</div>
				<?php
			}
		}
	}
};