<?php

namespace Andig;


use Andig\CardDav\Backend;
use Andig\Vcard\Parser;
use Andig\FritzBox\Api;
use Andig\FritzBox\Converter;
use \SimpleXMLElement;


function backendProvider(array $config): Backend
{
    $server = $config['server'] ?? $config;

    $backend = new Backend();
    $backend->setUrl($server['url']);
    $backend->setAuth($server['user'], $server['password']);

    return $backend;
}


function download(Backend $backend, $substitutes, callable $callback=null): array
{
    $backend->setProgress($callback);
    $backend->setSubstitutes ($substitutes);
    return $backend->getVcards();
}


function getImageCachePath ($cachePath = '') {
    
    $imageCache = $cachePath . '/fonpix';                              // ../[cache]/fonpix
    
    if (!file_exists($imageCache)) {
        IF (!mkdir($imageCache)) {
            $cwd = getcwd() ?? '';                                     // /carddav2fb/fonpix OR /fonpix
            $imageCache = $cwd.'/fonpix';
        }
    }
    return $imageCache; 
}


function regularizeSuffixes ($fileList) {
    
    $needles = ['JPG' , 'JPEG', 'jpeg'];                               // just in case user changed suffix in cache manual
    $lowerSuffix = str_replace ($needles, 'jpg', $fileList);
    return $lowerSuffix;
}


function storeImages(array $vcards, $cachePath = '')
{
    $imageCache = getImageCachePath ($cachePath);
    $new_files = [];
        
    $cachedFiles = array_diff(scandir($imageCache), array('.', '..'));
    $cachedFiles = regularizeSuffixes($cachedFiles);
            
    foreach ($vcards as $vcard) {
        if (isset($vcard->rawPhoto)) {                                 // skip all other vCards
            if (!in_array($vcard->uid . '.jpg', $cachedFiles)) {       // this UID has recently no file in cache
                if ($vcard->photoData == 'JPEG') {                     // Fritz!Box only accept jpg-files
                    $imgFile = imagecreatefromstring ($vcard->rawPhoto);
                    if ($imgFile !== false) {
                        $fullPath = $imageCache . '/' . $vcard->uid . '.jpg';
                        header('Content-Type: image/jpeg');
                        imagejpeg($imgFile, $fullPath);
                        $new_files[] = $fullPath;
                        imagedestroy($imgFile);
                    }
                }
            }
        }
    }
    return $new_files;
}


function uploadImages ($new_files, $config)
{
    $conn_id = ftp_connect($config['url']);
    $result = ftp_login($conn_id, $config['user'], $config['password']);
    $i = 0;
    
    if ((!$conn_id) || (!$result)) {
        return;
    }
    ELSE {
        ftp_chdir($conn_id, $config['fonpix']);
        $fonpix_files = ftp_nlist($conn_id, '.');
        $fonpix_files = regularizeSuffixes ($fonpix_files);
        IF (!is_array($fonpix_files)) {
            $fonpix_files = [];
        }
        foreach ($new_files as $new_file) {
            $cachedFile = basename($new_file);
            IF (!in_array($cachedFile, $fonpix_files)) {                   // there is already a jpg saved for this UID 
                $local = $new_file;                                    // file from cache
                $remote = $config['fonpix']. '/'. $cachedFile;
                If (ftp_put($conn_id, $remote, $local, FTP_BINARY) != false) {
                    $i++;
                }
            }
        }
        ftp_close($conn_id);
    }
    return $i;
}


function parse(array $cards): array
{
    $vcards = [];
    $groups = [];

    // parse all vcards
    foreach ($cards as $card) {
        $parser = new Parser($card);
        $vcard = $parser->getCardAtIndex(0);

        // separate iCloud groups
        if (isset($vcard->xabsmember)) {
            $groups[$vcard->fullname] = $vcard->xabsmember;
            continue;
        }

        $vcards[] = $vcard;
    }

    // assign group memberships
    foreach ($vcards as $vcard) {
        foreach ($groups as $group => $members) {
            if (in_array($vcard->uid, $members)) {
                if (!isset($vcard->group)) {
                    $vcard->group = array();
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
 * @param array $cards
 * @param array $filters
 * @return array
 */
function filter(array $cards, array $filters): array
{
    // include selected
    $includeFilter = $filters['include'] ?? [];
    if (count($includeFilter)) {
        $step1 = [];

        foreach ($cards as $card) {
            if (filtersMatch($card, $includeFilter)) {
                $step1[] = $card;
            }
        }
    }
    else {
        // include all by default
        $step1 = $cards;
    }

    $excludeFilter = $filters['exclude'] ?? [];
    if (!count($excludeFilter)) {
        return $step1;
    }

    $step2 = [];
    foreach ($step1 as $card) {
        if (!filtersMatch($card, $excludeFilter)) {
            $step2[] = $card;
        }
    }

    return $step2;
}

function filtersMatch($card, array $filters): bool
{
    foreach ($filters as $attribute => $values) {
        if (isset($card->$attribute)) {
            if (filterMatches($card->$attribute, $values)) {
                return true;
            }
        }
    }

    return false;
}

function filterMatches($attribute, $filterValues): bool
{
    if (!is_array($filterValues)) {
        $filterValues = array($filterMatches);
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
        $contact = $converter->convert($card);
        xml_adopt($root, $contact);
    }
    return $xml;
}


function downloadPhonebook ($config) {
    
    $Fritzbox  = $config['fritzbox'];
    $Phonebook = $config['phonebook'];
    
    $fb_pb = new SOApi ($Fritzbox['url'], $Fritzbox['user'], $Fritzbox['password']);
    return $fb_pb->getFBphonebook($Phonebook['id']);
}


// https://stackoverflow.com/questions/4778865/php-simplexml-addchild-with-another-simplexmlelement
function xml_adopt(SimpleXMLElement $to, SimpleXMLElement $from)
{
    $toDom = dom_import_simplexml($to);
    $fromDom = dom_import_simplexml($from);
    $toDom->appendChild($toDom->ownerDocument->importNode($fromDom, true));
}


function upload(string $xml, $config) {
    
    $fritzbox = $config['fritzbox'];
    
    $fritz = new Api($fritzbox['url'], $fritzbox['user'], $fritzbox['password']); //, 1);

    $formfields = array(
        'PhonebookId' => $config['phonebook']['id']
    );

    $filefields = array(
        'PhonebookImportFile' => array(
            'type' => 'text/xml',
            'filename' => 'updatepb.xml',
            'content' => $xml,
        )
    );

    $result = $fritz->doPostFile($formfields, $filefields); // send the command

    if (strpos($result, 'Das Telefonbuch der FRITZ!Box wurde wiederhergestellt') === false) {
        throw new \Exception('Upload failed');
        return false;
    }
    return true;
}