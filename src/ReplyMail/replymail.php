<?php

namespace Andig\ReplyMail;

/*
author: Volker Püschel
*/

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

//require '../vendor/autoload.php';


class replymail

{
    private $mail;

    public function __construct ($account) {              
        
        date_default_timezone_set('Etc/UTC');
                
        $this->mail = new PHPMailer;                           // Create a new PHPMailer instance
        $this->mail->isSMTP();                                 // Tell PHPMailer to use SMTP
        $this->mail->SMTPDebug = $account['debug'];
        $this->mail->Host = $account['url'];                   // Set the hostname of the mail server
        $this->mail->Port = $account['port'];                  // Set the SMTP port number - likely to be 25, 465 or 587
        $this->mail->SMTPSecure = $account['secure'];
        $this->mail->SMTPAuth = true;                          // Whether to use SMTP authentication
        $this->mail->Username = $account['user'];              // Username to use for SMTP authentication
        $this->mail->Password = $account['password'];          // Password to use for SMTP authentication
        $this->mail->setFrom($account['user'], 'carddav2fb');  // Set who the message is to be sent fromly-to address
        $this->mail->addAddress($account['receiver']);         // Set who the message is to be sent to
    }
    
    public function sendReply ($phonebook, $attachment, $label) {
        $this->mail->clearAttachments();                       // initialize
        $this->mail->Subject = 'New contact was found in phonebook: ' . $phonebook;  //Set the subject line
        $this->mail->Body = 'Add this contact to your CardDAV server:';
        $this->mail->addStringAttachment($attachment, $label, 'quoted-printable', 'text/x-vcard');
        
        if (!$this->mail->send()) {                            // send the message, check for errors
            echo 'Mailer Error: ' . $mail->ErrorInfo;
            return false;
        } else {
            return true;
        //    echo 'Message sent!';
        }
    }

}

?>