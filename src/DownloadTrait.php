<?php

namespace Andig;

use Andig\CardDav\Backend;
use Sabre\VObject\Document;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;

trait DownloadTrait
{
    /**
     * get progress bar in info scheme (green)
     *
     * @param OutputInterface $output
     * @return ProgressBar
     */
    public function getProgressBar(OutputInterface $output): ProgressBar
    {
        $progress = new ProgressBar($output);
        $progress->setFormatDefinition('info', '<info>%current% [%bar%]</info>');
        $progress->setFormat('info');

        return $progress;
    }

    /**
     * Default list of card attributes to substitute
     *
     * @return array
     */
    public function getDefaultSubstitutes(): array
    {
        return ['PHOTO'];
    }

    /**
     * Download vcards from single provider
     *
     * @param OutputInterface $output
     * @param Backend $provider
     * @return Document[]
     */
    public function downloadProvider(OutputInterface $output, Backend $provider): array
    {
        $progress = self::getProgressBar($output);
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
     * @param OutputInterface $output
     * @param bool $downloadImages
     * @param string[] $local
     * @return Document[]
     */
    public function downloadAllProviders(OutputInterface $output, bool $downloadImages, array $local = []): array
    {
        $vcards = [];

        foreach ($local as $file) {
            $output->writeln('<info>Reading vCard(s) from file '. $file . '</info>');

            $provider = localProvider($file);
            $cards = $this->downloadProvider($output, $provider);

            $info = sprintf("\nRead %d vCard(s)", count($cards));
            $output->writeln('<info>' . $info . '</info>');
            $vcards = array_merge($vcards, $cards);
        }

        foreach ($this->config['server'] as $server) {
            $output->writeln('<info>Downloading vCard(s) from account ' . $server['user'] . '</info>');

            $provider = backendProvider($server, $output);
            if ($downloadImages) {
                $substitutes = $this->getDefaultSubstitutes();
                $provider->setSubstitutes($substitutes);
            }
            $cards = $this->downloadProvider($output, $provider);

            $info = sprintf("\nDownloaded %d vCard(s)", count($cards));
            $output->writeln('<info>' . $info . '</info>');
            $vcards = array_merge($vcards, $cards);
        }

        return $vcards;
    }

    /**
     * Dissolve the groups of iCloud contacts
     *
     * @param mixed[] $vcards
     * @return mixed[]
     */
    public function processGroups(array $vcards, $output): array
    {
        $quantity = count($vcards);
        $output->writeln('<info>Dissolving groups (e.g. iCloud)</info>');
        $vcards = dissolveGroups($vcards);
        $info = sprintf("Dissolved %d group(s)", $quantity - count($vcards));
        $output->writeln('<info>' . $info . '</info>');

        return $vcards;
    }

    /**
     * Filter included/excluded vcards
     *
     * @param mixed[] $vcards
     * @param OutputInterface $output
     * @return mixed[]
     */
    public function processFilters(array $vcards, OutputInterface $output): array
    {
        $quantity = count($vcards);

        $info = sprintf("Filtering %d vCard(s)", $quantity);
        $output->writeln('<info>' . $info . '</info>');
        $vcards = filter($vcards, $this->config['filters'], $output);
        $info = sprintf("Filtered %d vCard(s)", $quantity - count($vcards));
        $output->writeln('<info>' . $info . '</info>');

        return $vcards;
    }
}
