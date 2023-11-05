<?php

namespace App\Services\Proxmox;

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
        $nodes = self::api()->getNodes()->mapWithKeys(function ($node, int $key) {
            return [$node->node => $node->node];
        });

        $storage = self::api()->getNodeStorage($package->data('node', 'pve2'))->mapWithKeys(function ($storage, int $key) {
            return [$storage->storage => $storage->storage];
        });

        $pools = self::api()->getPools()->mapWithKeys(function ($pool, int $key) {
            return [$pool->poolid => $pool->poolid];
        });

        $images = self::api()->getNodeISOImages($package->data('node', 'pve2'), $package->data('storage', 'local'));

        return 
        [
            [
                "col" => "col-4",
                "key" => "node",
                "name" => "Node",
                "description" => "Select the VM node",
                "type" => "select",
                "save_on_change" => true,
                "options" => $nodes,
                "rules" => ['required'],
            ],
            [
                "col" => "col-4",
                "key" => "pool",
                "name" => "Resource Pool",
                "description" => "Select the resource pool for the node",
                "type" => "select",
                "options" => $pools,
                "rules" => ['nullable'],
            ],
            [
                "col" => "col-4",
                "key" => "ostype",
                "name" => "OS Type",
                "description" => "Select the OS Type",
                "type" => "select",
                "options" => [
                    'l24' => 'Linux 2.4 Kernel',
                    'l26' => 'Linux 6.x - 2.6 Kernel',
                    'other' => 'other',
                    'solaris' => 'solaris',
                    'w2k' => 'Windows 2000',
                    'w2k3' => 'Windows 2003',
                    'w2k8' => 'Windows 2008',
                    'win7' => 'Windows 7',
                    'win8' => 'Windows 8',
                    'win10' => 'Windows 10',
                    'win11' => 'Windows 11',
                    'wvista' => 'Windows Vista',
                    'wxp' => 'Windows XP',
                ],
                'default_value' => 'l26',
                "rules" => ['required'],
            ],
            [
                "col" => "col-4",
                "key" => "storage",
                "name" => "Storage",
                "description" => "Select the storage for the node",
                "type" => "select",
                "options" => $storage,
                "rules" => ['nullable'],
                "save_on_change" => true,
            ],
            [
                "col" => "col-4",
                "key" => "vm_cdrom",
                "name" => "CD/DVD Configuration",
                "description" => "Configure CD/DVD media for the VM",
                "type" => "select",
                "options" => [
                    '' => 'Do not use any media',
                    'cdrom' => 'Use physical CD/DVD Drive',
                    'iso' => 'Use CD/DVD disc image file (iso)',
                ],
                "default_value" => 'iso',
                "rules" => ['nullable'],
                "save_on_change" => true,
            ],
            [
                "col" => "col-4",
                "key" => "image",
                "name" => "ISO Image",
                "description" => "Select the ISO image",
                "type" => "select",
                "options" => $images,
                "rules" => ['nullable'],
            ],
            [
                "key" => "disk_size",
                "name" => "Disk Size (GB)",
                "description" => "Disk size allocated to the VM",
                "type" => "number",
                "default_value" => 32,
                "rules" => ['required', 'numeric'],
            ],
            [
                "key" => "memory_size",
                "name" => "Memory Size (MB)",
                "description" => "Memory size allocated to the VM",
                "type" => "number",
                "default_value" => 2048,
                "rules" => ['required', 'numeric'],
            ],
            [
                "key" => "cpu_cores",
                "name" => "CPU Cores",
                "description" => "Number of CPU cores allocated to the VM",
                "type" => "number",
                "default_value" => 1,
                "rules" => ['required', 'numeric'],
            ],
            [
                "key" => "cpu_sockets",
                "name" => "CPU Sockets",
                "description" => "Number of CPU sockets allocated to the VM",
                "type" => "number",
                "default_value" => 1,
                "rules" => ['required', 'numeric'],
            ],
        ];
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
        $package = $this->order->package;
        $user = $this->order->user;
        $order = $this->order;

        $response = self::api()->createVM($package->data('node', 'pve2'), [
            'cores' => $package->data('cpu_cores', 1),
            'sockets' => $package->data('cpu_sockets', 1),
            'memory' => $package->data('memory_size', 1024),
            'disk' => $package->data('disk_size', 32),
            'os_type' => $package->data('ostype', 'l26'),
            'vm_cdrom' =>  $package->data('vm_cdrom'),
            'iso_image' => $package->data('image'),
        ]);

        // store the VMID
        $order->update(['data' => $response]);

        // create Proxmox User
        $realm = 'pve';
        $userData = [
            'username' => $user->username . rand(100, 999),
            'email' => $user->email,
            'password' => str_random(15),
        ];

        self::api()->createUser($userData, $realm);

        $order->createExternalUser([
            'external_id' => "{$userData['username']}@{$realm}",
            'username' => $userData['username'],
            'password' => $userData['password'],
        ]);

        self::api()->giveUserAccessToVM($order->data['vmid'], "{$userData['username']}@{$realm}");
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
        $VM = $this->order->data;
        self::api()->suspendVM($VM['node'], (int) $VM['vmid']);
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
        $VM = $this->order->data;
        self::api()->unsuspendVM($VM['node'], (int) $VM['vmid']);
    }

    /**
     * This function is responsible for deleting an instance of the
     * service. This can be anything such as a server, vps or any other instance.
     * 
     * @return void
    */
    public function terminate(array $data = [])
    {
        $VM = $this->order->data;
        self::api()->terminateVM($VM['node'], (int) $VM['vmid']);
    }

}
