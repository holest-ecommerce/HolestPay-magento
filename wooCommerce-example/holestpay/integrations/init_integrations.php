<?php
//HOLESTPAY 2023
if(!function_exists("add_action")){
	die("Direct access is not allowed");
};

add_filter( 'cartflows_offer_supported_payment_gateways', 'hpay_add_cartflows_offer_support' );

/**
 * Add new payment gateway in cartflows pro Supported Gateways.
 *
 * @param array $supported_gateways Supported Gateways by CartFlows.
 * @return array.
 */
function hpay_add_cartflows_offer_support( $supported_gateways ){
	try{
		$hmethods = HPay_Core::payment_methods_enabled();
		if(!empty($hmethods)){
			foreach($hmethods as $hmethod){
				
				$supported_gateways[$hmethod->id] = array(
					'file'  => 'class-cartflows-pro-gateway-hpay.php', // Your Custom code's file name
					'class' => 'Cartflows_Pro_Gateway_Hpay',   // Class name used in the Custom Code's file.
					'path'  => __DIR__ . DIRECTORY_SEPARATOR . "cartflowspro". DIRECTORY_SEPARATOR . "class-cartflows-pro-gateway-hpay.php", // Full directory path of the custom code's file.
				);
				
			}
		}
	}catch(Throwable $ex){
		//
	}
	return $supported_gateways; 
}

add_filter( "woocommerce_currency_symbols" ,"hpay_fix_currency_registry" ,70 ,1);
add_filter( "woocommerce_currency_symbol"  ,"hpay_fix_currency_symbol"   ,70 ,2);

function hpay_fix_currency_registry($symbols) {
	$symbols["RSD"] = __("RSD","holestpay");
	$symbols["BAM"] = __("BAM","holestpay");
	$symbols["MKD"] = __("MKD","holestpay");
	return $symbols;
}

function hpay_fix_currency_symbol( $symbol, $currency ) {
	
	if(stripos($currency,"rsd") === 0 || stripos($currency,"рсд") === 0 || stripos($currency,"din") === 0 || stripos($currency,"дин") === 0)
		return __("RSD","holestpay");
	
	if(stripos($currency,"bam") === 0 || stripos($currency,"km") === 0 || stripos($currency,"km") === 0)
		return __("BAM","holestpay");
	
	if(stripos($currency,"ден") !== false || stripos($currency,"den") !== false || stripos($currency,"mkd") !== false || stripos($currency,"мкд") !== false ){
		return __("MKD","holestpay");
	}
	
	return $symbol;
}
