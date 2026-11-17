<?php
/**
 * Infynipay Admin Setup Field
 * Renders: OAuth connect card (disconnected) or gateway status cards (connected)
 *
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace InfyniPay\Plugin\Payment\Infynipay\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use InfyniPay\Plugin\Payment\Infynipay\Helper\PaymentHelper;
use InfyniPay\Plugin\Payment\Infynipay\Service\OAuthActivator;

class SetupbuttonField extends FormField
{
    protected $type = 'Setupbutton';

    const PANEL_URL           = 'https://console.infynipay.com';
    const PAYMENT_APP_API_URL = 'https://appapi.infynipay.com';

    public function getLabel(): string
    {
        return '';
    }

    public function getInput(): string
    {
        $this->loadAssets();

        // Unconditional diagnostic: proves the field actually runs on this page and
        // shows exactly which params arrived on the OAuth return. If this line does
        // NOT appear in the log after loading the settings page, the new code is not
        // installed (reinstall / clear opcache). If it appears WITHOUT licenseId on
        // the return, the redirect did not land here with the expected params.
        $diagInput = Factory::getApplication()->input;
        PaymentHelper::log(
            sprintf(
                'Settings field rendered | option=%s view=%s task=%s hasLicenseId=%d hasAuthCode=%d query=%s',
                $diagInput->getCmd('option', ''),
                $diagInput->getCmd('view', ''),
                $diagInput->getCmd('task', ''),
                $diagInput->getString('licenseId', '') !== '' ? 1 : 0,
                $diagInput->getString('authorizationCode', '') !== '' ? 1 : 0,
                Uri::getInstance()->toString(['query'])
            ),
            'debug',
            'field.render'
        );

        // Root-relative ('/joomla5/media/…') so assets inherit the page scheme.
        // Absolute Uri::root() emits http:// behind an HTTPS reverse proxy / tunnel,
        // which the browser then blocks as mixed content.
        $logoBase  = Uri::root(true) . '/media/plg_vmpayment_infynipay/images/logos/';
        $logoLight = $logoBase . 'infynipay-logo-light.png';
        $logoDark  = $logoBase . 'infynipay-logo-dark.png';

        // Flash messages from activation
        $session   = Factory::getApplication()->getSession();
        $flashMsg  = (string) $session->get('ph_activation_msg', '');
        $flashErr  = (string) $session->get('ph_activation_err', '');
        $session->set('ph_activation_msg', '');
        $session->set('ph_activation_err', '');

        // ── OAuth return handling ────────────────────────────────────────────
        // CRITICAL: payment-group plugins are NOT imported at onAfterRoute time in
        // Joomla (only the `system` group is), so the plugin's own onAfterRoute never
        // fires on this com_plugins admin page. This field DOES run here, so the OAuth
        // return (licenseId + authorizationCode) must be handled from here.
        $savedParams = null;
        $input       = Factory::getApplication()->input;
        $oauthLid    = $input->getString('licenseId', '');
        $oauthCode   = $input->getString('authorizationCode', '');

        if ($oauthLid !== '' && $oauthCode !== '') {
            $result = (new OAuthActivator())->activate($oauthLid, $oauthCode);
            if ($result['error'] !== '') {
                $flashErr = $result['error'];
            } elseif ($result['status'] === 'ok' && $result['message'] !== '') {
                // Only announce a genuine new connection. The 'skip' case
                // ("Infynipay already connected.") must not show an alert.
                $flashMsg = $result['message'];
            }
            if (!empty($result['params'])) {
                $savedParams = $result['params'];
            }
        }

        // Connection state MUST come from the shared DB params (the single source of
        // truth), NOT $this->form->getValue() — the com_plugins edit form is bound from
        // per-session user-state that differs between domains (e.g. localhost vs. an
        // HTTPS tunnel have separate cookies/sessions), which made the same plugin show
        // "connected" on one URL and "disconnected" on the other.
        $stored       = is_array($savedParams) ? $savedParams : $this->loadStoredParams();
        $licenseId    = (string) ($stored['license_id'] ?? '');
        $sandboxAppId = (string) ($stored['sandbox_app_id'] ?? '');
        $sandboxToken = (string) ($stored['sandbox_token'] ?? '');
        $liveAppId    = (string) ($stored['live_app_id'] ?? '');
        $liveToken    = (string) ($stored['live_token'] ?? '');

        $isConnected = $licenseId !== '' && ($sandboxAppId !== '' || $liveAppId !== '');

        $html = $this->renderLocalhostWarning();

        if ($flashErr !== '') {
            $html .= '<div class="alert alert-danger mb-3" role="alert">'
                . htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        if ($flashMsg !== '') {
            $html .= '<div class="alert alert-success mb-3" role="alert">'
                . htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        if (!$isConnected) {
            $setupUrl = $this->buildSetupUrl();
            $html .= $this->renderDisconnectedCard($logoLight, $logoDark, $setupUrl);
        } else {
            $html .= $this->renderConnectedCard($logoLight, $logoDark, $sandboxAppId, $sandboxToken, $liveAppId, $liveToken);
        }

        return $html;
    }

    // ── Asset loading ────────────────────────────────────────────────────────

    private function loadAssets(): void
    {
        $doc      = Factory::getApplication()->getDocument();
        $urlBase  = Uri::root(true) . '/media/plg_vmpayment_infynipay/';
        $pathBase = JPATH_ROOT . '/media/plg_vmpayment_infynipay/';

        // Cache-busting token from the file's mtime. Without this, a CDN/proxy in
        // front of the site (e.g. a Cloudflare tunnel) caches css/js by URL and keeps
        // serving stale assets after an update — so the same page renders differently
        // through the tunnel than on localhost. The mtime changes on every update,
        // forcing every cache layer to refetch.
        $ver = static function (string $rel) use ($pathBase): string {
            $file = $pathBase . $rel;
            return is_file($file) ? '?v=' . filemtime($file) : '';
        };

        $doc->addStyleSheet($urlBase . 'css/setupbutton.css' . $ver('css/setupbutton.css'));
        $doc->addStyleSheet($urlBase . 'css/providers.css' . $ver('css/providers.css'));
        $doc->addScript($urlBase . 'js/setupbutton.js' . $ver('js/setupbutton.js'));
    }

    // ── URL helpers ──────────────────────────────────────────────────────────

    private function buildSetupUrl(): string
    {
        // IMPORTANT: return to the edit VIEW (view=plugin&layout=edit), NOT the
        // controller task (task=plugin.edit). A task triggers a server-side redirect
        // to the view and DROPS all extra query params — including the licenseId /
        // authorizationCode the console appends — so the field would never see them.
        $returnUrl = Uri::base() . 'index.php?option=com_plugins&view=plugin&layout=edit'
            . '&extension_id=' . $this->getExtensionId()
            . '&' . Session::getFormToken() . '=1';

        $setupUrl = self::PANEL_URL . '/auth/signin'
            . '?returnUrl=' . urlencode($returnUrl)
            . '&grantAuthorization=true';

        PaymentHelper::log('Built OAuth setup URL | returnUrl=' . $returnUrl, 'debug', 'oauth.url');

        return $setupUrl;
    }

    /**
     * Read the plugin's saved params (decoded) directly from #__extensions.
     * Source of truth for connection state — independent of session/form binding,
     * so every domain (localhost, tunnel) renders an identical connected/disconnected state.
     */
    private function loadStoredParams(): array
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
            PaymentHelper::logThrowable('field.params', $e);
            return [];
        }
    }

    private function getExtensionId(): int
    {
        static $id = null;
        if ($id !== null) {
            return $id;
        }
        try {
            $db    = Factory::getDbo();
            $query = $db->getQuery(true)
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('infynipay'))
                ->where($db->quoteName('type')    . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder')  . ' = ' . $db->quote('vmpayment'));
            $db->setQuery($query);
            $id = (int) ($db->loadResult() ?: 0);
        } catch (\Exception $e) {
            $id = 0;
        }
        return $id;
    }

    // ── Localhost / private-network warning ──────────────────────────────────

    private function renderLocalhostWarning(): string
    {
        $isLocalhost = PaymentHelper::isLocalhostUrl(Uri::root())
            || PaymentHelper::isLocalhostUrl('http://' . ($_SERVER['HTTP_HOST'] ?? '') . '/');

        if (!$isLocalhost) {
            return '';
        }

        // Self-contained (inline style + sized SVG) so the light-yellow design and
        // small icon render correctly even if the external CSS is cached or stale.
        return <<<HTML
<style>
  #ph-localhost-warn {
    display: flex; gap: 12px;
    width: 100%; max-width: 720px; margin: 0 0 16px;
    padding: 14px 16px;
    border-radius: 14px;
    background: #fffbeb;
    border: 1.5px solid #f59e0b;
    box-sizing: border-box;
  }
  #ph-localhost-warn p      { margin: 4px 0 10px; font-size: 13px; line-height: 1.55; color: #78350f; }
  #ph-localhost-warn strong { color: #92400e; }
  #ph-localhost-warn a      { font-size: 13px; font-weight: 600; color: #b45309; text-decoration: underline; }
  #ph-localhost-warn-title  { font-size: 13px; font-weight: 700; color: #92400e; margin: 0; }
</style>
<div id="ph-localhost-warn">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;width:20px;height:20px;color:#b45309;margin-top:1px" aria-hidden="true">
        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
        <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
    </svg>
    <div>
        <p id="ph-localhost-warn-title">Webhook delivery unavailable</p>
        <p>Your store is running on a <strong>localhost / private network URL</strong>. Infynipay payment webhooks cannot reach this address from outside your machine, so order statuses will not update automatically. Use a public domain or a tunnel (Cloudflare Tunnel, ngrok) before accepting live payments.</p>
        <a href="https://github.com/infynipay/infynipay-docs/blob/main/CLOUDFLARE-TUNNEL-INSTRUCTIONS.md" target="_blank" rel="noopener noreferrer">Learn how to set up a Cloudflare Tunnel &rarr;</a>
    </div>
</div>
HTML;
    }

    // ── Disconnected card ────────────────────────────────────────────────────

    private function renderDisconnectedCard(string $logoLight, string $logoDark, string $setupUrl): string
    {
        $logoLightEsc = htmlspecialchars($logoLight, ENT_QUOTES, 'UTF-8');
        $logoDarkEsc  = htmlspecialchars($logoDark,  ENT_QUOTES, 'UTF-8');
        $setupUrlEsc  = htmlspecialchars($setupUrl,  ENT_QUOTES, 'UTF-8');

        return <<<HTML
<div class="card mb-3 ph-card-disconnected ph-is-loading">
    <div class="card-header ph-card-disconnected-header d-flex align-items-center gap-3 fw-semibold p-3">
        <div class="ph-logo-wrap">
            <img src="{$logoLightEsc}" class="ph-logo ph-logo--on-light" alt="Infynipay" loading="eager" decoding="async">
            <img src="{$logoDarkEsc}"  class="ph-logo ph-logo--on-dark"  alt="" aria-hidden="true" loading="eager" decoding="async">
        </div>
        <span class="ph-card-disconnected-title">Action required: connect your Infynipay account</span>
    </div>
    <div class="card-body ph-card-disconnected-body p-3">
        <p class="mb-3">This plugin is not connected yet. Click the button below to sign in to your Infynipay account and grant access.</p>
        <a href="{$setupUrlEsc}" class="btn btn-primary">
            &#128279;&nbsp;Connect to Infynipay
        </a>
    </div>
</div>
HTML;
    }

    // ── Connected card (logo + gateway status cards) ─────────────────────────

    private function renderConnectedCard(
        string $logoLight,
        string $logoDark,
        string $sandboxAppId,
        string $sandboxToken,
        string $liveAppId,
        string $liveToken
    ): string {
        $logoLightEsc = htmlspecialchars($logoLight, ENT_QUOTES, 'UTF-8');
        $logoDarkEsc  = htmlspecialchars($logoDark,  ENT_QUOTES, 'UTF-8');

        $sandboxState = $this->getGatewayState($sandboxAppId, $sandboxToken);
        $liveState    = $this->getGatewayState($liveAppId, $liveToken);

        $sbCard   = $this->renderGatewayCard('sandbox', $sandboxAppId, $sandboxState);
        $liveCard = $this->renderGatewayCard('live', $liveAppId, $liveState);

        return <<<HTML
<div class="card mb-3 ph-card-connected">
    <div class="card-header ph-card-connected-header d-flex align-items-center gap-3 fw-semibold p-3">
        <div class="ph-logo-wrap">
            <img src="{$logoLightEsc}" class="ph-logo ph-logo--on-light" alt="Infynipay" loading="eager" decoding="async">
            <img src="{$logoDarkEsc}"  class="ph-logo ph-logo--on-dark"  alt="" aria-hidden="true" loading="eager" decoding="async">
        </div>
        <span class="ph-card-connected-title">Infynipay account connected</span>
    </div>
    <div class="card-body p-3">
        <div class="ph-gateway-apps">
            {$sbCard}
            {$liveCard}
        </div>
    </div>
</div>
HTML;
    }

    // ── Gateway state check via API ──────────────────────────────────────────

    private function getGatewayState(string $appId, string $token): string
    {
        if ($appId === '' || $token === '') {
            return 'disconnected';
        }

        try {
            $url      = self::PAYMENT_APP_API_URL . '/api/apps/' . urlencode($appId);
            $response = HttpFactory::getHttp()->get($url, [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ], 6);

            if ($response->code === 200) {
                $data = json_decode($response->body, true);
                if (is_array($data)) {
                    return ($data['isConnectFirstGateway'] ?? false) ? 'ready' : 'incomplete';
                }
            }
        } catch (\Exception $e) {
            // fall through
        }

        return 'incomplete';
    }

    // ── Individual gateway card ──────────────────────────────────────────────

    private function renderGatewayCard(string $mode, string $appId, string $state): string
    {
        $isSandbox = ($mode === 'sandbox');

        // Icon SVG
        $icon = $isSandbox
            ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 2v6.5L4.5 18a2 2 0 0 0 1.7 3h11.6a2 2 0 0 0 1.7-3L14 8.5V2"/><path d="M9 2h6"/><path d="M7 14h10"/></svg>'
            : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/></svg>';

        $title    = $isSandbox ? 'Sandbox' : 'Live';
        $subtitle = $isSandbox
            ? 'Test environment for trial transactions'
            : 'Production environment for real payments';

        $statusLabel = match ($state) {
            'ready'      => 'Gateway connected &amp; ready',
            'incomplete' => 'Setup incomplete — add a gateway',
            default      => 'Not connected',
        };

        $btnText = match ($state) {
            'ready'      => 'Manage Gateways',
            'incomplete' => 'Complete Setup',
            default      => 'Connect to Infynipay',
        };

        if ($state !== 'disconnected' && $appId !== '') {
            $btnUrl   = htmlspecialchars(self::PANEL_URL . '/' . urlencode($appId) . '/gateways', ENT_QUOTES, 'UTF-8');
            $btnTarget = ' target="_blank" rel="noopener noreferrer"';
        } else {
            $btnUrl   = htmlspecialchars($this->buildSetupUrl(), ENT_QUOTES, 'UTF-8');
            $btnTarget = '';
        }

        $btnClass = match ($state) {
            'ready'      => 'btn btn-sm btn-primary ph-gateway-card__action',
            'incomplete' => 'btn btn-sm btn-warning ph-gateway-card__action',
            default      => 'btn btn-sm btn-outline-secondary ph-gateway-card__action',
        };

        return <<<HTML
<div class="ph-gateway-card ph-gateway-card--{$mode} ph-gateway-card--{$state} ph-is-loading">
    <div class="ph-gateway-card__accent"></div>
    <div class="ph-gateway-card__body">
        <div class="ph-gateway-card__head">
            <span class="ph-gateway-card__icon">{$icon}</span>
            <div class="ph-gateway-card__title-wrap">
                <div class="ph-gateway-card__title">{$title}</div>
                <div class="ph-gateway-card__subtitle">{$subtitle}</div>
            </div>
        </div>
        <div class="ph-gateway-card__status">
            <span class="ph-gateway-card__dot"></span>
            <span>{$statusLabel}</span>
        </div>
        <a class="{$btnClass}" href="{$btnUrl}"{$btnTarget}>{$btnText}</a>
    </div>
</div>
HTML;
    }
}
