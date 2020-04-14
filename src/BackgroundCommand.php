<?php

namespace Andig;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
            $output->writeln('<info>Downloading recent FRITZ!Box phonebook</info>');
            $xmlPhonebook = downloadPhonebook($this->config['fritzbox'], $this->config['phonebook'], $output);
            if (count($savedAttributes = uploadAttributes($xmlPhonebook, $this->config, $output))) {
                $output->writeln('<info>Phone numbers with special attributes saved</info>');
            } else {                                                    // no attributes are set in the FRITZ!Box or lost
                $savedAttributes = downloadAttributes($this->config['fritzbox'], $output);   // try to get last saved attributes
            }
            uploadBackgroundImage($savedAttributes, $this->config['fritzbox'], $output);
        } else {
            $output->writeln('<comment>No destination phones are defined and/or the first phone book is not selected!</comment>');
        }

        return 0;
    }
}
