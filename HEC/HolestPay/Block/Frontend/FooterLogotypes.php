<?php
/**
 * HolestPay Footer Logotypes Block
 *
 * Renders HolestPay logotypes (cards, banks, 3DS) in the footer
 * based on the configuration and environment settings
 */
namespace HEC\HolestPay\Block\Frontend;

use Magento\Framework\View\Element\Template;
use Magento\Framework\App\Config\ScopeConfigInterface;
use HEC\HolestPay\Model\ConfigurationManager;

class FooterLogotypes extends Template
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var ConfigurationManager
     */
    protected $configurationManager;

    /**
     * @var string
     */
    protected $methodCode = 'holestpay';

    public function __construct(
        Template\Context $context,
        ScopeConfigInterface $scopeConfig,
        ConfigurationManager $configurationManager,
        array $data = []
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->configurationManager = $configurationManager;
        parent::__construct($context, $data);
    }

    /**
     * Check if footer logotypes should be displayed
     *
     * @return bool
     */
    public function shouldDisplayFooterLogotypes()
    {
        return (bool) $this->scopeConfig->getValue(
            'payment/' . $this->methodCode . '/insert_footer_logotypes',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get current environment (sandbox or production)
     *
     * @return string
     */
    public function getEnvironment()
    {
        return $this->scopeConfig->getValue(
            'payment/' . $this->methodCode . '/environment',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get configuration data for current environment
     *
     * @return array|null
     */
    public function getConfigurationData()
    {
        $environment = $this->getEnvironment();
        if (!$environment) {
            return null;
        }

        return $this->configurationManager->getConfiguration($environment);
    }

    /**
     * Get POS parameters from configuration
     *
     * @return array
     */
    public function getPosParameters()
    {
        $configData = $this->getConfigurationData();
        if (!$configData || !isset($configData['pos_parameters'])) {
            return [];
        }

        return $configData['pos_parameters'];
    }

    /**
     * Get footer logotypes HTML
     *
     * @return string
     */
    public function getFooterLogotypesHtml()
    {
        if (!$this->shouldDisplayFooterLogotypes()) {
            return '';
        }

        $posParameters = $this->getPosParameters();
        if (empty($posParameters)) {
            return '';
        }

        $html = '<div class="hpay_footer_branding" style="display:flex;justify-content:center;padding:4px 0;">';
        
        // Extract and render cards from configuration (comma-separated image URLs only)
        if (isset($posParameters['Logotypes Card Images']) && is_string($posParameters['Logotypes Card Images'])) {
            $cardUrls = array_filter(array_map('trim', explode("\n", $posParameters['Logotypes Card Images'])));
            if (!empty($cardUrls)) {
                $html .= '<div class="hpay-footer-branding-cards" style="display:flex">';
                foreach ($cardUrls as $cardUrl) {
                    if (!empty($cardUrl)) {
                        $html .= '<span style="padding:0 5px;">';
                        $html .= '<img style="height:30px;" src="' . $this->escapeUrl($cardUrl) . '" alt="Card" />';
                        $html .= '</span>';
                    }
                }
                $html .= '</div>';
                $html .= '<div style="padding: 0 25px;">&nbsp;</div>';
            }
        }

        // Extract and render banks from configuration (image_url:link_url pairs)
        if (isset($posParameters['Logotypes Banks']) && is_string($posParameters['Logotypes Banks'])) {
            $bankPairs = array_filter(array_map('trim', explode("\n", $posParameters['Logotypes Banks'])));
            if (!empty($bankPairs)) {
                $html .= '<div class="hpay-footer-branding-bank" style="display:flex">';
                foreach ($bankPairs as $bankPair) {
                    if (!empty($bankPair) && strpos($bankPair, ':') !== false) {
                        // Temporarily replace protocol colons to avoid splitting URLs incorrectly
                        $tempPair = str_replace(['https://', 'http://'], ['HTTPS_PROTOCOL', 'HTTP_PROTOCOL'], $bankPair);
                        list($imageUrl, $linkUrl) = array_map('trim', explode(':', $tempPair, 2));
                        
                        // Restore protocol colons
                        $imageUrl = str_replace(['HTTPS_PROTOCOL', 'HTTP_PROTOCOL'], ['https://', 'http://'], $imageUrl);
                        $linkUrl = str_replace(['HTTPS_PROTOCOL', 'HTTP_PROTOCOL'], ['https://', 'http://'], $linkUrl);
                        
                        if (!empty($imageUrl) && !empty($linkUrl)) {
                            $html .= '<a href="' . $this->escapeUrl($linkUrl) . '" target="_blank" style="padding:0 5px;">';
                            $html .= '<img style="height:32px;" src="' . $this->escapeUrl($imageUrl) . '" alt="Bank" />';
                            $html .= '</a>';
                        }
                    }
                }
                $html .= '</div>';
                $html .= '<div style="padding: 0 10px;">&nbsp;</div>';
            }
        }

        // Extract and render 3DS from configuration (image_url:link_url pairs)
        if (isset($posParameters['Logotypes 3DS']) && is_string($posParameters['Logotypes 3DS'])) {
            $tdsPairs = array_filter(array_map('trim', explode("\n", $posParameters['Logotypes 3DS'])));
            if (!empty($tdsPairs)) {
                $html .= '<div class="hpay-footer-branding-3ds" style="display:flex">';
                foreach ($tdsPairs as $tdsPair) {
                    if (!empty($tdsPair) && strpos($tdsPair, ':') !== false) {
                        // Temporarily replace protocol colons to avoid splitting URLs incorrectly
                        $tempPair = str_replace(['https://', 'http://'], ['HTTPS_PROTOCOL', 'HTTP_PROTOCOL'], $tdsPair);
                        list($imageUrl, $linkUrl) = array_map('trim', explode(':', $tempPair, 2));
                        
                        // Restore protocol colons
                        $imageUrl = str_replace(['HTTPS_PROTOCOL', 'HTTP_PROTOCOL'], ['https://', 'http://'], $imageUrl);
                        $linkUrl = str_replace(['HTTPS_PROTOCOL', 'HTTP_PROTOCOL'], ['https://', 'http://'], $linkUrl);
                        
                        if (!empty($imageUrl) && !empty($linkUrl)) {
                            $html .= '<a href="' . $this->escapeUrl($linkUrl) . '" target="_blank" style="padding:0 5px;">';
                            $html .= '<img style="height:32px;" src="' . $this->escapeUrl($imageUrl) . '" alt="3DS" />';
                            $html .= '</a>';
                        }
                    }
                }
                $html .= '</div>';
            }
        }

        $html .= '</div>';
        
        return $html;
    }
}
