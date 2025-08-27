<?php
//HOLESTPAY 2023
if(!defined("HPAY_PRODUCTION_URL")){
	die("Direct access is not allowed");
};

trait HPay_Core_Maintain{
	public function maintain_run(){
		//////////////////////////////////////////////////////////////////
		if(!get_option("hpay_patch_20241207","")){
			update_option("hpay_patch_20241207","1",true);
			try{
				global $wpdb;
				$wpdb->query("DELETE {$wpdb->prefix}postmeta FROM {$wpdb->prefix}postmeta LEFT JOIN {$wpdb->prefix}posts ON {$wpdb->prefix}postmeta.post_id = {$wpdb->prefix}posts.ID  WHERE {$wpdb->prefix}posts.post_type = 'shop_subscription' AND {$wpdb->prefix}postmeta.meta_key LIKE '_hpay_%'");
				$double_resps = $wpdb->get_results("SELECT max(meta_id) as meta_id FROM {$wpdb->prefix}postmeta WHERE meta_key like '_hpay_%' GROUP BY post_id, meta_key HAVING COUNT(meta_id) > 1");
				if(!empty($double_resps)){
					foreach($double_resps as $dresp){
						$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}postmeta WHERE meta_id = %d", $dresp->meta_id));
					}
				}
			}catch(Throwable $ex){
				hpay_write_log("error",$ex);
			}
		}
		//////////////////////////////////////////////////////////////////
	}
};	