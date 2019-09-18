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
    use DownloadTrait;

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

        // we want to check for image upload show stoppers as early as possible
        if ($input->getOption('image')) {
            $this->checkUploadImagePreconditions($this->config['fritzbox'], $this->config['phonebook']);
        }

        $vcards = $this->downloadAllProviders($output, $input->getOption('image'));

        // dissolve
        if ($input->getOption('dissolve')) {
            $quantity = count($vcards);
            error_log("Dissolving groups (e.g. iCloud)");
            $vcards = dissolveGroups($vcards);
            error_log(sprintf("Dissolved %d group(s)", $quantity - count($vcards)));
        }

        // filter
        if ($input->getOption('filter')) {
            $quantity = count($vcards);
            error_log(sprintf("Filtering %d vCard(s)", $quantity));
            $vcards = filter($vcards, $this->config['filters']);
            error_log(sprintf("Filtered out %d vCard(s)", $quantity - count($vcards)));
        }

        // save to file
        $vCardContents = '';
        foreach ($vcards as $vcard) {
            $vCardContents .= $vcard->serialize();
        }

        $filename = $input->getArgument('filename');
        if (file_put_contents($filename, $vCardContents) != false) {
            error_log(sprintf("Succesfully saved vCard(s) in %s", $filename));
        }
    }

    /**
     * checks if preconditions for upload images are OK
     *
     * @return            mixed     (true if all preconditions OK, error string otherwise)
     */
    private function checkUploadImagePreconditions($configFritz, $configPhonebook)
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
