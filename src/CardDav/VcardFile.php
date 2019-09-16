<?php

namespace Andig\CardDav;

use Andig\CardDav\Backend;
use Sabre\VObject\Document;
use Sabre\VObject\Splitter\VCard;

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

    public function __construct(string $fullpath = null)
    {
        parent::__construct();
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
            error_log(sprintf('File %s not found!', $this->fullpath));
            return [];
        }
        $vcf = fopen($this->fullpath, 'r');
        if (!$vcf) {
            error_log(sprintf('File %s open failed!', $this->fullpath));
            return [];
        }
        $cards = [];
        $vCards = new VCard($vcf);
        while ($vCard = $vCards->getNext()) {
            if ($vCard instanceof Document) {
                $cards[] = $this->enrichVcard($vCard);
            } else {
                error_log('Unexpected type: ' . get_class($vCard));
            }

            $this->progress();
        }

        return $cards;
    }
}
