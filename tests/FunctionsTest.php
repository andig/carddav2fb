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
