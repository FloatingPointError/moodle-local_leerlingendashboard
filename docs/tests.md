# Tests draaien op de test-VM

## PHPUnit

```
cd /var/www/moodle
sudo -u www-data /usr/bin/php8.3 vendor/bin/phpunit --testsuite local_leerlingendashboard_testsuite --do-not-cache-result
```

Stand 22-11-2026 (fase 3): 28 tests, 89 controles, alles OK.

Na een versieverhoging van de plugin moet de testsite opnieuw geïnstalleerd worden
(`--drop` en `--install`, zie hieronder); PHPUnit meldt dat zelf.

## Opzet (eenmalig, al gedaan op 22-11-2026)

- Composer-pakketten in `/var/www/moodle/vendor` (uit Moodle's `composer.lock`, PHPUnit 11.5).
  `vendor/` en `composer.phar` staan op groep www-data, schrijfbaar voor de groep.
- `config.php`: `$CFG->phpunit_prefix = 'phpu_';` en
  `$CFG->phpunit_dataroot = '/var/www/moodle/phpu_moodledata';` (buiten `public/`).
  Reservekopie van de oude `config.php` in `/var/www/moodle/config-backups/`.
- Locale `en_AU.UTF-8` (Moodle eist die voor PHPUnit): in `/etc/locale.gen` het
  commentaarteken weghalen, dan `sudo locale-gen`. Een naam als argument aan `locale-gen`
  wordt op dit systeem genegeerd.

## Na een Moodle-upgrade of een nieuwe plugin

`admin/tool/phpunit/cli/init.php` roept letterlijk `php` aan, en dat is op deze VM PHP 8.5
zonder mysqli. Gebruik daarom `util.php` rechtstreeks:

```
cd /var/www/moodle/public/admin/tool/phpunit/cli
sudo -u www-data /usr/bin/php8.3 util.php --drop      # alleen als de testsite opnieuw moet
sudo -u www-data /usr/bin/php8.3 util.php --install
/usr/bin/php8.3 util.php --buildconfig                # als eigenaar van /var/www/moodle
```

`--buildconfig` schrijft `/var/www/moodle/phpunit.xml` en eist dat die map beschrijfbaar
is; daarom als eigenaar (milux, lid van groep www-data) en niet als www-data.
