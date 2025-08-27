<?php
/**
 * Plugin Name: HolestPay Payments Plugin WooCommerce or Standalone 
 * Plugin URI:
 * Description: HolestPay payment system supports integration with most of the banks in the Adriatic region. Ovaj softver je vlasnistvo HOLEST E-COMMERCE D.O.O. Neovlašćenim korišćenjem ovog softver-a podležete riziku od zakonske kazne.
 * Version: 1.1.059
 * Requires at least: 4.0
 * WC requires at least: 4.2.0
 * WC tested up to: 7.4.0
 * Tested up to: 6.1.1
 * Author: HOLEST E-COMMERCE
 * Author URI: https://ecommerce.holest.com
 * Text Domain: holestpay
 * Domain Path: /languages
 */


if(!function_exists("add_action")){
	die("Direct access is not allowed");
};
 
if(isset($_GET["__hpay_skip__"])){
	return;
}

if(isset($_GET["__hpay_enable_error_reporting__"])){
	error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
	ini_set("display_errors","on");
}

if(!defined('HPAY_PRODUCTION_URL'))
	define("HPAY_PRODUCTION_URL","https://pay.holest.com");

if(!defined('HPAY_SANDBOX_URL'))
	define("HPAY_SANDBOX_URL","https://sandbox.pay.holest.com");

if(!defined('HPAY_PLUGIN'))
	define("HPAY_PLUGIN", plugin_basename( __FILE__ ));

if(!defined('HPAY_PLUGIN_PATH'))
	define("HPAY_PLUGIN_PATH", __DIR__);

if(!defined('HPAY_PLUGIN_URL'))
	define("HPAY_PLUGIN_URL", rtrim(plugin_dir_url(__FILE__),"/"));

if(!defined('HPAY_PLUGIN_FILE'))
	define("HPAY_PLUGIN_FILE",__FILE__);

try{
	require_once(__DIR__ . DIRECTORY_SEPARATOR . "hpay.php");
}catch(Throwable $ex){
	if(method_exists($ex, "getTrace")){
		$t = $ex->getTrace();
		if(!empty($t)){
			$t = $t[0];
		}
		echo "<!-- HPAY_EXCEPTION: " . $ex->getMessage() . "|TRACE: " .  json_encode($t, JSON_PRETTY_PRINT)  . " -->";
	}else{
		echo "<!-- HPAY_EXCEPTION: " . $ex->getMessage() . "|" . basename(__FILE__). ':' . __LINE__ . " -->";
	}
}