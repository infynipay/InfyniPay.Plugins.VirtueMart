<?php
/**
 * Infynipay API Client
 * Platform-agnostic HTTP client for all Infynipay API calls
 *
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace InfyniPay\Plugin\Payment\Infynipay\Service;

defined('_JEXEC') or die;

use Exception;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Log\Log;

class ApiClient
{
    /**
     * @var string Infynipay App API URL
     */
    private $appApiUrl;

    /**
     * @var string Infynipay Payment API URL
     */
    private $paymentApiUrl;

    /**
     * @var string Current app ID
     */
    private $appId;

    /**
     * @var string Current bearer token
     */
    private $token;

    /**
     * @var callable Logger function
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct(
        string $appApiUrl,
        string $paymentApiUrl,
        string $appId,
        string $token,
        ?callable $logger = null
    ) {
        $this->appApiUrl = rtrim($appApiUrl, '/');
        $this->paymentApiUrl = rtrim($paymentApiUrl, '/');
        $this->appId = $appId;
        $this->token = $token;
        $this->logger = $logger;
    }

    /**
     * Exchange short-lived authorization code for persistent app credentials
     *
     * @param string $licenseId License UUID from Infynipay
     * @param string $authCode  Authorization code (short-lived JWT)
     * @return array|null Array with [licenseId, isSandbox, appId, authorizationCode] or null on error
     */
    public function fetchLicense(string $licenseId, string $authCode): ?array
    {
        try {
            $url = $this->appApiUrl . '/api/licenses/' . urlencode($licenseId);

            $response = HttpFactory::getHttp()->get($url, [
                'Accept'        => 'text/plain',
                'Authorization' => 'Bearer ' . $authCode,
            ], 10);

            if ($response->code !== 200) {
                $this->log("fetchLicense failed: HTTP {$response->code}", 'warning');
                return null;
            }

            $data = json_decode($response->body, true);

            if (!is_array($data)) {
                $this->log('fetchLicense: invalid JSON response', 'warning');
                return null;
            }

            // Response is array of license objects
            return $data;
        } catch (Exception $e) {
            $this->log('fetchLicense exception: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Get payment checkout methods (providers + credit card option)
     *
     * @return array Array of checkout method groups or [] on error
     */
    public function fetchCheckoutMethods(): ?array
    {
        if (empty($this->appId) || empty($this->token)) {
            return null;
        }

        try {
            $url = $this->appApiUrl . '/api/apps/' . urlencode($this->appId)
                . '/payment-profiles/payment-checkout-methods';

            $response = HttpFactory::getHttp()->get($url, [
                'Accept'        => 'text/plain',
                'Authorization' => 'Bearer ' . $this->token,
            ], 10);

            if ($response->code !== 200) {
                $this->log("fetchCheckoutMethods HTTP {$response->code}", 'warning');
                return [];
            }

            $data = json_decode($response->body, true);
            return is_array($data) ? $data : [];
        } catch (Exception $e) {
            $this->log('fetchCheckoutMethods exception: ' . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * Get all available providers (public endpoint, cache 24h)
     *
     * @return array Array of provider objects or [] on error
     */
    public function fetchProviders(): ?array
    {
        try {
            $url = $this->appApiUrl . '/api/apps/providers';

            $response = HttpFactory::getHttp()->get($url, [
                'Accept' => 'application/json',
            ], 10);

            if ($response->code !== 200) {
                $this->log("fetchProviders HTTP {$response->code}", 'warning');
                return [];
            }

            $data = json_decode($response->body, true);
            return is_array($data) ? $data : [];
        } catch (Exception $e) {
            $this->log('fetchProviders exception: ' . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * Create hosted payment session
     *
     * @param array $payload Hosted payment request payload
     * @return string|null Redirect URL, or 'APP_INACTIVE'/'ALREADY_PAID' sentinel, or null on error
     */
    public function createHostedPayment(array $payload): ?string
    {
        if (empty($this->appId) || empty($this->token)) {
            return null;
        }

        try {
            $url = $this->paymentApiUrl . '/api/apps/' . urlencode($this->appId) . '/payments/hosted-page';

            $response = HttpFactory::getHttp()->post($url, json_encode($payload), [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $this->token,
            ], 15);

            // Success
            if ($response->code === 200 || $response->code === 201) {
                $data = json_decode($response->body, true);
                return $data['redirectUrl'] ?? null;
            }

            // HTTP 400 - check for AppInactiveException
            if ($response->code === 400) {
                $errorBody = json_decode($response->body, true);

                if (is_array($errorBody) && ($errorBody['TypeName'] ?? '') === 'AppInactiveException') {
                    $this->log('App is inactive (license expired)', 'warning');
                    return 'APP_INACTIVE';
                }

                // Try to recover existing payment
                $orderId = $payload['referenceId'] ?? null;
                if ($orderId) {
                    $existingUrl = $this->getPaymentByReference($orderId);
                    if (is_array($existingUrl)) {
                        $state = strtolower($existingUrl['paymentState'] ?? '');

                        if ($state === 'captured') {
                            $this->log("Order {$orderId} already paid", 'info');
                            return 'ALREADY_PAID';
                        }

                        if (!empty($existingUrl['redirectUrl'])) {
                            $this->log("Recovered existing payment for order {$orderId}", 'info');
                            return $existingUrl['redirectUrl'];
                        }
                    }
                }

                $this->log("createHostedPayment HTTP 400: {$response->body}", 'warning');
                return null;
            }

            $this->log("createHostedPayment HTTP {$response->code}: {$response->body}", 'error');
            return null;
        } catch (Exception $e) {
            $this->log('createHostedPayment exception: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Get payment by reference ID (order ID)
     *
     * @param string $referenceId Order reference ID
     * @return array|null Array with payment data or null on error
     */
    public function getPaymentByReference(string $referenceId): ?array
    {
        if (empty($this->appId) || empty($this->token)) {
            return null;
        }

        try {
            $url = $this->paymentApiUrl . '/api/apps/' . urlencode($this->appId)
                . '/payments/referenceId:' . urlencode($referenceId);

            $response = HttpFactory::getHttp()->get($url, [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type'  => 'application/json',
            ], 10);

            if ($response->code !== 200) {
                $this->log("getPaymentByReference HTTP {$response->code} for ref {$referenceId}", 'warning');
                return null;
            }

            $data = json_decode($response->body, true);
            return is_array($data) ? $data : null;
        } catch (Exception $e) {
            $this->log('getPaymentByReference exception: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Register webhook authorization secret
     *
     * @param string $secret 64-char hex webhook secret
     * @return bool True on success
     */
    public function registerWebhookSecret(string $secret): bool
    {
        if (empty($this->appId) || empty($this->token)) {
            return false;
        }

        try {
            $url = $this->appApiUrl . '/api/apps/' . urlencode($this->appId);

            $payload = [
                'webhookAuthorizationHeaderScheme'    => ['value' => 'Bearer'],
                'webhookAuthorizationHeaderParameter' => ['value' => $secret],
            ];

            $response = HttpFactory::getHttp()->patch($url, json_encode($payload), [
                'Accept'        => 'text/plain',
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->token,
            ], 10);

            if ($response->code !== 200) {
                $this->log("registerWebhookSecret HTTP {$response->code}", 'warning');
                return false;
            }

            return true;
        } catch (Exception $e) {
            $this->log('registerWebhookSecret exception: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Get app status (check if first gateway connected)
     *
     * @return array|null Array with [appId, isConnectFirstGateway] or null on error
     */
    public function getAppStatus(): ?array
    {
        if (empty($this->appId) || empty($this->token)) {
            return null;
        }

        try {
            $url = $this->appApiUrl . '/api/apps/' . urlencode($this->appId);

            $response = HttpFactory::getHttp()->get($url, [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $this->token,
            ], 10);

            if ($response->code !== 200) {
                $this->log("getAppStatus HTTP {$response->code}", 'warning');
                return null;
            }

            $data = json_decode($response->body, true);
            return is_array($data) ? $data : null;
        } catch (Exception $e) {
            $this->log('getAppStatus exception: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Log message
     */
    private function log(string $message, string $level = 'info')
    {
        if ($this->logger && is_callable($this->logger)) {
            call_user_func($this->logger, $message, $level);
        }
    }
}
