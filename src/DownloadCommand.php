<?php

namespace Andig;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;

class DownloadCommand extends Command
{
    use ConfigTrait;

    protected function configure()
    {
        $this->setName('download')
            ->setDescription('Load from CardDAV server')
            ->addArgument('filename', InputArgument::REQUIRED, 'raw vcards file (VCF)')
            ->addOption('dissolve', 'd', InputOption::VALUE_NONE, 'dissolve groups')
            ->addOption('filter', 'f', InputOption::VALUE_NONE, 'filter vCards')
            ->addOption('image', 'i', InputOption::VALUE_NONE, 'download images');

        $this->addConfig();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->loadConfig($input);

        $filename = $input->getArgument('filename');

        // we want to check for image upload show stoppers as early as possible
        if ($input->getOption('image')) {
            $this->uploadImagePreconditions($this->config['fritzbox'], $this->config['phonebook']);
        }

        $quantity = 0;
        $remain = 0;
        $vcards = [];
        $xcards = [];
        $substitutes = ($input->getOption('image')) ? ['PHOTO'] : [];
        $vCardFile = '';

        foreach ($this->config['local'] as $file) {
            if (isset($file)) {
                error_log("Reading vCard(s) from file ".$file);
                $local = localProvider($file);

                $progress = new ProgressBar($output);
                $progress->start();
                $xcards = download($local, [], function () use ($progress) {
                    $progress->advance();
                });
                $progress->finish();

                $vcards = array_merge($vcards, $xcards);
                $quantity += count($xcards);
                error_log(sprintf("\nRead %d vCard(s)", $quantity));
            }
        }

        foreach ($this->config['server'] as $server) {
            error_log("Downloading vCard(s) from account ".$server['user']);
            $backend = backendProvider($server);

            $progress = new ProgressBar($output);
            $progress->start();
            $xcards = download($backend, $substitutes, function () use ($progress) {
                $progress->advance();
            });
            $progress->finish();

            $vcards = array_merge($vcards, $xcards);
            $quantity += count($xcards);
            error_log(sprintf("\nDownloaded %d vCard(s)", $quantity));
            $remain = $quantity;
        }

        // dissolve
        if ($input->getOption('dissolve')) {
            error_log("Dissolving groups (e.g. iCloud)");
            $vcards = dissolveGroups($vcards);
            $remain = count($vcards);
            error_log(sprintf("Dissolved %d group(s)", $quantity - $remain));
        }

        // filter
        if ($input->getOption('filter')) {
            error_log(sprintf("Filtering %d vCard(s)", $remain));
            $vcards = filter($vcards, $this->config['filters']);
            error_log(sprintf("Filtered out %d vCard(s)", $remain - count($vcards)));
        }

        foreach ($vcards as $vcard) {
            $vCardFile .= $vcard->serialize();
        }
        if (file_put_contents($filename, $vCardFile) != false) {
            error_log(sprintf("Succesfull saved vCard(s) in %s", $filename));
        }
    }

    /**
     * checks if preconditions for upload images are OK
     *
     * @return            mixed     (true if all preconditions OK, error string otherwise)
     */
    private function uploadImagePreconditions($configFritz, $configPhonebook)
    {
        if (!function_exists("ftp_connect")) {
            throw new \Exception(
                <<<EOD
FTP functions not available in your PHP installation.
Image upload not possible (remove -i switch).
Ensure PHP was installed with --enable-ftp
Ensure php.ini does not list ftp_* functions in 'disable_functions'
In shell run: php -r \"phpinfo();\" | grep -i FTP"
EOD
            );
        }
        if (!$configFritz['fonpix']) {
            throw new \Exception(
                <<<EOD
config.php missing fritzbox/fonpix setting.
Image upload not possible (remove -i switch).
EOD
            );
        }
        if (!$configPhonebook['imagepath']) {
            throw new \Exception(
                <<<EOD
config.php missing phonebook/imagepath setting.
Image upload not possible (remove -i switch).
EOD
            );
        }
        if ($configFritz['user'] == 'dslf-conf') {
            throw new \Exception(
                <<<EOD
TR-064 default user dslf-conf has no permission for ftp access.
Image upload not possible (remove -i switch).
EOD
            );
        }
    }
}
