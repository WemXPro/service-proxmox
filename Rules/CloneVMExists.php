<?php
 
namespace App\Services\Proxmox\Rules;
 
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Services\Proxmox\ProxmoxAPI;

class CloneVMExists implements ValidationRule
{
    public $node;

    public function __construct($node)
    {
        $this->node = $node;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$this->cloneVmExists($value)) {
            $fail("The clone vm {$value} template does not exist in node {$this->node}.");
        }
    }

    /**
     * Determine if the clone vm template exists.
     */
    protected function cloneVmExists(string $value): bool
    {
        $proxmox = new ProxmoxAPI();
        try {
            $proxmox->getVMResourceUsage($this->node, $value);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}