#!/usr/bin/php

<?php
// Global declerations
$configFile = "config.php";
$folders = array('pickup', 'queue', 'error', 'sent');

// Bootstrap
if (!file_exists($configFile)) WriteError(dirname($configFile) . "/" . $configFile . " not found", false);
require $configFile;
require "vendor/autoload.php";

use Nette\Mail\Message;

foreach ($folders as $folder) {
  if (!isset($config[$folder])) WriteError("'" . $folder  . "' folder not set in config file", false);
  if (!file_exists($config[$folder])) WriteError("'" . $folder  . "' not found", false);
}
  
// Program Logic

if (isset($config['timezone'])) date_default_timezone_set($config['timezone']);

$now = date('m/d/Y H:i:s', time());
Write("------------------------------------");
Write("PHP Mail Sender Started at " . $now);

Write("Checking pickup folder");
$files = ReadFiles($config['pickup']);

if (count($files) === 0) { // 
  Write("There are not any mail files");
  exit(0);
}
Write("Found " . count($files) . " mail to sent");

foreach ($files as $file) {
  try {
  
    $mail = ParseMail($file);
    $mail = ControlMail($mail);
    
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

  } catch(Exception $err) {
  
    WriteError($err);
    continue;
  
  }
}

// Funcitons
function MailStructure(){
  return array(
    'host' => 'mail.platinmarketreform.com',
    'user' => 'bildirim@platinmarketreform.com',
    'password' => '123456789',
    'body' => null,
    'source' => null,
    'from' => null,
    'to' => null,
    'cc' => null,
    'subject' => null,
    'usessl' => '0'
  );
}

function ParseMail($file) {
  $mail = MailStructure();
  $patterns = array();
  foreach ($mail as $key => $value) $patterns[$key] = '/' . $key . ':(.+)\n/i';

  
  $file_content = file_get_contents($file);
  
  foreach ($patterns as $key => $pattern) {
      preg_match_all($pattern, $file_content, $results);
      if (is_array($results) && isset($results[1]) && isset($results[1][0])) {
        $mail[$key] = $results[1][0];
      }
  }
  return $mail;
}

function ControlMail($mail){
  if (is_null($mail['body']) && !is_null($mail['source'])) {
    $mail['body'] = file_get_contents($mail['source']);
  }
  return $mail;
}

function WriteError($errObj = null, $resume = true){
  $message = null;
  if ($errObj instanceof Exception) {
      $message .= print_r($errObj, true);
  }
  if (is_null($message)) $message .= $errObj;
  
  fwrite(STDERR, 'Error: ' . $message . "\n");

  if ($resume == false) exit(1);
}

function Write($targetObj = null, $newline = true){
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
      $file = $folder . '/' . $file; 
      if (is_file($file)) $files[]  = $file; 
    } 
    closedir($handle); 
  } else {
    WriteError("'" . $folder . "' can not open!");
  }
  return $files;
}

?>