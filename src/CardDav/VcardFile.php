<?php

namespace Andig\CardDav;

use Andig\CardDav\Backend;
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
     * @return array   All parsed vCards from file
     */
    public function getVcards(): array
    {
        if (empty($this->fullpath)) {
            return [];
        }

        $cards = [];
        $vcards = new VObject\Splitter\VCard(fopen($this->fullpath, 'r'));
        while ($vcard = $vcards->getNext()) {
            $vcard = $this->enrichVcard($vcard);
            $cards[] = $vcard;

            $this->progress();
        }

        return $cards;
    }
}