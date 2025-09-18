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
          'display_name' => 'Proxmox VPS',
          'author' => 'Enhanced by User',
          'version' => '2.0.0',
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
                "description" => "Hostname of your Proxmox panel i.e https://host.example.com:8006",
                "type" => "url",
                "rules" => ['required'], // laravel validation rules
            ],
            [
                "key" => "proxmox::token_id",
                "name" => "Token ID",
                "description" => "The token ID for the Proxmox API",
                "type" => "text",
                "rules" => ['required'], // laravel validation rules
            ],
            [
                "key" => "encrypted::proxmox::token_secret",
                "name" => "Token Secret",
                "description" => "The token secret for the Proxmox API",
                "type" => "password",
                "rules" => ['required'], // laravel validation rules
            ],
            [
                "key" => "proxmox::default_bridge",
                "name" => "Default Network Bridge",
                "description" => "Default network bridge (e.g., vmbr0)",
                "type" => "text",
                "default_value" => "vmbr0",
                "rules" => ['required'],
            ],
            [
                "key" => "proxmox::ip_pool_start",
                "name" => "IP Pool Start",
                "description" => "Starting IP address for automatic assignment (e.g., 192.168.1.100)",
                "type" => "text",
                "rules" => ['nullable', 'ip'],
            ],
            [
                "key" => "proxmox::ip_pool_end",
                "name" => "IP Pool End",
                "description" => "Ending IP address for automatic assignment (e.g., 192.168.1.200)",
                "type" => "text",
                "rules" => ['nullable', 'ip'],
            ],
        ];
    }

    /**
     * Define the default package configuration values required when creating
     * new packages. Simplified and all optional approach.
     *
     * @return array
     */
    public static function setPackageConfig(Package $package): array
    {
        // Get nodes
        $nodes = self::api()->getNodes()->mapWithKeys(function ($node, int $key) {
            return [$node->node => $node->node];
        });

        $firstNode = $nodes->first();
        $selectedNode = $package->data('node', $firstNode);

        // Get storage
        $storage = self::api()->getNodeStorage($selectedNode)->mapWithKeys(function ($storage, int $key) {
            return [$storage->storage => $storage->storage];
        });

        // Get VM templates and ISO images
        $vmTemplates = self::api()->getVMTemplates($selectedNode);
        $isoImages = self::api()->getAllNodeISOImages($selectedNode);
        $osTemplates = self::api()->getOSTemplates($selectedNode, $storage->first());

        // Basic configuration - all simplified
        $config = [
            [
                "col" => "col-12",
                "key" => "node",
                "name" => "Proxmox Node",
                "description" => "Select the Proxmox node for deployments",
                "type" => "select",
                "save_on_change" => true,
                "options" => $nodes,
                "rules" => ['required'],
            ],
            [
                "col" => "col-12",
                "key" => "storage",
                "name" => "Default Storage",
                "description" => "Default storage for VM disks",
                "type" => "select",
                "options" => $storage,
                "default_value" => "local-lvm",
                "rules" => ['required'],
            ],
            
            // VM Creation Options
            [
                "col" => "col-12",
                "key" => "creation_method",
                "name" => "VM Creation Method",
                "description" => "Choose how VMs should be created",
                "type" => "select",
                "options" => [
                    'template' => 'Clone from VM Templates (Recommended)',
                    'iso' => 'Create from ISO Images',
                    'container' => 'LXC Containers',
                    'flexible' => 'Allow Customer Choice'
                ],
                "default_value" => 'flexible',
                "save_on_change" => true,
                "rules" => ['required'],
            ],
            
            // Resource Limits (Optional)
            [
                "col" => "col-6",
                "key" => "cpu_cores",
                "name" => "CPU Cores",
                "description" => "Number of CPU cores (leave empty for customer choice)",
                "type" => "number",
                "default_value" => 1,
                "rules" => ['nullable', 'numeric', 'min:1', 'max:64'],
            ],
            [
                "col" => "col-6",
                "key" => "cpu_sockets", 
                "name" => "CPU Sockets",
                "description" => "Number of CPU sockets (leave empty for customer choice)",
                "type" => "number",
                "default_value" => 1,
                "rules" => ['nullable', 'numeric', 'min:1', 'max:8'],
            ],
            [
                "col" => "col-6",
                "key" => "memory_mb",
                "name" => "Memory (MB)",
                "description" => "Memory in MB (leave empty for customer choice)",
                "type" => "number",
                "default_value" => 2048,
                "rules" => ['nullable', 'numeric', 'min:512'],
            ],
            [
                "col" => "col-6",
                "key" => "disk_gb",
                "name" => "Disk Space (GB)",
                "description" => "Disk space in GB (leave empty for customer choice)",
                "type" => "number", 
                "default_value" => 20,
                "rules" => ['nullable', 'numeric', 'min:1'],
            ],
            [
                "col" => "col-6",
                "key" => "bandwidth_mbps",
                "name" => "Bandwidth Limit (Mbps)",
                "description" => "Network bandwidth limit in Mbps (empty = unlimited)",
                "type" => "number",
                "rules" => ['nullable', 'numeric', 'min:1'],
            ],
            
            // Post-creation script
            [
                "col" => "col-12",
                "key" => "post_creation_script",
                "name" => "Post-Creation Script (Cloud-Init)",
                "description" => "Script to run after VM creation. This will be executed as root during first boot.",
                "type" => "textarea",
                "placeholder" => "#!/bin/bash\n# Your script here\napt update && apt upgrade -y\n# Install Docker\ncurl -fsSL https://get.docker.com -o get-docker.sh\nsh get-docker.sh",
                "rules" => ['nullable'],
            ],
            
            // Cloud-init user configuration
            [
                "col" => "col-6",
                "key" => "default_username",
                "name" => "Default Username",
                "description" => "Default username for cloud-init (leave empty to use 'root')",
                "type" => "text",
                "default_value" => "admin",
                "rules" => ['nullable'],
            ],
            [
                "col" => "col-6",
                "key" => "enable_ssh_keys",
                "name" => "Enable SSH Key Authentication",
                "description" => "Allow customers to add SSH keys during setup",
                "type" => "select",
                "options" => [
                    0 => 'Disabled - Password only',
                    1 => 'Enabled - Allow SSH keys',
                ],
                "default_value" => 1,
                "rules" => ['required'],
            ],
            
            // Auto IP assignment
            [
                "col" => "col-12",
                "key" => "ip_assignment",
                "name" => "IP Assignment Method",
                "description" => "How IP addresses should be assigned",
                "type" => "select",
                "options" => [
                    'dhcp' => 'DHCP (Automatic)',
                    'pool' => 'Assign from IP Pool',
                    'manual' => 'Manual (Customer Input Required)'
                ],
                "default_value" => 'dhcp',
                "rules" => ['required'],
            ],
        ];

        // Add template selection based on creation method
        $creationMethod = $package->data('creation_method', 'flexible');
        
        if (in_array($creationMethod, ['template', 'flexible'])) {
            $config[] = [
                "col" => "col-12",
                "key" => "available_templates[]",
                "name" => "Available VM Templates",
                "description" => "Select which VM templates customers can choose from",
                "type" => "select",
                "multiple" => true,
                "options" => $vmTemplates,
                "rules" => [],
            ];
        }

        if (in_array($creationMethod, ['iso', 'flexible'])) {
            $config[] = [
                "col" => "col-12", 
                "key" => "available_isos[]",
                "name" => "Available ISO Images",
                "description" => "Select which ISO images customers can choose from",
                "type" => "select",
                "multiple" => true,
                "options" => $isoImages,
                "rules" => [],
            ];
        }

        if (in_array($creationMethod, ['container', 'flexible'])) {
            $config[] = [
                "col" => "col-12",
                "key" => "available_containers[]", 
                "name" => "Available Container Templates",
                "description" => "Select which container templates customers can choose from",
                "type" => "select",
                "multiple" => true,
                "options" => $osTemplates,
                "rules" => [],
            ];
        }

        return $config;
    }

    /**
     * Simplified checkout config with optional selections
     *
     * @return array
     */
    public static function setCheckoutConfig(Package $package): array
    {
        $config = [];
        $creationMethod = $package->data('creation_method', 'flexible');

        // Creation method selection (if flexible)
        if ($creationMethod === 'flexible') {
            $options = [];
            if (!empty($package->data('available_templates'))) {
                $options['template'] = 'Clone from VM Template';
            }
            if (!empty($package->data('available_isos'))) {
                $options['iso'] = 'Install from ISO Image';
            }
            if (!empty($package->data('available_containers'))) {
                $options['container'] = 'LXC Container';
            }

            if (count($options) > 1) {
                $config[] = [
                    "col" => "w-full mb-4",
                    "key" => "vm_type",
                    "name" => "VM Type",
                    "description" => "Choose how you want to create your server",
                    "type" => "select",
                    "save_on_change" => true,
                    "options" => $options,
                    "rules" => ['required'],
                ];
            }
        }

        // Template selection
        if (in_array($creationMethod, ['template', 'flexible'])) {
            $templates = collect($package->data('available_templates', []))->mapWithKeys(function ($template) {
                return [$template => $template];
            });
            
            if ($templates->isNotEmpty()) {
                $config[] = [
                    "col" => "w-full mb-4",
                    "key" => "vm_template",
                    "name" => "VM Template", 
                    "description" => "Select a VM template to clone",
                    "type" => "select",
                    "options" => $templates,
                    "rules" => ['required_if:vm_type,template'],
                ];
            }
        }

        // ISO selection  
        if (in_array($creationMethod, ['iso', 'flexible'])) {
            $isos = collect($package->data('available_isos', []))->mapWithKeys(function ($iso) {
                return [$iso => $iso];
            });
            
            if ($isos->isNotEmpty()) {
                $config[] = [
                    "col" => "w-full mb-4",
                    "key" => "iso_image",
                    "name" => "Operating System ISO",
                    "description" => "Select an ISO image to install",
                    "type" => "select", 
                    "options" => $isos,
                    "rules" => ['required_if:vm_type,iso'],
                ];
            }
        }

        // Container selection
        if (in_array($creationMethod, ['container', 'flexible'])) {
            $containers = collect($package->data('available_containers', []))->mapWithKeys(function ($container) {
                return [$container => $container];
            });
            
            if ($containers->isNotEmpty()) {
                $config[] = [
                    "col" => "w-full mb-4",
                    "key" => "container_template",
                    "name" => "Container Template",
                    "description" => "Select a container template",
                    "type" => "select",
                    "options" => $containers,
                    "rules" => ['required_if:vm_type,container'],
                ];
            }
        }

        // Optional resource customization
        if (empty($package->data('cpu_cores'))) {
            $config[] = [
                "col" => "w-full mb-4",
                "key" => "custom_cpu_cores",
                "name" => "CPU Cores",
                "description" => "Number of CPU cores",
                "type" => "select",
                "options" => [
                    1 => '1 Core',
                    2 => '2 Cores', 
                    4 => '4 Cores',
                    8 => '8 Cores',
                ],
                "default_value" => 1,
                "rules" => ['required', 'numeric'],
            ];
        }

        if (empty($package->data('memory_mb'))) {
            $config[] = [
                "col" => "w-full mb-4",
                "key" => "custom_memory_mb",
                "name" => "Memory (MB)",
                "description" => "Amount of RAM memory",
                "type" => "select",
                "options" => [
                    1024 => '1 GB (1024 MB)',
                    2048 => '2 GB (2048 MB)',
                    4096 => '4 GB (4096 MB)',
                    8192 => '8 GB (8192 MB)',
                    16384 => '16 GB (16384 MB)',
                ],
                "default_value" => 2048,
                "rules" => ['required', 'numeric'],
            ];
        }

        if (empty($package->data('disk_gb'))) {
            $config[] = [
                "col" => "w-full mb-4",
                "key" => "custom_disk_gb",
                "name" => "Disk Space (GB)",
                "description" => "Amount of disk storage",
                "type" => "select",
                "options" => [
                    10 => '10 GB',
                    20 => '20 GB',
                    40 => '40 GB', 
                    80 => '80 GB',
                    160 => '160 GB',
                ],
                "default_value" => 20,
                "rules" => ['required', 'numeric'],
            ];
        }

        // Manual IP assignment
        if ($package->data('ip_assignment') === 'manual') {
            $config[] = [
                "col" => "w-full mb-4",
                "key" => "custom_ip",
                "name" => "IP Address",
                "description" => "Enter the IP address for your server",
                "type" => "text",
                "rules" => ['required', 'ip'],
            ];
        }

        // SSH Key input if enabled
        if ($package->data('enable_ssh_keys', 0) == 1) {
            $config[] = [
                "col" => "w-full mb-4",
                "key" => "ssh_public_key",
                "name" => "SSH Public Key (Optional)",
                "description" => "Paste your SSH public key for passwordless login",
                "type" => "textarea",
                "placeholder" => "ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAAB... user@hostname",
                "rules" => ['nullable'],
            ];
        }

        // Custom script input if post-creation script is enabled
        if (!empty($package->data('post_creation_script'))) {
            $config[] = [
                "col" => "w-full mb-4",
                "key" => "custom_script",
                "name" => "Additional Setup Script (Optional)",
                "description" => "Additional commands to run after server creation",
                "type" => "textarea",
                "placeholder" => "#!/bin/bash\n# Your custom commands here\necho 'Server ready!'",
                "rules" => ['nullable'],
            ];
        }

        // Password for containers or VMs with cloud-init
        $config[] = [
            "col" => "w-full mb-4",
            "key" => "root_password",
            "name" => "Root Password",
            "description" => "Password for the root/administrator user",
            "type" => "password",
            "rules" => ['required', 'confirmed', 'min:8'],
        ];

        $config[] = [
            "col" => "w-full mb-4", 
            "key" => "root_password_confirmation",
            "name" => "Confirm Root Password",
            "description" => "Confirm your root password",
            "type" => "password",
            "rules" => ['required'],
        ];

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
                "name" => "Login to Proxmox Panel",
                "color" => "primary",
                "href" => settings('proxmox::hostname'),
                "target" => "_blank",
            ],
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
                'description' => 'Permission to start a Proxmox VM',
            ],
            'proxmox.server.stop' => [
                'description' => 'Permission to stop a Proxmox VM',
            ],
            'proxmox.server.shutdown' => [
                'description' => 'Permission to shutdown a Proxmox VM',
            ],
            'proxmox.server.reboot' => [
                'description' => 'Permission to reboot a Proxmox VM',
            ],
            'proxmox.server.console' => [
                'description' => 'Permission to access VM console',
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
     * Simplified VM creation with automatic IP assignment and improved error handling
     */
    public function create(array $data = [])
    {
        $package = $this->order->package;
        $user = $this->order->user;
        $order = $this->order;

        try {
            // Determine creation method
            $creationMethod = $package->data('creation_method', 'flexible');
            $vmType = $order->option('vm_type', $creationMethod);
            
            if ($vmType === 'container') {
                $response = $this->createContainer();
            } else {
                $response = $this->createVirtualMachine($vmType);
            }

            // Store VM data
            $order->update(['data' => $response]);

            // Create Proxmox user if needed
            if (!$order->hasExternalUser()) {
                $this->createProxmoxUser();
            }

            // Set VM permissions
            $proxmoxUser = $order->getExternalUser();
            self::api()->giveUserAccessToVM($response['vmid'], $proxmoxUser->external_id);

        } catch(\Exception $error) {
            // Check if this is a lock-related error and provide better guidance
            if (strpos($error->getMessage(), "can't lock file") !== false || 
                strpos($error->getMessage(), 'got timeout') !== false) {
                
                ErrorLog('proxmox::create::lock', "[Proxmox] VM creation failed due to lock issue for order {$order->id} - Error: {$error->getMessage()}", 'WARNING');
                
                // Try to provide a more helpful error message
                $vmId = $this->extractVMIdFromError($error->getMessage());
                if ($vmId) {
                    $this->attemptLockRecovery($package->data('node'), $vmId);
                    throw new \Exception("[Proxmox] VM creation failed due to a temporary lock issue with VM {$vmId}. This usually resolves automatically. Please try again in a few minutes.");
                } else {
                    throw new \Exception("[Proxmox] VM creation failed due to a temporary lock issue. Please try again in a few minutes.");
                }
            }
            
            ErrorLog('proxmox::create', "[Proxmox] Failed to create VM for order {$order->id} - Error: {$error->getMessage()}", 'CRITICAL');
            throw new \Exception("[Proxmox] Failed to create VM: " . $error->getMessage());
        }
    }

    /**
     * Extract VM ID from error message
     */
    private function extractVMIdFromError($errorMessage)
    {
        if (preg_match('/lock-(\d+)\.conf/', $errorMessage, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Attempt to recover from lock issues
     */
    private function attemptLockRecovery($node, $vmId)
    {
        try {
            // Try to force unlock the VM
            self::api()->forceUnlockVM($node, $vmId);
            sleep(2); // Give it a moment
        } catch(\Exception $e) {
            // If force unlock fails, just log it
            ErrorLog('proxmox::lock-recovery', "[Proxmox] Failed to force unlock VM {$vmId} - Error: {$e->getMessage()}", 'WARNING');
        }
    }

    /**
     * Create LXC container
     */
    private function createContainer()
    {
        $package = $this->order->package;
        $order = $this->order;

        $containerData = [
            'os_template' => $order->option('container_template'),
            'cores' => $this->getCpuCores(),
            'memory' => $this->getMemory(),
            'disk' => $this->getDiskSpace(),
            'password' => $order->option('root_password'),
            'storage' => $package->data('storage'),
            'ip_config' => $this->getNetworkConfig(),
        ];

        return self::api()->createCT($package->data('node'), $containerData);
    }

    /**
     * Create virtual machine
     */
    private function createVirtualMachine($vmType)
    {
        $package = $this->order->package;
        $order = $this->order;
        $user = $this->order->user;

        $vmData = [
            'cores' => $this->getCpuCores(),
            'sockets' => $this->getCpuSockets(), 
            'memory' => $this->getMemory(),
            'disk' => $this->getDiskSpace(),
            'storage' => $package->data('storage'),
            'name' => "vm-{$user->username}-" . substr(md5($order->id), 0, 8),
            'ip_config' => $this->getNetworkConfig(),
            'bandwidth' => $package->data('bandwidth_mbps'),
        ];

        if ($vmType === 'template' || $order->option('vm_template')) {
            // Clone from template
            $templateId = $order->option('vm_template') ?: $package->data('available_templates')[0] ?? null;
            if (!$templateId) {
                throw new \Exception("[Proxmox] No template specified for VM creation");
            }
            $vmData['clone_template_id'] = $templateId;
            $response = self::api()->createVM($package->data('node'), $vmData);
            
            // Configure cloud-init for template-based VMs
            $cloudInitCredentials = $this->configureCloudInit($response, $order->option('root_password'));
            
            // Store cloud-init credentials in order data
            if ($cloudInitCredentials) {
                $orderData = $order->data ?? [];
                $orderData['cloudinit'] = $cloudInitCredentials;
                $order->update(['data' => array_merge($response, $orderData)]);
            }
        } else {
            // Create from ISO
            $isoImage = $order->option('iso_image') ?: $package->data('available_isos')[0] ?? null;
            if (!$isoImage) {
                throw new \Exception("[Proxmox] No ISO specified for VM creation");
            }
            $vmData['cdrom'] = $isoImage;
            $vmData['ostype'] = $this->detectOSType($isoImage);
            $response = self::api()->createVMFromScratch($package->data('node'), $vmData);
        }

        return $response;
    }

    /**
     * Detect OS type from ISO name for better compatibility
     */
    private function detectOSType($isoName)
    {
        $isoName = strtolower($isoName);
        
        if (strpos($isoName, 'windows') !== false) {
            return 'win10';
        } elseif (strpos($isoName, 'ubuntu') !== false || strpos($isoName, 'debian') !== false) {
            return 'l26';
        } elseif (strpos($isoName, 'centos') !== false || strpos($isoName, 'rhel') !== false || strpos($isoName, 'fedora') !== false) {
            return 'l26';
        } elseif (strpos($isoName, 'freebsd') !== false) {
            return 'other';
        }
        
        return 'l26'; // Default to Linux
    }

    /**
     * Get CPU cores from package or customer selection
     */
    private function getCpuCores()
    {
        $package = $this->order->package;
        return $package->data('cpu_cores') ?: $this->order->option('custom_cpu_cores', 1);
    }

    /**
     * Get CPU sockets from package or default
     */
    private function getCpuSockets()
    {
        $package = $this->order->package;
        return $package->data('cpu_sockets', 1);
    }

    /**
     * Get memory from package or customer selection
     */
    private function getMemory()
    {
        $package = $this->order->package;
        return $package->data('memory_mb') ?: $this->order->option('custom_memory_mb', 2048);
    }

    /**
     * Get disk space from package or customer selection
     */
    private function getDiskSpace()
    {
        $package = $this->order->package;
        return $package->data('disk_gb') ?: $this->order->option('custom_disk_gb', 20);
    }

    /**
     * Get network configuration with automatic IP assignment
     */
    private function getNetworkConfig()
    {
        $package = $this->order->package;
        $ipAssignment = $package->data('ip_assignment', 'dhcp');

        switch ($ipAssignment) {
            case 'dhcp':
                return 'ip=dhcp';
            
            case 'pool':
                $assignedIp = $this->assignIPFromPool();
                return $assignedIp ? "ip={$assignedIp}/24" : 'ip=dhcp';
            
            case 'manual':
                $customIp = $this->order->option('custom_ip');
                return $customIp ? "ip={$customIp}/24" : 'ip=dhcp';
            
            default:
                return 'ip=dhcp';
        }
    }

    /**
     * Assign IP from configured pool
     */
    private function assignIPFromPool()
    {
        $startIp = settings('proxmox::ip_pool_start');
        $endIp = settings('proxmox::ip_pool_end');

        if (!$startIp || !$endIp) {
            return null;
        }

        // Simple IP assignment logic (you may want to enhance this)
        $startLong = ip2long($startIp);
        $endLong = ip2long($endIp);

        // Get used IPs from existing orders (implement based on your needs)
        $usedIps = $this->getUsedIPs();

        for ($ip = $startLong; $ip <= $endLong; $ip++) {
            $ipAddress = long2ip($ip);
            if (!in_array($ipAddress, $usedIps)) {
                return $ipAddress;
            }
        }

        return null; // No available IPs
    }

    /**
     * Get list of already used IPs
     */
    private function getUsedIPs()
    {
        // Implement logic to check used IPs from database
        // This is a placeholder - implement based on your data structure
        return [];
    }

    /**
     * Configure cloud-init for automatic setup
     */
    private function configureCloudInit($vmResponse, $password)
    {
        $package = $this->order->package;
        $order = $this->order;
        $user = $this->order->user;

        // Determine username
        $username = $package->data('default_username', 'admin');
        if ($username === 'root' || empty($username)) {
            $username = 'root';
        }

        $cloudInitConfig = [
            'ciuser' => $username,
            'cipassword' => $password,
            'nameserver' => '8.8.8.8 8.8.4.4',
            'ipconfig0' => $this->getNetworkConfig(),
            'searchdomain' => 'local',
        ];

        // Add SSH key if provided
        $sshKey = $order->option('ssh_public_key');
        if (!empty($sshKey)) {
            $cloudInitConfig['sshkeys'] = urlencode(trim($sshKey));
        }

        // Build user-data script
        $userDataScript = $this->buildUserDataScript();
        if (!empty($userDataScript)) {
            // Create cloud-init user-data
            $userData = "#cloud-config\n";
            $userData .= "users:\n";
            $userData .= "  - name: {$username}\n";
            $userData .= "    sudo: ALL=(ALL) NOPASSWD:ALL\n";
            $userData .= "    shell: /bin/bash\n";
            if (!empty($sshKey)) {
                $userData .= "    ssh_authorized_keys:\n";
                $userData .= "      - " . trim($sshKey) . "\n";
            }
            $userData .= "    lock_passwd: false\n";
            $userData .= "    passwd: " . crypt($password, '$6$rounds=4096$' . substr(md5(time()), 0, 16) . '$') . "\n";
            
            $userData .= "runcmd:\n";
            foreach (explode("\n", $userDataScript) as $line) {
                $line = trim($line);
                if (!empty($line) && substr($line, 0, 1) !== '#') {
                    $userData .= "  - " . $line . "\n";
                }
            }
            
            // Store user-data as snippet
            $snippetPath = self::api()->createCloudInitSnippet($package->data('node'), $vmResponse['vmid'], $userData);
            if ($snippetPath) {
                $cloudInitConfig['cicustom'] = "user={$snippetPath}";
            }
        }

        // Apply cloud-init configuration
        try {
            self::api()->api('put', "/nodes/{$package->data('node')}/qemu/{$vmResponse['vmid']}/config", $cloudInitConfig);
        } catch(\Exception $error) {
            ErrorLog('proxmox::cloudinit', "[Proxmox] Failed to apply cloud-init config for VM {$vmResponse['vmid']} - Error: {$error->getMessage()}", 'WARNING');
        }
        
        return [
            'username' => $username,
            'password' => $password,
            'type' => 'cloud-init',
            'ssh_enabled' => !empty($sshKey),
        ];
    }

    /**
     * Build user-data script combining package and customer scripts
     */
    private function buildUserDataScript()
    {
        $package = $this->order->package;
        $order = $this->order;
        
        $script = '';
        
        // Add package default script
        $packageScript = $package->data('post_creation_script');
        if (!empty($packageScript)) {
            $script .= $packageScript . "\n";
        }
        
        // Add customer custom script
        $customScript = $order->option('custom_script');
        if (!empty($customScript)) {
            $script .= $customScript . "\n";
        }
        
        // Add default system updates if no scripts provided
        if (empty($script)) {
            $script = "apt update\napt upgrade -y\nsystemctl enable ssh\nsystemctl start ssh";
        }
        
        return $script;
    }

    /**
     * Create Proxmox user account
     */
    private function createProxmoxUser()
    {
        $user = $this->order->user;
        $order = $this->order;

        $realm = 'pve';
        $userData = [
            'username' => $user->username . rand(100, 999),
            'email' => $user->email,
            'password' => \Illuminate\Support\Str::random(15),
        ];

        self::api()->createUser($userData, $realm);

        $order->createExternalUser([
            'external_id' => "{$userData['username']}@{$realm}",
            'username' => $userData['username'],
            'password' => $userData['password'],
        ]);

        $this->emailDetails($user, $userData['username'], $userData['password'], $order);
    }

    protected function emailDetails($user, $username, $password, $order = null): void
    {
        $emailContent = "
            Your Proxmox VPS Account Details: <br><br>
            <strong>Proxmox Panel Access:</strong><br>
            - Username: {$username}<br>
            - Password: {$password}<br>
            - Panel: " . settings('proxmox::hostname') . "<br><br>
            
            <strong>VPS Details:</strong><br>
            - VM ID: {$order->data['vmid']}<br>
            - Node: {$order->data['node']}<br>
            - Type: " . ucfirst($order->data['type'] ?? 'qemu') . "<br>
        ";
        
        // Add cloud-init access details if available
        if ($order && isset($order->data['cloudinit'])) {
            $cloudInit = $order->data['cloudinit'];
            if ($cloudInit && isset($cloudInit['username']) && isset($cloudInit['password'])) {
                $emailContent .= "
                    <br><strong>VPS Root Access:</strong><br>
                    - Username: {$cloudInit['username']}<br>
                    - Password: {$cloudInit['password']}<br>
                    - Access: SSH or Console<br>
                ";
                
                if ($cloudInit['ssh_enabled'] ?? false) {
                    $emailContent .= "    - SSH Key: Configured for passwordless login<br>";
                }
            }
        }

        $emailContent .= "<br><em>Your VPS will be ready shortly. You can manage it through the Proxmox panel.</em>";
        
        $user->email([
            'subject' => 'Your Proxmox VPS is Ready!',
            'content' => $emailContent,
            'button' => [
                'name' => 'Access Proxmox Panel',
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
            $this->emailDetails($order->user, $proxmoxUser->username, decrypt($proxmoxUser->password), $order);
        } catch(\Exception $error) {
            ErrorLog('proxmox::resend-password', "[Proxmox] Failed to send {$order->user->email} their Proxmox Password | Received error {$error}");
            return redirect()->back()->withError('Something went wrong, please try again.');
        }

        return redirect()->back()->withSuccess("We have emailed your password to {$order->user->email}");
    }

    /**
     * Start server
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
     * Stop server
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
     * Shutdown server
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
     * Reboot server
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

    /**
     * Get console access - Updated with improved UI and proper authentication
     */
    public function getConsoleAccess(Order $order)
    {
        if (!$order->canViewOrder()) {
            return abort(403, 'You dont have permissions to access this resource');
        }

        try {
            $vmData = $order->data;
            $node = $vmData['node'];
            $vmid = $vmData['vmid'];
            $type = $vmData['type'] ?? 'qemu';

            // Build the console URL
            $proxmoxHost = settings('proxmox::hostname');
            $proxmoxHost = rtrim($proxmoxHost, '/');
            
            // Build proper console URL based on type
            $consoleType = $type === 'lxc' ? 'lxc' : 'kvm';
            $consoleUrl = "{$proxmoxHost}/?console={$consoleType}&novnc=1&vmid={$vmid}&node={$node}";
            
            // Return modern HTML interface
            $html = '
<!DOCTYPE html>
<html>
<head>
    <title>Proxmox Console - VM ' . $vmid . '</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            color: white;
        }
        .console-container {
            text-align: center;
            background: rgba(255, 255, 255, 0.1);
            padding: 40px;
            border-radius: 20px;
            backdrop-filter: blur(10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 100%;
        }
        .console-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.9;
        }
        h1 {
            margin: 0 0 20px 0;
            font-size: 28px;
            font-weight: 300;
        }
        p {
            margin: 0 0 30px 0;
            opacity: 0.8;
            line-height: 1.6;
        }
        .console-btn {
            display: inline-block;
            background: #28a745;
            color: white;
            padding: 15px 30px;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            box-shadow: 0 10px 20px rgba(40, 167, 69, 0.3);
            margin: 10px;
            border: none;
            cursor: pointer;
        }
        .console-btn:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(40, 167, 69, 0.4);
            color: white;
            text-decoration: none;
        }
        .info {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            font-size: 14px;
        }
        .vm-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            text-align: left;
            opacity: 0.9;
        }
        .vm-details span {
            padding: 5px 0;
        }
        .fallback-btn {
            background: #6c757d;
            box-shadow: 0 10px 20px rgba(108, 117, 125, 0.3);
        }
        .fallback-btn:hover {
            background: #5a6268;
            box-shadow: 0 15px 30px rgba(108, 117, 125, 0.4);
        }
        @media (max-width: 600px) {
            .console-container {
                padding: 30px 20px;
                margin: 10px;
            }
            h1 {
                font-size: 24px;
            }
            .console-btn {
                padding: 12px 24px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="console-container">
        <div class="console-icon">🖥️</div>
        <h1>VM Console Access</h1>
        <p>Click the button below to open the console for your Proxmox VM.</p>
        
        <div class="info">
            <div class="vm-details">
                <span><strong>VM ID:</strong></span><span>' . $vmid . '</span>
                <span><strong>Node:</strong></span><span>' . $node . '</span>
                <span><strong>Type:</strong></span><span>' . ucfirst($type) . '</span>
                <span><strong>Status:</strong></span><span>Ready</span>
            </div>
        </div>
        
        <a href="' . $consoleUrl . '" target="_blank" class="console-btn">
            🚀 Open Console (noVNC)
        </a>
        <br>
        <button onclick="history.back()" class="console-btn fallback-btn">
            ← Go Back
        </button>
        
        <script>
            // Auto-focus and provide keyboard shortcuts
            document.addEventListener("keydown", function(e) {
                if (e.ctrlKey && e.key === "Enter") {
                    document.querySelector(".console-btn").click();
                }
                if (e.key === "Escape") {
                    history.back();
                }
            });
        </script>
    </div>
</body>
</html>';
            
            return response($html)->header('Content-Type', 'text/html');
            
        } catch(\Exception $error) {
            return response('
<!DOCTYPE html>
<html>
<head><title>Console Error</title></head>
<body style="margin:0;padding:20px;font-family:Arial;background:#f5f5f5;display:flex;align-items:center;justify-content:center;height:100vh;">
    <div style="background:white;padding:40px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);text-align:center;max-width:400px;">
        <div style="font-size:48px;color:#e74c3c;margin-bottom:20px;">⚠️</div>
        <h1 style="font-size:24px;color:#333;margin-bottom:10px;">Console Access Error</h1>
        <p style="color:#666;margin-bottom:20px;">' . htmlspecialchars($error->getMessage()) . '</p>
        <button onclick="window.location.reload()" style="background:#3498db;color:white;border:none;padding:10px 20px;border-radius:4px;cursor:pointer;font-size:16px;">Try Again</button>
        <br><br>
        <a href="javascript:history.back()" style="color:#666;text-decoration:none;font-size:14px;">← Go Back</a>
    </div>
</body>
</html>', 500)->header('Content-Type', 'text/html');
        }
    }

    /**
     * Get server stats (for AJAX updates)
     */
    public function getServerStats(Order $order)
    {
        if (!$order->canViewOrder()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            $vmData = $order->data;
            $node = $vmData['node'];
            $vmid = $vmData['vmid'];
            $type = $vmData['type'] ?? 'qemu';

            $stats = self::api()->getVMResourceUsage($node, $vmid, $type);
            
            return response()->json(['stats' => $stats]);
            
        } catch(\Exception $error) {
            return response()->json(['error' => 'Failed to get stats'], 500);
        }
    }

    /**
     * Get network information
     */
    public function getNetworkInfo(Order $order)
    {
        if (!$order->canViewOrder()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            $vmData = $order->data;
            $node = $vmData['node'];
            $vmid = $vmData['vmid'];
            $type = $vmData['type'] ?? 'qemu';

            $networkInfo = self::api()->getVMNetworkInterfaces($node, $vmid, $type);
            
            return response()->json(['network' => $networkInfo]);
            
        } catch(\Exception $error) {
            return response()->json(['error' => 'Failed to get network info'], 500);
        }
    }

    /**
     * Force unlock a VM (admin function for resolving lock issues)
     */
    public function forceUnlockVM(Order $order)
    {
        if (!$order->canViewOrder()) {
            return abort(403, 'You dont have permissions to access this resource');
        }

        try {
            $vmData = $order->data;
            $node = $vmData['node'];
            $vmid = $vmData['vmid'];

            // Attempt to force unlock
            self::api()->forceUnlockVM($node, $vmid);
            
            return redirect()->back()->withSuccess("Successfully attempted to unlock VM {$vmid}. The VM should be accessible now.");
            
        } catch(\Exception $error) {
            return redirect()->back()->withError('Failed to unlock VM: ' . $error->getMessage());
        }
    }
}
