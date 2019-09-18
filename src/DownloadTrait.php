<?php

namespace Andig;

use Andig\CardDav\Backend;
use Sabre\VObject\Document;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;

trait DownloadTrait
{
    /**
     * Download vcards from single provider
     *
     * @param OutputInterface $output
     * @param Backend $provider
     * @return Document[]
     */
    function downloadProvider(OutputInterface $output, Backend $provider): array {
        $progress = new ProgressBar($output);
        $progress->start();
        $cards = download($provider, function () use ($progress) {
            $progress->advance();
        });
        $progress->finish();
        return $cards;
    }

    /**
     * Download vcards from all configured providers
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return Document[]
     */
    function downloadAllProviders(InputInterface $input, OutputInterface $output): array {
        $vcards = [];

        foreach ($this->config['local'] as $file) {
            error_log("Reading vCard(s) from file ".$file);

            $provider = localProvider($file);
            $cards = $this->downloadProvider($output, $provider);

            error_log(sprintf("\nRead %d vCard(s)", count($cards)));
            $vcards = array_merge($vcards, $cards);
        }

        $substitutes = ($input->getOption('image')) ? ['PHOTO'] : [];
        foreach ($this->config['server'] as $server) {
            error_log("Downloading vCard(s) from account ".$server['user']);

            $provider = backendProvider($server);
            $provider->setSubstitutes($substitutes);
            $cards = $this->downloadProvider($output, $provider);

            error_log(sprintf("\nDownloaded %d vCard(s)", count($cards)));
            $vcards = array_merge($vcards, $cards);
        }
    }
}
