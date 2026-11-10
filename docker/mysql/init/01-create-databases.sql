-- The primary schema (`starter`) and the `starter` user are created by the
-- official image from MYSQL_DATABASE / MYSQL_USER. These are the extra schemas
-- the app opens its own connections against.

-- Test suite (phpunit.xml).
CREATE DATABASE IF NOT EXISTS `starter_test` DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `starter_test`.* TO 'starter'@'%';

FLUSH PRIVILEGES;
