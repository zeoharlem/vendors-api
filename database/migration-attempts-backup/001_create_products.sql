CREATE TABLE IF NOT EXISTS products (
    id UUID PRIMARY KEY,
    vendor_id UUID NOT NULL,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(200) NOT NULL,
    price_minor BIGINT NOT NULL CHECK (price_minor >= 0),
    currency CHAR(3) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT products_vendor_slug_unique UNIQUE (vendor_id, slug)
);

CREATE INDEX IF NOT EXISTS products_active_created_idx
    ON products (is_active, created_at DESC);

CREATE INDEX IF NOT EXISTS products_vendor_id_idx
    ON products (vendor_id);
