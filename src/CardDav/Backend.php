<?php

namespace Andig\CardDav;

use Andig\Http\ClientTrait;
use Sabre\VObject\Document;
use Sabre\VObject\Reader;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * @author Christian Putzke <christian.putzke@graviox.de>
 * @author Andreas Goetz <cpuidle@gmx.de>
 * @author Volker Püschel <knuffy@anasco.de>
 * @copyright Christian Putzke
 * @license http://www.gnu.org/licenses/agpl.html GNU AGPL v3 or later
 */

class Backend
{
    use ClientTrait;

    /**
     * CardDAV server url
     * @var string
     */
    private $url;

    /**
     * VCard File URL Extension
     * @var string
     */
    private $vcard_extension = '.vcf';

    /**
     * Progress callback
     * @var callable
     */
    private $callback;

    /**
     * Set substitutions of links to embedded data
     * @var array
     */
    private $substitutes = [];

    /**
     * Cached http client
     * @var Client|null
     */
    private $client;

    /**
     * Request method
     * @var string
     */
    private $method;

    /**
     * Constructor
     * Sets the CardDAV server url
     *
     * @param   string  $url    CardDAV server url
     */
    public function __construct(string $url = null, $method = 'REPORT')
    {
        if ($url) {
            $this->setUrl($url);
        }
        $this->setClientOptions([
            'headers' => [
                'Depth' => 1
            ]
        ]);
        $this->method = $method;
    }

    /**
     * Set the properties/elements to be substituted
     *
     * @param   array $elements        the properties whose value should be replaced ('LOGO', 'KEY', 'PHOTO' or 'SOUND')
     */
    public function setSubstitutes($elements)
    {
        foreach ($elements as $element) {
            $this->substitutes[] = strtoupper($element);
        }
    }

    /**
     * Set and normalize server url
     *
     * @param string $url
     * @return void
     */
    public function setUrl(string $url)
    {
        $this->url = rtrim($url, '/') . '/';

        // workaround for providers that don't use the default .vcf extension
        if (strpos($this->url, "google.com")) {
            $this->vcard_extension = '';
        }
    }

    /**
     * Set progress callback
     */
    public function setProgress(callable $callback=null)
    {
        $this->callback = $callback;
    }

    /**
     * Execute progress callback
     */
    protected function progress()
    {
        if (is_callable($this->callback)) {
            ($this->callback)();
        }
    }

    /**
     * Get initialized http client. Improves download performance by up to x7
     *
     * @return Client
     */
    private function getCachedClient(): Client
    {
        if (!$this->client) {
            $this->client = $this->getClient();
        }
        return $this->client;
    }

    /**
     * Gets all vCards including additional information from the CardDAV server
     *
     * @return array   All parsed Vcards from backend
     */
    public function getVcards(): array
    {
        if ($this->method == 'PROPFIND') {
            $body = <<<EOD
<?xml version="1.0" encoding="utf-8"?>
<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">
    <D:prop>
        <C:address-data content-type="text/vcard"/>
        <D:getetag/>
    </D:prop>
</D:propfind>
EOD;
        } else {
            $body = <<<EOD
<?xml version="1.0" encoding="utf-8"?>
<C:addressbook-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">
    <D:prop>
        <C:address-data content-type="text/vcard"/>
        <D:getetag/>
    </D:prop>
</C:addressbook-query>
EOD;
        }

        try {
            $response = $this->getCachedClient()->request($this->method, $this->url, [
                'headers' => [
                    'Content-Type' => 'application/xml',
                ],
                'body' => $body
            ]);
        } catch (RequestException $e) {
            if ($e->hasResponse() && 404 == $e->getResponse()->getStatusCode()) {
                error_log('Ignoring empty response from carddav REPORT request');
                return [];
            }

            // bubble anything else
            throw $e;
        }

        $cards = [];
        $body = $this->stripNamespaces((string)$response->getBody());
        $xml = new \SimpleXMLElement($body);
        // NOTE: instead of stripping the namespaces they could also be queried using xpath:
        // $xml->registerXPathNamespace('dav', 'DAV:');
        // $xml->registerXPathNamespace('card', 'urn:ietf:params:xml:ns:carddav');
        // $xml->xpath('//dav:response/dav:propstat/dav:prop/card:address-data');

        foreach ($xml->response as $response) {
            foreach ($response->propstat->prop as $prop) {
                $content = (string)$prop->{'address-data'};
                if (empty($content)) {              // PROPFIND: first node is empty
                    continue;
                }

                $vcard = Reader::read($content);
                $vcard = $this->enrichVcard($vcard);
                $cards[] = $vcard;

                $this->progress();
            }
        }

        return $cards;
    }

    /**
     * If elements are declared as to be substituted,
     * the data from possibly linked sources are embedded directly into the vCard
     *
     * @param   Document $vcard single parsed vCard
     * @param   string $property the property whose value is to be replaced ('LOGO', 'KEY', 'PHOTO' or 'SOUND')
     * @return  Document single vCard with embedded value
     */
    private function embedBase64(Document $vcard, string $property): Document
    {
        if ($embedded = $this->getLinkedData($vcard->$property)) {      // get the data from the external URL or false
            if ($vcard->VERSION == '3.0') {                             // the different vCard versions must be considered
                unset($vcard->$property);                               // delete the old property
                $vcard->add($property, $embedded['data'], ['TYPE' => strtoupper($embedded['subtype']), 'ENCODING' => 'b']);
            } elseif ($vcard->VERSION == '4.0') {
                unset($vcard->$property);                               // delete the old property
                $vcard->add($property, 'data:' . $embedded['mimetype'] . ';base64,' . base64_encode($embedded['data']));
            }
        }

        return $vcard;
    }

    /**
     * Delivers an array including the previously linked data and its mime type details
     * a mime type is composed of a type, a subtype, and optional parameters (e.g. "; charset=UTF-8")
     *
     * @param string $uri           URL of the external linked data
     * @return bool|array ['mimetype',    e.g. "image/jpeg"
     *                     'type',        e.g. "audio"
     *                     'subtype',     e.g. "mpeg"
     *                     'parameters',  whatever
     *                     'data']        the base64 encoded data
     */
    private function getLinkedData(string $uri)
    {
        $response = $this->getCachedClient()->request('GET', $uri, ['http_errors' => false]);
        if ($response->getStatusCode() != 200) {
            return false;
        }

        $contentType = $response->getHeader('Content-Type');

        @list($mimeType, $parameters) = explode(';', $contentType[0], 2);
        @list($type, $subType) = explode('/', $mimeType);

        $externalData = [
            'mimetype'   => $mimeType ?? '',
            'type'       => $type ?? '',
            'subtype'    => $subType ?? '',
            'parameters' => $parameters ?? '',
            'data'       => (string)$response->getBody(),
        ];

        return $externalData;
    }

    /**
     * enrich the vcard with
     * ->FULLNAME (equal to ->FN)
     * ->LASTNAME, ->FIRSTNAME etc. extracted from ->N
     * ->PHOTO with embedded data from linked sources (equal for KEY, LOGO or SOUND)
     *
     * @param Document $vcard
     * @return Document
     */
    public function enrichVcard(Document $vcard): Document
    {
        if (isset($vcard->{'X-ADDRESSBOOKSERVER-KIND'}) && $vcard->{'X-ADDRESSBOOKSERVER-KIND'} == 'group') {
            return $vcard;
        }

        if (isset($vcard->FN)) {                                // redundant for downward compatibility
            $vcard->FULLNAME = (string)$vcard->FN;
        }
        if (isset($vcard->N)) {                                 // add 'N'-values to additional separate fields
            foreach ($this->parseName($vcard->N) as $key => $value) {
                if (!empty($value)) {
                    $vcard->$key = $value;
                }
            }
        }

        foreach (['PHOTO', 'LOGO', 'SOUND', 'KEY'] as $property) {      // replace of linked data by embedded
            if (!isset($vcard->$property)) {
                continue;
            }
            if (in_array($property, $this->substitutes) && preg_match("/^http/", $vcard->$property)) {
                $vcard = $this->embedBase64($vcard, $property);
            }
        }

        return $vcard;
    }

    /**
     * Cleans CardDAV XML response
     * https://stackoverflow.com/questions/1245902/remove-namespace-from-xml-using-php
     *
     * @param   string  $xml   CardDAV XML response
     * @return  string         Cleaned CardDAV XML response
     */
    private function stripNamespaces($xml)
    {
        // Gets rid of all namespace definitions
        $xml = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $xml);
        // Gets rid of all namespace references
        $xml = preg_replace('/(<\/*)[^!>:]+:/', '$1', $xml);

        return $xml;
    }

    /**
     * split the values from 'N' into separate fields
     *
     * @param string $value
     * @return array
     */
    private function parseName($value)
    {
        @list(
            $lastname,
            $firstname,
            $additional,
            $prefix,
            $suffix
        ) = explode(';', $value);
        return [
            'LASTNAME' => $lastname,
            'FIRSTNAME' => $firstname,
            'ADDITIONAL' => $additional,
            'PREFIX' => $prefix,
            'SUFFIX' => $suffix,
        ];
    }
}
