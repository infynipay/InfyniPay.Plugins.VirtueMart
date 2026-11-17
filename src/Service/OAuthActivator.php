<?php
/**
 * Infynipay OAuth Activator
 *
 * Self-contained activation of a merchant account from the OAuth return
 * (licenseId + authorizationCode). Usable WITHOUT a live plugin instance, because
 * payment-group plugins are NOT imported at onAfterRoute time in Joomla — the OAuth
 * return lands on the com_plugins admin page where only `system` plugins run.
 * The custom Setupbutton field invokes this on that page instead.
 *
 * Reads/writes credentials directly to #__extensions and logs every critical stage
 * (see INTEGRATION_GUIDE.md Section 21).
 *
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace InfyniPay\Plugin\Payment\Infynipay\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use InfyniPay\Plugin\Payment\Infynipay\Helper\PaymentHelper;

class OAuthActivator
{
    const PAYMENT_APP_API_URL = 'https://appapi.infynipay.com';
    const PAYMENT_API_URL     = 'https://api.infynipay.com';

    /**
     * Run the full activation. Returns:
     *   ['status' => 'ok'|'skip'|'error', 'message' => string, 'error' => string, 'params' => array]
     * 'params' is the freshly-saved credential set (on ok/skip) for immediate rendering.
     */
    public function activate(string $licenseId, string $authCode): array
    {
        try {
            PaymentHelper::log(
                sprintf(
                    'OAuth return detected | licenseId=%s hasAuthCode=%d client=administrator',
                    PaymentHelper::mask($licenseId),
                    $authCode !== '' ? 1 : 0
                ),
                'info',
                'oauth.entry'
            );

            // (a) CSRF / security-token guard
            if (!Session::checkToken('get')) {
                PaymentHelper::log('Security token mismatch — activation aborted', 'warning', 'oauth.csrf');
                return $this->err('Activation failed: security token mismatch. Please reconnect.');
            }
            PaymentHelper::log('Security token OK', 'info', 'oauth.csrf');

            $current = $this->readParams();

            // (b) Re-activation guard
            $stored = (string) ($current['license_id'] ?? '');
            if ($stored !== '' && $stored === $licenseId) {
                PaymentHelper::log('Already connected — skipping API exchange | licenseId=' . PaymentHelper::mask($licenseId), 'info', 'oauth.skip');
                return ['status' => 'skip', 'message' => 'Infynipay already connected.', 'error' => '', 'params' => $current];
            }

            // (c) Exchange authorizationCode for app credentials
            PaymentHelper::log('Exchanging authorizationCode | GET /api/licenses/' . PaymentHelper::mask($licenseId), 'info', 'oauth.token');
            $client   = new ApiClient(self::PAYMENT_APP_API_URL, self::PAYMENT_API_URL, '', '', [PaymentHelper::class, 'log']);
            $licenses = $client->fetchLicense($licenseId, $authCode);

            if (!is_array($licenses) || count($licenses) === 0) {
                PaymentHelper::log(
                    'License exchange failed — empty/invalid response, aborting | licenseCount=' . (is_array($licenses) ? count($licenses) : 'null'),
                    'error',
                    'oauth.token'
                );
                return $this->err('Activation failed: could not reach the Infynipay API.');
            }
            PaymentHelper::log('License API responded | licenseCount=' . count($licenses), 'info', 'oauth.token');

            // (d) Parse sandbox + live credentials
            $sandboxAppId = '';
            $sandboxToken = '';
            $liveAppId    = '';
            $liveToken    = '';

            foreach ($licenses as $license) {
                if (!is_array($license)) {
                    continue;
                }
                $isSandbox = (bool) ($license['isSandbox'] ?? false);
                $appId     = (string) ($license['appId'] ?? '');
                $token     = (string) ($license['authorizationCode'] ?? '');
                if ($appId === '' || $token === '') {
                    continue;
                }
                if ($isSandbox) {
                    $sandboxAppId = $appId;
                    $sandboxToken = $token;
                } else {
                    $liveAppId = $appId;
                    $liveToken = $token;
                }
            }

            PaymentHelper::log(
                sprintf('Credentials parsed | sandbox=%d live=%d', $sandboxAppId !== '' ? 1 : 0, $liveAppId !== '' ? 1 : 0),
                'info',
                'oauth.parse'
            );

            if ($sandboxAppId === '' && $liveAppId === '') {
                PaymentHelper::log('No usable credentials in response — aborting', 'error', 'oauth.parse');
                return $this->err('Activation failed: unexpected response from Infynipay API.');
            }

            // Generate 64-char hex webhook secrets (once per environment)
            $sandboxSecret = ($sandboxAppId !== '')
                ? bin2hex(random_bytes(32))
                : (string) ($current['sandbox_webhook_secret'] ?? '');
            $liveSecret = ($liveAppId !== '')
                ? bin2hex(random_bytes(32))
                : (string) ($current['live_webhook_secret'] ?? '');

            // Merge into existing params (preserve order-status mappings etc.)
            $newParams = array_merge($current, [
                'license_id'             => $licenseId,
                'sandbox_app_id'         => $sandboxAppId ?: (string) ($current['sandbox_app_id'] ?? ''),
                'sandbox_token'          => $sandboxToken ?: (string) ($current['sandbox_token'] ?? ''),
                'sandbox_webhook_secret' => $sandboxSecret,
                'live_app_id'            => $liveAppId ?: (string) ($current['live_app_id'] ?? ''),
                'live_token'             => $liveToken ?: (string) ($current['live_token'] ?? ''),
                'live_webhook_secret'    => $liveSecret,
            ]);

            // (e) Persist
            $saved = $this->saveParams($newParams);
            PaymentHelper::log('Params persisted | ok=' . ($saved ? 1 : 0), $saved ? 'info' : 'error', 'oauth.save');
            if (!$saved) {
                return $this->err('Activation failed: could not save credentials.');
            }

            // (f) Register webhook Bearer secrets, per environment
            if ($sandboxAppId !== '' && $sandboxToken !== '') {
                $sb = new ApiClient(self::PAYMENT_APP_API_URL, self::PAYMENT_API_URL, $sandboxAppId, $sandboxToken, [PaymentHelper::class, 'log']);
                $ok = $sb->registerWebhookSecret($sandboxSecret);
                PaymentHelper::log('Webhook secret registered | env=sandbox ok=' . ($ok ? 1 : 0), $ok ? 'info' : 'warning', 'oauth.webhook');
            }
            if ($liveAppId !== '' && $liveToken !== '') {
                $lv = new ApiClient(self::PAYMENT_APP_API_URL, self::PAYMENT_API_URL, $liveAppId, $liveToken, [PaymentHelper::class, 'log']);
                $ok = $lv->registerWebhookSecret($liveSecret);
                PaymentHelper::log('Webhook secret registered | env=live ok=' . ($ok ? 1 : 0), $ok ? 'info' : 'warning', 'oauth.webhook');
            }

            // (g) Outcome
            PaymentHelper::log('OAuth activation completed | licenseId=' . PaymentHelper::mask($licenseId), 'info', 'oauth.done');
            return ['status' => 'ok', 'message' => 'Infynipay connected successfully.', 'error' => '', 'params' => $newParams];

        } catch (\Throwable $e) {
            PaymentHelper::logThrowable('oauth.error', $e);
            return $this->err('Activation failed unexpectedly. See the Infynipay log for details.');
        }
    }

    private function err(string $message): array
    {
        return ['status' => 'error', 'message' => '', 'error' => $message, 'params' => []];
    }

    /** Read the current plugin params (decoded) directly from #__extensions. */
    private function readParams(): array
    {
        try {
            $db    = Factory::getDbo();
            $query = $db->getQuery(true)
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('infynipay'))
                ->where($db->quoteName('type')    . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder')  . ' = ' . $db->quote('vmpayment'));
            $db->setQuery($query);
            $json = (string) $db->loadResult();
            $arr  = json_decode($json, true);
            return is_array($arr) ? $arr : [];
        } catch (\Throwable $e) {
            PaymentHelper::logThrowable('oauth.read', $e);
            return [];
        }
    }

    private function saveParams(array $params): bool
    {
        try {
            $db    = Factory::getDbo();
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params)))
                ->where($db->quoteName('element') . ' = ' . $db->quote('infynipay'))
                ->where($db->quoteName('type')    . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder')  . ' = ' . $db->quote('vmpayment'));
            $db->setQuery($query);
            $db->execute();
            return true;
        } catch (\Throwable $e) {
            PaymentHelper::logThrowable('oauth.save', $e);
            return false;
        }
    }
}
