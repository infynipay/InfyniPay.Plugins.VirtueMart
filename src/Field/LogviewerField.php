<?php
/**
 * Infynipay Log Viewer Field
 * Renders: debug log file path with monospace styling
 *
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace InfyniPay\Plugin\Payment\Infynipay\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;

class LogviewerField extends FormField
{
    protected $type = 'Logviewer';

    public function getInput(): string
    {
        $logFile = JPATH_ADMINISTRATOR . '/logs/plg_payment_infynipay.log';
        $exists  = is_file($logFile);

        $pathEsc = htmlspecialchars($logFile, ENT_QUOTES, 'UTF-8');

        if ($exists) {
            $size    = filesize($logFile);
            $sizeStr = $size < 1024
                ? $size . ' B'
                : round($size / 1024, 1) . ' KB';
            $info = ' <span class="badge bg-secondary">' . htmlspecialchars($sizeStr, ENT_QUOTES, 'UTF-8') . '</span>';
        } else {
            $info = ' <span class="text-muted">(not yet created)</span>';
        }

        return '<p class="ph-log-path">' . $pathEsc . $info . '</p>';
    }
}
