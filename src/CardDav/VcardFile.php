<?php

namespace Andig\CardDav;

use Andig\CardDav\Backend;
use Sabre\VObject\Document;
use Sabre\VObject;

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

    /**
     * single vCard
     * @var Document
     */
    private $vCard;

    public function __construct(string $fullpath = null)
    {
        parent::__construct();
        if ($fullpath) {
            $this->fullpath = $fullpath;
        }
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

        $cards = [];
        $vCards = new VObject\Splitter\VCard(fopen($this->fullpath, 'r'));
        while ($this->vCard = $vCards->getNext()) {
            $this->vCard = $this->enrichVcard($this->vCard);
            $cards[] = $this->vCard;

            $this->progress();
        }

        return $cards;
    }
}
