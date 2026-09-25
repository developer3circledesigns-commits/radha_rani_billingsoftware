-- Radha Rani Hotel Portal - Seed Data (see database/init.sql for the combined init)
-- Owner password:  (set via profile after first login)

INSERT IGNORE INTO users (id, branch_id, name, email, username, password_hash, role, status)
VALUES
    (1, NULL, 'Radha Rani Owner', 'owner@radharani.local', 'owner', '$2y$10$TvPhYOxNBjhTXNRbwPtgXOmSwfrXdkbPq/2ZWGMgv3xEeAMZrxsqG', 'owner', 'active');