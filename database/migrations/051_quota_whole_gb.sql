-- 051: disk defaults in whole GiB (5000 MB displayed as "4.88 GB").
-- Column defaults only: existing users/plans keep their numbers (changing a
-- plan here would silently desync it from the accounts already on it) —
-- adjust those from Reseller -> Edit packages, which pushes to its users.
ALTER TABLE users MODIFY disk_quota_mb INT NOT NULL DEFAULT 5120;
ALTER TABLE packages MODIFY disk_quota_mb INT DEFAULT 5120;
