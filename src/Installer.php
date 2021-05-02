<?php

namespace PhotonCms\Installer\Console;

use ZipArchive;
use RuntimeException;
use GuzzleHttp\Client;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

class Installer extends Command
{
    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Create a new Photon CMS application')
            ->addArgument('name', InputArgument::OPTIONAL)
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Installs the latest "development" release');
    }

    /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (! class_exists('ZipArchive')) {
            throw new RuntimeException('The Zip PHP extension is not installed. Please install it and try again.');
        }

        $name = $input->getArgument('name') ? $input->getArgument('name') : 'photoncms';

        $hostname = $this->askForHost($input, $output);
        $port = $this->askForPort($input, $output);
        $username = $this->askForUsername($input, $output);
        $password = $this->askForPassword($input, $output);

        if(!$this->testDbConnection($input, $output, $username, $password, $name, $hostname, $port)) {
            $output->writeln('<error>...Connecting to database failed</error>');
            return false;
        }

        $directory = getcwd().'/'.$name;

        $this->verifyApplicationDoesntExist($directory);

        $output->writeln('<info>...Crafting application</info>');

        $version = $this->getVersion($input);

        $this->download($zipFile = $this->makeFilename(), $version)
             ->extract($zipFile, $directory, $version)
             ->prepareWritableDirectories($directory, $output)
             ->cleanUp($zipFile);

        $composer = $this->findComposer();

        $commands = [
            $composer.' install --no-scripts',
            $composer.' run-script post-root-package-install',
            $composer.' run-script post-create-project-cmd',
            $composer.' run-script post-autoload-dump',
        ];

        if ($input->getOption('no-ansi')) {
            $commands = array_map(function ($value) {
                return $value.' --no-ansi';
            }, $commands);
        }

        $process = new Process(implode(' && ', $commands), $directory, null, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            $process->setTty(true);
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write($line);
        });

        $this->setEnvironmentValue("DB_DATABASE", $name, $directory);
        $this->setEnvironmentValue("DB_HOST", $hostname, $directory);
        $this->setEnvironmentValue("DB_PORT", $port, $directory);
        $this->setEnvironmentValue("DB_USERNAME", $username, $directory);
        $this->setEnvironmentValue("DB_PASSWORD", $password, $directory);
        $output->writeln('<info>...updated .env file</info>');

        exec("php ".$directory."/artisan photon:hard-reset");

        $output->writeln('<info>...Photon CMS Installed!</info>');
    }

    /**
     * Verify that the application does not already exist.
     *
     * @param  string  $directory
     * @return void
     */
    protected function verifyApplicationDoesntExist($directory)
    {
        if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
            throw new RuntimeException('Application already exists!');
        }
    }

    /**
     * Generate a random temporary filename.
     *
     * @return string
     */
    protected function makeFilename()
    {
        return getcwd().'/laravel_'.md5(time().uniqid()).'.zip';
    }

    /**
     * Download the temporary Zip to the given file.
     *
     * @param  string  $zipFile
     * @param  string  $version
     * @return $this
     */
    protected function download($zipFile, $version = 'master')
    {
        switch ($version) {
            case 'development':
                $filename = 'development.zip';
                break;
            case 'master':
                $filename = 'master.zip';
                break;
        }

        $ga = (new Client)->request('POST', 'https://www.google-analytics.com/collect', [
            'form_params' => [
                'v' => '1',
                't' => 'event',
                'tid' => 'UA-1936460-37',
                'cid' => '65ee52da-f6fa-4f0d-a1e5-d12dd4945679',
                'ec' => 'Downloads',
                'ea' => 'Installation via Installer Package',
            ]
        ]);

        $response = (new Client)->get('https://github.com/photoncms/cms/archive/'.$filename);

        file_put_contents($zipFile, $response->getBody());

        return $this;
    }

    /**
     * Extract the Zip file into the given directory.
     *
     * @param  string  $zipFile
     * @param  string  $directory
     * @param  string  $version
     * @return $this
     */
    protected function extract($zipFile, $directory, $version)
    {
        $archive = new ZipArchive;

        $archive->open($zipFile);

        $archive->extractTo($directory);

        $archive->close();

        $filesystem = new Filesystem();
        $filesystem->mirror($directory."/cms-".$version, $directory);
        $filesystem->remove($directory."/cms-".$version);

        return $this;
    }

    /**
     * Clean-up the Zip file.
     *
     * @param  string  $zipFile
     * @return $this
     */
    protected function cleanUp($zipFile)
    {
        @chmod($zipFile, 0777);

        @unlink($zipFile);

        return $this;
    }

    /**
     * Make sure the storage and bootstrap cache directories are writable.
     *
     * @param  string  $appDirectory
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return $this
     */
    protected function prepareWritableDirectories($appDirectory, OutputInterface $output)
    {
        $filesystem = new Filesystem;

        try {
            $filesystem->chmod($appDirectory.DIRECTORY_SEPARATOR."bootstrap/cache", 0755, 0000, true);
            $filesystem->chmod($appDirectory.DIRECTORY_SEPARATOR."storage", 0755, 0000, true);
        } catch (IOExceptionInterface $e) {
            $output->writeln('<comment>You should verify that the "storage" and "bootstrap/cache" directories are writable.</comment>');
        }

        return $this;
    }

    /**
     * Get the version that should be downloaded.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @return string
     */
    protected function getVersion(InputInterface $input)
    {
        if ($input->getOption('dev')) {
            return 'development';
        }

        return 'master';
    }

    /**
     * Get the composer command for the environment.
     *
     * @return string
     */
    protected function findComposer()
    {
        if (file_exists(getcwd().'/composer.phar')) {
            return '"'.PHP_BINARY.'" composer.phar';
        }

        return 'composer';
    }

    /**
     * Prompt user for their db hostname.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return string
     */
    protected function askForHost($input, $output)
    {
        $questionHelper = new QuestionHelper();
        $question = new Question('<info>...What is your mysql hostname? [localhost]</info>  ', 'localhost');
        $hostname = $questionHelper->ask($input, $output, $question);

        return $hostname;
    }

    /**
     * Prompt user for their db port.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    protected function askForPort($input, $output)
    {
        $questionHelper = new QuestionHelper();
        $question = new Question('<info>...What is your mysql port? [3306]</info>  ', 3306);
        $port = $questionHelper->ask($input, $output, $question);

        return $port;
    }

    /**
     * Prompt user for their db username.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return string
     */
    protected function askForUsername($input, $output)
    {
        $questionHelper = new QuestionHelper();
        $question = new Question('<info>...What is your mysql username? [root]</info>', 'root');
        $username = $questionHelper->ask($input, $output, $question);

        return $username;
    }

    /**
     * Prompt user for their db password.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return string
     */
    protected function askForPassword($input, $output)
    {
        $questionHelper = new QuestionHelper();
        $question = new Question('<info>...What is your mysql password? []</info>', '');
        $question->setHidden(true);
        $password = $questionHelper->ask($input, $output, $question);

        return $password;
    }

    /**
     * Prompt user for their db password.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  string  $database
     * @return boolean
     */
    protected function confirmDatabase($input, $output, $database)
    {
        $questionHelper = new QuestionHelper();
        $question = new ChoiceQuestion(
            '<comment>...Database '.$database.' alerady exists. This action will empty it. Do you wish to continue?</comment>  ',
            [
                1 => "yes",
                2 => "no"
            ]
        );
        $answer = $questionHelper->ask($input, $output, $question);

        if($answer == "yes")
            return true;

        return false;
    }

    protected function testDbConnection($input, $output, $username, $password, $database, $hostname, $port)
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        // test connection, if invalid credentials return false
        try {
            $connection = mysqli_connect($hostname, $username, $password, null, $port);
        } catch (\mysqli_sql_exception $e) {
            return false;
        }

        // test database, if it doesn't exist create it
        try {
            $selectedDb = mysqli_select_db($connection, $database);
        } catch (\mysqli_sql_exception $e) {
            $sql = 'CREATE DATABASE `' . $database . '`';
            mysqli_query($connection, $sql);
            return true;
        }

        return $this->confirmDatabase($input, $output, $database);
    }

    protected function setEnvironmentValue($envKey, $envValue, $directory)
    {
        $envFile = $directory."/.env";

        $str = file_get_contents($envFile);

        switch ($envKey) {
            case 'DB_HOST':
                $oldValue = "localhost";
                break;
            case 'DB_PORT':
                $oldValue = 3306;
                break;
            case 'DB_DATABASE':
                $oldValue = "dbname";
                break;
            case 'DB_USERNAME':
                $oldValue = "username";
                break;
            case 'DB_PASSWORD':
                $oldValue = "password";
                break;

        }

        # append port config if not exist
        if ($envKey === 'DB_HOST' && strpos($str, 'DB_PORT') === false) {
            $str = str_replace("{$envKey}={$oldValue}\n", "{$envKey}={$envValue}\nDB_PORT=3306\n", $str);
        } else {
            $str = str_replace("{$envKey}={$oldValue}\n", "{$envKey}={$envValue}\n", $str);
        }
        
        $fp = fopen($envFile, 'w');
        fwrite($fp, $str);
        fclose($fp);
    }
}
