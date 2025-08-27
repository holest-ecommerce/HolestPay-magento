<?php
//HOLESTPAY 2023
if(!defined("HPAY_PRODUCTION_URL")){
	die("Direct access is not allowed");
};

trait HPay_Core_Static {
	
	public static function hpaylang(){
		
		$olang = trim(HPay_Core::instance()->getSetting("override_language",""));
		if($olang){
			return $olang;
		}
		
		if(stripos(__("Serbian","holestpay"),"С") !== false || stripos(__("Yes","holestpay"),"Д") !== false){
			return "rs-cyr";
		}
		
		if(stripos(__("Serbia","woocommerce"),"С") !== false || stripos(__("Yes","woocommerce"),"Д") !== false){
			return "rs-cyr";
		}
		
		return substr(strtolower(str_ireplace("sr","rs", get_locale())),0,2);
	}
	
	public static function instance(){
		global $hpay_core_instance;
		if(isset($hpay_core_instance)){
			return $hpay_core_instance;
		}
		$hpay_core_instance = new HPay_Core();
		return $hpay_core_instance;
	}
	
	public static function payment_method_instance($method_id){
		global $hpay_pm_class_mapper;
		foreach($hpay_pm_class_mapper as $hclass_name => $m){
			
			if(ctype_digit("{$method_id}")){
				if($m["hpay_id"] == $method_id){
					if(!isset($m["instance"])){
						$cname = $m["alias"];
						$m["instance"] = new $cname();
					}
					return $m["instance"];
				}
			}else{
				if($m["id"] == $method_id || $m["alias"] == $method_id || $hclass_name == $method_id){
					if(!isset($m["instance"])){
						$cname = $m["alias"];
						$m["instance"] = new $cname();
					}
					return $m["instance"];
				}
			}
		}
		return null;
	}
	
	public static function shipping_method_instance($method_id){
		global $hpay_sm_class_mapper;
		foreach($hpay_sm_class_mapper as $hclass_name => $m){
			
			if(ctype_digit("{$method_id}")){
				if($m["hpay_id"] == $method_id){
					if(!isset($m["instance"])){
						$cname = $m["alias"];
						$m["instance"] = new $cname();
					}
					return $m["instance"];
				}
			}else{
				if($m["id"] == $method_id || $m["alias"] == $method_id || $hclass_name == $method_id){
					if(!isset($m["instance"])){
						$cname = $m["alias"];
						$m["instance"] = new $cname();
					}
					return $m["instance"];
				}
			}
		}
		return null;
	}
	
	public static function payment_methods_supporting_operation($op){
		global $hpay_pm_class_mapper;
		$filtered = array();
		
		foreach($hpay_pm_class_mapper as $hclass_name => $m){
			if(!isset($m["instance"])){
				$cname = $m["alias"];
				$m["instance"] = new $cname();
			}
			if($m["instance"]){
				if($m["instance"]->enabled != 'yes')
					continue;
				if($m["instance"]->supportsOperation($op)){
					$filtered[] = $m["instance"];
				}
			}
		}
		return $filtered;
	}
	
	public static function payment_methods_of_type($type){
		global $hpay_pm_class_mapper;
		$filtered = array();
		
		foreach($hpay_pm_class_mapper as $hclass_name => $m){
			if(!isset($m["instance"])){
				$cname = $m["alias"];
				$m["instance"] = new $cname();
			}
			if($m["instance"]){
				if($m["instance"]->enabled != 'yes')
					continue;
				if($m["instance"]->hpay_method_type() == $type){
					$filtered[] = $m["instance"];
				}
			}
		}
		return $filtered;
	}
	
	public static function shipping_methods_of_type($type){
		global $hpay_sm_class_mapper;
		$filtered = array();
		
		foreach($hpay_sm_class_mapper as $hclass_name => $m){
			if(!isset($m["instance"])){
				$cname = $m["alias"];
				$m["instance"] = new $cname();
			}
			if($m["instance"]){
				if($m["instance"]->enabled != 'yes')
					continue;
				if($m["instance"]->hpay_method_type() == $type){
					$filtered[] = $m["instance"];
				}
			}
		}
		return $filtered;
	}
	
	public static function payment_methods_enabled(){
		global $hpay_pm_class_mapper;
		$filtered = array();
		
		foreach($hpay_pm_class_mapper as $hclass_name => $m){
			if(!isset($m["instance"])){
				$cname = $m["alias"];
				$m["instance"] = new $cname();
			}
			if($m["instance"]){
				if($m["instance"]->enabled != 'yes')
					continue;
				$filtered[] = $m["instance"];
			}
		}
		return $filtered;
	}
	
	public static function shipping_methods_enabled(){
		global $hpay_sm_class_mapper;
		$filtered = array();
		
		foreach($hpay_sm_class_mapper as $hclass_name => $m){
			if(!isset($m["instance"])){
				$cname = $m["alias"];
				$m["instance"] = new $cname();
			}
			if($m["instance"]){
				if($m["instance"]->enabled != 'yes')
					continue;
				$filtered[] = $m["instance"];
			}
		}
		return $filtered;
	}
	
	public static function getExchnageRate($from, $to){
		
		$from   = strtoupper(trim($from)); 
		$to     = strtoupper(trim($to)); 
		
		if($from == $to){
			return 1.00;	
		}
		
		$cached = null;
		
		try{
			
			$cached = get_option("_hpay_exchangerate_{$from}_{$to}",null);
			
			if($cached){
				if(isset($cached["ts"])){
					if($cached["ts"] + 21600 > time()){
						return $cached["rate"];
					}
				}
			}

			$response = wp_remote_get( "https://pay.holest.com/clientpay/exchangerate?from={$from}&to={$to}" , array(
						'method'      => 'GET',
						'timeout'     => 18,
						'redirection' => 5,
						'httpversion' => '1.0',
						'blocking'    => true
					)
				);
			
			if (! is_wp_error( $response ) ) {
				$response =  json_decode(wp_remote_retrieve_body( $response ),true);
				if($response){
					if(isset($response["rate"])){
						update_option("_hpay_exchangerate_{$from}_{$to}", array(
							"rate" => floatval($response["rate"]),
							"ts"   => time()
						),true);
						return floatval($response["rate"]);
					}
				}
			}	
		}catch(Throwable $ex){
			hpay_write_log("error",$ex);
		}	
		
		if($cached){
		   return $cached["rate"];
	    }
		
		return null;
	}	
	
	public static function getMerchantExchnageRate($from, $to){
		$rate = HPay_Core::getExchnageRate($from, $to);
		if($rate === null){
			return $rate;
		}
		
		$ExchanageCorrection = floatval(HPay_Core::instance()->getPOSSetting("ExchanageCorrection",null));
		if($ExchanageCorrection){
			$rate *= (1.00 + ($ExchanageCorrection/100));
		}
		return $rate;
	}
	
}