<?php

namespace Andig\FritzBox;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Ringcentral\Psr7;

/**
 * Extended from https://github.com/jens-maus/carddav2fb
 * Public Domain
 */
class Api
{
    private $username;
    private $password;
    private $url;

    protected $sid = '0000000000000000';

    /**
     * Execute fb login
     *
     * @access public
     */
    public function __construct($url = 'https://fritz.box', $user_name = false, $password = false, $force_local_login = false)
    {
        // set FRITZ!Box-IP and URL
        $this->url = $url;
        $this->username = $user_name;
        $this->password = $password;

        $this->sid = $this->initSID();
    }

    /**
     * do a POST request on the box
     *
     * @param  array  $formfields    an associative array with the POST fields to pass
     * @return int                   the HTTP status code returned by the Fritz!Box
     */
    function postFile(array $formFields, array $fileFields) {
        
        $client = new \GuzzleHttp\Client();
        $multipart = [];
    
        // sid must be first parameter
        $formFields = array_merge(array('sid' => $this->sid), $formFields);
    
        foreach ($formFields as $key => $val) {
            $multipart[] = [
                'name' => $key,
                'contents' => $val,
            ];
        }
    
        foreach ($fileFields as $name => $file) {
            $multipart[] = [
                'name' => $name,
                'filename' => $file['filename'],
                'contents' => $file['content'],
                'headers' => [
                    'Content-Type' => $file['type'],
                ],
            ];
    
        }
    
        $url = $this->url . '/cgi-bin/firmwarecfg';
        $response = $client->request('POST', $url, [
            'multipart' => $multipart,
        ]);
        return $response->getStatusCode();
    }
    
    private function _create_custom_file_post_header($postFields, $fileFields)
    {
        // form field separator
        $delimiter = '-------------' . uniqid();

        $data = '';

        // populate normal fields first (simpler)
        foreach ($postFields as $name => $content) {
            $data .= "--" . $delimiter . "\r\n";
            $data .= 'Content-Disposition: form-data; name="' . urlencode($name) . '"';
            $data .= "\r\n\r\n";
            $data .= $content;
            $data .= "\r\n";
        }
        // populate file fields
        foreach ($fileFields as $name => $file) {
            $data .= "--" . $delimiter . "\r\n";
            // "filename" attribute is not essential; server-side scripts may use it
            $data .= 'Content-Disposition: form-data; name="' . urlencode($name) . '";' .
                     ' filename="' . $file['filename'] . '"' . "\r\n";

            //$data .= 'Content-Transfer-Encoding: binary'."\r\n";
            // this is, again, informative only; good practice to include though
            $data .= 'Content-Type: ' . $file['type'] . "\r\n";
            // this endline must be here to indicate end of headers
            $data .= "\r\n";
            // the file itself (note: there's no encoding of any kind)
            $data .= $file['content'] . "\r\n";
        }
        // last delimiter
        $data .= "--" . $delimiter . "--\r\n";

        return array('delimiter' => $delimiter, 'data' => $data);
    }

    /**
     * do a GET request on the box
     * the main cURL wrapper handles the command
     *
     * @param  array  $params    an associative array with the GET params to pass
     * @return string            the raw HTML code returned by the Fritz!Box
     */
    public function doGetRequest($params = array())
    {
        // add the sid, if it is already set
        if ($this->sid != '0000000000000000') {
            $params['sid'] = $this->sid;
        }

        if (strpos($params['getpage'], '.lua') > 0) {
            $getpage = $params['getpage'] . '?';
            unset($params['getpage']);
        } else {
            $getpage = '/cgi-bin/webcm?';
        }

        $url = $this->url . $getpage . http_build_query($params);

        $this->client = $this->client ?? new Client();
        $response = $this->client->send(new Request('GET', $url));

        if (200 !== $response->getStatusCode()) {
            throw new \Exception('Received HTTP ' . $response->getStatusCode());
        }

        return (string)$response->getBody();
    }

    /**
     * the login method, handles the secured login-process
     * newer firmwares (xx.04.74 and newer) need a challenge-response mechanism to prevent Cross-Site Request Forgery attacks
     * see http://www.avm.de/de/Extern/Technical_Note_Session_ID.pdf for details
     *
     * @return string                a valid SID, if the login was successful, otherwise throws an Exception with an error message
     */
    protected function initSID()
    {
        $loginpage = '/login_sid.lua';

        // read the current status
        $login = $this->doGetRequest(array('getpage' => $loginpage));

        $xml = simplexml_load_string($login);
        if ($xml->SID != '0000000000000000') {
            return $xml->SID;
        }

        // the challenge-response magic, pay attention to the mb_convert_encoding()
        $response = $xml->Challenge . '-' . md5(mb_convert_encoding($xml->Challenge . '-' . $this->password, "UCS-2LE", "UTF-8"));

        // do the login
        $formfields = array(
            'getpage' => $loginpage,
            'username' => $this->username,
            'response' => $response
        );

        $output = $this->doGetRequest($formfields);

        // finger out the SID from the response
        $xml = simplexml_load_string($output);
        if ($xml->SID != '0000000000000000') {
            return (string)$xml->SID;
        }

        throw new \Exception('ERROR: Login failed with an unknown response.');
    }

    /**
     * a getter for the session ID
     *
     * @return string                $this->sid
     */
    public function getSID()
    {
        return $this->sid;
    }
}
