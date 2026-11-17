<?php
/**
 * Infynipay Payment Plugin for VirtueMart
 *
 * VirtueMart vmpayment-group plugin (extends vmPSPlugin). Appears in
 * VirtueMart -> Shop -> Payment Methods and handles the hosted-redirect
 * checkout flow.
 *
 * Redirect approach (plgVmConfirmedOrder):
 *   After storing the plugin-table row, we create the hosted-payment session
 *   via the API and redirect the buyer to the returned hosted page using a
 *   server-side $app->redirect($redirectUrl). The cart is preserved
 *   (_confirmDone/_dataValidated = false + setCartIntoSession) so VirtueMart
 *   does NOT finalise/empty it before the buyer pays. The order is marked
 *   "pending" (status_pending) before the redirect; the authoritative status
 *   change happens on return (plgVmOnPaymentResponseReceived) and via the
 *   webhook (plgVmOnPaymentNotification), both of which re-verify with the API.
 *
 * Webhook/notify approach:
 *   VirtueMart's native task=notify (view=vmplg) maps to
 *   plgVmOnPaymentNotification(). The webhook body (php://input JSON) is
 *   Bearer-token validated against the stored global webhook_secret, the order
 *   is resolved via referenceId, the payment is RE-VERIFIED against the API
 *   (never trust the body), and the order status is updated idempotently with
 *   updateStatusForOneOrder. This replaces the old com_ajax webhook endpoint.
 *
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * @copyright 2024-2026 Infynipay
 */

defined('_JEXEC') or die();

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use InfyniPay\Plugin\Payment\Infynipay\Service\ApiClient;
use InfyniPay\Plugin\Payment\Infynipay\Helper\PaymentHelper;

class plgVmPaymentInfynipay extends vmPSPlugin
{
    const PANEL_URL           = 'https://console.infynipay.com';
    const PAYMENT_APP_API_URL = 'https://appapi.infynipay.com';
    const PAYMENT_API_URL     = 'https://api.infynipay.com';

    /** @var array|null Cached active credentials (from global #__extensions params) */
    private $activeCredentials = null;

    /** @var array|null Per-request cache of the checkout-methods API response */
    private $checkoutMethodsCache = null;

    /** @var bool Whether renderPluginName should append the provider icon strip */
    private $renderProviderIcons = false;

    function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable   = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey  = 'id';
        $this->_tableId    = 'id';

        $varsToPush = $this->getVarsToPush();
        $this->addVarsToPushCore($varsToPush, 1);
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    // =========================================================================
    // Plugin table
    // =========================================================================

    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Infynipay Table');
    }

    function getTableSQLFields()
    {
        $SQLfields = array(
            'id'                          => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'         => 'int(1) UNSIGNED',
            'order_number'                => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name'                => 'varchar(5000)',
            'payment_order_total'         => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency'            => 'char(3)',
            'email_currency'              => 'char(3)',
            'cost_per_transaction'        => 'decimal(10,2)',
            'cost_min_transaction'        => 'decimal(10,2)',
            'cost_percent_total'          => 'decimal(10,2)',
            'tax_id'                      => 'smallint(1)',
            'infynipay_payment_id'      => 'varchar(64)',
            'infynipay_transaction_id'  => 'varchar(128)',
            'infynipay_payment_state'   => 'varchar(32)',
            'infynipay_fullresponse'    => 'text',
        );

        return $SQLfields;
    }

    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        if ($jplugin_id != $this->_jid) {
            return false;
        }
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    // =========================================================================
    // Boilerplate triggers (delegate to base) — standard.php pattern
    // =========================================================================

    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        return $this->OnSelectCheck($cart);
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected, &$htmlIn)
    {
        // Render the provider icon strip inside the branded card only while building
        // the checkout payment-method list (not when the name is stored at confirm time).
        $this->renderProviderIcons = true;
        $result = $this->displayListFE($cart, $selected, $htmlIn);
        $this->renderProviderIcons = false;

        return $result;
    }

    public function plgVmOnSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->getPaymentCurrency($method);

        $paymentCurrencyId = $method->payment_currency;
        return;
    }

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices, &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    function plgVmgetEmailCurrency($virtuemart_paymentmethod_id, $virtuemart_order_id, &$emailCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        if (empty($method->email_currency)) {
            // nothing
        } else if ($method->email_currency == 'vendor') {
            $vendor_model = VmModel::getModel('vendor');
            $vendor       = $vendor_model->getVendor($method->virtuemart_vendor_id);
            $emailCurrencyId = $vendor->vendor_currency;
        } else if ($method->email_currency == 'payment') {
            $emailCurrencyId = $this->getPaymentCurrency($method);
        }
    }

    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        return $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    function plgVmOnShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    // -------------------------------------------------------------------------
    // Currency helpers (copied from standard.php — base signature differs)
    // -------------------------------------------------------------------------

    static function getPaymentCurrency(&$method, $selectedUserCurrency = false)
    {
        if (empty($method->payment_currency)) {
            $vendor_model = VmModel::getModel('vendor');
            $vendor       = $vendor_model->getVendor($method->virtuemart_vendor_id);
            $method->payment_currency = $vendor->vendor_currency;
            return $method->payment_currency;
        } else {

            $vendor_model      = VmModel::getModel('vendor');
            $vendor_currencies = $vendor_model->getVendorAndAcceptedCurrencies($method->virtuemart_vendor_id);

            if (!$selectedUserCurrency) {
                if ($method->payment_currency == -1) {
                    $mainframe = Factory::getApplication();
                    $selectedUserCurrency = $mainframe->getUserStateFromRequest("virtuemart_currency_id", 'virtuemart_currency_id', vRequest::getInt('virtuemart_currency_id', $vendor_currencies['vendor_currency']));
                } else {
                    $selectedUserCurrency = $method->payment_currency;
                }
            }

            $vendor_currencies['all_currencies'] = explode(',', $vendor_currencies['all_currencies']);
            if (in_array($selectedUserCurrency, $vendor_currencies['all_currencies'])) {
                $method->payment_currency = $selectedUserCurrency;
            } else {
                $method->payment_currency = $vendor_currencies['vendor_currency'];
            }

            return $method->payment_currency;
        }
    }

    function getEmailCurrency(&$method)
    {
        if (empty($method->email_currency) or $method->email_currency == 'vendor') {
            $vendor_model = VmModel::getModel('vendor');
            $vendor       = $vendor_model->getVendor($method->virtuemart_vendor_id);
            return $vendor->vendor_currency;
        } else if ($method->email_currency == 'payment') {
            return $this->getPaymentCurrency($method);
        }
        return '';
    }

    /**
     * Branded payment-method row for the checkout selection list.
     * The base renderer prints only the bare element name ("infynipay"); this
     * shows the Infynipay logo + a friendly title + a short description, using
     * inline styles so it renders without a frontend stylesheet. The merchant can
     * still override the title/description via the method's Payment Name / Description.
     */
    protected function renderPluginName($plugin)
    {
        // Idempotency: VirtueMart assigns this result back to $method->payment_name,
        // so if it is rendered again, return the already-branded markup unchanged
        // instead of nesting it.
        if (isset($plugin->payment_name) && strpos((string) $plugin->payment_name, 'ph-pay-method') !== false) {
            return $plugin->payment_name;
        }

        $logo    = Uri::root(true) . '/media/plg_vmpayment_infynipay/images/logos/infynipay-logo-light.png';
        $logoEsc = htmlspecialchars($logo, ENT_QUOTES, 'UTF-8');

        $configuredName = isset($plugin->payment_name) ? trim((string) $plugin->payment_name) : '';
        $title = ($configuredName !== '' && strtolower($configuredName) !== 'infynipay')
            ? $configuredName
            : 'Infynipay';
        $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        $configuredDesc = isset($plugin->payment_desc) ? trim((string) $plugin->payment_desc) : '';
        $desc = $configuredDesc !== ''
            ? $configuredDesc
            : "You'll be redirected to Infynipay to complete your payment.";
        $descEsc = htmlspecialchars($desc, ENT_QUOTES, 'UTF-8');

        // Live provider icons (credit card + connected providers) — only in the
        // frontend checkout list, never when the name is stored at confirm time.
        $iconsHtml = ($this->renderProviderIcons && !$this->needsSetup())
            ? $this->renderCheckoutMethodIcons()
            : '';

        // Sandbox badge — shown only when the active environment is sandbox; never in live.
        $sandboxBadge = '';
        if (!$this->needsSetup() && !empty($this->getActiveCredentials()['sandbox_flag'])) {
            $sandboxBadge = '<span style="display:inline-flex;align-items:center;gap:4px;margin-left:8px;'
                . 'padding:2px 8px;border-radius:6px;background:rgba(251,191,36,.15);'
                . 'border:1px solid rgba(251,191,36,.4);color:#b45309;font-size:10px;font-weight:700;'
                . 'letter-spacing:.04em;text-transform:uppercase;vertical-align:middle">'
                . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
                . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:11px;height:11px;flex-shrink:0">'
                . '<path d="M12 9v4"/>'
                . '<path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214'
                . 'a1.914 1.914 0 0 0 1.636-2.87L13.637 3.59a1.914 1.914 0 0 0-3.274 0z"/>'
                . '<path d="M12 17h.01"/></svg>Sandbox</span>';
        }

        return '<span class="vmpayment_name ph-pay-method" '
            . 'style="position:relative;display:block;width:100%;'
            . 'box-sizing:border-box;text-align:left;line-height:1.35">'
            // Infynipay logo — pinned to the top-right of the card.
            . '<img src="' . $logoEsc . '" alt="Infynipay" '
            . 'style="position:absolute;top:0;right:0;height:22px;width:auto;max-width:110px;'
            . 'object-fit:contain;opacity:.85">'
            // Title + description: pad right so the text clears the absolute logo.
            . '<span style="display:block;padding-right:120px">'
            . '<span style="display:block;font-size:11px;font-weight:500;color:#94a3b8">' . $titleEsc . $sandboxBadge . '</span>'
            . '<span style="display:block;font-size:11px;color:#94a3b8">' . $descEsc . '</span>'
            . '</span>'
            // Provider icons: full card width (NOT constrained by the logo padding).
            . $iconsHtml
            . '</span>';
    }

    /**
     * Build the provider icon strip from the checkout-methods API response.
     *
     * Rules (per INTEGRATION_GUIDE §8.6 / §8.4):
     *   - "CreditCard"          → render a single generic credit-card SVG tile.
     *   - "ProviderHostedPage"  → render one <img> per provider (Stripe, PayPal, …)
     *                             using iconUri1 (colour) or iconUri2 (mono) fallback.
     *
     * SVG icons are served by Azure Blob Storage which can return the wrong MIME
     * type (application/octet-stream); a tiny one-time JS helper re-wraps them as
     * image/svg+xml blobs so the <img> tags render. Icons are non-critical: any
     * failure renders nothing rather than blocking checkout.
     */
    private function renderCheckoutMethodIcons(): string
    {
        $methods = $this->getCheckoutMethods();
        if (empty($methods)) {
            return '';
        }

        $tiles = '';
        foreach ($methods as $group) {
            if (!is_array($group)) {
                continue;
            }
            $checkoutMethod = (string) ($group['checkoutMethod'] ?? '');
            $items          = $group['paymentCheckoutMethodItems'] ?? [];

            if ($checkoutMethod === 'CreditCard') {
                $tiles .= '<span class="ph-pm-cc" title="Credit/Debit Card">'
                    . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="18"'
                    . ' fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">'
                    . '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>'
                    . '<line x1="1" y1="10" x2="23" y2="10"/>'
                    . '<line x1="5" y1="15" x2="9" y2="15"/>'
                    . '</svg></span>';
            } elseif ($checkoutMethod === 'ProviderHostedPage' && is_array($items)) {
                foreach ($items as $item) {
                    $provider = $item['paymentProfile']['paymentProvider'] ?? null;
                    if (!is_array($provider)) {
                        continue;
                    }
                    $iconUrl = (string) ($provider['iconUri1'] ?? $provider['iconUri2'] ?? '');
                    $name    = (string) ($provider['provider'] ?? '');

                    // XSS guard: only allow https:// or data:image/ icon URLs.
                    if ($iconUrl === ''
                        || (strpos($iconUrl, 'https://') !== 0 && strpos($iconUrl, 'data:image/') !== 0)) {
                        continue;
                    }

                    $urlEsc  = htmlspecialchars($iconUrl, ENT_QUOTES, 'UTF-8');
                    $nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                    $tiles .= '<img src="' . $urlEsc . '" alt="' . $nameEsc . '" title="' . $nameEsc . '" '
                        . 'class="ph-pm-ico" loading="lazy" decoding="async">';
                }
            }
        }

        if ($tiles === '') {
            return '';
        }

        $this->registerCheckoutIconAssets();

        return '<span class="ph-pm-icons">' . $tiles . '</span>';
    }

    /**
     * Fetch (and per-request cache) the checkout-methods API response.
     */
    private function getCheckoutMethods(): array
    {
        if ($this->checkoutMethodsCache !== null) {
            return $this->checkoutMethodsCache;
        }
        if ($this->needsSetup()) {
            return $this->checkoutMethodsCache = [];
        }

        $methods = $this->api()->fetchCheckoutMethods();
        return $this->checkoutMethodsCache = is_array($methods) ? $methods : [];
    }

    /**
     * Register the icon-strip CSS and the Azure-SVG MIME fix JS once per page.
     */
    private function registerCheckoutIconAssets(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        $doc = Factory::getDocument();

        $doc->addStyleDeclaration(
            // Full-width row; tiles wrap only when they truly cannot fit.
            '.ph-pm-icons{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:10px;width:100%}'
            // Shared tile chrome. Fixed tile height + box-sizing so the inner logo
            // area is identical for every provider.
            . '.ph-pm-icons .ph-pm-ico,.ph-pm-icons .ph-pm-cc{display:inline-flex;align-items:center;'
            . 'justify-content:center;height:34px;box-sizing:border-box;background:#fff;border:1.5px solid #e2e8f0;'
            . 'border-radius:9px;box-shadow:0 1px 2px rgba(15,23,42,.06)}'
            // Logos: every icon renders at the SAME height (34px tile − 12px padding = 22px);
            // width follows the aspect ratio. This makes Stripe, Authorize.net, etc. visually equal.
            . '.ph-pm-icons img.ph-pm-ico{width:auto;max-width:96px;padding:6px 9px;object-fit:contain}'
            . '.ph-pm-icons .ph-pm-cc{padding:0 10px;color:#1e293b}'
            . '.ph-pm-icons .ph-pm-cc svg{height:22px;width:auto}'
            // Mobile: same idea, scaled down (30px tile − 10px padding = 20px logo height).
            . '@media (max-width:480px){'
            . '.ph-pm-icons{gap:6px}'
            . '.ph-pm-icons .ph-pm-ico,.ph-pm-icons .ph-pm-cc{height:30px;border-radius:8px}'
            . '.ph-pm-icons img.ph-pm-ico{max-width:74px;padding:5px 7px}'
            . '.ph-pm-icons .ph-pm-cc{padding:0 8px}'
            . '.ph-pm-icons .ph-pm-cc svg{height:20px}'
            . '}'
        );

        // Azure Blob serves .svg as application/octet-stream; browsers refuse to
        // render those via <img>. Re-fetch each svg and re-wrap as image/svg+xml.
        $doc->addScriptDeclaration(
            '(function(){'
            . 'function fix(){'
            .   'if(!window.fetch||!window.Blob||!URL.createObjectURL){return;}'
            .   'document.querySelectorAll(".ph-pm-icons img.ph-pm-ico").forEach(function(img){'
            .     'var src=img.getAttribute("src")||"";'
            .     'if(!src||src.slice(0,5)==="blob:"||src.slice(0,5)==="data:"){return;}'
            .     'if(src.split("?")[0].toLowerCase().slice(-4)!==".svg"){return;}'
            .     'fetch(src,{mode:"cors",credentials:"omit"}).then(function(r){'
            .       'var ct=(r.headers.get("content-type")||"").toLowerCase();'
            .       'if(ct.indexOf("svg")!==-1){return null;}'
            .       'return r.text();'
            .     '}).then(function(text){'
            .       'if(!text){return;}'
            .       'var url=URL.createObjectURL(new Blob([text],{type:"image/svg+xml"}));'
            .       'if(img.getAttribute("src")===src){img.src=url;}'
            .     '}).catch(function(){});'
            .   '});'
            . '}'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",fix);}else{fix();}'
            . '})();'
        );
    }

    // =========================================================================
    // Confirmed order — REDIRECT-TO-HOSTED-PAGE
    // =========================================================================

    function plgVmConfirmedOrder($cart, $order)
    {
        if (!($this->_currentMethod = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
            return false;
        }

        vmLanguage::loadJLang('com_virtuemart_orders', true);
        vmLanguage::loadJLang('com_virtuemart', true);

        $orderBT      = $order['details']['BT'];
        $orderId      = (int) $orderBT->virtuemart_order_id;
        $orderNumber  = (string) $orderBT->order_number;
        $methodId     = (int) $orderBT->virtuemart_paymentmethod_id;

        if ($orderId < 1 || $this->needsSetup()) {
            vmInfo(vmText::_('VMPAYMENT_INFYNIPAY_NOT_CONFIGURED'));
            return false;
        }

        // Currency resolution (for plugin-table storage + email).
        $this->getPaymentCurrency($this->_currentMethod, $orderBT->payment_currency_id);
        $currency_code_3 = shopFunctions::getCurrencyByID($this->_currentMethod->payment_currency, 'currency_code_3');
        $email_currency  = $this->getEmailCurrency($this->_currentMethod);

        $payment_name = $this->renderPluginName($this->_currentMethod, $order);

        // ── Build VM "view=vmplg" URLs ──────────────────────────────────────
        $Itemid    = (int) vRequest::getInt('Itemid', 0);
        $siteBase  = Uri::root();

        $returnUrl = $siteBase . 'index.php?option=com_virtuemart&view=vmplg&task=pluginresponsereceived'
            . '&on=' . urlencode($orderNumber) . '&pm=' . $methodId . '&Itemid=' . $Itemid;

        $notifyUrl = $siteBase . 'index.php?option=com_virtuemart&view=vmplg&task=notify&tmpl=component'
            . '&on=' . urlencode($orderNumber) . '&pm=' . $methodId;

        $cancelUrl = $siteBase . 'index.php?option=com_virtuemart&view=vmplg&task=pluginUserPaymentCancel'
            . '&on=' . urlencode($orderNumber) . '&pm=' . $methodId . '&Itemid=' . $Itemid;

        // Log the webhook URL we hand to Infynipay — if it is http:// (behind an
        // HTTPS tunnel that doesn't forward the scheme) the gateway's webhook POST
        // may fail to be delivered, which is the first thing to check if no webhook
        // ever arrives. Enable Joomla's "Force HTTPS = Entire Site" to fix the scheme.
        $this->log(
            'plgVmConfirmedOrder: order ' . $orderId . ' (' . $orderNumber . ') | webhookUrl=' . $notifyUrl
                . ' | returnUrl=' . $returnUrl,
            'info',
            'pay.entry'
        );

        // ── Build the hosted-payment payload ────────────────────────────────
        $vmOrder = $this->loadVmOrder($orderId);
        if (!$vmOrder) {
            $this->log('plgVmConfirmedOrder: could not load order ' . $orderId, 'error', 'pay.load');
            vmError(vmText::_('VMPAYMENT_INFYNIPAY_PAYMENT_ERROR_CREATE_FAILED'));
            return false;
        }

        $payload = $this->buildHostedPaymentPayload($vmOrder, $returnUrl, $notifyUrl, $cancelUrl);
        if ($payload === null) {
            $this->log('plgVmConfirmedOrder: order amount is zero, order ' . $orderId, 'warning', 'pay.amount');
            vmError(vmText::_('VMPAYMENT_INFYNIPAY_PAYMENT_ERROR_CREATE_FAILED'));
            return false;
        }

        $redirectUrl = $this->api()->createHostedPayment($payload);

        if ($redirectUrl === 'APP_INACTIVE') {
            $this->log('plgVmConfirmedOrder: app inactive, order ' . $orderId, 'warning', 'pay.create');
            vmError(vmText::_('VMPAYMENT_INFYNIPAY_SERVICE_UNAVAILABLE_MSG'));
            return false;
        }

        if ($redirectUrl === 'ALREADY_PAID') {
            // Already paid: skip redirect, send the buyer to the return handler.
            $redirectUrl = $returnUrl;
        }

        if ($redirectUrl === null || $redirectUrl === '') {
            $this->log('plgVmConfirmedOrder: createHostedPayment returned empty, order ' . $orderId, 'error', 'pay.create');
            vmError(vmText::_('VMPAYMENT_INFYNIPAY_PAYMENT_ERROR_CREATE_FAILED'));
            return false;
        }

        // ── Re-verify to capture the payment id for the plugin-table row ────
        $apiPayment = $this->api()->getPaymentByReference((string) $orderId);
        $phPaymentId  = is_array($apiPayment) ? (string) ($apiPayment['paymentId'] ?? '') : '';
        $paymentState = is_array($apiPayment) ? strtolower((string) ($apiPayment['paymentState'] ?? 'created')) : 'created';

        // ── Store the plugin-table row ──────────────────────────────────────
        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($orderBT->order_total, $this->_currentMethod->payment_currency);

        $dbValues = array();
        $dbValues['order_number']                = $orderNumber;
        $dbValues['payment_name']                = $payment_name;
        $dbValues['virtuemart_paymentmethod_id'] = $methodId;
        $dbValues['cost_per_transaction']        = $this->_currentMethod->cost_per_transaction;
        $dbValues['cost_min_transaction']        = isset($this->_currentMethod->cost_min_transaction) ? $this->_currentMethod->cost_min_transaction : 0;
        $dbValues['cost_percent_total']          = $this->_currentMethod->cost_percent_total;
        $dbValues['payment_currency']            = $currency_code_3;
        $dbValues['email_currency']              = $email_currency;
        $dbValues['payment_order_total']         = $totalInPaymentCurrency['value'];
        $dbValues['tax_id']                      = $this->_currentMethod->tax_id;
        $dbValues['infynipay_payment_id']      = $phPaymentId;
        $dbValues['infynipay_payment_state']   = $paymentState !== '' ? $paymentState : 'created';
        $dbValues['infynipay_fullresponse']    = is_array($apiPayment) ? json_encode($apiPayment) : '';
        $this->storePSPluginInternalData($dbValues);

        // ── Mark the order pending ──────────────────────────────────────────
        $pendingStatus = $this->getStatusCode('pending', 'P');
        $modelOrder    = VmModel::getModel('orders');
        $orderUpdate = array(
            'order_status'      => $pendingStatus,
            'customer_notified' => 0,
            'comments'          => 'Awaiting Infynipay payment.',
        );
        $modelOrder->updateStatusForOneOrder($orderId, $orderUpdate, false);

        // ── Preserve the cart and redirect the buyer to the hosted page ─────
        // 2 = don't delete the cart, don't send email and don't redirect (VM-side).
        $cart->_confirmDone   = false;
        $cart->_dataValidated = false;
        $cart->setCartIntoSession();

        $this->log('plgVmConfirmedOrder: redirecting order ' . $orderId . ' to hosted page', 'info', 'pay.redirect');

        $app = Factory::getApplication();
        $app->redirect($redirectUrl);

        return true;
    }

    // =========================================================================
    // Payment response received (buyer returns from hosted page)
    // =========================================================================

    function plgVmOnPaymentResponseReceived(&$html)
    {
        vmLanguage::loadJLang('com_virtuemart_orders', true);
        vmLanguage::loadJLang('com_virtuemart', true);

        $paymentmethod_id = vRequest::getInt('pm', 0);
        $order_number     = vRequest::getString('on', 0);

        $this->log("Return from hosted page | pm={$paymentmethod_id} on={$order_number}", 'info', 'return.entry');

        if (!($this->_currentMethod = $this->getVmPluginMethod($paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
            return null;
        }

        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
            $this->log("Return: could not resolve order from on={$order_number}", 'warning', 'return.entry');
            return null;
        }
        $virtuemart_order_id = (int) $virtuemart_order_id;

        // Re-verify with the API (authoritative).
        $apiPayment   = $this->needsSetup() ? null : $this->api()->getPaymentByReference((string) $virtuemart_order_id);
        $paymentState = is_array($apiPayment) ? strtolower((string) ($apiPayment['paymentState'] ?? '')) : '';
        $phPaymentId  = is_array($apiPayment) ? (string) ($apiPayment['paymentId'] ?? '') : '';
        $redirectUrl  = is_array($apiPayment) ? (string) ($apiPayment['redirectUrl'] ?? '') : '';
        $transactionId = is_array($apiPayment) ? (string) ($apiPayment['transactionId'] ?? '') : '';

        if ($paymentState !== '') {
            $this->updateOrderStatusFromState($virtuemart_order_id, $order_number, $paymentmethod_id, $paymentState, $phPaymentId, $transactionId, $apiPayment, 'ReturnReceived');
        }

        // Build the result card.
        $orderModel = VmModel::getModel('orders');
        $order      = $orderModel->getOrder($virtuemart_order_id);
        $creds      = $this->getActiveCredentials();
        $isSandbox  = (bool) $creds['sandbox_flag'];

        $amount       = isset($order['details']['BT']->order_total) ? (float) $order['details']['BT']->order_total : 0.0;
        $currencyCode = $this->resolveCurrencyCode($order);

        $html = $this->renderResultCard($paymentState, $virtuemart_order_id, $amount, $currencyCode, $redirectUrl, $isSandbox);

        // Empty the cart.
        $cart = VirtueMartCart::getCart();
        $cart->emptyCart();

        return true;
    }

    // =========================================================================
    // Payment notification (silent webhook) — task=notify
    // =========================================================================

    function plgVmOnPaymentNotification()
    {
        // Resolve the method from the URL (pm) so we can guard correctly.
        $paymentmethod_id = vRequest::getInt('pm', 0);
        $order_number     = vRequest::getString('on', '');

        // Entry log — proves the webhook actually reached us. If you trigger a refund
        // and DON'T see this line, the webhook never arrived (delivery/URL/routing),
        // not a mapping problem.
        $this->log(sprintf(
            'Webhook received | reqMethod=%s pm=%d on=%s remoteIp=%s hasAuth=%d',
            $_SERVER['REQUEST_METHOD'] ?? '?',
            $paymentmethod_id,
            $order_number !== '' ? $order_number : '(none)',
            $_SERVER['REMOTE_ADDR'] ?? '?',
            (isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) ? 1 : 0
        ), 'info', 'webhook.entry');

        if ($paymentmethod_id) {
            if (!($this->_currentMethod = $this->getVmPluginMethod($paymentmethod_id))) {
                $this->log("Webhook: pm={$paymentmethod_id} did not resolve to a method — ignoring", 'warning', 'webhook.entry');
                return null;
            }
            if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
                $this->log("Webhook: pm={$paymentmethod_id} is not a Infynipay method — ignoring", 'info', 'webhook.entry');
                return null;
            }
        }

        // ── Bearer-token authentication against the stored global secret ────
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if ($authHeader === '' && function_exists('getallheaders')) {
            $headers    = getallheaders();
            $authHeader = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }
        $receivedToken = '';
        if (stripos($authHeader, 'Bearer ') === 0) {
            $receivedToken = substr($authHeader, 7);
        }

        $creds        = $this->getActiveCredentials();
        $storedSecret = (string) ($creds['webhook_secret'] ?? '');

        if (!PaymentHelper::validateWebhookToken($storedSecret, $receivedToken)) {
            http_response_code(401);
            $this->log('Webhook: unauthorized - token mismatch', 'warning', 'webhook.auth');
            echo json_encode(['received' => false, 'error' => 'unauthorized']);
            return true;
        }

        // ── Parse the raw JSON body ─────────────────────────────────────────
        $rawBody = (string) file_get_contents('php://input');
        $data    = json_decode($rawBody, true);

        if (!is_array($data)) {
            http_response_code(400);
            $this->log('Webhook: invalid JSON body', 'warning', 'webhook.body');
            echo json_encode(['received' => false, 'error' => 'invalid_json']);
            return true;
        }

        // Handle both {"payment":{...}} wrapped and bare {...} bodies.
        $payload       = isset($data['payment']) && is_array($data['payment']) ? $data['payment'] : $data;
        $refOrderId    = (int)    ($payload['referenceId'] ?? $payload['order_id'] ?? 0);
        $phPaymentId   = (string) ($payload['paymentId']   ?? '');
        $transactionId = (string) ($payload['transactionId'] ?? $payload['id'] ?? '');

        // What did the webhook body actually say? (event type + state are the keys
        // that tell us whether Infynipay reports the refund here.)
        $bodyState  = strtolower((string) ($payload['paymentState'] ?? $payload['status'] ?? ''));
        $bodyEvent  = (string) ($data['eventType'] ?? $data['type'] ?? $data['event'] ?? $payload['eventType'] ?? $payload['type'] ?? '');
        $this->log(sprintf(
            'Webhook body | event=%s bodyState=%s referenceId=%d paymentId=%s txId=%s bodyLen=%d',
            $bodyEvent !== '' ? $bodyEvent : '(none)',
            $bodyState !== '' ? $bodyState : '(none)',
            $refOrderId,
            $phPaymentId !== '' ? PaymentHelper::mask($phPaymentId) : '(none)',
            $transactionId !== '' ? $transactionId : '(none)',
            strlen($rawBody)
        ), 'info', 'webhook.body');

        if ($refOrderId < 1) {
            // Try resolving via the order_number on the URL.
            if ($order_number !== '') {
                $refOrderId = (int) VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
            }
        }

        if ($refOrderId < 1) {
            http_response_code(400);
            $this->log('Webhook: missing/unknown orderId', 'warning', 'webhook.resolve');
            echo json_encode(['received' => false, 'error' => 'missing_order']);
            return true;
        }

        // Resolve the method from the stored plugin row if not provided in the URL.
        if (empty($this->_currentMethod)) {
            $mData = $this->getDataByOrderId($refOrderId);
            if ($mData && !empty($mData->virtuemart_paymentmethod_id)) {
                $paymentmethod_id   = (int) $mData->virtuemart_paymentmethod_id;
                $this->_currentMethod = $this->getVmPluginMethod($paymentmethod_id);
            }
        }
        if (empty($this->_currentMethod) || !$this->selectedThisElement($this->_currentMethod->payment_element)) {
            http_response_code(404);
            $this->log('Webhook: could not resolve Infynipay method for order ' . $refOrderId, 'warning', 'webhook.resolve');
            echo json_encode(['received' => false, 'error' => 'method_not_found']);
            return true;
        }

        if (empty($order_number)) {
            $om = VmModel::getModel('orders');
            $order_number = (string) $om->getOrderNumber($refOrderId);
        }

        // ── Re-verify payment state with the API — never trust the body ─────
        $apiPayment = $this->api()->getPaymentByReference((string) $refOrderId);
        if (!is_array($apiPayment)) {
            http_response_code(502);
            $this->log("Webhook: API verification failed for orderId={$refOrderId}", 'error', 'webhook.verify');
            echo json_encode(['received' => false, 'error' => 'verification_failed']);
            return true;
        }

        $apiState = strtolower((string) ($apiPayment['paymentState'] ?? ''));
        if ($phPaymentId === '') {
            $phPaymentId = (string) ($apiPayment['paymentId'] ?? '');
        }

        // Normally we trust the API re-verification. But some gateways keep the
        // payment as 'captured' after a refund and report the refund only in the
        // (Bearer-authenticated) webhook body. So if the webhook reports a terminal
        // NEGATIVE state the API hasn't reflected yet, trust the webhook body.
        $negative       = ['refunded', 'failed', 'disputed', 'cancelled', 'canceled'];
        $effectiveState = $apiState;
        if (in_array($bodyState, $negative, true) && !in_array($apiState, $negative, true)) {
            $effectiveState = $bodyState;
            $this->log(
                "Webhook: API state='{$apiState}' but authenticated body state='{$bodyState}' — trusting webhook body",
                'warning',
                'webhook.verify'
            );
        } else {
            $this->log(
                "Webhook: re-verified | orderId={$refOrderId} apiState='{$apiState}' bodyState='{$bodyState}'",
                'info',
                'webhook.verify'
            );
        }

        $this->updateOrderStatusFromState($refOrderId, $order_number, (int) $this->_currentMethod->virtuemart_paymentmethod_id, $effectiveState, $phPaymentId, $transactionId, $apiPayment, 'Webhook');

        http_response_code(200);
        echo json_encode(['received' => true]);
        return true;
    }

    // =========================================================================
    // User payment cancel
    // =========================================================================

    function plgVmOnUserPaymentCancel()
    {
        $order_number     = vRequest::getString('on', '');
        $paymentmethod_id = vRequest::getInt('pm', 0);

        if (empty($order_number) || empty($paymentmethod_id) || !$this->selectedThisByMethodId($paymentmethod_id)) {
            return null;
        }
        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
            return null;
        }

        vmLanguage::loadJLang('com_virtuemart');
        VmInfo(vmText::_('VMPAYMENT_INFYNIPAY_PAYMENT_CANCELLED'));
        $this->log('Payment cancelled by buyer, order ' . $virtuemart_order_id, 'info', 'cancel');

        $this->handlePaymentUserCancel((int) $virtuemart_order_id);
        return true;
    }

    // =========================================================================
    // Back-office order display ("View in Infynipay" card)
    // =========================================================================

    public function plgVmOnShowOrderBEPayment($virtuemart_order_id, $method_id)
    {
        if (!$this->selectedThisByMethodId($method_id)) {
            return null; // Another method was selected, do nothing
        }
        if (!($this->_currentMethod = $this->getVmPluginMethod($method_id))) {
            return false;
        }
        if ($this->needsSetup()) {
            return '';
        }

        // The order_number is needed for the API reference (which is the order id).
        $om          = VmModel::getModel('orders');
        $orderNumber = $om->getOrderNumber($virtuemart_order_id);

        // Resolve the Infynipay payment id (server-side; back-office, trusted).
        $apiPayment = $this->api()->getPaymentByReference((string) $virtuemart_order_id);
        $paymentId  = is_array($apiPayment) ? (string) ($apiPayment['paymentId'] ?? '') : '';

        if ($paymentId === '') {
            // Fall back to the stored row.
            $mData = $this->getDataByOrderId($virtuemart_order_id);
            if ($mData && !empty($mData->infynipay_payment_id)) {
                $paymentId = (string) $mData->infynipay_payment_id;
            }
        }

        if ($paymentId === '') {
            return '';
        }

        $creds      = $this->getActiveCredentials();
        $consoleUrl = self::PANEL_URL . '/' . urlencode($creds['app_id'])
            . '/payments/detail?appId=' . urlencode($creds['app_id'])
            . '&paymentId=' . urlencode($paymentId);

        $logoBase   = Uri::root(true) . '/media/plg_vmpayment_infynipay/images/logos/';
        $logoLight  = htmlspecialchars($logoBase . 'infynipay-logo-light.png', ENT_QUOTES, 'UTF-8');
        $logoDark   = htmlspecialchars($logoBase . 'infynipay-logo-dark.png', ENT_QUOTES, 'UTF-8');
        $paymentEsc = htmlspecialchars($paymentId, ENT_QUOTES, 'UTF-8');
        $consoleEsc = htmlspecialchars($consoleUrl, ENT_QUOTES, 'UTF-8');

        $doc = Factory::getDocument();
        $doc->addStyleDeclaration(
            '.ph-od-logo{height:52px;width:auto;max-width:230px;object-fit:contain;vertical-align:middle}'
            . '.ph-od-logo--dk{display:none}'
            . '@media(prefers-color-scheme:dark){.ph-od-logo--lt{display:none}.ph-od-logo--dk{display:inline-block}}'
            . '[data-bs-theme="dark"] .ph-od-logo--lt,.joomla-color-scheme-dark .ph-od-logo--lt{display:none}'
            . '[data-bs-theme="dark"] .ph-od-logo--dk,.joomla-color-scheme-dark .ph-od-logo--dk{display:inline-block}'
        );

        return '<div id="ph-od-card" '
            . 'class="card p-3 d-flex flex-row justify-content-between align-items-center gap-3">'
            . '<div class="d-flex align-items-center gap-3">'
            . '<div><img src="' . $logoLight . '" alt="Infynipay" class="ph-od-logo ph-od-logo--lt">'
            . '<img src="' . $logoDark . '" alt="" aria-hidden="true" class="ph-od-logo ph-od-logo--dk"></div>'
            . '<div><div class="fw-bold">Infynipay</div>'
            . '<div class="text-muted small">Payment ID: <strong>' . $paymentEsc . '</strong></div></div></div>'
            . '<a href="' . $consoleEsc . '" target="_blank" rel="noopener noreferrer" '
            . 'class="btn btn-sm btn-outline-primary">' . vmText::_('VMPAYMENT_INFYNIPAY_VIEW_IN_CONSOLE') . '</a>'
            . '</div>';
    }

    // =========================================================================
    // Conditions (publish up/down support)
    // =========================================================================

    protected function checkConditions($cart, $method, $cart_prices)
    {
        if (!empty($method->publishup)) {
            $nowDate    = Factory::getDate();
            $publish_up = Factory::getDate($method->publishup);
            if ($publish_up->toUnix() > $nowDate->toUnix()) {
                return false;
            }
        }
        if (!empty($method->publishdown)) {
            $nowDate      = Factory::getDate();
            $publish_down = Factory::getDate($method->publishdown);
            if ($publish_down->toUnix() <= $nowDate->toUnix()) {
                return false;
            }
        }

        return parent::checkConditions($cart, $method, $cart_prices);
    }

    // =========================================================================
    // Order status update (idempotent, via updateStatusForOneOrder)
    // =========================================================================

    /**
     * Map a Infynipay payment state to the configured VM order-state code and
     * apply it idempotently. Also refreshes the stored plugin-table row.
     */
    private function updateOrderStatusFromState(
        int    $virtuemart_order_id,
        string $order_number,
        int    $paymentmethod_id,
        string $paymentState,
        string $phPaymentId,
        string $transactionId,
        $apiPayment,
        string $trigger
    ): void {
        $paymentState = strtolower(trim($paymentState));

        $newStatus = $this->mapStateToStatus($paymentState);
        $this->log(
            sprintf('%s: mapping state=%s -> status=%s (order %d)', $trigger, $paymentState, $newStatus ?? '(none)', $virtuemart_order_id),
            'info',
            'sync.map'
        );
        if ($newStatus === null) {
            $this->log("{$trigger}: non-terminal/unknown state '{$paymentState}' for order {$virtuemart_order_id}", 'info', 'sync.skip');
            $this->storeGatewayResponse($virtuemart_order_id, $order_number, $paymentmethod_id, $paymentState, $phPaymentId, $transactionId, $apiPayment);
            return;
        }

        $orderModel = VmModel::getModel('orders');
        $order      = $orderModel->getOrder($virtuemart_order_id);

        $current = isset($order['details']['BT']->order_status) ? (string) $order['details']['BT']->order_status : '';

        // Idempotency: skip if already at target status.
        if ($current === $newStatus) {
            $this->log("{$trigger}: already at '{$newStatus}' for order {$virtuemart_order_id} - skipped", 'info', 'sync.skip');
            $this->storeGatewayResponse($virtuemart_order_id, $order_number, $paymentmethod_id, $paymentState, $phPaymentId, $transactionId, $apiPayment);
            return;
        }

        $comment = "Status changed by Infynipay {$trigger}. State: {$paymentState}.";
        if ($transactionId !== '') {
            $comment .= " TxID: {$transactionId}.";
        }
        if ($phPaymentId !== '') {
            $comment .= " PaymentID: {$phPaymentId}.";
        }

        $orderUpdate = array(
            'order_status'      => $newStatus,
            'customer_notified' => 1,
            'comments'          => $comment,
        );
        $orderModel->updateStatusForOneOrder($virtuemart_order_id, $orderUpdate, true);

        $this->storeGatewayResponse($virtuemart_order_id, $order_number, $paymentmethod_id, $paymentState, $phPaymentId, $transactionId, $apiPayment);

        $this->log("{$trigger}: order {$virtuemart_order_id} -> {$newStatus} (state {$paymentState})", 'info', 'sync.update');
    }

    /**
     * Refresh the gateway columns on the stored plugin-table row.
     */
    private function storeGatewayResponse(
        int    $virtuemart_order_id,
        string $order_number,
        int    $paymentmethod_id,
        string $paymentState,
        string $phPaymentId,
        string $transactionId,
        $apiPayment
    ): void {
        $dbValues = array(
            'virtuemart_order_id'         => $virtuemart_order_id,
            'order_number'                => $order_number,
            'virtuemart_paymentmethod_id' => $paymentmethod_id,
            'infynipay_payment_state'   => $paymentState,
        );
        if ($phPaymentId !== '') {
            $dbValues['infynipay_payment_id'] = $phPaymentId;
        }
        if ($transactionId !== '') {
            $dbValues['infynipay_transaction_id'] = $transactionId;
        }
        if (is_array($apiPayment)) {
            $dbValues['infynipay_fullresponse'] = json_encode($apiPayment);
        }
        $this->storePSPluginInternalData($dbValues, $this->_tablepkey, 0);
    }

    /**
     * Map a Infynipay payment state to a configured VM order-state code.
     * Returns null for non-terminal states (no status change).
     */
    private function mapStateToStatus(string $paymentState): ?string
    {
        // NOTE: VirtueMart status CODES are install-specific. In the default scheme:
        //   C=Confirmed  R=Refunded  X=Cancelled  D=Denied  F=COMPLETED  P=Pending
        // so "F" must NOT be used for failed payments (it means Completed).
        switch ($paymentState) {
            case 'captured':
                return $this->getStatusCode('success', 'C');
            case 'refunded':
                return $this->getStatusCode('refunded', 'R');
            case 'failed':
            case 'disputed':
                return $this->getStatusCode('failed', 'X');
            case 'cancelled':
            case 'canceled':
                return $this->getStatusCode('cancelled', 'X');
            default:
                return null;
        }
    }

    /**
     * Read a per-method status code from the current method's vmconfig params.
     */
    private function getStatusCode(string $key, string $default): string
    {
        $field = 'status_' . $key;
        if (!empty($this->_currentMethod) && !empty($this->_currentMethod->{$field})) {
            return (string) $this->_currentMethod->{$field};
        }
        return $default;
    }

    // =========================================================================
    // API factory + credentials (global #__extensions params)
    // =========================================================================

    private function api(?string $appId = null, ?string $token = null): ApiClient
    {
        if ($appId === null || $token === null) {
            $creds = $this->getActiveCredentials();
            $appId = $creds['app_id'];
            $token = $creds['token'];
        }
        return new ApiClient(
            self::PAYMENT_APP_API_URL,
            self::PAYMENT_API_URL,
            (string) $appId,
            (string) $token,
            [$this, 'log']
        );
    }

    /**
     * Read the GLOBAL Joomla plugin params (folder='vmpayment') from #__extensions
     * and resolve the active (sandbox/live) credentials.
     */
    public function getActiveCredentials(): array
    {
        if ($this->activeCredentials !== null) {
            return $this->activeCredentials;
        }

        $config    = $this->loadGlobalParams();
        $isSandbox = (int) ($config['sandbox'] ?? 1) === 1;
        $prefix    = $isSandbox ? 'sandbox_' : 'live_';

        $this->activeCredentials = [
            'mode'           => $isSandbox ? 'sandbox' : 'live',
            'app_id'         => (string) ($config[$prefix . 'app_id'] ?? ''),
            'token'          => (string) ($config[$prefix . 'token'] ?? ''),
            'webhook_secret' => (string) ($config[$prefix . 'webhook_secret'] ?? ''),
            'sandbox_flag'   => $isSandbox,
        ];

        return $this->activeCredentials;
    }

    public function needsSetup(): bool
    {
        $creds = $this->getActiveCredentials();
        return empty($creds['app_id']) || empty($creds['token']);
    }

    /**
     * Read the global plugin params JSON directly from #__extensions
     * (folder changed from 'payment' to 'vmpayment').
     */
    private function loadGlobalParams(): array
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
            $this->log('loadGlobalParams error: ' . $e->getMessage(), 'error', 'creds.read');
            return [];
        }
    }

    // =========================================================================
    // VirtueMart order DB helpers
    // =========================================================================

    private function loadVmOrder(int $orderId): ?object
    {
        try {
            $db    = Factory::getDbo();
            $order = $db->setQuery(
                'SELECT o.*, ui.first_name, ui.last_name, ui.email, ui.phone_1,'
                . ' ui.address_1, ui.zip, ui.city,'
                . ' c.country_2_code AS country_code, c.country_name,'
                . ' cu.currency_code_3 AS currency_code'
                . ' FROM `#__virtuemart_orders` o'
                . ' LEFT JOIN `#__virtuemart_order_userinfos` ui'
                . '   ON ui.virtuemart_order_id = o.virtuemart_order_id AND ui.address_type = \'BT\''
                . ' LEFT JOIN `#__virtuemart_countries` c'
                . '   ON c.virtuemart_country_id = ui.virtuemart_country_id'
                . ' LEFT JOIN `#__virtuemart_currencies` cu'
                . '   ON cu.virtuemart_currency_id = o.user_currency_id'
                . ' WHERE o.`virtuemart_order_id` = ' . (int) $orderId
            )->loadObject();
            return $order ?: null;
        } catch (\Exception $e) {
            $this->log('loadVmOrder error: ' . $e->getMessage(), 'error', 'order.load');
            return null;
        }
    }

    private function getVmCurrencyCode(object $order): string
    {
        if (!empty($order->currency_code)) {
            return (string) $order->currency_code;
        }
        try {
            $db     = Factory::getDbo();
            $currId = (int) ($order->user_currency_id ?? $order->virtuemart_currency_id ?? 0);
            if ($currId > 0) {
                $code = $db->setQuery(
                    'SELECT `currency_code_3` FROM `#__virtuemart_currencies`'
                    . ' WHERE `virtuemart_currency_id` = ' . $currId
                )->loadResult();
                if ($code) {
                    return (string) $code;
                }
            }
        } catch (\Exception $e) {
            // ignore
        }
        return 'USD';
    }

    /**
     * Resolve the currency code (3-letter) from a VM order array.
     */
    private function resolveCurrencyCode(array $order): string
    {
        if (!empty($order['details']['BT']->order_currency)) {
            $code = shopFunctions::getCurrencyByID((int) $order['details']['BT']->order_currency, 'currency_code_3');
            if ($code) {
                return (string) $code;
            }
        }
        if (!empty($order['details']['BT']->user_currency_id)) {
            $code = shopFunctions::getCurrencyByID((int) $order['details']['BT']->user_currency_id, 'currency_code_3');
            if ($code) {
                return (string) $code;
            }
        }
        return 'USD';
    }

    /**
     * Build the hosted payment payload.
     * Uses a single consolidated line item so the API validation holds exactly:
     *   paymentAmount = netSubtotal + tax + shipping - discount
     */
    private function buildHostedPaymentPayload(object $order, string $returnUrl, string $webhookUrl, string $cancelUrl): ?array
    {
        $orderId       = (int) ($order->virtuemart_order_id ?? 0);
        $paymentAmount = round((float) ($order->order_total ?? 0), 2);

        if ($paymentAmount <= 0) {
            return null;
        }

        $tax      = round((float) ($order->order_tax      ?? 0), 2);
        $shipping = round((float) ($order->order_shipment ?? 0), 2);
        $discount = round(abs((float) ($order->order_discount ?? 0)), 2);

        // Net subtotal so the rule holds exactly:
        //   paymentAmount = netSubtotal + tax + shipping - discount
        $netSubtotal = round($paymentAmount - $tax - $shipping + $discount, 2);

        // Safety: if discount exceeds goods, collapse to a single net line.
        if ($netSubtotal < 0) {
            $netSubtotal = $paymentAmount;
            $tax = $shipping = $discount = 0.0;
        }

        $currencyCode = $this->getVmCurrencyCode($order);
        $firstName    = (string) ($order->first_name  ?? 'Guest');
        $lastName     = (string) ($order->last_name   ?? '');
        $email        = (string) ($order->email        ?? '');
        $phone        = (string) ($order->phone_1      ?? '');
        $address      = (string) ($order->address_1    ?? '');
        $zip          = (string) ($order->zip          ?? '');
        $city         = (string) ($order->city         ?? '');
        $country      = (string) ($order->country_name ?? '');
        $countryCode  = (string) ($order->country_code ?? '');

        $userId     = (int) ($order->virtuemart_user_id ?? 0);
        $customerId = $userId > 0 ? 'USER-' . $userId : PaymentHelper::generateUuid();

        return [
            'referenceId'                => (string) $orderId,
            'amount'                     => $paymentAmount,
            'currency'                   => $currencyCode,
            'autoCapture'                => true,
            'showPayRecurringInCheckout' => false,
            'returnUrl'                  => $returnUrl,
            'webhookUrl'                 => $webhookUrl,
            'cancelUrl'                  => $cancelUrl,
            'customerOrder' => [
                'customer' => [
                    'customerId'         => $customerId,
                    'accountCreatedTime' => null,
                    'firstName'          => $firstName,
                    'lastName'           => $lastName,
                    'email'              => $email,
                    'phoneNumber'        => $phone,
                    'mobileNumber'       => $phone,
                    'address' => [
                        'streetAddressLine1' => $address,
                        'streetAddressLine2' => '',
                        'zipCode'            => $zip,
                        'city'               => $city,
                        'state'              => '',
                        'country'            => $country,
                        'countryCode'        => $countryCode,
                    ],
                ],
                'orderId'     => (string) $orderId,
                'description' => 'Order #' . $orderId,
                'customId'    => null,
                'amount' => [
                    'total'            => $netSubtotal,
                    'handling'         => 0,
                    'insurance'        => 0,
                    'discount'         => $discount,
                    'shipping'         => $shipping,
                    'shippingDiscount' => 0,
                    'totalTax'         => $tax,
                ],
                'items' => [[
                    'name'        => 'Order #' . $orderId,
                    'description' => '',
                    'amount'      => $netSubtotal,
                    'quantity'    => 1,
                    'tax'         => $tax,
                    'sku'         => (string) $orderId,
                    'category'    => 'PhysicalGoods',
                ]],
            ],
        ];
    }

    // =========================================================================
    // Frontend result card rendering (ported from the original plugin)
    // =========================================================================

    private function renderResultCard(
        string $paymentState,
        int    $orderId,
        float  $amount,
        string $currencyCode,
        string $redirectUrl,
        bool   $isSandbox
    ): string {
        $isConfirmed = ($paymentState === 'captured');
        $isFailed    = in_array($paymentState, ['failed', 'disputed', 'refunded'], true);

        if ($isConfirmed) {
            $gradFrom  = '#0f172a'; $gradTo = '#1e3a5f';
            $title     = 'Payment Successful';
            $subtitle  = 'Your transaction has been completed';
            $badgeBg   = 'linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%)';
            $dotColor  = '#34d399';
            $badgeText = 'Confirmed';
            $iconPath  = '<polyline points="20 6 9 17 4 12"/>';
        } elseif ($isFailed) {
            $gradFrom  = '#0f172a'; $gradTo = '#1e3a5f';
            $title     = 'Payment Failed';
            $subtitle  = 'Your transaction could not be completed';
            $badgeBg   = '#dc2626';
            $dotColor  = '#fca5a5';
            $badgeText = 'Failed';
            $iconPath  = '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>';
        } else {
            $gradFrom  = '#0f172a'; $gradTo = '#1e3a5f';
            $title     = 'Payment Pending';
            $subtitle  = 'Your payment is awaiting confirmation';
            $badgeBg   = '#f59e0b';
            $dotColor  = '#fde68a';
            $badgeText = 'Pending';
            $iconPath  = '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>';
        }

        $amountFmt   = number_format($amount, 2);
        $currEsc     = htmlspecialchars(strtoupper($currencyCode), ENT_QUOTES, 'UTF-8');
        $titleEsc    = htmlspecialchars($title,    ENT_QUOTES, 'UTF-8');
        $subtitleEsc = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');
        $badgeEsc    = htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8');

        $sandboxHtml = '';
        if ($isSandbox) {
            $sandboxHtml = '<span class="phrc-sandbox">'
                . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
                . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
                . '<path d="M12 9v4"/>'
                . '<path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214'
                . 'a1.914 1.914 0 0 0 1.636-2.87L13.637 3.59a1.914 1.914 0 0 0-3.274 0z"/>'
                . '<path d="M12 17h.01"/></svg>Sandbox</span>';
        }

        // "Continue Payment" only makes sense for a non-terminal payment (e.g. pending).
        // Hide it for terminal states: captured, failed, disputed, refunded.
        $isTerminal  = $isConfirmed || $isFailed;
        $continueBtn = '';
        if (!$isTerminal && $redirectUrl !== '') {
            $rlEsc = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
            $continueBtn = '<div class="phrc-actions"><a href="' . $rlEsc . '" class="phrc-continue">'
                . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
                . ' stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">'
                . '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>Continue Payment</a></div>';
        }

        // Embed the logo as base64 so it always loads (no URL / scheme / cache issues).
        // The "dark" variant is the white logo, for the dark gradient header.
        $logoFile = JPATH_PLUGINS . '/vmpayment/infynipay/media/images/logos/infynipay-logo-dark.png';
        $logoImg  = is_file($logoFile)
            ? '<img class="phrc-logo" alt="Infynipay" src="data:image/png;base64,'
                . base64_encode((string) file_get_contents($logoFile)) . '">'
            : '';

        $css = '<style>'
            . '.phrc{max-width:380px;margin:24px auto;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#fff;border-radius:16px;border:1px solid #cbd5e1;box-shadow:0 10px 30px rgba(15,23,42,.12);overflow:hidden;text-align:center;box-sizing:border-box}'
            . '.phrc *{box-sizing:border-box}'
            . '.phrc-top{padding:24px 22px 18px;background:linear-gradient(135deg,var(--phrc-gf) 0%,var(--phrc-gt) 100%)}'
            . '.phrc-logo{height:24px;width:auto;max-width:150px;object-fit:contain;display:block;margin:0 auto 14px}'
            . '.phrc-check{width:46px;height:46px;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.3);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px}'
            . '.phrc-check svg{width:24px;height:24px;stroke:#fff;fill:none}'
            . '.phrc-h{font-size:17px;font-weight:700;color:#fff;margin:0}'
            . '.phrc-sub{font-size:12.5px;color:rgba(255,255,255,.72);margin:5px 0 0}'
            . '.phrc-body{padding:20px 22px}'
            . '.phrc-amt{font-size:27px;font-weight:800;color:#111827;margin:0 0 2px;letter-spacing:-.5px}'
            . '.phrc-cur{font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:1px;font-weight:600;margin-bottom:16px}'
            . '.phrc-rows{border-top:1px solid #f1f5f9;padding-top:12px;text-align:left}'
            . '.phrc-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f4f6f9}'
            . '.phrc-row:last-child{border-bottom:none}'
            . '.phrc-lbl{font-size:12.5px;color:#94a3b8;font-weight:500}'
            . '.phrc-val{font-size:13px;color:#1e293b;font-weight:600;text-align:right}'
            . '.phrc-badge{display:inline-flex;align-items:center;gap:6px;margin-top:18px;color:#fff;font-size:12.5px;font-weight:600;padding:7px 16px;border-radius:20px}'
            . '.phrc-dot{width:7px;height:7px;border-radius:50%;animation:phrc-p 2s infinite}'
            . '@keyframes phrc-p{0%,100%{opacity:1}50%{opacity:.3}}'
            . '.phrc-actions{margin-top:22px;padding-top:18px;border-top:1px solid #f1f5f9}'
            . '.phrc-continue{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:11px 24px;border-radius:12px;background:linear-gradient(135deg,#1d4ed8 0%,#0f172a 100%);color:#fff;font-size:13.5px;font-weight:700;text-decoration:none}'
            . '.phrc-continue svg{width:16px;height:16px}'
            . '.phrc-sandbox{display:inline-flex;align-items:center;gap:4px;margin-right:6px;padding:3px 8px;border-radius:6px;background:rgba(251,191,36,.15);border:1px solid rgba(251,191,36,.4);color:#b45309;font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}'
            . '.phrc-sandbox svg{width:11px;height:11px;flex-shrink:0}'
            . '</style>';

        return $css
            . '<div class="phrc" style="--phrc-gf:' . $gradFrom . ';--phrc-gt:' . $gradTo . '">'
            . '<div class="phrc-top">'
            . $logoImg
            . '<div class="phrc-check"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' . $iconPath . '</svg></div>'
            . '<h3 class="phrc-h">' . $titleEsc . '</h3>'
            . '<p class="phrc-sub">' . $subtitleEsc . '</p>'
            . '</div>'
            . '<div class="phrc-body">'
            . '<p class="phrc-amt">' . $amountFmt . '</p>'
            . '<p class="phrc-cur">' . $currEsc . '</p>'
            . '<div class="phrc-rows">'
            . '<div class="phrc-row"><span class="phrc-lbl">Order ID</span><span class="phrc-val">' . $sandboxHtml . '#' . $orderId . '</span></div>'
            . '<div class="phrc-row"><span class="phrc-lbl">Date</span><span class="phrc-val">' . date('Y-m-d H:i') . '</span></div>'
            . '<div class="phrc-row"><span class="phrc-lbl">Payment Method</span><span class="phrc-val">Infynipay</span></div>'
            . '</div>'
            . '<div class="phrc-badge" style="background:' . $badgeBg . '">'
            . '<span class="phrc-dot" style="background:' . $dotColor . '"></span>' . $badgeEsc
            . '</div>'
            . $continueBtn
            . '</div></div>';
    }

    // =========================================================================
    // Logging
    // =========================================================================

    /**
     * Append one structured event line to the plugin log.
     * Format: [YYYY-MM-DD HH:MM:SS] [LEVEL] [stage] message
     * Path is intentionally kept unchanged (plg_payment_infynipay.log).
     */
    public function log(string $message, string $level = 'info', string $stage = ''): void
    {
        PaymentHelper::log($message, $level, $stage);
    }
}
