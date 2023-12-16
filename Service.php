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
                "col" => "col-12",
                "key" => "proxmox::hostname",
                "name" => "Hostname",
                "description" => "Hostname of your Proxmox panel i.e https://host.example.com:8008",
                "type" => "url",
                "rules" => ['required'], // laravel validation rules
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

        $storage = self::api()->getNodeStorage($package->data('node', $nodes->first()))->mapWithKeys(function ($storage, int $key) {
            return [$storage->storage => $storage->storage];
        });

        $pools = self::api()->getPools()->mapWithKeys(function ($pool, int $key) {
            return [$pool->poolid => $pool->poolid];
        });

        $images = self::api()->getNodeISOImages($package->data('node', $nodes->first()), $package->data('storage', 'local'));

        $config =
        [
            [
                "col" => "col-12",
                "key" => "type",
                "name" => "VM Type",
                "description" => "Please select the type of the VM",
                "type" => "select",
                "save_on_change" => true,
                "options" => [
                    'qemu' => 'QEMU/KVM Virtual Machine',
                    'lxc' => 'Linux Container',
                ],
                "rules" => ['required'],
            ],
            [
                "col" => "col-12",
                "key" => "node",
                "name" => "Node",
                "description" => "Select the VM node",
                "type" => "select",
                "save_on_change" => true,
                "options" => $nodes,
                "rules" => ['required'],
            ],
            [
                "col" => "col-12",
                "key" => "storage",
                "name" => "Storage",
                "description" => "Enter the storage where you wish to load assets from such as images and templates",
                "type" => "select",
                "options" => $storage,
                "default_value" => "local",
                "rules" => ['required'],
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

        if($package->data('type') == 'qemu') {
            $config = array_merge($config, [
                [
                    "col" => "col-12",
                    "key" => "clone_template_id",
                    "name" => "Clone Template ID",
                    "description" => "Enter the ID of the VM template you want to clone the clone must be of type {$package->data('type')}",
                    "type" => "number",
                    "default_value" => 100,
                    "save_on_change" => true,
                    "rules" => ['required', 'numeric', new Rules\CloneVMExists($package->data('node'))], // todo add custom rule to check if the clone template exists
                ],
                [
                    "col" => "col-12",
                    "key" => "selectable_iso_template",
                    "name" => "Allow Selectable ISO image at Checkout",
                    "description" => "Do you want to allow the user to select the ISO image at checkout?",
                    "type" => "select",
                    "options" => [
                        1 => 'Yes, let user select the iso image and os type at checkout',
                        0 => 'No, setup the iso image and os type below',
                    ],
                    "default_value" => 1,
                    "save_on_change" => true,
                    "rules" => ['required'],
                ]
            ]);

            if($package->data('selectable_iso_template')) {
                $config = array_merge($config, [
                    [
                    "col" => "col-12",
                    "key" => "iso_images[]",
                    "name" => "Selectable ISO Templates",
                    "description" => "Enter the ISO images that are selectable at checkout by the user",
                    "type" => "select",
                    "multiple" => true,
                    "options" => $images,
                    "rules" => ['required'],
                    ],
                ]);
            } else{
                $config = array_merge($config, [
                    [
                        "col" => "col-12",
                        "key" => "image",
                        "name" => "Image",
                        "description" => "Select the ISO Image for the VM",
                        "type" => "select",
                        "options" => $images,
                        "rules" => ['required'],
                    ],
                ]);
            }
        }

        if($package->data('type') == 'lxc') {
            $osTemplates = self::api()->getOSTemplates($package->data('node', $nodes->first()), $package->data('storage', 'local'));

            $config = array_merge($config, [
                [
                    "col" => "col-12",
                    "key" => "selectable_os_template",
                    "name" => "Allow Selectable OS Templates at Checkout",
                    "description" => "Do you want to allow the user to select the OS template at checkout?",
                    "type" => "select",
                    "options" => [
                        1 => 'Yes, let user select the OS template at checkout',
                        0 => 'No, select the OS template below',
                    ],
                    "default_value" => 1,
                    "save_on_change" => true,
                    "rules" => ['required', 'boolean'],
                ]
            ]);

            if($package->data('selectable_os_template', true)) {
                $config = array_merge($config, [
                    [
                    "col" => "col-12",
                    "key" => "os_templates[]",
                    "name" => "Selectable OS Templates",
                    "description" => "Enter the OS templates that are selectable at checkout by the user",
                    "type" => "select",
                    "multiple" => true,
                    "options" => $osTemplates,
                    "rules" => ['required'],
                    ],
                ]);
            } else {
                $config = array_merge($config, [
                    [
                        "col" => "col-12",
                        "key" => "os_template",
                        "name" => "OS Template",
                        "description" => "Select the OS template for the VM",
                        "type" => "select",
                        "options" => $osTemplates,
                        "rules" => ['required'],
                    ],
                ]);
            }
            
        }

        return $config;
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
        $config = [];

        if($package->data('type') == 'lxc') {
            $osTemplates = collect($package->data('os_templates'))->mapWithKeys(function ($template, int $key) {
                return [$template => $template];
            });

            if($package->data('selectable_os_template', true)) {
                $config = array_merge($config, [
                    [
                        "col" => "w-full mb-4",
                        "key" => "os_template",
                        "name" => "Operating System Template",
                        "description" => "Please select the operating system template you wish to use",
                        "type" => "select",
                        "options" => $osTemplates,
                        "rules" => ['required'],
                        ],
                ]);
            }

            $config = array_merge($config, [
                [
                    "col" => "w-full mb-4",
                    "key" => "password",
                    "name" => "Root Password",
                    "description" => "Please enter the password for the root user of the server",
                    "type" => "password",
                    "rules" => ['required', 'confirmed', 'min:5'],
                ],
                [
                    "col" => "w-full mb-4",
                    "key" => "password_confirmation",
                    "name" => "Confirm Password",
                    "description" => "Please confirm the password for the root user",
                    "type" => "password",
                    "rules" => ['required'],
                ],
            ]);
        }

        if($package->data('type') == 'qemu') {
            $images = collect($package->data('iso_images'))->mapWithKeys(function ($image, int $key) {
                return [$image => $image];
            });

            if($package->data('selectable_iso_template', 2) == 1) {
                $config = array_merge($config, [
                    [
                        "col" => "w-full mb-4",
                        "key" => "image",
                        "name" => "Operating System",
                        "description" => "Please select the operating system image you wish to use",
                        "type" => "select",
                        "options" => $images,
                        "rules" => ['required'],
                        ],
                ]);
            }
        }

        return $config;
    }

    /**
     * Define buttons shown at order management page
     *
     * @return array
     */
    public static function setServiceButtons(Order $order): array
    {
        return [
            [
                "name" => "Login to Panel",
                "color" => "primary",
                "href" => settings('proxmox::hostname'),
                "target" => "_blank", // optional
            ],
            // add more buttons
        ];
    }

    /**
     * Define custom permissions for this service
     *
     * @return array
     */
    public static function permissions(): array
    {
        return [
            'proxmox.server.start' => [
                'description' => 'Permission to start a Proxmox VM from the dashboard',
            ],
            'proxmox.server.stop' => [
                'description' => 'Permission to stop a Proxmox VM from the dashboard',
            ],
            'proxmox.server.shutdown' => [
                'description' => 'Permission to shutdown a Proxmox VM from the dashboard',
            ],
            'proxmox.server.reboot' => [
                'description' => 'Permission to reboot a Proxmox VM from the dashboard',
            ],
        ];
    }

    /**
     * Init API connection
     */
    protected static function api()
    {
        return new ProxmoxAPI;
    }

    /**
     * Test API connection
     */
    public static function testConnection()
    {
        try {
            self::api()->getNodes()->all();
        } catch(\Exception $error) {
            return redirect()->back()->withError("Failed to connect to Proxmox. <br><br>{$error->getMessage()}");
        }

        return redirect()->back()->withSuccess("Successfully connected with Proxmox");
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

        // if type is qemu, create a VM
        if($package->data('type', 'qemu') == 'qemu') { 
            $cdrom = ($package->data('selectable_iso_template')) ? $order->option('image') : $package->data('image');
            $response = self::api()->createVM($package->data('node'), [
                'cores' => $package->data('cpu_cores', 1),
                'sockets' => $package->data('cpu_sockets', 1),
                'memory' => $package->data('memory_size', 1024),
                'disk' => $package->data('disk_size', 32),
                'os_type' => $package->data('ostype', 'l26'),
                'cdrom' =>  $cdrom,
                'iso_image' => $package->data('image'),
                'storage' => $package->data('storage'),
                'clone_template_id' => $package->data('clone_template_id'),
            ]);
        }

        // if type is lxc, create a CT
        if($package->data('type') == 'lxc') {
            $osTemplate = $package->data('selectable_os_template', true) ? $order->option('os_template') : $package->data('os_template');
            $response = self::api()->createCT($package->data('node'), [
                'cores' => $package->data('cpu_cores', 1),
                'password' => $order->option('password'),
                'memory' => $package->data('memory_size', 1024),
                'disk' => $package->data('disk_size', 32),
                'os_template' => $osTemplate,
                'storage' => $package->data('storage'),
            ]);
        }

        // store the VMID
        $order->update(['data' => $response]);

        if(!$order->hasExternalUser()) {
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

            $this->emailDetails($user, $userData['username'], $userData['password']);
        }

        $proxmoxUser = $order->getExternalUser();
        self::api()->giveUserAccessToVM($order->data['vmid'], $proxmoxUser->external_id);
    }

    protected function emailDetails($user, $username, $password): void
    {
        $user->email([
            'subject' => 'Proxmox Account',
            'content' => "
                Your Proxmox Account details: <br> <br>
                - Username: {$username} <br>
                - Password: {$password} <br>
                - Realm: Proxmox VE authentication server <br>
            ",
            'button' => [
                'name' => 'Proxmox Panel',
                'url' => settings('proxmox::hostname'),
            ],
        ]);
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
        $type = $this->orderData('type', 'qemu');
        $node = $this->orderData('node');
        $vmid = (int) $this->orderData('vmid');

        if($type == 'qemu') {
           return self::api()->suspendVM($node, $vmid);
        }
        
        self::api()->suspendCT($node, $vmid);
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
        $type = $this->orderData('type', 'qemu');
        $node = $this->orderData('node');
        $vmid = (int) $this->orderData('vmid');

        if($type == 'qemu') {
            return self::api()->unsuspendVM($node, $vmid);
        }
        
        self::api()->unsuspendCT($node, $vmid);
    }

    /**
     * This function is responsible for deleting an instance of the
     * service. This can be anything such as a server, vps or any other instance.
     * 
     * @return void
    */
    public function terminate(array $data = [])
    {
        $type = $this->orderData('type', 'qemu');
        $node = $this->orderData('node');
        $vmid = (int) $this->orderData('vmid');

        if($type == 'qemu') {
            self::api()->terminateVM($node, $vmid);
        } else {
            self::api()->terminateCT($node, $vmid);
        }
        
        $this->order->update(['data' => ['node' => 'terminated', 'vmid' => 'terminated']]);
    }

    protected function orderData($key, $default = null) {
        return $this->order->data[$key] ?? $default;
    }

    /**
     * Email the password to the user again
    */
    public function resendPassword(Order $order)
    {
        if (!$order->canViewOrder()) {
            return abort(403, 'You dont have permissions to access this resource');
        }

        try {
            $proxmoxUser = $order->getExternalUser();
            $this->emailDetails($order->user, $proxmoxUser->username, decrypt($proxmoxUser->password));
        } catch(\Exception $error) {
            ErrorLog('proxmox::resend-password', "[Proxmox] Failed to send {$order->user->email} their Proxmox Password | Received error {$error}");
            return redirect()->back()->withError('Something went wrong, please try again.');
        }

        return redirect()->back()->withSuccess("We have emailed your password to {$order->user->email}");
    }

    /**
     * Attempt to start a VM
    */
    public function startServer(Order $order)
    {
        try {
            $VM = $order->data;
            self::api()->startVM($VM['node'], (int) $VM['vmid'], $VM['type'] ?? 'qemu');
        } catch(\Exception $error) {
            return redirect()->back()->withError('Something went wrong, please try again.');
        }

        sleep(1);

        return redirect()->back()->withSuccess('Starting your server...');
    }

    /**
     * Attempt to stop a VM
    */
    public function stopServer(Order $order)
    {
        try {
            $VM = $order->data;
            self::api()->stopVM($VM['node'], (int) $VM['vmid'], $VM['type'] ?? 'qemu');
        } catch(\Exception $error) {
            return redirect()->back()->withError('Something went wrong, please try again.');
        }

        sleep(1);

        return redirect()->back()->withSuccess('Stopping your server...');
    }

    /**
     * Attempt to shutdown a VM
    */
    public function shutdownServer(Order $order)
    {
        try {
            $VM = $order->data;
            self::api()->shutdownVM($VM['node'], (int) $VM['vmid'], $VM['type'] ?? 'qemu');
        } catch(\Exception $error) {
            return redirect()->back()->withError('Something went wrong, please try again.');
        }

        sleep(1);

        return redirect()->back()->withSuccess('Shutting down your server...');
    }
    
    /**
     * Attempt to reboot a VM
    */
    public function rebootServer(Order $order)
    {
        try {
            $VM = $order->data;
            self::api()->rebootVM($VM['node'], (int) $VM['vmid'], $VM['type'] ?? 'qemu');
        } catch(\Exception $error) {
            return redirect()->back()->withError('Something went wrong, please try again.');
        }

        sleep(1);

        return redirect()->back()->withSuccess('Rebooting your server...');
    }
}
