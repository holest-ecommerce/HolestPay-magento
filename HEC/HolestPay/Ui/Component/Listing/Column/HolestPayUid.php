<?php
namespace HEC\HolestPay\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Ui\Component\Listing\Columns\Column;

class HolestPayUid extends Column
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
                $holestpayUid = isset($item['holestpay_uid']) ? $item['holestpay_uid'] : '';
                $orderId = isset($item['entity_id']) ? $item['entity_id'] : null;
                
                // Debug logging - log what data we're receiving
                if (isset($item['increment_id']) && $item['increment_id'] === '000000006') {
                    error_log("HolestPayUid Debug - Order 000000006: " . json_encode([
                        'entity_id' => $item['entity_id'],
                        'increment_id' => $item['increment_id'],
                        'holestpay_uid' => $holestpayUid,
                        'all_data' => $item
                    ]));
                }
                
                // Handle empty values
                if (empty($holestpayUid)) {
                    $item[$this->getData('name')] = '-';
                    continue;
                }
                
                if ($orderId) {
                    $url = $this->urlBuilder->getUrl('sales/order/view', ['order_id' => $orderId]);
                    $item[$this->getData('name')] = sprintf('<a href="%s">%s</a>', $url, $holestpayUid);
                } else {
                    $item[$this->getData('name')] = $holestpayUid;
                }
            }
        }
        return $dataSource;
    }
}
