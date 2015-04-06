#!/usr/bin/php

<?php
// Global
$configFile = "config.php";
$folders = array('pickup', 'queue', 'error', 'sent');

// Config
if (!file_exists($configFile)) WriteError($configFile . " not found", false);
require $configFile;

// Composer
if (!file_exists("vendor/autoload.php")) WriteError("Run composer install first", false);
require "vendor/autoload.php"; //Composer Auto Load

// Depencies
use Nette\Mail\Message;
use SmtpValidatorEmail\ValidatorEmail;

// Validator Options
$validator_options = array(
  'domainMoreInfo' => false,
  'delaySleep' => array(0),
  'noCommIsValid' => 0,
  'catchAllIsValid' => 0,
  'catchAllEnabled' => 1,
);
if (isset($config['validator_options']) && is_array($config['validator_options'])) $validator_options = $config['validator_options'];

// Define
define("DS", isset($config['DS']) ? $config['DS'] : "/");
define("DEBUG", isset($config['debug']) ? $config['debug'] : true);
define("LOG", isset($config['log']) ? $config['log'] : false);
define("LOG_FILE", isset($config['log_file']) ? $config['log_file'] : 'log');
define("LOG_FORMAT", isset($config['log_format']) ? $config['log_format'] : 'eventtime identity host user from to cc subject last_error');

// Checking Folders
foreach ($folders as $folder) {
  if (!isset($config[$folder])) WriteError("'" . $folder  . "' folder not set in config file", false);
  if (!file_exists($config[$folder])) WriteError("'" . $folder  . "' not found", false);
}
  
// Checking Timezone set
if (isset($config['timezone'])) date_default_timezone_set($config['timezone']);

// Get Current Date Time
$now = date('m/d/Y H:i:s', time());
Write("------------------------------------");
Write("PHP Mail Sender Started at " . $now);


Write("Checking pickup folder");
$files = ReadFiles($config['pickup']);

if (count($files) === 0) {
  Write("Pickup folder has not any mail file");
} else {
  Write("Found " . count($files) . " mail to queue");
}

foreach ($files as $file) {
  try {
    rename($file, $config['queue'] . DS . uniqid("mail_") . ".mai");
  } catch(Exception $e) {
    WriteError($err);
    continue;
  }
}

Write("Checking queue folder");
$files = ReadFiles($config['queue']);

if (count($files) === 0) {
  Write("Queue folder has not any mail file");
  exit(0);
} else {
  Write("Found " . count($files) . " mail to sent");
}

foreach ($files as $file) {
  try {
    
    $now = date('m/d/Y H:i:s', time());

    $mail = ParseMail($file);
    $mail = ControlMail($mail);
    $mail['event_time'] = $now;
    
    // Validate 'to' address
    $validator = new ValidatorEmail(array($mail['to']), $mail['from'], $validator_options);
    $result = $validator->getResults();
    if (is_array($result[$mail['to']]) && $result[$mail['to']]['info'] === "catch all detected") $result[$mail['to']] = 1; //Catch All Prevented
    if (!isset($result[$mail['to']])) $result[$mail['to']] = 0;

    if ($result[$mail['to']] !== 1) { // 
      throw new ValidationException($mail['to'] . " -> " . $result[$mail['to']]['info']);
    }

    // Send mail
    $mailObj = new Message;
    $hostSettings = array(
        'host' => $mail['host'],
        'username' => $mail['user'],
        'password' => $mail['password']
    );
    if ($mail['usessl'] == '1') $hostSettings['secure'] = 'ssl';

    $senderObj = new Nette\Mail\SmtpMailer($hostSettings);

    $mailObj->setFrom($mail['from'])
      ->addTo($mail['to'])
      ->setSubject($mail['subject'])
      ->setHTMLBody($mail['body']);
    if (!is_null($mail['cc'])) $mailObj->addCc($mail['cc']);
    
    $result = $senderObj->send($mailObj);

    $mail['last_error'] = null;
    rename($file, $config['sent'] . DS . uniqid(is_null($mail['identity']) ? "mail_" : $mail['identity'] . "_") . ".mai");

  } catch(Exception $err) {

    $mail['last_error'] = $err->__toString();
    ArrayToFile($mail, $config['error'] . DS . uniqid(is_null($mail['identity']) ? "mail_" : $mail['identity'] . "_") . ".mai");
    unlink($file);
    WriteError($err);

  }
}

// Funcitons
function ArrayToFile($source = array(), $destination) {
  $fileData = array();
  foreach ($source as $key => $value) $fileData[] = $key . ":" . $value;
  file_put_contents($destination, implode("\n", $fileData));
}

function MailStructure(){
  return array(
    'identity' => null,
    'host' => 'mail.platinmarketreform.com',
    'user' => 'bildirim@platinmarketreform.com',
    'password' => '123456789',
    'body' => null,
    'source' => null,
    'from' => null,
    'to' => null,
    'cc' => null,
    'subject' => null,
    'usessl' => '0',
    'from_source' => false,
    'last_error' => null,
    'html' => true,
    'event_time'=> null
  );
}

function ParseMail($file) {
  $mail = MailStructure();
  $patterns = array();
  foreach ($mail as $key => $value) $patterns[$key] = '/' . $key . ':(.*?)(\n(' . implode('|', array_keys($mail)) .  '):|\z)/s';
  
  $file_content = file_get_contents($file);
  
  foreach ($patterns as $key => $pattern) {
      $results = null;
      preg_match_all($pattern, $file_content, $results);
      if (is_array($results) && isset($results[1]) && isset($results[1][0])) {
        $mail[$key] = trim($results[1][0]);
        if (empty($mail[$key])) $mail[$key] = null;
      }
  }
  return $mail;
}

function ControlMail($mail){
  if (!is_null($mail['source'])) {
    $mail['body'] = file_get_contents_utf8($mail['source']);
    $mail['from_source'] = true;
  } else {
    $mail['from_source'] = false;
  }
  return $mail;
}

function WriteError($errObj = null, $resume = true){
  if (DEBUG === false) return;
  $message = null;
  if ($errObj instanceof Exception) {
      $message .= "[" . get_class($errObj) . "] " . $errObj->getMessage();
      $message .= " at " . $errObj->getFile() . " line " . $errObj->getLine();
  }
  if (is_null($message)) $message .= $errObj;
  
  fwrite(STDERR, $message . "\n");

  if ($resume == false) exit(1);
}

function Write($targetObj = null, $newline = true){
  if (DEBUG === false) return;

  $message = null;
  $message .= print_r($targetObj, true);
  
  fwrite(STDOUT, $message);
  if ($newline) fwrite(STDOUT, "\n");
}

function ReadFiles($folder) {
  $files = array();
  if ($handle = opendir($folder)) {
    while (false !== ($file = readdir($handle))) { 
      if ($file == '.' || $file == '..') continue; 
      $file = $folder . DS . $file; 
      if (is_file($file)) $files[]  = $file; 
    } 
    closedir($handle); 
  } else {
    WriteError("'" . $folder . "' can not open!");
  }
  return $files;
}

function LogData($log_data) {

}

function file_get_contents_utf8($fn) {
  $content = file_get_contents($fn);
    return mb_convert_encoding($content, 'UTF-8',
      mb_detect_encoding($content, 'UTF-8, ISO-8859-9', true));
}

// Validation Exception
class ValidationException extends Exception {
  
}

?>