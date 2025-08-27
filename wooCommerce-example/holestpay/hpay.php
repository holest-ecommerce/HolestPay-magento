<?php
//HOLESTPAY 2023
if(!function_exists("add_action")){
	die("Direct access is not allowed");
};

try {
	require_once(__DIR__ . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "hpay_front.php");
	require_once(__DIR__ . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "hpay_admin.php");

	require_once(__DIR__ . DIRECTORY_SEPARATOR . "hpay_static.php");
	require_once(__DIR__ . DIRECTORY_SEPARATOR . "hpay_update.php");
	require_once(__DIR__ . DIRECTORY_SEPARATOR . "hpay_conversion.php");
	require_once(__DIR__ . DIRECTORY_SEPARATOR . "hpay_data.php");
	require_once(__DIR__ . DIRECTORY_SEPARATOR . "hpay_woo.php");
	require_once(__DIR__ . DIRECTORY_SEPARATOR . "hpay_woo_gui.php");
	require_once(__DIR__ . DIRECTORY_SEPARATOR . "hpay_maintain.php");
	require_once(__DIR__ . DIRECTORY_SEPARATOR . "hpay_checkout.php");
	require_once(__DIR__ . DIRECTORY_SEPARATOR . "hpay_email.php");
}catch (Throwable $ex) {
	return;	
}

if(!defined('HPAY_LOG_DIR')){
	define('HPAY_LOG_DIR', WP_CONTENT_DIR . "/uploads/hpay-logs");
}

if(!defined('HPAY_DEBUG_TRACE')){
	define('HPAY_DEBUG_TRACE',false);
}

global $hpay_pm_class_mapper, $hpay_sm_class_mapper, $hpay_fm_class_mapper, $hpay_valid_currencies;

$hpay_valid_currencies = array("AED","ALL","ANG","ARS","AUD","AWG","BBD","BDT","BHD","BIF","BMD","BND","BOB","BRL","BSD","BTN","BWP","BZD","CAD","CHF","CLF","CLP","CNY","COP","CRC","CZK","CUP","CVE","DKK","DOP","DZD","EGP","ETB","EUR","FJD","FKP","GBP","GIP","GMD","GNF","GTQ","GYD","HKD","HNL","HTG","HUF","IDR","ILS","INR","IQD","IRR","JMD","JOD","JPY","KES","KHR","KMF","KPW","KRW","KWD","KYD","LAK","LBP","LKR","LRD","LSL","LYD","MAD","MNT","MOP","MRO","MUR","MVR","MWK","MYR","NGN","NOK","NPR","NZD","OMR","PAB","PEN","PGK","PHP","PKR","PLN","PYG","QAR","RON","RWF","SAR","SBD","SCR","SEK","SGD","SHP","SLL","SOS","STD","RUB","SVC","SYP","SZL","THB","TND","TOP","TRY","TTD","TWD","TZS","USD","VND","VUV","WST","YER","RSD","ZAR","ZMK","ZWD","AMD","MMK","HRK","ERN","DJF","ISK","KZT","KGS","LVL","LTL","MXN","MDL","NAD","NIO","UGX","MKD","UYU","UZS","AZN","GHS","VEF","SDG","UYI","MZN","CHE","CHW","XAF","XCD","XOF","XPF","SRD","MGA","COU","AFN","TJS","AOA","BYR","BGN","CDF","BAM","MXV","UAH","GEL","BOV");

$hpay_pm_class_mapper = array();
$hpay_sm_class_mapper = array();
$hpay_fm_class_mapper = array();

//POLYFILLS////////////////////////////////////////////////////////
if (!function_exists('array_is_list')) {
    function array_is_list(array $arr)
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
///////////////////////////////////////////////////////////////////

if(!function_exists("hpay_read_get_parm")){
	function hpay_read_get_parm($parm_name, $default = null){
		if(isset($_GET[$parm_name])){
			if(is_array($_GET[$parm_name])){
				$arr = array();
				foreach($_GET[$parm_name] as $index => $value){
					$arr[$index] = sanitize_text_field($value);
				}
				return $arr;
			}else
				return sanitize_text_field($_GET[$parm_name]);
		}
		return $default;
	}
}

if(!function_exists("hpay_read_post_parm")){
	function hpay_read_post_parm($parm_name, $default = null){
		if(isset($_POST[$parm_name])){
			if(is_array($_POST[$parm_name])){
				$arr = array();
				foreach($_POST[$parm_name] as $index => $value){
					$arr[$index] = sanitize_text_field($value);
				}
				return $arr;
			}else
				return sanitize_text_field($_POST[$parm_name]);
		}
		return $default;
	}
}

if(!function_exists("hpay_read_request_parm")){
	function hpay_read_request_parm($parm_name, $default = null){
		if(isset($_REQUEST[$parm_name])){
			if(is_array($_REQUEST[$parm_name])){
				$arr = array();
				foreach($_REQUEST[$parm_name] as $index => $value){
					$arr[$index] = sanitize_text_field($value);
				}
				return $arr;
			}else
				return sanitize_text_field($_REQUEST[$parm_name]);
		}
		return $default;
	}
}

if(!function_exists("hpay_read_server_parm")){
   function hpay_read_server_parm($parm_name, $default = NULL){
		if(isset($_SERVER[$parm_name])){
			if(is_array($_SERVER[$parm_name])){
				$arr = array();
				foreach($_SERVER[$parm_name] as $index => $value){
					$arr[$index] = sanitize_text_field($value);
				}
				return $arr;
			}else
				return sanitize_text_field($_SERVER[$parm_name]);
		}
		return $default;
   }
}

if(!function_exists("hpay_return_false")){
	function hpay_return_false($arg1 = null,$arg2 = null,$arg3 = null,$arg4 = null,$arg5 = null,$arg6 = null,$arg7 = null){
		return false;
	}
	
	function hpay_return_true($arg1 = null,$arg2 = null,$arg3 = null,$arg4 = null,$arg5 = null,$arg6 = null,$arg7 = null){
		return true;
	}	
}

if(!function_exists("hpay_wchps_enabled")){
	function hpay_wchps_enabled(){
		try{
			return Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}catch(Throwable $ex){
			return null;
		}
	}
}

if(!function_exists("hpay_id_is_wc_order")){
	function hpay_id_is_wc_order($id){
		try{
			$type = Automattic\WooCommerce\Utilities\OrderUtil::get_order_type($id);
			return $type == "shop_order" || strpos("".$type,"shop_order_placehold") !== false;
		}catch(Throwable $ex){
			$type = get_post_type($id);
			return $type == "shop_order" || strpos("".$type,"shop_order_placehold") !== false;
		}
	}
}

if(!function_exists("hpay_write_log")){
	function hpay_write_log($log_type, $data, $flag = false){
		try{
			if(HPay_Core::instance()->getSetting("enable_log","") == 1 || $log_type == "error"){
				if(!file_exists(HPAY_LOG_DIR)){
					@mkdir(HPAY_LOG_DIR,0775,true);
				}
				
				if(!is_string($data)){
					try{
						if($data){
							if(method_exists($data,"getMessage")){
								$data = $data->getMessage();
							}
						}
					}catch(Throwable $mex){}
				}
				
				if($log_type == "error" || $log_type == "trace"){
					try{
						$data = array($data,debug_backtrace(2,5));
					}catch(Throwable $mex){}
				}
				
				if(!is_string($data)){
					$data = json_encode($data, JSON_PRETTY_PRINT);
				}
				
				$d = date("Ymd"); 
				@file_put_contents(HPAY_LOG_DIR . "/{$log_type}{$d}.log.txt","\r\n" . date("Y-m-d H:i:s") .  "\r\n" . $data . "\r\n",FILE_APPEND);
				global $__hpay_log_clear;
				if(!isset($__hpay_log_clear)){
					$__hpay_log_clear = true;
					
					$files = array_unique(array_merge(glob(HPAY_LOG_DIR ."/*.log"),glob(HPAY_LOG_DIR ."/*.txt")));
					
					$threshold = strtotime('-7 day');
					foreach ($files as $file) {
						if (is_file($file)) {
							if ($threshold >= filemtime($file)) {
								unlink($file);
							}
						}
					}
				}
			}
		}catch(Throwable $ex){
			//
		}
	}
}

if(!function_exists("hpay_get_error_logs")){
	function hpay_get_error_logs(){
		$elogs = array();
		try{
			if(!file_exists(HPAY_LOG_DIR)){
				return $elogs;
			}
			$files = array_unique(array_merge(glob(HPAY_LOG_DIR ."/error*.log"),glob(HPAY_LOG_DIR ."/error*.txt")));
			$threshold = strtotime('-7 day');
			foreach ($files as $file) {
				if (is_file($file)) {
					if ($threshold <= filemtime($file)) {
						$elogs[basename($file)] = @file_get_contents($file);
					}
				}
			}
		}catch(Throwable $ex){
			//
		}
		return $elogs;
	}
}

if(!function_exists("hpay_get_order")){
	function hpay_get_order($order_id, $force_db_read = false){
		global $_hpay_order_cache;
		
		if(!$order_id)
			return null;
		
		if($force_db_read){
			try{
				wp_using_ext_object_cache( false );
				wp_cache_flush();
				wp_cache_init();
			}catch(Throwable $ex){
				
			}
		}
		
		if(is_numeric($order_id)){
			if(!isset($_hpay_order_cache)){
				$_hpay_order_cache = array();
			}
			if($force_db_read || !isset($_hpay_order_cache[$order_id])){
				$o = wc_get_order($order_id);
				if($o){
					$_hpay_order_cache[$order_id] = $o;
				}else{
					return null;
				}
			}
			return $_hpay_order_cache[$order_id];
		}else{
			if(is_a($order_id, "WC_Order")){
				if(!isset($_hpay_order_cache)){
					$_hpay_order_cache = array();
				}
				if($force_db_read){
					$order_id = wc_get_order($order_id->get_id());
				}
				if(!isset($_hpay_order_cache[$order_id->get_id()])){
					$_hpay_order_cache[$order_id->get_id()] = $order_id;
				}
				return $_hpay_order_cache[$order_id->get_id()];
			}
		}
	}
}

if(!function_exists('hpay_get_wc_order_currency')){
	function hpay_get_wc_order_currency($order = null){
		try{
			global $hpay_valid_currencies;
			
			if($order){
				if(is_numeric($order)){
					$order = np_get_order($order);
				}
				$currency = strtoupper($order->get_currency());
			}else{
				$currency = get_woocommerce_currency();
			}
			
			if(in_array($currency, $hpay_valid_currencies))
				return $currency;
			
			$maybe = get_woocommerce_currency_symbol($currency);
			
			if(stripos($maybe,"дин") !== false || stripos($maybe,"din") !== false || stripos($maybe,"rsd") !== false || stripos($maybe,"рсд") !== false){
				return "RSD";
			}else if(stripos($maybe,"ден") !== false || stripos($maybe,"den") !== false || stripos($maybe,"mkd") !== false || stripos($maybe,"мкд") !== false ){
				return "MKD";
			}else if(stripos($maybe,"km") !== false || stripos($maybe,"км") !== false ){
				return "БАМ";
			}
			
			return substr(strtoupper($maybe),0,3);
		}catch(Throwable $ex){
			return null;
		}
	}
	
	function hpay_get_wc_currency(){
		try{
			global $hpay_valid_currencies;
			$currency = get_option( 'woocommerce_currency' );
			
			if(in_array($currency, $hpay_valid_currencies))
				return $currency;
			
			$maybe = get_woocommerce_currency_symbol($currency);
			
			if(stripos($maybe,"дин") !== false || stripos($maybe,"din") !== false || stripos($maybe,"rsd") !== false || stripos($maybe,"рсд") !== false){
				return "RSD";
			}else if(stripos($maybe,"ден") !== false || stripos($maybe,"den") !== false || stripos($maybe,"mkd") !== false || stripos($maybe,"мкд") !== false ){
				return "MKD";
			}else if(stripos($maybe,"km") !== false || stripos($maybe,"км") !== false ){
				return "БАМ";
			}
			
			return substr(strtoupper($maybe),0,3);
		}catch(Throwable $ex){
			return null;
		}
	}
}

if(!function_exists('hpay_on_wp_redirect')){
    function hpay_on_wp_redirect( $status, $location){
		try{
			if(stripos("{$status}","30") === 0){
				$hpay_forwarded_payment_response = hpay_read_request_parm('hpay_forwarded_payment_response',null);
				if($hpay_forwarded_payment_response){
					set_transient('hpay_forwarded_payment_response'. session_id(),$hpay_forwarded_payment_response,18);
				}
			}
		}catch(Throwable $ex){
			//
		}
		return $status;
	}
}
add_filter( 'wp_redirect_status', 'hpay_on_wp_redirect', 999, 2);


add_action( 'before_woocommerce_init', function() {
	try{
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', HPAY_PLUGIN_FILE , true );
		}
	}catch(Throwable $ex){
		//
	}
});

function HPay_init(){
	try{
		global $__hpay_init_done, $hpay_pm_class_mapper, $hpay_sm_class_mapper, $hpay_fm_class_mapper;
		
		if(isset($__hpay_init_done)){
			if($__hpay_init_done)
				return;
		}
		
		if (class_exists( 'WC_Payment_Gateway' ) ){
			$__hpay_init_done = true;
			require_once(__DIR__ . "/class/class-wc-hpay-payment-gateway.php");
			
			//PAYMENT METHOD PLACEHOLDER CLASSES///////////////////////////////////////
			class WC_Gateway_HPayPMPHOLDER1 extends WC_Gateway_HPayPayment{};class WC_Gateway_HPayPMPHOLDER2 extends WC_Gateway_HPayPayment{};
			class WC_Gateway_HPayPMPHOLDER3 extends WC_Gateway_HPayPayment{};class WC_Gateway_HPayPMPHOLDER4 extends WC_Gateway_HPayPayment{};
			class WC_Gateway_HPayPMPHOLDER5 extends WC_Gateway_HPayPayment{};class WC_Gateway_HPayPMPHOLDER6 extends WC_Gateway_HPayPayment{};
			class WC_Gateway_HPayPMPHOLDER7 extends WC_Gateway_HPayPayment{};class WC_Gateway_HPayPMPHOLDER8 extends WC_Gateway_HPayPayment{};
			class WC_Gateway_HPayPMPHOLDER9 extends WC_Gateway_HPayPayment{};class WC_Gateway_HPayPMPHOLDER10 extends WC_Gateway_HPayPayment{};
			class WC_Gateway_HPayPMPHOLDER11 extends WC_Gateway_HPayPayment{};class WC_Gateway_HPayPMPHOLDER12 extends WC_Gateway_HPayPayment{};
			class WC_Gateway_HPayPMPHOLDER13 extends WC_Gateway_HPayPayment{};class WC_Gateway_HPayPMPHOLDER14 extends WC_Gateway_HPayPayment{};
			///////////////////////////////////////////////////////////////////////////
					
			$settings = HPay_Core::instance()->getSettings();
			if(isset($settings["environment"])){
				$environment = $settings["environment"];
				if(isset($settings["{$environment}POS"])){
					if(isset($settings["{$environment}POS"]["payment"])){
						if(!empty($settings["{$environment}POS"]["payment"])){
							$n = 1;
							foreach($settings["{$environment}POS"]["payment"] as $pm){
								if(!class_exists("WC_Gateway_HPayPMPHOLDER{$n}"))
									break;//ADD MORE ABOVE IF NEEDED
								
								$alias = "WC_Gateway_HPay_" . $pm["PaymentMethod"] . "_" . $pm["HPaySiteMethodId"];
								$hpay_pm_class_mapper["WC_Gateway_HPayPMPHOLDER{$n}"] = array(
									"alias"   => $alias,
									"data"    => $pm,
									"hpay_id" => $pm["HPaySiteMethodId"],
									"id"      => "hpaypayment-" . $pm["HPaySiteMethodId"]
								);
								class_alias("WC_Gateway_HPayPMPHOLDER{$n}",$alias);
								$n++;
							}
						}
					}	
				}
			}
		}
		
		
		if (class_exists( 'WC_Shipping_Method' ) ){
			$__hpay_init_done = true;
			require_once(__DIR__ . "/class/class-wc-hpay-shipping-method.php");
			
			//SHIPPING METHOD PLACEHOLDER CLASSES///////////////////////////////////////
			class WC_Gateway_HPaySMPHOLDER1 extends WC_HPay_Shipping_Method{};class WC_Gateway_HPaySMPHOLDER2 extends WC_HPay_Shipping_Method{};
			class WC_Gateway_HPaySMPHOLDER3 extends WC_HPay_Shipping_Method{};class WC_Gateway_HPaySMPHOLDER4 extends WC_HPay_Shipping_Method{};
			class WC_Gateway_HPaySMPHOLDER5 extends WC_HPay_Shipping_Method{};class WC_Gateway_HPaySMPHOLDER6 extends WC_HPay_Shipping_Method{};
			class WC_Gateway_HPaySMPHOLDER7 extends WC_HPay_Shipping_Method{};class WC_Gateway_HPaySMPHOLDER8 extends WC_HPay_Shipping_Method{};
			class WC_Gateway_HPaySMPHOLDER9 extends WC_HPay_Shipping_Method{};class WC_Gateway_HPaySMPHOLDER10 extends WC_HPay_Shipping_Method{};
			class WC_Gateway_HPaySMPHOLDER11 extends WC_HPay_Shipping_Method{};class WC_Gateway_HPaySMPHOLDER12 extends WC_HPay_Shipping_Method{};
			class WC_Gateway_HPaySMPHOLDER13 extends WC_HPay_Shipping_Method{};class WC_Gateway_HPaySMPHOLDER14 extends WC_HPay_Shipping_Method{};
			///////////////////////////////////////////////////////////////////////////
					
			$settings = HPay_Core::instance()->getSettings();
			if(isset($settings["environment"])){
				$environment = $settings["environment"];
				if(isset($settings["{$environment}POS"])){
					if(isset($settings["{$environment}POS"]["shipping"])){
						
						
						
						if(!empty($settings["{$environment}POS"]["shipping"])){
							$n = 1;
							foreach($settings["{$environment}POS"]["shipping"] as $sm){
								if(!class_exists("WC_Gateway_HPaySMPHOLDER{$n}"))
									break;//ADD MORE BELOW IF NEEDED
								
								$alias = "WC_Gateway_HPay_" . $sm["ShippingMethod"] . "_" . $sm["HPaySiteMethodId"];
								$hpay_sm_class_mapper["WC_Gateway_HPaySMPHOLDER{$n}"] = array(
									"alias"   => $alias,
									"data"    => $sm,
									"hpay_id" => $sm["HPaySiteMethodId"],
									"id"      => "hpayshipping-" . $sm["HPaySiteMethodId"]
								);
								class_alias("WC_Gateway_HPaySMPHOLDER{$n}",$alias);
								$n++;
							}
						}
					}	
				}
			}
		}
	}catch(Throwable $ex){
		
	}
}

function hpay_woocommerce_add_payment_methods($methods){
	global $hpay_pm_class_mapper;
	foreach($hpay_pm_class_mapper as $placeholder_class => $pmref){
		$methods[] = $pmref["alias"];
	}
	return $methods;
}

function hpay_woocommerce_add_shipping_methods($methods){
	global $hpay_sm_class_mapper;
	foreach($hpay_sm_class_mapper as $placeholder_class => $smref){
		$methods[$smref["id"]] = $smref["alias"];
	}
	return $methods;
}

function hpay_woo_order_type($order_id){
	try{
		if(is_a($order_id,"WC_Order")){
			$order_id = $order_id->get_id();
		}
		return Automattic\WooCommerce\Utilities\OrderUtil::get_order_type( $order_id );
	}catch(Throwable $ex){
		return null;
	}
}

require_once(__DIR__ . DIRECTORY_SEPARATOR . "hpay_class.php");

add_filter( 'woocommerce_shipping_methods', 'hpay_woocommerce_add_shipping_methods' );
add_filter( 'woocommerce_payment_gateways', 'hpay_woocommerce_add_payment_methods' );
add_action( 'woocommerce_blocks_loaded', 'hpay_woocommerce_gateway_block_support' );

//INIT/////////////////////////////////////////////////////////////////////////////////////
HPay_Core::instance();
///////////////////////////////////////////////////////////////////////////////////////////
require_once(__DIR__ . DIRECTORY_SEPARATOR . "integrations" . DIRECTORY_SEPARATOR . "init_integrations.php");
///////////////////////////////////////////////////////////////////////////////////////////

add_action( 'plugins_loaded', 'hpay_on_plugins_loaded' );
function hpay_on_plugins_loaded() {
	
	if(strpos(hpay_read_server_parm("REQUEST_URI",""),"wp-admin") === false){
		if(strpos(hpay_read_server_parm("REQUEST_URI",""),"order=") !== false || hpay_read_get_parm("key") || hpay_read_get_parm("order-received")){
			add_filter( 'gettext', 'hpay_fix_nazalost_message', 10, 3 );
		}
	}
	
	if(hpay_read_get_parm("__hpay_list_logs__","")){
		$files = array();
		if(file_exists(HPAY_LOG_DIR)){
			$files = array_unique(array_merge(glob(HPAY_LOG_DIR ."/*.log"),glob(HPAY_LOG_DIR ."/*.txt")));
		}
		
		foreach($files as $file){
			if(strpos($file, ".log.txt") === false){
				$nfile = str_replace(".log",".log.txt",$file);
				if(@rename($file,$nfile)){
					$file = $nfile;
				}
			}
			echo "<br/><a href='". esc_url(get_site_url() . '/' . str_replace( ABSPATH, '', $file ))."'>" . esc_url(get_site_url() . '/' . str_replace( ABSPATH, '', $file )) . "</a>";
		}
		die;
	}
	
}

function hpay_fix_nazalost_message( $translated_text, $untranslated_text, $domain ) {
    if ( 'woocommerce' === $domain && strlen($translated_text) > 50) {
		if(stripos($untranslated_text,"originating bank/merchant has declined your transaction") !== false){
			return __("Unfortunately the payment has failed. Please try agian.","holestpay");
		}
    }
	return $translated_text;
}

function hpay_woocommerce_gateway_block_support(){
	if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		require_once(rtrim(__DIR__,"/") . '/class/class-wc-hpay-payment-gateway-block.php');
		
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
				global $hpay_pm_class_mapper;
				foreach($hpay_pm_class_mapper as $holder_class => $info){
					$pm = $info["data"];
					$alias = "WC_Gateway_HPay_" . $pm["PaymentMethod"] . "_" . $pm["HPaySiteMethodId"] . "_Block";
					class_alias("{$holder_class}_BLOCK",$alias);
					$payment_method_registry->register(new $alias());
				}
			}
		);
	}
}

