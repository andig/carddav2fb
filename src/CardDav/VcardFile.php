<?php

namespace Andig\CardDav;

use Andig\CardDav\Backend;
use Sabre\VObject\Document;
use Sabre\VObject\Splitter\VCard;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Volker Püschel <knuffy@anasco.de>
 * @license MIT
 */

class VcardFile extends Backend
{
    /**
     * local path and filename
     * @var string
     */
    private $fullpath;

    public function __construct(OutputInterface $output, string $fullpath = null)
    {
        parent::__construct($output);
        $this->fullpath = $fullpath;
    }

    /**
     * Gets all vCards including additional information from the local file
     *
     * @return Document[] All parsed vCards from file
     */
    public function getVcards(): array
    {
        if (empty($this->fullpath)) {
            return [];
        }
        if (!file_exists($this->fullpath)) {
            $error = sprintf('File %s not found!', $this->fullpath);
            $this->output->writeln('<error>' . $error . '</error>');

            return [];
        }
        $vcf = fopen($this->fullpath, 'r');
        if (!$vcf) {
            $error = sprintf('File %s open failed!', $this->fullpath);
            $this->output->writeln('<error>' . $error . '</error>');

            return [];
        }
        $cards = [];
        $vCards = new VCard($vcf);
        while ($vCard = $vCards->getNext()) {
            if ($vCard instanceof Document) {
                $cards[] = $this->enrichVcard($vCard);
            } else {
                $error = 'Unexpected type: ' . get_class($vCard);
                $this->output->writeln('<error>' . $error . '</error>');
            }

            $this->progress();
        }

        return $cards;
    }
}
