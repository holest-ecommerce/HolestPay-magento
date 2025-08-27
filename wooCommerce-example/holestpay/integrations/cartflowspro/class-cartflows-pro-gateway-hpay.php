<?php
/**
 * Hpay Gateway.
 *
 * @package cartflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Cartflows_Pro_Gateway_Hpay.
 */
class Cartflows_Pro_Gateway_Hpay {

	/**
	 * Member Variable
	 *
	 * @var instance
	 */
	private static $instance;

	/**
	 * Key name variable.
	 *
	 * @var key
	 */
	public $key = 'hpay';

	/**
	 * Refund supported
	 *
	 * @var is_api_refund
	 */
	public $is_api_refund = false;

	/**
	 *  Initiator
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		add_filter("hpay_before_payment_order_status", array($this, "before_payment_order_status"),99,3);
	}
	
	public function before_payment_order_status($order_status, $order, $hpay_method){
		$hpay_payment_status = HPay_Core::instance()->orderHpayPaymentStatus($order);
		if(!in_array($hpay_payment_status,array("PAID","RESERVED","SUCCESS","AWAITING","REFUND","VOID"))){
			$order_status = wcf_pro()->front->set_upsell_return_new_order_status( $order_status, $order );
			return $order_status;
		}
	}

	/**
	 * Process offer payment
	 *
	 * @since 1.0.0
	 * @param array $order order data.
	 * @param array $product product data.
	 * @return bool
	 */
	public function process_offer_payment( $order, $product ) {
		return true;
	}

	/**
	 * Is gateway support offer refund
	 *
	 * @return bool
	 */
	public function is_api_refund() {

		return $this->is_api_refund;
	}
}

/**
 *  Prepare if class 'Cartflows_Pro_Gateway_Hpay' exist.
 *  Kicking this off by calling 'get_instance()' method
 */
Cartflows_Pro_Gateway_Hpay::get_instance();
