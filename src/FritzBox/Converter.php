<?php

namespace Andig\FritzBox;

use Andig;
use \SimpleXMLElement;

class Converter
{
    private $config;

    /** @var SimpleXMLElement */
    private $contact;

    private $phoneSort = [];

    public function __construct(array $config)
    {
        $this->config = $config['conversions'];
        $this->phoneSort = $this->getPhoneTypesSortOrder();
    }

    /**
     * Convert Vcard to FritzBox XML
     * All conversion steps operate on $this->contact
     *
     * @param mixed $card
     * @return SimpleXMLElement[]
     */
    public function convert($card): array
    {
        $allNumbers  = $this->getPhoneNumbers($card);       // get array of prequalified phone numbers
        $adresses = $this->getEmailAdresses($card);         // get array of prequalified email adresses

        $contacts = [];
        if (count($allNumbers) > 9) {
            error_log("Contact with >9 phone numbers will be split");
        } elseif (count($allNumbers) == 0) {
            error_log("Contact without phone numbers will be skipped");
        }

        foreach (array_chunk($allNumbers, 9) as $numbers) {
            $this->contact = new SimpleXMLElement('<contact />');
            $this->contact->addChild('carddav_uid', (string)$card->UID);    // reference for image upload

            $this->addVip($card);
            $this->addPhone($numbers);

            // add eMail
            if (count($adresses)) {
                $this->addEmail($adresses);
            }

            // add Person
            $person = $this->contact->addChild('person');
            $realName = htmlspecialchars($this->getProperty($card, 'realName'));
            $person->addChild('realName', $realName);

            // add photo
            if (isset($card->PHOTO) && isset($card->IMAGEURL)) {
                $person->addChild('imageURL', (string)$card->IMAGEURL);
            }

            $contacts[] = $this->contact;
        }

        return $contacts;
    }

    /**
     * convert a phone number if conversions (phoneReplaceCharacters) are set
     *
     * @param string $number
     * @return string $number
     */
    public function convertPhonenumber($number)
    {
        // check if phone number is a SIP or internal number to avoid unwanted conversions
        if (filter_var($number, FILTER_VALIDATE_EMAIL) || substr($number, 0, 2) == '**') {
            return $number;
        }
        if (count($this->config['phoneReplaceCharacters'])) {
            $number = str_replace("\xc2\xa0", "\x20", $number);
            $number = strtr($number, $this->config['phoneReplaceCharacters']);
            $number = trim(preg_replace('/\s+/', ' ', $number));
        }

        return $number;
    }


    /**
     * Return a simple array depending on the order of phonetype conversions
     * whose order should determine the sorting of the telephone numbers
     *
     * @return array
     */
    private function getPhoneTypesSortOrder(): array
    {
        $seqArr = array_values(array_map('strtolower', $this->config['phoneTypes']));
        $seqArr[] = 'other';                               // ensures that the default value is included
        return array_unique($seqArr);                      // deletes duplicates
    }

    /**
     * add VIP node
     *
     * @param mixed $card
     * @return void
     */
    private function addVip($card)
    {
        $vipCategories = $this->config['vip'] ?? [];

        if (Andig\filtersMatch($card, $vipCategories)) {
            $this->contact->addChild('category', '1');
        }
    }

    /**
     * add phone nodes
     *
     * @param array $numbers
     * @return void
     */
    private function addPhone(array $numbers)
    {
        $telephony = $this->contact->addChild('telephony');

        foreach ($numbers as $idx => $number) {
            $phone = $telephony->addChild('number', $number['number']);
            $phone->addAttribute('id', (string)$idx);

            foreach (['type', 'quickdial', 'vanity'] as $attribute) {
                if (isset($number[$attribute])) {
                    $phone->addAttribute($attribute, $number[$attribute]);
                }
            }
        }
    }

    /**
     * add emails nodes
     *
     * @param array $addresses
     * @return void
     */
    private function addEmail(array $addresses)
    {
        $services = $this->contact->addChild('services');

        foreach ($addresses as $idx => $address) {
            $email = $services->addChild('email', $address['email']);
            $email->addAttribute('id', (string)$idx);

            if (isset($address['classifier'])) {
                $email->addAttribute('classifier', $address['classifier']);
            }
        }
    }

    /**
     * Return an array of prequalified phone numbers. This is neccesseary to
     * handle the maximum of nine phone numbers per FRITZ!Box phonebook contacts
     *
     * @param mixed $card
     * @return array
     */
    private function getPhoneNumbers($card): array
    {
        if (!isset($card->TEL)) {
            return [];
        }

        $phoneNumbers = [];
        $phoneTypes = $this->config['phoneTypes'] ?? [];
        foreach ($card->TEL as $key => $number) {
            // format number
            $number = $this->convertPhonenumber($number);
            // get type
            $type = 'other';
            $telTypes = strtoupper($card->TEL[$key]->parameters['TYPE'] ?? '');
            foreach ($phoneTypes as $phoneType => $value) {
                if (strpos($telTypes, strtoupper($phoneType)) !== false) {
                    $type = strtolower((string)$value);
                    break;
                }
            }
            if (strpos($telTypes, 'FAX') !== false) {
                $type = 'fax_work';
            }
            $addNumber = [
                'type'   => $type,
                'number' => (string)$number,
            ];
            $phoneNumbers[] = $addNumber;
        }

        // sort phone numbers
        if (count($phoneNumbers)) {
            usort($phoneNumbers, function ($a, $b) {
                $idx1 = array_search($a['type'], $this->phoneSort, true);
                $idx2 = array_search($b['type'], $this->phoneSort, true);
                if ($idx1 == $idx2) {
                    return ($a['number'] > $b['number']) ? 1 : -1;
                } else {
                    return ($idx1 > $idx2) ? 1 : -1;
                }
            });
        }

        return $phoneNumbers;
    }

    /**
     * Return an array of prequalified email adresses. There is no limitation
     * for the amount of email adresses in FRITZ!Box phonebook contacts.
     *
     * @param mixed $card
     * @return array
     */
    private function getEmailAdresses($card): array
    {
        if (!isset($card->EMAIL)) {
            return [];
        }

        $mailAdresses = [];
        $emailTypes = $this->config['emailTypes'] ?? [];

        foreach ($card->EMAIL as $key => $address) {
            $addAddress = [
                'id'    => count($mailAdresses),
                'email' => (string)$address,
            ];

            $mailTypes = strtoupper($card->EMAIL[$key]->parameters['TYPE'] ?? '');
            foreach ($emailTypes as $emailType => $value) {
                if (strpos($mailTypes, strtoupper($emailType)) !== false) {
                    $addAddress['classifier'] = strtolower($value);
                    break;
                }
            }

            $mailAdresses[] = $addAddress;
        }

        return $mailAdresses;
    }

    /**
     * Return class property with applied conversion rules
     *
     * @param mixed $card
     * @param string $property
     * @return string
     */
    private function getProperty($card, string $property): string
    {
        if (null === ($rules = @$this->config[$property])) {
            throw new \Exception("Missing conversion definition in config for [$property]");
        }

        foreach ($rules as $rule) {
            // parse rule into tokens
            $token_format = '/{([^}]+)}/';
            preg_match_all($token_format, $rule, $tokens);

            if (!count($tokens)) {
                throw new \Exception("Invalid conversion definition for `$property`");
            }

            $replacements = [];

            // check card for tokens
            foreach ($tokens[1] as $idx => $token) {
                $param = strtoupper($token);
                if (isset($card->$param) && $card->$param) {
                    $replacements[$token] = (string)$card->$param;
                }
            }

            // check if all tokens found
            if (count($replacements) !== count($tokens[0])) {
                continue;
            }

            // replace
            return preg_replace_callback($token_format, function ($match) use ($replacements) {
                $token = $match[1];
                return $replacements[$token];
            }, $rule);
        }

        error_log("No data for conversion `$property`");
        return '';
    }
}
