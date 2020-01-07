<?php

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * @package redaxo\core
 *
 * @internal
 */
class rex_command_setup_run extends rex_console_command
{
    protected function configure()
    {
        $this
            ->setDescription('Perform redaxo setup')
            ->addOption('--lang', null, InputOption::VALUE_REQUIRED, 'System language e.g. "de_de" or "en_gb"')
            ->addOption('--agree-license', null, InputOption::VALUE_NONE, 'Accept licence terms and conditions')
            ->addOption('--server', null, InputOption::VALUE_REQUIRED, 'Website URL e.g. "http://example.org/"')
            ->addOption('--servername', null, InputOption::VALUE_REQUIRED, 'Website name')
            ->addOption('--error-email', null, InputOption::VALUE_REQUIRED, 'Error mail address e.g. "info@example.org"')
            ->addOption('--timezone', null, InputOption::VALUE_REQUIRED, 'Timezone e.g. "Europe/Berlin"')
            ->addOption('--db-host', null, InputOption::VALUE_REQUIRED, 'Database hostname e.g. "localhost" or "127.0.0.1"')
            ->addOption('--db-login', null, InputOption::VALUE_REQUIRED, 'Database username e.g. "root"')
            ->addOption('--db-password', null, InputOption::VALUE_REQUIRED, 'Database user password')
            ->addOption('--db-name', null, InputOption::VALUE_REQUIRED, 'Database name e.g. "redaxo"')
            ->addOption('--db-createdb', null, InputOption::VALUE_NONE, 'Creates the database')
            ->addOption('--db-setup', null, InputOption::VALUE_REQUIRED, 'Database setup mode e.g. "normal", "override" or "import"')
            ->addOption('--db-import', null, InputOption::VALUE_REQUIRED, 'Database import name if you choosed "import" as --db-setup')
            ->addOption('--admin-username', null, InputOption::VALUE_REQUIRED, 'Creates a redaxo admin user with the given username')
            ->addOption('--admin-password', null, InputOption::VALUE_REQUIRED, 'Sets the password for the new admin user')
        ;

        // TODO Create options to do the setup as one liner
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = $this->getStyle($input, $output);

        $configFile = rex_path::coreData('config.yml');
        $config = array_merge(
            rex_file::getConfig(rex_path::core('default.config.yml')),
            rex_file::getConfig($configFile)
        );

        // This step is needed to make sure that no packages are loaded
        // the database setup breaks if packages are loaded.
        // because packages are loaded early, we need to re-boot the whole application
        if ($config['setup'] !== true) {
            if($io->confirm('Setup already performed. Would you like to run it anyway?')) {
                $config['setup'] = true;
                rex_file::putConfig($configFile, $config);
                $io->success('Setup prepared. Please re-run setup:run.');
            }
            return 0;
        }

        $requiredValue = static function($value) {
            if(empty($value)) {
                throw new InvalidArgumentException('Value required');
            }
            return $value;
        };


        $interactiveMode = count($input->getOptions()) === 0;

        // ---------------------------------- Step 1 . Language
        $io->title('Step 1 of 6 / Language');
        $langs = [];
        foreach (rex_i18n::getLocales() as $locale) {
            $langs[$locale] = rex_i18n::msgInLocale('lang', $locale);
        }
        ksort($langs);

        if ($interactiveMode) {
            $config['lang'] = $io->askQuestion(new ChoiceQuestion('Please select a language', $langs));
        } else {
            $lang = $input->getOption('lang');
            if (!$lang || in_array($lang, $langs, true)) {
                throw new InvalidArgumentException('Unknown lang "'.$lang.'" specified');
            }
            $config['lang'] = $lang;
        }

        // ---------------------------------- Step 2 . license
        $io->title('Step 2 of 6 / Licence');

        $license_file = rex_path::base('LICENSE.md');
        $license = rex_file::get($license_file);

        if ($interactiveMode) {
            $io->writeln($license);
            if (!$io->confirm('Accept licence terms and conditions?')) {
                $io->error('You need to accept licence terms and conditions');
                return 1;
            }
        } else {
            if (null === $input->getOption('agree-licence')) {
                $io->error('You need to accept licence terms and conditions');
                return 1;
            }
        }



        // ---------------------------------- Step 3 . Perms, Environment
        $io->title('Step 3 of 6 / System check');

        // Embed existing check
        $checkCommand = new rex_command_setup_check();
        if(0 !== $checkCommand->doChecks($input, $output)) {
            return 1;
        }

        // ---------------------------------- step 4 . Config
        $io->title('Step 4 of 6 / Creating config');

        if ($interactiveMode) {
            $io->section('General');
            $config['server'] = $io->ask('Website URL', $config['server'], $requiredValue);
            $config['servername'] = $io->ask('Website name', $config['servername'], $requiredValue);
            $config['error_email'] = $io->ask('E-mail address in case of errors', $config['error_email'], $requiredValue);

            $q = new Question('Choose timezone', $config['timezone']);
            $q->setAutocompleterValues(DateTimeZone::listIdentifiers());
            $q->setValidator(function($value) {
                if (false === @date_default_timezone_set($value)) {
                    throw new RuntimeException('Time zone invalid');
                }
                return $value;
            });
            $config['timezone'] = $io->askQuestion($q);


            $io->section('Database information');
            do {
                $config['db'][1]['host'] = $io->ask('MySQL host', $config['db'][1]['host']);
                $config['db'][1]['login'] = $io->ask('Login', $config['db'][1]['login']);
                $config['db'][1]['password'] = $io->ask('Password', $config['db'][1]['password']);
                $config['db'][1]['name'] = $io->ask('Database name', $config['db'][1]['name']);


                $redaxo_db_create = $io->confirm('Create database', false);

                try {
                    $err = rex_setup::checkDb($config, $redaxo_db_create);
                } catch (PDOException $e) {
                    $err = 'The following error occured: ' . $e->getMessage();
                }

                if ('' !== $err) {
                    $io->error($err);
                }
            } while('' !== $err);

        } else {
            $config['server'] = $input->getOption('server');
            $config['servername'] = $input->getOption('servername');
            $config['error_email'] = $input->getOption('error-email');

            $timezone = $input->getOption('timezone');
            if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                throw new InvalidArgumentException('Unknown timezone "'.$timezone.'" specified');
            }
            $config['timezone'] = $timezone;


            $config['db'][1]['host'] = $input->getOption('db-host');
            $config['db'][1]['login'] = $input->getOption('db-login');
            $config['db'][1]['password'] = $input->getOption('db-password');
            $config['db'][1]['name'] = $input->getOption('db-name');


            $redaxo_db_create = true === $input->getOption('db-createdb');

            try {
                $err = rex_setup::checkDb($config, $redaxo_db_create);
            } catch (PDOException $e) {
                $err = 'The following error occured: ' . $e->getMessage();
            }

            if ('' !== $err) {
                $io->error($err);
                return 1;
            }
        }

        // ---------------------------------- step 5 . create db / demo
        $io->title('Step 5 of 6 / Database');


        // Search for exports
        $export_dir = rex_backup::getDir();
        $exports_found = false;

        if (is_dir($export_dir)) {
            if ($handle = opendir($export_dir)) {
                $export_sqls = [];

                while (false !== ($file = readdir($handle))) {
                    if ('.' == $file || '..' == $file) {
                        continue;
                    }

                    if ('.sql' == substr($file, strlen($file) - 4)) {
                        // cut .sql
                        $export_sqls[] = substr($file, 0, -4);
                        $exports_found = true;
                    }
                }
                closedir($handle);
            }
        }

        $createdbOptions = [
            'normal' => 'Setup database',
            'override' => 'Setup database and overwrite it if it exitsts already (Caution - All existing data will be deleted!',
            'existing' => 'Database already exists (Continue without database import)',
            'update' => 'Update database (Update from previous version)',
        ];
        if ($exports_found) {
            $createdbOptions['import'] = 'Import existing database export';
        }


        if ($interactiveMode) {
            $createdb = $io->askQuestion(new ChoiceQuestion('Setup database', $createdbOptions));
        } else {
            $validOptions = array_keys($createdbOptions);
            $createdb = $input->getOption('db-setup');
            if (!in_array($createdb, $validOptions, true)) {
                throw new InvalidArgumentException('Unknown db-setup value "'.$createdb.'". Valid values are ' . implode(', ', $validOptions));
            }
        }



        $tables_complete = ('' == rex_setup_importer::verifyDbSchema()) ? true : false;

        if ('update' == $createdb) {
            $error = rex_setup_importer::updateFromPrevious();
        } elseif ('import' == $createdb) {
            if($interactiveMode) {
                $import_name = $io->askQuestion(new ChoiceQuestion('Please choose a database export', $export_sqls));
            } else {
                $import_name = $input->getOption('db-import');
                if(!in_array($import_name, $export_sqls, true)) {
                    throw new InvalidArgumentException('Unknown import file ".'.$import_name.'." specified');
                }
            }
            $error = rex_setup_importer::loadExistingImport($import_name);
        } elseif ('existing' == $createdb && $tables_complete) {
            $error = rex_setup_importer::databaseAlreadyExists();
        } elseif ('override' == $createdb) {
            $error = rex_setup_importer::overrideExisting();
        } elseif ('normal' == $createdb) {
            $error = rex_setup_importer::prepareEmptyDb();
        } else {
            $error = 'An undefinied error occurred';
        }

        if('' !== $error) {
            $io->error($error);
            return 1;
        }

        $error = rex_setup_importer::verifyDbSchema();
        if ('' != $error) {
            $io->error($error);
            return 1;
        }

        rex_clang_service::generateCache();
        rex::setConfig('version', rex::getVersion());


        // ---------------------------------- Step 6 . Create User
        $io->title('Step 6 of 6 / User');

        $login = null;
        $password = null;

        if ($interactiveMode) {
            $user = rex_sql::factory();
            $user
                ->setTable(rex::getTable('user'))
                ->select();

            $skipUserCreation = false;
            if ($user->getRows()) {
                $skipUserCreation = $io->confirm('Users already exists. Skip user creation?');
            }
            $passwordPolicy = rex_backend_password_policy::factory(rex::getProperty('password_policy', []));

            if (!$skipUserCreation) {
                $io->section('Create administrator account');
                $login = $io->ask('Username', null, static function ($login) {
                    $user = rex_sql::factory();
                    $user
                        ->setTable(rex::getTable('user'))
                        ->setWhere(['login' => $login])
                        ->select();

                    if ($user->getRows()) {
                        throw new InvalidArgumentException(sprintf('User "%s" already exists.', $login));
                    }
                    return $login;
                });
                $password = $io->askHidden('Password', static function ($password) use ($passwordPolicy) {
                    if (true !== $msg = $passwordPolicy->check($password)) {
                        throw new InvalidArgumentException($msg);
                    }

                    return $password;
                });
            }
        } else {
            $login = $input->getOption('admin-username');
            $password = $input->getOption('admin-password');
        }

        if ($login && $password) {
            $user = rex_sql::factory();
            $user
                ->setTable(rex::getTable('user'))
                ->setWhere(['login' => $login])
                ->select();

            if ($user->getRows()) {
                throw new InvalidArgumentException(sprintf('User "%s" already exists.', $login));
            }

            $user = rex_sql::factory();
            $user->setTable(rex::getTablePrefix() . 'user');
            $user->setValue('login', $login);
            $user->setValue('password', rex_backend_login::passwordHash($password));
            $user->setValue('admin', 1);
            $user->addGlobalCreateFields('console');
            $user->addGlobalUpdateFields('console');
            $user->setValue('status', '1');
            $user->insert();

            $io->success(sprintf('User "%s" successfully created.', $login));
        }

        // ---------------------------------- last step. save config

        $config['setup'] = false;
        if (!rex_file::putConfig($configFile, $config)) {
            $io->error('Writing to config.yml failed.');
            return 1;
        }
        rex_file::delete(rex_path::coreCache('config.yml.cache'));

        $io->success('Congratulations! REDAXO has successfully been installed.');
        return 0;
    }
}
