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
        if (count($this->config['fritzbox']['fritzfons'])) {
            error_log('Downloading FRITZ!Box phonebook');
            $xmlPhonebook = downloadPhonebook($this->config['fritzbox'], $this->config['phonebook']);
            uploadBackgroundImage($xmlPhonebook, $this->config['fritzbox']);
        }
    }
}
