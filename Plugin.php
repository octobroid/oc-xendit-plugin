<?php namespace Octobro\Xendit;

use Backend;
use System\Classes\PluginBase;

/**
 * Xendit Plugin Information File
 */
class Plugin extends PluginBase
{
    public $require = ['Responsiv.Pay'];

    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name'        => 'Xendit',
            'description' => 'Xendit payment gateway for Responsiv.Pay plugin.',
            'author'      => 'Octobro',
            'icon'        => 'icon-credit-card'
        ];
    }

    /**
     * Registers any payment gateways implemented in this plugin.
     * The gateways must be returned in the following format:
     * ['className1' => 'alias'],
     * ['className2' => 'anotherAlias']
     */
    public function registerPaymentGateways()
    {
        return [
            'Octobro\Xendit\PaymentTypes\Xendit' => 'xendit',
        ];
    }

}
