<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Gateway_HPayPayment_Block extends AbstractPaymentMethodType {
	private $gateway;
	
	protected $name = 'hpaypayment-';
	
	public function __construct(){
		global $hpay_pm_class_mapper;
		
		$method_instance_name = str_replace("_BLOCK","",get_class($this));	
		
		if(!isset($hpay_pm_class_mapper[$method_instance_name]))
			return;
		
		$this->name = $hpay_pm_class_mapper[$method_instance_name]["id"];
		$this->gateway = HPay_Core::payment_method_instance($this->name);
	}
	
	public function is_active() {
		if(!$this->gateway)
			return false;
		
		return $this->gateway->is_available();
	}
	
	public function initialize() {
		//
	}
	
	public function get_payment_method_script_handles() {
					
		$pdata = HPay_Core::instance()->getPluginData();
			
		$script_path       = '/blocks/blocks.js';
		$script_asset_path = rtrim(HPAY_PLUGIN_PATH,"/") . '/blocks/blocks.asset.php';
		$script_asset      = file_exists( $script_asset_path )
			? require( $script_asset_path )
			: array(
				'dependencies' => array(),
				'version'      => $pdata["Version"]
			);
			
		$script_url        = HPAY_PLUGIN_URL . "/" . $script_path;

		wp_register_script(
			'wc-holestpay-payments-blocks',
			$script_url,
			$script_asset[ 'dependencies' ],
			$script_asset[ 'version' ],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wc-holestpay-payments-blocks', 'holestpay', rtrim(HPAY_PLUGIN_PATH,"/") . '/languages/' );
		}

		return [ 'wc-holestpay-payments-blocks' ];
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		ob_start();
		$this->gateway->payment_fields();
		$desc_area = ob_get_clean();
		
		return [
			'title'       => $this->gateway->title,
			'description' => $desc_area,
			'supports'    => array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] )
		];
	}
}

//PAYMENT METHOD BLOCK PLACEHOLDER CLASSES///////////////////////////////////////
class WC_Gateway_HPayPMPHOLDER1_BLOCK extends WC_Gateway_HPayPayment_Block{};class WC_Gateway_HPayPMPHOLDER2_BLOCK extends WC_Gateway_HPayPayment_Block{};
class WC_Gateway_HPayPMPHOLDER3_BLOCK extends WC_Gateway_HPayPayment_Block{};class WC_Gateway_HPayPMPHOLDER4_BLOCK extends WC_Gateway_HPayPayment_Block{};
class WC_Gateway_HPayPMPHOLDER5_BLOCK extends WC_Gateway_HPayPayment_Block{};class WC_Gateway_HPayPMPHOLDER6_BLOCK extends WC_Gateway_HPayPayment_Block{};
class WC_Gateway_HPayPMPHOLDER7_BLOCK extends WC_Gateway_HPayPayment_Block{};class WC_Gateway_HPayPMPHOLDER8_BLOCK extends WC_Gateway_HPayPayment_Block{};
class WC_Gateway_HPayPMPHOLDER9_BLOCK extends WC_Gateway_HPayPayment_Block{};class WC_Gateway_HPayPMPHOLDER10_BLOCK extends WC_Gateway_HPayPayment_Block{};
class WC_Gateway_HPayPMPHOLDER11_BLOCK extends WC_Gateway_HPayPayment_Block{};class WC_Gateway_HPayPMPHOLDER12_BLOCK extends WC_Gateway_HPayPayment_Block{};
class WC_Gateway_HPayPMPHOLDER13_BLOCK extends WC_Gateway_HPayPayment_Block{};class WC_Gateway_HPayPMPHOLDER14_BLOCK extends WC_Gateway_HPayPayment_Block{};
///////////////////////////////////////////////////////////////////////////