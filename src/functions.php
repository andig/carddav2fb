<?php

namespace Andig;

use Andig\CardDav\Backend;
use Andig\CardDav\VcardFile;
use Andig\FritzBox\Converter;
use Andig\FritzBox\Api;
use Andig\FritzBox\BackgroundImage;
use Sabre\VObject\Document;
use \SimpleXMLElement;

define("MAX_IMAGE_COUNT", 150); // see: https://avm.de/service/fritzbox/fritzbox-7490/wissensdatenbank/publication/show/300_Hintergrund-und-Anruferbilder-in-FRITZ-Fon-einrichten/
define("CSV_HEADER", 'uid,number,id,type,quickdial,vanity,prio,name');

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

function localProvider($fullpath)
{
    $local = new VcardFile($fullpath);

    return $local;
}

/**
 * Download vcards from CardDAV server
 *
 * @param Backend $backend
 * @param callable $callback
 * @return Document[]
 */
function download(Backend $backend, callable $callback=null): array
{
    $backend->setProgress($callback);
    return $backend->getVcards();
}

/**
 * set up a stable FTP connection to a designated destination
 *
 * @param string $url
 * @param string $user
 * @param string $password
 * @param string $directory
 * @param boolean $secure
 * @return mixed false or stream of ftp connection
 */
function getFtpConnection($url, $user, $password, $directory, $secure)
{
    $ftpserver = parse_url($url, PHP_URL_HOST) ? parse_url($url, PHP_URL_HOST) : $url;
    $connectFunc = $secure ? 'ftp_connect' : 'ftp_ssl_connect';

    if ($connectFunc == 'ftp_ssl_connect' && !function_exists('ftp_ssl_connect')) {
        throw new \Exception("PHP lacks support for 'ftp_ssl_connect', please use `plainFTP` to switch to unencrypted FTP");
    }
    if (false === ($ftp_conn = $connectFunc($ftpserver))) {
        $message = sprintf("Could not connect to ftp server %s for upload", $ftpserver);
        throw new \Exception($message);
    }
    if (!ftp_login($ftp_conn, $user, $password)) {
        $message = sprintf("Could not log in %s to ftp server %s for upload", $user, $ftpserver);
        throw new \Exception($message);
    }
    if (!ftp_pasv($ftp_conn, true)) {
        $message = sprintf("Could not switch to passive mode on ftp server %s for upload", $ftpserver);
        throw new \Exception($message);
    }
    if (!ftp_chdir($ftp_conn, $directory)) {
        $message = sprintf("Could not change to dir %s on ftp server %s for upload", $directory, $ftpserver);
        throw new \Exception($message);
    }
    return $ftp_conn;
}

/**
 * upload image files via ftp to the fritzbox fonpix directory
 *
 * @param Document[] $vcards downloaded vCards
 * @param array $config
 * @param array $phonebook
 * @param callable $callback
 * @return mixed false or [number of uploaded images, number of total found images]
 */
function uploadImages(array $vcards, array $config, array $phonebook, callable $callback=null)
{
    $countUploadedImages = 0;
    $countAllImages = 0;
    $vcardImage = '';
    $mapFTPUIDtoFTPImageName = [];                      // "9e40f1f9-33df-495d-90fe-3a1e23374762" => "9e40f1f9-33df-495d-90fe-3a1e23374762_190106123906.jpg"
    $timestampPostfix = substr(date("YmdHis"), 2);      // timestamp, e.g., 190106123906

    if (null == ($imgPath = @$phonebook['imagepath'])) {
        throw new \Exception('Missing phonebook/imagepath in config. Image upload not possible.');
    }
    $imgPath = rtrim($imgPath, '/') . '/';  // ensure one slash at end

    // Prepare FTP connection
    $secure = @$config['plainFTP'] ? $config['plainFTP'] : false;
    $ftp_conn = getFtpConnection($config['url'], $config['user'], $config['password'], $config['fonpix'], $secure);

    // Build up dictionary to look up UID => current FTP image file
    if (false === ($ftpFiles = ftp_nlist($ftp_conn, "."))) {
        $ftpFiles = [];
    }

    foreach ($ftpFiles as $ftpFile) {
        $ftpUid = preg_replace("/\_.*/", "", $ftpFile);  // new filename with time stamp postfix
        $ftpUid = preg_replace("/\.jpg/i", "", $ftpUid); // old filename
        $mapFTPUIDtoFTPImageName[$ftpUid] = $ftpFile;
    }

    /** @var \stdClass $vcard */
    foreach ($vcards as $vcard) {
        if (is_callable($callback)) {
            ($callback)();
        }

        if (!isset($vcard->PHOTO)) {                            // skip vCard without image
            continue;
        }

        $uid = (string)$vcard->UID;

        // Occurs when embedding was not possible during download (for example, no access to linked data)
        if (preg_match("/^http/", $vcard->PHOTO)) {             // if the embed failed
            error_log(sprintf(PHP_EOL . 'The image for UID %s can not be accessed! ', $uid));
            continue;
        }
        // Fritz!Box only accept jpg-files
        $version = (string)$vcard->VERSION;
        if ($version == '3.0') {
            if ($vcard->PHOTO['TYPE'] != 'JPEG') {
                continue;
            } else {
                $vcardImage = (string)$vcard->PHOTO;
            }
        } elseif ($version == '4.0') {                       // see: https://github.com/sabre-io/vobject/issues/458
            $value = explode(',', (string)$vcard->PHOTO, 2);
            if (!preg_match("/jpeg/", $value[0])) {
                continue;
            } else {
                if (!$vcardImage = base64_decode($value[1])) {      // PONR: I don´t trust this way of fetching data
                    continue;
                };
            }
        }

        $countAllImages++;

        // Check if we can skip upload
        $newFTPimage = sprintf('%1$s_%2$s.jpg', $uid, $timestampPostfix);
        if (array_key_exists($uid, $mapFTPUIDtoFTPImageName)) {
            $currentFTPimage = $mapFTPUIDtoFTPImageName[$uid];
            if (ftp_size($ftp_conn, $currentFTPimage) == strlen($vcardImage)) {
                // No upload needed, but store old image URL in vCard
                $vcard->IMAGEURL = $imgPath . $currentFTPimage;
                continue;
            }
            // we already have an old image, but the new image differs in size
            ftp_delete($ftp_conn, $currentFTPimage);
        }

        // Upload new image file
        $memstream = fopen('php://memory', 'r+');     // we use a fast in-memory file stream
        fputs($memstream, $vcardImage);
        rewind($memstream);

        // upload new image
        if (ftp_fput($ftp_conn, $newFTPimage, $memstream, FTP_BINARY)) {
            $countUploadedImages++;
            // upload of new image done, now store new image URL in vCard (new Random Postfix!)
            $vcard->IMAGEURL = $imgPath . $newFTPimage;
        } else {
            error_log(PHP_EOL."Error uploading $newFTPimage.");
            unset($vcard->PHOTO);                              // no wrong link will set in phonebook
            unset($vcard->IMAGEURL);                           // no wrong link will set in phonebook
        }
        fclose($memstream);
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
 * @param mixed[] $vcards
 * @return mixed[]
 */
function dissolveGroups(array $vcards): array
{
    $groups = [];

    // separate iCloud groups
    /** @var \stdClass $vcard */
    foreach ($vcards as $key => $vcard) {
        if (isset($vcard->{'X-ADDRESSBOOKSERVER-KIND'})) {
            if ($vcard->{'X-ADDRESSBOOKSERVER-KIND'} == 'group') {      // identifier
                foreach ($vcard->{'X-ADDRESSBOOKSERVER-MEMBER'} as $member) {
                    $member = str_replace(['urn:', 'uuid:'], ['', ''], (string)$member);
                    $groups[(string)$vcard->FN][] = $member;
                }
                unset($vcards[$key]);                                   // delete this vCard
            }
        }
    }

    // assign group memberships
    foreach ($vcards as $vcard) {
        foreach ($groups as $group => $members) {
            if (in_array((string)$vcard->UID, $members)) {
                if (isset($vcard->GROUPS)) {
                    $assignedGroups = $vcard->GROUPS->getParts();   // get array of values
                    $assignedGroups[] = $group;                     // add the new value
                    $vcard->GROUPS->setParts($assignedGroups);      // set the values
                } else {
                    $vcard->GROUPS = $group;                        // set the new value
                }
            }
        }
    }

    return $vcards;
}

/**
 * Filter included/excluded vcards
 *
 * @param mixed[] $vcards
 * @param array $filters
 * @return mixed[]
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
            error_log('Include filter is empty: including all downloaded vCards');
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
 * Check a list of filters against the vcard properties CATEGORIES and/or GROUPS
 *
 * @param Document $vcard
 * @param array $filters
 * @return bool
 */
function filtersMatch(Document $vcard, array $filters): bool
{
    foreach ($filters as $attribute => $values) {
        $param = strtoupper($attribute);
        if (isset($vcard->$param)) {
            if (array_intersect($vcard->$param->getParts(), $values)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Export cards to fritzbox xml
 *
 * @param Document[] $cards
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
function uploadPhonebook(SimpleXMLElement $xmlPhonebook, array $attributes, array $config)
{
    $options = $config['fritzbox'];

    $fritz = new Api($options['url']);
    $fritz->setAuth($options['user'], $options['password']);
    $fritz->mergeClientOptions($options['http'] ?? []);
    $fritz->login();

    if ($config['phonebook']['id'] == 0) {                      // only the first phonebook has special attributes
        $xmlPhonebook = mergePhoneNumberAttributes($xmlPhonebook, $attributes);
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
 * @param   array $fritzbox
 * @param   array $phonebook
 * @return  SimpleXMLElement|bool with the old existing phonebook
 */
function downloadPhonebook(array $fritzbox, array $phonebook)
{
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
        error_log("ERROR: Could not load phonebook with ID=".$phonebook['id']);
        return false;
    }
    $xmlPhonebook = simplexml_load_string($result);

    return $xmlPhonebook;
}

/**
 * get empty associated array accordig to CSV_HEADER
 *
 * @return array
 */
function getPlainArray()
{
    $csvHeader = explode(',', CSV_HEADER);
    $dump = array_shift($csvHeader);

    return array_fill_keys($csvHeader, '');
}

/**
 * Get quickdial and vanity special attributes from given XML phone book
 *
 * @param SimpleXMLElement $xmlPhonebook
 * @return array an array of special attributes with CardDAV UID as key
 */
function getPhoneNumberAttributes(SimpleXMLElement $xmlPhonebook)
{
    if (!property_exists($xmlPhonebook, "phonebook")) {
        return [];
    }

    $specialAttributes = [];
    $numbers = $xmlPhonebook->xpath('//number[@quickdial or @vanity]');
    foreach ($numbers as $number) {
        $attributes = getPlainArray();
        $attributes['number'] = preg_replace("/[^\+0-9]/", "", (string)$number);
        foreach ($number->attributes() as $key => $value) {
            $attributes[(string)$key] = (string)$value;
        }
        $contact = $number->xpath("./ancestor::contact");
        $attributes['name'] = (string)$contact[0]->person->realName;
        $specialAttributes[(string)$contact[0]->carddav_uid] = $attributes;
    }

    return $specialAttributes;
}

/**
 * Restore special attributes (quickdial, vanity) in given target phone book
 *
 * @param SimpleXMLElement $xmlTargetPhoneBook
 * @param array $attributes array of special attributes
 * @return SimpleXMLElement phonebook with restored special attributes
 */
function mergePhoneNumberAttributes(SimpleXMLElement $xmlTargetPhoneBook, array $attributes)
{
    if (!$attributes) {
        return $xmlTargetPhoneBook;
    }

    error_log("Restoring old special attributes (quickdial, vanity)".PHP_EOL);
    foreach ($attributes as $key => $values) {
        if ($contact = $xmlTargetPhoneBook->xpath(sprintf('//contact[carddav_uid = "%s"]', $key))) {
            if ($phone = $contact[0]->xpath(sprintf("telephony/number[text() = '%s']", $values['number']))) {
                foreach (['quickdial', 'vanity'] as $attribute) {
                    if (!empty($values[$attribute])) {
                        $phone[0]->addAttribute($attribute, $values[$attribute]);
                    }
                }
            }
        }
    }

    return $xmlTargetPhoneBook;
}

/**
 * Get quickdial number and names as array from given XML phone book
 *
 * @param array $attributes
 * @return array
 */
function getQuickdials(array $attributes)
{
    if (empty($attributes)) {
        return [];
    }

    $quickdialNames = [];
    foreach ($attributes as $values) {
        $parts = explode(', ', $values['name']);
        if (count($parts) !== 2) {                  // if the name was not separated by a comma (no first and last name)
            $name = $values['name'];                // fullName
        } else {
            $name = $parts[1];                      // firstname
        }
        $name = preg_replace('/Dr. /', '', $name);
        $quickdialNames[$values['quickdial']] = substr($name, 0, 10);
    }
    ksort($quickdialNames);                         // ascending: lowest quickdial # first

    return $quickdialNames;
}

/**
 * upload background image to fritzbox
 *
 * @param SimpleXMLElement $phonebook
 * @param array $attributes
 * @param array $config
 * @return void
 */
function uploadBackgroundImage($phonebook, $attributes, array $config)
{
    $quickdials = getQuickdials($attributes);
    if (!count($quickdials)) {
        error_log('No quickdial numbers are set for a background image upload');
        return;
    }
    if (key($quickdials) > 9) {    // usual the pointer should on the first element; with 7.3.*: array_key_first()
        error_log('Quickdial numbers out of range for a background image upload');
        return;
    }

    $image = new BackgroundImage();
    $image->uploadImage($quickdials, $config);
}

/**
 * save special attributes to internal FRITZ!Box memory (../FRITZ/mediabox)
 *
 * @param SimpleXMLElement $phonebook
 * @param array $config
 * @return string|void
 */
function uploadAttributes($phonebook, $config)
{
    if (!count($specialAttributes = getPhoneNumberAttributes($phonebook))) {
        return [];
    }
    error_log('Save special attributes from recent FRITZ!Box phonebook!');

    // Prepare FTP connection
    $secure = @$config['plainFTP'] ? $config['plainFTP'] : false;
    $ftp_conn = getFtpConnection($config['url'], $config['user'], $config['password'], '/FRITZ/mediabox', $secure);
    // open a fast in-memory file stream
    $memstream = fopen('php://memory', 'r+');
    $rows = xmlArrayToCSV($specialAttributes);
    fputs($memstream, $rows);
    rewind($memstream);
    if (!ftp_fput($ftp_conn, 'Attributes.csv', $memstream, FTP_BINARY)) {
        error_log(sprintf('Error uploadind %s!' . PHP_EOL, $csv_filename));
    }
    fclose($memstream);
    ftp_close($ftp_conn);

    return $specialAttributes;
}

/**
 * get saved special attributes from internal FRITZ!Box memory (../FRITZ/mediabox)
 *
 * @param array $config
 * @return array|void
 */
function downloadAttributes($config)
{
    // Prepare FTP connection
    $secure = @$config['plainFTP'] ? $config['plainFTP'] : false;
    $ftp_conn = getFtpConnection($config['url'], $config['user'], $config['password'], '/FRITZ/mediabox', $secure);
    if (ftp_size($ftp_conn, 'Attributes.csv') == -1) {
        return [];
    }

    $csvFile = fopen('php://temp', 'r+');
    if (ftp_fget($ftp_conn, $csvFile, 'Attributes.csv', FTP_BINARY)) {
        rewind($csvFile);
        $specialAttributes = [];
        while ($csvRow = fgetcsv($csvFile)) {
            if (!count(array_diff(explode(',', CSV_HEADER), $csvRow))) {    // all CSV_HEADER elements are in csvRow => header line
                $collums = $csvRow;
            } else {
                foreach ($csvRow as $key => $value) {
                    if ($key == 0) {
                        $uid = $value;
                    } else {
                        $specialAttributes[$uid][$collums[$key]] = $value;
                    }
                }
            }
        }
    }
    fclose($csvFile);
    ftp_close($ftp_conn);

    return $specialAttributes;
}

/**
 * convert special atributes (array of SimpleXMLElement) to string (rows of csv)
 *
 * @param array $arrayOfXML
 * @return string csv
 */
function xmlArrayToCSV($specialAttributes) {
    $row = CSV_HEADER . PHP_EOL;                            // csv header row
    foreach ($specialAttributes as $uid => $values) {
        $row = $row . $uid;                                 // array key first collum
        foreach ($values as $key => $value) {
            if ($key == 'name') {
                $value = '"' . $value . '"';
            }
            $row = $row . ',' . $value;                     // values => collums
        }
        $row = $row . PHP_EOL;                              // next row
    }
    return $row;
}
