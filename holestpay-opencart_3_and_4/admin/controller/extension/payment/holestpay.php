<?php
/**
 * Admin Controller for HolestPay Payment Extension
 * 
 * Handles admin configuration and order management
 */

class ControllerExtensionPaymentHolestPay extends Controller {
    
    private $error = array();
    
    public function index() {
        $this->load->language('extension/payment/holestpay');
        
        $this->document->setTitle($this->language->get('heading_title'));
        
        $this->load->model('setting/setting');
        
        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_holestpay', $this->request->post);
            
            $this->session->data['success'] = $this->language->get('text_success');
            
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }
        
        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }
        
        if (isset($this->error['merchant_site_uid'])) {
            $data['error_merchant_site_uid'] = $this->error['merchant_site_uid'];
        } else {
            $data['error_merchant_site_uid'] = '';
        }
        
        if (isset($this->error['secret_key'])) {
            $data['error_secret_key'] = $this->error['secret_key'];
        } else {
            $data['error_secret_key'] = '';
        }
        
        $data['breadcrumbs'] = array();
        
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        );
        
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
        );
        
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/holestpay', 'user_token=' . $this->session->data['user_token'], true)
        );
        
        $data['action'] = $this->url->link('extension/payment/holestpay', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);
        
        // Status
        if (isset($this->request->post['payment_holestpay_status'])) {
            $data['payment_holestpay_status'] = $this->request->post['payment_holestpay_status'];
        } else {
            $data['payment_holestpay_status'] = $this->config->get('payment_holestpay_status');
        }
        
        // Title
        if (isset($this->request->post['payment_holestpay_title'])) {
            $data['payment_holestpay_title'] = $this->request->post['payment_holestpay_title'];
        } else {
            $data['payment_holestpay_title'] = $this->config->get('payment_holestpay_title');
        }
        
        // Environment
        if (isset($this->request->post['payment_holestpay_environment'])) {
            $data['payment_holestpay_environment'] = $this->request->post['payment_holestpay_environment'];
        } else {
            $data['payment_holestpay_environment'] = $this->config->get('payment_holestpay_environment');
        }
        
        // Merchant Site UID
        if (isset($this->request->post['payment_holestpay_merchant_site_uid'])) {
            $data['payment_holestpay_merchant_site_uid'] = $this->request->post['payment_holestpay_merchant_site_uid'];
        } else {
            $data['payment_holestpay_merchant_site_uid'] = $this->config->get('payment_holestpay_merchant_site_uid');
        }
        
        // Secret Key
        if (isset($this->request->post['payment_holestpay_secret_key'])) {
            $data['payment_holestpay_secret_key'] = $this->request->post['payment_holestpay_secret_key'];
        } else {
            $data['payment_holestpay_secret_key'] = $this->config->get('payment_holestpay_secret_key');
        }
        
        // Order Status
        if (isset($this->request->post['payment_holestpay_order_status'])) {
            $data['payment_holestpay_order_status'] = $this->request->post['payment_holestpay_order_status'];
        } else {
            $data['payment_holestpay_order_status'] = $this->config->get('payment_holestpay_order_status');
        }
        
        // Sort Order
        if (isset($this->request->post['payment_holestpay_sort_order'])) {
            $data['payment_holestpay_sort_order'] = $this->request->post['payment_holestpay_sort_order'];
        } else {
            $data['payment_holestpay_sort_order'] = $this->config->get('payment_holestpay_sort_order');
        }
        
        // Country restrictions
        if (isset($this->request->post['payment_holestpay_allowspecific'])) {
            $data['payment_holestpay_allowspecific'] = $this->request->post['payment_holestpay_allowspecific'];
        } else {
            $data['payment_holestpay_allowspecific'] = $this->config->get('payment_holestpay_allowspecific');
        }
        
        if (isset($this->request->post['payment_holestpay_specificcountry'])) {
            $data['payment_holestpay_specificcountry'] = $this->request->post['payment_holestpay_specificcountry'];
        } else {
            $data['payment_holestpay_specificcountry'] = $this->config->get('payment_holestpay_specificcountry');
        }
        
        // Order total limits
        if (isset($this->request->post['payment_holestpay_min_order_total'])) {
            $data['payment_holestpay_min_order_total'] = $this->request->post['payment_holestpay_min_order_total'];
        } else {
            $data['payment_holestpay_min_order_total'] = $this->config->get('payment_holestpay_min_order_total');
        }
        
        if (isset($this->request->post['payment_holestpay_max_order_total'])) {
            $data['payment_holestpay_max_order_total'] = $this->request->post['payment_holestpay_max_order_total'];
        } else {
            $data['payment_holestpay_max_order_total'] = $this->config->get('payment_holestpay_max_order_total');
        }
        
        // Load order statuses
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        
        // Load countries
        $this->load->model('localisation/country');
        $data['countries'] = $this->model_localisation_country->getCountries();
        
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        
        $this->response->setOutput($this->load->view('extension/payment/holestpay', $data));
    }
    
    /**
     * Validate form data
     */
    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/payment/holestpay')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        
        if (empty($this->request->post['payment_holestpay_merchant_site_uid'])) {
            $this->error['merchant_site_uid'] = $this->language->get('error_merchant_site_uid');
        }
        
        if (empty($this->request->post['payment_holestpay_secret_key'])) {
            $this->error['secret_key'] = $this->language->get('error_secret_key');
        }
        
        return !$this->error;
    }
    
    /**
     * Install extension
     */
    public function install() {
        $this->load->model('extension/payment/holestpay');
        $this->model_extension_payment_holestpay->install();
    }
    
    /**
     * Uninstall extension
     */
    public function uninstall() {
        $this->load->model('extension/payment/holestpay');
        $this->model_extension_payment_holestpay->uninstall();
    }
    
    /**
     * View HolestPay orders
     */
    public function orders() {
        $this->load->language('extension/payment/holestpay');
        
        $this->document->setTitle($this->language->get('heading_orders'));
        
        $this->load->model('extension/payment/holestpay');
        
        $data['breadcrumbs'] = array();
        
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        );
        
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
        );
        
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/holestpay', 'user_token=' . $this->session->data['user_token'], true)
        );
        
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_orders'),
            'href' => $this->url->link('extension/payment/holestpay/orders', 'user_token=' . $this->session->data['user_token'], true)
        );
        
        // Get HolestPay orders
        $data['holestpay_orders'] = $this->model_extension_payment_holestpay->getOrderStatuses();
        
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        
        $this->response->setOutput($this->load->view('extension/payment/holestpay_orders', $data));
    }
}
