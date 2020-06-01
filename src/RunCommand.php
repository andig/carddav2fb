<?php

namespace Andig;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;

class RunCommand extends Command
{
    use ConfigTrait;
    use DownloadTrait;

    protected function configure()
    {
        $this->setName('run')
            ->setDescription('Download, convert and upload - all in one')
            ->addOption('image', 'i', InputOption::VALUE_NONE, 'download images')
            ->addOption('local', 'l', InputOption::VALUE_OPTIONAL|InputOption::VALUE_IS_ARRAY, 'local file(s)');

        $this->addConfig();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->loadConfig($input);

        $ftpDisabled = $this->config['fritzbox']['ftp']['disabled'] ?? false;
        if ($ftpDisabled) {
            $input->setOption('image', false);
            error_log('Images can only be uploaded if ftp is enabled!');
        }

        // we want to check for image upload show stoppers as early as possible
        if ($input->getOption('image')) {
            $this->checkUploadImagePreconditions($this->config['fritzbox'], $this->config['phonebook']);
        }

        // download recent phonebook and save special attributes
        $savedAttributes = [];
        error_log("Downloading recent FRITZ!Box phonebook");
        $recentPhonebook = downloadPhonebook($this->config['fritzbox'], $this->config['phonebook']);
        if (count($savedAttributes = uploadAttributes($recentPhonebook, $this->config))) {
            error_log('Phone numbers with special attributes saved');
        } else {
            // no attributes are set in the FRITZ!Box or lost -> try to download them
            $savedAttributes = downloadAttributes($this->config['fritzbox']);   // try to get last saved attributes
        }

        // download from server or local files
        $local = $input->getOption('local');
        $vcards = $this->downloadAllProviders($output, $input->getOption('image'), $local);
        error_log(sprintf("Downloaded %d vCard(s) in total", count($vcards)));

        // process groups & filters
        $vcards = $this->processGroups($vcards);
        $vcards = $this->processFilters($vcards);

        // image upload
        if ($input->getOption('image')) {
            error_log("Detaching and uploading image(s)");

            $progress = new ProgressBar($output);
            $progress->start(count($vcards));
            $pictures = uploadImages($vcards, $this->config['fritzbox'], $this->config['phonebook'], function () use ($progress) {
                $progress->advance();
            });
            $progress->finish();

            if ($pictures) {
                error_log(sprintf(PHP_EOL . "Uploaded/refreshed %d of %d image file(s)", $pictures[0], $pictures[1]));
            }
        }

        // convert fritzbox format
        error_log("Converting vCard(s)");
        $contacts = convertVCards($vcards, $this->config);
        error_log(sprintf("Converted %d vCard(s) into %d contacts", count($vcards), count($contacts)));

        if (!count($contacts)) {
            error_log("Phonebook empty - skipping write to file");
            return 1;
        }

        // fritzbox phonebook
        $xmlPhonebook = contactsToFritzXML($contacts, $this->config);

        // write back saved attributes
        $xmlPhonebook = mergeAttributes($xmlPhonebook, $savedAttributes);

        // upload
        error_log("Uploading new phonebook to FRITZ!Box");
        uploadPhonebook($xmlPhonebook, $this->config['fritzbox'], $this->config['phonebook']);
        error_log("Successful uploaded new FRITZ!Box phonebook");

        // uploading background image
        if (count($this->config['fritzbox']['fritzfons']) &&
            $this->config['phonebook']['id'] == 0 &&
            !$ftpDisabled) {
            uploadBackgroundImage($savedAttributes, $this->config['fritzbox']);
        }

        return 0;
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
