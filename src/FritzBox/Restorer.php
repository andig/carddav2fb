<?php

namespace Andig\FritzBox;

use Andig\FritzBox\Converter;
use Sabre\VObject\Document;
use \SimpleXMLElement;

/**
 * Copyright (c) 2019 Volker Püschel
 * @license MIT
 */

class Restorer
{
    const CSV_HEADER = 'uid,number,id,type,quickdial,vanity,prio,name';

    private $collums = [];

    public function __construct()
    {
        $this->collums = explode(',', self::CSV_HEADER);
    }

    /**
     * get an empty associated array according to CSV_HEADER
     * [ 'number' => '',
     *   'id' => '',
     *   'type' => '',
     *   'quickdial' => '',
     *   'vanity' => '',
     *   'prio' => '',
     *   'name' => '']
     *
     * @return array
     */
    private function getPlainArray()
    {
        $csvHeader = explode(',', self::CSV_HEADER);
        $dump = array_shift($csvHeader);            // eliminate the first column header (uid)

        return array_fill_keys($csvHeader, '');
    }

    /**
     * Get quickdial and vanity special attributes and
     * internal numbers ('**[n]' from given XML phone book
     * return is an array according to CSV_HEADER:
     * ['foo-bar' => [            // uid
     *      'number' => '1',
     *      'id' => '1',
     *      'type' => 'foo',
     *      'quickdial' => '1',
     *      'vanity' => 'bar',
     *      'prio' => '1',
     *      'name' => 'baz']
     * ],
     *
     * @param SimpleXMLElement $xmlPhonebook
     * @return array an array of special attributes with CardDAV UID as key
     */
    public function getPhonebookData(SimpleXMLElement $xmlPhonebook, array $conversions)
    {
        if (!property_exists($xmlPhonebook, "phonebook")) {
            return [];
        }

        $converter = new Converter($conversions);
        $phonebookData = [];
        $numbers = $xmlPhonebook->xpath('//number[@quickdial or @vanity] | //number[starts-with(text(),"**")]');
        foreach ($numbers as $number) {
            if (preg_match('/00@hd-telefonie.avm.de/', (string)$number)) {
                continue;
            }
            $attributes = $this->getPlainArray();                   // it´s easier to handle with the full set
            // regardless of how the number was previously converted, the current config is applied here
            $attributes['number'] = $converter->convertPhonenumber((string)$number);
            // get all phone number attibutes
            foreach ($number->attributes() as $key => $value) {
                $attributes[(string)$key] = (string)$value;
            }
            // get the contacts header data (name and UID)
            $contact = $number->xpath("./ancestor::contact");
            $attributes['name'] = (string)$contact[0]->person->realName;
            $uid = (string)$contact[0]->carddav_uid ?: uniqid();
            $phonebookData[$uid] = $attributes;
        }

        return $phonebookData;
    }

    /**
     * get a xml contact structure from saved internal numbers
     *
     * @param string $uid
     * @param array $internalNumber
     * @return SimpleXMLElement $contact
     */
    private function getInternalContact(string $uid, array $internalNumber)
    {
        $contact = new SimpleXMLElement('<contact />');
        $contact->addChild('carddav_uid', $uid);
        $telephony = $contact->addChild('telephony');
        $number = $telephony->addChild('number', $internalNumber['number']);
        $number->addAttribute('id', $internalNumber['id']);
        $number->addAttribute('type', $internalNumber['type']);
        $person = $contact->addChild('person');
        $person->addChild('realName', $internalNumber['name']);

        return $contact;
    }

    /**
     * Attach xml element to parent
     * https://stackoverflow.com/questions/4778865/php-simplexml-addchild-with-another-simplexmlelement
     *
     * @param SimpleXMLElement $to
     * @param SimpleXMLElement $from
     * @return void
     */
    public function xml_adopt(SimpleXMLElement $to, SimpleXMLElement $from)
    {
        $toDom = dom_import_simplexml($to);
        $fromDom = dom_import_simplexml($from);
        $toDom->appendChild($toDom->ownerDocument->importNode($fromDom, true));
    }

    /**
     * Restore special attributes (quickdial, vanity) and internal phone numbers
     * in given target phone book
     *
     * @param SimpleXMLElement $xmlTargetPhoneBook
     * @param array $attributes array of special attributes
     * @return SimpleXMLElement phonebook with restored special attributes
     */
    public function setPhonebookData(SimpleXMLElement $xmlTargetPhoneBook, array $attributes)
    {
        $root = $xmlTargetPhoneBook->xpath('//phonebook')[0];

        error_log('Restoring saved attributes (quickdial, vanity) and internal numbers');
        foreach ($attributes as $key => $values) {
            if (substr($values['number'], 0, 2) == '**') {      // internal number
                $contact = $this->getInternalContact($key, $values);
                $this->xml_adopt($root, $contact);              // add contact with internal number
            }
            if ($contact = $xmlTargetPhoneBook->xpath(sprintf('//contact[carddav_uid = "%s"]', $key))) {
                if ($phone = $contact[0]->xpath(sprintf("telephony/number[text() = '%s']", $values['number']))) {
                    foreach (['quickdial', 'vanity'] as $attribute) {
                        if (!empty($values[$attribute])) {
                            if (isset($phone[0]->attributes()->$attribute)) {
                                $phone[0]->attributes()->$attribute = $values[$attribute];
                            } else {
                                $phone[0]->addAttribute($attribute, $values[$attribute]);
                            }
                        }
                    }
                }
            }
        }

        return $xmlTargetPhoneBook;
    }

    /**
     * convert internal phonbook data (array of SimpleXMLElement) to string (rows of csv)
     *
     * @param array $phonebookData
     * @return string $row csv
     */
    public function phonebookDataToCSV($phonebookData)
    {
        $row = self::CSV_HEADER . PHP_EOL;                            // csv header row
        foreach ($phonebookData as $uid => $values) {
            $row .= $uid;                                 // array key first collum
            foreach ($values as $key => $value) {
                if ($key == 'name') {
                    $value = '"' . $value . '"';
                }
                $row .= ',' . $value;                           // values => collums
            }
            if (next($phonebookData) == true) {
                $row .= PHP_EOL;
            }
        }

        return $row;
    }

    /**
     * convert csv line to internal phonbook data
     *
     * @param array $csvRow
     * @return array $phonebookData
     */
    public function csvToPhonebookData($csvRow)
    {
        $rows = '';
        $uid = '';
        $phonebookData = [];

        if (count($csvRow) <> count($this->collums)) {
            throw new \Exception('The number of csv columns does not match the default!');
        }
        if ($csvRow <> $this->collums) {                    // values equal CSV_HEADER
            foreach ($csvRow as $key => $value) {
                if ($key == 0) {
                    $uid = $value;
                } else {
                    $phonebookData[$uid][$this->collums[$key]] = $value;
                }
            }
        }

        return $phonebookData;
    }
}
