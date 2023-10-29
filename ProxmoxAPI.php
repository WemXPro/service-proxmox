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
            $this->exception();
        }

        return $response;
    }

    /**
     * Get the nodes as a Laravel collection
    */
    public function getNodes()
    {
        return collect($this->api('get', '/nodes')->object()->data ?? $this->exception());
    }

    /**
     * Get the storage groups as a Laravel collection
    */
    public function getStorage()
    {
        return collect($this->api('get', '/storage')->object()->data ?? $this->exception());
    }

    /**
     * Get the pool groups as a Laravel collection
    */
    public function getPools()
    {
        return collect($this->api('get', '/pools')->object()->data ?? $this->exception());
    }

    /**
     * Throw an exception
    */
    protected function exception()
    {
        throw new \Exception("[Proxmox] Failed to connect to the API. Please ensure the API details and hostname are valid.");
    }
}
