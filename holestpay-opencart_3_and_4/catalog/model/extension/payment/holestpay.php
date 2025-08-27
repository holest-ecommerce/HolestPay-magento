<?php
/**
 * HolestPay Payment Gateway for OpenCart 3 and 4
 * 
 * This extension provides a complete payment gateway integration with HolestPay
 * using standard PHP functions and direct database calls for maximum compatibility
 * and minimal file count.
 */

class ModelExtensionPaymentHolestPay extends Model {
    
    private $db;
    private $config;
    private $log;
    
    public function __construct($registry) {
        parent::__construct($registry);
        $this->db = $registry->get('db');
        $this->config = $registry->get('config');
        $this->log = $registry->get('log');
    }
    
    /**
     * Get payment method data for checkout
     */
    public function getMethod($address) {
        $this->load->language('extension/payment/holestpay');
        
        if (!$this->config->get('payment_holestpay_status')) {
            return false;
        }
        
        // Check country restrictions
        if ($this->config->get('payment_holestpay_allowspecific')) {
            $allowed_countries = $this->config->get('payment_holestpay_specificcountry');
            if (!in_array($address['country_id'], $allowed_countries)) {
                return false;
            }
        }
        
        // Check order total limits
        $total = $this->cart->getTotal();
        $min_total = $this->config->get('payment_holestpay_min_order_total');
        $max_total = $this->config->get('payment_holestpay_max_order_total');
        
        if ($min_total && $total < $min_total) {
            return false;
        }
        
        if ($max_total && $total > $max_total) {
            return false;
        }
        
        return array(
            'code' => 'holestpay',
            'title' => $this->config->get('payment_holestpay_title') ?: $this->language->get('text_title'),
            'terms' => '',
            'sort_order' => $this->config->get('payment_holestpay_sort_order')
        );
    }
    
    /**
     * Process payment and redirect to HolestPay
     */
    public function processPayment($order_id) {
        try {
            // Get order details
            $order = $this->getOrder($order_id);
            if (!$order) {
                return array('success' => false, 'error' => 'Order not found');
            }
            
            // Create HolestPay order
            $holestpay_order = $this->createHolestPayOrder($order);
            if (!$holestpay_order) {
                return array('success' => false, 'error' => 'Failed to create HolestPay order');
            }
            
            // Update order with HolestPay UID
            $this->updateOrderHolestPayUid($order_id, $holestpay_order['order_uid']);
            
            // Generate payment URL
            $payment_url = $this->generatePaymentUrl($holestpay_order);
            
            return array(
                'success' => true,
                'redirect_url' => $payment_url,
                'order_uid' => $holestpay_order['order_uid']
            );
            
        } catch (Exception $e) {
            $this->log->write('HolestPay Error: ' . $e->getMessage());
            return array('success' => false, 'error' => 'Payment processing error');
        }
    }
    
    /**
     * Create order in HolestPay system
     */
    private function createHolestPayOrder($order) {
        $environment = $this->config->get('payment_holestpay_environment');
        $merchant_uid = $this->config->get('payment_holestpay_merchant_site_uid');
        $secret_key = $this->config->get('payment_holestpay_secret_key');
        
        if (!$merchant_uid || !$secret_key) {
            throw new Exception('HolestPay configuration incomplete');
        }
        
        $api_url = $environment === 'live' 
            ? 'https://api.holestpay.com/api/v1/orders'
            : 'https://sandbox-api.holestpay.com/api/v1/orders';
        
        $order_data = array(
            'merchant_site_uid' => $merchant_uid,
            'order_id' => $order['order_id'],
            'amount' => $order['total'],
            'currency' => $order['currency_code'],
            'customer_email' => $order['email'],
            'customer_name' => $order['firstname'] . ' ' . $order['lastname'],
            'customer_phone' => $order['telephone'],
            'return_url' => $this->url->link('extension/payment/holestpay/callback', '', true),
            'cancel_url' => $this->url->link('checkout/checkout', '', true),
            'webhook_url' => $this->url->link('extension/payment/holestpay/webhook', '', true),
            'items' => $this->getOrderItems($order['order_id'])
        );
        
        // Generate signature
        $signature = $this->generateSignature($order_data, $secret_key);
        $order_data['signature'] = $signature;
        
        // Send request to HolestPay
        $response = $this->sendHttpRequest($api_url, $order_data, 'POST');
        
        if (!$response || !isset($response['success']) || !$response['success']) {
            throw new Exception('Failed to create HolestPay order: ' . ($response['message'] ?? 'Unknown error'));
        }
        
        return $response['data'];
    }
    
    /**
     * Generate payment URL for customer
     */
    private function generatePaymentUrl($holestpay_order) {
        $environment = $this->config->get('payment_holestpay_environment');
        $base_url = $environment === 'live' 
            ? 'https://pay.holestpay.com'
            : 'https://sandbox-pay.holestpay.com';
        
        return $base_url . '/payment/' . $holestpay_order['order_uid'];
    }
    
    /**
     * Process webhook from HolestPay
     */
    public function processWebhook($data) {
        try {
            // Verify webhook signature
            if (!$this->verifyWebhookSignature($data)) {
                $this->log->write('HolestPay Webhook: Invalid signature');
                return false;
            }
            
            $order_uid = $data['order_uid'] ?? null;
            $status = $data['status'] ?? null;
            
            if (!$order_uid || !$status) {
                $this->log->write('HolestPay Webhook: Missing required data');
                return false;
            }
            
            // Find order by HolestPay UID
            $order_id = $this->getOrderIdByHolestPayUid($order_uid);
            if (!$order_id) {
                $this->log->write('HolestPay Webhook: Order not found for UID: ' . $order_uid);
                return false;
            }
            
            // Update order status
            $this->updateOrderStatus($order_id, $status);
            
            // Update HolestPay status in order
            $this->updateOrderHolestPayStatus($order_id, $status);
            
            $this->log->write('HolestPay Webhook: Successfully processed for order: ' . $order_id);
            return true;
            
        } catch (Exception $e) {
            $this->log->write('HolestPay Webhook Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Process payment callback
     */
    public function processCallback($data) {
        $order_uid = $data['order_uid'] ?? null;
        $status = $data['status'] ?? null;
        
        if (!$order_uid || !$status) {
            return false;
        }
        
        // Find order by HolestPay UID
        $order_id = $this->getOrderIdByHolestPayUid($order_uid);
        if (!$order_id) {
            return false;
        }
        
        // Update order status
        $this->updateOrderStatus($order_id, $status);
        
        // Update HolestPay status
        $this->updateOrderHolestPayStatus($order_id, $status);
        
        return $order_id;
    }
    
    /**
     * Get order details from database
     */
    private function getOrder($order_id) {
        $sql = "SELECT o.*, c.firstname, c.lastname, c.email, c.telephone 
                FROM `" . DB_PREFIX . "order` o 
                LEFT JOIN `" . DB_PREFIX . "customer` c ON o.customer_id = c.customer_id 
                WHERE o.order_id = '" . (int)$order_id . "'";
        
        $result = $this->db->query($sql);
        return $result->row;
    }
    
    /**
     * Get order items
     */
    private function getOrderItems($order_id) {
        $sql = "SELECT name, quantity, price, total 
                FROM `" . DB_PREFIX . "order_product` 
                WHERE order_id = '" . (int)$order_id . "'";
        
        $result = $this->db->query($sql);
        return $result->rows;
    }
    
    /**
     * Update order with HolestPay UID
     */
    private function updateOrderHolestPayUid($order_id, $holestpay_uid) {
        $sql = "UPDATE `" . DB_PREFIX . "order` 
                SET holestpay_uid = '" . $this->db->escape($holestpay_uid) . "' 
                WHERE order_id = '" . (int)$order_id . "'";
        
        $this->db->query($sql);
    }
    
    /**
     * Update order HolestPay status
     */
    private function updateOrderHolestPayStatus($order_id, $status) {
        $sql = "UPDATE `" . DB_PREFIX . "order` 
                SET holestpay_status = '" . $this->db->escape($status) . "' 
                WHERE order_id = '" . (int)$order_id . "'";
        
        $this->db->query($sql);
    }
    
    /**
     * Update order status based on HolestPay status
     */
    private function updateOrderStatus($order_id, $holestpay_status) {
        $magento_status = $this->mapHolestPayStatusToOrderStatus($holestpay_status);
        
        if ($magento_status) {
            $sql = "UPDATE `" . DB_PREFIX . "order` 
                    SET order_status_id = '" . (int)$magento_status . "' 
                    WHERE order_id = '" . (int)$order_id . "'";
            
            $this->db->query($sql);
            
            // Add order history
            $sql = "INSERT INTO `" . DB_PREFIX . "order_history` 
                    (order_id, order_status_id, comment, date_added) 
                    VALUES ('" . (int)$order_id . "', '" . (int)$magento_status . "', 
                    'HolestPay Status: " . $this->db->escape($holestpay_status) . "', NOW())";
            
            $this->db->query($sql);
        }
    }
    
    /**
     * Map HolestPay status to OpenCart order status
     */
    private function mapHolestPayStatusToOrderStatus($holestpay_status) {
        $status_map = array(
            'PAYMENT:SUCCESS' => $this->config->get('payment_holestpay_order_status'),
            'PAYMENT:PAID' => $this->config->get('payment_holestpay_order_status'),
            'PAYMENT:FAILED' => 7, // Cancelled
            'PAYMENT:CANCELED' => 7, // Cancelled
            'PAYMENT:EXPIRED' => 7, // Cancelled
            'PAYMENT:REFUNDED' => 11, // Refunded
        );
        
        return $status_map[$holestpay_status] ?? null;
    }
    
    /**
     * Get order ID by HolestPay UID
     */
    private function getOrderIdByHolestPayUid($holestpay_uid) {
        $sql = "SELECT order_id FROM `" . DB_PREFIX . "order` 
                WHERE holestpay_uid = '" . $this->db->escape($holestpay_uid) . "'";
        
        $result = $this->db->query($sql);
        return $result->row ? $result->row['order_id'] : null;
    }
    
    /**
     * Generate signature for API requests
     */
    private function generateSignature($data, $secret_key) {
        // Remove signature field if exists
        unset($data['signature']);
        
        // Sort by keys
        ksort($data);
        
        // Create string to sign
        $string_to_sign = '';
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $string_to_sign .= $key . json_encode($value);
            } else {
                $string_to_sign .= $key . $value;
            }
        }
        
        // Add secret key
        $string_to_sign .= $secret_key;
        
        // Return MD5 hash
        return md5($string_to_sign);
    }
    
    /**
     * Verify webhook signature
     */
    private function verifyWebhookSignature($data) {
        $received_signature = $data['signature'] ?? null;
        $secret_key = $this->config->get('payment_holestpay_secret_key');
        
        if (!$received_signature || !$secret_key) {
            return false;
        }
        
        $calculated_signature = $this->generateSignature($data, $secret_key);
        return $received_signature === $calculated_signature;
    }
    
    /**
     * Send HTTP request
     */
    private function sendHttpRequest($url, $data, $method = 'POST') {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen(json_encode($data))
            ));
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_error($ch)) {
            throw new Exception('cURL Error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        if ($http_code !== 200) {
            throw new Exception('HTTP Error: ' . $http_code);
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Get order statuses for admin
     */
    public function getOrderStatuses() {
        $sql = "SELECT order_id, holestpay_status, holestpay_uid 
                FROM `" . DB_PREFIX . "order` 
                WHERE holestpay_uid IS NOT NULL";
        
        $result = $this->db->query($sql);
        return $result->rows;
    }
    
    /**
     * Install extension (create database tables)
     */
    public function install() {
        // Add HolestPay fields to order table
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` 
                          ADD COLUMN `holestpay_uid` VARCHAR(255) NULL AFTER `order_id`,
                          ADD COLUMN `holestpay_status` VARCHAR(255) NULL AFTER `holestpay_uid`");
        
        // Add index for better performance
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` 
                          ADD INDEX `idx_holestpay_uid` (`holestpay_uid`)");
    }
    
    /**
     * Uninstall extension (remove database tables)
     */
    public function uninstall() {
        // Remove HolestPay fields from order table
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` 
                          DROP COLUMN `holestpay_uid`,
                          DROP COLUMN `holestpay_status`");
        
        // Remove index
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` 
                          DROP INDEX `idx_holestpay_uid`");
    }
}
