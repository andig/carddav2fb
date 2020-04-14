<?php

namespace Andig;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UploadCommand extends Command
{
    use ConfigTrait;

    protected function configure()
    {
        $this->setName('upload')
            ->setDescription('Upload to FRITZ!Box')
            ->addArgument('filename', InputArgument::REQUIRED, 'filename');

        $this->addConfig();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->loadConfig($input);
        $savedAttributes = [];

        $filename = $input->getArgument('filename');
        $xmlPhonebookStr = file_get_contents($filename);
        $xmlPhonebook = simplexml_load_string($xmlPhonebookStr);

        if ($this->config['phonebook']['id'] == 0) {                // only the first phonebook has special attributes
            $savedAttributes = downloadAttributes($this->config['fritzbox'], $output);   // try to get last saved attributes
            $xmlPhonebook = mergeAttributes($xmlPhonebook, $savedAttributes, $output);
        }

        $output->writeln('<info>Uploading FRITZ!Box phonebook</info>');
        uploadPhonebook($xmlPhonebook, $this->config);
        $output->writeln('<info>Successful uploaded new FRITZ!Box phonebook</info>');

        return 1;
    }
}
