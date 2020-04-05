<?php

namespace Andig;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;

class BackgroundCommand extends Command
{
    use ConfigTrait;

    protected function configure()
    {
        $this->setName('background-image')
            ->setDescription('Generate an upload of a background image from quick dial numbers');

        $this->addConfig();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->loadConfig($input);

        // uploading background image
        $savedAttributes = [];
        $ftpDisabled = $this->config['fritzbox']['ftp']['disabled'] ?? false;
        if (count($this->config['fritzbox']['fritzfons']) &&
            $this->config['phonebook']['id'] == 0 && !$ftpDisabled) {
            error_log('Downloading FRITZ!Box phonebook');
            $xmlPhonebook = downloadPhonebook($this->config['fritzbox'], $this->config['phonebook']);
            if (count($savedAttributes = uploadAttributes($xmlPhonebook, $this->config))) {
                error_log('Numbers with special attributes saved' . PHP_EOL);
            } else {                                                    // no attributes are set in the FRITZ!Box or lost
                $savedAttributes = downloadAttributes($this->config['fritzbox']);   // try to get last saved attributes
            }
            uploadBackgroundImage($savedAttributes, $this->config['fritzbox']);
        } else {
            error_log('No destination phones are defined and/or the first phone book is not selected!');
        }

        return 0;
    }
}
