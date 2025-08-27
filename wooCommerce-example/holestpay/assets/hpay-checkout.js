/* HolestPay */
(function(){
	
	
	if(typeof HolestPayCheckout !== 'undefined' ){
		
		let __hscript_load = (SCRIPT_URL, callback , async = true, type = "text/javascript") => {
						
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
		
		if(typeof HolestPayAdmin === 'undefined' ){
			
			jQuery(document).ready(function(){
				__hscript_load(HolestPayCheckout.hpay_url + "/clientpay/cscripts/hpay.js?verdeffer=23-" + HolestPayCheckout.plugin_version + "-" + (new Date()).toISOString().replace(/[^\d]/g,"").substring(0,8), function(){
					try{
						let event = new Event("onHpayScriptLoaded");
						document.dispatchEvent(event);
					}catch(ex){
						console.error(ex);
					}
				});
			});
		};
		
		document.addEventListener("onHpayScriptLoaded", function(e){
			if(HolestPayCheckout.hpay_autoinit == 1 || document.querySelector("input[value*='hpayshipping-]")){
				HPayInit().then(client => {
					if(client && client.POS && client.POS.shipping){
						client.POS.shipping.forEach(s => {
							let mid = "hpayshipping-" + s.HPaySiteMethodId;
							let tmp = document.createElement("span");
							for(var el of document.querySelectorAll("input[value*='" + mid + "']")){
								try{
									el.parentNode.className = String((el.parentNode.className || "") + " hpay_sm_description_wrapper").trim();
									tmp.innerHTML = s.Description;
									el.parentNode.setAttribute("hpay_sm_description",(tmp.innerText || "").trim());
								}catch(ex){}
							}
							try{
								let event = new Event("onHpayCartUpdated");
								event.cart = HolestPayCheckout.cart;
								document.dispatchEvent(event);
							}catch(ex){
								console.error(ex);
							}
						});	
					}
				});
			}	
		},false);
		
		let adapted_checkout_destroy = null;
		let prev_hpay_shipping_method = null;
		
		window.document.addEventListener("onHPayCheckoutVariableChange", function(e){
			if(e.variable_name == "dispenser"){
				let dispenser = (e.variable_value && typeof e.variable_value === 'string') ? JSON.parse(e.variable_value) : e.variable_value;
				let data = {
					order_shipping: {
						dispenser: "",
						dispenser_desc: "",
						dispenser_method_id: ""
					}
				};
				if(dispenser){
					data.order_shipping.dispenser_method_id = sessionStorage.dispenser_method_id;
					data.order_shipping.dispenser = dispenser.ID;
					data.order_shipping.dispenser_desc = dispenser.Name + ", " + dispenser.Address;
				}
				hpay_setCheckoutData(data);
			}
		}, true);
		
		async function hpay_setCheckoutData(data){
			return fetch(HolestPayCheckout.ajax_url + "&operation=checkout_sessiom_data",{
				method:"POST",
				headers:{
						"Content-Type":"application/json"
				},
				body: JSON.stringify(data || {})
			}).then(rawr => rawr.json()).catch(err => {return {error: err}}); 
		}
		
		async function hpay_onCartUpdated(){
			
			if(typeof HolestPayCheckout === 'undefined')
				return;
			
			if(typeof HolestPayCheckout.cart === 'undefined')
				return;
			
			if(!HolestPayCheckout.cart.payment_method){
				if(typeof HPay !== 'undefined' && HPay && HPay.setPaymentMethodDock && HolestPayCheckout && HolestPayCheckout.dock_payment_methods){
					let hpmsel = document.querySelector("*[name='payment_method'][value^='hpaypayment-']:checked");
					if(hpmsel){
						let mpid = parseInt(hpmsel.value.replace("hpaypayment-",""));
						if(mpid){
							HolestPayCheckout.cart.payment_method = mpid;
							update_hpay_pay_dock(mpid);
						}
					}
				}
			}
			
			if(prev_hpay_shipping_method && prev_hpay_shipping_method.HPaySiteMethodId != HolestPayCheckout.cart.shipping_method){
				if(adapted_checkout_destroy && (typeof adapted_checkout_destroy === 'function' || adapted_checkout_destroy.then)){
					try{
						if(adapted_checkout_destroy.then){
							adapted_checkout_destroy = await adapted_checkout_destroy;
						}
						if(typeof adapted_checkout_destroy === 'function')
							adapted_checkout_destroy();
						
						adapted_checkout_destroy = null;
					}catch(ex){
						
					}
				}
				prev_hpay_shipping_method = null;
			}
			
			if(typeof HPay !== 'undefined'){
				if(HPay && HPay.POS && HPay.POS.shipping){
					 HPay.POS.shipping.forEach(s => {
									let mid = "hpayshipping-" + s.HPaySiteMethodId;
									let tmp = document.createElement("span");
									for(var el of document.querySelectorAll("input[value*='" + mid + "']")){
										try{
											el.parentNode.className = String((el.parentNode.className || "") + " hpay_sm_description_wrapper").trim();
											tmp.innerHTML = s.Description;
											el.parentNode.setAttribute("hpay_sm_description", (tmp.innerText || "").trim());
										}catch(ex){}
									}
								});	 
				}
			}
			
			let sipping_m = document.querySelectorAll("input[value^='hpayshipping-']");
			if(sipping_m && sipping_m.length){
				for(var el of sipping_m){
					if(!el.parentNode.querySelector('.hpay-sm-options')){
						let sm_options = document.createElement("span");
						sm_options.className = 'hpay-sm-options';
						sm_options.setAttribute("hpay_site_shipping_method",el.getAttribute("value"));
						sm_options.style.display = 'none';
						let smid = String(el.getAttribute("value") || "").split(":")[0];
						smid = smid.split('hpayshipping-')[1];
						if(smid){
							sm_options.setAttribute("hpay_shipping_method_id",smid);
							el.parentNode.appendChild(sm_options);
							if(HolestPayCheckout.cart.shipping_method == smid){
								sm_options.style.display = 'block';	
							}
						}
					}else if(!el.checked){
						let smid = String(el.getAttribute("value") || "").split(":")[0];
						let sm_options = el.parentNode.querySelector('.hpay-sm-options');
						if(sm_options){
							if(HolestPayCheckout.cart.shipping_method == smid){
								sm_options.style.display = 'block';	
							}else{
								sm_options.style.display = 'none';
							}	
						}
					} 
				}
			}
			
			if(HolestPayCheckout.cart.shipping_method){
				try{
					if(prev_hpay_shipping_method && prev_hpay_shipping_method.HPaySiteMethodId == HolestPayCheckout.cart.shipping_method){
						return;	
					}
					
					HPayInit(async function(client){ 
					   
					    
						let smethod = HPay.POS.shipping.find(s => s.HPaySiteMethodId == HolestPayCheckout.cart.shipping_method);
						if(smethod && smethod.AdaptCheckout){
							try{
								adapted_checkout_destroy = smethod.AdaptCheckout({
									billing: {
										postcode: "#billing_postcode,#billing-postcode",
										phone: "#billing_phone,#billing-phone",
										country: "#billing_country,#billing-country",
										city: "#billing_city,#billing-city",
										address: "#billing_address_1,#billing-address_1",
										address_num: "#billing_address_2,#billing-address_2"	
									},
									shipping:{
										postcode: "#shipping_postcode,#shipping-postcode",
										phone: "#shipping_phone,#shipping-phone",
										country: "#shipping_country,#shipping-country",
										city: "#shipping_city,#shipping-city",
										address: "#shipping_address_1,#shipping-address_1",
										address_num: "#shipping_address_2,#shipping-address_2"
									}
								}) || null;
							}catch(ex){
								console.log(ex)
							}
						}
						prev_hpay_shipping_method = smethod
					});
				}catch(iex){
					//script not yet loaded
				}
			} else {
				if(adapted_checkout_destroy && (typeof adapted_checkout_destroy === 'function' || adapted_checkout_destroy.then)){
					try{
						if(adapted_checkout_destroy.then){
							adapted_checkout_destroy = await adapted_checkout_destroy;
						}
						if(typeof adapted_checkout_destroy === 'function')
							adapted_checkout_destroy();
						
						adapted_checkout_destroy = null;
					}catch(ex){
						
					}
				}
				prev_hpay_shipping_method = null;
			}
		}
		
		function update_hpay_pay_dock(pm_id){
			if(!pm_id){
				HPay.setPaymentMethodDock(null);
				return;
			}
			
			let cnt = jQuery("div[data-hpay-dock-pmethod='" + pm_id + "']")[0];
			let vault_selector = null;
			
			if(cnt){
				vault_selector = cnt.getAttribute('data-hpay-dock-ptokenref-selector') || "";
			}
			
			HPay.setPaymentMethodDock(pm_id, {
				order_amount: HolestPayCheckout.cart.order_amount,//may be element, selector or actual value. Selector may contain {$pmid} replace makro 
				order_currency: HolestPayCheckout.cart.order_currency,//may be element, selector or actual value. Selector may contain {$pmid} replace makro 
				monthly_installments: HolestPayCheckout.cart.monthly_installments || null,//may be element, selector or actual value. Selector may contain {$pmid} replace makro 
				vault_token_uid: vault_selector,//may be element, selector or actual value. Selector may contain {$pmid} replace makro,
				hpaylang: HolestPayCheckout.hpaylang,
				cof: HolestPayCheckout.cart.cof || "" 	
			},cnt);// cnt - element or selector. Defaults to first visible div element with data-hpay-dock-payment attribute. Selector may contain {$pmid} replace makro  
		}
		
		document.addEventListener("onHpayCartUpdated", function(e){
			if(typeof HPayInit === 'undefined')
				return;
			
			hpay_onCartUpdated();
			
			if(typeof HPay !== 'undefined' && HPay && HPay.setPaymentMethodDock && HolestPayCheckout && HolestPayCheckout.dock_payment_methods){
				let hpmeth = document.querySelector("input[value^='hpaypayment-']:checked");
				if(hpmeth){
					let pm_id = parseInt(String(hpmeth.getAttribute("value")).replace(/[^\d]/g,''));
					update_hpay_pay_dock(pm_id || null);
				}else{
					if(HPay.getCurrentPaymentMethodDock()){
						update_hpay_pay_dock(null);
					}
				}
			}
		},false);
		
		jQuery(document).on("change","input[name$='_vault_token_id']", function(e){
			if(this.checked){
				if(typeof HPay !== 'undefined' && HPay && HPay.setPaymentMethodDock && HolestPayCheckout && HolestPayCheckout.dock_payment_methods){
					let pm_id = parseInt(String(this.getAttribute("name")).replace(/[^\d]/g,''));
					update_hpay_pay_dock(pm_id || null);
				}	
			}
		});
		
		jQuery(document).on("change","input[value^='hpaypayment-']", function(e){
			if(typeof HPay !== 'undefined' && HPay && HPay.setPaymentMethodDock && HolestPayCheckout && HolestPayCheckout.dock_payment_methods){
				if(/hpaypayment-\d*$/.test(this.getAttribute("value"))){
					if(this.checked){
						let pm_id = parseInt(String(this.getAttribute("value")).replace(/[^\d]/g,''));
						update_hpay_pay_dock(pm_id || null);
					}else{
						if(!jQuery("input[value^='hpaypayment-']:checked")[0]){
							if(HPay.getCurrentPaymentMethodDock()){
								update_hpay_pay_dock(null);
							}
						}
					}
				}
			}	
		});
		
		jQuery(document).on("updated_checkout","body", function(e,data){
			if(data.fragments && data.fragments.hpaycart){
				HolestPayCheckout.cart = data.fragments.hpaycart;
				
				try{
					if(HolestPayCheckout.cart && HolestPayCheckout.cart.UI && HolestPayCheckout.cart.UI.checkout_fields){
						let checkout_fields = HolestPayCheckout.cart.UI.checkout_fields;
						
						let setFields = (is_company) => { 
							for(var what in checkout_fields){
								if(checkout_fields.hasOwnProperty(what)){
									try{
										if(what == "is_company")
											continue;
										for(var el of document.querySelectorAll(checkout_fields[what])){
											el.setAttribute("hpay_checkout_field", what);
											if(!is_company){
												if(el.value){
													el.setAttribute("orig_value", el.value);
												}
												el.value = "";
											}else{
												el.value = el.getAttribute("orig_value") || "";
												if(!el.value){
													if(HolestPayCheckout.cart.order_billing && HolestPayCheckout.cart.order_billing[what]){
														el.value = HolestPayCheckout.cart.order_billing[what];
													}
												}
											}
										}
									}catch(ex){
										//
									}
								}
							}
						};
						
						if(checkout_fields.is_company){
							let is_company_el = document.querySelectorAll(checkout_fields.is_company);
							if(is_company_el && is_company_el[0] && is_company_el[0].form){
								let onchange = function(){
									let is_company = 0;
									if(/checkbox/.test(this.getAttribute("type"))){
										is_company = this.checked;
									}else if(/radio/.test(this.getAttribute("type"))){
										if(this.checked){
											is_company = this.checked && /1|yes|on|true|comp/i.test(String(this.getAttribute("value"))) ? 1 : 0;	
										}
									}else{
										is_company = /1|yes|on|true|comp/i.test(String(this.value)) ? 1 : 0;
									}
									this.form.className = String(this.form.className || "").replace(/\s?is-company-order-yes|\s?is-company-order-no/,'') + (is_company ? " is-company-order-yes": " is-company-order-no");
									setFields(is_company);
								};
								
								let isc = HolestPayCheckout.cart && HolestPayCheckout.cart.order_billing && HolestPayCheckout.cart.order_billing.is_company;
								is_company_el[0].form.className = String(is_company_el[0].form.className || "").replace(/\s?is-company-order-yes|\s?is-company-order-no/,'') + (isc ? " is-company-order-yes": " is-company-order-no");
								setFields(isc);
								
								for(var el of is_company_el){
									el.setAttribute("hpay_checkout_field", "is_company");
									el.addEventListener("change", onchange,false);	
								}
							}
						}
					}
				}catch(ex){
					console.error(ex);
				}
				
				try{
					let event = new Event("onHpayCartUpdated");
					event.cart = HolestPayCheckout.cart;
					document.dispatchEvent(event);
				}catch(ex){
					console.error(ex);
				}
			}
		});
		
		let hpay_parse_qs = function(url){
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
		};
		
		let hpay_handleUnsuccessfullResponse = function(hpay_response){
			
			if(typeof HPay !== 'undefined'){
				if(HPay && HPay.exitClientWait){
					HPay.exitClientWait();
				}
			}
			
			if(!hpay_response.status){
				hpay_response.status = "ERROR";
			}
			
			let title   = "";
			let content = "";
			let footer  = "";
			
			if(/REFUSED/i.test(hpay_response.status)){
				title  = HolestPayCheckout.labels["Payment refused"] || "Payment refused";
				content = "Payment refused, you can try again";
				content = HolestPayCheckout.labels[content] || content;
			}else if(/FAILED/i.test(hpay_response.status)){
				title  = HolestPayCheckout.labels["Payment failed"] || "Payment failed";
				content = "Payment failed, you can try again";
				content = HolestPayCheckout.labels[content] || content;
			}else if(/CANCELED/i.test(hpay_response.status)){
				title  = HolestPayCheckout.labels["Payment canceled"] || "Payment canceled";
				content = "Payment canceled, you can try again";
				content = HolestPayCheckout.labels[content] || content;
			}else{
				title  = HolestPayCheckout.labels["Payment error"] || "Payment error";
				if(hpay_response.error_code == 2000){
					content = "No payment respose";
					content = HolestPayCheckout.labels[content] || content;
				}else{
					content = "Payment has failed";
					content = HolestPayCheckout.labels[content] || content;
				}
			}
			
			if(hpay_response.transaction_user_info){
				content += "<pre>";
				for(var key in hpay_response.transaction_user_info){
					if(hpay_response.transaction_user_info.hasOwnProperty(key)){
						content += ("\r\n" + String( HolestPayCheckout.labels[key] || key ) + ": " + hpay_response.transaction_user_info[key]);
					}
				}
				content += "</pre>";
			}
			
			let resinp = jQuery("#hpaypayment-" + hpay_response.payment_method + "_payresult");
			let retried = false;
			
			if(/REFUSED|FAILED|CANCELED/i.test(hpay_response.status)){
				
				let retry_button = jQuery("<button></button>").html(HolestPayCheckout.labels["Try to pay again"] || "Try to pay again").click(function(e){
					retried = true;
					
					hpay_closePopup("payreq-error");
					if(resinp[0]){
						resinp.val(JSON.stringify(hpay_response));
						
						if(window.hpay_last_pay_req){
							presentHPayPayForm(window.hpay_last_pay_req);
						}else{
							if(resinp[0].form){
								if(jQuery(resinp[0].form).find(':submit')[0]){
									jQuery(resinp[0].form).find(':submit').trigger("click");
								}else if(jQuery(resinp[0].form).find("button[class*='checkout-place-order-button']")[0]){
									jQuery(resinp[0].form).find("button[class*='checkout-place-order-button']").trigger('click');
								}else{
									jQuery(resinp[0].form).find("button[class*='checkout-place-order-button']").trigger('click');
								}
							}
						}
					}
				});
				
				if(resinp[0]){
					retry_button.attr("class",jQuery(resinp[0].form).find(':submit').attr("class")).css("padding","8px");
				}
				
				footer = jQuery("<div></div>");
				footer.append(retry_button);
			}
			
			
			hpay_PresentPopup("payreq-error xs-popup", title, content, footer, function(){
				if(!retried && hpay_response.order_user_url)
					window.location.href = hpay_response.order_user_url;
			});
		};
		
		document.addEventListener("onHPayResult",function(e){
			if(e.hpay_response.transaction_uid){
				
				if(typeof HPay !== 'undefined'){
					if(HPay && HPay.enterClientWait){
						HPay.enterClientWait();
					}
				}
				
				if(!e.hpay_response.vhash){
					if(e.hpay_response.order_user_url && (/SUCCESS|PAID|PAYING|RESERVED|AWAITING|OBLIGATED/.test(e.hpay_response.status))){
						window.location.href = e.hpay_response.order_user_url;
						return;
					}
					
					hpay_handleUnsuccessfullResponse(e.hpay_response);
					return;
				}
				
				if(typeof hpay_method_wcapi !== 'undefined'){
					
					fetch(hpay_method_wcapi.url,{
						method:"POST",
						headers:{
							"Content-Type":"application/json"
						},
						body: JSON.stringify(e.hpay_response)
					}).then(rresp => rresp.json()).then(r => {
						if(/SUCCESS|PAID|PAYING|RESERVED|AWAITING|OBLIGATED/.test(e.hpay_response.status)){
							if(r.order_user_url){
								window.location.href = r.order_user_url;
								return;
							}
							
							if(e.hpay_response.order_user_url){
								window.location.href = e.hpay_response.order_user_url;
								return;
							}
							
						}else
							hpay_handleUnsuccessfullResponse(e.hpay_response);
						
					}).catch(err => {
						if(/SUCCESS|PAID|PAYING|RESERVED|AWAITING|OBLIGATED/.test(e.hpay_response.status)){
							if(e.hpay_response.order_user_url){
								window.location.href = e.hpay_response.order_user_url;
								return;
							}
						}else
							hpay_handleUnsuccessfullResponse(e.hpay_response);
					});
				}else{
					
					if(e.hpay_response.order_user_url){
						fetch("//" + HolestPayCheckout.site_url,{
							method:"POST",
							headers:{
								'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
							},
							body: new URLSearchParams({ "hpay_forwarded_payment_response": JSON.stringify(e.hpay_response), "hpay_local_request": 1})
						}).then(r=>{
							if(/SUCCESS|PAID|PAYING|RESERVED|AWAITING|OBLIGATED/.test(e.hpay_response.status)){
								
								if(r.order_user_url){
									window.location.href = r.order_user_url;
									return;
								}
								
								if(e.hpay_response.order_user_url){
									window.location.href = e.hpay_response.order_user_url;
									return;
								}
							}else{
								hpay_handleUnsuccessfullResponse(e.hpay_response);
							}
						}).catch(err => {
							if(/SUCCESS|PAID|PAYING|RESERVED|AWAITING|OBLIGATED/.test(e.hpay_response.status)){
								if(e.hpay_response.order_user_url){
									window.location.href = e.hpay_response.order_user_url;
									return;
								}
							}else{
								hpay_handleUnsuccessfullResponse(e.hpay_response);
							}	
						})
					}else{
						if(/SUCCESS|PAID|PAYING|RESERVED|AWAITING|OBLIGATED/.test(e.hpay_response.status)){
							if(e.hpay_response.order_user_url){
								window.location.href = e.hpay_response.order_user_url;
								return;
							}
						}else{
							hpay_handleUnsuccessfullResponse(e.hpay_response);
						}
					}
				}
			}
		}, false);
		
		jQuery(document).on("submit","form:has(input[name='woocommerce_pay'])",function(e){
			if(jQuery(this).data("pass_payrequestforward")){
				return;
			}
			let self_frm = jQuery(this);
			
			let request_data = {};
			let formData = new FormData(this);
			if(formData.get("woocommerce_pay") && /^hpaypayment\-/.test(formData.get("payment_method") || "---")){
				e.preventDefault();
				let request_data = {};
				for(let prop of formData.keys()){
					if(/\[/.test(prop)){
						let $ref = request_data;
						let prop_path = prop.split("[").forEach(function(t,index,arr){
							let sprop = t.replace("]","").trim();
							if(index == arr.length - 1){
								$ref[sprop] = formData.get(prop);
							}else{
								if(!$ref[sprop]){
									$ref[sprop] = {};
								}
								$ref = $ref[sprop];
							}
						});
					}else{
						request_data[prop] = formData.get(prop);	
					}
				}
				
				if(this.action){
					request_data = Object.assign(request_data,hpay_parse_qs(this.action));
				}
				
				if(request_data["order-pay"]){
					request_data["order_id"] = request_data["order-pay"];
				}else if (/\/order-pay\//.test(window.location.href)){
					let m = window.location.href.match(/order-pay\/([\d]*)\//);
					if(m && m[1]){
						request_data["order_id"] = parseInt(m[1]);
					}
				}
				
				fetch(HolestPayCheckout.ajax_url + "&operation=forward_pay_request",{
					method:"POST",
					headers:{
							"Content-Type":"application/x-www-form-urlencoded"
					},
					body: (new URLSearchParams(request_data)).toString()
				}).then(rawr => rawr.json()).then(resp => {
					
					setTimeout(function(){
						self_frm.find(".blockOverlay").remove();
					},2500);
					
					if(resp.redirect){
						window.location.href = resp.redirect;
					}else if(resp.result == "error"){
						hpay_PresentPopup("payreq-error xs-popup", HolestPayCheckout.labels.error , HolestPayCheckout.labels.error_contact_us);	
						self_frm.data("pass_payrequestforward",1);
						self_frm.submit();
					}else if(resp.result == "success"){
						if(resp.messages){
							if(Array.isArray(resp.messages)){
								resp.messages = resp.messages.join(" ");
							}
							jQuery('body').append(jQuery(resp.messages));
						}else{
							self_frm.data("pass_payrequestforward",1);
							self_frm.submit();
						}
					}
				}).catch(err => {
					self_frm.find(".blockOverlay").remove();
					hpay_PresentPopup("payreq-error xs-popup", HolestPayCheckout.labels.error , HolestPayCheckout.labels.error_contact_us);	
					//self_frm.data("pass_payrequestforward",1);
					//self_frm.submit();
				});
			}
		});
		
		jQuery(document).on("click", ".hpay-destroy-vault", function(e){
			e.preventDefault();
			let item = jQuery(this).closest("*[token_id]");
			let token_id = item.attr("token_id");
			
			if(confirm(HolestPayCheckout.labels.remove_token_confirm)){
				fetch(HolestPayCheckout.ajax_url + "&operation=destroy_vault",{
					method:"POST",
					headers:{
							"Content-Type":"application/json"
					},
					body: JSON.stringify({token_id: parseInt(token_id)})
				}).then(rawr => rawr.json()).then(resp => {
					if(resp && resp.result == "ok"){
						item.remove();
					}else{
						throw "__BAD__";
					}
				}).catch(err => {
					hpay_PresentPopup("payreq-error xs-popup", HolestPayCheckout.labels.error , HolestPayCheckout.labels.error_contact_us);	
				});
			}
		});
		
		jQuery(document).on("click", ".hpay-set-default-vault", function(e){
			e.preventDefault();
			let item = jQuery(this).closest("*[token_id]");
			let token_id = item.attr("token_id");
			fetch(HolestPayCheckout.ajax_url + "&operation=default_vault",{
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
				hpay_PresentPopup("payreq-error xs-popup", HolestPayCheckout.labels.error , HolestPayCheckout.labels.error_contact_us);	
			});
		});
		
		
		let zindex = 999999;
		let hpay_PresentPopup = function(popup_class, title, content, footer, onclose){
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
		
		window.hpay_PresentPopup = hpay_PresentPopup;

		let hpay_closePopup = function(popup_class){
			jQuery("." + popup_class).hide();
			if(!jQuery(".hpay_popup:visible")[0]){
				jQuery("body").removeClass("hpay-popup-shown");
			}
		};

		jQuery(document).on("click",".hpay_popup_close",function(e){
			e.preventDefault(); 
			jQuery(this).closest(".hpay_popup").hide(); 
			
			if(onclose){
				try{
					onclose();
				}catch(ex){}
			}
			
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
	}
})();

function hpay_value_observer(element, callback) {
  (new (window.MutationObserver || window.WebKitMutationObserver)(function(mutations, observer) {
	if(mutations[0].attributeName == "value") {
		callback(element.value);
	}
  })).observe(element, {
	attributes: true
  });
}; 

function hpay_column_layout_init(variations){
	jQuery(document).on("click",".hpay-variant-columns-layout-item[variation_id]", function(e){
		e.preventDefault();
		e.stopImmediatePropagation();
		
		let variation_id = jQuery(this).attr("variation_id");
		let variation = variations.find(v => v.variation_id == variation_id);
		
		if(!jQuery(this).is(".selected")){
			jQuery(this).parent().find("> .hpay-variant-columns-layout-item").removeClass('selected');
			jQuery(this).addClass('selected')
		}
		
		for(let attr_name in variation.attributes){
			if(variation.attributes.hasOwnProperty(attr_name)){
				if(jQuery("*[name='" + attr_name + "']").val() != variation.attributes[attr_name]){
					jQuery("*[name='" + attr_name + "']").val(variation.attributes[attr_name]).trigger("change");
				}
			}
		}
	});
	
	let setObserverInterval = setInterval(function(){
		let set_done = null;
		jQuery(".hpay-variant-columns-layout:not(.init-done)").each(function(ind){
			let inp = jQuery(this).closest('.product').find("input[name='variation_id']")[0];
			if(inp){
				if(parseInt(inp.value)){
					jQuery(".hpay-variant-columns-layout-item[variation_id='" + parseInt(inp.value) + "']:not(.selected)").trigger("click");
				}
				hpay_value_observer(inp, function(value){
					if(parseInt(value)){
						jQuery(".hpay-variant-columns-layout-item[variation_id='" + parseInt(value) + "']:not(.selected)").trigger("click");
					}
				});
			}else{
				set_done = false;
			}
		});
		if(set_done !== false){
			clearInterval(setObserverInterval);
		}
	},250);
}

function hpay_enter_price_init(epdata){
	var hpay_enter_price_data = epdata;
	function hpay_set_enter_price_data(foritem){
		let data = null;
		if(foritem == "product"){
			data = hpay_enter_price_data.product;
		}else{
			data = {
				value: "",
				min:"",
				max:""
			};
			if(hpay_enter_price_data.variants && hpay_enter_price_data.variants[foritem]){
				data = hpay_enter_price_data.variants[foritem];
			}
		}
		
		let inp = document.getElementById('hpay_name_price_input');
		
		inp.value = data.value;
		
		let info = "";
		if(data.min){
			inp.setAttribute("min",data.min);
			info += ("min. " + parseFloat(data.min).toFixed(2));
		}else{
			inp.setAttribute("min","");
		}
		
		if(data.max){
			inp.setAttribute("max",data.max);
			if(info) info += " | ";
			info += ("max. " + parseFloat(data.max).toFixed(2));
		}else{
			inp.setAttribute("max","");
		}
		
		if(info){
			info += (" " +  hpay_enter_price_data.currency_symbol);
			document.getElementById('hpay_name_price_panel_min_max').innerHTML = info;
		}
		
		document.getElementById('hpay_name_price_panel').style.display = data.id ? '' : "none";
		document.getElementById('hpay_name_price_panel_min_max').style.display = info ? '' : "none";
	} 
	
	
	document.getElementById('hpay_name_price_input').addEventListener("change", function(e){
		if(this.getAttribute("change_no_trigger") == 1)
			return;
		
		if(this.getAttribute("min")){
			if(parseFloat(this.value) < parseFloat(this.getAttribute("min"))){
				this.setAttribute("change_no_trigger",1);
				this.value = parseFloat(this.getAttribute("min")).toFixed(2);
				this.setAttribute("change_no_trigger",0);
			}
		}
		
		if(this.getAttribute("max")){
			if(parseFloat(this.value) > parseFloat(this.getAttribute("max"))){
				this.setAttribute("change_no_trigger",1);
				this.value = parseFloat(this.getAttribute("max")).toFixed(2);
				this.setAttribute("change_no_trigger",0);
			}
		}
	},false);
	
	if(hpay_enter_price_data.product){
		hpay_set_enter_price_data('product');
	}else if(document.getElementById('hpay_name_price_input').form){
		let var_id_input = document.getElementById('hpay_name_price_input').form.querySelector("input[name='variation_id']");
		if(var_id_input){
			if(parseInt(var_id_input.value)){
			  hpay_set_enter_price_data(parseInt(var_id_input.value));
		    }	
			hpay_value_observer(var_id_input, function(value){
				if(parseInt(value))
					hpay_set_enter_price_data(parseInt(value));
			});
		}else{
			let wint = setInterval(function(){
				var_id_input = document.getElementById('hpay_name_price_input').form.querySelector("input[name='variation_id']")
				if(var_id_input){
					if(parseInt(var_id_input.value)){
					  hpay_set_enter_price_data(parseInt(var_id_input.value));
					}	
					hpay_value_observer(var_id_input, function(value){
						if(parseInt(value))
							hpay_set_enter_price_data(parseInt(value));
					});
					clearInterval(wint);
				}
			},250);
		}
	}
};
		
addEventListener("DOMContentLoaded", (event) => {
	
	const __set_input_value = (inp, value, skip_of_same) => {
	
		try{
			
			if(skip_of_same && (inp.value == value)){
				return;
			}
			
			let nativeInputValueSetter = null;
			if(/SELECT/i.test(inp.tagName)){
				nativeInputValueSetter = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype,'value').set;
			}else if(/TEXTAREA/i.test(inp.tagName)){
				nativeInputValueSetter = Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype,'value').set;
			}else{
				nativeInputValueSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype,'value').set;
			}
			
			nativeInputValueSetter.call(inp, value );
		
			const ievent = new Event('input', { bubbles: true });
			ievent.simulated = true;
			ievent.target = inp;
			inp.dispatchEvent(ievent);
			
		}catch(rex){
			inp.value = value;
		}
	};
	
	
	if(typeof HolestPayCheckout !== 'undefined' && typeof React !== 'undefined'){
		React._hpay_createElement = React.createElement;
		
		let order_billing = null;
		
		let __callUpdateCompanyFields = null;
		const updateCompanyFields = () => {
			__callUpdateCompanyFields = null;
			
			if(!order_billing) return;
			
			let is_company = order_billing.is_company || 0;
			if(!HolestPayCheckout.cart.UI.checkout_fields.is_company){
				is_company = null;
			}
			
			let send_data = {
					is_company: is_company,
					company_tax_id: (is_company === null || is_company) ? (order_billing.company_tax_id || "") : "",
					company_reg_id: (is_company === null || is_company) ? (order_billing.company_reg_id || "") : ""
				};
			
			if(is_company === 0){
				send_data.company = '';
			}	
			
			wc.blocksCheckout.extensionCartUpdate({ namespace: 'hpay', data: { 
			    order_billing:send_data
			}}).then(d => {
				if(d && d.extensions && d.extensions.hpay && d.extensions.hpay.cart){
					HolestPayCheckout.cart = Object.assign(HolestPayCheckout.cart, d.extensions.hpay.cart);
				}
			});
		};
		
		const callUpdateCompanyFields = () => {
			if(__callUpdateCompanyFields){
				return;
			}
			__callUpdateCompanyFields = setTimeout(updateCompanyFields,450);
		};
		
		const h_company_adapt = function(...args){
			if(!order_billing && HolestPayCheckout.cart.order_billing)
				order_billing = {...HolestPayCheckout.cart.order_billing};
			
			let no_is_company = false;
			let no_tax_id = !HolestPayCheckout.cart.UI.checkout_fields.company_tax_id;
			let no_reg_id = !HolestPayCheckout.cart.UI.checkout_fields.company_reg_id;
			
			if(!HolestPayCheckout.cart.UI.checkout_fields.is_company){
				no_is_company = true;
				checked = true;
			}else{
				checked = order_billing.is_company;
			}
			
			args[1].__hcreated = true;
			
			let company_el = React._hpay_createElement(...args);
			
			return [
				...(
				no_is_company ? [] : [React._hpay_createElement(wc.blocksComponents.CheckboxControl,{label: HolestPayCheckout.labels["Ordering as a company?"] || "Ordering as a company?" , checked: checked, onChange: (e) => {
					
					order_billing.is_company = !checked;
					let cmp_em = document.getElementById('billing-company');
					
					if(order_billing.is_company){
						order_billing.company_tax_id = window.h__company_tax_id || "";
						order_billing.company_reg_id = window.h__company_reg_id || "";
						
						
						if(cmp_em){
							__set_input_value(cmp_em,window.h__company || "");
						}
						
					}else{
						window.h__company_tax_id = order_billing.company_tax_id || "";
						window.h__company_reg_id = order_billing.company_reg_id || "";
						window.h__company = HolestPayCheckout.cart.order_billing.company || "";
						
						order_billing.company_tax_id = "";
						order_billing.company_reg_id = "";
												
						if(cmp_em){
							__set_input_value(cmp_em,"");
						}
					}
					
					callUpdateCompanyFields();
					
					let v = document.getElementById('billing-first_name').value;
					__set_input_value(document.getElementById('billing-first_name'),v + " ");
					__set_input_value(document.getElementById('billing-first_name'),v);
					
				}},"")]),
				...(checked ? [
					company_el,
					...(no_tax_id ? [] : [React._hpay_createElement(args[0],{...args[1], label: HolestPayCheckout.labels["Company Tax ID"] || "Company Tax ID", id: "billing-company_tax_id", value: order_billing.company_tax_id || "", onChange:(e)=>{
						order_billing.company_tax_id = e;
						callUpdateCompanyFields();
					}})]),
					
					...(no_reg_id ? [] : [React._hpay_createElement(args[0],{...args[1], label: HolestPayCheckout.labels["Company Register ID"] || "Company Register ID", id: "billing-company_reg_id", value: order_billing.company_reg_id || "", onChange:(e)=>{
						order_billing.company_reg_id = e;
						callUpdateCompanyFields();
					}})]),
				] : [])
			];
		};
		
		
		const h_create_el = function(...args){
			if(HolestPayCheckout.cart.UI && HolestPayCheckout.cart.UI.checkout_fields && (HolestPayCheckout.cart.UI.checkout_fields.is_company || HolestPayCheckout.cart.UI.checkout_fields.company_tax_id || HolestPayCheckout.cart.UI.checkout_fields.company_reg_id)){
				try{
					if(args && args[1] && typeof args[1].label && typeof args[1].label == "string" && !args[1].__hcreated){
						if(/billing-company/.test(args[1].id)){
							return h_company_adapt(...args);
						}
					}
				}catch(ex){
					console.error(ex);
					//
				}
				return React._hpay_createElement(...args);
			}
			return React._hpay_createElement(...args);
		};
		
		try{
			React.createElement = function(...args){
				try{
					if(!HolestPayCheckout.cart)
						return React._hpay_createElement(...args);
					if(!HolestPayCheckout.cart.order_billing)
						return React._hpay_createElement(...args);
					if(!HolestPayCheckout.cart.UI)
						return React._hpay_createElement(...args);
				
					return h_create_el(...args);
				}catch(ex){
					console.error(ex);
					return React._hpay_createElement(...args);
				}
			};
		}catch(ex){
			console.error(ex);
		}
		
	}
	
	if(document.querySelector("#billing_first_name_field span.required")){
		try{
			let root = document.querySelector(':root');
			if(root && window.getComputedStyle){
				root.style.setProperty('--hpay-wc-red', window.getComputedStyle(document.querySelector("#billing_first_name_field span.required")).color);
			}
		}catch(ex){
			console.error(ex);
		}
	}
});