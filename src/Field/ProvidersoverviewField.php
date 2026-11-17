<?php
/**
 * Infynipay Providers Overview Field
 * Renders: 5-column provider icon grid fetched from public API (cached 24h)
 *
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace InfyniPay\Plugin\Payment\Infynipay\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Http\HttpFactory;

class ProvidersoverviewField extends FormField
{
    protected $type = 'Providersoverview';

    const PAYMENT_APP_API_URL = 'https://appapi.infynipay.com';

    // NOTE: getLabel() is intentionally NOT overridden — the field renders its
    // manifest label ("Supported Gateways") in the standard Joomla control-label
    // column, aligned with the "Sandbox Mode" field and the other rows.

    public function getInput(): string
    {
        $providers = $this->fetchProviders();
        return $this->renderGrid($providers);
    }

    // ── Provider fetch with 24-hour file cache ───────────────────────────────

    private function fetchProviders(): array
    {
        $cacheFile = sys_get_temp_dir() . '/ph_providers_cache.json';

        // Use cached response if fresh (24h = 86400s)
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached) && count($cached) > 0) {
                return $cached;
            }
        }

        // Fetch from public API (no authentication required)
        try {
            $response = HttpFactory::getHttp()->get(
                self::PAYMENT_APP_API_URL . '/api/apps/providers',
                ['Accept' => 'application/json'],
                10
            );

            if ($response->code === 200) {
                $data = json_decode($response->body, true);
                if (is_array($data) && count($data) > 0) {
                    @file_put_contents($cacheFile, json_encode($data));
                    return $data;
                }
            }
        } catch (\Exception $e) {
            // Fall through to empty state
        }

        return [];
    }

    // ── Grid HTML ────────────────────────────────────────────────────────────

    private function renderGrid(array $providers): string
    {
        if (empty($providers)) {
            return '<div class="ph-providers-card">'
                . '<p class="ph-providers-empty">No supported gateways are connected yet.</p>'
                . '</div>';
        }

        $tiles = '';
        foreach ($providers as $provider) {
            if (!is_array($provider)) {
                continue;
            }

            $name     = (string) ($provider['provider'] ?? '');
            $iconUri1 = (string) ($provider['iconUri1'] ?? '');
            $iconUri2 = (string) ($provider['iconUri2'] ?? '');

            // Security: only allow https:// or data: URIs
            $iconUri1 = $this->isSafeIconUrl($iconUri1) ? $iconUri1 : '';
            $iconUri2 = $this->isSafeIconUrl($iconUri2) ? $iconUri2 : '';

            if ($iconUri1 === '' && $iconUri2 === '') {
                continue;
            }

            $defaultSrc  = $iconUri1 !== '' ? $iconUri1 : $iconUri2;
            $fallbackSrc = $iconUri1 !== '' ? $iconUri2 : '';

            $nameEsc        = htmlspecialchars($name,        ENT_QUOTES, 'UTF-8');
            $defaultSrcEsc  = htmlspecialchars($defaultSrc,  ENT_QUOTES, 'UTF-8');
            $iconUri1Esc    = htmlspecialchars($iconUri1,     ENT_QUOTES, 'UTF-8');
            $iconUri2Esc    = htmlspecialchars($iconUri2,     ENT_QUOTES, 'UTF-8');
            $fallbackSrcEsc = htmlspecialchars($fallbackSrc,  ENT_QUOTES, 'UTF-8');

            $tiles .= <<<HTML
<div class="ph-provider-item ph-is-loading" title="{$nameEsc}">
    <img
        src="{$defaultSrcEsc}"
        data-light-src="{$iconUri1Esc}"
        data-dark-src="{$iconUri2Esc}"
        data-fallback-src="{$fallbackSrcEsc}"
        class="ph-provider-art"
        alt="{$nameEsc}"
        loading="eager"
        decoding="async"
        onerror="if(this.dataset.fallbackSrc&amp;&amp;this.dataset.fallbackSrc!==this.src){this.src=this.dataset.fallbackSrc;this.dataset.fallbackSrc=''}"
    >
</div>
HTML;
        }

        if ($tiles === '') {
            return '<div class="ph-providers-card">'
                . '<p class="ph-providers-empty">No supported gateways are connected yet.</p>'
                . '</div>';
        }

        return '<div class="ph-providers-card">'
            . '<div class="ph-providers-grid">' . $tiles . '</div>'
            . '</div>';
    }

    private function isSafeIconUrl(string $url): bool
    {
        return $url !== ''
            && (str_starts_with($url, 'https://')
                || str_starts_with($url, 'data:image/'));
    }
}
