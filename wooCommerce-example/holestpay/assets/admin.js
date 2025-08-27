//HPAY WP ADMIN 
if(typeof jQuery !== 'undefined' ){
	
	let getRunningScript = function(){
			return () => {      
				return new Error().stack.match(/([^ \n])*([a-z]*:\/\/\/?)*?[a-z0-9\/\\]*\.js/ig)[0]
			}
	};
	
	let settings_panel = null;
	
	function testConnection(saveondiff){
		let environment = jQuery("#hpay_settings_page select[name='hpay_environment']").val() || HolestPayAdmin.settings.environment;
		if(HolestPayAdmin.settings[environment]){
			if(HolestPayAdmin.settings[environment].secret_token){
				if(settings_panel)
					settings_panel.removeClass("connected connecting disconnected").addClass("checking");
		
				let pos_connection = HolestPayAdmin.settings[environment];
				
				
				HPayInit(pos_connection.merchant_site_uid, HolestPayAdmin.hpaylang,{
						secretkeyinit: pos_connection.secret_token,
						environment: environment
					}, function(client){
						let save = false;
						
						if(client && client.POS && client.POS.posuts){
							
							if(!HolestPayAdmin.settings[environment + "POS"]){
								HolestPayAdmin.settings[environment + "POS"] = client.POS;
								save = true;
							}else if(HolestPayAdmin.settings[environment + "POS"].posuts != client.POS.posuts){
								HolestPayAdmin.settings[environment + "POS"] = client.POS;
								save = true;
							}
							
							if(saveondiff && save){
								saveHPaySettings(async function(success){
									//
								});
							}
							
							jQuery("#hpay_connection_info").html("(" + environment + ") " + "Merchant POS UID: " + HolestPayAdmin.settings[environment].merchant_site_uid + " <span style='color:green;font-size:2em;'>&#10003;</span>");
							if(settings_panel)
								settings_panel.removeClass("connecting disconnected checking").addClass("connected");
						}else{
							jQuery("#hpay_connection_info").html("(" + environment + ") " + "Merchant POS UID: " + HolestPayAdmin.settings[environment].merchant_site_uid + " <span style='color:red;font-size:2em;'>&#9888;</span>");
							if(settings_panel)
								settings_panel.removeClass("connected connecting checking").addClass("disconnected");
						}
					}).then(client => {
						
						
					}).catch(err => {
						jQuery("#hpay_connection_info").html("(" + environment + ") " + "Merchant POS UID: " + HolestPayAdmin.settings[environment].merchant_site_uid + " <span style='color:red;font-size:2em;'>&#9888;</span>");
						if(settings_panel)
							settings_panel.removeClass("connected connecting checking").addClass("disconnected");
						
					});
			}
			return;
		}
		
		jQuery("#hpay_connection_info").html("");
		if(settings_panel)
			settings_panel.removeClass("connected connecting checking").addClass("disconnected");
	}
	
	function saveHPaySettings(callback, partial, silent){
		
		if(partial){
			HolestPayAdmin.settings = Object.assign(HolestPayAdmin.settings, partial);
		}
		
		fetch(HolestPayAdmin.ajax_url + "?action=hpay-save-settings", {
			method: "POST",
			headers:{
				"Content-type": "application/json"
			},
			body: JSON.stringify(partial ? {
					nonce: HolestPayAdmin.nonce,
					update_settings: HolestPayAdmin.settings
				} : 
				{
					nonce: HolestPayAdmin.nonce,
					settings: HolestPayAdmin.settings
				})
		}).then(r => r.json()).then(resp => {
			if(resp.updated){
				if(callback)
					callback(resp.updated);
			}
		}).catch(err => {
			if(!silent)
				alert(HolestPayAdmin.labels.error_saving_settings);	
		})
	}
	
	window.hpay_init_order_list_toolbox = async function(order_toolbox,no_init){
		if(typeof HolestPayAdmin === 'undefined'){
			return {error: "Not initalized"};
		}
		
		if(!HolestPayAdmin || !HolestPayAdmin.settings || !HolestPayAdmin.settings[HolestPayAdmin.settings.environment + "POS"]){
			return {error: "Not initalized"};
		}
		
		let POS = HolestPayAdmin.settings[HolestPayAdmin.settings.environment + "POS"];
		
		if(POS.payment && POS.payment.length){
			POS.payment.forEach(pm => {
				try{
					if(pm.initActions){
						if(typeof pm.initActions === "string"){
							eval('pm.initActions = ' + pm.initActions);
						}
						pm.initActions();
					}
					if(pm.orderListActions){
						if(typeof pm.orderListActions === "string"){
							eval('pm.orderListActions = ' + pm.orderListActions);
						}
						let oactions = pm.orderListActions();
						
						if(oactions && oactions.length){
							
							jQuery("<h6></h6>").css("margin","5px 0px 0px 0px").html(pm["Backend Name"] || (pm.SystemTitle + " " + pm.Name)).appendTo(order_toolbox);
							
							oactions.forEach(item => {
								if(item.Run){
									
									jQuery("<button class='button button-primary'></button>").html(item.Caption).click(function(e){
										e.preventDefault();
										window.hpay_init().then(r=> {
											item.Run(order);
										});
									}).appendTo(order_toolbox);
									
								}else if(item.actions){
									let p = jQuery("<p></p>").html(item.Caption).appendTo(order_toolbox);	
									
									item.actions.forEach(subitem => {
										jQuery("<button class='button button-primary'></button>").html(subitem.Caption).click(function(e){
											e.preventDefault();
											window.hpay_init().then(r=> {
												subitem.Run(order);
											});
										}).appendTo(p);
									});
									
								}
							});
						}
					}
				}catch(ex){
					console.error(ex);
				}
			});
		}
		
		if(POS.fiscal && POS.fiscal.length){
			POS.fiscal.forEach(fm => {
				
				try{
					if(fm.initActions){
						if(typeof fm.initActions === "string"){
							eval('fm.initActions = ' + fm.initActions);
						}
						fm.initActions();
					}
					if(fm.orderListActions){
						if(typeof fm.orderListActions === "string"){
							eval('fm.orderListActions = ' + fm.orderListActions);
						}
						let oactions = fm.orderListActions();
						if(oactions && oactions.length){
							
							jQuery("<h6></h6>").css("margin","5px 0px 0px 0px").html(fm["Backend Name"] || (fm.SystemTitle + " " + fm.Name)).appendTo(order_toolbox);
							
							oactions.forEach(item => {
								if(item.Run){
									
									jQuery("<button class='button button-primary'></button>").html(item.Caption).click(function(e){
										e.preventDefault();
										window.hpay_init().then(r=> {
											item.Run(order);
										});
									}).appendTo(order_toolbox);
									
								}else if(item.actions){
									let p = jQuery("<p></p>").html(item.Caption).appendTo(order_toolbox);	
									
									item.actions.forEach(subitem => {
										jQuery("<button class='button button-primary'></button>").html(subitem.Caption).click(function(e){
											e.preventDefault();
											window.hpay_init().then(r=> {
												subitem.Run(order);
											});
										}).appendTo(p);
									});
									
								}
							});
						}
					}
				}catch(ex){
					console.error(ex);
				}
				
			});
		}
		
		if(POS.shipping && POS.shipping.length){
			POS.shipping.forEach(sm => {
				try{
					if(sm.initActions){
						if(typeof sm.initActions === "string"){
							eval('sm.initActions = ' + sm.initActions);
						}
						sm.initActions();
					}
					if(sm.orderListActions){
						if(typeof sm.orderListActions === "string"){
							eval('sm.orderListActions = ' + sm.orderListActions);
						}
						let oactions = sm.orderListActions();
						if(oactions && oactions.length){
							
							jQuery("<h6></h6>").css("margin","5px 0px 0px 0px").html(sm["Backend Name"] || (sm.SystemTitle + " " + sm.Name)).appendTo(order_toolbox);
							
							oactions.forEach(item => {
								if(item.Run){
									
									jQuery("<button class='button button-primary'></button>").html(item.Caption).click(function(e){
										e.preventDefault();
										
										window.hpay_init().then(r=> {
											item.Run();
										});
										
										
									}).appendTo(order_toolbox);
									
								}else if(item.actions){
									let p = jQuery("<p></p>").html(item.Caption).appendTo(order_toolbox);	
									
									item.actions.forEach(subitem => {
										jQuery("<button class='button button-primary'></button>").html(subitem.Caption).click(function(e){
											e.preventDefault();
											
											window.hpay_init().then(r=> {
												subitem.Run();
											});
										
										}).appendTo(p);
									});
								}
							});
						}
					}
				}catch(ex){
					console.error(ex);
				}
				
			});
		}
		
		return POS;
	};
	
	window.hpay_init = async function(no_init){
		if(typeof HPay === 'undefined' || !HPay){
			if(no_init){
				return {
					error: "Error in order toolbox init!"
				};
			}
			if(typeof HolestPayAdmin !== 'undefined'){
				if(HolestPayAdmin.settings && HolestPayAdmin.settings.environment && HolestPayAdmin.settings[HolestPayAdmin.settings.environment]){
					let pos = HolestPayAdmin.settings[HolestPayAdmin.settings.environment];
					return HPayInit(pos.merchant_site_uid, HolestPayAdmin.language,{
						secretkeyinit: pos.secret_token,
						environment: HolestPayAdmin.settings.environment
					}).then(client => {
						return HPay.POS;
					}).catch(err => {
						return {
							error: err
						}
					});
				}else{
					return {error: "Can not initalize"};
				}
			}else{
				return {error: "Can not initalize"};
			}
		}else{
			return HPay.POS;
		}
	};	
	
	window.hpay_init_order_panel = async function(order_uid, order_toolbox, use_wait, no_init, order){
		jQuery(order_toolbox).html("<div class='hpay-await-loader'></div>");		
		
		if(typeof HPay === 'undefined' || !HPay){
			return window.hpay_init().then(r => {
				if(r.error){
					hpay_alert_dialog("Error in order toolbox init!");
					return r;
				}else{
					return window.hpay_init_order_panel(order_uid, order_toolbox, use_wait, true);
				}
			});
		}
		
		if(!order){
		
			if(use_wait)
				HPay.enterClientWait();
		
			return HPay.getOrder(order_uid).then(ord => {
				if(use_wait)
					HPay.exitClientWait();
				
				if(typeof _orders === 'undefined'){
					window._orders = {};
				}
				window._orders[order_uid] = ord;
				
				return window.hpay_init_order_panel(order_uid, order_toolbox, use_wait, no_init, ord);
			}).catch(err => {
				if(use_wait)
					HPay.exitClientWait();
				
				console.log(err);
				
				return {error: err}
			});
		}
		
		jQuery(order_toolbox).html("");
		
		if(order.Uid){
			
			if(typeof _orders === 'undefined'){
				window._orders = {};
			}
			window._orders[order_uid] = order;
			
			
			jQuery("<p class='hpay-order-status'></p>").html(_orders[order_uid].Status).appendTo(order_toolbox);
			
			if(HPay.POS.payment && HPay.POS.payment.length){
				HPay.POS.payment.forEach(pm => {
					try{
						if(pm.initActions){
							if(typeof pm.initActions === "string"){
								eval('pm.initActions = ' + pm.initActions);
							}
							pm.initActions();
						}
						if(pm.orderActions){
							if(typeof pm.orderActions === "string"){
								eval('pm.orderActions = ' + pm.orderActions);
							}
							let oactions = pm.orderActions(_orders[order_uid]);
							
							if(oactions && oactions.length){
								jQuery("<h6></h6>").css("margin","5px 0px 0px 0px").html(pm["Backend Name"] || (pm.SystemTitle + " " + pm.Name)).appendTo(order_toolbox);
								oactions.forEach(item => {
									if(item.Run){
										
										jQuery("<button class='button button-primary'></button>").html(item.Caption).click(function(e){
											e.preventDefault();
											item.Run(_orders[order_uid]);
										}).appendTo(order_toolbox);
										
									}else if(item.actions){
										let p = jQuery("<p></p>").html(item.Caption).appendTo(order_toolbox);	
										
										item.actions.forEach(subitem => {
											jQuery("<button class='button button-primary'></button>").html(subitem.Caption).click(function(e){
												e.preventDefault();
												subitem.Run(_orders[order_uid]);
											}).appendTo(p);
										});
										
									}
								});
							}
						}
					}catch(ex){
						console.error(ex);
					}
				});
			}
			
			if(HPay.POS.fiscal && HPay.POS.fiscal.length){
				HPay.POS.fiscal.forEach(fm => {
					
					try{
						if(fm.initActions){
							if(typeof fm.initActions === "string"){
								eval('fm.initActions = ' + fm.initActions);
							}
							fm.initActions();
						}
						if(fm.orderActions){
							if(typeof fm.orderActions === "string"){
								eval('fm.orderActions = ' + fm.orderActions);
							}
							let oactions = fm.orderActions(_orders[order_uid]);
							if(oactions && oactions.length){
								
								jQuery("<h6></h6>").css("margin","5px 0px 0px 0px").html(fm["Backend Name"] || (fm.SystemTitle + " " + fm.Name)).appendTo(order_toolbox);
								
								oactions.forEach(item => {
									if(item.Run){
										
										jQuery("<button class='button button-primary'></button>").html(item.Caption).click(function(e){
											e.preventDefault();
											item.Run(_orders[order_uid]);
										}).appendTo(order_toolbox);
										
									}else if(item.actions){
										let p = jQuery("<p></p>").html(item.Caption).appendTo(order_toolbox);	
										
										item.actions.forEach(subitem => {
											jQuery("<button class='button button-primary'></button>").html(subitem.Caption).click(function(e){
												e.preventDefault();
												subitem.Run(_orders[order_uid]);
											}).appendTo(p);
										});
										
									}
								});
							}
						}
					}catch(ex){
						console.error(ex);
					}
					
				});
			}
			
			if(HPay.POS.shipping && HPay.POS.shipping.length){
				HPay.POS.shipping.forEach(sm => {
					try{
						if(sm.initActions){
							if(typeof sm.initActions === "string"){
								eval('sm.initActions = ' + sm.initActions);
							}
							sm.initActions();
						}
						if(sm.orderActions){
							if(typeof sm.orderActions === "string"){
								eval('sm.orderActions = ' + sm.orderActions);
							}
							let oactions = sm.orderActions(_orders[order_uid]);
							if(oactions && oactions.length){
								
								jQuery("<h6></h6>").css("margin","5px 0px 0px 0px").html(sm["Backend Name"] || (sm.SystemTitle + " " + sm.Name)).appendTo(order_toolbox);
								
								oactions.forEach(item => {
									if(item.Run){
										
										jQuery("<button class='button button-primary'></button>").html(item.Caption).click(function(e){
											e.preventDefault();
											item.Run(_orders[order_uid]);
										}).appendTo(order_toolbox);
										
									}else if(item.actions){
										let p = jQuery("<p></p>").html(item.Caption).appendTo(order_toolbox);	
										
										item.actions.forEach(subitem => {
											jQuery("<button class='button button-primary'></button>").html(subitem.Caption).click(function(e){
												e.preventDefault();
												subitem.Run(_orders[order_uid]);
											}).appendTo(p);
										});
									}
								});
							}
						}
					}catch(ex){
						console.error(ex);
					}
					
				});
			}
		}
		
		return order;
	};
	
	if(typeof HolestPayAdmin !== 'undefined'){
		
		if(!HolestPayAdmin.settings){
			HolestPayAdmin.settings = { environment: "sandbox" };
		}
		
		function hpayscripturl(file){
			if(!file)
				file = "hpay";
			
			let environ = "sandbox";
			if(HolestPayAdmin.settings && HolestPayAdmin.settings.environment){
				environ = HolestPayAdmin.settings.environment;
			}
			return "https://" + (environ == "sandbox" ? "sandbox." : "") + "pay.holest.com/clientpay/cscripts/" + file + ".js?verdeffer=34-" + HolestPayAdmin.plugin_version + "-" + (new Date()).toISOString().replace(/[^\d]/g,"").substring(0,8);
		}
		
		jQuery(document).ready(function(){
			
			 jQuery("a.hpayopen[href]:not([hpayopen])").each(function(ind){
				let openref = jQuery(this).attr("href").split("pay.holest.com/")[1];  
				if(openref){
					jQuery(this).attr("hpayopen",openref);	
				}
			 });
			 
			 let hload_script = (SCRIPT_URL, callback , async = true, type = "text/javascript") => {
				
				return new Promise((resolve, reject) => {
					try {
						if(window.HPayInit){
							if(callback){
								callback();
							}
							resolve({ status: true });
						}else{
							const scriptEle = document.createElement("script");
							scriptEle.type  = type;
							scriptEle.async = async;
							scriptEle.src   = SCRIPT_URL;

							scriptEle.addEventListener("load", (ev) => {
								if(callback){
									callback();
								}
								resolve({ status: true });
							});

							scriptEle.addEventListener("error", (ev) => {
								reject({
									error_code: -1,
									error: 'Failed to load the script'
								});
							});
							document.body.appendChild(scriptEle);
						}
					} catch (error) {
						reject(error);
					}
				});
			};
			
			hload_script(hpayscripturl("hpay.clientadmin.ui"), function(){
				//
			});
			
			hload_script(hpayscripturl(), function(){
				
				let hpayadmin_wnd = {};
				let hpayadmin_wnd_control = {};
				
				function hpaypanelurl(environ){
					if(!environ)
						environ = HolestPayAdmin.settings.environment;
					return "https://" + (environ == "sandbox" ? "sandbox." : "") + "pay.holest.com";
				}
				
				window.manageOnHolestPay = function (path, maxWidth, maxHeight, notimeout){
					
					let environ    = HolestPayAdmin.settings.environment;
					let company_id = HolestPayAdmin.settings[environ].company_id;
					let site_id    = HolestPayAdmin.settings[environ].site_id || "";
					let wnd_uid    = HolestPayAdmin.settings.environment + "_" + site_id;
					let hpay_url   = hpaypanelurl(environ);
					
					path = path.replace("/company_id", "/" + company_id).replace("/site_id", "/" + site_id);
					if(!path)
						path = "app";
					
					if(hpayadmin_wnd[ wnd_uid ]){
						
						if((hpayadmin_wnd_control[ wnd_uid ].path == "app" && path != "app")
							||	
						   (path == "app")
						){
							try{
								hpayadmin_wnd[ wnd_uid ].close();
								delete hpayadmin_wnd[ wnd_uid ];
								clearInterval(hpayadmin_wnd_control[wnd_uid].interval);
								delete hpayadmin_wnd_control[wnd_uid];
							}catch(ex){}
						}else{ 
							if(hpayadmin_wnd[ wnd_uid ].closed){
								delete hpayadmin_wnd[ wnd_uid ];
								clearInterval(hpayadmin_wnd_control[wnd_uid].interval);
								delete hpayadmin_wnd_control[wnd_uid];
							}else{
								setTimeout(function(){
									hpayadmin_wnd[ wnd_uid ].postMessage({"command": "navigate", "link": path}, hpay_url);	
									
									if (window.focus) {
										hpayadmin_wnd[wnd_uid].focus();
									}
								
								},50);
								return;
							}
						}
					}
					
					let h = parseInt(jQuery(window).innerHeight() * 0.8);
					let w = parseInt(jQuery(window).innerWidth() * 0.8);
					
					if(maxHeight && h > maxHeight){
						h = maxHeight;
					}	
					
					if(maxWidth && w > maxWidth){
						w = maxWidth;
					}
					
					let wprops = 'height=' + h + ',width=' + w;
					if(window.scrollLeft && window.scrollTop){
						if(window.scrollLeft){
							wprops += (",left=" + (window.scrollLeft + (jQuery(window).innerWidth() - w)/2)); 
						}
						if(window.scrollTop){
							wprops += (",left=" + (window.scrollTop + (jQuery(window).innerHeight() - h)/2)); 
						}
					}
					
					if(path == "app"){
						hpayadmin_wnd[wnd_uid] = window.open(hpay_url + "?wmanage=" + encodeURIComponent(path) + "&origin=" + encodeURIComponent(window.location.origin) + "&company_id=" + company_id + "&site_id=" + site_id + "&lang=" + HolestPayAdmin.hpaylang,"HolestPay: " + environ);
					}else{
						hpayadmin_wnd[wnd_uid] = window.open(hpay_url + "?wmanage=" + encodeURIComponent(path) + "&origin=" + encodeURIComponent(window.location.origin) + "&company_id=" + company_id + "&site_id=" + site_id + "&lang=" + HolestPayAdmin.hpaylang,"HolestPay: " + environ,wprops);
					}
					
					if (window.focus) {
						hpayadmin_wnd[wnd_uid].focus();
					}
					
					hpayadmin_wnd_control[wnd_uid] = {
						message_session_uid: "pmesssession" + parseInt(Math.random() * 999999),
						subscribes_sent_nr: 0
					};
					
					let selfw = window;
					
					hpayadmin_wnd_control[ wnd_uid ].path = path;
					
					hpayadmin_wnd_control[wnd_uid].interval = setInterval(function(){
						
						if(hpayadmin_wnd[wnd_uid].closed){
							delete hpayadmin_wnd[ wnd_uid ];
							clearInterval(hpayadmin_wnd_control[wnd_uid].interval);
							delete hpayadmin_wnd_control[wnd_uid];
							return;
						}
						
						hpayadmin_wnd_control[wnd_uid].subscribes_sent_nr++;
						hpayadmin_wnd[wnd_uid].postMessage({command: "subscribe_pos_updates", message_session_uid: hpayadmin_wnd_control[wnd_uid].message_session_uid}, hpay_url);	
						
						try{
							sessionStorage["hpayses_" + hpayadmin_wnd_control[wnd_uid].message_session_uid] = wnd_uid;
						}catch(ex){
							
						}
						
						if(!notimeout && hpayadmin_wnd_control[wnd_uid].subscribes_sent_nr > 12){
							try{
								hpayadmin_wnd[ wnd_uid ].close();
							}catch(cex){}
							
							delete hpayadmin_wnd[ wnd_uid ];
							clearInterval(hpayadmin_wnd_control[wnd_uid].interval);
							delete hpayadmin_wnd_control[wnd_uid];
							
							if(selfw.focus){
								selfw.focus();
							}
							
							alert(HolestPayAdmin.labels.noncontactable);
						}
					},1000);
				}
				
				function onHPayPostMessage(event){
					
					Object.keys(hpayadmin_wnd).forEach( wnd_uid => {
						if(hpayadmin_wnd[ wnd_uid ].closed){
							delete hpayadmin_wnd[ wnd_uid ];
							clearInterval(hpayadmin_wnd_control[wnd_uid].interval);
							delete hpayadmin_wnd_control[wnd_uid];
						}	
					});
					
					if (event.origin.indexOf( hpaypanelurl().replace("https://","").replace("sandbox.","")) === -1) return; 
					
					if(event.data.subscribed_pos_updates){
						for(let wnd_uid in hpayadmin_wnd_control){
							if(hpayadmin_wnd_control.hasOwnProperty(wnd_uid)){
								if(hpayadmin_wnd_control[wnd_uid].message_session_uid == event.data.subscribed_pos_updates){
									hpayadmin_wnd_control[wnd_uid].subscribes_sent_nr = 0;
								}
							}
						}
					}
					
					if(typeof HPay === 'undefined') return;
					if(!HPay) return;
					
					if(event.data.hpay_pos_data_update){
						if(HolestPayAdmin.settings && HolestPayAdmin.settings[event.data.environment + "POS"]){
							let current_posconnection_data = HolestPayAdmin.settings[event.data.environment];
							if(current_posconnection_data){
								if(current_posconnection_data.company_id == event.data.company_id){
									if(!current_posconnection_data.site_id || current_posconnection_data.site_id === current_posconnection_data.site_id){
										HPay.syncPOS(function(POS){
											if(POS && !POS.error){
												let partial = {};
												
												let old = HolestPayAdmin.settings[event.data.environment + "POS"] || {};
												partial[event.data.environment + "POS"] = POS;
												saveHPaySettings(function(success){
													if(jQuery("button[hpayrefreshpagescope='shippingmethods'],a[hpayrefreshpagescope='shippingmethods'],div[hpayrefreshpagescope='shippingmethods']")[0]){
														if(JSON.stringify(old.shipping) != JSON.stringify(POS.shipping)){
															window.location.replace(window.location.href);
														}
													}
													
													if(jQuery("button[hpayrefreshpagescope='paymentmethods'],a[hpayrefreshpagescope='paymentmethods'],div[hpayrefreshpagescope='paymentmethods']")[0]){
														if(JSON.stringify(old.payment) != JSON.stringify(POS.payment)){
															window.location.replace(window.location.href);
														}
													}
												}, partial);
											}
										});
									}
								}
							}
						}
					}
				}
				
				window.addEventListener("message",onHPayPostMessage,false);
				
				jQuery(document).on("click","a[hpayopen],button[hpayopen],a.hpayopen[href]", function(e){
						
						let mpath = "";
						if(!jQuery(this).attr("hpayopen")){
							mpath = jQuery(this).attr("href").split("pay.holest.com/")[1];
						}else{
							mpath = jQuery(this).attr("hpayopen");
						}
						
						if(mpath){
							e.preventDefault();
							manageOnHolestPay(mpath, 1024, undefined, jQuery(this).is("#cmdOpenHpay"));
						}
				});
					
				jQuery(document).ready(function(){
					if(jQuery(".hpayautoopen[hpayopen]")[0]){
						jQuery(".hpayautoopen[hpayopen]").trigger("click");
					}
				});	
					
				if(jQuery("#hpay_settings_page")[0]){
					
					if(!/https/i.test(window.location.href)){
						alert(HolestPayAdmin.labels.https_required);
					}
					
					settings_panel = jQuery("#hpay_settings_page");
					
					if(HolestPayAdmin.settings && settings_panel[0]){
						for(let prop in HolestPayAdmin.settings){
							if(HolestPayAdmin.settings.hasOwnProperty(prop)){
								
								if(prop == "custom_plugin_integrations"){
									hpay_show_custom_integrations(HolestPayAdmin.settings.custom_plugin_integrations);
									continue;
								}
								
								let inp = settings_panel.find("*[name='hpay_" + prop + "']")[0];
								if(inp){
									if(jQuery(inp).is("input[type='checkbox']")){
										jQuery(inp).prop("checked",HolestPayAdmin.settings[prop] ? (HolestPayAdmin.settings[prop] == "no" ? false : true) : false);	
										if(jQuery(inp).is(".hpay-trigger-on-set")){
											setTimeout(function(){
												jQuery(inp).trigger("change");
											},150);
										}
									}else if(jQuery(inp).is("input[type='radio']")){
										settings_panel.find("input[name='hpay_" + prop + "'][value='" + String(HolestPayAdmin.settings[prop]) + "']").prop("checked",true);
									}else{
										jQuery(inp).val(HolestPayAdmin.settings[prop]);	
									}
								}
							}
						}
					}
					
					testConnection(true);
					
					checkWooPendingStatus();
					
					jQuery(".hpay_bottom_bar").appendTo("BODY");
					
					jQuery("#cmdOpenHpay").attr("href", hpaypanelurl());
					
					jQuery(document).on("change", "#hpay_settings_page select[name='hpay_environment']", function(e){
						jQuery("#cmdOpenHpay").attr("href", hpaypanelurl(jQuery(this).val()));
						testConnection(false);
						jQuery("#cmdSaveHpay").show();
					});
					
					jQuery(document).on("change", "#hpay_settings_page *[name^='hpay_']", function(e){
						
						if(jQuery(this).is("input[type='checkbox']")){
							HolestPayAdmin.settings[jQuery(this).attr("name").replace("hpay_","")] = jQuery(this).prop("checked");
						}else if(jQuery(this).is("input[type='radio']")){
							HolestPayAdmin.settings[jQuery(this).attr("name").replace("hpay_","")] = settings_panel.find("input[type='radio'][name='" + jQuery(this).attr("name") + "']:checked").attr("value");
						}else
							HolestPayAdmin.settings[jQuery(this).attr("name").replace("hpay_","")] = jQuery(this).val();
						
						if(jQuery(this).attr("name") != "hpay_environment")
							jQuery("#cmdSaveHpay").show();
					});
					
					jQuery(document).on("click", "#cmdSaveHpay", function(e){
						
						saveHPaySettings(function(success){
							jQuery("#cmdSaveHpay").hide();	
						});
						
					});
					
					jQuery(document).on("click", "#hpay_settings_page #cmdDisconnectHpay", function(e){
						e.preventDefault();
						let environment = jQuery("#hpay_settings_page select[name='hpay_environment']").val();
						if(confirm(environment + ": " + HolestPayAdmin.labels.disconnect_question)){
							
							delete HolestPayAdmin.settings[environment];	
							delete HolestPayAdmin.settings[environment + "POS"];
							
							saveHPaySettings(function(success){
								settings_panel.removeClass("connecting connected").addClass("disconnected");
							});
						}
					});
					
					jQuery(document).on("click", "#hpay_settings_page #cmdConnectHpay", function(e){
						e.preventDefault();
						
						try{
							if(hpayadmin_wnd[ wnd_uid ]){
								hpayadmin_wnd[ wnd_uid ].close();
								delete hpayadmin_wnd[ wnd_uid ];
								clearInterval(hpayadmin_wnd_control[wnd_uid].interval);
								delete hpayadmin_wnd_control[wnd_uid];
							}
						}catch(ex){}
						
						let environment = jQuery("#hpay_settings_page select[name='hpay_environment']").val();
						
						let h = parseInt(jQuery(window).innerHeight() * 0.8);
						let w = parseInt(jQuery(window).innerWidth() * 0.8);
						if(h > 720){
							h = 720;
						}	
						if(w > 720){
							w = 720;
						}	
						let wprops = 'height=' + h + ',width=' + w;
						if(window.scrollLeft && window.scrollTop){
							if(window.scrollLeft){
								wprops += (",left=" + (window.scrollLeft + (jQuery(window).innerWidth() - w)/2)); 
							}
							if(window.scrollTop){
								wprops += (",top=" + (window.scrollTop + (jQuery(window).innerHeight() - h)/2)); 
							}
						}
						
						let hpay_url = hpaypanelurl(environment);
						let hpaywindow=window.open(hpay_url + "/sites?addsite=" + encodeURIComponent(HolestPayAdmin.site_url) + "&addsite_notify_url=" + encodeURIComponent(HolestPayAdmin.notify_url) + "&lang=" + HolestPayAdmin.hpaylang,"HolestPay: " + environment,wprops);
						
						if (hpaywindow.focus) {
							hpaywindow.focus();
						}
						
						let loader = jQuery("<div class='hpay-loader'></div>").insertBefore(this);
						
						jQuery("#hpay_settings_page select[name='hpay_environment']").prop("disabled",true);
						let settings_panel_class      = settings_panel.attr("class");
						let hpay_connection_info_text = jQuery("#hpay_connection_info").html();
						
						settings_panel.removeClass("connected disconnected checking").addClass("connecting");
						
						let lastResponse = (new Date()).getTime();
						let responses_recceived = 0;
						let connect_pend_interval  = null;
						
						let onConnectMessage = function(event){
							  if (event.origin.indexOf(hpay_url.replace("https://","")) === -1) return;
							  if(event.data){
								  lastResponse = (new Date()).getTime();
								  responses_recceived++;
								  if(event.data.window_closed){
									  if(connect_pend_interval){
										  
										  clearInterval(connect_pend_interval);
										  connect_pend_interval = null;
										  loader.remove();
										  window.removeEventListener("message",onConnectMessage,false);
										  
										  settings_panel.attr("class",settings_panel_class);
										  jQuery("#hpay_connection_info").html(hpay_connection_info_text);
										  jQuery("#hpay_settings_page select[name='hpay_environment']").prop("disabled",false);
									  }
								  }else if(event.data.addsite_status_message){
									  jQuery("#hpay_connection_info").html("HolestPay: " + event.data.addsite_status_message);
									  if(event.data.addedsite){
										
										jQuery("#cmdSaveHpay").show();		
										
										clearInterval(connect_pend_interval);
										connect_pend_interval = null;
										loader.remove();
										window.removeEventListener("message",onConnectMessage,false);
										
										let connect_response = JSON.parse(event.data.addedsite);
										
										hpaywindow.postMessage({command: "close"}, hpay_url);
										
										jQuery("#hpay_connection_info").html("(" + environment + ") " + "Merchant POS UID: " + connect_response.merchant_site_uid  + " <span style='color:green;font-size:2em;'>&#10003;</span>");
										jQuery("#hpay_settings_page select[name='hpay_environment']").prop("disabled",false);
										window.HPayInit(connect_response.merchant_site_uid, HolestPayAdmin.language,{
												secretkeyinit: connect_response.secret_token,
												environment: environment
											}, function(client){
												
												if(client && client.POS && client.POS.posuts){
													HolestPayAdmin.settings.environment = environment;
													HolestPayAdmin.settings[environment]         = connect_response;
													HolestPayAdmin.settings[environment + "POS"] = client.POS;
													saveHPaySettings(async function(success){
														settings_panel.removeClass("connecting disconnected checking").addClass("connected");
														
														HolestPayAdmin.settings.environment          = environment;
														jQuery("#cmdSaveHpay").show();
														
													});
												}else{
													settings_panel.attr("class",settings_panel_class);
													jQuery("#hpay_connection_info").html(hpay_connection_info_text);
													jQuery("#hpay_settings_page select[name='hpay_environment']").prop("disabled",false);
												}
											});
									  }
								  }
							  }
						};
						
						window.addEventListener("message",onConnectMessage,false);
						
						connect_pend_interval = setInterval(function(){
							hpaywindow.postMessage({command: "addsite_status"}, hpay_url); 
							if(hpaywindow.closed || lastResponse + 10000 < (new Date()).getTime()){
							   clearInterval(connect_pend_interval);
							   connect_pend_interval = null;
							   loader.remove();
							   window.removeEventListener("message",onConnectMessage,false);
							   
							   settings_panel.attr("class",settings_panel_class);
							   jQuery("#hpay_connection_info").html(hpay_connection_info_text);
							   jQuery("#hpay_settings_page select[name='hpay_environment']").prop("disabled",false);
							   if(!responses_recceived){
								   alert(HolestPayAdmin.labels.noncontactable);
							   }
							}
						},1000);
						
						window.addEventListener("beforeunload",function(){
							hpaywindow.postMessage({command: "close"}, hpay_url);
							hpaywindow.close();
						});
						
					});
				}else{
					if(jQuery("button[hpayrefreshpagescope],a[hpayrefreshpagescope],div[hpayrefreshpagescope],button[hpayopen],a[hpayopen],div[hpayopen]")[0]){
						if(HolestPayAdmin.settings && HolestPayAdmin.settings.environment && HolestPayAdmin.settings[HolestPayAdmin.settings.environment]){
							
							let pos = HolestPayAdmin.settings[HolestPayAdmin.settings.environment];
							HPayInit(pos.merchant_site_uid, HolestPayAdmin.language,{
								secretkeyinit: pos.secret_token,
								environment: HolestPayAdmin.settings.environment
							}).then(client => {
								
								////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
								/*//IN admin_wc.js
								document.addEventListener("onHPayOrderOpExecuted", function(evt){
									HPay.enterClientWait();
									
									if(evt.order_op_response){
										
									   if(evt.order_op_response.error){
										   hpay_alert_dialog("<h3>" + evt.operation.toUpperCase() + " ERROR:<h3>" +  "<pre>" + JSON.stringify(evt.order_op_response.error, null, 2).replace(/\"|\{|\}|\,/g,"").replace(/\_/g," ") + "</pre>","normal").then(aletred => {
												//
										   });
									   }else if(evt.order_op_response.order){	
										   fetch( HolestPayAdmin.notify_url + "&topic=orderupdate", {
											   method: "POST",
											   headers:{
												 "Content-type": "application/json"
											   },
											   body: JSON.stringify(evt.order_op_response)
											  }
										   ).then(r => r.json()).then(r => {
											   HPay.exitClientWait();
											   hpay_alert_dialog("<h3>" + evt.operation.toUpperCase() + ":<h3>" +  "<pre>" + JSON.stringify(r, null, 2).replace(/\"|\{|\}|\,/g,"").replace(/\_/g," ") + "</pre>","normal").then(aletred => {
												   HPay.enterClientWait();
												   window.location.reload();
											   });
										   }).catch(err => {
											   window.location.reload();	
										   });
									   }else{
										   hpay_alert_dialog("<h3>" + evt.operation.toUpperCase() + ":<h3>" +  "<pre>" + JSON.stringify(evt.order_op_response, null, 2).replace(/\"|\{|\}|\,/g,"").replace(/\_/g," ") + "</pre>","normal").then(aletred => {
												//
										   });
									   }
									}else{
										setTimeout(function(){
											window.location.reload();	
										},1500);
									}									
								});
								*/
								
								if(typeof HolestPayAdmin !== 'undefined' && HPay && HPay.POS && jQuery("div[hpay_order_action_toolbox]")[0]){
									let order_toolbox = jQuery(jQuery("div[hpay_order_action_toolbox]")[0]);
									let order_uid = order_toolbox.attr("hpay_order_action_toolbox");
									
									window.hpay_init_order_panel(order_uid, order_toolbox);
								}
								////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
								
							})
						}
					}
				}
				
				try{
					let event = new Event("onHpayScriptLoaded");
					document.dispatchEvent(event);
				}catch(ex){
					console.error(ex);
				}
				
			});
		});
		
		let zindex = 999999;
		let hpay_PresentPopup = function(popup_class, title, content, footer){
		 let pop = jQuery("." + popup_class.replace(/^\./,""));
		 if(!pop[0]){
			pop = jQuery("<div class='hpay_popup'><div class='hpay_popup_wrapper' ><div class='hpay_popup_top'></div><div class='hpay_popup_content'></div><div class='hpay_popup_footer'></div></div></div>").addClass(popup_class).appendTo(jQuery("BODY"));
			jQuery("<h4 class='hpay_popup_title'></h4>").appendTo(pop.find(".hpay_popup_top"));
			jQuery("<a class='hpay_popup_close'>&nbsp;</a>").appendTo(pop.find(".hpay_popup_top"));
		 }
		 if(content){
			 try{
				 if(jQuery(title).html()){
					pop.find(".hpay_popup_title > *").remove();
					pop.find(".hpay_popup_title").append(jQuery(title));
				 }else
					pop.find(".hpay_popup_title").html(title);
			 }catch(ex){
				 pop.find(".hpay_popup_title").html(title);
			 }
			 
			 pop.find(".hpay_popup_content *").remove();
			 
			 if(typeof content === "string"){
				pop.find(".hpay_popup_content").append(jQuery("<div class='popup_inner'></div>").html(content));	 
			 }else{
				pop.find(".hpay_popup_content").append(jQuery("<div class='popup_inner'></div>").append(jQuery(content))); 
			 }
			 
			 try{
				 if(jQuery(footer).html()){
					pop.find(".hpay_popup_footer > *").remove();
					pop.find(".hpay_popup_footer").append(jQuery(footer));	 
				 }else
					pop.find(".hpay_popup_footer").html(footer);
			 }catch(ex){
				 pop.find(".hpay_popup_footer").html(footer);
			 }
		 }
		 
		 zindex++;
		 jQuery("." + popup_class).css("zIndex",zindex);
		 jQuery("." + popup_class).show();
		 jQuery("body").addClass("hpay-popup-shown");
		};

		let hpay_closePopup = function(popup_class){
			jQuery("." + popup_class).hide();
			if(!jQuery(".hpay_popup:visible")[0]){
				jQuery("body").removeClass("hpay-popup-shown");
			}
		};

		jQuery(document).on("click",".hpay_popup_close",function(e){
			e.preventDefault(); 
			jQuery(this).closest(".hpay_popup").hide(); 
			if(!jQuery(".hpay_popup:visible")[0]){
				jQuery("body").removeClass("hpay-popup-shown");
			}
		});

		jQuery(document).on("click",".hpay_popup_wrapper",function(e){
			e.stopImmediatePropagation();
		});

		jQuery(document).on("click",".hpay_popup",function(e){
			jQuery(this).find("a.hpay_popup_close").trigger("click");
		});

		function hpay_elementizeObject(obj){
			
			let el = jQuery('<span><prop></prop><value></value></span>');	
			let val_el = el.find("value");
			
			if(!obj){
				el.attr("property_type","simple");
			}else if(typeof obj === 'object'){
				if(Array.isArray(obj)){
					el.attr("property_type","array");
					for(let prop in obj){
						if(obj.hasOwnProperty(prop)){
							let sub = elementizeObject(obj[prop]);
							sub.find("> prop").html(prop);
							val_el.append(sub);
						}
					}
				}else{
					el.attr("property_type","object");
					for(let prop in obj){
						if(obj.hasOwnProperty(prop)){
							let sub = elementizeObject(obj[prop]);
							sub.find("> prop").html(prop);
							val_el.append(sub);
						}
					}
				}
			}else{
				el.attr("property_type","simple");
				val_el.html(obj);
			}
			return el;
		}

		jQuery(document).on("click","#hpay_cmdSeeCustomCodingInstructions",function(e){
			e.preventDefault();
			hpay_PresentPopup("hpay_coding_instructions",HolestPayAdmin.labels.coding_Ref,"<iframe src='" + HolestPayAdmin.plugin_url.replace(/\/$/,"") + "/assets/custom_integration.html'></iframe>")
		});

		jQuery(document).on("click","#hpay_runSmartTool",function(e){
			e.preventDefault();
			
			hpay_PresentPopup("hpay_smart_tool_popup md-popup",HolestPayAdmin.labels.smart_subscriptions_setup, jQuery(".hpay_smart_tool_wizzard")[0], '<div><button class="button button-primary hpay_smart_wizard_prev">' + HolestPayAdmin.labels.back + '</button ><button class="button button-primary hpay_smart_wizard_next">' + HolestPayAdmin.labels.next + '</button></div>');
			
			jQuery(".hpay_smart_tool_popup").attr("wizzard_step",1);
			
			jQuery(".hpay_smart_tool_wizzard > *").hide();
			jQuery(".hpay_smart_tool_wizzard > div.hpay_wizzard_step_1").show();
		});
		
		jQuery(document).on("change","select.hpay_this_field_is", function(e){
			
			
			if(jQuery(this).data("prev_value")){
				let rel = "";
				let pvalue = jQuery(this).data("prev_value");
				let pinp = jQuery(".hpay_smart_tool_bindings input[bindprop='" + pvalue + "']");
				rel = jQuery(this).closest("li").find(".hpay_field_relation").html().trim();
				
				if(pinp[0] && String(pinp.val()).indexOf(rel) > -1){
					if(pinp.attr("bindmulti") == "and" || pinp.attr("bindmulti") == "or"){
						pinp.val(pinp.val().split(/ and | or /i).map(t => t.trim()).filter(t => !(t == rel || t.indexOf( rel + " " ) > -1)).join(" " + pinp.attr("bindmulti") + " "));
					}else{
						pinp.val("");
					}
				}
			}
			
			if(jQuery(this).val()){
				let rel = "";
				let pinp = jQuery(".hpay_smart_tool_bindings input[bindprop='" + jQuery(this).val() + "']");
				rel = jQuery(this).closest("li").find(".hpay_field_relation").html().trim();
				if(pinp.attr("bindmulti") == "and" || pinp.attr("bindmulti") == "or"){
					let val = jQuery(this).closest("li").find("span[prop='value']").html().trim();
					rel = rel + " = " + val;
					if(pinp.val().trim()){
						pinp.val(String(pinp.val()) + " " + pinp.attr("bindmulti") + " " + rel);
					}else{
						pinp.val(rel);
					}
				}else{
					pinp.val(rel);
				}
			}
			
			jQuery(this).data("prev_value",jQuery(this).val());
			jQuery(this).attr("selectedvalue", jQuery(this).val() || "");
		});
		
		jQuery(document).on("click",".hpay_smart_tool_wizzard .hpay_wizzard_step_1 .hpay-select-sample-post", function(e){
			e.preventDefault();
			
			jQuery(".hpay_smart_tool_wizzard .hpay_wizzard_step_1").hide();
			jQuery(".hpay_smart_tool_wizzard .hpay_wizzard_step_2").show();
			
			jQuery(".hpay_smart_tool_popup").attr("wizzard_step",2);
			
			
			fetch(HolestPayAdmin.ajax_url + "?action=hpay-data-search", {
					method: "POST",
					headers:{
						"Content-type": "application/json"
					},
					body: JSON.stringify({
							nonce: HolestPayAdmin.nonce,
							post_id: parseInt(jQuery(this).attr("post_id")),
							post_parent: parseInt(jQuery(this).attr("post_parent")) || 0,
							what: "post_rels_and_meta"
						})
				}).then(r => r.json()).then(resp => {
					if(resp.result){
						
						let model = jQuery(".hpay_smart_tool_refs .hpay_row_model");
						let list = jQuery(".hpay_smart_tool_refs .hpay_smart_tool_detected_meta");
						list.find("> *").remove();
						
						let sub_props = {};
						let n = 0;
						jQuery(".hpay_smart_tool_bindings input[bindprop]").each(function(int){
							let fld = {
								bindprop: jQuery(this).attr("bindprop"),
								bindmulti: jQuery(this).attr("bindmulti") || "",
								name: jQuery(this).closest("tr").prev().find("th").html().trim().replace(/\s\*$/,"").trim(),
								index: n
							};
							n++;
							sub_props[jQuery(this).attr("bindprop")] = fld;
						});
						
						let price_set, interval_set, interval_unit_set; 
						
						Object.values(resp.result).forEach(function(m){
							let r = model.clone(false,false).attr("style","").attr("class","");
							r.find("[prop='field_relation']").html(m.field_relation);
							r.find("[prop='value']").html(m.value);
							let sel = r.find("select.hpay_this_field_is");
							
							Object.keys(sub_props).forEach(k => {
								jQuery("<option></option>").attr("value",k).html(sub_props[k].name).appendTo(sel);
							});
							
							list.append(r);
							
							if(!price_set && m.field_relation == "@meta._price"){
								sel.val('subsciption_amount');
								price_set = true;
								sel.trigger("change");
							}else if(!interval_set && parseInt(m.value) && /^\d*$/.test(String(m.value))){
								sel.val('interval');
								interval_set = true;
								sel.trigger("change");
							}else if(!interval_unit_set && m.value && /^(da|mo|year|wee)/.test(String(m.value))){
								sel.val('interval_unit');
								interval_unit_set = true;
								sel.trigger("change");
							}
							
							sel.attr("selectedvalue", sel.val() || "");
						});
					}
				}).catch(err => {
					console.error(err);
				})
		});
		
		let hpay_smart_tool_search_handle = null;
		function hpay_smart_tool_search_fn(){
			if(jQuery("#hpay_smart_tool_search").val().trim().length){
				
				let search = jQuery("#hpay_smart_tool_search").val().trim();
				
				let pt = null;
				if(HolestPayAdmin.settings.mode == 'woocommerce'){
					pt = {
						post_types:["product","product_variation"]
					};
				}
				
				if(!window.__hpay_smart_tool_search_no){
					window.__hpay_smart_tool_search_no = 1;
				}else{
					window.__hpay_smart_tool_search_no++;
				}
				
				let hpay_smart_tool_search_no = window.__hpay_smart_tool_search_no;
				
				fetch(HolestPayAdmin.ajax_url + "?action=hpay-data-search", {
					method: "POST",
					headers:{
						"Content-type": "application/json"
					},
					body: JSON.stringify({
							nonce: HolestPayAdmin.nonce,
							search: search,
							what: "posts",
							...pt
						})
				}).then(r => r.json()).then(resp => {
					
					if(hpay_smart_tool_search_no != window.__hpay_smart_tool_search_no){
						return;
					}
					
					if(resp.result){
						let list = jQuery(".hpay_smart_tool_wizzard .hpay_wizzard_step_1 table tbody");
						let model = jQuery(".hpay_smart_tool_wizzard .hpay_wizzard_step_1 table tfoot .hpay_row_model");
						list.find("> *").remove();
						
						for(let ID in resp.result){
							if(resp.result.hasOwnProperty(ID)){
								
								let row = model.clone(false,false).attr("style","").attr("class","");
								row.appendTo(list);
								
								row.find(".hpay-select-sample-post").attr("post_id", ID).attr("post_parent", resp.result[ID].post_parent);
								
								row.find("*[prop]").each(function(ind){
									jQuery(this).html(resp.result[ID][jQuery(this).attr("prop") || ""]);
								});
								
								if(resp.result[ID].children){
									for(let CID in resp.result[ID].children){
										if(resp.result[ID].children.hasOwnProperty(CID)){
											row = model.clone(false,false).attr("style","").attr("class","").addClass("chield");
											row.appendTo(list);
											
											row.find(".hpay-select-sample-post").attr("post_id", CID).attr("post_parent", resp.result[ID].children[CID].post_parent);
											
											row.find("*[prop]").each(function(ind){
												jQuery(this).html(resp.result[ID].children[CID][jQuery(this).attr("prop") || ""]);
											});
										}
									}
								}
								
							}
						}
					}
				}).catch(err => {
					//
				})
			}
		}

		jQuery(document).on("input","#hpay_smart_tool_search", function(e){
			if(hpay_smart_tool_search_handle){
				clearTimeout(hpay_smart_tool_search_handle);
				hpay_smart_tool_search_handle = null;
			}
			if(jQuery(this).val().length){
				hpay_smart_tool_search_handle = setTimeout(hpay_smart_tool_search_fn,400);	
			}
		});
		
		jQuery(document).on("click","a.hpay_remove_custom_inergation",function(e){
			e.preventDefault();
			if(confirm(HolestPayAdmin.labels.remove_custom_integration)){
				let ind = parseInt(jQuery(this).closest("p").attr("integartion_index"));
				HolestPayAdmin.settings.custom_plugin_integrations.splice(ind,1);
				hpay_show_custom_integrations(HolestPayAdmin.settings.custom_plugin_integrations);
				jQuery("#cmdSaveHpay").show();
			}
		});
		
		function checkWooPendingStatus(){
			let has_pending = false;
			jQuery(".hpay-woo-order-status-mappings select").each(function(ind){
				if(jQuery(this).val() == "wc-pending"){
					has_pending = true;
				}else if(jQuery(this).attr("name") == "hpay_woo_status_map_created" && !jQuery(this).val()){
					has_pending = true;
				}
			});
			
			if(has_pending){
				jQuery(".hpay-wc-pending-warning").show();
			}else{
				jQuery(".hpay-wc-pending-warning").hide();
			}
		}
		
		jQuery(document).on("change",".hpay-woo-order-status-mappings select", function(e){
			checkWooPendingStatus();
		});
		
		function hpay_show_custom_integrations(custom_plugin_integrations){
			jQuery(".hpay_custom_integrations").html("");
			
			if(custom_plugin_integrations && custom_plugin_integrations.length){
				custom_plugin_integrations.forEach((cint, index) => {
					
					let info = [];
					Object.keys(cint).forEach(k => {
						info.push(k + ": " + cint[k]);
					});
					let intdatainfo = jQuery("<span></span>").attr("integartion_index", index).html(info.join(", "));
					let introw = jQuery("<p></p>").attr("label", HolestPayAdmin.labels.custom_integration + " " + (index + 1) + ": " ).appendTo(jQuery(".hpay_custom_integrations"));
					
					intdatainfo.appendTo(introw);
					jQuery("<a class='hpay_remove_custom_inergation hpay-remove-cmd'>&times;</a>").appendTo(introw);
					
				});
			}
		}
		
		function hpay_set_wizzard_step(step){
			
			let from_step = jQuery(".hpay_smart_tool_popup").attr("wizzard_step");
			
			if(step == 4){
				let custom_plugin_integration = {};
				jQuery(".hpay_smart_tool_wizzard input[bindprop],.hpay_smart_tool_wizzard select[bindprop],.hpay_smart_tool_wizzard textarea[bindprop]").each(function(ind){
					if(String(jQuery(this).val()).trim()){
						if(jQuery(this).is("[type='checkbox']")){
							custom_plugin_integration[jQuery(this).attr('bindprop')] = this.checked;
						}else{
							custom_plugin_integration[jQuery(this).attr('bindprop')] = String(jQuery(this).val()).trim().replace(/  /g," ");
						}
					}
				});
				
				if(!HolestPayAdmin.settings.custom_plugin_integrations)
					HolestPayAdmin.settings.custom_plugin_integrations = [];
				HolestPayAdmin.settings.custom_plugin_integrations.push(custom_plugin_integration);
				hpay_show_custom_integrations(HolestPayAdmin.settings.custom_plugin_integrations);
				hpay_closePopup('hpay_smart_tool_popup');
				jQuery("#cmdSaveHpay").show();
			}
			
			if(step == 3){
				jQuery(".hpay_smart_wizard_next").html(HolestPayAdmin.labels.finish);
			}else{
				jQuery(".hpay_smart_wizard_next").html(HolestPayAdmin.labels.next);
			}
			
			if(step == 3){
				if(from_step == 2){
					let valid = true;
					jQuery(".hpay_smart_tool_bindings input[required]").each(function(ind){
						if(!String(jQuery(this).val()).trim()){
							valid = false;	
						}
					});
					
					if(!valid){
						alert(HolestPayAdmin.labels.required_missing);
						return;
					}
				}else{
					
				}
			}
			
			jQuery(".hpay_smart_tool_popup").attr("wizzard_step",step);
			jQuery(".hpay_smart_tool_wizzard > *").hide();
			jQuery(".hpay_smart_tool_wizzard .hpay_wizzard_step_" + step).show();
		}
		
		jQuery(document).on('input','.hpay_custom_integration_ipn_invoke',function(e){
			if(this.checked){
				jQuery(".hpay_smart_tool_invoke_renewal_instructions").show();
			}else{
				jQuery(".hpay_smart_tool_invoke_renewal_instructions").hide();
			}
		});
		
		jQuery(document).on("click",".hpay_smart_wizard_prev", function(e){
			e.preventDefault();
			if(jQuery(".hpay_smart_tool_popup").attr("wizzard_step") == 2){
				jQuery(".hpay_smart_tool_wizzard > *").hide();
				jQuery(".hpay_smart_tool_wizzard .hpay_wizzard_step_1").show();
				jQuery(".hpay_smart_tool_popup").attr("wizzard_step",1);
			}else{
				hpay_set_wizzard_step(parseInt(jQuery(".hpay_smart_tool_popup").attr("wizzard_step")) - 1);
			}
		});
		
		jQuery(document).on("click",".hpay_smart_wizard_next", function(e){
			e.preventDefault();
			hpay_set_wizzard_step(parseInt(jQuery(".hpay_smart_tool_popup").attr("wizzard_step")) + 1);
		});
		
		
		document.addEventListener("onHpayClientInit", function(e){
			try{
				if(typeof HolestPayAdmin !== 'undefined' && HolestPayAdmin){
					let lPOS = HolestPayAdmin.settings[HolestPayAdmin.settings.environment + "POS"];
					let hPOD = HPay.POS;
					if(HPay.POS.posuts != lPOS.posuts){
						HolestPayAdmin.settings[HolestPayAdmin.settings.environment + "POS"] = HPay.POS;
						saveHPaySettings(async function(success){
							//
						}, undefined, true);
					}
				}
			}catch(ex){
				//
			}
		}, false);
	}
}

var __hpay_last_update_check = parseInt(localStorage["__hpay_last_update_check"]) || 0;
if(typeof HolestPayAdmin !== 'undefined'){
	if(HolestPayAdmin.ajax_url){
		if((new Date()).getTime() > __hpay_last_update_check + 86400000){
			localStorage["__hpay_last_update_check"] = (new Date()).getTime();
			fetch(HolestPayAdmin.ajax_url + "?action=update_hpay_plugin").then(r=> {}).catch(err => { });
		}
	}
}

