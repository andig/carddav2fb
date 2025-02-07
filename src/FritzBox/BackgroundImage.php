<?php

namespace Andig\FritzBox;

use Andig\FritzBox\Api;

/**
 * @author Volker Püschel <knuffy@anasco.de>
 * @copyright 2025 Volker Püschel
 * @license MIT
 */

class BackgroundImage
{
    const TEXTCOLOR = [38, 142, 223];           // light blue from FRITZ!Box GUI
    const LINE_SPACING = 100;
    const FRITZ_FONS = [610, 611, 612, 613, 614, 615];  // up to six handheld phones can be registered
    CONST UPLD_SUCCESS = 'SUCCEEDED';   // positiv result string after upload

    /** @var object */
    private $bgImage;

    /** @var string */
    private $font;

    /** @var int */
    private $textColor;

    public function __construct()
    {
        $this->bgImage = $this->getImageAsset(dirname(__DIR__, 2) . '/assets/keypad.jpg');
        putenv('GDFONTPATH=' . realpath('.'));
        $this->setFont(dirname(__DIR__, 2) . '/assets/impact.ttf');
        $this->setTextcolor(self::TEXTCOLOR);   // light blue from Fritz!Box GUI
    }

    /**
     * Get GD image as object
     *
     * @param string $path
     * @return Object
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
     *
     * @param string $path
     * @return void
     */
    public function setFont(string $path)
    {
        $this->font = $path;
    }

    /**
     * set a new text color
     *
     * @param array $rgb
     * @return void
     */
    public function setTextcolor($rgb)
    {
        $rgb = array_slice($rgb, 0, 3) + [0, 0, 0];
        $this->textColor = imagecolorallocate($this->bgImage, $rgb[0], $rgb[1], $rgb[2]);
    }

    /**
     * get the image
     *
     * @return Object
     */
    public function getImage()
    {
        return $this->bgImage;
    }

    /**
     * creates an image based on a phone keypad with names assoziated to the
     * quickdial numbers 2 to 9
     *
     * @param array $quickdials
     * @return string|bool
     */
    private function getBackgroundImage($quickdials)
    {
        foreach ($quickdials as $key => $quickdial) {
            if ($key < 2 || $key > 9) {
                continue;
            }
            $posX = 19;
            $posY = 74;
            if ($key == 2 || $key == 5 || $key == 8) {
                $posX = 178;
            } elseif ($key == 3 || $key == 6 || $key == 9) {
                $posX = 342;
            }
            if ($key == 4 || $key == 5 || $key == 6) {
                $posY = $posY + self::LINE_SPACING;
            } elseif ($key == 7 || $key == 8 || $key == 9) {
                $posY = $posY + self::LINE_SPACING * 2;
            }
            imagettftext($this->bgImage, 20, 0, $posX, $posY, $this->textColor, $this->font, $quickdial);
        }

        ob_start();
        imagejpeg($this->bgImage, null, 100);
        $content = ob_get_clean();

        return $content;
    }

    /**
     * Returns a well-formed body string, which is accepted by the FRITZ!Box for
     * uploading a background image. Guzzle's multipart option does not work on 
     * this interface (the last test carried out was 09/2021). 
     * If this changes, this function can be replaced.
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
     * @return void
     */
    public function uploadImage($quickdials, $config)
    {
        $phones = array_slice($config['fritzfons'], 0, 6);  // only the first six numbers are considered

        // assamble background image
        $backgroundImage = $this->getBackgroundImage($quickdials);

        // http request preconditions
        $fritz = new Api($config['url']);
        $fritz->setAuth($config['user'], $config['password']);
        $fritz->mergeClientOptions($config['http'] ?? []);

        $fritz->login();

        foreach ($phones as $phone) {
            if (!in_array($phone, self::FRITZ_FONS)) {      // the internal numbers must be in this number range
                continue;
            }

            error_log(sprintf("Uploading background image to FRITZ!Fon #%s", $phone));

            $body = $this->getBody($fritz->getSID(), $phone, $backgroundImage);
            $result = $fritz->postImage($body);
            // comment out the two lines above, if you activate the following block for multipart usage
            /*
            $formFields = [
                'PhonebookId'      => '255',
                'PhonebookType'    => '1',
                'PhonebookEntryId' => $phone,
            ];
            $fileFields = [
                'PhonebookPictureFile' => [
                    'filename' => 'dummy.jpg',
                    'type'     => 'image/jpeg',
                    'content'  => $backgroundImage,
                ],
            ];
            $result = $fritz->postFile($formFields, $fileFields);
            */
            if (strpos($result, self::UPLD_SUCCESS)) {
                error_log('Background image upload successful');
            } else {
                error_log('Background image upload failed');
            }
        }
    }
}
