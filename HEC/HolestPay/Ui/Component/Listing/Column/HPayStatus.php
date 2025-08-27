<?php
namespace HEC\HolestPay\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Ui\Component\Listing\Columns\Column;

class HPayStatus extends Column
{
    /** @var UrlInterface */
    private $urlBuilder;

    public function __construct(
        \Magento\Framework\View\Element\UiComponent\ContextInterface $context,
        \Magento\Framework\View\Element\UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
        $this->urlBuilder = $urlBuilder;
    }

    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {
                $status = isset($item['hpay_status']) ? $item['hpay_status'] : '';
                $orderId = isset($item['entity_id']) ? $item['entity_id'] : null;
                
                // Debug logging - log what data we're receiving
                if (isset($item['increment_id']) && $item['increment_id'] === '000000006') {
                    error_log("HPayStatus Debug - Order 000000006: " . json_encode([
                        'entity_id' => $item['entity_id'],
                        'increment_id' => $item['increment_id'],
                        'hpay_status' => $status,
                        'all_data' => $item
                    ]));
                }
                
                // Handle empty values
                if (empty($status)) {
                    $status = '-'; // Show dash for empty status
                }
                
                if ($orderId && $status !== '-') {
                    $url = $this->urlBuilder->getUrl('sales/order/view', ['order_id' => $orderId]);
                    $item[$this->getData('name')] = sprintf('<a href="%s">%s</a>', $url, $status);
                } else {
                    $item[$this->getData('name')] = $status;
                }
            }
        }
        return $dataSource;
    }
}


