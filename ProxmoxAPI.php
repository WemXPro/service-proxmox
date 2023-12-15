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
        $response = Http::withHeaders([
                    'Authorization' => 'PVEAPIToken=' . settings('proxmox::token_id') . '=' . settings('encrypted::proxmox::token_secret'),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ])->withoutVerifying()->$method($url, $data);

        if ($response->failed()) {
            if ($response->unauthorized() or $response->forbidden()) {
                throw new \Exception("[Proxmox] This action is unauthorized! Confirm that API token has the right permissions");
            }

            dd($response, $response->json());
            if ($response->serverError()) {
                throw new \Exception("[Proxmox] Internal Server Error: {$response->status()}");
            }

            throw new \Exception("[Proxmox] Failed to connect to the API. Ensure the API details and hostname are valid.");
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
     * Get the storage of a node groups as a Laravel collection
    */
    public function getNodeISOImages($node, $storage)
    {
        $response = $this->api('get', "/nodes/{$node}/storage/{$storage}/content")['data'];

        $contents = collect($response);
        $isoImages = $contents->filter(function($item) {
            return $item['content'] == 'iso';
        })->pluck('volid', 'volid');  // Use ISO volid as both key and value for select options
    
        return $isoImages->toArray();
    }

    // add a method to get the OS templates from proxmox api
    public function getOSTemplates($node, $storage)
    {
        $response = $this->api('get', "/nodes/{$node}/storage/{$storage}/content")['data'];
        $contents = collect($response);
        $isoImages = $contents->filter(function($item) {
            return $item['content'] == 'vztmpl';
        })->pluck('volid', 'volid');  // Use ISO volid as both key and value for select options
    
        return $isoImages->toArray();
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
        return collect($this->api('get', '/pools')->object()->data);
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
     * Give a user access to a VM
     */
    public function changeUserPassword($user_id, $newPassword)
    {
        $this->api('put', '/access/password', [
            'userid' => $user_id,
            'password' => $newPassword,
        ]);
    }

    public function createCT($node, array $data) 
    {
        $vmid = $this->api('get', '/cluster/nextid')['data'];
        $disk_size = $data['disk'] ?? 8;

        $response = $this->api('post', "/nodes/{$node}/lxc", [
            'vmid' => $vmid,
            'ostemplate' => $data['os_template'],
            'cores' => $data['cores'] ?? 1,
            // 'sockets' => $data['sockets'] ?? 1,
            'memory' => $data['memory'] ?? 1024,
            'rootfs' => "{$data['storage']}:{$disk_size}",
            'password' => $data['password'],
            'onboot' => 1,
        ]);

        return ['type' => 'lxc', 'node' => $node, 'vmid' => $vmid];
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
            ErrorLog('proxmox::suspend::vm', "[Proxmox] We failed to suspend VM {$vmid} in node {$node} - Received error {$error}", 'CRITICAL');
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
            ErrorLog('proxmox::unsuspend::vm', "[Proxmox] We failed to unsuspend VM {$vmid} in node {$node} - Received error {$error}", 'CRITICAL');
        }
    }

    /**
     * Terminate LXC container
     */
    public function terminateCT($node, $vmid)
    {
        if($node == 'terminated') {
            throw new \Exception("[Proxmox] The server for this order was terminated");
        }

        // attempt to gracefully stop a VM
        $this->api('post', "/nodes/{$node}/lxc/{$vmid}/status/stop", [
            'node' => $node,
            'vmid' => $vmid,
        ]);

        sleep(10);

        try {
            // attempt to delete the VM
            $this->api('delete', "/nodes/{$node}/lxc/{$vmid}", ['node' => $node, 'vmid' => $vmid]);
            return;
        } catch(\Exception $error) {
            // if delete fails, attempt to shutdown forcefully
            $this->api('post', "/nodes/{$node}/lxc/{$vmid}/status/shutdown", ['node' => $node, 'vmid' => $vmid, 'forceStop' => 1, 'timeout' => 30]);
        }

        sleep(30);

        try {
            $this->api('delete', "/nodes/{$node}/lxc/{$vmid}", ['node' => $node, 'vmid' => $vmid]);
        } catch(\Exception $error) {
            ErrorLog('proxmox::terminate::vm', "[Proxmox] We failed to terminate VM {$vmid} in node {$node} - Received error {$error->getMessage()}", 'CRITICAL');
        }
    }

    /**
     * Create a new VM.
     */
    public function createVM($node, array $data)
    {
        $vmid = $this->api('get', '/cluster/nextid')['data'];
        $clone_vmid = $data['clone_template_id'] ?? null;

        // clone an existing VM
        $response = $this->api('post', "/nodes/{$node}/qemu/{$clone_vmid}/clone", [
            'vmid' => $clone_vmid,
            'node' => $node,
            'newid' => $vmid,
            // 'name' => $data['name'],
            // 'description' => $data['description'],
            'target' => $node,
            'storage' => $data['storage'],
            'full' => 1,
        ]);

        // update the cloned vm 
        $response = $this->api('put', "/nodes/{$node}/qemu/{$vmid}/config", [
            'node' => $node,
            'vmid' => $vmid,
            'cores' => $data['cores'] ?? 1,
            'sockets' => $data['sockets'] ?? 1,
            'memory' => $data['memory'] ?? 1024,
            'scsi0' => "{$data['storage']}:{$data['disk']}",
            // 'ide2' => "{$data['storage']}:iso/{$data['iso']},media=cdrom",
            'onboot' => 1,
        ]);

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
     * Terminate a VM
     */
    public function terminateVM($node, $vmid)
    {
        if($node == 'terminated') {
            throw new \Exception("[Proxmox] The server for this order was terminated");
        }

        // attempt to gracefully stop a VM
        $this->api('post', "/nodes/{$node}/qemu/{$vmid}/status/stop", ['timeout' => 10]);

        sleep(10);

        try {
            // attempt to delete the VM
            $this->api('delete', "/nodes/{$node}/qemu/{$vmid}");
            return;
        } catch(\Exception $error) {
            // if delete fails, attempt to shutdown forcefully
            $this->api('post', "/nodes/{$node}/qemu/{$vmid}/status/shutdown", ['forceStop' => 1]);
        }

        sleep(120);

        try {
            $this->api('delete', "/nodes/{$node}/qemu/{$vmid}");
        } catch(\Exception $error) {
            ErrorLog('proxmox::terminate::vm', "[Proxmox] We failed to terminate VM {$vmid} in node {$node} - Received error {$error->getMessage()}", 'CRITICAL');
        }
    }

    /**
     * Attempt to start a VM
     */
    public function startVM($node, $vmid, $type = 'qemu') 
    {
        $this->api('post', "/nodes/{$node}/{$type}/{$vmid}/status/start", [
            'node' => $node,
            'vmid' => $vmid,
        ]);
    }
    
    /**
     * Attempt to stop a VM
     */
    public function stopVM($node, $vmid, $type = 'qemu') 
    {
        $this->api('post', "/nodes/{$node}/{$type}/{$vmid}/status/stop", [
            'node' => $node,
            'vmid' => $vmid,
        ]);
    }

    /**
     * Attempt to shutdown a VM
     */
    public function shutdownVM($node, $vmid, $type = 'qemu') 
    {
        $this->api('post', "/nodes/{$node}/{$type}/{$vmid}/status/shutdown", [
            'node' => $node,
            'vmid' => $vmid,
        ]);
    }

    /**
     * Attempt to reboot a VM
     */
    public function rebootVM($node, $vmid, $type = 'qemu') 
    {
        $this->api('post', "/nodes/{$node}/{$type}/{$vmid}/status/reboot", [
            'node' => $node,
            'vmid' => $vmid,
        ]);
    }
}
