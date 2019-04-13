<?php

namespace Andig\FritzBox;

use Andig\FritzBox\Api;

/**
 * Copyright (c) 2019 Volker Püschel
 * @license MIT
 */

class BackgroundImage
{

    const ASSETS = './assets/';

    /** @var  resource */
    protected $bgImage;

    /** @var  string */
    protected $font;

    /** @var int */
    protected $textColor;


    public function __construct()
    {
        $this->bgImage = $this->getImageAsset(self::ASSETS . 'keypad.jpg');
        putenv('GDFONTPATH=' . realpath('.'));
        $this->setFont(self::ASSETS . 'impact.ttf');
        $this->setTextcolor(38, 142, 223);           // light blue from Fritz!Box GUI
    }

    public function __destruct()
    {
        if (isset($this->bgImage)) {
            imagedestroy($this->bgImage);
        }
    }

    /**
     * Get image as resource
     * @param string $path
     * @return resource
     */
    public function getImageAsset(string $path)
    {
        if (false === ($img = imagecreatefromjpeg($path))) {
            throw new \Exception('Cannot open master image file');
        }
        return $img;
    }

    /**
     * set a new font
     * @param string $path
     */
    public function setFont(string $path)
    {
        $this->font = $path;
    }

    /**
     * set a new text color
     * @param int $red
     * @param int $green
     * @param int $blue
     */
    public function setTextcolor(int $red, int $green, int $blue)
    {
        $this->textColor = imagecolorallocate($this->bgImage, $red, $green, $blue);
    }

    /**
     * get image
     * @return resource
     */
    public function getImage()
    {
        return $this->bgImage;
    }

    /**
     * creates an image based on a phone keypad with names assoziated to the quickdial numbers 1 to 9
     *
     * @param array $quickdials
     * @return string|bool
     */
    private function getBackgroundImage($quickdials)
    {
        $posX = 0;
        $posY = 0;

        foreach ($quickdials as $key => $quickdial) {
            switch ($key) {
                case 1:
                case 4:
                case 7:
                    $posX = 20;
                    break;

                case 2:
                case 5:
                case 8:
                    $posX = 178;
                    break;

                case 3:
                case 6:
                case 9:
                    $posX = 342;
                    break;
            }
            switch ($key) {
                case 1:
                case 2:
                case 3:
                    $posY = 74;
                    break;

                case 4:
                case 5:
                case 6:
                    $posY = 172;
                    break;

                case 7:
                case 8:
                case 9:
                    $posY = 272;
                    break;
                default:          // all values > 9 with no keypad relation
                    continue 2;
            }
            imagettftext($this->bgImage, 20, 0, $posX, $posY, $this->textColor, $this->font, $quickdial);
        }

        ob_start();
        imagejpeg($this->bgImage, null, 100);
        $content = ob_get_clean();

        return $content;
    }

    /**
     * Returns a well-formed body string, which is accepted by the FRITZ!Box for uploading
     * a background image. Guzzle's multipart option does not work on this interface. If
     * this changes, this function can be replaced.
     *
     * @param string $sID
     * @param string $phone
     * @param string $image
     * @return string
     */
    private function getBody($sID, $phone, $image)
    {
        $boundary = '--' . sha1(uniqid());
        $imageSize = strlen($image);

        $body = <<<EOD
$boundary
Content-Disposition: form-data; name="sid"
Content-Length: 16

$sID
$boundary
Content-Disposition: form-data; name="PhonebookId"
Content-Length: 3

255
$boundary
Content-Disposition: form-data; name="PhonebookType"
Content-Length: 1

1
$boundary
Content-Disposition: form-data; name="PhonebookEntryId"
Content-Length: 3

$phone
$boundary
Content-Disposition: form-data; name="PhonebookPictureFile"; filename="dummy.jpg"
Content-Type: image/jpeg
Content-Length: $imageSize

$image
$boundary--
EOD;

    return $body;
    }

    /**
     * upload background image to FRITZ!Box
     *
     * @param array $quickdials
     * @param array $config
     */
    public function uploadImage($quickdials, $config)
    {
        $numberRange = range(strval(610), strval(615));     // up to six handhelds can be registered
        $phones = array_slice($config['fritzfons'], 0, 6);  // only the first six numbers are considered

        // assamble background image
        $backgroundImage = $this->getBackgroundImage($quickdials);

        // http request preconditions
        $fritz = new Api($config['url']);
        $fritz->setAuth($config['user'], $config['password']);
        $fritz->mergeClientOptions($config['http'] ?? []);

        $fritz->login();

        foreach ($phones as $phone) {
            if (!in_array($phone, $numberRange)) {             // the internal numbers must be in this number range
                continue;
            }

            error_log(sprintf("Uploading background image to Fritz!Fon #%s", $phone));

            $body = $this->getBody($fritz->getSID(), $phone, $backgroundImage);
            $result = $fritz->postImage($body);

            if (strpos($result, 'Das Bild wurde erfolgreich hinzugefügt')) {
                error_log('Upload successful');
            } else {
                error_log('Upload failed');
            }
        }
    }
}
