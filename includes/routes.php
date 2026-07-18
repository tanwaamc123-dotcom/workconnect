<?php
declare(strict_types=1);

function route_configuration(): array
{
    $guest = ['home', 'login', 'register', 'forgot-password', 'reset-password', 'file', 'maintenance', 'about', 'privacy', 'help-center', 'safety', 'community', 'services', 'service-detail', 'search'];
    $workspace = ['about-workspace', 'marketplace', 'marketplace-detail', 'messages', 'notifications', 'profile', 'settings', 'topup', 'seller-pending'];
    $roles = [
        'customer' => ['dashboard', 'checkout', 'orders', 'saved-services', 'topup'],
        'seller' => ['seller-dashboard', 'seller-services', 'seller-add-service', 'seller-orders', 'seller-messages', 'seller-earnings', 'seller-analytics', 'seller-profile', 'seller-settings'],
        'admin' => ['admin-users', 'admin-services', 'admin-orders', 'admin-messages', 'admin-control', 'admin-approvals', 'admin-moderation', 'admin-categories', 'admin-coupons', 'admin-logs', 'admin-broadcast', 'admin-export', 'admin-reports', 'admin-finance', 'admin-settings'],
    ];
    return [
        'guest' => $guest,
        'workspace' => $workspace,
        'roles' => $roles,
        'public_layout' => ['home', 'maintenance', 'about', 'privacy', 'help-center', 'safety', 'community', 'services', 'service-detail', 'search'],
        'all' => array_merge($guest, $workspace, ...array_values($roles)),
    ];
}

function page_titles(): array
{
    return [
        'home'=>'Find the right talent','login'=>'Sign in','register'=>'Create account','forgot-password'=>'Reset password','reset-password'=>'Choose new password','maintenance'=>'Maintenance','about'=>'About WorkConnect','privacy'=>'Privacy Policy','help-center'=>'Help Center','safety'=>'Safety','community'=>'Community','services'=>'Explore services','service-detail'=>'Service details','search'=>'Search',
        'dashboard'=>'Dashboard','checkout'=>'Checkout','about-workspace'=>'About WorkConnect','marketplace'=>'Find services','marketplace-detail'=>'Service details','orders'=>'My orders','saved-services'=>'Saved services','messages'=>'Messages','notifications'=>'Notifications','profile'=>'Profile','settings'=>'Settings','topup'=>'Top up wallet',
        'seller-dashboard'=>'Seller dashboard','seller-pending'=>'Approval pending','seller-services'=>'My services','seller-add-service'=>'Add service','seller-orders'=>'Manage orders','seller-messages'=>'Messages','seller-earnings'=>'Earnings','seller-analytics'=>'Analytics','seller-profile'=>'Profile','seller-settings'=>'Settings',
        'admin-users'=>'Users','admin-services'=>'Services','admin-orders'=>'Orders','admin-messages'=>'Messages','admin-control'=>'Control center','admin-approvals'=>'Approvals','admin-moderation'=>'Moderation','admin-categories'=>'Categories','admin-coupons'=>'Coupons','admin-logs'=>'Logs','admin-broadcast'=>'Broadcast','admin-export'=>'Export','admin-reports'=>'Reports','admin-finance'=>'Revenue dashboard','admin-settings'=>'System settings',
    ];
}
