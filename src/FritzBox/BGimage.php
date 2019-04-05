<?php

namespace Andig\FritzBox;

use Andig\FritzBox\Api;

/**
 * Copyright (c) 2019 Volker Püschel
 * @license MIT
 */

class BGimage
{
    /** @var  resource */
    protected $bgImage;

    /** @var  string */
    protected $font;
    
    /** @var int */
    protected $textColor;


    public function __construct()
    {
        $this->bgImage = $this->getMasterImage('./src/img/keypad.jpg');
        putenv('GDFONTPATH=' . realpath('.'));
        $this->setFont ('/src/img/impact');
        $this->setTextcolor(38, 142, 223);           // light blue from Fritz!Box GUI
    }

    public function __destruct()
    {
        imagedestroy($this->bgImage);
    }
    
    /**
     * get master image
     * @param string $path
     * @return resource|bool
     */
    public function getMasterImage(string $path)
    {
        $masterImage = imagecreatefromjpeg($path);
        if ($masterImage == false) {
            throw new \Exception('Cannot open master image file');
        }
        return $masterImage;
    }

    /**
     * set a new font
     * @param string $fontFile
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
     * creates an image based on a phone keypad with names assoziated to the quickdial numbers
     *  
     * @param array $quickdials
     * @return string|bool 
     */
    public function getBackgroundImage ($quickdials)
    {
        $posX = 1;
        $posY = 1;

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
            }
            imagettftext($this->bgImage, 20, 0, $posX, $posY, $this->textColor, $this->font, $quickdial);
        }
        
        ob_start();
        imagejpeg($this->bgImage, null, 100);
        $content = ob_get_contents();
        ob_end_clean();

        return $content;
    }

}