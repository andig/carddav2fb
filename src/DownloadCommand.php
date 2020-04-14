<?php

namespace Andig;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
            ->addOption('image', 'i', InputOption::VALUE_NONE, 'download images')
            ->addOption('local', 'l', InputOption::VALUE_OPTIONAL|InputOption::VALUE_IS_ARRAY, 'local file(s)');

        $this->addConfig();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->loadConfig($input);

        // download from server or local files
        $local = $input->getOption('local');
        $vcards = $this->downloadAllProviders($output, $input->getOption('image'), $local);
        $info = sprintf("Downloaded %d vCard(s) in total", count($vcards));
        $output->writeln('<info>' . $info . '</info>');

        // dissolve
        if ($input->getOption('dissolve')) {
            $vcards = $this->processGroups($vcards, $output);
        }

        // filter
        if ($input->getOption('filter')) {
            $vcards = $this->processFilters($vcards, $output);
        }

        // save to file
        $vCardContents = '';
        foreach ($vcards as $vcard) {
            $vCardContents .= $vcard->serialize();
        }

        $filename = $input->getArgument('filename');
        if (file_put_contents($filename, $vCardContents) != false) {
            $info = sprintf("Succesfully saved vCard(s) in %s", $filename);
            $output->writeln('<info>' . $info . '</info>');
        }

        return 0;
    }
}
