<div class="grid gap-6 mt-6 lg:mt-6 sm:grid-cols-2 lg:grid-cols-4">
    <a href="{{ route('proxmox.server.start', $order->id) }}" class="p-6 text-center bg-white rounded-lg border border-gray-200 shadow-md dark:bg-gray-800 dark:hover:bg-gray-700 dark:border-gray-700 hover:shadow-lg">
        <div class="flex justify-center items-center mx-auto mb-4 w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-900 lg:h-12 lg:w-12">
            <div class="text-2xl text-gray-600 dark:text-gray-400" style="font-size: 30px">
                <i class='bx bx-power-off'></i>
            </div>
        </div>
        <h3 class="mb-2 text-lg font-semibold tracking-tight text-gray-500 dark:text-gray-400">Start</h3>
    </a>
    <a href="{{ route('proxmox.server.stop', $order->id) }}" class="p-6 text-center bg-white rounded-lg border border-gray-200 shadow-md dark:bg-gray-800 dark:hover:bg-gray-700 dark:border-gray-700 hover:shadow-lg">
        <div class="flex justify-center items-center mx-auto mb-4 w-10 h-10 rounded-lg bg-gray-100 dark:bg-gray-900 lg:h-12 lg:w-12">
            <div class="text-2xl text-gray-600 dark:text-gray-400" style="font-size: 30px">
                <i class='bx bx-stop'></i>
            </div>
        </div>
        <h3 class="mb-2 text-lg font-semibold tracking-tight text-gray-500 dark:text-gray-400">Stop</h3>
    </a>
    <a href="{{ route('proxmox.server.shutdown', $order->id) }}" class="p-6 text-center bg-white rounded-lg border border-gray-200 shadow-md dark:bg-gray-800 dark:hover:bg-gray-700 dark:border-gray-700 hover:shadow-lg">
        <div class="flex justify-center items-center mx-auto mb-4 w-10 h-10 rounded-lg bg-gray-100 dark:bg-gray-900 lg:h-12 lg:w-12">
            <div class="text-2xl text-gray-600 dark:text-gray-400" style="font-size: 30px">
                <i class='bx bx-x-circle' ></i>
            </div>
        </div>
        <h3 class="mb-2 text-lg font-semibold tracking-tight text-gray-500 dark:text-gray-400">Shutdown</h3>
    </a>
    <a href="{{ route('proxmox.server.reboot', $order->id) }}" class="p-6 text-center bg-white rounded-lg border border-gray-200 shadow-md dark:bg-gray-800 dark:hover:bg-gray-700 dark:border-gray-700 hover:shadow-lg">
        <div class="flex justify-center items-center mx-auto mb-4 w-10 h-10 rounded-lg bg-gray-100 dark:bg-gray-900 lg:h-12 lg:w-12">
            <div class="text-2xl text-gray-600 dark:text-gray-400" style="font-size: 35px">
                <i class='bx bx-refresh' ></i>
            </div>
        </div>
        <h3 class="mb-2 text-lg font-semibold tracking-tight text-gray-500 dark:text-gray-400">Reboot</h3>
    </a>
</div>

<div class="mt-6 p-6 bg-white border border-gray-200 rounded-lg shadow dark:bg-gray-800 dark:border-gray-700">
    <a href="#">
        <h5 class="mb-2 text-1xl font-bold tracking-tight text-gray-900 dark:text-white mb-4">Details</h5>
    </a>
    <div class="grid gap-4 px-4 mb-4 sm:mb-5 sm:grid-cols-2 sm:gap-6 md:gap-12">
        <!-- Column -->
        <dl>
            <dt class="mb-2 font-semibold leading-none text-gray-900 dark:text-white">Username</dt>
            <dd class="mb-4 font-light text-gray-500 sm:mb-5 dark:text-gray-400">{{ $order->getExternalUser()->username }}</dd>
            <dt class="mb-2 font-semibold leading-none text-gray-900 dark:text-white">Node</dt>
            <dd class="mb-4 font-light text-gray-500 sm:mb-5 dark:text-gray-400">{{ $order->data['node'] }}</dd>
        </dl>
        <!-- Column -->
        <dl>
            <dt class="mb-2 font-semibold leading-none text-gray-900 dark:text-white">Password</dt>
            <dd class="mb-4 font-light text-gray-500 sm:mb-5 dark:text-gray-400">*************** <a href="{{ route('proxmox.password.resend', $order->id) }}">show <i class='bx bx-show'></i></a></dd>
            <dt class="mb-2 font-semibold leading-none text-gray-900 dark:text-white">VM Identifier</dt>
            <dd class="mb-4 font-light text-gray-500 sm:mb-5 dark:text-gray-400">VM {{ $order->data['vmid'] }}</dd>
        </dl>
        </div>
</div>
