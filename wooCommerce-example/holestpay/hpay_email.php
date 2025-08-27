<?php
//HOLESTPAY 2023
if(!defined("HPAY_PRODUCTION_URL")){
	die("Direct access is not allowed");
};

trait HPay_Core_Email{
	
	public function setup_mailing(){
		add_action( 'woocommerce_thankyou', array( $this, 'thankyou' ),30 );
		add_action( 'woocommerce_view_order', array( $this, 'order_view' ) ,30);
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_content' ), 99, 1 );
		add_filter( 'woocommerce_email_attachments', array( $this, 'email_attachments' ), 99, 3  );
		add_filter( 'woocommerce_email_headers', array( $this, 'add_email_headers'), 40, 3 );
	}
	
	public function thankyou($order_id){
		try{
			$order = null;
			if(is_a($order_id,"WC_Order")){
				$order = $order_id;
				$order_id = $order->get_id();
				$order = hpay_get_order($order_id /*, true*/ );
			}else{
				$order = hpay_get_order($order_id /*, true*/ );
			}
			
			$ptransaction = HPay_Core::instance()->get_presentable_transaction($order_id);
			HPay_Core::instance()->dispayOrderInfo($order_id, $ptransaction, null, null);
		}catch(Throwable $ex){
			hpay_write_log("error", $ex);
		}
	}
	
	public function order_view($order_id){
		try{
			$this->thankyou($order_id);
		}catch(Throwable $ex){
			hpay_write_log("error", $ex);
		}
	}
	
	public function prepare_email_content($content){
		
		try{
			$files = glob(WP_CONTENT_DIR . "/uploads/hpay-email-images/tmpimg*");
			$threshold = strtotime('-1 day');
			foreach ($files as $file) {
				if (is_file($file)) {
					if ($threshold >= filemtime($file)) {
						@unlink($file);
					}
				}
			}
		}catch(Throwable $ex){
			//
		}
		
		try{
			$data_urls = array();
			preg_match_all(@'/src=[\"|\'](data:image[^\"|\']*)[\"|\']/', $content, $data_urls);
			if(!empty($data_urls)){
				if(!empty($data_urls[1])){
					if(!file_exists(WP_CONTENT_DIR . "/uploads/hpay-email-images")){
						@mkdir(WP_CONTENT_DIR . "/uploads/hpay-email-images",0775,true);
					}
					
					if(file_exists(WP_CONTENT_DIR . "/uploads/hpay-email-images")){
						foreach($data_urls[1] as $index => $data_url){
							$dhaed = str_replace(":","/", substr($data_url, 0, strpos($data_url,";")));
							$dhaed = explode("/",$dhaed);
							$ext   = end($dhaed);
							
							if($ext == "jpeg")
								$ext = "jpg";
							
							$name = "tmpimg" . rand(100000,999999);
							
							if(@file_put_contents(WP_CONTENT_DIR . "/uploads/hpay-email-images/{$name}.{$ext}", @file_get_contents($data_url))){
								$content = str_replace($data_url, content_url("/uploads/hpay-email-images/{$name}.{$ext}"),$content);	
							}
						}
					}
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error", $ex);
		}
		return $content;
	}
	
	public function email_content($order){
		try{
			
			if($this->getSetting('woo_embeded_mails') == 1){
				
				if(HPAY_DEBUG_TRACE)
					hpay_write_log("trace", array($order_id, "email_content"));
			
				$order_id = null;
				if(is_a($order,"WC_Order")){
					$order_id = $order->get_id();
					$order = hpay_get_order($order_id, true);
				}else{
					$order = hpay_get_order($order_id, true);
					$order_id = $order->get_id();
				}
				
				ob_start();
				$this->thankyou($order_id);
				$content = ob_get_clean();
				
				$data_urls = array();
				preg_match_all(@'/src=[\"|\'](data:image[^\"|\']*)[\"|\']/', $content, $data_urls);
				if(!empty($data_urls)){
					if(!empty($data_urls[1])){
						if(!file_exists(WP_CONTENT_DIR . "/uploads/hpay-email-images")){
							@mkdir(WP_CONTENT_DIR . "/uploads/hpay-email-images",0775,true);
						}
						
						$order_status = $order->get_status();
						
						if(file_exists(WP_CONTENT_DIR . "/uploads/hpay-email-images")){
							foreach($data_urls[1] as $index => $data_url){
								$dhaed = str_replace(":","/", substr($data_url, 0, strpos($data_url,";")));
								$dhaed = explode("/",$dhaed);
								$ext   = end($dhaed);
								
								if($ext == "jpeg")
									$ext = "jpg";
								
								if(file_exists(WP_CONTENT_DIR . "/uploads/hpay-email-images/order-{$order_status}-{$order_id}-{$index}.{$ext}")){
									$content = str_replace($data_url, content_url("/uploads/hpay-email-images/order-{$order_status}-{$order_id}-{$index}.{$ext}"),$content);
								}else{
									if(@file_put_contents(WP_CONTENT_DIR . "/uploads/hpay-email-images/order-{$order_status}-{$order_id}-{$index}.{$ext}", @file_get_contents($data_url))){
										$content = str_replace($data_url, content_url("/uploads/hpay-email-images/order-{$order_status}-{$order_id}-{$index}.{$ext}"),$content);	
									}
								}
							}
						}
					}
				}
				echo $content;
			}
		}catch(Throwable $ex){
			hpay_write_log("error", $ex);
		}
	}
	
	public function email_attachments($attachments, $email_id, $email_order){
		try{
			
			if(!$email_order)
				return $attachments;
			
			if(!is_a( $email_order, 'WC_Order' ) || !isset($email_id)){
				return $attachments;
			}
			
			$order_id = $email_order->get_id();
			
			$order = hpay_get_order($order_id, true);
			
			$fiscal_user_info   = $order->get_meta('_fiscal_user_info');
			$shipping_user_info = $order->get_meta('_shipping_user_info');
			
			if(HPAY_DEBUG_TRACE)
				hpay_write_log("trace", array($order_id, "email_attachments_fiscal_user_info",$fiscal_user_info, $shipping_user_info));
			
			if($fiscal_user_info){
				if(!array_is_list($fiscal_user_info)){
					$fiscal_user_info = array($fiscal_user_info);
				}
				
				foreach($fiscal_user_info as $info){
					if(isset($info["info_views"])){
						if(isset($info["info_link"]) && in_array("pdf", $info["info_views"])){
							$filename = sanitize_file_name($info["name"]).".pdf";
							
							$tmppath = "";
							if(function_exists('wp_tempnam'))
								$tmppath = wp_tempnam( add_query_arg('pdf',1, $info["info_link"]));
							else 
								$tmppath = rtrim(get_temp_dir(),"/") . "/attachment" . rand(10000,99999) . ".pdf";
							
							$file_path = dirname($tmppath) . DIRECTORY_SEPARATOR . $filename;
							if(!file_exists($file_path)){
								$tmppath = download_url( add_query_arg('pdf',1, $info["info_link"]));
								@rename($tmppath, $file_path);
							}
							if(file_exists($file_path)){
								$attachments[] = $file_path;
								if(HPAY_DEBUG_TRACE)
									hpay_write_log("trace", array($order_id, "fiscal_email_attachment_added",basename($file_path)));
							}
						}
					}	
				}
			}
			
			if($shipping_user_info){
				if(!array_is_list($shipping_user_info)){
					$shipping_user_info = array($shipping_user_info);
				}
				foreach($shipping_user_info as $info){
					if(isset($info["info_views"])){
						if(isset($info["info_link"]) && in_array("pdf", $info["info_views"])){
							$filename = sanitize_file_name($info["name"]).".pdf";
							
							$tmppath = "";
							if(function_exists('wp_tempnam'))
								$tmppath = wp_tempnam( add_query_arg('pdf',1, $info["info_link"]));
							else 
								$tmppath = rtrim(get_temp_dir(),"/") . "/attachment" . rand(10000,99999) . ".pdf";
							
							$file_path = dirname($tmppath) . DIRECTORY_SEPARATOR . $filename;
							if(!file_exists($file_path)){
								$tmppath = download_url( add_query_arg('pdf',1, $info["info_link"]));
								@rename($tmppath, $file_path);
							}
							if(file_exists($file_path)){
								$attachments[] = $file_path;
								if(HPAY_DEBUG_TRACE)
									hpay_write_log("trace", array($order_id, "shipping_email_attachment_added",basename($file_path)));
							}
						}
					}
				}
			}
			
			return $attachments;
		}catch(Throwable $ex){
			hpay_write_log("error", $ex);
			return $attachments;
		}
	}
	
	public function add_email_headers( $headers, $email_id, $order ) {
		try{
			if(!is_a($order,"WC_Order"))
				return $headers;

			$email_cc  = "";
			$email_bcc = "";
			
			if($this->getSetting("billing_email_cc","") == 1){
				$order_id = $order->get_id();
				$email_cc = $order->get_meta("_billing_email_cc");
				if($email_cc){
					$email_cc = str_replace(" ","",$email_cc);
					$email_cc = str_replace(";",",",$email_cc);
					$email_cc = explode(",",$email_cc);
					$email_cc = implode(",",array_filter(array_map('trim',$email_cc),function($eml){
						if(strpos($eml,"@") !== false && strpos($eml,".") !== false){
							return true;
						}
						return false;
					}));
				}
			}
			
			if(strpos($this->getSetting("billing_email_bcc",""),"@") !== false){
				$email_bcc = $this->getSetting("billing_email_bcc","");
				if($email_bcc){
					$email_bcc = str_replace(" ","",$email_bcc);
					$email_bcc = str_replace(";",",",$email_bcc);
					$email_bcc = explode(",",$email_bcc);
					$email_bcc = implode(",",array_filter(array_map('trim',$email_bcc),function($eml){
						if(strpos($eml,"@") !== false && strpos($eml,".") !== false){
							return true;
						}
						return false;
					}));
				}
			}
			
			if($email_cc || $email_bcc){
				$h = explode("\r\n",$headers);
				foreach($h as $index => $hline){
					if(stripos($hline,"CC:") === 0 && $email_cc){
						if(strlen(trim($hline)) > 3){
							$hline .= ",";
						}
						$h[$index] = $hline . $email_cc;
						$email_cc = "";
					}else if(stripos($hline,"BCC:") === 0 && $email_bcc){
						if(strlen(trim($hline)) > 3){
							$hline .= ",";
						}
						$h[$index] = $hline . $email_bcc;
						$email_bcc = "";
					}
				}
				$headers = implode("\r\n",$h);
				
				if($email_cc){
					$headers .= "CC: " . $email_cc . "\r\n";
				}
				
				if($email_bcc){
					$headers .= "BCC: " . $email_bcc . "\r\n";
				}
			}
		}catch(Throwable $ex){
			hpay_write_log("error", $ex);
		}
		return $headers;
	}
};	