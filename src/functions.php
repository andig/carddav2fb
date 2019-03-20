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
 * upload image files via ftp to the fritzbox fonpix directory
 *
 * @param stdClass[] $vcards downloaded vCards
 * @param array $config
 * @param array $phonebook
 * @param callable $callback
 * @return mixed false or [number of uploaded images, number of total found images]
 */
function uploadImages(array $vcards, array $config, array $phonebook, callable $callback=null)
{
    $countUploadedImages = 0;
    $countAllImages = 0;
    $mapFTPUIDtoFTPImageName = [];                      // "9e40f1f9-33df-495d-90fe-3a1e23374762" => "9e40f1f9-33df-495d-90fe-3a1e23374762_190106123906.jpg"
    $timestampPostfix = substr(date("YmdHis"), 2);      // timestamp, e.g., 190106123906

    if (null == ($imgPath = @$phonebook['imagepath'])) {
        throw new \Exception('Missing phonebook/imagepath in config. Image upload not possible.');
    }
    $imgPath = rtrim($imgPath, '/') . '/';  // ensure one slash at end

    // Prepare FTP connection
    $ftpserver = parse_url($config['url'], PHP_URL_HOST) ? parse_url($config['url'], PHP_URL_HOST) : $config['url'];
    $connectFunc = (@$config['plainFTP']) ? 'ftp_connect' : 'ftp_ssl_connect';

    if ($connectFunc == 'ftp_ssl_connect' && !function_exists('ftp_ssl_connect')) {
        throw new \Exception("PHP lacks support for 'ftp_ssl_connect', please use `plainFTP` to switch to unencrypted FTP.");
    }

    if (false === ($ftp_conn = $connectFunc($ftpserver))) {
        throw new \Exception("Could not connect to ftp server ".$ftpserver." for image upload.");
    }
    if (!ftp_login($ftp_conn, $config['user'], $config['password'])) {
        throw new \Exception("Could not log in ".$config['user']." to ftp server ".$ftpserver." for image upload.");
    }
    if (!ftp_pasv($ftp_conn, true)) {
        throw new \Exception("Could not switch to passive mode on ftp server ".$ftpserver." for image upload.");
    }
    if (!ftp_chdir($ftp_conn, $config['fonpix'])) {
        throw new \Exception("Could not change to dir ".$config['fonpix']." on ftp server ".$ftpserver." for image upload.");
    }

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
 * download the current phonebook from the FRITZ!Box
 * @param  array   $config  configuration
 * @return SimpleXMLElement
 */
function downloadPhonebook(array $config)
{
    $fritzbox  = $config['fritzbox'];
    $phonebook = $config['phonebook'];
    $fritz = new Api($fritzbox['url']);
    $fritz->setAuth($fritzbox['user'], $fritzbox['password']);
    $fritz->mergeClientOptions($fritzbox['http'] ?? []);
    $fritz->login();
    $formfields = [
        'PhonebookId' => $phonebook['id'],
        'PhonebookExportName' => $phonebook['name'],
        'PhonebookExport' => "",
    ];
    $result = $fritz->postFile($formfields, []); // send the command to load existing phone book
    if (substr($result, 0, 5) !== "<?xml") {
        error_log("ERROR: Could not load old phonebook with ID=".$config['phonebook']['id']);
        return new SimpleXMLElement("<xml><empty /></xml>");
    }
    $xmlPhonebook = simplexml_load_string($result);
    return $xmlPhonebook;
}

/**
 * get quickdial and vanity attributes from given XML phone book
 * Via the FRITZ!Box GUI it is only possible to assign one(!) number per contact
 * to a quickdial number. In addition, there is the restriction that a vanity number
 * can only be assigned if also a quickdial number is assigned (both to the same number!).
 * @param   SimpleXMLElement    $xml
 * @return  array 
 */
function getSpecialAttributes ($xml)
{
    $specialAttributes = [];
    foreach ($xml->phonebook->contact as $contact) {
        foreach ($contact->telephony->number as $number) {
            if (isset($number->attributes()->quickdial) || !empty($number->attributes()->vanity)) {
                $attributes['number'] = $number;
                foreach ($number->attributes() as $key => $value ) {
                    $attributes[$key] = (string)$value;
                }
                $specialAttributes[(string)$contact->carddav_uid] = $attributes;
            }
        }
    }
    return $specialAttributes;
}

/**
 * writes back previous saved quickdial and vanity attributes into the vCard
 * the array key of the assigned phone number will expanded
 * the quickdial and vanity number are stored seperatly 
 * @param   stdClass  $cards
 * @param   array     $attributes
 * @return  stdClass 
 */
function setSpecialAttributes($cards, $attributes)
{
    foreach ($cards as $card) {
        if (array_key_exists($card->uid, $attributes)) {
            if (isset($attributes[$card->uid]['quickdial'])) {
                $card->quickdial = $attributes[$card->uid]['quickdial'];    // add the quickdial to the vCard
                $additionalType = ";QUICKDIAL";
            }
            if (isset($attributes[$card->uid]['vanity'])) {
                $card->vanity = $attributes[$card->uid]['vanity'];          // add the vanity to the vCard
                $additionalType = $additionalType . ";VANITY";              // see getSpecialAttributes comment for this sequence
            }
            foreach ($card->phone as $type => $number) {                    // figure out the assigned number
                if ($attributes[$card->uid]['number'] == $number) {
                    $card->phone[$type . $additionalType] = $number ;       // expand the types of mappings
                    unset($card->phone[$type]);
                }
            }
        }
    }
    return $cards;
}

/**
 * Export cards to fritzbox xml
 *
 * @param array $cards
 * @param array $conversions
 * @return SimpleXMLElement
 */
function export(array $cards, array $conversions): SimpleXMLElement
{
    $xml = new SimpleXMLElement(
        <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<phonebooks>
<phonebook />
</phonebooks>
EOT
    );

    $root = $xml->xpath('//phonebook')[0];
    $root->addAttribute('name', $conversions['phonebook']['name']);

    $converter = new Converter($conversions);

    foreach ($cards as $card) {
        $contacts = $converter->convert($card);
        foreach ($contacts as $contact) {
            xml_adopt($root, $contact);
        }
    }
    return $xml;
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
 * @param string $xml
 * @param array $config
 * @return void
 */
function upload(string $xml, $config)
{
    $options = $config['fritzbox'];

    $fritz = new Api($options['url']);
    $fritz->setAuth($options['user'], $options['password']);
    $fritz->mergeClientOptions($options['http'] ?? []);
    $fritz->login();

    $formfields = [
        'PhonebookId' => $config['phonebook']['id']
    ];

    $filefields = [
        'PhonebookImportFile' => [
            'type' => 'text/xml',
            'filename' => 'updatepb.xml',
            'content' => $xml,
        ]
    ];

    $result = $fritz->postFile($formfields, $filefields); // send the command

    if (strpos($result, 'Das Telefonbuch der FRITZ!Box wurde wiederhergestellt') === false) {
        throw new \Exception('Upload failed');
    }
}
