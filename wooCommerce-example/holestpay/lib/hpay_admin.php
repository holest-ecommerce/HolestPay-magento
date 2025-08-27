<?php
//HOLESTPAY 2023
if(!function_exists("add_action")){
	die("Direct access is not allowed");
};

if(!defined('HPAY_KNOWN_META_SKIP')){
	define('HPAY_KNOWN_META_SKIP',"'_edit_lock','_edit_last','_wp_page_template','_wp_page_template','_variation_description','_variation_image','_wp_attached_file','_product_attributes','_wp_attachment_metadata','_sku','total_sales','_tax_status','_tax_class','_manage_stock','_backorders','_sold_individually','_virtual','_downloadable','_download_limit','_download_expiry','_stock','_stock_status','_visibility','_wc_average_rating','_wc_review_count','_product_version','_sku','total_sales','_tax_status','_tax_class','_manage_stock','_backorders','_sold_individually','_virtual','_downloadable','_download_limit','_download_expiry','_stock','_stock_status','_wc_average_rating','_wc_review_count','_product_version','_thumbnail_id','_wp_old_slug','_wpcom_is_markdown'"); 
}

if(!defined('HPAY_KNOWN_METALIKE_SKIP')){
	define('HPAY_KNOWN_METALIKE_SKIP'," AND NOT meta_key LIKE '%_min_%' AND NOT meta_key LIKE '%_max_%' "); 
}

class HPay_Admin{
	public $core = null;
	
	public function __construct($core){
		$this->core = $core;
	}
	
	public function init(){
		add_filter( "plugin_action_links_{$this->core->plugin}", array($this,"settingsLinks") );
		add_action('admin_menu', array( $this, 'set_wp_admin_menu' ), 9); 
		if(is_admin()){
			add_action('admin_enqueue_scripts',array( $this, 'enqueue_scripts' )); 
		}else{
			add_action('wp_enqueue_scripts',array( $this, 'enqueue_scripts' )); 
		}
		
		add_action( 'wp_ajax_hpay-save-settings', array( $this, 'save_settings' ));
		add_action( 'wp_ajax_hpay-data-search', array( $this, 'data_search' ));
		add_action( 'wp_ajax_hpay-push-order', array( $this, 'push_order' ));
		add_action( 'wp_ajax_hpay-back-operations', array( $this, 'back_operations' ));
		
		add_action( 'woocommerce_admin_field_payment_gateways', array( $this, 'add_payment_gateways_setting' ),1 );
		add_action( 'woocommerce_shipping_zone_after_methods_table', array( $this, 'add_shipping_methods_setting' ),1,1 );
		add_filter( 'admin_body_class', array($this,"admin_body_class"),50,1);
		add_action( 'add_meta_boxes', array($this,'post_meta_boxes'), 30, 2);
		//add_action( 'save_post', array($this,'on_save_post'), 30, 2 );
		add_action( 'woocommerce_update_order', array($this,'on_woocommerce_update_order'), 30,2);
		
		if(hpay_read_get_parm("page","") == "holestpay-setup" || hpay_read_get_parm("page","") == "holestpay-main"){
			try{
				include_once( WP_PLUGIN_DIR . '/woocommerce/includes/abstracts/abstract-wc-session.php' );
				include_once( WP_PLUGIN_DIR . '/woocommerce/includes/wc-cart-functions.php' );
			}catch(Throwable $ex){
				echo '<!-- HPAY EX: ' . $ex->getMessage() . ' -->';
			}
			add_action('admin_init', array($this,'load_checkout_fields'));
		}
	}
	
	
	public function back_operations(){
		$json = file_get_contents('php://input');
		if($json){
			$json = json_decode($json, true);
		}
		
		$op = hpay_read_get_parm("operation","");
		if(!$op){
			if($json){
				wp_send_json(array("error" => "Not found", "error_code" => 404),404);	
			}else{
				wp_die("Not found","404 Not found", array("response" => 404));
			}
			return;
		}
		
		try{
			if($op == "send_mail"){
				if(isset($json["bodyb64"])){
					$json["body"] = base64_decode($json["bodyb64"]);
				}
				
				if(isset($json["body"])){
					try{
						$json["body"] = HPay_Core::instance()->prepare_email_content($json["body"]);
					}catch(Throwable $ex){
						hpay_write_log("error",$ex);
					}
				}
				
				if(isset($json["to"]) && strpos($json["to"],"@") !== false && isset($json["subject"]) && isset($json["body"])){
					$mail_sent = false;
					$headers = null;
					
					try{
							
						if(is_array($json["to"])){
							$json["to"] = implode(",",$json["to"]);
						}
						
						if(isset($json["cc"]) && $json["cc"]){
							if(is_array($json["cc"])){
								$json["cc"] = implode(",",$json["cc"]);
							}
							
							if(!$headers)
								$headers = "Content-Type: text/html\r\n";
							$headers .= "CC:{$json["cc"]}\r\n";
						}else if(isset($json["bbc"]) && $json["bbc"]){
							if(is_array($json["bcc"])){
								$json["bcc"] = implode(",",$json["bcc"]);
							}
							if(!$headers)
								$headers = "Content-Type: text/html\r\n";
							$headers .= "BCC:{$json["bcc"]}\r\n";
						}
						
						if(function_exists("WC")){
							$mailer  = WC()->mailer();
							if($mailer){
								$message = $mailer->wrap_message( $subject, $html );
								if($headers){
									$mail_sent = $mailer->send( $json["to"] , strip_tags( $json["subject"] ), $json["body"], $headers ) ? true : false;
								}else{
									$mail_sent = $mailer->send( $json["to"] , strip_tags( $json["subject"] ), $json["body"] ) ? true : false;
								}
							}
						}
						
						if(!$mail_sent){
							$mail_sent = wp_mail($json["to"], strip_tags( $json["subject"] ), $json["body"], $headers);
						}
						
						if($mail_sent){
							wp_send_json(array("success" => true),200);
							return;
						}
					}catch(Throwable $ex){
						hpay_write_log("error",$ex);
						wp_send_json(array("error" => $ex->getMessage(), "error_code" => 503),503);
						return;
					}
					wp_send_json(array("error" => "Mail Service Unavailable", "error_code" => 503),503);
				}else{
					wp_send_json(array("error" => "Not accepatable - insufficient input", "error_code" => 406),406);
				}
			}else if($op == "quick_order_form"){
				
				$id = intval($json["variant_id"] ? $json["variant_id"] : $json["product_id"]);
				
				$prod = wc_get_product($id);
				
				$buy_now_url = get_permalink($id);
				
				
				ob_start();
				?>
				<div class="hpay-quick-buy-panel">
				
					<div style="background: aliceblue;padding: 5px" class="hpay_quick_buy_toolbox">
						<label><?php echo esc_attr__("Send to:","holestpay"); ?></label><input style="width: 60%;" class='hpay_quick_buy_email' type="text" value="" />
						<label><?php echo esc_attr__("Quantity:","holestpay"); ?></label><input style="width: 50px;" class='hpay_quick_buy_qty' type="number" step="1" value="1" />
						
						<?php
							$attr_d = array();
							if(isset($json["variant_attributes"]) && !empty($json["variant_attributes"])){
								echo "<br><div class='hpay-quick-buy-panel-attributes'>";
								foreach($json["variant_attributes"] as $name => $a){
									if(isset($a["options"])){
										echo "<span>" . esc_attr(str_replace("attribute_","",$name)) .": <select name='" . esc_attr($name) ."'>";
										$first = false;
										foreach($a["options"] as $key => $text){
											echo "<option " . (!$first ? " selected='selected' " : "") . " value='" . esc_attr($key) . "'>" . esc_attr($text) . "</option>";
											if(!$first){
												$attr_d[] = "<span class='" . esc_attr($name) ."'>" . esc_attr(str_replace("attribute_","",$name)) . ": " . esc_attr($text)  . "</span>";
											}
											$first = true;
										}

										echo "</select></span> | ";	
									}else{
										echo "<span>" . esc_attr(str_replace("attribute_","",$name)) .": " . esc_attr($a["label"]) .  "<input type='hidden' name='" . esc_attr($name) ."' value='" .   esc_attr($a["value"])  . "' /></span> | ";	
										$attr_d[] = "<span class='" . esc_attr($name) ."'>" . esc_attr(str_replace("attribute_","",$name)) . ": ". esc_attr($a["label"])  . "</span>";
									}
								}
								echo "</div>";
							}	
						?>
						<br>
						<label><?php echo esc_attr__("Discount percent (using coupon)","holestpay"); ?></label><input style="width: 90px;" class='hpay_quick_buy_coupon' type="number" value="0.00" step="0.1" />%
						<label><?php echo esc_attr__("Discount validity days","holestpay"); ?></label><input style="width: 70px;" class='hpay_quick_buy_coupon_valid_days' type="number" value="7" step="1" />
					</div>
					
					<div class="hpay_quick_buy_message">
						<div contenteditable="true" style="text-align:left;padding:20px 5px;font-size:14px;background:white;">
						<p style="text-align:left;font-size:14px;"><?php echo esc_attr__("Hi,","holestpay"); ?></p>
						
						<?php
							$attr = "";
							
						?>
						
						<p style="text-align:left;font-size:14px;"><?php echo sprintf(esc_attr__("Here is the link you can use to directly make order for the product %s:","holestpay"),"<b><a target=_blank' href='%BASE_URL%'>" . esc_html($prod->get_title()) . " " . implode(", ",$attr_d) . "</a> <span class='hpay_qb_qty'></span></b>"); ?>
						
						</p>
						
						<?php
						try{
							$image = (isset($json["variant_image"]) && $json["variant_image"]) ? $json["variant_image"] : wp_get_attachment_image_src( $prod->get_image_id(), 'full' )[0];
							?>
							<img style="max-width:80px;" src="<?php  echo esc_url($image); ?>" />
							<?php
						}catch(Throwable $ex){
							//
						}
						?>
						<div class='hpay-qbuy-discount' style="font-weight:bold;"></div>
						
						<p style="text-align:left"><a target="_blank" href="%LINK%" style="background: #135e96;padding: 12px 25px;display:inline-block;font-size: 140%;font-weight: bold;color: white;border-radius: 5px;"><?php echo esc_attr__("Order Now","holestpay"); ?></a></p>
						<p style="text-align:left">[ <a target="_blank" href="%LINK%">%LINK%</a> ]</p>
						<p style="text-align:left">&nbsp;</p>
						
						<p style="text-align:left;font-size:14px;"><?php echo esc_attr__("You can load same url from your phone easily by scanning bellow QR code from your smarthone default camera app:","holestpay"); ?></p>
						<span class='hpay-qbuy-qr'></span>
						<p style="text-align:left">&nbsp;</p>
												
						<?php
						wc_get_template( 'order/order-details.php', array( 'order' => $order, "order_id" => $order_id ) );
						?>
						</div>
					</div>	
				
				</div>
				<?php
				$body = ob_get_clean();
				
				wp_send_json(array("title" => __("Direct purchase link offer for","holestpay") . " " . $prod->get_title(), "body" => $body, "link" => $buy_now_url),200);
				
			}else if($op == "get_user_vaults"){
				if(isset($json["customer_user_id"]) && intval($json["customer_user_id"])){
					$tokens = WC_Payment_Token_HPay::get_hpay_tokens(intval($json["customer_user_id"]), null);
					
					if(empty($tokens)){
						$tokens = array();
					}else{
						$v = array();
						foreach($tokens as $token){
							$v[] = array(
								"token_id" => $token->get_id(),
								"is_default" => $token->is_default(),
								"last_used" => $token->token_time(),
								"card_brand" => $token->vault_card_brand(),
								"card_umask" => $token->vault_card_umask(),
								"merchant_site_uid" => $token->merchant_site_uid(),
								"hpaymethodtype" => $token->hpaymethodtype(),
								"token_uid" => $token->vault_token_uid(),
								"scope" => $token->vault_scope(),
								"onlyforuser" => $token->vault_onlyforuser()
							);	
						}
						$tokens = $v;
					}
					
					wp_send_json(array("tokens" => $tokens),200);
				}else{
					wp_send_json(array("error" => "Not accepatable, customer user id not provided", "error_code" => 406),406);
				}
			}else{
				wp_send_json(array("error" => "Not accepatable", "error_code" => 406),406);
			}
		}catch(Throwable $mex){
			hpay_write_log("error",$mex);
		}				
	}
	
	public function load_checkout_fields(){
		try{
			if(!WC()->session) WC()->session  = new WC_Session_Handler;
			if(!WC()->customer) WC()->customer = new WC_Customer;
		    if(!WC()->cart){
				WC()->cart = new WC_Cart();
			}
		}catch(Throwable $ex){
			echo '<!-- HPAY EX: ' . $ex->getMessage() . ' -->';
		}
	}
	
	public function post_meta_boxes($screen_id, $post){
		$id = null;
		
		if(method_exists($post,"get_id")){
			$id = $post->get_id();
		}else{
			$id = @$post->ID;
		}	
		
		if(hpay_id_is_wc_order($id)){
			add_meta_box(
				'hpay_shop_order_meta_box',
				__( 'HolestPay', 'holestpay' ),
				array($this,'add_hpay_wc_shop_order_meta_box'),
				null,
				'normal',
				'default'
			);
		}
		
	}
	
	public function add_hpay_wc_shop_order_meta_box($post){
		$hide_box = true;
		
		$order_id = null;
		
		if(method_exists($post,"get_id")){
			$order_id = $post->get_id();
		}else{
			$order_id = @$post->ID;
		}	
		
		$order = hpay_get_order($order_id);
		
		if(!$order)
			return;
		
		$responses = HPay_Core::instance()->getHPayPayResponses($order);
		
		$pbl = false;
		if(!$order->is_paid() && !$order->has_status(array("completed","refunded","cancelled"))){
			$hpay_status = $order->get_meta("_hpay_status");
			if(!$hpay_status)
				$hpay_status = "";
			if(strpos($hpay_status,"PAID") === false && strpos($hpay_status,"SUCCESS") === false && strpos($hpay_status,"REFUNDED") === false){
				$pbl = true;
			}
		}
		
		if($pbl){
			$pay_now_url =  $order->get_checkout_payment_url() ;
			$ostatus = $order->get_status();
			?>
			<div class="hpay_pay_by_link" style="float:right;padding:8px 0;">
				<img src="<?php echo esc_attr__(HPAY_PLUGIN_URL .'/assets/icon-18.png'); ?>" /> 
				<?php
					if(strpos($ostatus,"draft")){
						?>
						<span class="button button-secondary"><?php echo esc_attr__("Save order to get 'Pay by link' link","holestpay"); ?></span>
						<?php
					}else{
						?>
						
						<button class="button button-primary cmd-hpay-pbl-dialog"><?php echo esc_attr__("Send 'Pay by link' link to the customer...","holestpay"); ?></button>
						<button class="button button-primary cmd-hpay-pbl-copy" title="<?php echo esc_attr__("Copy link to memory","holestpay"); ?>">📄</button>
						<?php
					}
				?>
			</div>
			<div class="hpay_pay_by_link_message_tmpl" style="display:none">
				<div>
				    <div style="background: aliceblue;padding: 5px"><label><?php echo esc_attr__("Send to:","holestpay"); ?></label><input style="width: 80%;" class='hpay_pay_by_link_email' type="text" value="<?php echo esc_attr($order->get_billing_email()); ?>" /></div>
					
					<div class="hpay_pay_by_link_message">
						<div contenteditable="true" style="text-align:left;padding:20px 5px;font-size:14px;background:white;">
						<p style="text-align:left;font-size:14px;"><?php echo esc_attr__("Hi,","holestpay"); ?></p>
						<p style="text-align:left;font-size:14px;"><?php echo sprintf(esc_attr__("Here is the link you can use to make payment for the order %s that we prepared for you:","holestpay"),"#".esc_html($order->get_order_number())); ?>
						</p>
						
						<p style="text-align:left"><a target="_blank" href="<?php echo esc_url($pay_now_url); ?>" style="background: #135e96;padding: 12px 25px;display:inline-block;font-size: 140%;font-weight: bold;color: white;border-radius: 5px;"><?php echo esc_attr__("Pay Now","holestpay"); ?></a></p>
						<p style="text-align:left">[ <a target="_blank" href="<?php echo esc_url($pay_now_url); ?>"><?php echo esc_url($pay_now_url); ?></a> ]</p>
						<p style="text-align:left">&nbsp;</p>
						<p style="text-align:left;font-size:14px;"><?php echo esc_attr__("You can load same url from your phone easily by scanning bellow QR code from your smarthone default camera app:","holestpay"); ?></p>
						<span class='hpay-qr'></span>
						<p style="text-align:left">&nbsp;</p>
												
						<?php
						wc_get_template( 'order/order-details.php', array( 'order' => $order, "order_id" => $order_id ) );
						?>
						</div>
					</div>	
				</div>
			</div>
			<script type="text/javascript">	
			
				jQuery(document).ready(function(){
					if(jQuery(".hpay_pay_by_link")[0] && !jQuery(".hpay_pay_by_link.aux")[0]){
						jQuery(".hpay_pay_by_link").clone().addClass("aux").insertAfter(".wc-customer-user:visible:first");
					}
				});
			
				jQuery(document).on("click",".cmd-hpay-pbl-dialog", function(e){
					e.preventDefault();
					let cnt = jQuery(".hpay_pay_by_link_message_tmpl > div").clone(false,false);
					
					if(window.__edit_pbl_cnt){
						cnt.find(".hpay_pay_by_link_message").html(window.__edit_pbl_cnt);
					}else{
						setTimeout(function(){
							hpay_require_script("qrcode.min.js").then(r => {
								try{
									qrcode.stringToBytes = qrcode.stringToBytesFuncs["UTF-8"];
									var qr = qrcode('0', 'Q');
									qr.addData(<?php echo json_encode($pay_now_url); ?>, 'Byte');
									qr.make();
									jQuery(".hpay-qr").html(qr.createImgTag());
								}catch(ex){
									
								}
							});
						},150);
					}
					
					cnt.find("th").css("text-align","left");
					let eml_inp = cnt.find(".hpay_pay_by_link_email");
					
					hpay_dialog_open("hpay-send-pbl","<?php echo esc_attr__("Send 'Pay by link' link to the customer...","holestpay"); ?>",cnt[0],"medium", {
						"<?php echo esc_attr__("Send","holestpay"); ?>": {
							Run: (dlg) => {
								
								if(!eml_inp.val().trim()){
									hpay_alert_dialog("<?php echo esc_attr__("Enter reciptient email!","holestpay"); ?>");
									return;
								}
								
								let emails = eml_inp.val().split(",").map(t=>t.trim()).filter(s=>!!s);
								
								if(emails.find(eml => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(eml))){
									hpay_alert_dialog("<?php echo esc_attr__("Enter valid email(s)!","holestpay"); ?>");
									return;
								}
								
								
								const encoder = new TextEncoder();
								const encodedBytes = encoder.encode(jQuery(".hpay-dialog-content .hpay_pay_by_link_message").html());
								let bodyb64 = btoa(String.fromCharCode(...encodedBytes));
								
								HPay.enterClientWait();
								fetch(addQueryArgs(HolestPayAdmin.ajax_url,{action: "hpay-back-operations", operation:"send_mail" }), {
									method:"POST",
									headers: {
										'Content-Type': 'application/json'
									},
									credentials: 'include',
									body: JSON.stringify({
										to: emails.join(","),
										subject: "<?php echo sprintf(esc_attr__("Payment link for order %s","holestpay"), esc_html($order->get_order_number()) ); ?>",
										bodyb64: bodyb64
									})
								}).then(r=>r.json()).then(r=>{
									HPay.exitClientWait();
									if(r && r.success){
										dlg.close();
										hpay_alert_dialog("<?php echo esc_attr__("Mail sent successfully!","holestpay"); ?>");
									}else{
										hpay_alert_dialog("<?php echo esc_attr__("Error: Mail not sent!","holestpay"); ?>");	
										console.error(r);
									}
								}).catch(err => {
									HPay.exitClientWait();
									console.error(err);
									hpay_alert_dialog("<?php echo esc_attr__("Error: Mail not sent!","holestpay"); ?>");
								});
							}
						}
					},() => {
						window.__edit_pbl_cnt = jQuery(".hpay-dialog-content .hpay_pay_by_link_message").html();
					});
				});
				
				jQuery(document).on("click",".cmd-hpay-pbl-copy", function(e){
					e.preventDefault();
					jQuery(".cmd-hpay-pbl-copy").css("background","yellow");
					setTimeout(function(){
						jQuery(".cmd-hpay-pbl-copy").css("background","");
					},300);
					hpay_copy_to_cliboard("<?php echo ($pay_now_url); ?>");
				});
			
			</script>
			<?php
		}
		
		if(!empty($responses) || $order->meta_exists("_hpay_status")){
			echo "<span order_details='1' class='hpay_store_order hpay-push' stored_uid='" . esc_attr($order->get_order_key()) . "' order_id=" . esc_attr($order_id) . " >&rarr;</span>";
			
			HPay_Core::instance()->woocommerce_saved_order_items($order, null);//IT WILL DO UPDATE OR STORE IF NEEDED
			?>
			<p class="hpay-manage-on-line" >
				<button class="button button-primary hpayopen" hpayopen="orders/Uid:<?php echo esc_attr($order->get_order_key());?>"><?php echo esc_attr__("Manage on HolestPay...","holestpay"); ?></button>
			</p>
			<div hpay_order_action_toolbox="<?php echo esc_attr($order->get_order_key());?>">
			<div class='hpay-await-loader'></div>	
			</div>
			<?php
		}else{
			echo "<span order_details='1' class='hpay_store_order hpay-push' order_id=" . esc_attr($order_id) . " >&rarr;</span>";
			?>
			<p class="hpay-manage-on-line" style='disply:none'>
				<button class="button button-primary hpayopen" hpayopen="orders/Uid:<?php echo esc_attr($order->get_order_key());?>"><?php echo esc_attr__("Manage on HolestPay...","holestpay"); ?></button>
			</p>
			<?php
		}
		
		$hpaymethod = null;
		if($order->get_payment_method()){
			$hpaymethod = HPay_Core::payment_method_instance($order->get_payment_method());
		}
		
		if($order_id && $order->get_customer_id()){
			$charge_methods = HPay_Core::payment_methods_supporting_operation("charge");
			if(!empty($charge_methods)){
				
				try{
					if(!class_exists('WC_Payment_Token_HPay'))
						require_once(__DIR__ . "/../class/class-wc-hpay-vault-token.php");
				}catch(Throwable $ex){
					hpay_write_log("error",$ex);	
				}
				
				if($order->get_status() == "pending"){
					$hpay_charge_after_ts = $order->get_meta("_hpay_charge_after_ts");
					if($hpay_charge_after_ts){
						try{
							$tokens = WC_Payment_Token_HPay::get_hpay_tokens($order->get_customer_id(), $hpaymethod);
							if(empty($tokens)){
								$hpay_charge_after_ts = null;
								$order->delete_meta_data("_hpay_charge_after_ts");
								$order->delete_meta_data("_hpay_charge_tries");
								$order->delete_meta_data("_hpay_charge_attempt_ts");
								$order->save_meta_data();
							}
						}catch(Throwable $ex){
							hpay_write_log("error",$ex);	
						}
						
						if(intval($hpay_charge_after_ts)){
							if(intval($hpay_charge_after_ts) > time()){
								$after_sec = intval($hpay_charge_after_ts) - time();
								$after_min = "<span countdown='{$after_sec}'>" . intval($after_sec / 60) . " min</span>";
								?>
								<p><b><?php echo sprintf(esc_attr__("First auto-charge attempt will be tried on this order beween 15 and 60 min after %s. You can run charge immediately. If you wish to change/remove/add something to order do it soon as possoble. If you want to prevent auto-charge change order status to something else than 'pending'.","holestpay"), $after_min ); ?></b></p>
								<?php
							}else{
								if(time() - intval($hpay_charge_after_ts) < 3600){
								?>
									<p><b><?php echo esc_attr__("Attempting the order change is planned for the next batch run. If you want to prevent it change order status to something else than 'pending'.","holestpay"); ?></b></p>
								<?php	
								}
							}
						}
					}
				}
				
				$hide_box = false;
				$vault_count = HPay_Core::instance()->displayUserVaults($order->get_customer_id(),$hpaymethod,false,
					!$order->is_paid() ? __( 'Charge customer for this order', 'holestpay' ) : __( 'Available user charge tokens', 'holestpay' ), false, $order->is_paid(),true);
					
				if(!$order->is_paid() && $vault_count){
					?>
					<input id="hpay_merchant_charge_token" style="display:none;" type='submit' name="hpay_merchant_charge_token" value="1" />
					<input id="hpay_merchant_charge_order_id" style="display:none;" type='hidden' name="hpay_merchant_charge_order_id" order_id="<?php echo intval($order_id); ?>" value="" />
					<input id="hpay_merchant_charge_installments" style="display:none;" type='hidden' name="hpay_merchant_charge_installments" value="" />
					<button class='button button-primary hpay_cmd_merchant_charge_customer'><?php echo sprintf(esc_attr__("Charge %s now","holestpay"), $order->get_total() . " " . hpay_get_wc_order_currency($order)); ?></button>
					<?php
				}
				?>
				<p <?php if($vault_count) echo " style='display:none' " ?> class='hpay_no_user_vaults'><?php echo esc_attr__("There are no customer vault payment tokens awailable for merchant initiated charges.","holestpay"); ?></p>
				<?php
			}
		}
		
		if(!empty($responses)){
			$hide_box = false;
			
			?>
				<h4><?php echo esc_attr__("Transaction log","holestpay"); ?>  <button id="hpay_cmd_show_tlog"><span class='hbutton-label'><?php echo esc_attr__("Show transactions","holestpay"); ?></span> <span class='hpaytcount'></span></button></h4>
				<div id="hpay_order_transaction_log" style="display:none">
					<?php
						$last_status = "------";
						$count = 0;
						foreach($responses as $index => $resp){
							try{
								
								if(!$resp || !is_array($resp))
									continue;
								
								$transaction_info = null;
								if(isset($resp["transaction_user_info"])){
									if($resp["transaction_user_info"]){
										$transaction_info = array("transaction_user_info" => $resp["transaction_user_info"]);
									}
								}
								
								if(!$transaction_info){
									if(isset($resp["gateway_resp"])){
										$transaction_info = array("transaction_user_info" => $resp["gateway_resp"]);
									}
								}
								$transaction_pay_status = "";
								if(isset($resp["status"])){
									
									if($last_status == $resp["status"]){
										if(isset($resp["hpay_operation"])){
											if($resp["hpay_operation"] == "store"){
												continue;
											}
										}
									}
								
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
									}else if(stripos($resp["status"],"REFUNDED") !== false){
										$transaction_pay_status =  "REFUNDED";
									}
									
									$transaction_info["pay_status"] = $resp["status"];
									$last_status = $resp["status"];
								}
								
								echo "<hr/>";
								
								HPay_Core::instance()->dispayOrderInfo($order_id , $transaction_info, $transaction_pay_status, null, "payment");
								$count++;
							
							}catch(Throwable $ex){
								echo "<!-- ";
								var_dump($ex);
								echo " -->";
								echo $ex->getMessage();
							}
						}
						
						
						try{
							HPay_Core::instance()->dispayOrderInfo($order_id , null, null, null, "fiscal");
						}catch(Throwable $ex){
							echo "<!-- ";
							var_dump($ex);
							echo " -->";
							echo $ex->getMessage();
						}
						
						try{
							HPay_Core::instance()->dispayOrderInfo($order_id , null, null, null, "shipping");
						}catch(Throwable $ex){
							echo "<!-- ";
							var_dump($ex);
							echo " -->";
							echo $ex->getMessage();
						}
						echo "<hr/>";
					?>
				</div>
				<script type="text/javascript">
					jQuery(".hpaytcount").html("<?php echo intval($count);?>");
				</script>
			<?php
		}
		
		$hide_box = false;
		
		?>
		<script type="text/javascript">
		
			window.addEventListener("message",function(event){
				if(event.origin && event.data && /pay\.holest\.com/.test(event.origin)){
					if(event.data.hpay_ost_event == "hpay-order-updated"){
						let order = event.data.data;
						
						if(order.Uid === "<?php echo $order->get_order_key(); ?>"){
							if(window.__for_reload)
								return;
							
							window.__for_reload = true;
							
							if(typeof _orders === 'undefined')
								return;
							
							if(_orders && _orders["<?php echo $order->get_order_key(); ?>"]){
								setTimeout(function(){
									window.location.reload();	
								},1000)
							}
						}
					}
				}
			},false);
		
			jQuery(document).on("click", "#hpay_cmd_show_tlog", function(e){
				e.preventDefault();
				if(jQuery("#hpay_order_transaction_log:visible")[0]){
					jQuery("#hpay_order_transaction_log").hide();
					jQuery(this).find("span.hbutton-label").html("<?php echo esc_attr__("Show transactions","holestpay"); ?>");
				}else{
					jQuery("#hpay_order_transaction_log").show();
					jQuery(this).find("span.hbutton-label").html("<?php echo esc_attr__("Hide transactions","holestpay"); ?>");
				}
			});
			
			jQuery(document).on("click", ".hpay-destroy-vault", function(e){
				e.preventDefault();
				let item = jQuery(this).closest("*[token_id]");
				let token_id = item.attr("token_id");
				
				if(confirm("<?php echo esc_attr__("Remove customer payment token vault reference? If you lose this vault reference you will no longer be able to charge the client by it.","holestpay"); ?>")){
					fetch("<?php echo add_query_arg( array('action' => "hpay-user-operations", "operation" => "destroy_vault"),admin_url('admin-ajax.php')); ?>",{
						method:"POST",
						headers:{
								"Content-Type":"application/json"
						},
						body: JSON.stringify({token_id: parseInt(token_id)})
					}).then(rawr => rawr.json()).then(resp => {
						
						if(resp && resp.result == "ok"){
							
							item.remove();
							
							if(!jQuery(".hpay-vault-tokens .hpay-vault")[0]){
								jQuery(".hpay_no_user_vaults").show();
								jQuery(".hpay_cmd_merchant_charge_customer,#hpay_merchant_charge_token,#hpay_merchant_charge_installments").remove();
							}
							
						}else{
							throw "__BAD__";
						}
						
					}).catch(err => {
						alert("<?php echo esc_attr__("Could not delete user vault payment token!","holestpay"); ?>");	
					});
				}
			});
			
			jQuery(document).on("click", ".hpay-set-default-vault", function(e){
				e.preventDefault();
				let item = jQuery(this).closest("*[token_id]");
				let token_id = item.attr("token_id");
				fetch("<?php echo add_query_arg( array('action' => "hpay-user-operations", "operation" => "default_vault"),admin_url('admin-ajax.php')); ?>",{
					method:"POST",
					headers:{
							"Content-Type":"application/json"
					},
					body: JSON.stringify({token_id: parseInt(token_id)})
				}).then(rawr => rawr.json()).then(resp => {
					if(resp && resp.result == "ok"){
						jQuery(".hpay-detafult-vault").removeClass('hpay-detafult-vault');
						jQuery(".hpay-vault-tokens *[token_id='" + token_id + "']").addClass('hpay-detafult-vault')
					}else{
						throw "__BAD__";
					}
				}).catch(err => {
					alert("<?php echo esc_attr__("Could not set default user vault payment token!","holestpay"); ?>");	
				});
			});
			
			jQuery(document).on("click", ".hpay_cmd_merchant_charge_customer", function(e){
				e.preventDefault();
				e.stopImmediatePropagation();
				
				if(!jQuery("input[name='hpay_vault_token_id']:checked")[0]){
					alert("<?php echo esc_attr__("Please select a customer vault payment token reference!","holestpay"); ?>");
					return;	
				}
				
				let vault_li = jQuery("input[name='hpay_vault_token_id']:checked").closest(".hpay-vault"); 
				jQuery("#hpay_merchant_charge_order_id").val(jQuery("#hpay_merchant_charge_order_id").attr("order_id"));
				jQuery("#hpay_merchant_charge_token").val(vault_li.find("input[name='hpay_vault_token_id']:checked").val());
				
				let installments = "";
				if(vault_li.find(".hpay-vault-installment select")[0]){
					if(vault_li.find(".hpay-vault-installment select").val()){
						installments = vault_li.find(".hpay-vault-installment select").val();
					}
				}
				jQuery("#hpay_merchant_charge_installments").val(installments);
				
				jQuery("#hpay_merchant_charge_token").trigger("click");
			});
			
			<?php if($hide_box){ ?>
				jQuery(document).ready(function(){
					jQuery("#hpay_shop_order_meta_box").hide();	
				});
			<?php } ?>
		</script>
		<?php
	}
	
	public function chargeOrder($order, $usetoken = null, $hpay_method_id = null){
		global $__chargeOrder_called;
		if(!isset($__chargeOrder_called))
			$__chargeOrder_called = array();
		
		if(isset($__chargeOrder_called[$order->get_id()])){
			return array("skip" => 1, "error" => __("HPAY Charge aleady called for order","holestpay"));
		}
		
		$__chargeOrder_called[$order->get_id()] = true;
		
		if(!$order){
			return array("error" => __("HPAY Charge: No order for charge provided","holestpay"));
		}
		
		if(!is_a($order,"WC_Order")){
			return array("error" => __("HPAY Charge: non WC_Order object given for charge","holestpay"));
		}
		
		if(hpay_woo_order_type($order) != "shop_order"){
			return array("error" => __("HPAY Charge: non WC_Order object given for charge","holestpay"));
		}
		
		if($order->is_paid()){
			return array("error" => __("HPAY Charge: Order is already paid","holestpay"));
		}
		
		if(!$order->get_customer_id()){
			return array("error" => __("HPAY Charge: Order has no customer set","holestpay"));
		}
		
		$tokens = array();
		if($usetoken){
			$tokens = array($usetoken);
		}else{
			$user_tokens = WC_Payment_Token_HPay::get_hpay_tokens($order->get_customer_id());
			foreach($user_tokens as $tok){
				if($tok->is_default()){
					$tokens = array_merge(array($tok),$tokens);
				}else{
					$tokens[] = $tok;
				}
			}
		}	
		
		if(empty($tokens)){
			return array("error" => __("HPAY Charge: No usable payment token found","holestpay"));
		}
		
		$last_failed = null;
		$last_success = null;
		
		$method_found = false;
		foreach($tokens as $token){
			$hmethods = HPay_Core::payment_methods_of_type($token->hpaymethodtype());
			if(!empty($hmethods)){
				
				foreach($hmethods as $hmethod){
					try{
						if(!$hmethod)
							continue;
						
						if($hpay_method_id){
							if($hmethod->hpay_id != $hpay_method_id){
								continue;
							}
						}
						
						$method_found = true;
						$pay_request = HPay_Core::instance()->generateHPayRequest($order, $hmethod->id, 'required', $token->vault_token_uid());
						$resp = HPay_Core::instance()->charge($pay_request, $pay_request["payment_method"], $pay_request["vault_token_uid"]);
						
						if($resp){
							if(isset($resp["status"]) && isset($resp["transaction_uid"])){
								
								$hmethod->acceptResult($order, $resp);
								
								if(strpos($resp["status"],"SUCCESS") !== false || strpos($resp["status"],"PAID") !== false || strpos($resp["status"],"RESERVED") !== false || strpos($resp["status"], "AWAITING") !== false){
									$last_success = $resp;
									break;
								}else{
									$last_failed  = $resp;
								}
							}
						}
					}catch(Throwable $ex){
						hpay_write_log("error",$ex);
						$order->add_order_note( __('HPAY Charge Error:', 'holestpay') . " " . $ex->getMessage());
					}
				}
				if($last_success){
					break;
				}
			}
		}
		
		if(!$method_found){
			return array("error" => __("HPAY Charge: No usable payment method found","holestpay"));
		}
		
		if($last_success){
			return $last_success;
		}else if($last_failed){
			return $last_failed;
		}else{
			return array("error" => __("HPAY Charge: charge failed","holestpay"));
		}
	}
	
	public function on_woocommerce_update_order($order_id, $order){
		global $__on_woocommerce_update_order_called;
		if(!isset($__on_woocommerce_update_order_called))
			$__on_woocommerce_update_order_called = array();
		
		if(isset($__on_woocommerce_update_order_called[$order_id])){
			return;
		}
		
		$__on_woocommerce_update_order_called[$order_id] = true;
		
		if(hpay_read_post_parm("hpay_vault_token_id","") && 
		   hpay_read_post_parm("hpay_merchant_charge_token","") && 
		   hpay_read_post_parm("hpay_merchant_charge_order_id","") == $order_id && 
		   hpay_read_post_parm("hpay_vault_token_id","") == hpay_read_post_parm("hpay_merchant_charge_token","")){
			$token = WC_Payment_Tokens::get(intval(hpay_read_post_parm("hpay_merchant_charge_token","")));
			if($token){
				if($order){
					if($order->get_customer_id() && !$order->is_paid()){
						$res = $this->chargeOrder($order, $token);
						if($res){
							if(isset($res["error"])){
								$order->add_order_note($res["error"]);
							}
						}
						return;
					}
				}
			}
		}
	}
	
	public function admin_body_class($classes){
		if(hpay_read_request_parm("page") == "wc-settings" && hpay_read_request_parm("tab") == "checkout" && strpos(hpay_read_request_parm("section",""),"hpaypayment-") === 0){
			if(is_string($classes)){
				$classes .= " hpay-wc-method-setup-page";
			}else if(is_array($classes)){
				$classes[] = "hpay-wc-method-setup-page";
			}
		}
		return $classes;
	}
	
	public function push_order(){
		if(hpay_read_request_parm("order_id","")){
			$with_status = null;
			if(hpay_read_request_parm("set_hpay_pay_status","")){
				$with_status = hpay_read_request_parm("set_hpay_pay_status","");
			}
			$resp = HPay_Core::instance()->store_order(intval(hpay_read_request_parm("order_id","")), $with_status);
			wp_send_json($resp);
		}else{
			wp_send_json(array("error" => "Not accepatable - bad request format.", "error_code" => 406),406);	
		}
	}
	
	public function data_search(){
		$data = json_decode( file_get_contents('php://input'), true);
		if(!isset($data["nonce"])){
			wp_send_json(array("error" => "Bad authorisation", "error_code" => 401),401);
		}else if ( ! wp_verify_nonce( $data["nonce"], 'hpay-ajax-nonce' ) ) {
			wp_send_json(array("error" => "Unauthorised", "error_code" => 401),401);
		} else {
			if(isset($data["what"])){
				global $wpdb;
				if($data["what"] == "posts"){
					if(!isset($data["search"]) || !$data["search"]){
						wp_send_json(array("result" => new stdClass),200);
					}else{
						$post_types = "";
						if(isset($data["post_types"])){
							if($data["post_types"]){
								if(is_array($data["post_types"])){
									if(!empty($data["post_types"])){
										$post_types = " AND post_type IN ('" .implode("','", $data["post_types"]). "')";
									}
								}else{
									$post_types = " AND post_type = '{$data["post_types"]}' ";
								}
							}
						}
						
						$q = $wpdb->prepare("SELECT ID as post_id, post_parent, post_title, post_type, post_date, post_name FROM {$wpdb->prefix}posts WHERE post_type != 'attachment' AND post_parent = 0 AND (ID = %s OR post_title LIKE %s) {$post_types} LIMIT 0,8",$data["search"],"%{$data["search"]}%");
						$result = $wpdb->get_results($q, OBJECT_K);
						
						foreach($result as $key => $res){
							$q = $wpdb->prepare("SELECT ID as post_id, post_parent, post_title, post_type, post_date, post_name FROM {$wpdb->prefix}posts WHERE post_parent = %d AND post_type != 'attachment' {$post_types}", $res->post_id);
							$result[$key]->children = $wpdb->get_results($q, OBJECT_K);
						}
						
						wp_send_json(array("result" => $result),200);	
					}
				}else if($data["what"] == "post_rels_and_meta"){
					if(!isset($data["post_id"]) || !$data["post_id"]){
						wp_send_json(array("result" => new stdClass),200);
					}else{
						
						if(!isset($data["post_parent"])){
							$data["post_parent"] = 0;
						}
						
						$q = $wpdb->prepare("SELECT post_id, meta_key, meta_value, {$wpdb->prefix}posts.post_type as post_type FROM {$wpdb->prefix}postmeta LEFT JOIN {$wpdb->prefix}posts ON {$wpdb->prefix}posts.ID = {$wpdb->prefix}postmeta.post_id WHERE (post_id = %d OR (post_id != 0 AND post_id = %d)) AND NOT meta_key IN (" . HPAY_KNOWN_META_SKIP . ") " . HPAY_KNOWN_METALIKE_SKIP . " ORDER BY meta_id DESC", $data["post_id"] , $data["post_parent"]);
						$metas = $wpdb->get_results($q);
						
						$result = array();
						
						$check_rels = array();
						
						$ptype = false;
						$pptype = false;
						
						
						foreach($metas as $meta){
							$ffiled = null;
							if($meta->post_id == $data["post_parent"]){
								
								if(!$pptype && $meta->post_type){
									$pptype = true;
									$result[] = array(
										"field_relation" => "@parentposttype",
										"value" => $meta->post_type
									);
								}
								
								$ffiled = array(
									"field_relation" => "@parent.meta." . $meta->meta_key,
									"value" => $meta->meta_value
								);
								if(intval($meta->meta_value) && ctype_digit($meta->meta_value)){
									if(substr($meta->meta_key,-5) !== "price" && substr($meta->meta_key,-5) !== "amount" && stripos($meta->meta_key,"_is_") === false && stripos($meta->meta_key,"enable") === false && stripos($meta->meta_key,"limit") === false)
										$check_rels["@parentmetarel." . $meta->meta_key] = $meta->meta_value;
								}
							}else{
								
								if(!$ptype && $meta->post_type){
									$ptype = true;
									$result[] = array(
										"field_relation" => "@posttype",
										"value" => $meta->post_type
									);
								}
								
								$ffiled = array(
									"field_relation" => "@meta." . $meta->meta_key,
									"value" => $meta->meta_value
								);
								if(intval($meta->meta_value) && ctype_digit($meta->meta_value)){
									if(substr($meta->meta_key,-5) !== "price" && substr($meta->meta_key,-5) !== "amount" && stripos($meta->meta_key,"_is_") === false && stripos($meta->meta_key,"enable") === false && stripos($meta->meta_key,"limit") === false)
										$check_rels["@metarel." . $meta->meta_key] = $meta->meta_value;
								}
							}
							$result[] = $ffiled;
						}
						
						$rids = array_unique(array_values($check_rels));
						
						if(!empty($rids)){
							$q = $wpdb->prepare("SELECT post_id, meta_key, meta_value, {$wpdb->prefix}posts.post_type as post_type FROM {$wpdb->prefix}postmeta LEFT JOIN {$wpdb->prefix}posts ON {$wpdb->prefix}posts.ID = {$wpdb->prefix}postmeta.post_id WHERE post_id IN (" . implode(",",$rids) . ") AND NOT {$wpdb->prefix}posts.ID IS NULL AND NOT meta_key IN (" . HPAY_KNOWN_META_SKIP . ") " . HPAY_KNOWN_METALIKE_SKIP . " ORDER BY meta_id DESC");
							$metas = $wpdb->get_results($q);
							if(!empty($metas)){
								$crpt = array();
								foreach($metas as $meta){
									foreach($check_rels as $rel => $post_id){
										if($meta->post_id == $post_id){
											
											if(!isset($crpt[$rel]) && $meta->post_type){
												$crpt[$rel] = true;
												$result[] = array(
													"field_relation" => $rel . ".post_type",
													"value" => $meta->post_type
												);
											}
											
											$ffiled = array(
												"field_relation" => $rel . "." . $meta->meta_key,
												"value" => $meta->meta_value
											);
											$result[] = $ffiled;
										}
									}
								}
							}
						}
						
						$q = $wpdb->prepare("SELECT post_id, meta_key, {$wpdb->prefix}posts.post_type as post_type FROM {$wpdb->prefix}postmeta LEFT JOIN {$wpdb->prefix}posts ON {$wpdb->prefix}posts.ID = {$wpdb->prefix}postmeta.post_id  WHERE meta_value = %d AND NOT {$wpdb->prefix}posts.ID IS NULL AND NOT meta_key LIKE '%price' AND NOT meta_key LIKE '%amount' AND NOT meta_key IN (" . HPAY_KNOWN_META_SKIP . ") " . HPAY_KNOWN_METALIKE_SKIP . "", $data["post_id"]);
						$revmetaposts = $wpdb->get_results($q, OBJECT_K);
						
						$parentrevmetaposts = array();
						
						if(intval($data["post_parent"])){	
							$q = $wpdb->prepare("SELECT post_id, meta_key, {$wpdb->prefix}posts.post_type as post_type FROM {$wpdb->prefix}postmeta LEFT JOIN {$wpdb->prefix}posts ON {$wpdb->prefix}posts.ID = {$wpdb->prefix}postmeta.post_id  WHERE meta_value = %d AND NOT {$wpdb->prefix}posts.ID IS NULL AND NOT meta_key LIKE '%price' AND NOT meta_key LIKE '%amount' AND NOT meta_key IN (" . HPAY_KNOWN_META_SKIP . ") " . HPAY_KNOWN_METALIKE_SKIP . "", $data["post_parent"]);
							$parentrevmetaposts = $wpdb->get_results($q, OBJECT_K);
						}
						
						if(!empty($revmetaposts)){
							$q = $wpdb->prepare("SELECT post_id, meta_key, meta_value FROM {$wpdb->prefix}postmeta WHERE post_id IN (" . implode(",",array_keys($revmetaposts)) . ") AND NOT meta_key IN (" . HPAY_KNOWN_META_SKIP . ") " . HPAY_KNOWN_METALIKE_SKIP . " ORDER BY meta_id DESC");
							$metas = $wpdb->get_results($q);
							
							foreach($metas as $meta){
								if(!isset($revmetaposts[$meta->post_id]->pt)){
									$revmetaposts[$meta->post_id]->pt = true;
									$result[] = array(
										"field_relation" => "@revmetarel." . $revmetaposts[$meta->post_id]->meta_key . ".post_type",
										"value" => $meta->post_type
									);
								}
								$result[] = array(
									"field_relation" => "@revmetarel." . $revmetaposts[$meta->post_id]->meta_key . "." . $meta->meta_key,
									"value" => $meta->meta_value
								);
							}
						}
						
						if(!empty($parentrevmetaposts)){
							$q = $wpdb->prepare("SELECT post_id, meta_key, meta_value FROM {$wpdb->prefix}postmeta WHERE post_id IN (" . implode(",",array_keys($parentrevmetaposts)) . ") AND NOT meta_key IN (" . HPAY_KNOWN_META_SKIP . ") " . HPAY_KNOWN_METALIKE_SKIP . " ORDER BY meta_id DESC");
							$metas = $wpdb->get_results($q);
							
							foreach($metas as $meta){
								if(!isset($parentrevmetaposts[$meta->post_id]->pt)){
									$parentrevmetaposts[$meta->post_id]->pt = true;
									$result[] = array(
										"field_relation" => "@revmetarel." . $parentrevmetaposts[$meta->post_id]->meta_key . ".post_type",
										"value" => $meta->post_type
									);
								}
								
								$result[] = array(
									"field_relation" => "@parentrevmetarel." . $parentrevmetaposts[$meta->post_id]->meta_key . "." . $meta->meta_key,
									"value" => $meta->meta_value
								);
								
							}
						}
						wp_send_json(array("result" => array_filter($result,function($item){ return $item["value"] !== "" && $item["value"] !== null && strpos($item["value"],"a:") !== 0 && strpos($item["value"],"{") !== 0  && strpos($item["value"],"[") !== 0;}) , "q" => $q, "m" => get_registered_meta_keys('product')),200);
					}
				}else{
					wp_send_json(array("error" => "Not accepatable - bad request format.", "error_code" => 406),406);		
				}
			}else{
				wp_send_json(array("error" => "Not accepatable - bad request format.", "error_code" => 406),406);		
			}
		}
	}
	
	public function save_settings($settings = null){
		$data = json_decode( file_get_contents('php://input'), true);
		
		if(!isset($data["nonce"])){
			wp_send_json(array("error" => "Bad authorisation", "error_code" => 401),401);
		}else if ( ! wp_verify_nonce( $data["nonce"], 'hpay-ajax-nonce' ) ) {
			wp_send_json(array("error" => "Unauthorised", "error_code" => 401),401);
		} else {
			if(isset($data["settings"])){
				
				$updated = $this->core->updateSettings($data["settings"]);
				if($updated){
					wp_send_json(array("updated" => "full"),200);	
				}else{
					wp_send_json(array("error" => "Not accepatable - not updated", "error_code" => 406),406);
				}
				
			}else if(isset($data["update_settings"])){
				
				$current = $this->core->getSettings();
				$settings = array_merge($current,$data["update_settings"]);
				
				$updated = $this->core->updateSettings($settings);
				if($updated){
					wp_send_json(array("updated" => "partial"),200);	
				}else{
					wp_send_json(array("error" => "Not accepatable", "error_code" => 406),406);
				}
				
			}else{
				wp_send_json(array("error" => "Not accepatable - bad request format.", "error_code" => 406),406);
			}
		}
	}
	
	public function enqueue_scripts(){
		$pdata = $this->core->getPluginData();
		
		
		wp_enqueue_script( 'select2' );
		wp_register_style( 'select2css', '/wp-content/plugins/woocommerce/assets/css/select2.css', false, '1.0', 'all' );
		wp_enqueue_style( 'select2css' );
		
		wp_enqueue_style( 'hpay_wpadmin_css', $this->core->plugin_url . '/assets/admin.css', array(), $pdata["Version"] );
		wp_enqueue_script( 'hpay_wpadmin_js', $this->core->plugin_url . '/assets/admin.js', array("jquery"), $pdata["Version"] );
		
		
		if(function_exists("WC")){
			wp_enqueue_script( 'hpay_wcadmin_js', $this->core->plugin_url . '/assets/admin_wc.js', array("jquery","hpay_wpadmin_js"), $pdata["Version"], true);
		}
		
		$site_url = rtrim(str_ireplace(array("https://","http://","/index.php"),"",get_site_url()),"/");
		$data = array(
			'settings'   => $this->core->getSettings(),
			'site_url'   => $site_url,
			'notify_url' => admin_url('admin-ajax.php') . "?action=hpay-webhook",
			'plugin_url' => plugin_dir_url(HPAY_PLUGIN_FILE),
			'labels'   => array(
				'noncontactable'            => esc_attr__("Can not contact HolestPay. Maybe popup blocker prevents connection dialog - if you saw no pupup appear then please check right side of browser address bar for warning!","holestpay"),
				'error_saving_settings'     => esc_attr__("Error saving settings","holestpay"),
				'disconnect_question'       => esc_attr__("Disconnect HolestPay?","holestpay"),
				"coding_ref"                => esc_attr__("Hooks/coding reference","holestpay"),
				"smart_subscriptions_setup" => esc_attr__("Smart custom plugins product subscription detection setup","holestpay"),
				"back"                      => esc_attr__("Back","holestpay"),
				"next"                      => esc_attr__("Next","holestpay"),
				"finish"                    => esc_attr__("Finish","holestpay"),
				"remove"                    => esc_attr__("Remove","holestpay"),
				"required_missing"          => esc_attr__("Please set all required fields!","holestpay"),
				"custom_integration"        => esc_attr__("Custom integration","holestpay"),
				"remove_custom_integration" => esc_attr__("Remove custom integration?","holestpay"),
				"https_required"            => esc_attr__("It is required to use HTTPS on sites that process payments!","holestpay"),
				"manage_on_hpay"            => esc_attr__("Manage on HolestPay","holestpay"),
				"push_to_hpay"              => esc_attr__("This order is not stored on HPay. Push it now so you could manage it from HPay?","holestpay"),
				"push_update_to_hpay"       => esc_attr__("Push order data to HPay?","holestpay"),
				"error_in_operation"        => esc_attr__("Error during operation!","holestpay"),
				"yes"                       => esc_attr__("Yes","holestpay"),
				"no"                        => esc_attr__("No","holestpay"),
				"cancel"                    => esc_attr__("Cancel","holestpay"),
				"with_pay_status_change"    => esc_attr__("With payment status change","holestpay"),
				"send"                      => esc_attr__("Send","holestpay"),
				"discountp_qbuy"            => esc_attr__("Discount of %s% is applied to this offer link.","holestpay"),
				"discountp_qbuy_vdays"      => esc_attr__("This offer in link is limited to %s days.","holestpay"),
				"enter_valid_email"      	=> esc_attr__("Please enter valid email!","holestpay"),
				"mail_sent"                 => esc_attr__("Mail sent successfully","holestpay"),
				"mail_not_sent"             => esc_attr__("ERROR: mail sending failed","holestpay")
			),
			"nonce" => wp_create_nonce('hpay-ajax-nonce'),
            "ajax_url" => admin_url('admin-ajax.php'),
			"language" => get_locale(),
			"hpaylang" =>  HPay_Core::hpaylang(),
			"plugin_version"  => $pdata["Version"]	
		);
		
		if(function_exists('wc_get_order_statuses')){
			$woo_statuses = wc_get_order_statuses();
			
			foreach($woo_statuses as $status => $name){
				$data['labels'][$status] = $name;
			}
		}
		
		wp_localize_script( 'hpay_wpadmin_js', 'HolestPayAdmin', $data );
		
		if(!is_admin()){
			$this->core->front()->enqueue_scripts(true);
		}
	}
	
	public function add_shipping_methods_setting($zone){
		?>
		<button hpayrefreshpagescope="<?php echo esc_attr("shippingmethods"); ?>" hpayopen="shippingmethods" id="cmdShippingMethodsHpay"  class='button button-primary'><img src="<?php echo esc_attr__(HPAY_PLUGIN_URL .'/assets/icon-18.png'); ?>" /> <?php echo esc_attr__("Manage HolestPay shiping methods...","holestpay"); ?></button>
		<hr/>
		<?php
	}
	
	public function add_payment_gateways_setting(){
		?>
		<button hpayrefreshpagescope="<?php echo esc_attr("paymentmethods"); ?>" hpayopen="paymentmethods" id="cmdPaymentMethodsHpay"  class='button button-primary'><img src="<?php echo esc_attr__(HPAY_PLUGIN_URL .'/assets/icon-18.png'); ?>" /> <?php echo esc_attr__("Manage HolestPay payment methods...","holestpay"); ?></button>
		<hr/>
		<?php
	}
	
	public function set_wp_admin_menu(){
		
		add_menu_page(  __("HolestPay","holestpay"), __("HolestPay","holestpay"), 'administrator', $this->core->plugin_name . "-main", array( $this, 'settingsPage' ), HPAY_PLUGIN_URL .'/assets/icon-18.png', 99 );
		add_submenu_page( $this->core->plugin_name . "-main", __("Setup","holestpay"), __("Setup","holestpay"), 'administrator', $this->core->plugin_name . "-setup", array( $this, 'settingsPage' ), 99 );
		
	}
	
	public function settingsLinks($links){
		$links[] = '<a href="'.admin_url( 'admin.php?page=holestpay-setup' ).'">' . __("HPay Site Setup...","holestpay") .  '</a>';
		return $links;
	}
	
	public function settingsPage(){
		
		$checkout_fields = array();
		$checkout_fields_billing = array();
		
		try{
			if(function_exists("WC")){
				$chk = new WC_Checkout;
				if(isset($chk->checkout_fields)){
					$checkout_fields = $chk->checkout_fields;
					if(isset($checkout_fields["billing"])){
						$checkout_fields_billing = $checkout_fields["billing"];
					}else{
						echo '<!-- HPAY EX: WC()->checkout->checkout_fields["billing"] not set -->';
					}
				}else{
					echo '<!-- HPAY EX: WC()->checkout->checkout_fields not set -->';
				}
			}
		}catch(Throwable $ex){
			echo '<!-- HPAY EX: ' . $ex->getMessage() . ' -->';
		}
		
		?>
		<div id="hpay_settings_page" locale="<?php echo esc_attr(HPay_Core::hpaylang()); ?>" style='background-image:url(<?php echo esc_attr(HPAY_PLUGIN_URL .'/assets/logo.png')?>)'>
		   <h2>HolestPay</h2>
		   <p class='hpay_pci_dss'><a target="_blank" href="https://www.pcisecuritystandards.org/"><?php echo esc_attr__("PCI DSS COMPLIANT PAYMENT SERVICE PRIVIDER","holestpay"); ?></a> - <?php echo esc_attr__("you don't need to perform quarter penetration testings, fill up SAQ questionnaires or adapt server to be PCI DSS complient merchant - all this is transfered/handled for you by HolestPay","holestpay"); ?></p>
		   <p class='flex-column' label="<?php echo esc_attr__("HolestPay environment","holestpay"); ?>">
			    <select name="hpay_environment">
					<option value='sandbox'><?php echo esc_attr__("Sandbox / Testing","holestpay"); ?></option>
					<option value='production'><?php echo esc_attr__("Production / Live","holestpay"); ?></option>
				</select>
				
				<p class='only-when-disconnected hpay-connect-popup-message'><?php echo esc_attr__("IMPORTANT: Please un-block connect popup in you browser address bar if 'blocked' notification appears when you click on 'Connect' button!","holestpay"); ?></p>
				
				<span id="hpay_connection">
					<button id="cmdConnectHpay" class='button button-primary'><?php echo esc_attr__("Connect","holestpay"); ?></button>
					<button id="cmdDisconnectHpay" class='button button-secondary'><?php echo esc_attr__("Disconnect","holestpay"); ?></button>
					<span id="hpay_connection_info"></span>
					<a hpayopen="app" target="_blank" id="cmdOpenHpay" class='button button-primary'><img src="<?php echo esc_attr__(HPAY_PLUGIN_URL .'/assets/icon-18.png'); ?>" /> <?php echo esc_attr__("Open HolestPay panel...","holestpay"); ?></a>
				</span>
		   </p>
		   
		   <div id="hpay_panel">
		      <div id="hpay_panel_menu">
					<hr/>
					
					<button hpayopen="companydetails/company_id" id="cmdCompanyHpay" class='button button-primary'><?php echo esc_attr__("Edit company info","holestpay"); ?></button>
					<button hpayopen="sitedetails/site_id" id="cmdSiteHpay" class='button button-primary'><?php echo esc_attr__("Edit site info","holestpay"); ?></button>
					<button hpayopen="paymentmethods" id="cmdPaymentMethodsHpay"  class='button button-primary'><?php echo esc_attr__("Manage payment methods","holestpay"); ?></button>
					<button hpayopen="shippingmethods" id="cmdShippingMethodsHpay" class='button button-primary'><?php echo esc_attr__("Manage shipping methods","holestpay"); ?></button>
					<button hpayopen="fiscalmethods" id="cmdFiscalMethodsHpay" class='button button-primary'><?php echo esc_attr__("Manage fiscal/e-invoice/integration methods","holestpay"); ?></button>
					
					<hr/>
			  </div>
			  <div id="hpay_panel_content"> 
					
		      </div>	
		   </div>
		   
		   <div id="hpay_site_panel">
		      <div id="hpay_site_panel_menu">
					
			  </div>
			  <div id="hpay_site_panel_content"> 
					<h4><?php echo esc_attr__("Local site settings","holestpay"); ?>:</h4>
					
					<div id="hpay_local_site_settings">
					
						<p label="<?php echo esc_attr__("Enable on frontend","holestpay"); ?>" class='hpay-important'>
							<select name="hpay_enabled">
								<option value='1'><?php echo esc_attr__("Yes","holestpay"); ?></option>
								<option value='admin'><?php echo esc_attr__("Only for administrators (when testing)","holestpay"); ?></option>
								<option value='0'><?php echo esc_attr__("No","holestpay"); ?></option>
							</select>
						</p>
						
						<p label="<?php echo esc_attr__("Override site language","holestpay"); ?>">
							<input placeholder="<?php echo esc_attr__("set if diffrent form site language","holestpay"); ?>" type="text" maxlenth="6" name="hpay_override_language" list="hpay_langauges_list" />
							<datalist id="hpay_langauges_list">
								<option value="en" /> 
								<option value="rs" />
								<option value="rs-cyr" />
							</datalist>
						</p>
						
						
						<p label="<?php echo esc_attr__("Checkout billing is company field","holestpay"); ?>" >
							<select id="hpay_is_company_field" name="hpay_is_company_field">
								<option value=''><?php echo esc_attr__("--none--","holestpay"); ?></option>
								<?php 
									foreach($checkout_fields_billing as $key => $field){
										echo "<option value='" . esc_attr($key) . "' >" . esc_attr($field["label"]) . "</option>";
									}
								
								?>
							</select>
							<span label='<?php echo esc_attr__("add a is-company field","holestpay"); ?>' class='auto-label-width'>
								<input type='checkbox' value='1' id="hpay_is_company" name='hpay_is_company' class='hpay-trigger-on-set' onchange="hpay_is_company_field.disabled = this.checked; if(this.checked){hpay_is_company_field.value =''; } " />
							</span>
							<span class='hpay_note'>* <?php echo esc_attr__("if you use block checkout you must turn on 'company' address field","holestpay"); ?></span>
						</p>
						
						<p label="<?php echo esc_attr__("Checkout billing company TAX ID field","holestpay"); ?>" >
							<select id="hpay_tax_id_field" name="hpay_tax_id_field">
								<option value=''><?php echo esc_attr__("--none--","holestpay"); ?></option>
								<?php 
									foreach($checkout_fields_billing as $key => $field){
										echo "<option value='" . esc_attr($key) . "' >" . esc_attr($field["label"]) . "</option>";
									}
								
								?>
							</select>
							<span label='<?php echo esc_attr__("add a company TAX ID field","holestpay"); ?>' class='auto-label-width'>
								<input type='checkbox' value='1' id="hpay_company_tax_id" name='hpay_company_tax_id' class='hpay-trigger-on-set' onchange="hpay_tax_id_field.disabled = this.checked; if(this.checked){hpay_tax_id_field.value =''; } " />
							</span>
							<span label='<?php echo esc_attr__("set required","holestpay"); ?>' class='auto-label-width'>
								<input type='checkbox' value='1' name='hpay_tax_id_field_required' onchange="if(!hpay_tax_id_field.value && ! hpay_company_tax_id.checked) this.checked = false;" />
							</span>
						</p>
						
						
						<p label="<?php echo esc_attr__("Checkout billing company registry ID field","holestpay"); ?>" >
							<select id="hpay_reg_id_field" name="hpay_reg_id_field">
								<option value=''><?php echo esc_attr__("--none--","holestpay"); ?></option>
								<?php 
								foreach($checkout_fields_billing as $key => $field){
								  echo "<option value='" . esc_attr($key) . "' >" . esc_attr($field["label"]) . "</option>";
								}
								?>
							</select>
							<span label='<?php echo esc_attr__("add a company registry ID field","holestpay"); ?>' class='auto-label-width'>
								<input type='checkbox' value='1' id="hpay_company_reg_id" name='hpay_company_reg_id' class='hpay-trigger-on-set' onchange="hpay_reg_id_field.disabled = this.checked; if(this.checked){hpay_reg_id_field.value =''; } " />
							</span>
							<span label='<?php echo esc_attr__("set required","holestpay"); ?>' class='auto-label-width'>
								<input type='checkbox' value='1' name='hpay_reg_id_field_required' onchange="if(!hpay_reg_id_field.value && ! hpay_company_reg_id.checked) this.checked = false;" />
							</span>
						</p>
						
						<p label="<?php echo esc_attr__("Add customer CC mail address(es) for order status updates field on the checkout","holestpay"); ?>" >
							<input type='checkbox' value='1' name='hpay_billing_email_cc'  />
						</p>
						
						<p label="<?php echo esc_attr__("BCC for all customer emails (comma separated for multiple)","holestpay"); ?>" >
							<input type='text' value='' name='hpay_billing_email_bcc'  />
						</p>
						
						<p label="<?php echo esc_attr__("Order key modification","holestpay"); ?>" >
							<select name="hpay_order_key_modification">
								<option value=""><?php echo esc_attr__("None","holestpay"); ?></option>
								<option value="embed_order_id"><?php echo esc_attr__("Append order id","holestpay"); ?></option>
								<option value="yymmdd-order_id"><?php echo esc_attr__("YYMMDD-order_id","holestpay"); ?></option>
								<option value="yymmddnnn-order_id"><?php echo esc_attr__("YYMMDDNNN-order_id","holestpay"); ?></option>
								<option value="order_id"><?php echo esc_attr__("Use order id / not recommended","holestpay"); ?></option>
								<option value="yymmdd-order_number"><?php echo esc_attr__("yymmdd-order_number / not recommended","holestpay"); ?></option>
								<option value="order_number"><?php echo esc_attr__("Use order number / not recommended","holestpay"); ?></option>
							</select>
						</p>
						
						<?php if(function_exists('wc_get_order_statuses')) { 
							$woo_statuses = wc_get_order_statuses();
							$woocommerce_hold_stock_minutes = get_option("woocommerce_hold_stock_minutes",null);
							$woocommerce_manage_stock       = get_option("woocommerce_manage_stock",null);
						?>
						
							<div class='hpay-woo-order-status-mappings'>
								<hr/>
								<h4><?php echo esc_attr__("WooCommerce order status mappings","holestpay"); ?></h4>
								
								
								<p label="<?php echo esc_attr__("Site order status may be changed:","holestpay"); ?>">
									<select name="hpay_woo_status_set_policy">
										<option value="once"><?php echo esc_attr__("Only once","holestpay"); ?></option>
										<option value="each"><?php echo esc_attr__("Once for each of the mappings","holestpay"); ?></option>
										<option value="always"><?php echo esc_attr__("Always","holestpay"); ?></option>
									</select>
								</p>
								
								
								<?php if(($woocommerce_manage_stock == "yes" || $woocommerce_manage_stock == "1") && $woocommerce_hold_stock_minutes){ ?>
								<p class='hpay-wc-pending-warning'><?php echo sprintf(esc_attr__("Warning: WooCommerce is set to cancel orders in '%s' status after %s minutes. Make sure timeout is sufficiant for you for non-instant payment methods.","holestpay"),"<b>" . esc_attr($woo_statuses["wc-pending"]) . "</b>", "<b>" . intval($woocommerce_hold_stock_minutes) . "</b>"); ?>
									<a target="_blank" href="<?php echo admin_url('admin.php?page=wc-settings&tab=products&section=inventory'); ?>"><?php echo esc_attr__("Alter this setting if needed...","holestpay"); ?></a>	
								</p>
								<?php } ?>
								
								<p label="<?php echo esc_attr__("Set status for orders/renewals when paid","holestpay"); ?>">
									<select name="hpay_woo_status_map_paid">
										<option value="">--<?php echo esc_attr__("No change leave as is","holestpay"); ?>--</option>
										<?php 
											foreach($woo_statuses as $status => $name){
												echo "<option value='{$status}'>{$name}</option>";
											}
										?>
									</select>
								</p>
								
								<p label="<?php echo esc_attr__("Set status for orders/renewals when amount is pre-authorised/reserved","holestpay"); ?>">
									<select name="hpay_woo_status_map_reserved">
										<option value="">--<?php echo esc_attr__("No change leave as is","holestpay"); ?>--</option>
										<?php 
											foreach($woo_statuses as $status => $name){
												echo "<option value='{$status}'>{$name}</option>";
											}
										?>
									</select>
								</p>
								
								<p label="<?php echo esc_attr__("Set status for orders/renewals when awaiting payment (...like bank transfer, passive IPS, crypto-await...)","holestpay"); ?>">
									<select name="hpay_woo_status_map_awaiting">
										<option value="">--<?php echo esc_attr__("No change leave as is","holestpay"); ?>--</option>
										<?php 
											foreach($woo_statuses as $status => $name){
												echo "<option value='{$status}'>{$name}</option>";
											}
										?>
									</select>
								</p>
								
								<p label="<?php echo esc_attr__("Set status for orders/renewals when VOID-ed","holestpay"); ?>">
									<select name="hpay_woo_status_map_void">
										<option value="">--<?php echo esc_attr__("No change leave as is","holestpay"); ?>--</option>
										<?php 
											foreach($woo_statuses as $status => $name){
												echo "<option value='{$status}'>{$name}</option>";
											}
										?>
									</select>
								</p>
								
								<p label="<?php echo esc_attr__("Set status for orders when refunded","holestpay"); ?>">
									<select name="hpay_woo_status_map_refund">
										<option value="">--<?php echo esc_attr__("No change leave as is","holestpay"); ?>--</option>
										<?php 
											foreach($woo_statuses as $status => $name){
												echo "<option value='{$status}'>{$name}</option>";
											}
										?>
									</select>
								</p>
								
								<p label="<?php echo esc_attr__("Set status for orders when partialy refunded","holestpay"); ?>">
									<select name="hpay_woo_status_map_partial_refund">
										<option value="">--<?php echo esc_attr__("No change leave as is","holestpay"); ?>--</option>
										<?php 
											foreach($woo_statuses as $status => $name){
												echo "<option value='{$status}'>{$name}</option>";
											}
										?>
									</select>
								</p>
								
								<p label="<?php echo esc_attr__("Set status for orders when shipped (only if HolestPay shipping method is used)","holestpay"); ?>">
									<select name="hpay_woo_status_map_shipped">
										<option value="">--<?php echo esc_attr__("No change leave as is","holestpay"); ?>--</option>
										<?php 
											foreach($woo_statuses as $status => $name){
												echo "<option value='{$status}'>{$name}</option>";
											}
										?>
									</select>
								</p>
								
								<h4><?php echo esc_attr__("Non-HPay payment method orders/payment status ","holestpay"); ?></h4>
									<?php 
										foreach($woo_statuses as $status => $name){
											if(in_array($status,array("wc-refunded","wc-cancelled","wc-completed","wc-failed","wc-checkout-draft","wc-pending")))
												continue;
											
											?>
											<p label="<?php echo esc_attr__("Considered as paid if status is","holestpay") . " '" . esc_attr($name); ?>'" class='hpay_woo_nonhpay_paid_status_<?php echo esc_attr($status); ?>>'> <input type='checkbox' value='1' name='hpay_woo_nonhpay_paid_status_<?php echo esc_attr($status); ?>' /> </p>
											<?php
										}
									?>
										
									
									
									
									
								<hr/>
							</div>
							<?php 
								$fiscal_methods = $this->core->getPOSSetting("fiscal",null);
								if(!empty($fiscal_methods)){
									?>
									<div class='hpay-woo-fiscal-and-integartion-modules'>
										<h4><?php echo esc_attr__("Fiscal & Integartion methods","holestpay"); ?></h4>
										<p><?php echo esc_attr__("Set wooCommerce order status to trigger default fiscal/integartion method action from the site (if you have some other sort of automation configured on HPay double check if you need this)","holestpay"); ?></p>
										<p style="font-weight:bold;font-size:0.9em;font-style:italic;"><?php echo esc_attr__("ATTENTION: invoking default actions from site corresponds to manual calls (if you use this, then in most cases settings on HPay panel should be set as for manuall invokation).","holestpay"); ?></p>
										<?php
										foreach($fiscal_methods as $fiscal_method){
											?>
											<p label="<?php echo esc_attr( $fiscal_method["SystemTitle"] . "/" . $fiscal_method["Name"] ); ?>">
												<select multiple name="hpay_woo_status_trigger_<?php echo esc_attr($fiscal_method["Uid"]); ?>">
													<?php 
														foreach($woo_statuses as $status => $name){
															echo "<option value='{$status}'>{$name}</option>";
														}
													?>
												</select>
												<span label="<?php echo esc_attr__("Trigger for each of this statuses (otherwise once for the first to happen)","holestpay"); ?>">
													<input type='checkbox' value='1' name='hpay_woo_status_trigger_<?php echo esc_attr($fiscal_method["Uid"]); ?>_each' />
												<span>
											</p>
											<?php
										}
										?>
										<hr/>
									</div>	
									<?php
								}
							?>
							
							
						<?php } ?>
						
						<div class='hpay-options'>
							<hr/>
							<p label="<?php echo esc_attr__("Enable I(P/F-I/S)N log","holestpay"); ?>" class='hpay_enable_log'> <input type='checkbox' value='1' name='hpay_enable_log' /> <span class='hpay_note'>(<?php echo esc_attr__("log dir is /wp-content/uploads/hpay-logs/","holestpay"); ?>)</span></p>
							<p label="<?php echo esc_attr__("Payment at 'thank you' page","holestpay"); ?>" class='hpay_thank_you_page_payment'> <input type='checkbox' value='1' name='hpay_thank_you_page_payment' /> <span class='hpay_note'>(<?php echo esc_attr__("enable if you want to first send customer to the 'thank you' page (by default payment runs straight at the checkout page)","holestpay"); ?>)</span></p>
							<p label="<?php echo esc_attr__("Allways store orders to HPay","holestpay"); ?>" class='hpay_manage_all_orders'> <input type='checkbox' value='1' name='hpay_manage_all_orders' /> <span class='hpay_note'>(<?php echo esc_attr__("Check this if you want HPay to record all orders no matter if HPay payment, shipping, integration or fiscal operation take place","holestpay"); ?>)</span></p>
							<p label="<?php echo esc_attr__("Embed results into WC mails","holestpay"); ?>" class='hpay_woo_embeded_mails'> <input type='checkbox' value='1' name='hpay_woo_embeded_mails' /> <span class='hpay_note'>(<?php echo esc_attr__("Check this if you want that all HPay results information intended for customers get embeded into WC mails. You can then turn off default HPay notifications.","holestpay"); ?>)</span></p>
							<p label="<?php echo esc_attr__("Dock dockable payment methods","holestpay"); ?>" class='hpay_dock_payment_methods'> <input type='checkbox' value='1' name='hpay_dock_payment_methods' /> <span class='hpay_note'>(<?php echo esc_attr__("Use dock layout for payment methods that support it","holestpay"); ?>)</span></p>
							
							
							<p label="<?php echo esc_attr__("Footer branding template","holestpay"); ?>" class='hpay_footer_template'> 
								<select name="hpay_footer_template">
									<option value="">--<?php echo esc_attr__("Not used","holestpay"); ?>--</option>
									<?php 
										$footer_templates  = array();
										if(file_exists(__DIR__ . "/../assets/footer_branding")){
											foreach(glob(__DIR__ . "/../assets/footer_branding/*",GLOB_ONLYDIR) as $footer_template){
												if(is_dir($footer_template)){
													$footer_templates[basename($footer_template)] = basename($footer_template); 
												}	
											}
										}
										
										if(file_exists(WP_CONTENT_DIR . "/uploads/hpay-assets/footer_branding")){
											foreach(glob(WP_CONTENT_DIR . "/uploads/hpay-assets/footer_branding/*",GLOB_ONLYDIR) as $footer_template){
												if(is_dir($footer_template)){
													$footer_templates[basename($footer_template)] = basename($footer_template); 
												}	
											}
										}
										
										foreach($footer_templates as $ftmpl){
											echo "<option value='" . esc_attr($ftmpl) . "'>" . esc_attr($ftmpl) . "</option>";	
										}
									?>
								</select>
								<span class='hpay_note'>(<?php echo esc_attr__("to alter or make a new template, see ","holestpay") . "...(wp_content)/uploads/hpay-assets/footer_branding/(template)..."; ?>)</span>
							</p>
						</div>
						
						<hr/>
						<div class='hpay-subscriptions'>
							
							<h4><?php echo esc_attr__("Subscriptions","holestpay"); ?></h4>
							
							<p label="<?php echo esc_attr__("Always require payment token vault refernce save","holestpay"); ?>" class='hpay_always_require_vault'> <input type='checkbox' value='1' name='hpay_always_require_vault' /> <span class='hpay_note'>(<?php echo esc_attr__("works only if the user is logged in","holestpay"); ?>)</span></p>
							
							<p label="<?php echo esc_attr__("No automatic charges","holestpay"); ?>" class='hpay_no_auto_charges'> <input type='checkbox' value='1' name='hpay_no_auto_charges' /> <span class='hpay_note'>(<?php echo esc_attr__("if you need to check all renewal orders before charge this may be required","holestpay"); ?>)</span></p>
							
							
							<hr/>
							
							<div class="hpay-woocommerce-subscriptions">
								<h5><?php echo esc_attr__("WooCommerce & WooCommerce plugins integration","holestpay"); ?></h5>
								<p class='hpay_supported_plugin'> WooCommerce Subscriptions <?php 
									echo esc_attr__("(natively supported)","holestpay"); 
									echo ": "; 
									if(class_exists("WC_Subscriptions")){
										echo " " . esc_attr__("DETECTED","holestpay")." &#10003;";
									}else{
										echo " " . esc_attr__("NOT DETECTED","holestpay");
									}
								?></p>	
									
								<p class='hpay_supported_plugin'> YITH WooCommerce Subscription: <?php 
									if(function_exists('YITH_WC_Subscription')){
										echo " " . esc_attr__("DETECTED","holestpay")." &#10003;";
										echo ", " .esc_attr__("Enable integration","holestpay");
										echo ": <input type='checkbox' value='1' name='hpay_yith_enable' />";
									}else{
										echo " " . esc_attr__("NOT DETECTED","holestpay");
									}
									?>
								</p>
								<br/>
								<p label="<?php echo esc_attr__("Only card-on-file|mit|recurring capable payment methods for subscriptions","holestpay"); ?>" >
									<input type='checkbox' value='1' name='hpay_subscriptions_only_true_capable' />
									<span class='hpay_note'><?php echo esc_attr__("* by default all methods are available for subscriptions. Tick this to limit only to no-customer-presence charges (recurring, card-on-file, mit) capable methods.","holestpay"); ?></span>
								</p>
								<!--
								<p class='hpay_supported_plugin'><?php echo esc_attr__("Add HolestPay subsciptions support to my products/variants (if you don't have other subscription plugin): ","holestpay"); ?>
									   <input type="checkbox" name="hpay_subscriptions_on" value="1" />
									   <span class='hpay_note'>**(you can usaly use this even with other subscriptions plugins, just define in parallel product/variant setupt for both)</span>
								</p>
								-->
								<!--
								<div class='hpay_custom_plugin_support'>
									<div class='hpay_light_border'>
										<h5 class='hpay_no_bottom_space'><?php echo esc_attr__("Other custom WooCommerce plugins integrations","holestpay"); ?></h5>	
										<span class='hpay_note'>*<?php echo esc_attr__("HolestPay may invoke renewal order creation in behalf of your custom plugin - but its better that your plugin performs that becuse usually additional data will need to be set or you will have to add code using hooks/filters. Plugin vendors sometimes explicitly block checkout for payment methods that are not pre-defined/pre-allowed by them. In that case you will have to contact them.","holestpay"); ?></span>
										<br/>
										<button id="hpay_runSmartTool" class='button button-primary'><?php echo esc_attr__("Run smart subscription plugin integration tool...","holestpay"); ?></button>
										<span class='hpay_note'>**<?php echo esc_attr__("Before running smart tool make sure you have at least one product defined as an subscription from your plugin.","holestpay"); ?></span>
										<br/><br/>
										
										<div class="hpay_custom_integrations">
										
										</div>
										
										
										<button id="hpay_cmdSeeCustomCodingInstructions" class='button button-primary'><?php echo esc_attr__("See code/hooks integration reference...","holestpay"); ?></button>
										<span class='hpay_note'>**<?php echo esc_attr__("If smart tool did not work for you, you can always just provide needed data vai hooks code to HolestPay on checkout, or run some actions on payment completion...","holestpay"); ?></span>
										
										<p label="<?php echo esc_attr__("Create renwal order to payment delay","holestpay"); ?>">
											<input type="number" value="0" name="hpay_renewal_paymnet_delay" />
											<select name="hpay_renewal_paymnet_delay_unit">
												<option value='days'><?php echo esc_attr__("Days","holestpay"); ?></option>
												<option value='hours'><?php echo esc_attr__("Hours","holestpay"); ?></option>
											</select>
										</p>
										
										
									</div>
								</div>
								-->
							</div>
							
						</div>
						<hr/>
						<!--
						<p label="<?php echo esc_attr__("Enable fast checkout QR codes","holestpay"); ?>">
							<input type='checkbox' name="hpay_qr_add_to_cart_enabled" value='1' />
						</p>
						
						<p label="<?php echo esc_attr__("Fast checkout QR codes - clear basket","holestpay"); ?>">
							<input type='checkbox' name="hpay_qr_add_to_cart_clear_basket" value='1' />
						</p>
						-->
						<?php if(!$this->core->availableCOFMethods()){ ?>
							<p class="hpay-subscriptions-warning">	
								<?php echo esc_attr__("WARNING (if you use subscriptions) You have not enabled any HolestPay payment method that allows an direct subscription charges or payment recurrance. If your customers use non-true subsciption payment method to pay the HolestPay can only notify them by e-mail to make a renewal payment before a subsciption period expiration.","holestpay"); ?>
							</p>
						<?php } ?>
						<p>
							Additional: <a target="_blank" href="https://holest.com/updatescheck/?sku=1016001&download=1">Download Checkout Installments Plugin (activate with HPay key)</a>
						</p>
					  <div>
				  </div>	
			   </div>
		   
		   <hr style='margin-bottom:80px;' />
		   
		   <p class="hpay_bottom_bar">
				<button id="cmdSaveHpay" style='display:none' class='button button-primary'><?php echo esc_attr__("Save","holestpay"); ?></button> 
		   </p>
		   
		   <div id="hpay_control_models" style='display:none!important'>
			 <div class='hpay_smart_tool_wizzard hpay_clear_input_form'>
				
				<div class="hpay_wizzard_step_1">
					<label><?php echo esc_attr__("Find & select a sample subsciption product/variant you have set up in your custom/3rd-party subscription plugin ...","holestpay"); ?></label>
					<p><input class='hpay_full_width' type="text" id="hpay_smart_tool_search" placeholder="<?php echo esc_attr__("search subsciption product by title or ID","holestpay"); ?>" /> </p>
					<p class="hpay_note">**<?php echo esc_attr__("If you have been trying around varius subscription plugins we recommend that you create fresh subscription product and set it up (make sure you set up all fields you will need). Then make test subsciption order and renewal becuse it might happen this tool might relate to data leftovers that stayed attached to your products from plugins you have tried and abanadoned. Use fresh product/order data for samples.","holestpay"); ?></p>
					<hr/>
					<table cellpadding="0" cellspacing="0">
						<thead>
						<tr>
							<th>
								ID
							<th>
							<th>
								<?php echo esc_attr__("Parent ID","holestpay"); ?>
							<th>
							<th>
								<?php echo esc_attr__("Title","holestpay"); ?>
							<th>
							<th>
								<?php echo esc_attr__("Post type","holestpay"); ?>
							<th>
						</tr>
						</thead>
						<tbody>
						
						</tbody>
						<tfoot>
							<tr class='hpay_row_model' style="display:none!important">
								<td prop="post_id"><td>
								<td prop="post_parent"><td>
								<td prop="post_title"><td>
								<td prop="post_type"><td>
								<td><button class='button button-primary hpay-select-sample-post'><?php echo esc_attr__("This is a good sample","holestpay"); ?> &#10148;</button></td>
							</tr>
						</tfoot>
					</table>
				</div>
				
				<div class="hpay_wizzard_step_2 hpay_flex_row">
				   <div class="hpay_smart_tool_bindings_wrapper">
					<table class="hpay_smart_tool_bindings">
						<thead>
						<tr>
							<th>
								<?php echo esc_attr__("Common product subscription fileds","holestpay"); ?>
							<th>
						</tr>
						</thead>
						<tbody>
							
							<tr><th><?php echo esc_attr__("Interval","holestpay"); ?> *</th></tr>
							<tr><td><input required placeholder="<?php echo esc_attr__("select bound ref or enter fixed value - number","holestpay"); ?>" type="text" value="" bindprop="interval" /> 
							<span class="hpay_note"><?php echo esc_attr__("Indicative that this is an subscription","holestpay"); ?></span>
							</td></tr>
							
							<tr><th><?php echo esc_attr__("Interval unit day/week/month/year","holestpay"); ?> *</th></tr>
							<tr><td>
							<input required placeholder="<?php echo esc_attr__("select bound ref or enter fixed value","holestpay"); ?>" type="text" value="" bindprop="interval_unit" /> 
							<span class="hpay_note"><?php echo esc_attr__("Indicative that this is an subscription","holestpay"); ?></span>
							</td></tr>
							
							<tr><th><?php echo esc_attr__("Charged renewal amount","holestpay"); ?> *</th></tr>
							<tr><td><input required placeholder="<?php echo esc_attr__("select bound ref or enter fixed value - number","holestpay"); ?>" type="text" value="" bindprop="subsciption_amount" /> 
							<span class="hpay_note"><?php echo esc_attr__("Indicative that this is an subscription. First payment will just take checkout amount.","holestpay"); ?></span>
							</td></tr>
							
							<tr><th><?php echo esc_attr__("Amount stays same yes|no","holestpay"); ?></th></tr>
							<tr><td>
							<input placeholder="<?php echo esc_attr__("yes or 1 | no or 0 or not set","holestpay"); ?>" type="text" value="" bindprop="first_renewal_after_unit" /> 
							<span class="hpay_note"><?php echo esc_attr__("If charged amount stays after you change prices on the site","holestpay"); ?></span>
							</td></tr>
							
							<!--
							<tr><th><?php echo esc_attr__("Charged renewal amount currency","holestpay"); ?></th></tr>
							<tr><td>
							<input placeholder="<?php echo esc_attr__("select bound ref or enter fixed value - currency code or num code","holestpay"); ?>" type="text" value="" bindprop="subsciption_currency" /> 
							<span class="hpay_note"><?php echo esc_attr__("Set only if it defers from the WooCommerce currency","holestpay"); ?></span>
							</td></tr>
							-->
							
							<tr><th><?php echo esc_attr__("First renewal after","holestpay"); ?></th></tr>
							<tr><td>
							<input placeholder="<?php echo esc_attr__("select bound ref or enter fixed value - number","holestpay"); ?>" type="text" value="" bindprop="first_renewal_after" /> 
							<span class="hpay_note"><?php echo esc_attr__("If first COF charge is not after regular interval","holestpay"); ?></span>
							</td></tr>
							
							<tr><th><?php echo esc_attr__("First renewal after unit day/week/month/year","holestpay"); ?></th></tr>
							<tr><td>
							<input placeholder="<?php echo esc_attr__("select bound ref or enter fixed value","holestpay"); ?>" type="text" value="" bindprop="first_renewal_after_unit" /> 
							<span class="hpay_note"><?php echo esc_attr__("If first COF charge is not after regular interval","holestpay"); ?></span>
							</td></tr>
							
							<!--
							<tr><th><?php echo esc_attr__("Trial duration/Schedule delay","holestpay"); ?></th></tr>
							<tr><td><input placeholder="<?php echo esc_attr__("select bound ref or enter fixed value - number","holestpay"); ?>" type="text" value="" bindprop="subsciption_trial_days" /> </td></tr>
							
							<tr><th><?php echo esc_attr__("Trial/Schedule delay unit day/week/month/year","holestpay"); ?></th></tr>
							<tr><td><input placeholder="<?php echo esc_attr__("select bound ref or enter fixed value","holestpay"); ?>" type="text" value="" bindprop="subsciption_trial_days_unit" /> </td></tr>
							
							<tr><th><?php echo esc_attr__("Limited duration enable","holestpay"); ?></th></tr>
							<tr><td><input placeholder="<?php echo esc_attr__("select bound ref or enter fixed value - yes|no","holestpay"); ?>" type="text" value="" bindprop="subsciption_limited_duration"  bindmulti="and" /> </td></tr>
							
							<tr><th><?php echo esc_attr__("Limited duration interval - number","holestpay"); ?></th></tr>
							<tr><td><input placeholder="<?php echo esc_attr__("select bound ref or enter fixed value","holestpay"); ?>" type="text" value="" bindprop="subsciption_limited_duration_interval" /> </td></tr>
							
							<tr><th><?php echo esc_attr__("Limited duration interval unit day/week/month/year","holestpay"); ?></th></tr>
							<tr><td><input placeholder="<?php echo esc_attr__("select bound ref or enter fixed value","holestpay"); ?>" type="text" value="" bindprop="subsciption_limited_duration_unit" /> </td></tr>
							
							<tr><th><?php echo esc_attr__("Different inital payment enable","holestpay"); ?></th></tr>
							<tr><td><input placeholder="<?php echo esc_attr__("select bound ref or enter fixed value - yes|no","holestpay"); ?>" type="text" value="" bindprop="subsciption_diff_first_payment" bindmulti="and" /> </td></tr>
							
							<tr><th><?php echo esc_attr__("Inital payment amount","holestpay"); ?></th></tr>
							<tr><td><input placeholder="<?php echo esc_attr__("select bound ref or enter fixed value","holestpay"); ?>" type="text" value="" bindprop="subsciption_inital_amount" /> </td></tr>
							-->
							<tr><th><?php echo esc_attr__("Is subscription indicator","holestpay"); ?></th></tr>
							<tr><td><input placeholder="<?php echo esc_attr__("select bound ref or enter fixed value","holestpay"); ?>" type="text" value="" bindprop="is_subsciption" bindmulti="or" /> 
								<span class="hpay_note"><?php echo esc_attr__("If specified it will be checked otherwise existance of interval, interval unit and amount will indicate subscription","holestpay"); ?></span>
							</td></tr>
							<!--
							<tr><th><?php echo esc_attr__("Only one product per cart","holestpay"); ?></th></tr>
							<tr><td><input placeholder="<?php echo esc_attr__("select bound ref or enter fixed value - yes|no","holestpay"); ?>" type="text" value="" bindprop="subsciption_one_product" bindmulti="and" /> </td></tr>
							
							<tr><th><?php echo esc_attr__("Allow re-schedule by client","holestpay"); ?></th></tr>
							<tr><td><input placeholder="<?php echo esc_attr__("select bound ref or enter fixed value - yes|no","holestpay"); ?>" type="text" value="" bindprop="subsciption_client_reschedule" bindmulti="and" /> </td></tr>
							
							<tr><th><?php echo esc_attr__("Allow subscription item(s) update by client","holestpay"); ?></th></tr>
							<tr><td><input placeholder="<?php echo esc_attr__("select bound ref or enter fixed value - yes|no","holestpay"); ?>" type="text" value="" bindprop="subsciption_one_product" bindmulti="and" /> </td></tr>
							
							<tr><th><?php echo esc_attr__("Assigned user role(s)","holestpay"); ?></th></tr>
							<tr><td><input placeholder="<?php echo esc_attr__("select bound ref or enter fixed value (comma separated)","holestpay"); ?>" type="text" value="" bindprop="subsciption_assign_roles" /> </td></tr>
							
							<tr><th><?php echo esc_attr__("Remove assigned user role(s) on expiration","holestpay"); ?></th></tr>
							<tr><td><input placeholder="<?php echo esc_attr__("remove assigned by default","holestpay"); ?>" type="text" value="" bindprop="subsciption_remove_roles" /> </td></tr>
							-->
						</tbody>
						<tfoot>
						
						</tfoot>
					</table>
					</div>
					
					<div class="hpay_smart_tool_refs">
						<h5><?php echo esc_attr__("Data references","holestpay"); ?></h5>
						<ul class='hpay_smart_tool_detected_meta'>
							
						</ul>
						<li class='hpay_row_model' style="display:none!important">
							<h6 class='hpay_field_relation' prop="field_relation"></h6>
							<div class='hpay_field_relation_data'>
								<span>
									<label><?php echo esc_attr__("Detected sample value","holestpay"); ?>:</label>
									<span prop="value"></span>
								</span>
								<span>
									<label><?php echo esc_attr__("What is this regarding subscription?","holestpay"); ?></label>
									<select class='hpay_this_field_is'>
											<option value="">-- <?php echo esc_attr__("Nothing","holestpay"); ?> --</option>
									</select>
								</span>
							</div>
						</li>
					</div>	
				</div>
				
				<div class="hpay_wizzard_step_3">
					<div class='hpay_smart_tool_ord_sub'>
						<p><?php echo esc_attr__("Invoke renewal order creation (if your plugin does not do that itself)","holestpay"); ?> <input bindprop="subscription_invoke_renewal" type='checkbox' class='hpay_custom_integration_ipn_invoke' /></p>
						
						<div class='hpay_call_renew_explain'>
							<!--
							<div>
								<p><?php echo esc_attr__("Create renewal order to payment try delay days","holestpay"); ?>
									<input placeholder="<?php echo esc_attr__("set number of days if you need delay to adjust something or pre-inform customer","holestpay"); ?>" type="text" value="0" bindprop="payment_delay_days" /></p>
							</div>
							-->
						</div>

<div class='hpay_smart_tool_invoke_renewal_instructions'>
	<h4><?php echo esc_attr__("In case you enabled HolestPay renewal order creation, then:","holestpay"); ?></h4>						
	<p><?php echo esc_attr__("HolestPay can only create renewal as an normal WooCommerce order with products detected as subsciptions in the original checkout order. You will maybe need to adjust other order/subscription data that is specific to your plugin. Below is the reference of hooks you can add to your theme functions.php that could serve for this purphose. Plugin vendor may help you best about this. You may also use all other regular wooCommerce hooks/filters.","holestpay"); ?> </p>
							
							
<pre>
add_action("woocommerce_new_order","mysite_renewal_order_created",10,1);
function mysite_renewal_order_created($order_id){
	$order = hpay_get_order($order_id);
	if($order->get_meta("_hpay_invoke_renewal")){
		//DO OTHER THINGS YOU NEED HERE BEFORE PAYMENT TRY. FOR custom subscription integrations meta _hpay_invoke_renewal will be set 
	}
}

add_action("woocommerce_pre_payment_complete","mysite_renewal_order_paid",10,1);
function mysite_renewal_order_paid($order_id){
	$order = hpay_get_order($order_id);
	if($order->get_meta("_hpay_invoke_renewal",true)){
		//DO OTHER THINGS YOU NEED HERE ON RENEWAL PAYMENT SUCCESS. FOR custom subscription integrations meta _hpay_invoke_renewal will be set 
	}
}

add_action("hpay_hourly_run","mysite_hourly_run",10,0);
function mysite_hourly_run($order_id){
	//RUN SOMETHING ONCE A HOUR.
}

</pre>
</div>
					<div>
				</div>
				
			 </div>
		   
		   
		   </div>
		</div>
		<script type="text/javascript">
			jQuery(document).ready(function(){
				setTimeout(function(){
					jQuery("#hpay_settings_page select[multiple]").select2();
				},350)
			});
			
			function indicateEnabled(){
				let sel_enb = jQuery("select[name='hpay_enabled']");
				if(sel_enb){
					sel_enb.css('color', sel_enb.val() != '1' ? "red" : "").css('border-color', sel_enb.val() != '1' ? "red" : "");
				}
			}
			
			jQuery(document).on("change","select[name='hpay_enabled']", function(e){
				indicateEnabled();
			});
			
			setTimeout(function(){
				indicateEnabled()
			},1500);
			
		</script>
		<?php
	}
};