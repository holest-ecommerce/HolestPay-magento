<?php
/**
 * HolestPay Payment Controller for OpenCart 3 and 4
 * 
 * Handles payment callbacks and webhooks from HolestPay
 */

class ControllerExtensionPaymentHolestPay extends Controller {
    
    /**
     * Payment callback - customer returns from HolestPay
     */
    public function callback() {
        $this->load->model('extension/payment/holestpay');
        
        $order_uid = $this->request->get['order_uid'] ?? null;
        $status = $this->request->get['status'] ?? null;
        
        if (!$order_uid || !$status) {
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return;
        }
        
        // Process the callback
        $order_id = $this->model_extension_payment_holestpay->processCallback([
            'order_uid' => $order_uid,
            'status' => $status
        ]);
        
        if ($order_id) {
            // Redirect based on status
            if (strpos($status, 'SUCCESS') !== false || strpos($status, 'PAID') !== false) {
                $this->response->redirect($this->url->link('checkout/success', '', true));
            } else {
                $this->session->data['error'] = 'Payment was not successful. Please try again.';
                $this->response->redirect($this->url->link('checkout/checkout', '', true));
            }
        } else {
            $this->session->data['error'] = 'Order not found. Please contact support.';
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
        }
    }
    
    /**
     * Webhook endpoint - receives updates from HolestPay
     */
    public function webhook() {
        $this->load->model('extension/payment/holestpay');
        
        // Get raw POST data
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid data']);
            return;
        }
        
        // Process webhook
        $result = $this->model_extension_payment_holestpay->processWebhook($data);
        
        if ($result) {
            http_response_code(200);
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Processing failed']);
        }
    }
    
    /**
     * Success page - customer successfully paid
     */
    public function success() {
        $this->load->language('extension/payment/holestpay');
        
        $this->document->setTitle($this->language->get('heading_success'));
        
        $data['breadcrumbs'] = array();
        
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/home')
        );
        
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_success'),
            'href' => $this->url->link('extension/payment/holestpay/success')
        );
        
        $data['heading_title'] = $this->language->get('heading_success');
        $data['text_success'] = $this->language->get('text_success_message');
        $data['text_continue'] = $this->language->get('text_continue');
        
        $data['continue'] = $this->url->link('common/home');
        
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');
        
        $this->response->setOutput($this->load->view('extension/payment/holestpay_success', $data));
    }
    
    /**
     * Failure page - payment failed
     */
    public function failure() {
        $this->load->language('extension/payment/holestpay');
        
        $this->document->setTitle($this->language->get('heading_failure'));
        
        $data['breadcrumbs'] = array();
        
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/home')
        );
        
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_failure'),
            'href' => $this->url->link('extension/payment/holestpay/failure')
        );
        
        $data['heading_title'] = $this->language->get('heading_failure');
        $data['text_failure'] = $this->language->get('text_failure_message');
        $data['text_continue'] = $this->language->get('text_continue');
        
        $data['continue'] = $this->url->link('checkout/checkout');
        
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');
        
        $this->response->setOutput($this->load->view('extension/payment/holestpay_failure', $data));
    }
}
