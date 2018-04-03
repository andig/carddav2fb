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
    $backend->setSubstitutes($substitutes);
    return $backend->getVcards();
}

/**
 * getting and if not exists setting the fonpix directory in the path to the designated cache (from config)
 * If the attempt fails in the cache, an attempt is made to create the directory in the working directory,
 * if that fails, in the root directory
 *
 * @param $cachePath   string    path to the cache from config 
 * @return                       fullpath to fonpix directory in cache 
 */
function getImageCachePath($cachePath = '')
{
    $imageCache = $cachePath . '/fonpix';                              // ../[cache]/fonpix
    
    if (!file_exists($imageCache)) {
        if (!mkdir($imageCache)) {
            $cwd = getcwd() ?? '';                                     // /carddav2fb/fonpix OR /fonpix
            $imageCache = $cwd.'/fonpix';
        }
    }
    return $imageCache; 
}

/**
 * writes image files to the designated cache if the filename (UID) is not already in the cache
 *
 * @param $new_files   array     files (filenames with full path) newly stored in cache
 * @param $config      array
 * @return                       number of transfered files
 */
function storeImages(array $vcards, $cachePath = '')
{
    $imageCache = getImageCachePath($cachePath);
    $new_files = [];
    $pattern = ['/JPG/' , '/JPEG/', '/jpeg/'];    
    $cachedFiles = array_diff(scandir($imageCache), array('.', '..'));
    $cachedFiles = preg_replace($pattern, 'jpg', $cachedFiles);
            
    foreach ($vcards as $vcard) {
        if (isset($vcard->rawPhoto)) {                                 // skip all other vCards
            if (!in_array($vcard->uid . '.jpg', $cachedFiles)) {       // this UID has recently no file in cache
                if ($vcard->photoData == 'JPEG') {                     // Fritz!Box only accept jpg-files
                    $imgFile = imagecreatefromstring($vcard->rawPhoto);
                    if ($imgFile !== false) {
                        $fullPath = $imageCache . '/' . $vcard->uid . '.jpg';
                        $fullPath = str_replace("\xEF\xBB\xBF",'',$fullPath);   // replacing BOM
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

/**
 * uploaded image files via ftp from the designated cache to the fritzbox fonpix directory
 *
 * @param $new_files   array     files (filenames with full path) newly stored in cache
 * @param $config      array
 * @return                       number of transfered files
 */
function uploadImages($new_files, $config)
{
    $conn_id = ftp_connect($config['url']);
    $result = ftp_login($conn_id, $config['user'], $config['password']);
    $i = 0;
    
    if ((!$conn_id) || (!$result)) {
        return;
    }
    ftp_chdir($conn_id, $config['fonpix']);
    $fonpix_files = ftp_nlist($conn_id, '.');
    $pattern = ['/JPG/' , '/JPEG/', '/jpeg/'];    
    $fonpix_files = preg_replace($pattern, 'jpg', $fonpix_files);
    if (!is_array($fonpix_files)) {
        $fonpix_files = [];
    }
    foreach ($new_files as $new_file) {
        $cachedFile = basename($new_file);
        if (!in_array($cachedFile, $fonpix_files)) {                // there is already a jpg saved for this UID 
            $local = $new_file;                                     // file from cache
            $remote = $config['fonpix']. '/'. $cachedFile;
            if (ftp_put($conn_id, $remote, $local, FTP_BINARY) != false) {
                $i++;
            }
        }
    }
    ftp_close($conn_id);
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
    if (countFilters($includeFilter)) {
        $step1 = [];
        foreach ($cards as $card) {
            if (filtersMatch($card, $includeFilter)) {
                $step1[] = $card;
            }
        }
    }
    else {
        // filter defined but empty sub-rules?
        if (count($includeFilter)) {
            error_log('Include filter empty- including all cards');
        }
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
 * @param [type] $card
 * @param array $filters
 * @return bool
 */
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

/**
 * Check a filter against a single attribute
 *
 * @param [type] $attribute
 * @param [type] $filterValues
 * @return bool
 */
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
        $contact = $converter->convert($card);
        // $root->addChild('contact', $contact); 
        xml_adopt($root, $contact);
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
 * @param string $config
 * @return boolean
 */
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