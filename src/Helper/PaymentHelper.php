<?php
/**
 * Infynipay Payment Helper
 * Shared utilities — webhook token validation, URL helpers, logging.
 * NOTE: Order status sync is handled directly in infynipay.php (syncVmOrderStatus)
 *       because it depends on plugin params and VirtueMart tables.
 *
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace InfyniPay\Plugin\Payment\Infynipay\Helper;

defined('_JEXEC') or die;

class PaymentHelper
{
    /** Terminal payment states (will not change further) */
    const TERMINAL_STATES = ['captured', 'failed', 'disputed', 'refunded'];

    /**
     * Detect if URL is localhost or private IP (used for webhook delivery warning)
     */
    public static function isLocalhostUrl(string $url): bool
    {
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($host);
            return ($long & 0xFF000000) === 0x0A000000   // 10.0.0.0/8
                || ($long & 0xFFF00000) === 0xAC100000   // 172.16.0.0/12
                || ($long & 0xFFFF0000) === 0xC0A80000   // 192.168.0.0/16
                || ($long & 0xFF000000) === 0x7F000000;  // 127.0.0.0/8
        }

        return false;
    }

    /**
     * Generate RFC 4122 v4 UUID
     */
    public static function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Validate webhook Bearer token using constant-time comparison
     */
    public static function validateWebhookToken(string $storedSecret, string $receivedToken): bool
    {
        if ($storedSecret === '' || $receivedToken === '') {
            return false;
        }

        return hash_equals($storedSecret, $receivedToken);
    }

    /**
     * Format currency amount with symbol
     */
    public static function formatCurrency(float $amount, string $currencyCode): string
    {
        $symbols = [
            'USD' => '$', 'EUR' => '€', 'GBP' => '£',
            'JPY' => '¥', 'CAD' => '$', 'AUD' => '$',
        ];

        $symbol = $symbols[strtoupper($currencyCode)] ?? $currencyCode;

        return $symbol . number_format($amount, 2);
    }

    /**
     * Build Infynipay console deep-link URL
     */
    public static function buildConsoleLinkUrl(string $appId, string $paymentId): string
    {
        return 'https://console.infynipay.com/' . urlencode($appId)
            . '/payments/detail?appId=' . urlencode($appId)
            . '&paymentId=' . urlencode($paymentId);
    }

    /**
     * Build OAuth setup URL (redirect to Infynipay panel)
     */
    public static function buildOAuthSetupUrl(string $returnUrl): string
    {
        return 'https://console.infynipay.com/auth/signin'
            . '?returnUrl=' . urlencode($returnUrl)
            . '&grantAuthorization=true';
    }

    /**
     * Append one structured event line to the plugin log.
     * Format: [YYYY-MM-DD HH:MM:SS] [LEVEL] [stage] message
     * See INTEGRATION_GUIDE.md Section 21 (Logging & Observability Standard).
     */
    public static function log(string $message, string $level = 'info', string $stage = ''): void
    {
        $dir = JPATH_ADMINISTRATOR . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $logFile  = $dir . '/plg_payment_infynipay.log';
        $stageTag = $stage !== '' ? '[' . $stage . '] ' : '';
        $line     = sprintf(
            "[%s] [%s] %s%s%s",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $stageTag,
            $message,
            PHP_EOL
        );

        $written = @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

        // If our own file is not writable (permissions), never lose the event —
        // fall back to PHP's error_log (Apache/PHP error log) so it is still visible.
        if ($written === false) {
            @error_log('[plg_payment_infynipay] ' . trim($line));
        }
    }

    /**
     * Log a caught throwable: class + file:line at ERROR, full stack at DEBUG.
     */
    public static function logThrowable(string $stage, \Throwable $e): void
    {
        self::log(
            sprintf('%s | exception=%s at=%s:%d', $e->getMessage(), get_class($e), $e->getFile(), $e->getLine()),
            'error',
            $stage
        );
        self::log('stacktrace: ' . $e->getTraceAsString(), 'debug', $stage);
    }

    /**
     * Mask a secret/token for safe logging: first 4 + last 4 chars + length.
     * Never write full tokens, webhook secrets, or authorization codes to the log.
     */
    public static function mask(string $s): string
    {
        $len = strlen($s);
        if ($len === 0) {
            return '(empty)';
        }
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($s, 0, 4) . '...' . substr($s, -4) . " (len={$len})";
    }
}
