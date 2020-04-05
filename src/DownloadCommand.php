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
        error_log(sprintf("Downloaded %d vCard(s) in total", count($vcards)));

        // dissolve
        if ($input->getOption('dissolve')) {
            $vcards = $this->processGroups($vcards);
        }

        // filter
        if ($input->getOption('filter')) {
            $vcards = $this->processFilters($vcards);
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

        return 0;
    }
}
