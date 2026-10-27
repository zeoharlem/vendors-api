<?php

declare(strict_types=1);

namespace App\Shared\Security;

use Phalcon\Acl\Adapter\Memory as AclMemory;
use Phalcon\Acl\Component;
use Phalcon\Acl\Enum;
use Phalcon\Acl\Role;

final class AclFactory
{
    
    /**
     * Defines all ACL resources and the actions registered for each resource.
     *
     * Every action referenced in ROLE_RULES must be registered here.
     * Actions not registered in this list are not recognized by the ACL
     * and should remain denied by default.
     *
     * Resource descriptions:
     * - users: View users and manage user accounts.
     * - roles: View and manage application roles.
     * - permissions: View and manage permission definitions.
     * - vendors: View vendor information, manage vendor profiles,
     *   and verify vendors.
     * - stores: View and manage stores.
     * - products: View, create, update, and delete products.
     * - orders: Create orders, view orders, view owned orders,
     *   cancel owned orders, update orders, and update fulfillment.
     * - customers: View and manage customer records.
     * - reports: View reports.
     *
     * Action naming conventions:
     * - view: General permission to view a resource.
     * - view_own: Permission to view only resources owned by the user.
     * - cancel_own: Permission to cancel only orders owned by the user.
     * - profile.manage: Permission to manage a vendor's profile.
     * - update_fulfillment: Permission to update order fulfillment.
     *
     * IMPORTANT:
     * Registering an action does not enforce record ownership.
     * Ownership restrictions must also be enforced in the relevant
     * service or repository layer.
     *
     * @var array<string, list<string>>
     */
    private const RESOURCES = [
        'users' => ['view', 'manage'],
        'roles' => ['view', 'manage'],
        'permissions' => ['view', 'manage'],
        'vendors' => ['view', 'profile.manage', 'verify', 'apply'],
        'stores' => ['view', 'manage'],
        'store_review' => [
            'view_pending',
            'view',
            'approve',
            'reject',
        ],
        'vendor_review' => [
            'view_pending',
            'view',
            'approve',
            'reject',
        ],
        'notification' => ['view','mark_read'],
        'products' => ['view', 'create', 'update', 'delete'],
        'orders' => [
            'create',
            'view',
            'view_own',
            'cancel_own',
            'update',
            'update_fulfillment',
        ],
        'customers' => ['view', 'manage'],
        'reports' => ['view'],
    ];

    
    /**
     * Defines the actions permitted for each application role.
     *
     * Structure:
     *     role => resource => permitted actions
     *
     * Role policies:
     *
     * - customer:
     *   Can view products and stores, create orders, view their own orders,
     *   and cancel their own orders.
     *
     * - vendor:
     *   Can view and manage their vendor profile, view and manage stores,
     *   manage products, view their own orders, and update order fulfillment.
     *
     * - support:
     *   Can view users, vendors, stores, and products, and view or update
     *   orders according to the permissions defined below.
     *
     * The admin role is intentionally omitted from this constant because
     * administrator access is granted separately in create(), where the
     * administrator is allowed every registered resource/action pair.
     *
     * Security notes:
     * - The ACL uses a default-deny policy.
     * - Roles not explicitly granted an action do not receive that permission.
     * - Users with multiple roles receive the combined permissions of those roles.
     * - These rules determine action-level access; they do not replace
     *   ownership checks or other business-specific authorization.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const ROLE_RULES = [
        'customer' => [
            'products' => ['view'],
            'stores' => ['view'],
            'orders' => ['create', 'view_own', 'cancel_own'],
            'vendors' => ['apply', 'view'],
            'notification' => ['view', 'mark_read'],
        ],

        'vendor' => [
            'vendors' => ['view', 'profile.manage'],
            'stores' => ['view', 'manage'],
            'products' => ['view', 'create', 'update', 'delete'],
            'orders' => ['view_own', 'update_fulfillment'],
            'notification' => ['view', 'mark_read'],
        ],

        'support' => [
            'users' => ['view'],
            'vendors' => ['view'],
            'stores' => ['view'],
            'products' => ['view'],
            'orders' => ['view', 'update'],
        ],
    ];

    private const array ROLES = ['customer', 'vendor', 'support', 'admin'];

    public static function create(): AclMemory
    {
        $acl = new AclMemory();

        // Deny any access not explicitly granted.
        $acl->setDefaultAction(Enum::DENY);

        // Register roles.
        foreach (self::ROLES as $roleName) {
            $acl->addRole(new Role($roleName, ucfirst($roleName)),);
        }

        // Register resources and their actions using Phalcon's API.
        foreach (self::RESOURCES as $resource => $actions) {
            $acl->addComponent(new Component($resource),$actions,);
        }

        // Grant explicitly defined permissions to non-admin roles.
        foreach (self::ROLE_RULES as $role => $resources) {
            foreach ($resources as $resource => $actions) {
                foreach ($actions as $action) {
                    $acl->allow($role, $resource, $action);
                }
            }
        }

        // Admin receives every registered action on every resource.
        foreach (self::RESOURCES as $resource => $actions) {
            foreach ($actions as $action) {
                $acl->allow('admin', $resource, $action);
            }
        }

        return $acl;
    }

    private function __construct()
    {
    }
}