<?php

namespace App\Services\Proxmox;

use Illuminate\Support\Facades\Http;

class ProxmoxAPI
{
    /**
     * Init connection with API
    */
    public function api($method, $endpoint, $data = [])
    {
        $url = settings('proxmox::hostname') . '/api2/json' . $endpoint;
        
        // Proxmox API expects form-encoded data for POST/PUT requests
        $headers = [
            'Authorization' => 'PVEAPIToken=' . settings('proxmox::token_id') . '=' . settings('encrypted::proxmox::token_secret'),
            'Accept' => 'application/json',
        ];
        
        // For GET requests, send as query parameters
        if (strtolower($method) === 'get') {
            $headers['Content-Type'] = 'application/json';
            $response = Http::withHeaders($headers)->withoutVerifying()->get($url, $data);
        } else {
            // For POST/PUT/DELETE, use form-encoded data
            $response = Http::withHeaders($headers)
                ->asForm()
                ->withoutVerifying()
                ->{$method}($url, $data);
        }

        if ($response->failed()) {
            if ($response->unauthorized() or $response->forbidden()) {
                throw new \Exception("[Proxmox] This action is unauthorized! Confirm that API token has the right permissions");
            }

            // Include response body for better debugging
            $errorBody = $response->body();
            if ($response->serverError()) {
                throw new \Exception("[Proxmox] Internal Server Error: {$response->status()} - {$errorBody}");
            }

            throw new \Exception("[Proxmox] Failed to connect to the API. Status: {$response->status()} - {$errorBody}");
        }

        return $response;
    }

    /**
     * Get the nodes as a Laravel collection
    */
    public function getNodes()
    {
        return collect($this->api('get', '/nodes')->object()->data);
    }

    /**
     * Get the storage of a node groups as a Laravel collection
    */
    public function getNodeStorage($node)
    {
        return collect($this->api('get', "/nodes/{$node}/storage")->object()->data);
    }

    /**
     * Get ISO images from a specific storage
     */
    public function getNodeISOImages($node, $storage)
    {
        $response = $this->api('get', "/nodes/{$node}/storage/{$storage}/content");
        $data = $response->json()['data'] ?? [];

        $contents = collect($data);
        $isoImages = $contents->filter(function($item) {
            return isset($item['content']) && $item['content'] == 'iso';
        })->pluck('volid', 'volid');
        
        return $isoImages->toArray();
    }

    /**
     * Get all ISO images from all storage drives on a node
     */
    public function getAllNodeISOImages($node)
    {
        $allImages = [];
        
        try {
            // Get all storage for the node
            $storages = $this->getNodeStorage($node);
            
            foreach($storages as $storage) {
                try {
                    $response = $this->api('get', "/nodes/{$node}/storage/{$storage->storage}/content");
                    
                    if ($response->successful()) {
                        $data = $response->json()['data'] ?? [];
                        $contents = collect($data);
                        $isoImages = $contents->filter(function($item) {
                            return isset($item['content']) && $item['content'] == 'iso';
                        });
                        
                        foreach($isoImages as $image) {
                            $displayName = basename($image['volid']);
                            $allImages[$image['volid']] = $displayName;
                        }
                    }
                } catch(\Exception $e) {
                    // Skip storage that can't be accessed
                    continue;
                }
            }
        } catch(\Exception $e) {
            // Return empty array if we can't get storage list
            return [];
        }
        
        return $allImages;
    }

    /**
     * Get OS templates for LXC containers
     */
    public function getOSTemplates($node, $storage)
    {
        try {
            $response = $this->api('get', "/nodes/{$node}/storage/{$storage}/content");
            $data = $response->json()['data'] ?? [];
            $contents = collect($data);
            $templates = $contents->filter(function($item) {
                return isset($item['content']) && $item['content'] == 'vztmpl';
            });

            $templateList = [];
            foreach($templates as $template) {
                $displayName = basename($template['volid']);
                $templateList[$template['volid']] = $displayName;
            }

            return $templateList;
        } catch(\Exception $e) {
            return [];
        }
    }

    /**
     * Get the storage groups as a Laravel collection
    */
    public function getStorage()
    {
        return collect($this->api('get', '/storage')->object()->data);
    }

    /**
     * Get the pool groups as a Laravel collection
    */
    public function getPools()
    {
        try {
            return collect($this->api('get', '/pools')->object()->data);
        } catch(\Exception $e) {
            return collect([]);
        }
    }

    /**
     * Create new Proxmox User
     */
    public function createUser(array $user, $realm = 'pam')
    {
        $response = $this->api('post', '/access/users', [
            'userid' => "{$user['username']}@{$realm}",
            'email' => $user['email'],
            'password' => $user['password'],
        ]);
    }

    /**
     * Give a user access to a VM
     */
    public function giveUserAccessToVM($vmid, $user)
    {
        $this->api('put', '/access/acl', [
            'path' => "/vms/{$vmid}",
            'roles' => "PVEVMUser",
            'users' => $user, // in root@pam format
        ]);
    }

    /**
     * Create LXC container with enhanced networking and retry logic
     */
    public function createCT($node, array $data) 
    {
        $response = $this->api('get', '/cluster/nextid');
        $vmid = $response->json()['data'];
        $disk_size = $data['disk'] ?? 8;

        // Build network configuration
        $networkConfig = $this->buildNetworkConfig($data);

        $params = [
            'vmid' => $vmid,
            'ostemplate' => $data['os_template'],
            'cores' => $data['cores'] ?? 1,
            'memory' => $data['memory'] ?? 1024,
            'rootfs' => "{$data['storage']}:{$disk_size}",
            'password' => $data['password'],
            'onboot' => 1,
            'net0' => $networkConfig,
            'unprivileged' => 1,
            'features' => 'nesting=1', // Enable container nesting
        ];

        $response = $this->executeWithRetry(function() use ($node, $params) {
            return $this->api('post', "/nodes/{$node}/lxc", $params);
        }, 3, 3);

        return ['type' => 'lxc', 'node' => $node, 'vmid' => $vmid];
    }

    /**
     * Build network configuration string with bandwidth limiting
     */
    private function buildNetworkConfig($data)
    {
        $bridge = settings('proxmox::default_bridge', 'vmbr0');
        $networkConfig = "name=eth0,bridge={$bridge}";

        // Add IP configuration
        if (isset($data['ip_config']) && !empty($data['ip_config'])) {
            $networkConfig .= ",{$data['ip_config']}";
        } else {
            $networkConfig .= ",ip=dhcp";
        }

        // Add bandwidth limiting if specified
        if (isset($data['bandwidth']) && !empty($data['bandwidth'])) {
            $networkConfig .= ",rate={$data['bandwidth']}";
        }

        return $networkConfig;
    }

    /**
     * Create a new VM by cloning template with enhanced features and lock handling
     */
    public function createVM($node, array $data)
    {
        $response = $this->api('get', '/cluster/nextid');
        $vmid = $response->json()['data'];
        $clone_vmid = $data['clone_template_id'] ?? null;

        if (!$clone_vmid) {
            throw new \Exception("[Proxmox] Clone template ID is required for VM creation");
        }

        // Check if template VM is locked or busy
        $this->waitForVMUnlock($node, $clone_vmid);

        // Step 1: Clone the template with retry logic
        $cloneParams = [
            'newid' => $vmid,
            'target' => $node,
            'storage' => $data['storage'],
            'full' => 1, // Full clone
        ];

        if (isset($data['name'])) {
            $cloneParams['name'] = $data['name'];
        }

        $cloneResponse = $this->executeWithRetry(function() use ($node, $clone_vmid, $cloneParams) {
            return $this->api('post', "/nodes/{$node}/qemu/{$clone_vmid}/clone", $cloneParams);
        }, 3, 5);

        // Wait for clone to complete and VM to be unlocked
        $this->waitForVMUnlock($node, $vmid, 60);

        // Step 2: Configure the cloned VM
        $configParams = [
            'cores' => $data['cores'] ?? 1,
            'sockets' => $data['sockets'] ?? 1,
            'memory' => $data['memory'] ?? 1024,
            'onboot' => 1,
            'agent' => 'enabled=1', // Enable QEMU guest agent
        ];

        // Configure disk
        if (isset($data['disk'])) {
            $configParams['scsi0'] = "{$data['storage']}:{$data['disk']}";
        }

        // Configure CD-ROM
        if (isset($data['cdrom']) && !empty($data['cdrom'])) {
            $configParams['ide2'] = "{$data['cdrom']},media=cdrom";
        }

        // Configure network with bandwidth limiting
        $networkConfig = $this->buildVMNetworkConfig($data);
        $configParams['net0'] = $networkConfig;

        $configResponse = $this->executeWithRetry(function() use ($node, $vmid, $configParams) {
            return $this->api('put', "/nodes/{$node}/qemu/{$vmid}/config", $configParams);
        }, 3, 3);

        return ['type' => 'qemu', 'node' => $node, 'vmid' => $vmid];
    }

    /**
     * Build VM network configuration
     */
    private function buildVMNetworkConfig($data)
    {
        $bridge = settings('proxmox::default_bridge', 'vmbr0');
        $networkConfig = "virtio,bridge={$bridge}";

        // Add bandwidth limiting if specified
        if (isset($data['bandwidth']) && !empty($data['bandwidth'])) {
            $networkConfig .= ",rate={$data['bandwidth']}";
        }

        return $networkConfig;
    }

    /**
     * Create a new VM from scratch with enhanced configuration and retry logic
     */
    public function createVMFromScratch($node, array $data)
    {
        $response = $this->api('get', '/cluster/nextid');
        $vmid = $response->json()['data'];

        $params = [
            'vmid' => $vmid,
            'name' => $data['name'] ?? "vm-{$vmid}",
            'ostype' => $data['ostype'] ?? 'l26', // Linux 2.6/3.x
            'cores' => $data['cores'] ?? 1,
            'sockets' => $data['sockets'] ?? 1,
            'memory' => $data['memory'] ?? 1024,
            'onboot' => 1,
            'agent' => 'enabled=1',
            'bios' => 'ovmf', // Use UEFI for better compatibility
            'machine' => 'q35', // Use modern machine type
        ];

        // Configure storage/disk
        if (isset($data['disk'])) {
            $params['scsi0'] = "{$data['storage']}:{$data['disk']},iothread=1";
        }

        // Configure CD-ROM/ISO
        if (isset($data['cdrom']) && !empty($data['cdrom'])) {
            $params['ide2'] = "{$data['cdrom']},media=cdrom";
        }

        // Configure network
        $params['net0'] = $this->buildVMNetworkConfig($data);

        // Add EFI disk for UEFI
        $params['efidisk0'] = "{$data['storage']}:1,format=raw,efitype=4m,pre-enrolled-keys=1";

        $response = $this->executeWithRetry(function() use ($node, $params) {
            return $this->api('post', "/nodes/{$node}/qemu", $params);
        }, 3, 5);

        return ['type' => 'qemu', 'node' => $node, 'vmid' => $vmid];
    }

    /**
     * Get resource usage and status for a specific VM
     */
    public function getVMResourceUsage($node, $vmid, $type = 'qemu')
    {
        $response = $this->api('get', "/nodes/{$node}/{$type}/{$vmid}/status/current");
        
        return $response->json()['data'];
    }

    /**
     * Get network interfaces for a VM
     */
    public function getVMNetworkInterfaces($node, $vmid, $type = 'qemu')
    {
        try {
            if ($type === 'qemu') {
                $response = $this->api('get', "/nodes/{$node}/qemu/{$vmid}/agent/network-get-interfaces");
            } else {
                $response = $this->api('get', "/nodes/{$node}/lxc/{$vmid}/interfaces");
            }
            
            return $response->json()['data'] ?? [];
        } catch(\Exception $e) {
            return [];
        }
    }

    /**
     * Get console access URL with proper noVNC ticket
     */
    public function getConsoleAccess($node, $vmid, $type = 'qemu')
    {
        try {
            if ($type === 'qemu') {
                // For QEMU VMs, get VNC proxy ticket
                $response = $this->api('post', "/nodes/{$node}/qemu/{$vmid}/vncproxy", [
                    'websocket' => 1,
                    'generate-password' => 0
                ]);
            } else {
                // For LXC containers, get VNC proxy ticket  
                $response = $this->api('post', "/nodes/{$node}/lxc/{$vmid}/vncproxy", [
                    'websocket' => 1,
                    'width' => 1024,
                    'height' => 768
                ]);
            }
            
            if ($response->successful()) {
                $data = $response->json()['data'];
                return [
                    'port' => $data['port'],
                    'ticket' => $data['ticket'],
                    'upid' => $data['upid'] ?? null,
                    'cert' => $data['cert'] ?? null
                ];
            }
            
            return null;
        } catch(\Exception $e) {
            return null;
        }
    }

    /**
     * Get serial console access (alternative to VNC)
     */
    public function getSerialConsoleAccess($node, $vmid, $type = 'qemu')
    {
        try {
            if ($type === 'qemu') {
                $response = $this->api('post', "/nodes/{$node}/qemu/{$vmid}/termproxy", [
                    'serial' => 'serial0'
                ]);
            } else {
                $response = $this->api('post', "/nodes/{$node}/lxc/{$vmid}/termproxy");
            }
            
            if ($response->successful()) {
                $data = $response->json()['data'];
                return [
                    'port' => $data['port'],
                    'ticket' => $data['ticket'],
                    'upid' => $data['upid'] ?? null
                ];
            }
            
            return null;
        } catch(\Exception $e) {
            return null;
        }
    }

    /**
     * Build proper noVNC URL with authentication
     */
    public function buildNoVNCUrl($node, $vmid, $consoleData, $type = 'qemu')
    {
        try {
            $hostname = settings('proxmox::hostname');
            $hostname = rtrim($hostname, '/');
            
            if ($type === 'qemu') {
                $params = [
                    'console' => 'kvm',
                    'novnc' => '1',
                    'node' => $node,
                    'vmid' => $vmid,
                    'port' => $consoleData['port'] ?? '',
                    'ticket' => $consoleData['ticket'] ?? ''
                ];
            } else {
                $params = [
                    'console' => 'lxc',
                    'novnc' => '1', 
                    'node' => $node,
                    'vmid' => $vmid,
                    'port' => $consoleData['port'] ?? '',
                    'ticket' => $consoleData['ticket'] ?? ''
                ];
            }
            
            // Filter out empty parameters
            $params = array_filter($params, function($value) {
                return !empty($value);
            });
            
            return $hostname . '/?' . http_build_query($params);
            
        } catch(\Exception $e) {
            return null;
        }
    }

    /**
     * Suspend a VM
     */
    public function suspendVM($node, $vmid)
    {
        if($node == 'terminated') {
            throw new \Exception("[Proxmox] The server for this order was terminated");
        }
        
        return $this->api('post', "/nodes/{$node}/qemu/{$vmid}/status/suspend", [
            'node' => $node,
            'vmid' => $vmid,
            'todisk' => 1,
        ]);
    }

    /**
     * Unsuspend a VM
     */
    public function unsuspendVM($node, $vmid)
    {
        if($node == 'terminated') {
            throw new \Exception("[Proxmox] The server for this order was terminated");
        }

        return $this->api('post', "/nodes/{$node}/qemu/{$vmid}/status/resume", [
            'node' => $node,
            'vmid' => $vmid,
        ]);
    }

    /**
     * Suspend LXC container
     */
    public function suspendCT($node, $vmid)
    {
        if($node == 'terminated') {
            throw new \Exception("[Proxmox] The server for this order was terminated");
        }
        
        try {
            return $this->api('post', "/nodes/{$node}/lxc/{$vmid}/status/suspend", [
                'node' => $node,
                'vmid' => $vmid,
            ]);
        } catch(\Exception $error) {
            ErrorLog('proxmox::suspend::lxc', "[Proxmox] Failed to suspend LXC {$vmid} in node {$node} - Error: {$error->getMessage()}", 'CRITICAL');
        }
    }

    /**
     * Unsuspend LXC container
     */
    public function unsuspendCT($node, $vmid)
    {
        if($node == 'terminated') {
            throw new \Exception("[Proxmox] The server for this order was terminated");
        }
        
        try {
            return $this->api('post', "/nodes/{$node}/lxc/{$vmid}/status/resume", [
                'node' => $node,
                'vmid' => $vmid,
            ]);
        } catch(\Exception $error) {
            ErrorLog('proxmox::unsuspend::lxc', "[Proxmox] Failed to unsuspend LXC {$vmid} in node {$node} - Error: {$error->getMessage()}", 'CRITICAL');
        }
    }

    /**
     * Terminate a VM with better cleanup
     */
    public function terminateVM($node, $vmid)
    {
        if($node == 'terminated') {
            throw new \Exception("[Proxmox] The server for this order was terminated");
        }

        try {
            // Gracefully stop the VM first
            $this->api('post', "/nodes/{$node}/qemu/{$vmid}/status/stop", ['timeout' => 60]);
            sleep(10);

            // Delete the VM
            $this->api('delete', "/nodes/{$node}/qemu/{$vmid}", ['purge' => 1]);
            
        } catch(\Exception $error) {
            // Force shutdown if graceful stop fails
            try {
                $this->api('post', "/nodes/{$node}/qemu/{$vmid}/status/shutdown", ['forceStop' => 1]);
                sleep(30);
                $this->api('delete', "/nodes/{$node}/qemu/{$vmid}", ['purge' => 1]);
            } catch(\Exception $secondError) {
                ErrorLog('proxmox::terminate::vm', "[Proxmox] Failed to terminate VM {$vmid} in node {$node} - Error: {$secondError->getMessage()}", 'CRITICAL');
                throw $secondError;
            }
        }
    }

    /**
     * Terminate LXC container with better cleanup
     */
    public function terminateCT($node, $vmid)
    {
        if($node == 'terminated') {
            throw new \Exception("[Proxmox] The server for this order was terminated");
        }

        try {
            // Stop the container gracefully
            $this->api('post', "/nodes/{$node}/lxc/{$vmid}/status/stop");
            sleep(5);

            // Delete the container
            $this->api('delete', "/nodes/{$node}/lxc/{$vmid}", ['purge' => 1]);
            
        } catch(\Exception $error) {
            // Force shutdown if graceful stop fails
            try {
                $this->api('post', "/nodes/{$node}/lxc/{$vmid}/status/shutdown", ['forceStop' => 1, 'timeout' => 30]);
                sleep(30);
                $this->api('delete', "/nodes/{$node}/lxc/{$vmid}", ['purge' => 1]);
            } catch(\Exception $secondError) {
                ErrorLog('proxmox::terminate::lxc', "[Proxmox] Failed to terminate LXC {$vmid} in node {$node} - Error: {$secondError->getMessage()}", 'CRITICAL');
                throw $secondError;
            }
        }
    }

    /**
     * Start VM/Container
     */
    public function startVM($node, $vmid, $type = 'qemu') 
    {
        $this->api('post', "/nodes/{$node}/{$type}/{$vmid}/status/start", [
            'node' => $node,
            'vmid' => $vmid,
        ]);
    }
    
    /**
     * Stop VM/Container
     */
    public function stopVM($node, $vmid, $type = 'qemu') 
    {
        $this->api('post', "/nodes/{$node}/{$type}/{$vmid}/status/stop", [
            'node' => $node,
            'vmid' => $vmid,
        ]);
    }

    /**
     * Shutdown VM/Container
     */
    public function shutdownVM($node, $vmid, $type = 'qemu') 
    {
        $this->api('post', "/nodes/{$node}/{$type}/{$vmid}/status/shutdown", [
            'node' => $node,
            'vmid' => $vmid,
        ]);
    }

    /**
     * Reboot VM/Container
     */
    public function rebootVM($node, $vmid, $type = 'qemu') 
    {
        $this->api('post', "/nodes/{$node}/{$type}/{$vmid}/status/reboot", [
            'node' => $node,
            'vmid' => $vmid,
        ]);
    }

    /**
     * Get all VMs that can be used for cloning
     */
    public function getCloneableVMs($node)
    {
        try {
            $vms = [];
            
            // Get QEMU VMs
            $qemuResponse = $this->api('get', "/nodes/{$node}/qemu");
            if ($qemuResponse->successful()) {
                $data = $qemuResponse->json()['data'] ?? [];
                $qemuVMs = collect($data);
                foreach($qemuVMs as $vm) {
                    // Exclude templates from cloneable VMs
                    if (!isset($vm['template']) || $vm['template'] != 1) {
                        $status = $vm['status'] ?? 'unknown';
                        $name = $vm['name'] ?? "VM-{$vm['vmid']}";
                        $displayName = "{$vm['vmid']} - {$name} ({$status})";
                        $vms[$vm['vmid']] = $displayName;
                    }
                }
            }
            
            return $vms;
        } catch(\Exception $e) {
            return [];
        }
    }

    /**
     * Get VM templates that can be used for cloning
     */
    public function getVMTemplates($node)
    {
        try {
            $templates = [];
            
            // Get QEMU VMs and filter templates
            $qemuResponse = $this->api('get', "/nodes/{$node}/qemu");
            if ($qemuResponse->successful()) {
                $data = $qemuResponse->json()['data'] ?? [];
                $qemuVMs = collect($data);
                $templateVMs = $qemuVMs->filter(function($vm) {
                    return isset($vm['template']) && $vm['template'] == 1;
                });
                
                foreach($templateVMs as $template) {
                    $name = $template['name'] ?? "Template-{$template['vmid']}";
                    $displayName = "{$template['vmid']} - {$name}";
                    $templates[$template['vmid']] = $displayName;
                }
            }
            
            return $templates;
        } catch(\Exception $e) {
            return [];
        }
    }

    /**
     * Create storage snippet for cloud-init
     */
    public function createCloudInitSnippet($node, $vmid, $content)
    {
        try {
            $snippetContent = base64_encode($content);
            
            $this->api('post', "/nodes/{$node}/storage/local/upload", [
                'content' => 'snippets',
                'filename' => "user-data-{$vmid}.yml",
                'data' => $snippetContent,
            ]);
            
            return "local:snippets/user-data-{$vmid}.yml";
        } catch(\Exception $e) {
            ErrorLog('proxmox::snippet', "[Proxmox] Failed to create cloud-init snippet for VM {$vmid} - Error: {$e->getMessage()}", 'WARNING');
            return null;
        }
    }

    /**
     * Update VM bandwidth limiting
     */
    public function updateVMBandwidth($node, $vmid, $bandwidthMbps)
    {
        try {
            // Get current network configuration
            $response = $this->api('get', "/nodes/{$node}/qemu/{$vmid}/config");
            $config = $response->json()['data'];
            
            if (isset($config['net0'])) {
                $currentNet = $config['net0'];
                
                // Remove existing rate limit if present
                $currentNet = preg_replace('/,rate=\d+/', '', $currentNet);
                
                // Add new rate limit
                if ($bandwidthMbps > 0) {
                    $currentNet .= ",rate={$bandwidthMbps}";
                }
                
                // Update configuration
                $this->api('put', "/nodes/{$node}/qemu/{$vmid}/config", [
                    'net0' => $currentNet
                ]);
            }
        } catch(\Exception $e) {
            ErrorLog('proxmox::bandwidth', "[Proxmox] Failed to update bandwidth for VM {$vmid} - Error: {$e->getMessage()}", 'WARNING');
        }
    }

    /**
     * Get VM configuration
     */
    public function getVMConfig($node, $vmid, $type = 'qemu')
    {
        try {
            $response = $this->api('get', "/nodes/{$node}/{$type}/{$vmid}/config");
            return $response->json()['data'] ?? [];
        } catch(\Exception $e) {
            return [];
        }
    }

    /**
     * Wait for VM to be unlocked
     */
    private function waitForVMUnlock($node, $vmid, $maxWaitSeconds = 30)
    {
        $startTime = time();
        
        while ((time() - $startTime) < $maxWaitSeconds) {
            try {
                // Try to get VM status to check if it's locked
                $response = $this->api('get', "/nodes/{$node}/qemu/{$vmid}/status/current");
                
                if ($response->successful()) {
                    $data = $response->json()['data'] ?? [];
                    
                    // Check if VM is locked
                    if (!isset($data['lock']) || empty($data['lock'])) {
                        return true; // VM is unlocked
                    }
                }
            } catch(\Exception $e) {
                // If we can't get status, VM might not exist yet (which is fine for new VMs)
                if (strpos($e->getMessage(), 'does not exist') !== false) {
                    return true;
                }
            }
            
            sleep(2); // Wait 2 seconds before checking again
        }
        
        throw new \Exception("[Proxmox] VM {$vmid} is still locked after {$maxWaitSeconds} seconds");
    }

    /**
     * Execute a function with retry logic for lock issues
     */
    private function executeWithRetry(callable $function, int $maxRetries = 3, int $delaySeconds = 5)
    {
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $function();
            } catch (\Exception $e) {
                $lastException = $e;
                
                // Check if this is a lock-related error
                if (strpos($e->getMessage(), "can't lock file") !== false || 
                    strpos($e->getMessage(), 'got timeout') !== false ||
                    strpos($e->getMessage(), 'VM is locked') !== false) {
                    
                    if ($attempt < $maxRetries) {
                        // Wait before retrying
                        sleep($delaySeconds);
                        continue;
                    }
                }
                
                // If it's not a lock error or we've exhausted retries, throw the exception
                throw $e;
            }
        }
        
        throw $lastException;
    }

    /**
     * Force unlock a VM (use with caution)
     */
    public function forceUnlockVM($node, $vmid)
    {
        try {
            return $this->api('delete', "/nodes/{$node}/qemu/{$vmid}/status/unlock");
        } catch(\Exception $e) {
            // Ignore errors if unlock fails
            return null;
        }
    }
}