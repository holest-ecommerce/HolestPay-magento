<?php
namespace HolestPay\HolestPay\Ui\Component\Listing\Column;

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
                $url = $orderId ? $this->urlBuilder->getUrl('sales/order/view', ['order_id' => $orderId]) : '#';
                $item[$this->getData('name')] = sprintf('<a href="%s">%s</a>', $url, $status);
            }
        }
        return $dataSource;
    }
}


