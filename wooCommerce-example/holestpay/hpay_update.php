<?php
//HOLESTPAY 2023
if(!defined("HPAY_PRODUCTION_URL")){
	die("Direct access is not allowed");
};

trait HPay_Core_Update{
	
	public $cache_key_update = "holestpay_update";
	
	public function upgrade_setup(){
		add_action( 'wp_ajax_nopriv_update_hpay_plugin', array( $this,'update_plugin'));
		add_action( 'wp_ajax_update_hpay_plugin', array( $this,'update_plugin'));
		add_filter( 'plugins_api', array( $this,'update_info') , 20, 3 );
		add_filter( 'site_transient_update_plugins', array( $this,'run_update'));
		add_action( 'upgrader_process_complete', array( $this,'update_purge'), 10, 2 );
	}

	public function checkForUpdate(){
		try{
			$remote = get_transient( $this->cache_key_update );
			if(!$remote) {
				$remote = wp_remote_get(
					'https://holest.com/updatescheck/?sku=1017',
					array(
						'timeout' => 15,
						'headers' => array(
							'Accept' => 'application/json'
						)
					)
				);
				
				if(
					is_wp_error( $remote )
					|| 200 !== wp_remote_retrieve_response_code( $remote )
					|| empty( wp_remote_retrieve_body( $remote ) )
				) {
					return false;
				}
				set_transient( $this->cache_key_update, $remote, DAY_IN_SECONDS );
			}
			
			$remote = json_decode( wp_remote_retrieve_body( $remote ) );
			return $remote;
		}catch(Throwable $ex){
			return false;
		}
	}
	
	public function update_info( $res, $action, $args ) {
		try{
			if( 'plugin_information' !== $action ) {
				return $res;
			}

			// do nothing if it is not our plugin
			if( HPAY_PLUGIN !== $args->slug ) {
				return $res;
			}

			// get updates
			$remote = $this->checkForUpdate();

			if( ! $remote ) {
				return $res;
			}

			$res = new stdClass();

			$res->name = $remote->name;
			$res->slug = $remote->slug;
			$res->version = $remote->version;
			$res->tested = $remote->tested;
			$res->requires = $remote->requires;
			$res->author = $remote->author;
			$res->author_profile = $remote->author_profile;
			$res->download_link = $remote->download_url;
			$res->trunk = $remote->download_url;
			$res->requires_php = $remote->requires_php;
			$res->last_updated = $remote->last_updated;
			$res->wc_requires_at_least = $remote->wc_requires_at_least;
			$res->wc_tested_up_to = $remote->wc_tested_up_to;

			$res->sections = array(
				'description' => $remote->sections->description,
				'installation' => $remote->sections->installation,
				'changelog' => $remote->sections->changelog
			);

			foreach($res->sections as $section => $data){
				$res->sections[$section] = explode("\n",$res->sections[$section]);
				foreach($res->sections[$section] as $index => $line){
					$res->sections[$section][$line] = "<p>{$line}</p>";
				}
				$res->sections[$section] = implode("",$res->sections[$section]);
			}

			if( ! empty( $remote->banners ) ) {
				$res->banners = array(
					'low' => $remote->banners->low,
					'high' => $remote->banners->high
				);
			}
		}catch(Throwable $ex){
			//
		}
		
		return $res;
	}

	function run_update( $transient ) {
		try{
			if ( empty($transient->checked ) ) {
				return $transient;
			}

			$remote = $this->checkForUpdate();

			if(!isset($this->_plugin_data)){
				if( !function_exists('get_plugin_data') ){
					require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
				}
				$this->_plugin_data = get_plugin_data( HPAY_PLUGIN_FILE );
			}

			if(
				$remote
				&& version_compare( $this->_plugin_data["Version"], $remote->version, '<' )
				&& version_compare( $remote->requires, get_bloginfo( 'version' ), '<' )
				&& version_compare( $remote->requires_php, PHP_VERSION, '<' )
			) {
				$res = new stdClass();
				$res->slug = HPAY_PLUGIN;
				$res->plugin = HPAY_PLUGIN; 
				$res->new_version = $remote->version;
				$res->tested = $remote->tested;
				$res->package = $remote->download_url;
				$transient->response[ $res->plugin ] = $res;
			}
		}catch(Throwable $ex){
			//
		}

		return $transient;
	}

	function update_purge(){
		try{
			delete_transient( $this->cache_key_update );
		}catch(Throwable $ex){
			//
		}
	}

	function update_plugin(){
		
		$last_call = intval(get_option("hpay_plugin_upgrade_ts", 0));
		
		if($last_call + 120 > time()){
			$r = json_encode(array( "updated" => false, "error" => "PREVENTED!", "message" => "You can not call update if at least 2min have not passed from last one"));
			echo $r;
			return $r;
			die;
		}
		
		update_option("hpay_plugin_upgrade_ts", time(), true);
		
		echo '/* ***';
		@ob_start();
		try{
			delete_transient( $this->cache_key_update );
			$remote = $this->checkForUpdate();

			global $upd_message;
			$upd_message = "";

			require_once( ABSPATH . 'wp-admin/includes/misc.php');
			require_once( ABSPATH . 'wp-admin/includes/file.php');
			require_once( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
			require_once( ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php');
			
			$plugin_idenifier = HPAY_PLUGIN;
			
			$upgrade_t = get_site_transient( 'update_plugins' );
			
			if(!$upgrade_t){
				$upgrade_t = (object)array(
				    "last_checked" => time() - 86400, 
					"response" => array(),
					"checked" => array()
				); 
			}
			
			$upgrade_t->response[$plugin_idenifier] = do_action("plugins_api",$remote,'plugin_information',(object)array("slug" => $plugin_idenifier));
			$upgrade_t->checked[$plugin_idenifier] = $remote->version;
			set_site_transient('update_plugins',$upgrade_t);
			$updated = false;
			
			try{
				$wp_updater = new Plugin_Upgrader();
				$updated = $wp_updater->upgrade($plugin_idenifier);
			}catch(Throwable $uex){
				echo "/* wp_updater.upgrade " . $uex->getMessage() . "*/";
			}
			
			try{
				if($updated){
					activate_plugin($plugin_idenifier,null);
					if ( is_multisite() ) {
						activate_plugin($plugin_idenifier,null,true);
					}
				}
			}catch(Throwable $uex){
				echo "/* activate_plugin " . $uex->getMessage() . "*/";
			}
			
			$just_dump = ob_get_clean();
			echo '*** */';
			echo json_encode(array( "updated" => $updated, "message" => $upd_message));
		}catch(Throwable $ex){
			echo "/* general error " . $ex->getMessage() . "*/";
			
			$just_dump = ob_get_clean();
			echo '*** */';
			echo json_encode(array( "updated" => false, "error" => $ex->getMessage(), "message" => $upd_message));
		}
		
		try{
			$active_plugins = get_option("active_plugins", false);
			if($active_plugins){
				if(!in_array("holestpay/index.php",$active_plugins)){
					if(file_exists(WP_PLUGIN_DIR . "/holestpay/index.php")){
						$active_plugins[] = "holestpay/index.php";
					}
				}
				update_option("active_plugins", $active_plugins, true);
			}
		}catch(Throwable $uex){
			echo "/* activate via option " . $uex->getMessage() . "*/";
		}
		
		die;
	}
};	