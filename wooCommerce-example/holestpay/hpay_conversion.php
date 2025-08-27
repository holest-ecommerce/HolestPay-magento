<?php
//HOLESTPAY 2023
if(!defined("HPAY_PRODUCTION_URL")){
	die("Direct access is not allowed");
};

trait HPay_Core_Conversion {

	private static $TO_GRAM_UNIT_CONVERT = array(
		"g" =>         1,
		"grams" =>     1,
		"gram" =>      1,
		"mg" =>        0.001,
		"mgs" =>       0.001,
		"miligrams" => 0.001,
		"miligram" =>  0.001,
		"kg" =>        1000,
		"kgs" =>       1000,
		"kilograms" => 1000,
		"kilogram" =>  1000,
		"oz" =>        28.3495231,
		"ounces" =>    28.3495231,
		"ounce" =>     28.3495231,
		"lb" =>        453.59237,
		"lbs" =>       453.59237,
		"pounds" =>    453.59237,
		"pound" =>     453.59237
	);
	
	private static $TO_CM_UNIT_CONVERT = array(
		"mm" =>       0.1,
		"m"  =>       100,
		"km" =>       100000,
		"cm" =>       1,
		"dm" =>       10,
		"in" =>       2.54,
		"mi" =>       160934.4,
		"ft" =>       30.48,
		"yd" =>       91.44,
		"yds"     =>  91.44,
		"chain"   =>  2011.68,
		"furlong" =>  20116.80
	);
	
	public static function convertToGrams($value, $unit){
		if(!$value)
			return 0;
		
		$unit = strtolower(trim($unit));
		if(isset(HPay_Core::$TO_GRAM_UNIT_CONVERT[$unit])){
			return HPay_Core::$TO_GRAM_UNIT_CONVERT[$unit] * $value;
		}
		return $value;
	}
	
	public static function convertToCM($value, $unit){
		if(!$value)
			return 0;
		
		$unit = strtolower(trim($unit));
		if(isset(HPay_Core::$TO_CM_UNIT_CONVERT[$unit])){
			return HPay_Core::$TO_CM_UNIT_CONVERT[$unit] * $value;
		}
		return $value;
	}
};	