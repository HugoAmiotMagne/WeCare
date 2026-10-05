-- Exécuté une seule fois, à la création du volume MariaDB.
-- Autorise l'utilisateur applicatif à gérer la base de test (wecare_test),
-- utilisée par les tests d'intégration (suffixe _test ajouté par doctrine.yaml).
GRANT ALL PRIVILEGES ON `wecare\_test%`.* TO 'wecare'@'%';
FLUSH PRIVILEGES;
