<?php

use \Andig\FritzBox\Restorer;
use \PHPUnit\Framework\TestCase;

class RestorerTest extends TestCase
{
    /** @var Restorer */
    public $restore;

    public function setUp()
    {
        $this->restore = new Restorer;
    }

    private function defaultConfig(): array
    {
        return [
            'conversions' => [
                'phoneTypes' => [
                    'WORK' => 'work',
                    'HOME' => 'home',
                    'CELL' => 'mobile',
                    'FAX' => 'fax_work',
                ],
                'phoneReplaceCharacters' => [
                    '+49' => '',
                    '('   => '',
                    ')'   => '',
                    '@'   => '',
                    '/'   => '',
                    '-'   => '',
                ],
            ],
        ];
    }

    private function defaultPhonebook(): SimpleXMLElement
    {
        $xml =                 <<<EOD
<?xml version="1.0" encoding="UTF-8"?>
<phonebooks>
    <phonebook name="Telefonbuch">
        <contact>
            <carddav_uid>ABCDEFGH-8AA4-4389-A2BE-18A42A61D24D</carddav_uid>
            <telephony>
                <number id="0" type="work">09131123456</number>
                <number id="1" type="home">0911123456</number>
                <number id="2" type="mobile">0152123456</number>
            </telephony>
            <person>
                <realName>Mustermann, Max</realName>
                <imageURL>file:///var/InternerSpeicher/MyUSB/FRITZ/fonpix/ABCDEFGH-8AA4-4389-A2BE-18A42A61D24D.jpg</imageURL>
            </person>
        </contact>
        <contact>
            <carddav_uid>ABCDEFGH-8AA4-4389-A2BE-18A42A61D24E</carddav_uid>
            <telephony>
                <number id="0" type="work">09131345678</number>
                <number id="1" type="home">0911345678</number>
                <number id="2" type="mobile">0152345678</number>
            </telephony>
            <person>
                <realName>Mustermann, Marianne</realName>
                <imageURL>file:///var/InternerSpeicher/MyUSB/FRITZ/fonpix/ABCDEFGH-8AA4-4389-A2BE-18A42A61D24E.jpg</imageURL>
            </person>
        </contact>
    </phonebook>
</phonebooks>
EOD;

        return simplexml_load_string($xml);
    }

    private function defaultCSV(): string
    {
        $header = $this->restore::CSV_HEADER;
        $csv = <<<EOD
$header
ABCDEFGH-8AA4-4389-A2BE-18A42A61D24D,09131123456,0,home,8,,1,""
E2B70D3D-FF6E-4E14-98C5-B334E18BF98D,**611,0,home,99,,1,Gruppenruf
ABCDEFGH-8AA4-4389-A2BE-18A42A61D24E,0152345678,0,home,,FOO,1,""
EOD;

    return $csv;
    }

    private function injectQuickDialAndVanity(SimpleXMLElement $phonebook, $contIndex, $numIndex, $qd, $van): SimpleXMLElement
    {
        $phonebook->phonebook->contact[$contIndex]->telephony->number[$numIndex]['quickdial'] = $qd;
        $phonebook->phonebook->contact[$contIndex]->telephony->number[$numIndex]['vanity'] = $van;
        return $phonebook;
    }

    public function testgetPhonebookData()
    {
        $phonebook = $this->defaultPhonebook();
        $phonebook = $this->injectQuickDialAndVanity($phonebook, 0, 0, "11", "AX");

        $attributes = $this->restore->getPhonebookData($phonebook, $this->defaultconfig());

        // This key should be there
        $expectedKey = 'ABCDEFGH-8AA4-4389-A2BE-18A42A61D24D';
        $this->assertEquals(1, count($attributes));
        $this->assertArrayHasKey($expectedKey, $attributes);

        // Now check if expected quickdial / vanity attributes have been found
        $this->assertEquals('11', $attributes[$expectedKey]['quickdial']);
        $this->assertEquals('AX', $attributes[$expectedKey]['vanity']);

        $newCSV = $this->restore->phonebookDataToCSV($attributes);
        $rows = explode(PHP_EOL, $newCSV);

        $this->assertEquals(2, count($rows));
        $this->assertEquals($this->restore::CSV_HEADER, $rows[0]);
    }

    public function testsetPhonebookData()
    {
        $attributes = [];
        $rows = explode(PHP_EOL, $this->defaultCSV());
        foreach ($rows as $row) {
            $csvRow = explode(',', $row);
            $attributes = array_merge($this->restore->csvToPhonebookData($csvRow), $attributes);
        }

        $this->assertEquals(3, count($attributes));

        $newPhoneBook = $this->restore->setPhonebookData($this->defaultPhonebook(), $attributes);

        $res = $newPhoneBook->xpath('//contact');
        $this->assertEquals(3, count($res));

        $this->assertEquals('8', $newPhoneBook->phonebook->contact[0]->telephony->number[0]['quickdial']);
        $this->assertEquals(false, isset($newPhoneBook->phonebook->contact[0]->telephony->number[0]['vanity']));
        $this->assertEquals(false, isset($newPhoneBook->phonebook->contact[0]->telephony->number[1]['quickdial']));
        $this->assertEquals(false, isset($newPhoneBook->phonebook->contact[0]->telephony->number[1]['vanity']));
        $this->assertEquals(false, isset($newPhoneBook->phonebook->contact[0]->telephony->number[2]['quickdial']));
        $this->assertEquals(false, isset($newPhoneBook->phonebook->contact[0]->telephony->number[2]['vanity']));

        $this->assertEquals(false, isset($newPhoneBook->phonebook->contact[1]->telephony->number[0]['quickdial']));
        $this->assertEquals(false, isset($newPhoneBook->phonebook->contact[1]->telephony->number[0]['vanity']));
        $this->assertEquals(false, isset($newPhoneBook->phonebook->contact[1]->telephony->number[1]['quickdial']));
        $this->assertEquals(false, isset($newPhoneBook->phonebook->contact[1]->telephony->number[1]['vanity']));
        $this->assertEquals('FOO', $newPhoneBook->phonebook->contact[1]->telephony->number[2]['vanity']);
        $this->assertEquals(false, isset($newPhoneBook->phonebook->contact[1]->telephony->number[2]['quickdial']));

        $this->assertEquals(1, count($newPhoneBook->phonebook->contact[2]->telephony->number));
        $this->assertEquals('E2B70D3D-FF6E-4E14-98C5-B334E18BF98D', $newPhoneBook->phonebook->contact[2]->carddav_uid);
        $this->assertEquals('Gruppenruf', $newPhoneBook->phonebook->contact[2]->person->realName);
        $this->assertEquals('**611', $newPhoneBook->phonebook->contact[2]->telephony->number[0]);
        $this->assertEquals('99', $newPhoneBook->phonebook->contact[2]->telephony->number[0]['quickdial']);
        $this->assertEquals(false, isset($newPhoneBook->phonebook->contact[2]->telephony->number[0]['vanity']));
    }
}
