BEGIN;

-- ============================================================
-- 1. DEMO VENDOR USER
-- ============================================================

INSERT INTO public.users (
    id,
    email,
    password_hash,
    first_name,
    last_name,
    phone,
    status,
    email_verified_at
)
VALUES (
    '10000000-0000-4000-8000-000000000001',
    'demo.vendor.seed.20261030@example.test',
    'SEED_ACCOUNT_NOT_FOR_LOGIN',
    'Demo',
    'Vendor',
    '+2348000000001',
    'active',
    NOW()
)
ON CONFLICT (id) DO UPDATE SET
    email = EXCLUDED.email,
    first_name = EXCLUDED.first_name,
    last_name = EXCLUDED.last_name,
    status = 'active',
    email_verified_at = COALESCE(
        public.users.email_verified_at,
        NOW()
    );


-- ============================================================
-- 2. VERIFIED VENDOR PROFILE
-- ============================================================

INSERT INTO public.vendor_profiles (
    id,
    user_id,
    business_name,
    business_registration_number,
    verification_status
)
VALUES (
    '20000000-0000-4000-8000-000000000001',
    '10000000-0000-4000-8000-000000000001',
    'Demo Electronics Limited',
    'DEMO-RC-2026-001',
    'verified'
)
ON CONFLICT (id) DO UPDATE SET
    business_name = EXCLUDED.business_name,
    business_registration_number = EXCLUDED.business_registration_number,
    verification_status = 'verified';


-- ============================================================
-- 3. ACTIVE DEMO STORE
-- ============================================================

INSERT INTO public.stores (
    id,
    vendor_profile_id,
    name,
    slug,
    description,
    status
)
VALUES (
    '30000000-0000-4000-8000-000000000001',
    '20000000-0000-4000-8000-000000000001',
    'Demo Electronics Store',
    'demo-electronics-store-seed-20261030',
    'A demonstration electronics store for marketplace testing.',
    'active'
)
ON CONFLICT (id) DO UPDATE SET
    name = EXCLUDED.name,
    description = EXCLUDED.description,
    status = 'active';


-- ============================================================
-- 4. PRODUCT CATEGORIES
-- ============================================================

INSERT INTO public.categories (
    id,
    parent_id,
    name,
    slug,
    description,
    is_active
)
VALUES (
    '40000000-0000-4000-8000-000000000001',
    NULL,
    'Demo Electronics',
    'demo-electronics-seed-20261030',
    'Electronics category created for marketplace testing.',
    TRUE
)
ON CONFLICT (id) DO UPDATE SET
    name = EXCLUDED.name,
    description = EXCLUDED.description,
    is_active = TRUE;


INSERT INTO public.categories (
    id,
    parent_id,
    name,
    slug,
    description,
    is_active
)
VALUES (
    '40000000-0000-4000-8000-000000000002',
    NULL,
    'Demo Accessories',
    'demo-accessories-seed-20261030',
    'Accessories category created for marketplace testing.',
    TRUE
)
ON CONFLICT (id) DO UPDATE SET
    name = EXCLUDED.name,
    description = EXCLUDED.description,
    is_active = TRUE;


-- ============================================================
-- 5. PUBLISHED AND APPROVED PRODUCTS
-- ============================================================

INSERT INTO public.products (
    id,
    store_id,
    category_id,
    name,
    slug,
    description,
    status,
    moderation_status,
    published_at
)
VALUES (
    '50000000-0000-4000-8000-000000000001',
    '30000000-0000-4000-8000-000000000001',
    '40000000-0000-4000-8000-000000000001',
    'Samsung Galaxy Demo Smartphone',
    'seed-samsung-galaxy-smartphone-20261030',
    'Demo smartphone product for testing cart and checkout.',
    'published',
    'approved',
    NOW()
)
ON CONFLICT (id) DO UPDATE SET
    name = EXCLUDED.name,
    description = EXCLUDED.description,
    status = 'published',
    moderation_status = 'approved',
    published_at = NOW();


INSERT INTO public.products (
    id,
    store_id,
    category_id,
    name,
    slug,
    description,
    status,
    moderation_status,
    published_at
)
VALUES (
    '50000000-0000-4000-8000-000000000002',
    '30000000-0000-4000-8000-000000000001',
    '40000000-0000-4000-8000-000000000001',
    'Demo Wireless Headphones',
    'seed-wireless-headphones-20261030',
    'Demo wireless headphones for testing product variants and cart.',
    'published',
    'approved',
    NOW()
)
ON CONFLICT (id) DO UPDATE SET
    name = EXCLUDED.name,
    description = EXCLUDED.description,
    status = 'published',
    moderation_status = 'approved',
    published_at = NOW();


INSERT INTO public.products (
    id,
    store_id,
    category_id,
    name,
    slug,
    description,
    status,
    moderation_status,
    published_at
)
VALUES (
    '50000000-0000-4000-8000-000000000003',
    '30000000-0000-4000-8000-000000000001',
    '40000000-0000-4000-8000-000000000002',
    'Demo USB-C Charger',
    'seed-usb-c-charger-20261030',
    'Demo USB-C fast charger for marketplace testing.',
    'published',
    'approved',
    NOW()
)
ON CONFLICT (id) DO UPDATE SET
    name = EXCLUDED.name,
    description = EXCLUDED.description,
    status = 'published',
    moderation_status = 'approved',
    published_at = NOW();


-- ============================================================
-- 6. PRODUCT VARIANTS
-- ============================================================

INSERT INTO public.product_variants (
    id,
    product_id,
    sku,
    name,
    attributes,
    price_minor,
    compare_at_minor,
    currency,
    status
)
VALUES (
    '60000000-0000-4000-8000-000000000001',
    '50000000-0000-4000-8000-000000000001',
    'SEED-SAMSUNG-GALAXY-001',
    '128GB Black',
    '{"storage": "128GB", "color": "Black"}'::jsonb,
    45000000,
    48000000,
    'NGN',
    'active'
)
ON CONFLICT (id) DO UPDATE SET
    name = EXCLUDED.name,
    price_minor = EXCLUDED.price_minor,
    compare_at_minor = EXCLUDED.compare_at_minor,
    currency = EXCLUDED.currency,
    status = 'active';


INSERT INTO public.product_variants (
    id,
    product_id,
    sku,
    name,
    attributes,
    price_minor,
    compare_at_minor,
    currency,
    status
)
VALUES (
    '60000000-0000-4000-8000-000000000002',
    '50000000-0000-4000-8000-000000000002',
    'SEED-WIRELESS-HEADPHONES-001',
    'Standard Black',
    '{"color": "Black"}'::jsonb,
    8500000,
    10000000,
    'NGN',
    'active'
)
ON CONFLICT (id) DO UPDATE SET
    name = EXCLUDED.name,
    price_minor = EXCLUDED.price_minor,
    compare_at_minor = EXCLUDED.compare_at_minor,
    currency = 'NGN',
    status = 'active';


INSERT INTO public.product_variants (
    id,
    product_id,
    sku,
    name,
    attributes,
    price_minor,
    compare_at_minor,
    currency,
    status
)
VALUES (
    '60000000-0000-4000-8000-000000000003',
    '50000000-0000-4000-8000-000000000003',
    'SEED-USBC-CHARGER-001',
    '25W Fast Charger',
    '{"power": "25W", "connector": "USB-C"}'::jsonb,
    3500000,
    4000000,
    'NGN',
    'active'
)
ON CONFLICT (id) DO UPDATE SET
    name = EXCLUDED.name,
    price_minor = EXCLUDED.price_minor,
    compare_at_minor = EXCLUDED.compare_at_minor,
    currency = 'NGN',
    status = 'active';


-- ============================================================
-- 7. INVENTORY
-- ============================================================

INSERT INTO public.inventory (
    variant_id,
    available_qty,
    reserved_qty
)
VALUES
(
    '60000000-0000-4000-8000-000000000001',
    25,
    0
),
(
    '60000000-0000-4000-8000-000000000002',
    40,
    0
),
(
    '60000000-0000-4000-8000-000000000003',
    100,
    0
)
ON CONFLICT (variant_id) DO UPDATE SET
    available_qty = EXCLUDED.available_qty,
    reserved_qty = 0,
    updated_at = NOW();


-- ============================================================
-- 8. INITIAL INVENTORY MOVEMENTS
-- ============================================================

INSERT INTO public.inventory_movements (
    id,
    variant_id,
    movement_type,
    quantity_delta,
    available_before,
    available_after,
    reserved_before,
    reserved_after,
    reason,
    reference_type,
    reference_id,
    actor_user_id
)
VALUES
(
    '70000000-0000-4000-8000-000000000001',
    '60000000-0000-4000-8000-000000000001',
    'initial_stock',
    25,
    0,
    25,
    0,
    0,
    'Initial demo inventory seed',
    'seed',
    '50000000-0000-4000-8000-000000000001',
    '10000000-0000-4000-8000-000000000001'
),
(
    '70000000-0000-4000-8000-000000000002',
    '60000000-0000-4000-8000-000000000002',
    'initial_stock',
    40,
    0,
    40,
    0,
    0,
    'Initial demo inventory seed',
    'seed',
    '50000000-0000-4000-8000-000000000002',
    '10000000-0000-4000-8000-000000000001'
),
(
    '70000000-0000-4000-8000-000000000003',
    '60000000-0000-4000-8000-000000000003',
    'initial_stock',
    100,
    0,
    100,
    0,
    0,
    'Initial demo inventory seed',
    'seed',
    '50000000-0000-4000-8000-000000000003',
    '10000000-0000-4000-8000-000000000001'
)
ON CONFLICT (id) DO NOTHING;


COMMIT;


-- ============================================================
-- 9. VERIFY SEEDED DATA
-- ============================================================

SELECT
    s.name AS store,
    p.name AS product,
    pv.name AS variant,
    pv.sku,
    pv.price_minor,
    pv.currency,
    i.available_qty,
    i.reserved_qty,
    p.status AS product_status,
    p.moderation_status,
    s.status AS store_status,
    vp.verification_status AS vendor_status
FROM public.product_variants pv
INNER JOIN public.products p
    ON p.id = pv.product_id
INNER JOIN public.stores s
    ON s.id = p.store_id
INNER JOIN public.vendor_profiles vp
    ON vp.id = s.vendor_profile_id
INNER JOIN public.inventory i
    ON i.variant_id = pv.id
WHERE s.slug = 'demo-electronics-store-seed-20261030'
ORDER BY p.name;