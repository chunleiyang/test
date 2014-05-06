<?php
/***************************************************************************
 * 
 * Copyright (c) 2014 Baidu.com, Inc. All Rights Reserved
 * 
 **************************************************************************/
 
 
 
/**
 * @file modify_user_data.php
 * @author yangchunlei(com@baidu.com)
 * @date 2014/05/05 19:14:34
 * @brief 
 *  
 **/


if($argc != 4) {
    echo "USAGE: php ".basename(__FILE__)." UID_FILE START_UID END_UID\n";
    exit();
}

$scope_uid_s = intval($argv[2]);
if(!$scope_uid_s) {
    echo "invalid argument. \$argv[2]:$argv[2]\n";
    exit();
}

$scope_uid_e = intval($argv[3]);
if(!$scope_uid_e) {
    echo "invalid argument. \$argv[3]:$argv[3]\n";
    exit();
}

$log = fopen("modify.log", "a");

function _logCommon($level, $str) {
    global $log; 

    $date = date("m-d h:i:s: ");
    fwrite($log, "$level $date $str\n");
}

function logWarning($str) {
    _logCommon("WARNING:", $str);
}

function logFatal($str) {
    _logCommon("FATAL:  ", $str);
}

function logNotice($str) {
    _logCommon("NOTICE: ", $str);
}

$file = fopen("$argv[1]", "r");

if($file == false) {
    logFatal("open uid file failed. [file:{$argv[1]}]");
    fclose($log);
    exit();
}

function _readFromDbgate($uid) {
    $req = array(
        'header' => array('content_type' => 'McpackRpc'),
        'content' => array(
            array(
                'service_name' => 'userinfo_all',
                'method' => 'batget',
                'id' => 1,
                'params' => array(
                    'inter_encoding' => 'utf8',
                    'app_user' => 'passport', 
                    'app_passwd' => 'passport',
                    'userid' => array(intval($uid)),
                    'req_fields' => array(
                        'username',
                        'secureemail',
                        'securemobil',
                        ),
                    ),
                ),
            ),
        ); 

    $res = ral("dbgate_r_bj", "batget", $req, 1);

    if(!is_null($res)) {
        $errno = $res['content'][0]['err_no'];
        $errmsg = $res['content'][0]['err_msg'];

        if($errno == 0) {
            return $res['content'][0]['result_params'];
        } 
        else {
            logWarning("read data from dbgate failed. [userid:$uid][errno:$errno][errmsg:$errmsg]");
            return null;
        }
    }
    else {
        logFatal("ral return null.");
        return null;
    }
}

function _getClientIp($uid) {
    $req = array(
        'cmd' => 4355,
        'apid' => 1025,
        'clientip' => 19890613,
        'uids' => array($uid),
        'uid_cnt' => 1,
        'exptime' => 0,
        );

    $res = ral('ssngate_rw', 'query', $req, 1);

    if(!is_null($res)) {
        $status = $res['status'];

        if($status != 0) {
            logWarning("read data from ssnim failed. [userid:$uid][status:$status]");
            return null;
        }

        return $res['lip'][0];
    }
    else {
        logFatal("ral return null."); 
        return null;
    }
}

function doLogin($uid) {
    $usr_data = _readFromDbgate($uid);
    if(empty($usr_data)) {
        logWarning("read user data from dbgate failed, maybe not exist. [usreid:$uid]");
        return false; 
    }

    $clientip = _getClientIp($uid);
    if(is_null($clientip)) {
        logWarning("read clientip from ssnim failed. [usreid:$uid]");
        return false;
    }

    echo "usr_data:\n";
    var_dump($usr_data);
    echo "clientip:\n";
    var_dump($clientip);

    $uname = is_null($usr_data[$uid]['usrename']) ? '' : $usr_data[$uid]['username'];
    $email = is_null($usr_data[$uid]['secureemail']) ? '' : $usr_data[$uid]['secureemail'];
    $mobil = is_null($usr_data[$uid]['securemobil']) ? '' : $usr_data[$uid]['securemobil'];

    $req = array(
        'cmd' => 4098,
        'apid' => 1025,
        'clientip' => $clientip,
        'uid' => $uid,
        'uname' => $uname,
        'secureemail' => $email,
        'securemobil' => $mobil,
        '(raw)gdata' => '00000000',
        '(raw)gmask' => '00000000',
        '(raw)pdata' => '00000000',
        '(raw)pmask' => '00000000',
        'exptime' => 0,
        );

    $res = ral('ssngate_rw', 'login', $req, 1);

    if(!is_null($res)) {
        $status = $res['status'];
        if($status != 0) {
            logWarning("login failed. [userid:$uid][status:$status]"); 
            return false;
        } 

        return _doLogout($res['bduss']);
    }
    else {
        logFatal("ral return null.");
        return false;
    }
}

function _doLogout($bduss) {
    $req = array(
        'cmd' => 4099,
        'apid' => 1025,
        'clientip' => 19890613,
        'bduss' => $bduss,
        ); 

    $res = ral('ssngate_rw', 'logout', $req, 1);

    if(!is_null($res)) {
        $status = $res['status'];
        if($status != 0) {
            logWarning("logout failed. [userid:$uid][status:$status]"); 
            return false;
        }

        return true;
    }
    else {
        logFatal("ral return null.");
        return false;
    }
}

$cost_s = gettimeofday(true);
$succ_cnt = 0;

logNotice("begin time");

while(!feof($file)) {
    $item = fgets($file);
    if(!$item) {
        break;
    }

    $uid = intval($item);
    if($uid > $scope_uid_e || $uid < $scope_uid_s) {
        continue;
    }

    echo "Process uid:$uid\n";
    $succ_cnt++;
    
    doLogin($uid);
}

logNotice("end time");
$cost_e = gettimeofday(true);
$cost = $cost_e - $cost_s;
logNotice("process $succ_cnt uids. cost $cost s!");
fclose($file);
fclose($log);

/* vim: set expandtab ts=4 sw=4 sts=4 tw=100: */
?>
