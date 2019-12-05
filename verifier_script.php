<?php

error_reporting(0);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('max_execution_time', TIMEOUT);
//ini_set('memory_limit', '64M');

define('VERSION', 1.0);
define('ID', '54FABC5D34D7');
define('TIMEOUT', 30);
//define('DEBUG', true);
if (!defined('DEBUG')) { define('DEBUG', false); }
if (DEBUG) {
    error_reporting(-1);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
}
if (!defined('__DIR__')) { define('__DIR__', dirname(__FILE__)); }
define('LOG_PATH', __DIR__);
define('CACHE_NAME', __DIR__ . "/" . md5(__FILE__ . ID));
define('TIME_STORAGE_CACHE', 86400*1);
define('DIR_WRITABLE', is_writable(__DIR__));
define('NEED_CACHE', true);
define('USE_CACHE', DIR_WRITABLE && NEED_CACHE);
define('LOG_OPERATION', DEBUG && DIR_WRITABLE);

global $_RESPONSE;
$_RESPONSE = array();

Script::write2Log("\n\n" . date('Y-m-d H:i:s'), '', false);
if (empty($_REQUEST['AllParams'])) {
    header('Content-Type: text/html; charset=utf-8');
    define('CHECK_BY_IP', false);
    define('ADDITIONAL_COMMAND', false);
    define('SIMPLE_TEST', false);
    Lang::$lang = (!empty($_REQUEST['Lang']) && in_array($_REQUEST['Lang'], array('RU', 'EN'))) ? $_REQUEST['Lang'] : 'EN';
    $test = new Test();
    $test->browserTest();
    exit();
} else {
    $args = json_decode(base64_decode($_REQUEST['AllParams']), true);
    if (!$args) { Script::responseErrorAndExit(ErrorScript::GET_PARAMETER); }
    if (empty($args['ID']) || $args['ID'] !== ID) { Script::responseErrorAndExit(ErrorScript::INVALID_ID); }
    define('CHECK_BY_IP', (!empty($args['CheckByIP']) && $args['CheckByIP'] == true));
    define('ADDITIONAL_COMMAND', (!empty($args['EmptyMail']) && $args['EmptyMail'] == true));
    define('SIMPLE_TEST', (!empty($args['SimpleTest']) && $args['SimpleTest'] == true));

    if (empty($args['Email'])) {
        Lang::$lang = (!empty($args['Lang']) && $args['Lang'] === 'Russian') ? 'RU' : 'EN';
        $test = new Test();
        $test->goTest();
        $response = array(
            "Status" => $test->getGeneralStatus(),
            "Version" => VERSION,
        );
        if (!SIMPLE_TEST) { $response["FCrDNS"] = $test->getFcrdnsStatus(); }
        if (TestStatus::OK !== $test->getGeneralStatus()) { $response["Description"] = $test->getGeneralStatusStr(); }
        Script::responseAndExit($response);
    }

    $mxRecords = (!empty($args['MX'])) ? $args['MX'] : false;
    $helo = (!empty($args['HELO'])) ? $args['HELO'] : '';
    $mailFrom = (!empty($args['MailFrom'])) ? $args['MailFrom'] : '';

    $verifier = new Verifier($helo, $mailFrom, $mxRecords);
    if (is_array($args['Email'])) {
        Script::responseErrorAndExit(ErrorScript::EMAIL_ARRAY);
    } else {
        $verifier->checkEmail($args['Email']);
    }
}
Script::responseAndExit($_RESPONSE);
exit();

class Script {
    static function responseAndExit($response) {
        header('Content-Type: application/json; charset=utf8');
        $responseString = json_encode($response);
        $responseString = preg_replace(array('/\\\r/i','/\\\n/i'), '', $responseString);
        if (DEBUG) { self::write2Log(print_r($response, 1), '', false); };
        exit(base64_encode($responseString));
    }

    static function responseErrorAndExit($text) {
        header('Content-Type: application/json; charset=utf8');
        if (DEBUG) { self::write2Log(print_r(array('Error' => $text), 1), '', false); }
        exit(base64_encode(json_encode(array('Error' => $text))));
    }

    static function write2Log($message, $tag = '', $replace = true) {
        if (!LOG_OPERATION) { return; }
        switch($tag){
            case 'error':
                $replace = false;
                $message .= "\n";
            case 'read': $symbol = '< '; break;
            case 'write': $symbol = '> '; break;
            case 'test': $symbol = '[*] '; break;
            default: $symbol = ''; break;
        }
        $message = ($replace) ? str_replace("\n", "", $message) : $message;
        $message = "{$symbol}{$message}\n";
        error_log($message, 3, LOG_PATH . '/work-log-' . date('Y-m-d') . '.log');
    }
}

class ErrorScript {
    const GET_PARAMETER = "Decode base64 or json decode";
    const EMPTY_PARAMETER = "Empty param";
    const EMPTY_MAIL_FROM = "Empty MailFrom";
    const INVALID_ID = "Invalid web script ID";
    const UNDEFINED_ERROR = "Undefined error";
    const RE_TRY = "Undefined error";
    const MX_EMPTY = "Empty MX records";
    const SCRIPT_TIMEOUT = "Script timeout";
    const EMAIL_ARRAY = "Email param contains array";
}

class Verifier {
    private $mailFrom;
    private $domain;
    private $smtp;

    function __construct($domain = '', $mailFrom = '', $mxRecords = false) {
        $this->domain = $domain;
        $this->mailFrom = $mailFrom;
        $this->smtp = new Smtp($domain, $mailFrom, $mxRecords);
    }

    public function checkEmail($email) {
        $this->smtp->connectAndVerify($email);
        $this->smtp->quit();
    }

    public function checkEmails($emails) {
        foreach($emails as $email) {
            $this->checkEmail($email);
        }
    }
}

class Smtp {

    private $host;
    private $socket;
    private $code;
    private $message;
    private $mailFrom;
    private $mxRecords;
    private $logID;

    function __construct($host = '', $mailFrom = '', $mxRecords = false) {
        $this->host = str_replace('www.','', empty($host) ? $_SERVER['SERVER_NAME'] : $host);
        $this->mailFrom = empty($mailFrom) ? 'support@' . $this->host : $mailFrom;
        $this->mxRecords = $mxRecords;
    }

    function connectAndVerify($email) {
        $expEmail = explode('@', $email);
        $mxRecords = $this->getMXRecords($expEmail[1]);
        if (empty($mxRecords)) { Script::responseErrorAndExit(ErrorScript::MX_EMPTY); }
        $res = false;
        $id = 0;
        foreach($mxRecords as $mxRecord){
            $this->logID = 'Attempt'.$id++;
            $res = $this->checkServer($mxRecord);
            if ($res){
                if ($this->verifyEmail($email) === ErrorScript::RE_TRY) { continue; }
                break;
            } else {
                break;
            }
        }
        return $res;
    }

    private function checkServer($mailHost){
        global $_RESPONSE;
        $connectCommand = "CONNECT TO: {$mailHost}";
        Script::write2Log($connectCommand, 'write', false);
        $this->socket = @fsockopen($mailHost, 25, $errorCode, $errorStr, TIMEOUT / 2);
        if ($this->socket) {
            $this->readSocket();
            $_RESPONSE['Attempts'][$this->logID]['Connect'] = array(
                'Query' => $connectCommand,
                'Response' => $this->message,
                'Code' => $this->code
            );
            if ($this->checkResponse($this->code) === false) { return false; }

            $heloCommand = "HELO {$this->host}";
            $this->writeSocket($heloCommand);
            $_RESPONSE['Attempts'][$this->logID]['Helo'] = array(
                'Query' => $heloCommand,
                'Response' => $this->message,
                'Code' => $this->code
            );
            if ($this->checkResponse($this->code) === false) { return false; }

            $this->writeSocket("MAIL FROM: <$this->mailFrom>");
            $_RESPONSE['Attempts'][$this->logID]['MailFrom'] = array(
                'Query' => 'MAIL FROM: <|'.$this->mailFrom.'|>',
                'Response' => $this->message,
                'Code' => $this->code
            );
            if ($this->checkResponse($this->code) === false) { return false; }

            return true;
        } else {
            $errorStr = strtoupper($errorStr);
            $_RESPONSE['Attempts'][$this->logID]['Connect'] = array(
                'Query' => $connectCommand,
                'Response' => $errorStr,
                'Code' => $errorCode
            );
            Script::write2Log("{$errorStr}: code {$errorCode}", 'error');
        }
        return false;
    }

    function verifyEmail($email) {
        global $_RESPONSE;
        $this->writeSocket("RCPT TO: <$email>");
        $_RESPONSE['Attempts'][$this->logID]['RcptTo'] = array(
            'Query' => 'RCPT TO: <|'.$email.'|>',
            'Response' => $this->message,
            'Code' => $this->code
        );
        if (ADDITIONAL_COMMAND && $this->checkResponse($this->code) === true) {
            $this->writeSocket("DATA");
            $_RESPONSE['Attempts'][$this->logID]['Data'] = array(
                'Query' => 'DATA',
                'Response' => $this->message,
                'Code' => $this->code
            );
            if ($this->code !== 354) { return false; }
            $this->writeSocket("\r\n.\r\n");
            $_RESPONSE['Attempts'][$this->logID]['Send'] = array(
                'Query' => 'DATA',
                'Response' => $this->message,
                'Code' => $this->code
            );
            if ($this->code !== 250) { return false; }
        }
        return $this->checkResponse($this->code);
    }

    function writeSocket($command, $writeRead = true) {
        if ($writeRead) { Script::write2Log($command, 'write'); }
        fputs($this->socket, $command . "\r\n");
        if ($writeRead) { $this->readSocket(); }
    }

    function readSocket() {
        $data = $res = '';
        $exit = false;

        while($str = @fgets($this->socket, 4096)) {
            if(substr($str,3,1) == " ") { $exit = true;}
            $data .= (!empty($data)) ? preg_replace('/\d+\s?\-?/', '', $str) : $str;
            if ($exit) { break; }
        }
        Script::write2Log($data, 'read');

        if (preg_match('/(\d+)(?:[\s\-]+(\d\.\d\.\d)|)[\s\-]+?(.*)/i', $data, $res)) {
            if (!empty($res[1])) $this->code = intval($res[1]);
        }

        $this->message = $data;
    }

    public function getMXRecords($domain){
        $usedCache = false;
        $hostsMX = array();
        if (CHECK_BY_IP) {
            $records = @dns_get_record($domain, DNS_A);
            foreach($records as $record){
                if (!empty($record['ip'])) { $hostsMX[] = $record['ip']; }
            }
        } else {
            if (empty($this->mxRecords)) {
                if (USE_CACHE) {
                    $hostsMX = CacheMX::loadCache($domain);
                    $usedCache = (!empty($hostsMX));
                }
                if (empty($hostsMX)) {
                    @getmxrr($domain, $mxhosts, $mxweight);
                    asort($mxweight);
                    foreach($mxweight as $key=>$priority) {
                        if (empty($mxhosts[$key])) { continue; }
                        $hostsMX[] = $mxhosts[$key];
                        Script::write2Log("MX:{$mxhosts[$key]} - priority: {$priority}");
                    }
                }
            } else {
                $hostsMX = $this->mxRecords;
            }
        }

        if (empty($this->mxRecords)) {
            global $_RESPONSE;
            $_RESPONSE['MX'] = $hostsMX;
            if (USE_CACHE && !CHECK_BY_IP && !empty($hostsMX) && !$usedCache){
                CacheMX::saveCache($domain, $hostsMX);
            }
        }
        return $hostsMX;
    }

    private function checkResponse($code){
        if (in_array($code, array(220, 250, 251, 452, 552))) {
            return true;
        } elseif ($code > 500 || in_array($code, array(421, 450, 510, 511, 550, 553, 554))) {
            return false;
        }
        return ErrorScript::RE_TRY;
    }

    public function quit() {
        if ($this->socket) {
            $this->writeSocket('QUIT', false);
            @fclose($this->socket);
        }
    }
}

class CacheMX {
    static function loadCache($domainKey) {
        $dataCache = json_decode(self::getDataCache(), true);
        if (empty($dataCache['storageTime']) || $dataCache['storageTime'] < time()) {
            Script::write2Log("CACHE EMPTY OR TIME HAS EXPIRED");
            return false;
        }
        if (!empty($dataCache) && !empty($dataCache[$domainKey])) {
            Script::write2Log("GET MX {$domainKey} FROM CACHE");
            return $dataCache[$domainKey];
        }
        return false;
    }

    static function saveCache($domainKey, $xmRecords) {
        $dataCache = json_decode(self::getDataCache(), true);
        if (empty($dataCache['storageTime']) || $dataCache['storageTime'] < time()) {
            unset($dataCache);
            $dataCache['storageTime'] = time() + TIME_STORAGE_CACHE;
        }
        $dataCache[$domainKey] = $xmRecords;
        $res = self::setDataCache(json_encode($dataCache));
        if ($res) {
            Script::write2Log("SET MX {$domainKey} TO CACHE");
        }
        return $res;
    }

    private static function getDataCache() {
        return @file_get_contents(CACHE_NAME);
    }

    private static function setDataCache($data) {
        return @file_put_contents(CACHE_NAME, $data);
    }
}

class TestStatus {
    const OK = 'OK';
    const WARNING = 'WARNING';
    const ERROR = 'ERROR';
}

class Test {
    private $warningFunction = array('file_exists', 'file_put_contents', 'file_get_contents');
    private $failFunction = array('json_decode', 'base64_encode', 'getmxrr', 'fsockopen', 'dns_get_record');

    private $hostForTest = 'google.com';
    private $portForTest = 25;
    private $logs;

    private $generalStatus = TestStatus::OK;
    private $generalStatusStr = '';
    private $fcrdnsLog = array();
    private $fcrdnsStatus = TestStatus::WARNING;

    function getGeneralStatus() { return $this->generalStatus; }
    function getGeneralStatusStr() { return $this->generalStatusStr; }
    function getFcrdnsStatus() { return (TestStatus::OK === $this->fcrdnsStatus); }

    private function checkPort($domain, $port) {
        $SMTP = new Smtp();
        $mxRecords = $SMTP->getMXRecords($domain);
        foreach($mxRecords as $mxRecord){
            if($res = @fsockopen($mxRecord, $port, $errCode, $errStr, TIMEOUT / 2)){
                fclose($res);
                return true;
            }
            Script::write2Log("{$mxRecord} no connect");
        }
        return false;
    }

    private function fcrdns_check()
    {
        $ipRecords = @dns_get_record($_SERVER['SERVER_NAME']);
        $NotConfirm = false;
        if (empty($ipRecords)) {
            $ipRecords[0] = array('ip' => $_SERVER['SERVER_ADDR']);
        }
        Script::write2Log('$ipRecords\n' . print_r($ipRecords, true), '', false);
        foreach($ipRecords as $ipRecord) {
            if (empty($ipRecord['ip'])) { continue; }
            $revIp = strrev($ipRecord['ip']);
            $revTargets = @dns_get_record("{$revIp}.IN-ADDR.ARPA.");
            Script::write2Log('$revTargets\n' . print_r($revTargets, true), '', false);
            if (empty($revTargets)) { continue; }
            foreach ($revTargets as $revTarget) {
                if (empty($revTarget['target'])) { continue; }
                $target = $revTarget['target'];
                $targetDomains = @dns_get_record($target);
                Script::write2Log('$targetDomains\n' . print_r($targetDomains, true), '', false);
                if (!is_array($targetDomains)) { continue; }
                foreach ($targetDomains as $targetDomain) {
                    if (empty($targetDomain['ip'])) { continue; }
                    $NotConfirm = true;
                    if ($ipRecord['ip'] == $targetDomain['ip']) {
                        $this->fcrdnsStatus = TestStatus::OK;
                        return array(
                            'msg' => Lang::_('fcrdns'),
                            'status' => $this->checkResult(TestStatus::OK)
                        );
                    }
                }
            }
        }
        if ($NotConfirm) {
            return array(
                'msg' => Lang::_('fcrdns'),
                'status' => $this->checkResult(TestStatus::WARNING),
                'query' => Lang::_('fcrdnsNotConfirm')
            );
        } else {
            return array(
                'msg' => Lang::_('fcrdns'),
                'status' => $this->checkResult(TestStatus::WARNING),
                'query' => Lang::_('fcrdnsNotFound')
            );
        }
    }

    function goTest() {
        $arLog[] = array(
            'msg' => Lang::_('Directory') . ' ' . __DIR__ . ' ' . Lang::_('writable'),
            'status' => $this->checkResult(DIR_WRITABLE, TestStatus::WARNING),
            'query' => Lang::_('writableError')
        );
        $funcCache = $this->checkFunctionExists($this->warningFunction, TestStatus::WARNING);
        $arLog[] = array(
            'msg' => Lang::_('FunctionCache'),
            'status' => $this->checkResult($funcCache['total'], TestStatus::WARNING),
            'subList' => $funcCache['listFunc'],
            'query' => Lang::_('FunctionError')
        );
        $funcScript = $this->checkFunctionExists($this->failFunction,  TestStatus::ERROR);
        $arLog[] = array(
            'msg' => Lang::_('FunctionScript'),
            'status' => $this->checkResult($funcScript['total']),
            'subList' => $funcScript['listFunc'],
            'query' => Lang::_('FunctionError')
        );
        $arLog[] = array(
            'msg' => Lang::_('Port25'),
            'status' => $this->checkResult($this->checkPort($this->hostForTest, $this->portForTest)),
            'query' => Lang::_('Port25Error')
        );
        if (!SIMPLE_TEST) { $fcrdnsLog = $this->fcrdns_check(); }

        foreach ($arLog as $log) {
            if ($log['status']['log'] === TestStatus::ERROR) {
                $this->generalStatus = TestStatus::ERROR;
                $this->generalStatusStr .= $log['msg'].': '.TestStatus::ERROR.';';
            } elseif ($log['status']['log'] === TestStatus::WARNING && $this->generalStatus !== TestStatus::ERROR) {
                $this->generalStatus = TestStatus::WARNING;
                $this->generalStatusStr .= $log['msg'].': '.TestStatus::WARNING.';';
            }
            Script::write2Log($log['msg'] . ' - ' . $log['status']['log'], 'test');
        }
        $this->logs = $arLog;
        if (!SIMPLE_TEST && !empty($fcrdnsLog)) {
            Script::write2Log($fcrdnsLog['msg'] . ' - ' . $fcrdnsLog['status']['log'], 'test');
            $this->fcrdnsLog = $fcrdnsLog;
        }
    }

    function browserTest() {
        $ruChecked = (!empty($_REQUEST['Lang']) && $_REQUEST['Lang'] == 'RU') ? 'checked' : '';
        $enChecked = (!empty($ruChecked)) ? '' : 'checked';

        echo '<form action="./'.basename(__FILE__) .'" method="get" style="float: right">
                <label>EN <input type="radio" name="Lang" value="EN" '.$enChecked.'></label>
                <label>RU <input type="radio" name="Lang" value="RU" '.$ruChecked.'></label>
                <input type="submit" value="'.LANG::_('changeLang').'">
              </form>';
        Script::write2Log('START BROWSER TEST');
        $this->goTest();

        $statusTests = '';

        if ($this->generalStatus !== TestStatus::OK) {
            foreach ($this->logs as $log){
                $error = '';
                $subList = '';
                if (!empty($log['subList'])) {
                    $subList .= '<ul>';
                    foreach ($log['subList'] as $funcName => $func) {
                        $error = '';
                        if ($func['log'] !== TestStatus::OK) {
                            $error = '[ '.$this->getGoogleLink(str_replace('%func%', $funcName, $log['query'])).' ]';
                        }
                        $subList .= "<li>$funcName - {$func['html']} {$error}</li>";
                    }
                    $subList .= "</ul>";
                }
                if (TestStatus::OK !== $log['status']['log'] && empty($log['subList'])) {
                    $error = "[" . $this->getGoogleLink($log['query']) . "]";
                }
                $statusTests .= "<li>{$log['msg']} - {$log['status']['html']} {$error} {$subList} </li>";
            }
            $statusTests = "<ul>{$statusTests}</ul>";
        }
        echo '<h2>' . Lang::_('BrowserTest') . '</h2>';
        echo "{$statusTests}";
        echo '<h4>'.Lang::_('GeneralStatus'). ": {$this->generalStatus}</h4>";

        if (!SIMPLE_TEST && $this->fcrdnsStatus !== TestStatus::OK && !empty($this->fcrdnsLog)) {
            echo '<h4>'.$this->fcrdnsLog['msg'] . ': ' . $this->fcrdnsLog['status']['html']  . ' [ '. $this->getGoogleLink($this->fcrdnsLog['query']) . ' ]</h4>';
        }

        echo '<h5>' . Lang::_('VERSION') . ': ' . VERSION . '</h5>';
        Script::write2Log('END BROWSER TEST');
    }

    private function checkFunctionExists($arrayFunction, $false) {
        $total = true;
        $listFunc = array();
        foreach ($arrayFunction as $func) {
            if (!function_exists($func)) {
                $total = false;
                $listFunc[$func] = $this->checkResult(false, $false);
            } else {
                $listFunc[$func] = $this->checkResult(true, $false);
            }
        }
        return array('total' => $total, 'listFunc' => $listFunc);
    }

    private function checkResult($result, $tag = '') {
        if ((!$result && $tag == TestStatus::WARNING) || $result === TestStatus::WARNING) { $answer = TestStatus::WARNING; $color = 'goldenrod';
        } elseif ($result || $result===TestStatus::OK) { $answer = TestStatus::OK; $color = 'green';
        } else { $answer = TestStatus::ERROR; $color  = 'red'; }
        return array("html" => "<span style='color:{$color}'>{$answer}</span>", "log" => $answer);
    }

    private function getGoogleLink($text) {
        $query = urlencode($text);
        return "<a target=\"_blank\" href=\"http://google.com/search?q={$query}\">{$text}</a>";
    }
}

class Lang {
    static $lang = 'EN';
    static $loca = array(
        "Directory" => array(
            'RU' => 'Папка',
            'EN' => 'Directory'
        ),
        "writable" => array(
            'RU' => 'доступна для записи',
            'EN' => 'writable'
        ),
        "writableError" => array(
            'RU' => 'как выставить права доступа 777 на папку',
            'EN' => 'How to set 777 permission on a particular folder'
        ),
        "FunctionCache" => array(
            'RU' => 'Функции для работы с кэшем',
            'EN' => 'Function for cache'
        ),
        "FunctionScript" => array(
            'RU' => 'Функции для работы скрипта',
            'EN' => 'Function for script'
        ),
        "FunctionError" => array(
            'RU' => 'php функция %func% не найдена',
            'EN' => 'php %func% not found'
        ),
        "Port25" => array(
            'RU' => 'Порт 25',
            'EN' => 'Port 25'
        ),
        "Port25Error" => array(
            'RU' => 'что делать если порт 25 закрыт на хостинге',
            'EN' => 'port 25 is blocked by hosting provider'
        ),
        "BrowserTest" => array(
            'RU' => 'Тест скрипта',
            'EN' => 'Browser script-test'
        ),
        "GeneralStatus" => array(
            'RU' => 'Общий статус',
            'EN' => 'General status'
        ),
        "VERSION" => array(
            'RU' => 'ВЕРСИЯ',
            'EN' => 'VERSION'
        ),
        "changeLang" => array(
            'RU' => 'Change',
            'EN' => 'Сменить'
        ),
        "fcrdns" => array(
            'RU' => 'Forward-confirmed reverse DNS',
            'EN' => 'Forward-confirmed reverse DNS'
        ),
        "fcrdnsNotFound" => array(
            'RU' => 'Обратная DNS не найдена',
            'EN' => 'reverse DNS not found'
        ),
        "fcrdnsNotConfirm" => array(
            'RU' => 'Обратная DNS не подтверждена',
            'EN' => 'reverse DNS is NOT forward confirmed'
        )
    );
    static function _($key){ return self::$loca[$key][self::$lang]; }
}
