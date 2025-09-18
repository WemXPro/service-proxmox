@php
try {
    $api = new \App\Services\Proxmox\ProxmoxAPI;
    $machine = $api->getVMResourceUsage($order->data['node'], $order->data['vmid'], $order->data['type'] ?? 'qemu');
    $vmConfig = $api->getVMConfig($order->data['node'], $order->data['vmid'], $order->data['type'] ?? 'qemu');
    $networkInterfaces = $api->getVMNetworkInterfaces($order->data['node'], $order->data['vmid'], $order->data['type'] ?? 'qemu');
} catch(\Exception $error) {
    $machine = false;
    $vmConfig = [];
    $networkInterfaces = [];
}
@endphp

<meta name="csrf-token" content="{{ csrf_token() }}">

<div class="max-w-7xl mx-auto">
    <!-- Header Section -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
        <div class="p-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center space-x-4">
                    @if($machine)
                        <div class="flex items-center">
                            <span class="flex w-3 h-3 @if($machine['status'] == 'running') bg-emerald-500 @elseif($machine['status'] == 'stopped') bg-red-500 @else bg-orange-500 @endif rounded-full mr-2"></span>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300 uppercase">{{ $machine['status'] }}</span>
                        </div>
                    @endif
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                            {{ $order->data['type'] == 'lxc' ? 'Container' : 'Virtual Machine' }} #{{ $order->data['vmid'] }}
                        </h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Node: {{ $order->data['node'] }}</p>
                    </div>
                </div>
                <div class="mt-4 sm:mt-0">
                    <a href="{{ settings('proxmox::hostname') }}" target="_blank" 
                       class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                        </svg>
                        Open Proxmox Panel
                    </a>
                </div>
            </div>
        </div>

        <!-- Console Section -->
        <div>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Console Access</h2>
                        <button onclick="openConsole()" 
                                class="inline-flex items-center px-3 py-2 bg-gray-600 hover:bg-gray-700 text-white text-sm font-medium rounded-md transition-colors">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                            </svg>
                            Open Console
                        </button>
                    </div>
                    
                    <div class="bg-black rounded-lg p-4 h-96 overflow-auto" id="console-container">
                        <div class="text-green-400 font-mono text-sm">
                            <div id="console-status" class="mb-2">Console: Ready to connect...</div>
                            <div id="console-output" class="whitespace-pre-wrap"></div>
                        </div>
                    </div>
                    
                    <div class="mt-4 flex items-center space-x-2">
                        <button onclick="connectConsole()" 
                                class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm rounded-md transition-colors">
                            Connect
                        </button>
                        <button onclick="disconnectConsole()" 
                                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm rounded-md transition-colors">
                            Disconnect
                        </button>
                        <button onclick="clearConsole()" 
                                class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white text-sm rounded-md transition-colors">
                            Clear
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reinstall Modal -->
    <div id="reinstallModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white dark:bg-gray-800">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white" id="modalTitle">Reinstall Server</h3>
                    <button onclick="closeReinstallModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <div id="modalContent">
                    <!-- Dynamic content will be loaded here -->
                </div>
                
                <div class="flex items-center justify-end space-x-3 mt-6">
                    <button onclick="closeReinstallModal()" 
                            class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-700 text-sm rounded-md transition-colors">
                        Cancel
                    </button>
                    <button onclick="confirmReinstall()" 
                            class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm rounded-md transition-colors">
                        Reinstall
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Control Panel -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Server Controls</h2>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <a href="{{ route('proxmox.server.start', $order->id) }}" 
                   class="flex flex-col items-center justify-center p-4 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-900/20 dark:hover:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 rounded-lg transition-colors group">
                    <svg class="w-8 h-8 text-emerald-600 dark:text-emerald-400 mb-2 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h1m4 0h1m-6 4h1m4 0h1m2-10V4a1 1 0 00-1-1H9a1 1 0 00-1 1v1M8 7V4a1 1 0 011-1h6a1 1 0 011 1v3"></path>
                    </svg>
                    <span class="text-sm font-medium text-emerald-700 dark:text-emerald-300">Start</span>
                </a>

                <a href="{{ route('proxmox.server.stop', $order->id) }}" 
                   class="flex flex-col items-center justify-center p-4 bg-red-50 hover:bg-red-100 dark:bg-red-900/20 dark:hover:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-lg transition-colors group">
                    <svg class="w-8 h-8 text-red-600 dark:text-red-400 mb-2 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 10h6v4H9z"></path>
                    </svg>
                    <span class="text-sm font-medium text-red-700 dark:text-red-300">Stop</span>
                </a>

                <a href="{{ route('proxmox.server.shutdown', $order->id) }}" 
                   class="flex flex-col items-center justify-center p-4 bg-orange-50 hover:bg-orange-100 dark:bg-orange-900/20 dark:hover:bg-orange-900/30 border border-orange-200 dark:border-orange-800 rounded-lg transition-colors group">
                    <svg class="w-8 h-8 text-orange-600 dark:text-orange-400 mb-2 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                    </svg>
                    <span class="text-sm font-medium text-orange-700 dark:text-orange-300">Shutdown</span>
                </a>

                <a href="{{ route('proxmox.server.reboot', $order->id) }}" 
                   class="flex flex-col items-center justify-center p-4 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:hover:bg-blue-900/30 border border-blue-200 dark:border-blue-800 rounded-lg transition-colors group">
                    <svg class="w-8 h-8 text-blue-600 dark:text-blue-400 mb-2 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    <span class="text-sm font-medium text-blue-700 dark:text-blue-300">Reboot</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Reinstall Section -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Reinstall Server</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Choose how you want to reinstall your server. This will wipe all data!</p>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Reset to Default -->
                <button onclick="showReinstallModal('reset')" 
                        class="flex flex-col items-center justify-center p-4 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:hover:bg-blue-900/30 border border-blue-200 dark:border-blue-800 rounded-lg transition-colors group">
                    <svg class="w-8 h-8 text-blue-600 dark:text-blue-400 mb-2 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    <span class="text-sm font-medium text-blue-700 dark:text-blue-300">Reset</span>
                    <span class="text-xs text-blue-600 dark:text-blue-400 text-center">Default Config</span>
                </button>

                <!-- Clone Template -->
                <button onclick="showReinstallModal('template')" 
                        class="flex flex-col items-center justify-center p-4 bg-green-50 hover:bg-green-100 dark:bg-green-900/20 dark:hover:bg-green-900/30 border border-green-200 dark:border-green-800 rounded-lg transition-colors group">
                    <svg class="w-8 h-8 text-green-600 dark:text-green-400 mb-2 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                    <span class="text-sm font-medium text-green-700 dark:text-green-300">Template</span>
                    <span class="text-xs text-green-600 dark:text-green-400 text-center">Clone VM</span>
                </button>

                <!-- ISO Installation -->
                <button onclick="showReinstallModal('iso')" 
                        class="flex flex-col items-center justify-center p-4 bg-orange-50 hover:bg-orange-100 dark:bg-orange-900/20 dark:hover:bg-orange-900/30 border border-orange-200 dark:border-orange-800 rounded-lg transition-colors group">
                    <svg class="w-8 h-8 text-orange-600 dark:text-orange-400 mb-2 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <span class="text-sm font-medium text-orange-700 dark:text-orange-300">ISO</span>
                    <span class="text-xs text-orange-600 dark:text-orange-400 text-center">Choose ISO</span>
                </button>

                <!-- Import Custom ISO -->
                <button onclick="showReinstallModal('import')" 
                        class="flex flex-col items-center justify-center p-4 bg-purple-50 hover:bg-purple-100 dark:bg-purple-900/20 dark:hover:bg-purple-900/30 border border-purple-200 dark:border-purple-800 rounded-lg transition-colors group">
                    <svg class="w-8 h-8 text-purple-600 dark:text-purple-400 mb-2 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                    </svg>
                    <span class="text-sm font-medium text-purple-700 dark:text-purple-300">Import</span>
                    <span class="text-xs text-purple-600 dark:text-purple-400 text-center">Custom ISO</span>
                </button>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Server Information -->
        <div>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                <div class="p-6">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Server Information</h2>
                    
                    <div class="space-y-4">
                        <!-- Proxmox Access -->
                        <div>
                            <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">Proxmox Username</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white font-mono bg-gray-50 dark:bg-gray-700 px-3 py-2 rounded">
                                {{ $order->getExternalUser()->username }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">Proxmox Password</dt>
                            <dd class="mt-1 flex items-center space-x-2">
                                <span class="text-sm text-gray-500 dark:text-gray-400">••••••••••••</span>
                                <a href="{{ route('proxmox.password.resend', $order->id) }}" 
                                   class="text-blue-600 hover:text-blue-700 dark:text-blue-400 text-sm font-medium">
                                    <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                    </svg>
                                    Show
                                </a>
                            </dd>
                        </div>

                        <!-- VM Details -->
                        <div>
                            <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">VM Type</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                {{ $order->data['type'] == 'lxc' ? 'LXC Container' : 'QEMU Virtual Machine' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">VM ID</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white font-mono">{{ $order->data['vmid'] }}</dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">Node</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white">{{ $order->data['node'] }}</dd>
                        </div>

                        @if($machine)
                        <div>
                            <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">Uptime</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white" id="uptime-display">
                                @if($machine['uptime'] > 0)
                                    <span id="uptime-counter">{{ $machine['uptime'] }}</span>
                                @else
                                    Offline
                                @endif
                            </dd>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
</div>

<script>
<script>
let currentReinstallType = '';
let consoleSocket = null;

// Reinstall Modal Functions
function showReinstallModal(type) {
    currentReinstallType = type;
    const modal = document.getElementById('reinstallModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    
    let title = '';
    let content = '';
    
    switch(type) {
        case 'reset':
            title = 'Reset to Default Configuration';
            content = `
                <div class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    <p class="mb-2">This will reset your server to the default VM template configuration.</p>
                    <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-3">
                        <p class="text-yellow-800 dark:text-yellow-200 text-sm">
                            <strong>Warning:</strong> All data on your server will be permanently deleted!
                        </p>
                    </div>
                </div>
            `;
            break;
            
        case 'template':
            title = 'Reinstall from VM Template';
            content = `
                <div class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    <p class="mb-4">Choose a VM template to clone from:</p>
                    <select id="templateSelect" class="w-full p-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                        <option value="">Loading templates...</option>
                    </select>
                    <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-3 mt-4">
                        <p class="text-yellow-800 dark:text-yellow-200 text-sm">
                            <strong>Warning:</strong> All data on your server will be permanently deleted!
                        </p>
                    </div>
                </div>
            `;
            // Load templates via AJAX
            setTimeout(() => loadTemplates(), 100);
            break;
            
        case 'iso':
            title = 'Reinstall from ISO Image';
            content = `
                <div class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    <p class="mb-4">Choose an ISO image to install from:</p>
                    <select id="isoSelect" class="w-full p-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                        <option value="">Loading ISO images...</option>
                    </select>
                    <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-3 mt-4">
                        <p class="text-yellow-800 dark:text-yellow-200 text-sm">
                            <strong>Warning:</strong> All data on your server will be permanently deleted!
                        </p>
                    </div>
                </div>
            `;
            // Load ISOs via AJAX
            setTimeout(() => loadISOs(), 100);
            break;
            
        case 'import':
            title = 'Import Custom ISO';
            content = `
                <div class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    <p class="mb-4">Enter the path or URL of your custom ISO:</p>
                    <input type="text" id="customIsoInput" placeholder="e.g., local:iso/custom-image.iso or http://example.com/image.iso" 
                           class="w-full p-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3 mt-4">
                        <p class="text-blue-800 dark:text-blue-200 text-sm">
                            <strong>Note:</strong> Make sure the ISO is accessible by your Proxmox server.
                        </p>
                    </div>
                    <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-3 mt-4">
                        <p class="text-yellow-800 dark:text-yellow-200 text-sm">
                            <strong>Warning:</strong> All data on your server will be permanently deleted!
                        </p>
                    </div>
                </div>
            `;
            break;
    }
    
    modalTitle.textContent = title;
    modalContent.innerHTML = content;
    modal.classList.remove('hidden');
}

function closeReinstallModal() {
    document.getElementById('reinstallModal').classList.add('hidden');
}

function confirmReinstall() {
    let data = { type: currentReinstallType };
    
    switch(currentReinstallType) {
        case 'template':
            const templateSelect = document.getElementById('templateSelect');
            if (!templateSelect || !templateSelect.value) {
                alert('Please select a template');
                return;
            }
            data.template = templateSelect.value;
            break;
            
        case 'iso':
            const isoSelect = document.getElementById('isoSelect');
            if (!isoSelect || !isoSelect.value) {
                alert('Please select an ISO');
                return;
            }
            data.iso = isoSelect.value;
            break;
            
        case 'import':
            const customIsoInput = document.getElementById('customIsoInput');
            if (!customIsoInput || !customIsoInput.value.trim()) {
                alert('Please enter an ISO path or URL');
                return;
            }
            data.custom_iso = customIsoInput.value.trim();
            break;
    }
    
    // Show loading state
    const confirmBtn = document.querySelector('#reinstallModal .bg-red-600');
    const originalText = confirmBtn.textContent;
    confirmBtn.innerHTML = '<svg class="animate-spin -ml-1 mr-3 h-4 w-4 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Reinstalling...';
    confirmBtn.disabled = true;
    
    // Get CSRF token
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (!csrfToken) {
        alert('CSRF token not found. Please refresh the page.');
        return;
    }
    
    // Make AJAX request to reinstall
    fetch(`/service/{{ $order->id }}/server/reinstall`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json'
        },
        body: JSON.stringify(data)
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            alert('Reinstallation started successfully! Your server will be ready in a few minutes.');
            closeReinstallModal();
            setTimeout(() => window.location.reload(), 3000);
        } else {
            alert('Error: ' + (data.message || 'Reinstallation failed'));
        }
    })
    .catch(error => {
        console.error('Reinstall error:', error);
        alert('Error: ' + error.message);
    })
    .finally(() => {
        confirmBtn.innerHTML = originalText;
        confirmBtn.disabled = false;
    });
}

function loadTemplates() {
    fetch(`/service/{{ $order->id }}/server/templates`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            const select = document.getElementById('templateSelect');
            if (!select) return;
            
            select.innerHTML = '<option value="">Select a template...</option>';
            
            if (data.templates && typeof data.templates === 'object') {
                Object.entries(data.templates).forEach(([value, label]) => {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = label;
                    select.appendChild(option);
                });
            } else {
                select.innerHTML = '<option value="">No templates available</option>';
            }
        })
        .catch(error => {
            console.error('Error loading templates:', error);
            const select = document.getElementById('templateSelect');
            if (select) {
                select.innerHTML = '<option value="">Error loading templates</option>';
            }
        });
}

function loadISOs() {
    fetch(`/service/{{ $order->id }}/server/isos`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            const select = document.getElementById('isoSelect');
            if (!select) return;
            
            select.innerHTML = '<option value="">Select an ISO...</option>';
            
            if (data.isos && typeof data.isos === 'object') {
                Object.entries(data.isos).forEach(([value, label]) => {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = label;
                    select.appendChild(option);
                });
            } else {
                select.innerHTML = '<option value="">No ISOs available</option>';
            }
        })
        .catch(error => {
            console.error('Error loading ISOs:', error);
            const select = document.getElementById('isoSelect');
            if (select) {
                select.innerHTML = '<option value="">Error loading ISOs</option>';
            }
        });
}

// Console Functions
function refreshConsole() {
    const iframe = document.getElementById('console-iframe');
    iframe.src = iframe.src; // Reload iframe
}

function openFullConsole() {
    const consoleUrl = `{{ settings('proxmox::hostname') }}/?console={{ $order->data['type'] ?? 'qemu' }}&novnc=1&vmid={{ $order->data['vmid'] }}&node={{ $order->data['node'] }}`;
    window.open(consoleUrl, 'proxmox-console', 'width=1024,height=768,scrollbars=yes,resizable=yes');
}

function connectConsole() {
    // This function is no longer needed with noVNC iframe
    refreshConsole();
}

function disconnectConsole() {
    // This function is no longer needed with noVNC iframe
    const iframe = document.getElementById('console-iframe');
    iframe.src = 'about:blank';
}

function clearConsole() {
    // This function is no longer needed with noVNC iframe
    refreshConsole();
}

@if(isset($machine) && $machine && isset($machine['uptime']) && $machine['uptime'] > 0)
function updateUptime() {
    let elapsedTime = {{ $machine['uptime'] }};
    
    const timerInterval = setInterval(() => {
        elapsedTime++;
        
        // Calculate days, hours, minutes and seconds
        const seconds = parseInt(elapsedTime % 60, 10);
        const minutes = parseInt((elapsedTime / 60) % 60, 10);
        const hours = parseInt((elapsedTime / (60 * 60)) % 24, 10);
        const days = parseInt(elapsedTime / (60 * 60 * 24), 10);
        
        // Format time string
        let timeString = '';
        if (days > 0) timeString += `${days}d `;
        if (hours > 0) timeString += `${hours}h `;
        if (minutes > 0) timeString += `${minutes}m `;
        timeString += `${seconds}s`;
        
        const uptimeElement = document.getElementById('uptime-counter');
        if (uptimeElement) {
            uptimeElement.textContent = timeString;
        }
    }, 1000);
}

// Start the uptime counter
updateUptime();
@endif

// Close modal when clicking outside
document.getElementById('reinstallModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeReinstallModal();
    }
});
</script>