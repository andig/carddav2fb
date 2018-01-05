<?php

/*
Adaptierter Clone der Converter.php von andig.
Eine Variante mit "class converter2fa extends Converter"
hat nicht funktioniert.
Diese Klasse stellt eine weitere Funktionen zur Verfügung,
um Fax-Nummern zu extrahieren und in einem simplen Array
mit 19 bzw. 21 Feldern - passend zu FritzAdr - zu übergeben:
 
- Konvertierung in das FritzAdr-Format:			convertfa

Programmierer: Volker Püschel
*/

namespace Andig\FritzAdr;

class converter2fa

{
	public	$FritzAdrRecords = array (),
			$CountFritzAdr = 0,
			$NumDataFields = 0;
		
	private $config;

    public function __construct($config)     {
        $this->config = $config;
		setlocale(LC_ALL, 'de_DE');
    }
	
	public function convertfa($card) {
		$this->card = $card;
		$name = htmlspecialchars($this->getProperty('realName'));
		// FritzAdr (dBase) verwendet den DOS-Zeichensatz (Codepage 850)
		$name = str_replace( '&amp;', '&', iconv('UTF-8', 'CP850//TRANSLIT', $name) );
		$faxnumbers = $this->getFaxNumber();						// übergibt 1-n FAX-Nummern
		IF (count ($faxnumbers)) {									// wenn FAX-Nummer gefunden wurde
		    $i = (count($this->FritzAdrRecords)-1);
			foreach ($faxnumbers as $faxnumber) {
				$i++;
				$this->FritzAdrRecords[$i] = array_fill (0,$this->NumDataFields,'');  // baue einen neuen leeren FRITZadr-Datensatz auf  
				$this->FritzAdrRecords[$i][0] = $name;				// FullName in Feld 1 ('BEZCHNG')
				$parts = explode (', ',$name);
				IF (count($parts) !== 2) { 							// wenn der Name nicht mit Komma getrennt war ( kein Vor- & Nachname) 
					$this->FritzAdrRecords[$i][1] = $name;			// FullName in Feld 2 ('FIRMA')
				}
				ELSE {
					$this->FritzAdrRecords[$i][2] = $parts[0];		// Nachname in Feld 3 ('NAME')
					$this->FritzAdrRecords[$i][3] = $parts[1];		// Vorname in Feld 4 ('VORNAME')
				}
				IF ($this->NumDataFields == 19) {
					$this->FritzAdrRecords[$i][11] = $faxnumber;	// FAX-Nummer in Feld 12 ('TELEFAX')
				}
				IF ($this->NumDataFields == 21) {
					$this->FritzAdrRecords[$i][10] = $faxnumber;	// FAX-Nummer in Feld 11 ('TELEFAX')
				}
			}
			$this->CountFritzAdr = $i;
		}
	}
	
	
	private function getFaxNumber() {					// siehe addPhone() in Converter.php
		$faxnumbers = array();
        $replaceCharacters = $this->config['phoneReplaceCharacters'] ?? array();
        if (isset($this->card->phone)) {
            foreach ($this->card->phone as $numberType => $numbers) {
                foreach ($numbers as $idx => $number) {
                    IF (strpos($numberType, 'FAX') !== false) {
						if (count($replaceCharacters)) {
							$number = str_replace("\xc2\xa0", "\x20", $number);
							$number = strtr($number, $replaceCharacters);
							$number = trim(preg_replace('/\s+/','', $number));
						}
						$faxnumbers[] = $number;		// wenn Type FAX gleich true
					}
				}
			}
		if (count($faxnumbers))		
			return $faxnumbers;							// liefert 1-n FAX-Nummern pro Kontakt zurück
        }
    }	
	

    private function getProperty(string $property): string	// identische Funktion wie in Converter.php von andig
    {
        if (null === ($rules = $this->config[$property] ?? null)) {
            throw new \Exception("Missing conversion definition for `$property`");
        }

        foreach ($rules as $rule) {
            // parse rule into tokens
            $token_format = '/{([^}]+)}/';
            preg_match_all($token_format, $rule, $tokens);

            if (!count($tokens)) {
                throw new \Exception("Invalid conversion definition for `$property`");
            }

            // print_r($tokens);
            $replacements = [];

            // check card for tokens
            foreach ($tokens[1] as $idx => $token) {
				// echo $idx.PHP_EOL;
                if (isset($this->card->$token) && $this->card->$token) {
                    // echo $tokens[0][$idx].PHP_EOL;
					$replacements[$token] = $this->card->$token;
                    // echo $this->card->$token.PHP_EOL;
					// ECHO PHP_EOL;
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

?>