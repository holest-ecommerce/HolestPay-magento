<?php
/**
 * HolestPay Add CSP Headers Observer
 *
 * Adds Content Security Policy headers to allow HolestPay domains and unsafe-eval
 */
namespace HEC\HolestPay\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\App\Response\Http;
use Magento\Framework\App\RequestInterface;
use Laminas\Http\Header\GenericHeader;
use Magento\Framework\App\Config\ScopeConfigInterface;
use HEC\HolestPay\Model\Trait\DebugLogTrait;

class AddCspHeaders implements ObserverInterface
{
    use DebugLogTrait;

    /**
     * @var Http
     */
    private $response;

    /**
     * @param Http $response
     */
    public function __construct(
        Http $response
    ) {
        $this->response = $response;
    }

    /**
     * Execute observer
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            // Get current CSP header if it exists
            $currentCsp = $this->response->getHeader('Content-Security-Policy');
            $cspValue = $currentCsp ? $currentCsp->getFieldValue() : '';

            // Add unsafe-eval to existing CSP if not present
            $updatedCsp = $this->addUnsafeEval($cspValue);
            
            if ($updatedCsp && $updatedCsp !== $cspValue) {
                // Remove existing CSP header if present
                if ($currentCsp) {
                    $this->response->getHeaders()->removeHeader('Content-Security-Policy');
                }
                
                // Create proper header object and add new CSP header
                $cspHeader = new GenericHeader('Content-Security-Policy', $updatedCsp);
                $this->response->getHeaders()->addHeader($cspHeader);
            }
        } catch (\Exception $e) {
            // Log error but don't break the page
            error_log('HolestPay CSP Headers Error: ' . $e->getMessage());
        }
    }

    /**
     * Add unsafe-eval to script-src directive if not present
     *
     * @param string $existingCsp
     * @return string
     */
    private function addUnsafeEval($existingCsp)
    {
        if (empty($existingCsp)) {
            return '';
        }

        // Parse existing CSP
        $directives = $this->parseCsp($existingCsp);
        
        // Check if script-src exists and add unsafe-eval if not present
        if (isset($directives['script-src'])) {
            $scriptSrc = $directives['script-src'];
            if (strpos($scriptSrc, "'unsafe-eval'") === false) {
                $directives['script-src'] = $scriptSrc . " 'unsafe-eval'";
            }
        } else {
            // If no script-src directive exists, create one with unsafe-eval
            $directives['script-src'] = "'unsafe-eval'";
        }

        // Build final CSP string
        $cspParts = [];
        foreach ($directives as $directive => $value) {
            $cspParts[] = $directive . ' ' . $value;
        }

        return implode('; ', $cspParts);
    }

    /**
     * Parse existing CSP string into directives
     *
     * @param string $csp
     * @return array
     */
    private function parseCsp($csp)
    {
        if (empty($csp)) {
            return [];
        }

        $directives = [];
        $parts = explode(';', $csp);
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }
            
            $spacePos = strpos($part, ' ');
            if ($spacePos !== false) {
                $directive = trim(substr($part, 0, $spacePos));
                $value = trim(substr($part, $spacePos + 1));
                $directives[$directive] = $value;
            }
        }

        return $directives;
    }
}
