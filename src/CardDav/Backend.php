<?php

namespace Andig\CardDav;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Ringcentral\Psr7;

/**
 * @author Christian Putzke <christian.putzke@graviox.de>
 * @copyright Christian Putzke
 * @link http://www.graviox.de/
 * @link https://twitter.com/cputzke/
 * @since 24.05.2015
 * @version 0.7
 * @license http://www.gnu.org/licenses/agpl.html GNU AGPL v3 or later
 */

class Backend
{
    /**
     * CardDAV server url
     *
     * @var     string
     */
    private $url;

    /**
     * VCard File URL Extension
     *
     * @var string
     */
    private $url_vcard_extension = '.vcf';

    /**
     * Authentication: username
     *
     * @var  string
     */
    private $username;

    /**
     * Authentication: password
     *
     * @var  string
     */
    private $password;

    /**
     * Authentication: method
     *
     * @var  string|null
     */
    private $authentication;

    /**
     * Progress callback
     */
    private $callback;

    /**
     * Do not use this directly! Rather use {@see getClient()}
     *
     * @var Client
     */
    private $client;
    
    /**
     * Set Subtitutions of links to embedded data
     */
    private $substitutes = [];

    /**
     * Constructor
     * Sets the CardDAV server url
     *
     * @param   string  $url    CardDAV server url
     */
    public function __construct(string $url=null) {
        if ($url) {
            $this->setUrl($url);
        }
    }
    
    /**
     * setting the properties/elements which should be substituted
     *
     * @param   array $elements        the properties whose value should be replaced ('LOGO', 'KEY', 'PHOTO' or 'SOUND')
     */
    public function setSubstitutes($elements) 
    {
        foreach ($elements as $element) {
            $this->substitutes [] = strtoupper($element);
        }
    }

    public function setUrl(string $url)
    {
        $this->url = $url;

        if (substr($this->url, -1, 1) !== '/') {
            $this->url = $this->url . '/';
        }

        // workaround for providers that don't use the default .vcf extension
        if (strpos($this->url, "google.com")) {
            $this->url_vcard_extension = '';
        }
    }

    /**
     * Set progress callback
     */
    public function setProgress($callback = null)
    {
        $this->callback = $callback;
    }

    /**
     * Set credentials
     */
    public function setAuth(string $username, string $password, string $method = null)
    {
        $this->username = $username;
        $this->password = $password;
        $this->authentication = $method;
    }

    /**
     * Gets all vCards including additional information from the CardDAV server
     *
     * @param   boolean $include_vcards     Include vCards within the response (simplified only)
     * @return  string                      Raw or simplified XML response
     */
    public function getVcards($include_vcards = true)
    {
        $response = $this->query($this->url, 'PROPFIND');

        if (in_array($response->getStatusCode(), [200,207])) {
            $body = (string)$response->getBody();
            return $this->simplify($body, $include_vcards);
        }

        throw new \Exception('Received HTTP ' . $response->getStatusCode());
    }

    private function getClient()
    {
        if (!$this->client) {
            $this->client = new Client($this->getClientOptions());
        }

        return $this->client;
    }

    private function getClientOptions($options = [])
    {
        if ($this->username) {
            $options['auth'] = [$this->username, $this->password, $this->authentication];
        }

        return $options;
    }

    /**
     * If elements are declared as to be substituted,
     * the data from possibly linked sources are embedded directly into the vCard
     *
     * @param   string $vcard               single raw vCard
     * @param   string $substituteID        the property whose value is to be replaced ('LOGO', 'KEY', 'PHOTO' or 'SOUND')
     * @return  string                      single raw vCard with embedded value
     */
    private function embeddingBase64($vcard, $substituteID) 
    {
        $search_card = strtoupper($vcard);                             // $equivalent to substituteID in 'CAPITALS'
        if (!preg_match("/$substituteID/", $search_card)) {    // rough check if searched element is ever included in vCard
            return $vcard;
        }
        else {                                                         // if so, we have to dismantle the vCard in lines
            $vcard = str_replace(["\r\n", "\r"], "\n", $vcard);
            $vcard = preg_replace("/\n(?:[ \t])/", "", $vcard);
            $lines = explode("\n", $vcard);
            
            $version = $this->getVersion(trim($lines[1]));             // CardDAV MUST support VERSION 3 or higher
            $key = -1;                                                 // VERSION must be second property (line)!
            foreach ($lines as $line)  {
                $key++;
                if (preg_match("/$substituteID/", $line)) {            // find the line number of LOGO, KEY, PHOTO or SOUND
                    break;
                }
            }
            @list($type, $value) = explode(':', $lines[$key], 2);      // dismantle the designated line
            if (!preg_match("/http/", $value)) {                       // no external URL -> must be already base64 or local
                return $vcard;
            }
            else {                                                     // get the data from the external URL
                $embedded = $this->getlinkedData($value);
                switch ($version) {
                    case 3:                                            // assemble the new line
                        $newline = $substituteID . ';TYPE=' . strtoupper($embedded['subtype']) . ';ENCODING=b:' . $embedded['base64data'];
                        break;
                    case 4:                                            // assemble the new line
                        $newline = $substituteID . ':data:' . $embedded['mimetype'] . ';base64,' . $embedded['base64data'];
                        break;
                }
                $lines[$key] = $newline ?? $lines[$key];               // reassembel the lines to a consitent vCard
                $vcard = implode(PHP_EOL, $lines);
            }
            return $vcard;
        }
    }
    
    /**
     * returns the vCard version as integer
     * delivers 0 if its not the line containing the VERSION property or
     * delivers 99 if the property value could not converted to an integer (contains whatever)
     *
     * @param   string vCardline               the detached line VERSION:
     * @return  integer                        the vCard version e.g. error codes (0, 99)
     */
    private function getVersion($vCardline)
    {
        $type = '';
        $value = '';
        @list($type, $value) = explode(':', $vCardline, 2);
        $type = strtoupper($type);
        if (strpos($type, 'VERSION') !== false) {
            $version = 0+$value ?? 99;
        }
        else {
            $version = 0;
        }
        return $version;
    }
        
    /**
     * delivers an array including the previously linked data and its mime type details
     * a mime type  is composed of a type, a subtype, and optional parameters (e.g. "; charset=UTF-8")
     * 
     * @param    string $uri             URL of the external linked data
     * @return   array ['mimetype',      e.g. "image/jpeg" 
     *                  'type',          e.g. "audio"  
     *                  'subtype',       e.g. "mpeg"
     *                  'parameters',    whatever
     *                  'base64data']    the base64 encoded data
     */
    public function getlinkedData($uri)
    {
        $externalData = array();
        
        $this->client = $this->client ?? new Client();
        $request = new Request('GET', $uri);

        if ($this->username) {
            $credentials = base64_encode($this->username . ':' . $this->password);
            $request = $request->withHeader('Authorization', 'Basic ' . $credentials);
        }
        $response = $this->client->send($request);
        
        if (200 !== $response->getStatusCode()) {
            throw new \Exception('Received HTTP ' . $response->getStatusCode());
        }
        else {
            $contentType = $response->getHeader('Content-Type');
            
            @list($mimeType,$parameters) = explode(';', $contentType[0], 2);
            @list($type, $subType) = explode('/', $mimeType);
                        
            $externalData['mimetype']   = $mimeType ?? '';
            $externalData['type']       = $type ?? '';
            $externalData['subtype']    = $subType ?? '';
            $externalData['parameters'] = $parameters ?? '';
            $externalData['base64data'] = base64_encode((string)$response->getBody());    
        }
        return $externalData;
    }
    
    public function fetchImage($uri)
    {
        $request = new Request('GET', $uri);

        $response = $this->getClient()->send($request);

        if (200 !== $response->getStatusCode()) {
            throw new \Exception('Received HTTP ' . $response->getStatusCode());
        }

        return (string)$response->getBody();
    }

    /**
    * Gets a clean vCard from the CardDAV server
    *
    * @param    string  $vcard_id   vCard id on the CardDAV server
    * @return   string              vCard (text/vcard)
    */
    public function getVcard($vcard_id)
    {
        $vcard_id = str_replace($this->url_vcard_extension, null, $vcard_id);
        $response = $this->query($this->url . $vcard_id . $this->url_vcard_extension, 'GET');

        if (in_array($response->getStatusCode(), [200,207])) {
            $body = (string)$response->getBody();

            if (isset($this->substitutes)) {
                foreach ($this->substitutes as $substitute) {
                    $body = $this->embeddingBase64($body, $substitute);
                }
            }
            if (is_callable($this->callback)) {
                ($this->callback)();
            }

            return $body;
        }

        throw new \Exception('Received HTTP ' . $response->getStatusCode());
    }

    /**
     * Simplify CardDAV XML response
     *
     * @param   string  $response           CardDAV XML response
     * @return  string                      Simplified CardDAV XML response
     */
    private function simplify(string $response): array
    {
        $response = $this->cleanResponse($response);
        $xml = new \SimpleXMLElement($response);

        $cards = [];

        foreach ($xml->response as $response) {
            if ((preg_match('/vcard/', $response->propstat->prop->getcontenttype) || preg_match('/vcf/', $response->href)) &&
              !$response->propstat->prop->resourcetype->collection) {
                $id = basename($response->href);
                $id = str_replace($this->url_vcard_extension, null, $id);

                $cards[] = $this->getVcard($id);
            }
        }

        return $cards;
    }

    /**
     * Cleans CardDAV XML response
     *
     * @param   string  $response   CardDAV XML response
     * @return  string  $response   Cleaned CardDAV XML response
     */
    private function cleanResponse($response)
    {
        $response = utf8_encode($response);
        $response = str_replace('D:', null, $response);
        $response = str_replace('d:', null, $response);
        $response = str_replace('C:', null, $response);
        $response = str_replace('c:', null, $response);

        return $response;
    }

    /**
     * Query the CardDAV server via curl and returns the response
     *
     * @param   string  $url                CardDAV server URL
     * @param   string  $method             HTTP method like (OPTIONS, GET, HEAD, POST, PUT, DELETE, TRACE, COPY, MOVE)
     * @param   string  $content            Content for CardDAV queries
     * @param   string  $content_type       Set content type
     * @return  array                       Raw CardDAV Response and http status code
     */
    private function query($url, $method, $content = null, $content_type = null)
    {
        $request = new Request($method, $url, [
            'Depth' => '1'
        ]);

        if ($content_type) {
            $request = $request->withHeader('Content-type', $content_type);
        }

        if ($content) {
            $request = $request->withBody($content);
        }

        $response = $this->getClient()->send($request);
        return $response;
    }
}
