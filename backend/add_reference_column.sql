-- Add reference column to license_codes table if it doesn't exist
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'license_codes' AND column_name = 'reference'
    ) THEN
        ALTER TABLE license_codes ADD COLUMN reference VARCHAR(255) NULL;
    END IF;
END $$;
