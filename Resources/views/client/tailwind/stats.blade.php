@php
try {
    $api = new \App\Services\Proxmox\ProxmoxAPI;
    $machine = $api->getVMResourceUsage($order->data['node'], $order->data['vmid'], $order->data['type'] ?? 'qemu');
} catch(\Exception $error) {
    $machine = false;
}
@endphp

@if($machine)
<span class="flex items-center text-1xl uppercase font-medium text-gray-900 dark:text-white mb-4">
    <span class="flex w-4 h-4 @if($machine['status'] == 'running') bg-emerald-600 @elseif($machine['status'] == 'stopped') bg-red-600 @else bg-orange-600 @endif  rounded-full mr-1.5 flex-shrink-0"></span>
    {{ $machine['status'] }}
</span>
<div class="flex flex-wrap">
    <div class="w-full md:w-1/3 pr-2 mb-4">
        <div class="p-6 bg-white rounded-lg shadow dark:bg-gray-800 dark:border-gray-700">
            <h5 class="mb-2 text-lg font-bold tracking-tight text-gray-900 dark:text-white">CPU Usage</h5>
            <p class="mb-3 font-normal text-gray-700 dark:text-gray-400">{{ number_format(($machine['cpu'] * 100), 2) }}% / {{ $machine['cpus'] }} CPU(s)</p>
            <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                <div class="bg-blue-600 h-2.5 rounded-full" style="width: {{ number_format(($machine['cpu'] * 100), 2) }}%"></div>
            </div>
            <div class="flex justify-between mt-1">
                <small class="mb-3 font-normal text-gray-700 dark:text-gray-400">{{ number_format(($machine['cpu'] * 100), 2) }}% Used</small>
                <small class="mb-3 font-normal text-gray-700 dark:text-gray-400" id="uptime">Loading...</small>
            </div>
        </div>

    </div>
    <div class="w-full md:w-1/3 pl-2 pr-2 mb-4">

        <div class="p-6 bg-white rounded-lg shadow dark:bg-gray-800 dark:border-gray-700">
            <h5 class="mb-2 text-lg font-bold tracking-tight text-gray-900 dark:text-white">Memory Usage</h5>
            <p class="mb-3 font-normal text-gray-700 dark:text-gray-400">{{ bytesToMB($machine['mem']) }} MB / {{ bytesToMB($machine['maxmem']) }} MB</p>
            <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                <div class="bg-blue-600 h-2.5 rounded-full" style="width: {{ number_format(($machine['mem'] / $machine['maxmem'] * 100), 2) }}%"></div>
            </div>
            <div class="flex mt-1">
                <small class="mb-3 font-normal text-gray-700 dark:text-gray-400">{{ number_format(($machine['mem'] / $machine['maxmem'] * 100), 2) }}% Used</small>
            </div>
        </div>

    </div>
    <div class="w-full md:w-1/3 pl-2 mb-4">

        <div class="p-6 bg-white rounded-lg shadow dark:bg-gray-800 dark:border-gray-700">
            <h5 class="mb-2 text-lg font-bold tracking-tight text-gray-900 dark:text-white">Disk Usage</h5>
            <p class="mb-3 font-normal text-gray-700 dark:text-gray-400">{{ bytesToMB($machine['disk']) }} MB / {{ bytesToMB($machine['maxdisk']) }} MB</p>
            <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                <div class="bg-blue-600 h-2.5 rounded-full" style="width: {{ number_format(($machine['disk'] / $machine['maxdisk'] * 100), 2) }}%"></div>
            </div>
            <div class="flex mt-1">
                <small class="mb-3 font-normal text-gray-700 dark:text-gray-400">{{ number_format(($machine['disk'] / $machine['maxdisk'] * 100), 2) }}% Used</small>
            </div>
        </div>

    </div>
</div>

<script>
    function setUptime() {
        let elapsedTime = {{ $machine['uptime'] ?? 0 }};

        if(elapsedTime == 0) {
            document.getElementById('uptime').innerHTML = 'OFFLINE';
            return;
        }

        const timerInterval = setInterval(() => {
            elapsedTime++;

            // Calculate days, hours, minutes and seconds
            const seconds = parseInt(elapsedTime % 60, 10);
            const minutes = parseInt((elapsedTime / 60) % 60, 10);
            const hours = parseInt((elapsedTime / (60 * 60)) % 24, 10);
            const days = parseInt(elapsedTime / (60 * 60 * 24), 10);

            // Format time string
            const timeString = `${days}d ${hours}h ${minutes}m ${seconds}s`;
            document.getElementById('uptime').innerHTML = timeString;

        }, 1000);
}

// Usage: Start a timer for 10 seconds.
setUptime(10);
</script>
@endif