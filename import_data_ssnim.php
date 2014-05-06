<?php
/***************************************************************************
 * 
 * Copyright (c) 2014 Baidu.com, Inc. All Rights Reserved
 * 
 **************************************************************************/
 
 
 
/**
 * @file import_data_ssnim.php
 * @author yangchunlei(com@baidu.com)
 * @date 2014/05/05 16:46:31
 * @brief 
 *  
 **/

error_reporting(E_ALL);
ini_set('display_errors',1);

if($argc != 3) {
    echo "USAGE: php ".basename(__FILE__)." START_UID END_UID\n";
    exit();
}

$log = fopen("import.log","a");

$db_conf_0 = array(
    'host' => '10.94.38.41',
    'port' => '3208',
    'user' => 'chunleiyang',
    'pass' => 'chunleiyang',
    'db'   => 'pass_session',
    );

$db_conf_1 = array(
    'host' => '10.94.38.41',
    'port' => '3208',
    'user' => 'chunleiyang',
    'pass' => 'chunleiyang',
    'db'   => 'pass_session',
    );

// for db shard 0
$mysqli_0 = new mysqli($db_conf_0['host'],$db_conf_0['user'],$db_conf_0['pass'],$db_conf_0['db'],$db_conf_0['port']);

if($mysqli_0->connect_errno) {
    logFatal("connect db failed. [errmsg:$mysqli_0->connect_error]");
    exit();
}

if(!$mysqli_0->set_charset('utf8')) {
    logFatal("set_charset failed. [errmsg:$mysqli_0->error]");
    exit();
}
  
// for db shard 1
$mysqli_1 = new mysqli($db_conf_1['host'],$db_conf_1['user'],$db_conf_1['pass'],$db_conf_1['db'],$db_conf_1['port']);

if($mysqli_1->connect_errno) {
    logFatal("connect db failed. [errmsg:$mysqli_0->connect_error]");
    exit();
}

if(!$mysqli_1->set_charset('utf8')) {
    logFatal("set_charset failed. [errmsg:$mysqli_0->error]");
    exit();
}

// for neclient
$neclient = new Ne_Memcache("pass_session");
if($neclient == null) {
    logFatal("create neclient failed.");
    exit();
}

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

function readFromSsnim($uid, &$ltime, &$lip, &$lcount) {
    $ltime = 0; 
    $lip = 0; 
    $lcount = 0; 

    $req = array(
        'cmd' => 4353,
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
            return false;
        }
        
        $ltime = is_null($res['ltime'][0]) ? 0 : $res['ltime'][0];
        $lip = is_null($res['lip'][0]) ? 0 : $res['lip'][0];
        $lcount = is_null($res['lcount'][0]) ? 0 : $res['lcount'][0];

        return true;
    }
    else {
        logFatal("ral return null.");
        return false;
    }
}

$tab_prefix = 'user_data_';
$cache_key_prefix = 'usr_data_';
$cost_s = gettimeofday(true);

logNotice("begin time");

for($uid=$argv[1]; $uid<$argv[2]; $uid++) {
    echo "Process uid:$uid\n";

    $tab_slice = $uid % 4;
    $mysqli = $uid % 2 == 0 ? $mysqli_0 : $mysqli_1;
    $sql_query  = "SELECT `user_data` FROM $tab_prefix$tab_slice WHERE `user_id` = $uid";

    if($res = $mysqli->query($sql_query)) {
        $row = $res->fetch_assoc();
        if(is_null($row)) {
            logWarning("$uid is not exist in db.");
            continue;
        }

        $deserial = mc_pack_pack2array($row['user_data']);
        
        if(!$deserial) {
            logWarning("deserialize failed. [userid:$uid]");
            continue;
        }

        readFromSsnim($uid, $ltime, $lip, $lcount);

        $deserial['ltime'] = $ltime;
        $deserial['lip'] = $lip;
        $deserial['lcount'] = $lcount;

        $serial = mc_pack_array2pack($deserial);
        if(!$serial) {
            logWarning("serialize failed. [userid:$uid]");
            continue;
        }

        $hex = bin2hex($serial);
        $sql_update = "INSERT INTO $tab_prefix$tab_slice (`user_id`, `user_data`) VALUES ($uid, X'{$hex}')";

        if($mysqli->query($sql_update)) {
            if(!$neclient->delete("$cache_key_prefix$uid")) {
                logWarning("delete from cache failed. [userid:$uid]"); 
            }
        }
        else {
            logFatal("write db failed. [userid:$uid][sql:$sql_update]");
        }

        $res->free();
    }
    else {
        logFatal("read db failed. [userid:$uid][sql:$sql_query]"); 
    }
}

logNotice("end time");
$cost_e = gettimeofday(true);
$cost = $cost_e - $cost_s;
logNotice("process userid [{$argv[1]}, {$argv[2]}) cost $cost s!");

$mysqli_0->close();
$mysqli_1->close();
fclose($log);

/* vim: set expandtab ts=4 sw=4 sts=4 tw=100: */
?>
