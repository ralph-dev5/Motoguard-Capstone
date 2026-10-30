<x-layouts::auth :title="__('Connect')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Connect your device')" :description="__('Enter the Device ID and PIN shown on the unit\'s setup page')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('connect') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="serial"
                :label="__('Device ID')"
                :value="old('serial')"
                required
                autofocus
                autocomplete="off"
                autocapitalize="characters"
                placeholder="MG-04A784"
            />

            <flux:input
                name="pin"
                :label="__('PIN')"
                type="password"
                inputmode="numeric"
                maxlength="6"
                required
                autocomplete="off"
                placeholder="6 digits"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="connect-button">
                {{ __('Connect') }}
            </flux:button>
        </form>

        <flux:text class="text-center text-sm">
            {{ __('This phone stays connected until you press Disconnect.') }}
        </flux:text>
    </div>
</x-layouts::auth>
