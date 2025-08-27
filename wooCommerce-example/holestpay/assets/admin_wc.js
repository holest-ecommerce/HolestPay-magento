function hpay_parse_qs(url){
	let data = {};
	if(!url){
		url = window.location.href;
	}
	let qsstr = url.split("?")[1];
	if(qsstr){
		qsstr.split("&").forEach(t => {
			if(t){
				let parm = t.split("=");
				if(parm[0]){
					data[parm[0]] = parm[1] ? decodeURIComponent(parm[1]) : "";
				}
			}
		});
	}
	return data;
}

function addQueryArg(url,param_name, param_value){
	if(/\?/.test(url || "")){
		return (url || "") + "&" + param_name + "=" + encodeURIComponent(param_value);
	}else{
		return (url || "") + "?" + param_name + "=" + encodeURIComponent(param_value);
	}
}

function addQueryArgs(url, params){
	if(params){
		for(var param in params){
			if(params.hasOwnProperty(param)){
				url = addQueryArg(url, param, params[param]);
			}
		}
	}
	return url;
}

function hpay_copy_to_cliboard(value){
	const textArea = document.createElement('textarea');
	textArea.value = value;

	textArea.style.position = 'fixed';
	textArea.style.top = '0';
	textArea.style.left = '0';
	textArea.style.opacity = '0';
	textArea.style.userSelect = 'none';
	document.body.appendChild(textArea);
	textArea.select();
	document.execCommand('copy');
	document.body.removeChild(textArea);
}

(function(){
	
	let q = hpay_parse_qs();
	if(q.page == "wc-settings" && q.tab == "checkout" && /^hpaypayment\-\d*$/.test(q.section)){
		jQuery("body").addClass("hpay-wc-method-setup-page");
	}
	
	jQuery(document).ready(function(){
		jQuery(".wc_payment_gateways_wrapper tr[data-gateway_id^='hpaypayment-'] .status a").css("pointer-events","none");
		jQuery("form input[name*='_hpaymethod_replaced_form']").each(function(ind){
			jQuery(this.form).find(":submit").hide();
		});
		
		
		if(/post_type\=shop_order(\&|$)|page\=wc\-orders(\&|$)/.test(window.location.href)){
			
			if(!window._hpay_list_order_toolbox){
				window._hpay_list_order_toolbox = document.createElement("div");
				window._hpay_list_order_toolbox.setAttribute("id", "hpay_list_order_toolbox");
				window._hpay_list_order_toolbox.className = 'hpay-list-order-toolbox';
			}
			
			setTimeout(function(){
				hpay_init_order_list_toolbox(window._hpay_list_order_toolbox).then(r => {
					if(r.posuts)
						jQuery(window._hpay_list_order_toolbox).insertAfter(".tablenav.top:first .actions:last");
				});	
			},500);
		}
	});
	
	function setListStatusBoxStatus(element, status){
		if(/RESERVED/.test(status)){
			if(!jQuery(element).is(".hpay-blind"))
				jQuery(element).addClass("hpay-blind");
		}else{
			jQuery(element).removeClass("hpay-blind");
		}
		
		jQuery(element).html( (status || "").split(" ").map(function(s){
					if(!/\:/.test(s)){
						return "<span><b>" + s + "</b></span>";
					}else{
						return "<span>" + s.replace(/\,/g,', ').replace(":",": <b>") + "</b></span>";
					}
				}).join(''));
	}
	
	document.addEventListener("onHPayOrderOpExecuted", function(evt){
		
		if(window._currentListStatusBox){
			if(evt.order && evt.order.Status){
				setListStatusBoxStatus(window._currentListStatusBox, evt.order.Status);
			}
			hpay_dialog_close("hpay_order_list_options");
		}
		
		if(evt.order_op_response){
		   	
		   if(evt.order_op_response.error){
			   hpay_alert_dialog("<h3>" + evt.operation.toUpperCase() + " ERROR:<h3>" +  "<pre>" + JSON.stringify(evt.order_op_response.error, null, 2).replace(/\"|\{|\}|\,/g,"").replace(/\_/g," ") + "</pre>","normal").then(aletred => {
					//
			   });
		   }else if(evt.order_op_response.order){
			   
			   if(typeof HPay !== 'undefined'){
					if(HPay)
						HPay.enterClientWait();
			   }
			   
			   fetch( HolestPayAdmin.notify_url + "&topic=orderupdate", {
				   method: "POST",
				   headers:{
					 "Content-type": "application/json"
				   },
				   body: JSON.stringify(evt.order_op_response)
				  }
			   ).then(r => r.json()).then(r => {
				   if(typeof HPay !== 'undefined'){
						if(HPay)
							HPay.exitClientWait();
				   }
				   
				   if(window._currentListStatusBox){
					   if(r.order_site_status && r.order_id && !r.info){
						   r.info = {
							   order_site_status: r.order_site_status,
							   order_id: r.order_id
						   };
					   }
					   
				  	   if(r.info && r.info.order_id && r.info.order_site_status){
							let status_cell = jQuery("tr#order-" + r.info.order_id + " td.order_status, tr#post-" + r.info.order_id + " td.order_status");
							if(status_cell[0]){
								status_cell.find("mark").remove();
								let wcstatus = r.info.order_site_status.replace(/^wc[-\_]/,'');
								jQuery('<mark class="order-status status-' + wcstatus + ' tips" ></mark>').html("<span>" + (HolestPayAdmin.labels["wc-" + wcstatus] || wcstatus) + "</span>").appendTo(status_cell);
							}
					   }
					   
					   hpay_alert_dialog("<h3>" + evt.operation.toUpperCase() + ":<h3>" +  "<pre>" + JSON.stringify(r, null, 2).replace(/\"|\{|\}|\,/g,"").replace(/\_/g," ") + "</pre>","normal").then(aletred => {
						   //
					   });
				   }else{
					    hpay_alert_dialog("<h3>" + evt.operation.toUpperCase() + ":<h3>" +  "<pre>" + JSON.stringify(r, null, 2).replace(/\"|\{|\}|\,/g,"").replace(/\_/g," ") + "</pre>","normal").then(aletred => {
						   HPay.enterClientWait();
						   window.location.reload();
					   });
				   }
			   }).catch(err => {
				   if(!window._currentListStatusBox){
					   window.location.reload();
				   }else{
					  if(typeof HPay !== 'undefined'){
							if(HPay)
								HPay.exitClientWait();
					   } 
				   }
			   });
		   }else{
			   hpay_alert_dialog("<h3>" + evt.operation.toUpperCase() + ":<h3>" +  "<pre>" + JSON.stringify(evt.order_op_response, null, 2).replace(/\"|\{|\}|\,/g,"").replace(/\_/g," ") + "</pre>","normal").then(aletred => {
					//
			   });
		   }
		}else if(!window._currentListStatusBox){
			setTimeout(function(){
				window.location.reload();	
			},1500);
		}
	});
	
	window.addEventListener("message",function(event){
		if(event.origin && event.data && /pay\.holest\.com/.test(event.origin)){
			if(event.data.hpay_ost_event == "hpay-order-updated"){
				let order = event.data.data;
				try{
					let sbox = jQuery("span[hpay_order_list_info='" + order.Uid + "']");
					if(sbox[0]){
						setListStatusBoxStatus(sbox[0], order.Status);
					}
				}catch(ex){}
			}
		}
	},false);
	
	jQuery(document).on("click",".hpay_store_order[order_id]", function(e){
		e.preventDefault();
		e.stopImmediatePropagation();
		
		let order_id      = jQuery(this).attr("order_id");
		let target_el     = jQuery(this);
		let order_details = jQuery(this).attr("order_details");
		let invoker_ctl   = jQuery(this);
		
		let ps_change = "";
		if(jQuery(this).attr("stored_uid")){
			ps_change = "<p><label>" + HolestPayAdmin.labels.with_pay_status_change + "</label><select class='hpay-order-status-explicit-change'>";
			["", "PAID","PAYING","OVERDUE", "RESERVED", "AWAITING", "REFUNDED", "PARTIALLY-REFUNDED","VOID", "EXPIRED", "OBLIGATED", "REFUSED", "FAILED", "CANCELED"].forEach(s =>{
				if(!s){
					ps_change += ("<option value=''>--" + HolestPayAdmin.labels.no + "--</option>");
				}else{
					ps_change += ("<option value='" + s + "'>" + s + "</option>");
				}
			});
			ps_change += "</select>";
		}
		
		hpay_confirm_dialog((jQuery(this).attr("stored_uid") ? HolestPayAdmin.labels.push_update_to_hpay : HolestPayAdmin.labels.push_to_hpay) + ps_change,"small",HolestPayAdmin.labels.yes, HolestPayAdmin.labels.cancel).then(yes => {
			if(yes){
				let schange = "";
				
				if(jQuery(".hpay-order-status-explicit-change:last").val()){
					schange = "&set_hpay_pay_status=" + jQuery(".hpay-order-status-explicit-change:last").val();
				}
				
				fetch(HolestPayAdmin.ajax_url + "?action=hpay-push-order&order_id=" + order_id + schange).then(r => r.json()).then(resp => {
					if(resp.order_uid){
						
						if(typeof _orders === 'undefined'){
							_orders = {};
						}
						
						let ord = resp.order ? resp.order : resp;
						
						_orders[resp.order_uid] = ord;
						
						invoker_ctl.attr("stored_uid",resp.order_uid);
						
						if(order_details == 1){
							jQuery(".hpay-manage-on-line").show();
							if(!jQuery('div[hpay_order_action_toolbox]')[0]){
								jQuery('<div></div>').attr("hpay_order_action_toolbox", resp.order_uid).insertAfter(jQuery(".hpay-manage-on-line"));
							}
							window.hpay_init_order_panel(resp.order_uid, jQuery('div[hpay_order_action_toolbox]'),undefined,undefined,ord);
							//window.location.replace("");
						}else{
							let scell = jQuery('<span></span>').attr('order_id', order_id).attr('hpay_order_list_info',resp.order_uid);
							target_el.replaceWith(scell);
							setListStatusBoxStatus(scell, resp.status);
						}
					}
				}).catch(err => {
					alert(HolestPayAdmin.labels.error_in_operation);	
				});
			}
		});
	});
	
	jQuery(document).on("click","span[hpay_order_list_info]", function(e){
		e.preventDefault();
		e.stopImmediatePropagation();
		
		if(typeof hpay_dialog_open === 'undefined')
			return;//WAIT A BIT
		
		if(typeof hpay_init_order_panel === 'undefined')
			return;//WAIT A BIT
		
		window._currentListStatusBox = jQuery(this);
		
		if(!window._hpay_inlist_order_toolbox){
			window._hpay_inlist_order_toolbox = document.createElement("div");
			window._hpay_inlist_order_toolbox.setAttribute("id", "hpay_inlist_order_panel");
			window._hpay_inlist_order_toolbox.className = 'hpay-inlist-order-panel';
		}
		
		window.hpay_init_order_panel( jQuery(this).attr("hpay_order_list_info"), window._hpay_inlist_order_toolbox, true).then(res => {
			if(res.Uid){
				let path = "orders/Uid:" + res.Uid;
				
				hpay_dialog_open("hpay_order_list_options", "#" + jQuery(this).attr("order_id"), window._hpay_inlist_order_toolbox, "normal",{
					"Manage":{
						caption:HolestPayAdmin.labels.manage_on_hpay || "Manage on HolestPay...",
						Run: function(){
							manageOnHolestPay(path,1024);
							hpay_dialog_close("hpay_order_list_options");
						},
						action_postion:"right"
					}
				});
			}else{
				//	
			}
		})
	});
	
	
	jQuery(document).on("click",".cmd-hpay-quick-buy", function(e){
		e.preventDefault();
		let btn = jQuery(this);
		
		HPayInit().then(client => {
			HPay.loadHPayUI().then(() => {
				hpay_require_script("qrcode.min.js").then(r => {
					try{
						
						HPay.enterClientWait();
						
						let data = {
								is_variant: btn.attr("is_variant") == 1 ? 1 : 0,
								variant_id: btn.attr("variant_id") ? parseInt(btn.attr("variant_id")) : null,
								product_id: parseInt(btn.attr("product_id"))
							};
							
						if(data.is_variant){
							try{
								data.variant_image = btn.closest(".woocommerce_variation").find(".upload_image_button img").attr("src");
							}catch(iex){
								//
							}
							
							try{
								data.variant_attributes = {};
								btn.closest(".woocommerce_variation").find("select[name^='attribute_']").each(function(ind){
									
									let aname = jQuery(this).attr("name").split("[")[0];
									
									data.variant_attributes[aname] = jQuery(this).val() ? {
										value: jQuery(this).val(),
										label: jQuery(this).find("option:selected").text().trim()
									}: {
										options: {}
									};

									if(data.variant_attributes[aname].options){
										let opt = {};
										jQuery(this).find("option").each(function(ind){
											if(jQuery(this).attr("value"))
												opt[jQuery(this).attr("value")] = jQuery(this).text().trim();
										});
										data.variant_attributes[aname].options = opt;
									}	
									
								});
							}catch(iex){
								console.error(iex);
							}
						} 	
						
						fetch(addQueryArgs(HolestPayAdmin.ajax_url,{action: "hpay-back-operations", operation:"quick_order_form" }), {
							method:"POST",
							headers: {
								'Content-Type': 'application/json'
							},
							credentials: 'include',
							body: JSON.stringify(data)
						}).then(r=>r.json()).then(r=>{
							HPay.exitClientWait();
							if(r && r.body && r.link && r.title){
								
								r.data = data;
								window.current_hpay_qb = r;
								
								
								if(!window.hpay_qb_render){
									
									
									window.hpay_qb_render = () => {
										
										let link_data = {
											qty: jQuery(".hpay_quick_buy_qty").val() || 1,
											uid: "HPQB" + (new Date()).toISOString().substring(0,10).replace(/-/g,'') + parseInt(Math.random() * 9999),
											vid: data.variant_id,
											pid: data.product_id
										};
										
										let disc = parseFloat(jQuery(".hpay_quick_buy_coupon").val());
										if(disc > 0){
											link_data["discount"] = disc;
										}else if(disc < 0){
											jQuery(".hpay_quick_buy_coupon").val(0.00);
										}
										
										let vdays = jQuery(".hpay_quick_buy_coupon_valid_days").val();
										if(parseInt(vdays)){
											link_data["vdays"] = parseInt(vdays);
										}
										
										jQuery(".hpay-quick-buy-panel-attributes:visible select").each(function(ind){
											link_data[jQuery(this).attr("name")] = jQuery(this).val();
										});
										
										jQuery(".hpay_quick_buy_message").html(jQuery(window.current_hpay_qb.body).find(".hpay_quick_buy_message").html());
										
										let body = jQuery(".hpay_quick_buy_message").html().replace(/\%BASE_URL\%/g, window.current_hpay_qb.link); 
										
										const encoder = new TextEncoder();
										const encodedBytes = encoder.encode(JSON.stringify(link_data));
										let qval = btoa(String.fromCharCode(...encodedBytes));
								
										let qb_link = addQueryArg(window.current_hpay_qb.link, "hpay_qbuy",qval); 
										
										window.hpay_current_qb_link = qb_link;
										body = body.replace(/\%LINK\%/g, qb_link); 
										
										jQuery(".hpay_quick_buy_message").html(body);
										
										if(link_data.qty > 1){
											jQuery(".hpay_qb_qty").html(" &times; " + link_data.qty);
										}
										
										for(var prop in link_data){
											if(link_data.hasOwnProperty(prop)){
												if(/attribute_/.test(prop)){
													jQuery(".hpay_quick_buy_message span." + prop).html(link_data[prop]);
												}
											}
										} 
										
										if(link_data["discount"]){
											let disc_text = HolestPayAdmin.labels.discountp_qbuy.replace("%s", link_data["discount"]);
											if(link_data["vdays"]){
												disc_text = disc_text + " " + HolestPayAdmin.labels.discountp_qbuy_vdays.replace("%s", link_data["vdays"]);
											}	
											jQuery(".hpay-qbuy-discount").html(disc_text);
										}
										try{
											qrcode.stringToBytes = qrcode.stringToBytesFuncs["UTF-8"];
											var qr = qrcode('0', 'Q');
										    qr.addData(qb_link, 'Byte');
										    qr.make();
											jQuery(".hpay-qbuy-qr").html(qr.createImgTag());
										}catch(ex){
											console.error(ex);
										}
									};
									
									jQuery(document).on("change",".hpay_quick_buy_toolbox *:not(.hpay_quick_buy_email)", function(e){
										window.hpay_qb_render();
									});
									
								}
								
								let d = {};
								d["📄"] = {
									Run: (dlg) => {
										hpay_copy_to_cliboard(window.hpay_current_qb_link);
										jQuery(".hpay-qb-clipborad").css("background","yellow");
										setTimeout(function(){
											jQuery(".hpay-qb-clipborad").css("background","#032b45");
										},300);
									},
									className:"hpay-qb-clipborad",
									action_postion: "left"
								};
								
								d[HolestPayAdmin.labels.send] = {
									Run: (dlg) => {
										let to = jQuery(".hpay_quick_buy_email:visible").val().trim();
										
										if(!to){
											hpay_alert_dialog(HolestPayAdmin.labels.enter_valid_email);
											return;
										}
										
										let emails = to.split(",").map(t=>t.trim()).filter(s=>!!s);
										
										if(emails.find(eml => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(eml))){
											hpay_alert_dialog(HolestPayAdmin.labels.enter_valid_email);
											return;
										}
										
										const encoder = new TextEncoder();
										const encodedBytes = encoder.encode(jQuery(".hpay_quick_buy_message:visible").html());
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
												subject: window.current_hpay_qb.title,
												bodyb64: bodyb64
											})
										}).then(r=>r.json()).then(r=>{
											HPay.exitClientWait();
											if(r && r.success){
												dlg.close();
												hpay_alert_dialog(HolestPayAdmin.labels.mail_sent);
											}else{
												hpay_alert_dialog(HolestPayAdmin.labels.mail_not_sent);	
												console.error(r);
											}
										}).catch(err => {
											HPay.exitClientWait();
											console.error(err);
											hpay_alert_dialog(HolestPayAdmin.labels.mail_not_sent);
										});
										
									},
									style:{
										padding:"10px 22px"
									}
								};
								
								hpay_dialog_open("hpay-send-quick-buy",r.title, r.body ,"medium", d ,() => {
										//
									});
								window.hpay_qb_render();
								
							}else{
								hpay_alert_dialog("ERROR in quick buy form!");
							}
						}).catch(err => {
							HPay.exitClientWait();
							console.error(err);
							hpay_alert_dialog("ERROR in quick buy form!");
						});
						
						
					}catch(ex){
						console.error(ex);
					}
				});
			});
		});
		
		
		
		
		
	});
	
})();