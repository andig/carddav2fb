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
        
        // compare timestamp of CardDAV against last update on Fritz!Box
        $lastupdate = 0;
        $latestmod  = 0;
        $pb_down = downloadPhonebook ($this->config);                    // is needed for forecedupload > 1 as well  
        
        IF ($this->config['phonebook']['forcedupload'] < 2) {
            error_log("Determine the last change of the Fritz!Box phonebook");
            /// date_default_timezone_set('CET');
            $lastupdate = $pb_down->phonebook->timestamp;                // get timestamp from phonebook
        
            error_log("Determine the last change(s) on the CardDAV server(s)");
            foreach ($this->config['server'] as $server) {               // determine the youngest modification date
                $backend = backendProvider($server);
                $dummy = getlastmodification ($backend);
                IF ($dummy > $latestmod) {
                    $latestmod = $dummy;
                }
            }
        }    
        IF ($lastupdate > $latestmod) {
            error_log("Your Fritz!Box phonebook is more up to date than your CardDAV server contacts");
        }
        ELSE {
            $vcards = array();
            $xcards = array();
            
            foreach ($this->config['server'] as $server) {
                $progress = new ProgressBar($output);
                error_log("Downloading vCard(s) from account ".$server['user']);
                $backend = backendProvider($server);
                $progress->start();
                $xcards = download($backend, function () use ($progress) {
                            $progress->advance();
                });
                $progress->finish();
                $vcards = array_merge($vcards, $xcards);
                error_log(sprintf("\nDownloaded %d vCard(s)", count($vcards)));
            }

            // parse and convert
            error_log("Parsing vCards");
            $cards = parse($vcards);

            // images
            if ($input->getOption('image')) {
                error_log("Downloading images");

                $progress->start();
                $cards = downloadImages($backend, $cards, function() use ($progress) {
                    $progress->advance();
                });
                $progress->finish();

                error_log(sprintf("\nDownloaded %d image(s)", countImages($cards)));
            }

            // conversion
            $filters = $this->config['filters'];
                    
            $filtered = filter($cards, $filters);

            error_log(sprintf("Converted %d vCard(s)", count($filtered)));
            
            // fritzbox format
            $phonebook = $this->config['phonebook'];
            $conversions = $this->config['conversions'];
            $xml = export($phonebook['name'], $filtered, $conversions);
            
            // check for newer contacts in phonebook
            IF ($this->config['phonebook']['forcedupload'] < 3) {
                error_log("Checking for new entries");
                $i = checkupdates ($pb_down, $xml, $this->config);
                IF ($i > 0) {
                    error_log(sprintf("Saved %d new contact(s) from Fritz!Box phonebook", $i));
                }
            }
            
            // upload
            error_log("Uploading");

            $xmlStr = $xml->asXML();

            upload($xmlStr, $this->config);

            error_log("Uploaded new Fritz!Box phonebook");
    
        } // end of else
    }
}