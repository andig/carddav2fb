<?php

namespace Andig\FritzBox;

/*
author: Volker Püschel
*/

class SOApi

{

    private $ip;
    private $user;
    private $password;

    
    public function __construct($ip = 'fritz.box', $user = 'dslf-config', $password = false) {

        $this->ip       = $ip;
        $this->user     = $user;
        $this->password = $password;
    }

    
    public function getSOAPclient() {
    
            $client = new \SoapClient(
                null,
                array(
                    'location'   => "http://".$this->ip.":49000/upnp/control/x_contact",
                    'uri'        => "urn:dslforum-org:service:X_AVM-DE_OnTel:1",
                    'noroot'     => true,
                    'login'      => $this->user,
                    'password'   => $this->password,
                    'trace'      => true,
                    'exceptions' => true
                )
            );
        return $client;
    }

    
    public function getFBphonebook ($Phonebook = 0) {
     
        $client = $this->getSoapClient();
        $result = $client->GetPhonebook(new \SoapParam($Phonebook,"NewPhonebookID"));
        return simplexml_load_file($result['NewPhonebookURL']);
    }

}
?>