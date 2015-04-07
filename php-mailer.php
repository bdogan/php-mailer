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
define('DS', isset($config['DS']) ? $config['DS'] : "/");
define('DEBUG', isset($config['debug']) ? $config['debug'] : true);
define('LOG', isset($config['log']) ? $config['log'] : false);
define('LOG_FOLDER', isset($config['log_folder']) ? $config['log_folder'] : 'log');
define('LOG_FORMAT', isset($config['log_format']) ? $config['log_format'] : 'event_time identity host user from to cc subject status last_error');

// Checking Folders
if (LOG > -1 && !file_exists(LOG_FOLDER)) WriteError("'" . LOG_FOLDER  . "' folder not found", false);
foreach ($folders as $folder) {
  if (!isset($config[$folder])) WriteError("'" . $folder  . "' folder not set in config file", false);
  if (!file_exists($config[$folder])) WriteError("'" . $folder  . "' not found", false);
}
  
// Checking Timezone set
if (isset($config['timezone'])) date_default_timezone_set($config['timezone']);

// Get Current Date Time
$now = date('m/d/Y H:i:s', time());
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
  $mail = MailStructure();
  try {
    
    $mail = ParseMail($file, $mail);
    $mail = ControlMail($mail);
    
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

    $mail['status'] = 0;
    LogMail($mail);
  } catch(Exception $err) {
    $mail['last_error'] = $err->__toString();
    $mail['status'] = 1;
    ArrayToFile($mail, $config['error'] . DS . uniqid(is_null($mail['identity']) ? "mail_" : $mail['identity'] . "_") . ".mai");
    unlink($file);
    LogMail($mail);
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
    'html' => null,
    'event_time'=> date('Y-m-dTH:i:s', time()),
    'status' => 0
  );
}

function ParseMail($file, $mail) {
  
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
    $mail['html'] = is_null($mail['html']) ? true : $mail['html']; 
  } else {
    $mail['from_source'] = false;
    $mail['html'] = is_null($mail['html']) ? true : $mail['html'];
  }
  return $mail;
}

function WriteError($errObj = null, $resume = true){
  LogError($errObj);
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
  $message = null;
  $message .= print_r($targetObj, true);
  
  LogDebug($message);
  if (DEBUG === false) return;
  fwrite(STDOUT, $message);
  if ($newline) fwrite(STDOUT, "\n");
}

function ReadFiles($folder) {
  $files = array();
  if ($handle = opendir($folder)) {
    while (false !== ($file = readdir($handle))) { 
      if ($file == '.' || $file == '..' || $file == 'empty') continue; 
      $file = $folder . DS . $file; 
      if (is_file($file)) $files[]  = $file; 
    } 
    closedir($handle); 
  } else {
    WriteError("'" . $folder . "' can not open!");
  }
  return $files;
}

function LogMail($mail) {
  $status = $mail['status'];
  if ($status === "0" && LOG < 1) return;
  if (LOG < 0) return;
  $message = LOG_FORMAT;
  $message = preg_replace('/([a-zA-Z_]+)\s?/i', '%$1% ', $message);
  foreach ($mail as $key => $value) {
    $data = null;
    if (isset($mail[$key])) $data = $mail[$key];
    if (is_null($data)) $data = "0";
    $data = explode("\n", $data)[0];
    $message = str_replace("%" . $key . "%", $data, $message);
  } 
  $message = preg_replace('/(%[a-zA-Z]+?%)/i', '0', $message);
  LogWrite($message);
}

function LogWrite($message) {
  $fileName = 'php_mailer_' . date('Y-m-d') . '.log';
  try {
    file_put_contents(LOG_FOLDER . DS . $fileName, $message . "\n", FILE_APPEND | LOCK_EX);
  } catch (Exception $e) {
    echo "\n" . $e->__toString();
  }
}

function LogError($log_data){
  if (LOG > 1) LogData($log_data);
}

function LogDebug($log_data) {
  if (LOG > 2) LogData($log_data);
}

function LogData($log_data) {
  $message = "";
  if ($log_data instanceof Exception) {
    $ex_message = $log_data->__toString();
    $ex_message = "# " . str_replace("\n", "\n# ", $ex_message);
    $message = $ex_message;
  }
  if (is_string($log_data)) $message = "# " . $log_data;
  if (is_null($message)) return;
  LogWrite($message);
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