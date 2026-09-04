-- Base dediee aux tests d'integration : le jeu de donnees y est cree et detruit
-- a chaque execution, ce qui interdit de la faire cohabiter avec le developpement.
CREATE DATABASE IF NOT EXISTS mssub_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON mssub_test.* TO 'mssub'@'%';
FLUSH PRIVILEGES;
