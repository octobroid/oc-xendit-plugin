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

    /**
     * Register method, called when the plugin is first registered.
     *
     * @return void
     */
    public function register()
    {

    }

    /**
     * Boot method, called right before the request route.
     *
     * @return array
     */
    public function boot()
    {

    }

    /**
     * Registers any front-end components implemented in this plugin.
     *
     * @return array
     */
    public function registerComponents()
    {
        return []; // Remove this line to activate

        return [
            'Octobro\Xendit\Components\MyComponent' => 'myComponent',
        ];
    }

    /**
     * Registers any back-end permissions used by this plugin.
     *
     * @return array
     */
    public function registerPermissions()
    {
        return []; // Remove this line to activate

        return [
            'octobro.xendit.some_permission' => [
                'tab' => 'Xendit',
                'label' => 'Some permission'
            ],
        ];
    }

    /**
     * Registers back-end navigation items for this plugin.
     *
     * @return array
     */
    public function registerNavigation()
    {
        return []; // Remove this line to activate

        return [
            'xendit' => [
                'label'       => 'Xendit',
                'url'         => Backend::url('octobro/xendit/mycontroller'),
                'icon'        => 'icon-leaf',
                'permissions' => ['octobro.xendit.*'],
                'order'       => 500,
            ],
        ];
    }
}
