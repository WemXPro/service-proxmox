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
        $url = 'https://' . settings('proxmox::hostname') . ':' . settings('proxmox::port') . '/api2/json' . $endpoint;
        $response = Http::withHeaders([
            'Authorization' => 'PVEAPIToken=' . settings('proxmox::token_id') . '=' . settings('encrypted::proxmox::token_secret'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ])->$method($url, $data);
        
        if($response->failed())
        {
            dd($response);
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

    /**
     * Create a new VM.
     */
    public function createVM($node, array $data)
    {
        $vmid = $this->api('get', '/cluster/nextid')['data'];
        $ide2 = ($data['vm_cdrom'] ?? '' == 'iso') ? ['ide2' => "{$data['iso_image']},media=cdrom"] : [];
    
        $response = $this->api('post', "/nodes/{$node}/qemu", array_merge([
            'vmid' => $vmid,
            'cores' => $data['cores'] ?? 1,
            'sockets' => $data['sockets'] ?? 1,
            'memory' => $data['memory'] ?? 1024,
            'scsi0' => "local-lvm:{$data['disk']}",
            'ostype' => $data['os_type'] ?? 'l26',
        ], $ide2));

        return ['node' => $node, 'vmid' => $vmid];
    }

    /**
     * Get resource usage and status for a specific VM
     */
    public function getVMResourceUsage($node, $vmid)
    {
        $response = $this->api('get', "/nodes/{$node}/qemu/{$vmid}/status/current");
        
        return $response->json()['data'];
    }

    /**
     * Suspend a VM
     */
    public function suspendVM($node, $vmid)
    {
        return $this->api('post', "/nodes/{$node}/qemu/{$vmid}/status/suspend");
    }

    /**
     * Unsuspend a VM
     */
    public function unsuspendVM($node, $vmid)
    {
        return $this->api('post', "/nodes/{$node}/qemu/{$vmid}/status/resume");
    }

    /**
     * Terminate a VM
     */
    public function terminateVM($node, $vmid)
    {
        return $this->api('delete', "/nodes/{$node}/qemu/{$vmid}");
    }
}
