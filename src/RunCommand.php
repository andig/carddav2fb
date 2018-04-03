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
   
    
    protected function configure()
    {
        $this->setName('run')
            ->setDescription('Download, convert and upload - all in one')
            ->addOption('image', 'i', InputOption::VALUE_NONE, 'download images');

        $this->addConfig();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->loadConfig($input);

        $vcards = array();
        $xcards = array();
        if ($input->getOption('image')) {
            $substitutes[] = 'PHOTO';
        }
        else {
            $substitutes = [];
        }
        foreach ($this->config['server'] as $server) {
            $progress = new ProgressBar($output);
            error_log("Downloading vCard(s) from account ".$server['user']);
            $backend = backendProvider($server);
            $progress->start();
            $xcards = download($backend, $substitutes, function () use ($progress) {
                $progress->advance();
            });
            $progress->finish();
            $vcards = array_merge($vcards, $xcards);
            error_log(sprintf("\nDownloaded %d vCard(s)", count($vcards)));
        }

        // parse and convert
        error_log("Parsing vCards");
        $cards = parse($vcards);

        // conversion
        $filters = $this->config['filters'];
        $filtered = filter($cards, $filters);
        error_log(sprintf("Converted and filtered %d vCard(s)", count($filtered)));

        // images
        if ($input->getOption('image')) {
            error_log("Detaching and storing image(s)");
            $new_files = storeImages($filtered, $this->config['script']['cache']);
            $pictures = count($new_files);
            error_log(sprintf("Temporarily stored %d image file(s)", $pictures));
            if ($pictures > 0) {
                $pictures = uploadImages ($new_files, $this->config['fritzbox']);
                error_log(sprintf("Uploaded %d image file(s)", $pictures));
            }
        }
        else {
            unset($this->config['phonebook']['imagepath']);
        }

        // fritzbox format
        $xml = export($filtered, $this->config);

        // upload
        error_log("Uploading");
        $xmlStr = $xml->asXML();
        if (upload($xmlStr, $this->config) === true) {;
            error_log("Successful uploaded new Fritz!Box phonebook");
        }
    }
}