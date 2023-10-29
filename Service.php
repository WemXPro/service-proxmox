<?php

namespace App\Services\Proxmox;

use Illuminate\Support\Facades\Http;
use App\Services\ServiceInterface;
use App\Models\Package;
use App\Models\Order;

class Service implements ServiceInterface
{
    /**
     * Unique key used to store settings 
     * for this service.
     * 
     * @return string
     */
    public static $key = 'proxmox'; 

    public function __construct(Order $order)
    {
        $this->order = $order;
    }
    
    /**
     * Returns the meta data about this Server/Service
     *
     * @return object
     */
    public static function metaData(): object
    {
        return (object)
        [
          'display_name' => 'Proxmox',
          'author' => 'WemX',
          'version' => '1.0.0',
          'wemx_version' => ['dev', '>=1.8.0'],
        ];
    }

    /**
     * Define the default configuration values required to setup this service
     * i.e host, api key, or other values. Use Laravel validation rules for
     *
     * Laravel validation rules: https://laravel.com/docs/10.x/validation
     *
     * @return array
     */
    public static function setConfig(): array
    {
        return [
            [
                "key" => "proxmox::hostname",
                "name" => "Hostname",
                "description" => "Hostname of your Proxmox panel i.e host.example.com",
                "type" => "text",
                "rules" => ['required'], // laravel validation rules
            ],
            [
                "key" => "proxmox::port",
                "name" => "Port",
                "description" => "Hostname of your Proxmox panel i.e host.example.com",
                "type" => "number",
                "rules" => ['required', 'numeric'], // laravel validation rules
            ],
            [
                "key" => "proxmox::token_id",
                "name" => "Token ID",
                "description" => "The token ID for the Promox API",
                "type" => "text",
                "rules" => ['required'], // laravel validation rules
            ],
            [
                "key" => "encrypted::proxmox::token_secret",
                "name" => "Token Secret",
                "description" => "The token secret for the Promox API",
                "type" => "password",
                "rules" => ['required'], // laravel validation rules
            ],
        ];
    }

    /**
     * Define the default package configuration values required when creatig
     * new packages. i.e maximum ram usage, allowed databases and backups etc.
     *
     * Laravel validation rules: https://laravel.com/docs/10.x/validation
     *
     * @return array
     */
    public static function setPackageConfig(Package $package): array
    {
        dd(self::api()->getPools());
        return [];
    }

    /**
     * Define the checkout config that is required at checkout and is fillable by
     * the client. Its important to properly sanatize all inputted data with rules
     *
     * Laravel validation rules: https://laravel.com/docs/10.x/validation
     *
     * @return array
     */
    public static function setCheckoutConfig(Package $package): array
    {
        return [];
    }

    /**
     * Define buttons shown at order management page
     *
     * @return array
     */
    public static function setServiceButtons(Order $order): array
    {
        return [];    
    }

    protected static function api()
    {
        return new ProxmoxAPI;
    }

    /**
     * This function is responsible for creating an instance of the
     * service. This can be anything such as a server, vps or any other instance.
     * 
     * @return void
     */
    public function create(array $data = [])
    {
        return [];
    }

    /**
     * This function is responsible for upgrading or downgrading
     * an instance of this service. This method is optional
     * If your service doesn't support upgrading, remove this method.
     * 
     * Optional
     * @return void
    */
    public function upgrade(Package $oldPackage, Package $newPackage)
    {
        return [];
    }

    /**
     * This function is responsible for suspending an instance of the
     * service. This method is called when a order is expired or
     * suspended by an admin
     * 
     * @return void
    */
    public function suspend(array $data = [])
    {
        return [];
    }

    /**
     * This function is responsible for unsuspending an instance of the
     * service. This method is called when a order is activated or
     * unsuspended by an admin
     * 
     * @return void
    */
    public function unsuspend(array $data = [])
    {
        return [];
    }

    /**
     * This function is responsible for deleting an instance of the
     * service. This can be anything such as a server, vps or any other instance.
     * 
     * @return void
    */
    public function terminate(array $data = [])
    {
        return [];
    }

}
