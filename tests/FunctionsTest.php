<?php

use \PHPUnit\Framework\TestCase;
use Sabre\VObject;

class FunctionsTest extends TestCase
{
    /** @var \stdClass */
    public $contacts;

    public function setUp()
    {
        $this->contacts = $this->defaultContacts();
    }

    private function defaultContacts()
    {
        // definition of the test-takers
        $contacts = [
            [
                'CATEGORIES' => ['foo', 'bar'],
                'GROUPS' => ['baz', 'qux'],
            ],
            [
                'CATEGORIES' => ['bar', 'qux'],
                'GROUPS' => ['foo', 'baz'],
            ],
            [
                'CATEGORIES' => ['foo'],
                'GROUPS' => ['bar'],
            ],
            [
                'CATEGORIES' => ['baz'],
                'GROUPS' => ['qux'],
            ],
        ];

        // assambling the four different test-takers
        /** @var \stdClass $vcard */
        foreach ($contacts as $key => $contact) {
            $vcard = new VObject\Component\VCard([
                'UID' =>  $key
            ]);
            foreach (['CATEGORIES', 'GROUPS'] as $property) {
                $vcard->$property = $contact[$property];
            }
            $vcards[] = $vcard;
        }

        return $vcards;
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
                <number id="0" type="work">09131 123456</number>
                <number id="1" type="home">0911 123456</number>
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
                <number id="0" type="work">09131 345678</number>
                <number id="1" type="home">0911 345678</number>
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

    private function injectQuickDialAndVanity(SimpleXMLElement $phonebook, $contIndex, $numIndex, $qd, $van): SimpleXMLElement
    {
        $phonebook->phonebook->contact[$contIndex]->telephony->number[$numIndex]['quickdial'] = $qd;
        $phonebook->phonebook->contact[$contIndex]->telephony->number[$numIndex]['vanity'] = $van;
        return $phonebook;
    }

    public function testGetPhoneNumberAttributes()
    {
        $phonebook = $this->defaultPhonebook();
        $phonebook = $this->injectQuickDialAndVanity($phonebook, 0, 0, "11", "AX");

        $attributes = Andig\getPhoneNumberAttributes($phonebook);

        // This key should be there
        $expectedKey = 'ABCDEFGH-8AA4-4389-A2BE-18A42A61D24D';
        $this->assertEquals(1, count($attributes));
        $this->assertArrayHasKey($expectedKey, $attributes);

        // Now check if expected quickdial / vanity attributes have been found
        $this->assertEquals('11', $attributes[$expectedKey]['quickdial']);
        $this->assertEquals('AX', $attributes[$expectedKey]['vanity']);
    }

    public function testMergePhoneNumberAttributes()
    {
        $phonebook = $this->defaultPhonebook();
        $phonebook = $this->injectQuickDialAndVanity($phonebook, 0, 0, "11", "AX");
        $phonebook = $this->injectQuickDialAndVanity($phonebook, 1, 1, "22", "AG");

        $attributes = Andig\getPhoneNumberAttributes($phonebook);
        $newPhoneBook = Andig\mergePhoneNumberAttributes($phonebook, $attributes);

        // Here we expect quickdial & vanity
        $this->assertEquals('11', $newPhoneBook->phonebook->contact[0]->telephony->number[0]['quickdial']);
        $this->assertEquals('22', $newPhoneBook->phonebook->contact[1]->telephony->number[1]['quickdial']);
        $this->assertEquals('AX', $newPhoneBook->phonebook->contact[0]->telephony->number[0]['vanity']);
        $this->assertEquals('AG', $newPhoneBook->phonebook->contact[1]->telephony->number[1]['vanity']);

        // These contact numbers should NOT have quickdial or vanity
        $this->assertEquals(false, isset($newPhoneBook->phonebook->contact[0]->telephony->number[1]['quickdial']));
        $this->assertEquals(false, isset($newPhoneBook->phonebook->contact[1]->telephony->number[0]['quickdial']));
        $this->assertEquals(false, isset($newPhoneBook->phonebook->contact[0]->telephony->number[1]['vanity']));
        $this->assertEquals(false, isset($newPhoneBook->phonebook->contact[1]->telephony->number[0]['vanity']));
    }

    public function filtersPropertiesProvider(): array
    {
        return [
            [
                [
                   'include' => [
                    ],
                    'exclude' => [
                        'categories' => ['qux', 'baz'],
                        'groups' => [],
                    ],
                ],
                2
            ],
            [
                [
                    'include' => [
                        'categories' => ['foo'],
                        'groups' => ['foo'],
                    ],
                    'exclude' => [],
                ],
                3
            ],
            [
                [
                    'include' => [
                        'categories' => ['foo', 'baz'],
                    ],
                    'exclude' => [
                        'groups' => ['bar'],
                    ],
                ],
                2
            ],
        ];
    }

    /**
     * @dataProvider filtersPropertiesProvider
     */
    public function testFilter(array $filter, int $residually)
    {
        $res = Andig\filter($this->contacts, $filter);
        $this->assertCount($residually, $res);
    }
}
