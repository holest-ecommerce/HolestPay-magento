<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if(!defined("WC_PAYMENT_TOKEN_HPAY_TYPE")){
	define("WC_PAYMENT_TOKEN_HPAY_TYPE","hpay");
}

class WC_Payment_Token_HPay extends WC_Payment_Token {
	
	protected $type = WC_PAYMENT_TOKEN_HPAY_TYPE;
	protected $extra_data = array();
	
	private $_token_data = null;
	
	protected function get_hook_prefix() {
		return 'woocommerce_payment_token_hpay_get_';
	}
	
	public function validate() {
		return true;
	}
	
	public function merchant_site_uid(){
		try{
			if(!$this->_token_data)
				$this->_token_data = json_decode(parent::get_token(),true);
			
			if(!$this->_token_data)
				return null;
			
			return $this->_token_data["merchant_site_uid"];
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
			return null;
		}
	}
	
	public function hpaymethodtype(){
		try{
			if(!$this->_token_data)
				$this->_token_data = json_decode(parent::get_token(),true);
			
			if(!$this->_token_data)
				return null;
			
			return $this->_token_data["hpaymethodtype"];
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
			return null;
		}
	}
	
	public function vault_card_brand(){
		try{
			if(!$this->_token_data)
				$this->_token_data = json_decode(parent::get_token(),true);
			
			if(!$this->_token_data)
				return null;
			
			return $this->_token_data["vault_card_brand"];
		}catch(Throwable $ex){
			return null;
		}
	}
	
	public function vault_card_umask(){
		try{
			if(!$this->_token_data)
				$this->_token_data = json_decode(parent::get_token(),true);
			
			if(!$this->_token_data)
				return null;
			
			return $this->_token_data["vault_card_umask"];
		}catch(Throwable $ex){
			return null;
		}
	}
	
	public function vault_token_uid(){
		try{
			if(!$this->_token_data)
				$this->_token_data = json_decode(parent::get_token(),true);
			
			if(!$this->_token_data)
				return null;
			
			return $this->_token_data["vault_token_uid"];
		}catch(Throwable $ex){
			return null;
		}
	}
	
	public function vault_scope(){
		try{
			if(!$this->_token_data)
				$this->_token_data = json_decode(parent::get_token(),true);
			
			if(!$this->_token_data)
				return null;
			
			return $this->_token_data["vault_scope"];
		}catch(Throwable $ex){
			return null;
		}
	}
	
	public function vault_onlyforuser(){
		try{
			if(!$this->_token_data)
				$this->_token_data = json_decode(parent::get_token(),true);
			
			if(!$this->_token_data)
				return null;
			
			return $this->_token_data["vault_onlyforuser"];
		}catch(Throwable $ex){
			return null;
		}
	}
	
	public function token_time(){
		try{
			if(!$this->_token_data)
				$this->_token_data = json_decode(parent::get_token(),true);
			
			if(!$this->_token_data)
				return null;
			if(isset($this->_token_data["token_time"]))
				return $this->_token_data["token_time"];
			else
				return "--";
			
		}catch(Throwable $ex){
			return null;
		}
	}
	
	public function hpay_language(){
		try{
			if(!$this->_token_data)
				$this->_token_data = json_decode(parent::get_token(),true);
			
			if(!$this->_token_data)
				return null;
			if(isset($this->_token_data["language"]))
				return $this->_token_data["language"];
			else
				return "en";
		}catch(Throwable $ex){
			return null;
		}
	}
	
	public function set_default($default){
		if(!$this->get_id()){
			return parent::set_default($default);
		}
		global $wpdb;
		global $hpay_wc_payment_token_hpay_default_cache;
		if(!isset($hpay_wc_payment_token_hpay_default_cache)){
			$hpay_wc_payment_token_hpay_default_cache = array();
		}
			
		try{
			if($default){
				$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}woocommerce_payment_tokens SET is_default = 0 WHERE gateway_id = %s", 'hpay'));
			}
			$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}woocommerce_payment_tokens SET is_default = %d WHERE token_id = %d", $default ? 1: 0, $this->get_id()));
			$hpay_wc_payment_token_hpay_default_cache[$this->get_id()] = $default ? 1: 0;
		}catch(Throwable $ex){
			//
		}
		return $default;
	}
	
	public function is_default(){
		if(!$this->get_id()){
			return parent::is_default();
		}
		global $wpdb;
		try{
			global $hpay_wc_payment_token_hpay_default_cache;
			if(!isset($hpay_wc_payment_token_hpay_default_cache)){
				$hpay_wc_payment_token_hpay_default_cache = array();
			}else{
				if(isset($hpay_wc_payment_token_hpay_default_cache[$this->get_id()])){
					return $hpay_wc_payment_token_hpay_default_cache[$this->get_id()];
				}
			}
			$default = $wpdb->get_col($wpdb->prepare("SELECT is_default FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE token_id = %d", $this->get_id()));
			$hpay_wc_payment_token_hpay_default_cache[$this->get_id()] = $default;
		}catch(Throwable $ex){
			//
		}
		return $default;
	}
	
	public static function create_hpay_token($customer_user_id, $merchant_site_uid, $hpaymethodtype, $vault_card_brand, $vault_card_umask, $vault_token_uid, $vault_scope, $vault_onlyforuser, $hpay_language = "en"){
		try{
			global $wpdb;
			
			if(!$customer_user_id){
				$customer_user_id = get_current_user_id();
			}
			
			if(!$customer_user_id)
				return null;
			
			$default = true;
			$token   = null;
			$existing_token = null;
			
			// $n = 0;
			// do{
				// $n++;
				// $existing_token = WC_Payment_Token_HPay::has_hpay_vault_token_uid($vault_token_uid, $customer_user_id, null, null, $vault_card_umask);
				// if($existing_token){
					// $default = $existing_token->is_default();
					// $existing_token->delete();
				// }
			// }while($existing_token && $n < 10);
			
			$existing_tokens = WC_Payment_Token_HPay::get_hpay_tokens($customer_user_id, null,  null, $vault_card_umask);
			foreach($existing_tokens as $existing_token){
				if($existing_token->vault_card_umask() == $vault_card_umask){
					$default = $existing_token->is_default();
					$existing_token->delete();	
					try{
						$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE token_id = %d", $existing_token->get_id()));
					}catch(Throwable $delex){
						//
					}
				}
			}
			
			$token = new WC_Payment_Token_HPay();
			$token->set_user_id($customer_user_id);
			$token->set_token(json_encode(array(
				"merchant_site_uid"   => $merchant_site_uid,
				"vault_card_brand"    => $vault_card_brand,
				"vault_card_umask"    => $vault_card_umask,
				"hpaymethodtype"      => $hpaymethodtype,
				"vault_token_uid"     => $vault_token_uid,
				"vault_onlyforuser"   => $vault_onlyforuser,
				"vault_scope"         => $vault_scope,
				"token_time"          => date("Y-m-d H:i"),
				"language"            => $hpay_language   
			)));
			
			$token->set_default($default);
			$token->set_gateway_id("hpay");
			$token->save();
			$token->set_default($default);
			
			return $token;
		}catch(Throwable $ex){
			return null;
		}
	}
	
	public static function get_hpay_tokens($customer_user_id = null, $hpaymethod_gateway = null,  $merchant_site_uid = null, $vault_card_umask = null){
		$filtred_tokens = array();
		
		try{
			if(!$customer_user_id){
				$customer_user_id = get_current_user_id();
			}
			
			$all_user_tokens  = WC_Payment_Tokens::get_tokens(array("user_id" => $customer_user_id, "gateway_id" => 'hpay', 'limit' => 999));
			
			if(!$merchant_site_uid){
				$merchant_site_uid = HPay_Core::instance()->getSetting("merchant_site_uid","");
			}
			
			foreach($all_user_tokens as $token_id => $token){
				if($token->get_type() == WC_PAYMENT_TOKEN_HPAY_TYPE){
					if($merchant_site_uid == $token->merchant_site_uid()){//USLESS CHECK
						if($hpaymethod_gateway){
							if($hpaymethod_gateway->hpay_method_type() != $token->hpaymethodtype()){
								continue;
							}
						}	
						
						if($vault_card_umask){
							if($vault_card_umask != $token->vault_card_umask()){
								continue;
							}
						}
						$filtred_tokens[$token_id] = $token;
					}
				}
			}
		}catch(Throwable $ex){
			
		}
		return $filtred_tokens;
	}
	
	public static function has_hpay_vault_token_uid($vault_token_uid, $customer_user_id = null, $hpaymethod_gateway = null,  $merchant_site_uid = null, $vault_card_umask = null){
		try{
			$tokens = WC_Payment_Token_HPay::get_hpay_tokens($customer_user_id, $hpaymethod_gateway,  $merchant_site_uid );
			if(!empty($tokens)){
				foreach($tokens as $token_id => $token){
					if($token->vault_token_uid() == $vault_token_uid){
						return $token;
					}
					if($customer_user_id && $vault_card_umask){
						if($token->vault_card_umask() == $vault_card_umask){
							return $token;
						}
					}
				}
			}
		}catch(Throwable $ex){
			
		}
		return null;
	}
	
	public static function destroy_hpay_tokens($customer_user_id = null, $hpaymethod_gateway = null,  $merchant_site_uid = null){
		$del_count = 0;
		try{
			
			$tokens = WC_Payment_Token_HPay::get_hpay_tokens($customer_user_id, $hpaymethod_gateway,  $merchant_site_uid );
			
			if(!empty($tokens)){
				foreach($tokens as $token_id => $token){
					$token->delete();
					$del_count++;
				}
			}
			
			return $del_count;
			
		}catch(Throwable $ex){
			return false;
		}
	}
	
};