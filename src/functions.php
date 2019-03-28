<?php

namespace Andig;

use Andig\CardDav\Backend;
use Andig\Vcard\Parser;
use Andig\FritzBox\Converter;
use Andig\FritzBox\Api;
use \SimpleXMLElement;
use \stdClass;

define("MAX_IMAGE_COUNT", 150); // see: https://avm.de/service/fritzbox/fritzbox-7490/wissensdatenbank/publication/show/300_Hintergrund-und-Anruferbilder-in-FRITZ-Fon-einrichten/

/**
 * Initialize backend from configuration
 *
 * @param array $config
 * @return Backend
 */
function backendProvider(array $config): Backend
{
    $options = $config['server'] ?? $config;

    $backend = new Backend($options['url']);
    $backend->setAuth($options['user'], $options['password']);
    $backend->mergeClientOptions($options['http'] ?? []);

    return $backend;
}

/**
 * Download vcards from CardDAV server
 *
 * @param Backend $backend
 * @param callable $callback
 * @return array
 */
function download(Backend $backend, $substitutes, callable $callback=null): array
{
    $backend->setProgress($callback);
    $backend->setSubstitutes($substitutes);
    return $backend->getVcards();
}

/**
 * set up a stable FTP connection to a designated destination
 * @param array $config
 * @param string $pathKey array key to the destination path
 * @param string $purpose part of error message
 * @return resource 
 */
function setUpFTPConnection ($config, $pathKey, $purpose)
{
    $fritzbox = $config['fritzbox'];

    $ftpserver = parse_url($fritzbox['url'], PHP_URL_HOST) ? parse_url($fritzbox['url'], PHP_URL_HOST) : $fritzbox['url'];
    $connectFunc = (@$fritzbox['plainFTP']) ? 'ftp_connect' : 'ftp_ssl_connect';

    if ($connectFunc == 'ftp_ssl_connect' && !function_exists('ftp_ssl_connect')) {
        throw new \Exception("PHP lacks support for 'ftp_ssl_connect', please use `plainFTP` to switch to unencrypted FTP");
    }

    if (false === ($ftp_conn = $connectFunc($ftpserver))) {
        $message = sprintf("Could not connect to ftp server %s for %s upload",$ftpserver , $purpose);
        throw new \Exception($message);
    }
    if (!ftp_login($ftp_conn, $fritzbox['user'], $fritzbox['password'])) {
        $message = sprintf("Could not log in %s to ftp server %s for %s upload", $fritzbox['user'], $ftpserver, $purpose);
        throw new \Exception($message);
    }
    if (!ftp_pasv($ftp_conn, true)) {
        $message = sprintf("Could not switch to passive mode on ftp server %s for s% upload", $ftpserver, $purpose);
        throw new \Exception($message);
    }
    if (!ftp_chdir($ftp_conn, $fritzbox[$pathKey])) {
        $message = sprintf("Could not change to dir %s on ftp server %s for %s upload", $fritzbox[$pathKey], $ftpserver, $purpose);
        throw new \Exception($message);
    }
    return $ftp_conn;
}

/**
 * upload image files via ftp to the fritzbox fonpix directory
 *
 * @param stdClass[] $vcards downloaded vCards
 * @param array $config
 * @param callable $callback
 * @return mixed false or [number of uploaded images, number of total found images]
 */
function uploadImages(array $vcards, array $config, callable $callback=null)
{
    $countUploadedImages = 0;
    $countAllImages = 0;
    $mapFTPUIDtoFTPImageName = [];                      // "9e40f1f9-33df-495d-90fe-3a1e23374762" => "9e40f1f9-33df-495d-90fe-3a1e23374762_190106123906.jpg"
    $timestampPostfix = substr(date("YmdHis"), 2);      // timestamp, e.g., 190106123906

    if (null == ($imgPath = @$config['phonebook']['imagepath'])) {
        throw new \Exception('Missing phonebook/imagepath in config. Image upload not possible.');
    }
    $imgPath = rtrim($imgPath, '/') . '/';  // ensure one slash at end

    // Prepare FTP connection
    $ftp_conn = setUpFTPConnection ($config, 'fonpix', 'image');
    
    // Build up dictionary to look up UID => current FTP image file
    if (false === ($ftpFiles = ftp_nlist($ftp_conn, "."))) {
        $ftpFiles = [];
    }

    foreach ($ftpFiles as $ftpFile) {
        $ftpUid = preg_replace("/\_.*/", "", $ftpFile);  // new filename with time stamp postfix
        $ftpUid = preg_replace("/\.jpg/i", "", $ftpUid); // old filename
        $mapFTPUIDtoFTPImageName[$ftpUid] = $ftpFile;
    }

    foreach ($vcards as $vcard) {
        if (is_callable($callback)) {
            ($callback)();
        }

        if (isset($vcard->rawPhoto)) {                                     // skip vCards without image
            if (preg_match("/JPEG/", strtoupper(substr($vcard->photoData, 0, 256)))) {     // Fritz!Box only accept jpg-files
                $countAllImages++;

                // Check if we can skip upload
                $newFTPimage = sprintf('%1$s_%2$s.jpg', $vcard->uid, $timestampPostfix);
                if (array_key_exists($vcard->uid, $mapFTPUIDtoFTPImageName)) {
                    $currentFTPimage = $mapFTPUIDtoFTPImageName[$vcard->uid];
                    if (ftp_size($ftp_conn, $currentFTPimage) == strlen($vcard->rawPhoto)) {
                        // No upload needed, but store old image URL in vCard
                        $vcard->imageURL = $imgPath . $currentFTPimage;
                        continue;
                    }
                    // we already have an old image, but the new image differs in size
                    ftp_delete($ftp_conn, $currentFTPimage);
                }

                // Upload new image file
                $memstream = fopen('php://memory', 'r+');     // we use a fast in-memory file stream
                fputs($memstream, $vcard->rawPhoto);
                rewind($memstream);

                // upload new image
                if (ftp_fput($ftp_conn, $newFTPimage, $memstream, FTP_BINARY)) {
                    $countUploadedImages++;
                    // upload of new image done, now store new image URL in vCard (new Random Postfix!)
                    $vcard->imageURL = $imgPath . $newFTPimage;
                } else {
                    error_log(PHP_EOL."Error uploading $newFTPimage.");
                    unset($vcard->rawPhoto);                           // no wrong link will set in phonebook
                    unset($vcard->imageURL);                           // no wrong link will set in phonebook
                }
                fclose($memstream);
            }
        }
    }
    ftp_close($ftp_conn);

    if ($countAllImages > MAX_IMAGE_COUNT) {
        error_log(sprintf(<<<EOD
WARNING: You have %d contact images on FritzBox. FritzFon may handle only up to %d images.
         Some images may not display properly, see: https://github.com/andig/carddav2fb/issues/92.
EOD
        , $countAllImages, MAX_IMAGE_COUNT));
    }

    return [$countUploadedImages, $countAllImages];
}

/**
 * Dissolve the groups of iCloud contacts
 *
 * @param stdClass[] $vcards
 * @return stdClass[]
 */
function dissolveGroups(array $vcards): array
{
    $groups = [];

    // separate iCloud groups
    foreach ($vcards as $key => $vcard) {
        if (isset($vcard->xabsmember)) {
            if (array_key_exists($vcard->fullname, $groups)) {
                $groups[$vcard->fullname] = array_merge($groups[$vcard->fullname], $vcard->xabsmember);
            } else {
                $groups[$vcard->fullname] = $vcard->xabsmember;
            }
            unset($vcards[$key]);
            continue;
        }
    }

    $vcards = array_values($vcards);

    // assign group memberships
    foreach ($vcards as $vcard) {
        foreach ($groups as $group => $members) {
            if (in_array($vcard->uid, $members)) {
                if (!isset($vcard->group)) {
                    $vcard->group = [];
                }
                $vcard->group = $group;
                break;
            }
        }
    }

    return $vcards;
}

/**
 * Filter included/excluded vcards
 *
 * @param stdClass[] $vcards
 * @param array $filters
 * @return stdClass[]
 */
function filter(array $vcards, array $filters): array
{
    // include selected
    $includeFilter = $filters['include'] ?? [];

    if (countFilters($includeFilter)) {
        $step1 = [];

        foreach ($vcards as $vcard) {
            if (filtersMatch($vcard, $includeFilter)) {
                $step1[] = $vcard;
            }
        }
    } else {
        // filter defined but empty sub-rules?
        if (count($includeFilter)) {
            error_log('Include filter empty- including all vcards');
        }

        // include all by default
        $step1 = $vcards;
    }

    $excludeFilter = $filters['exclude'] ?? [];
    if (!count($excludeFilter)) {
        return $step1;
    }

    $step2 = [];
    foreach ($step1 as $vcard) {
        if (!filtersMatch($vcard, $excludeFilter)) {
            $step2[] = $vcard;
        }
    }

    return $step2;
}

/**
 * Count populated filter rules
 *
 * @param array $filters
 * @return int
 */
function countFilters(array $filters): int
{
    $filterCount = 0;

    foreach ($filters as $key => $value) {
        if (is_array($value)) {
            $filterCount += count($value);
        }
    }

    return $filterCount;
}

/**
 * Check a list of filters against a card
 *
 * @param stdClass $vcard
 * @param array $filters
 * @return bool
 */
function filtersMatch(stdClass $vcard, array $filters): bool
{
    foreach ($filters as $attribute => $values) {
        if (isset($vcard->$attribute)) {
            if (filterMatches($vcard->$attribute, $values)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Check a filter against a single attribute
 *
 * @param mixed $attribute
 * @param mixed $filterValues
 * @return bool
 */
function filterMatches($attribute, $filterValues): bool
{
    if (!is_array($filterValues)) {
        $filterValues = [$filterValues];
    }

    foreach ($filterValues as $filter) {
        if (is_array($attribute)) {
            // check if any attribute matches
            foreach ($attribute as $childAttribute) {
                if ($childAttribute === $filter) {
                    return true;
                }
            }
        } else {
            // check if simple attribute matches
            if ($attribute === $filter) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Export cards to fritzbox xml
 *
 * @param array $cards
 * @param array $conversions
 * @return SimpleXMLElement     the XML phone book in Fritz Box format
 */
function exportPhonebook(array $cards, array $conversions): SimpleXMLElement
{
    $xmlPhonebook = new SimpleXMLElement(
        <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<phonebooks>
<phonebook />
</phonebooks>
EOT
    );

    $root = $xmlPhonebook->xpath('//phonebook')[0];
    $root->addAttribute('name', $conversions['phonebook']['name']);

    $converter = new Converter($conversions);

    foreach ($cards as $card) {
        $contacts = $converter->convert($card);
        foreach ($contacts as $contact) {
            xml_adopt($root, $contact);
        }
    }
    return $xmlPhonebook;
}

/**
 * Attach xml element to parent
 * https://stackoverflow.com/questions/4778865/php-simplexml-addchild-with-another-simplexmlelement
 *
 * @param SimpleXMLElement $to
 * @param SimpleXMLElement $from
 * @return void
 */
function xml_adopt(SimpleXMLElement $to, SimpleXMLElement $from)
{
    $toDom = dom_import_simplexml($to);
    $fromDom = dom_import_simplexml($from);
    $toDom->appendChild($toDom->ownerDocument->importNode($fromDom, true));
}

/**
 * Upload cards to fritzbox
 *
 * @param SimpleXMLElement  $xmlPhonebook
 * @param array             $config
 * @return void
 */
function uploadPhonebook(SimpleXMLElement $xmlPhonebook, array $config)
{
    $options = $config['fritzbox'];

    $fritz = new Api($options['url']);
    $fritz->setAuth($options['user'], $options['password']);
    $fritz->mergeClientOptions($options['http'] ?? []);
    $fritz->login();

    if (!phoneNumberAttributesSet($xmlPhonebook)) {
        $xmlOldPhoneBook = downloadPhonebook($fritz, $config);
        if ($xmlOldPhoneBook) {
            $attributes = getPhoneNumberAttributes($xmlOldPhoneBook);
            $xmlPhonebook = mergePhoneNumberAttributes($xmlPhonebook, $attributes);
        }
    } else {
        error_log("Note: Skipping automatic restore of quickdial/vanity attributes.");
        error_log("      Are you using X-FB-QUICKDIAL/X-FB-VANITY CardDav extensions?");
    }

    $formfields = [
        'PhonebookId' => $config['phonebook']['id']
    ];

    $filefields = [
        'PhonebookImportFile' => [
            'type' => 'text/xml',
            'filename' => 'updatepb.xml',
            'content' => $xmlPhonebook->asXML(), // convert XML object to XML string
        ]
    ];

    $result = $fritz->postFile($formfields, $filefields); // send the command to store new phonebook
    if (strpos($result, 'Das Telefonbuch der FRITZ!Box wurde wiederhergestellt') === false) {
        throw new \Exception('Upload failed');
    }
}


/**
 * Downloads the phone book from Fritzbox
 *
 * @param   Api   $fritz
 * @param   array $config
 * @return  SimpleXMLElement|bool with the old existing phonebook
 */
function downloadPhonebook(Api $fritz, array $config)
{
    $formfields = [
        'PhonebookId' => $config['phonebook']['id'],
        'PhonebookExportName' => $config['phonebook']['name'],
        'PhonebookExport' => "",
    ];
    $result = $fritz->postFile($formfields, []); // send the command to load existing phone book
    if (substr($result, 0, 5) !== "<?xml") {
        error_log("ERROR: Could not load phonebook with ID=".$config['phonebook']['id']);
        return false;
    }
    $xmlPhonebook = simplexml_load_string($result);
    return $xmlPhonebook;
}


/**
 * Get quickdial and vanity special attributes from given XML phone book
 *
 * @param   SimpleXMLElement                $xmlPhonebook
 * @return  array|array<string, object>     [] or map with {phonenumber@CardDavUID}=>SimpleXMLElement-Attributes
 */
function getPhoneNumberAttributes(SimpleXMLElement $xmlPhonebook)
{
    if (!property_exists($xmlPhonebook, "phonebook")) {
        return [];
    }

    $specialAttributes = [];
    foreach ($xmlPhonebook->phonebook->contact as $contact) {
        foreach ($contact->telephony->number as $number) {
            if ((isset($number->attributes()->quickdial) && $number->attributes()->quickdial >= 0)
                || (isset($number->attributes()->vanity) && $number->attributes()->vanity != "")) {
                $key = generateUniqueKey($number, $contact->carddav_uid);
                $specialAttributes[$key] = $number->attributes();
            }
        }
    }
    return $specialAttributes;
}


/**
 * Restore special attributes (quickdial, vanity) in given target phone book
 *
 * @param   SimpleXMLElement    $xmlTargetPhoneBook
 * @param   array               $attributes [] or map key => attributes
 * @return  SimpleXMLElement    phonebook with restored special attributes
 */
function mergePhoneNumberAttributes(SimpleXMLElement $xmlTargetPhoneBook, array $attributes)
{
    if (!$attributes) {
        return $xmlTargetPhoneBook;
    }
    error_log("Restoring old special attributes (quickdial, vanity)".PHP_EOL);
    foreach ($xmlTargetPhoneBook->phonebook->contact as $contact) {
        foreach ($contact->telephony->number as $number) {
            $key = generateUniqueKey($number, $contact->carddav_uid);
            if (array_key_exists($key, $attributes)) {
                foreach (['quickdial','vanity'] as $attribute) {
                    if (property_exists($attributes[$key], $attribute)) {
                        $number[$attribute] = (string)$attributes[$key]->$attribute;
                    }
                }
            }
        }
    }
    return $xmlTargetPhoneBook;
}


/**
 * Build unique key with normalized phone number to lookup phonebook attributes
 * normalizing number means: remove all non-"+" and non-number characters like SPACE, MINUS, SLASH...
 *
 * @param   string  $number
 * @param   string  $carddav_uid
 * @return  string  format: {normalized-phone-number}@{vCard UUID}
 */
function generateUniqueKey(string $number, string $carddav_uid)
{
    return preg_replace("/[^\+0-9]/", "", $number)."@".$carddav_uid;
}


/**
 * Check if special attributes already set (e.g., via CardDav extension 'X-FB-QUICKDIAL' / 'X-FB-VANITY')
 *
 * @param   SimpleXMLElement    $xmlPhonebook
 * @return  boolean             true if any element already has a special attribute set
 */
function phoneNumberAttributesSet(SimpleXMLElement $xmlPhonebook)
{
    if (!property_exists($xmlPhonebook, "phonebook")) {
        return false;
    }
    foreach ($xmlPhonebook->phonebook->contact as $contact) {
        foreach ($contact->telephony->number as $number) {
            if (property_exists($number->attributes(), "quickdial")
                || property_exists($number->attributes(), "vanity")) {
                return true;
            }
        }
    }
    return false;
}
