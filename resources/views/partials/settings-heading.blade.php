<div class="relative mb-6 w-full">
    <x-ui.breadcrumbs :items="[
        ['label' => 'Account', 'href' => route('dashboard'), 'wireNavigate' => true],
        ['label' => 'Settings', 'current' => true],
    ]" />
    <flux:heading size="xl" level="1">{{ __('Settings') }}</flux:heading>
    <flux:subheading size="lg" class="mb-6">{{ __('Manage your profile and account settings') }}</flux:subheading>
    <flux:separator variant="subtle" />
</div>
