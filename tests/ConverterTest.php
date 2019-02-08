<?php

use \Andig\FritzBox\Converter;
use \PHPUnit\Framework\TestCase;

class ConverterTest extends TestCase
{
    public function setUp()
    {
        $minimalConfig = [
            'conversions' => [
                'phoneTypes' => [],
                'realName' => [],
            ],
        ];

        $this->converter = new Converter($minimalConfig);
    }

    public function testMoreThan10Emails()
    {
        $c = new stdClass;
        $c->uid = 'uid';
        $c->phone = [];

        for ($i=1; $i<=18; $i++) {
            $c->phone[] = [
                'business' => (string)$i
            ];
        }

        $res = $this->converter->convert($c);
        $this->assertInternalType('array', $res);
        $this->assertCount(2, $res);

        foreach ($res as $idx => $contact) {
            $this->assertInstanceOf(SimpleXMLElement::class, $contact->telephony);
            $this->assertInstanceOf(SimpleXMLElement::class, $contact->telephony->number);

            for ($i=1; $i<=9; $i++) {
                $expect = $idx * 9 + $i;
                $this->assertContains((string)$expect, $contact->telephony->number);
            }
        }
    }
}
