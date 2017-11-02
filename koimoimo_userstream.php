<?php
require 'twitteroauth/autoload.php';
use Abraham\TwitterOAuth\TwitterOAuth;
require_once('phirehose/lib/UserstreamPhirehose.php');

class MyUserConsumer extends UserstreamPhirehose
{
  public function enqueueStatus($status)
  {
    $data = json_decode($status);
    debug_print($status."\n");
    if(array_key_exists("id_str",$data)){
        reply_bot($data);
    }
  }
}

define("MENTION_VALUE","ﾎｱｰｯ");
define("MENTION_HEAD"," RT @");
define("MESSAGE_HEAD","koimoimo_botは[");
define("MESSAGE_FOOT","]を学習しました！ http://ko.imoimo.xyz/");

// REST API
$conn = new TwitterOAuth(TWITTER_CONSUMER_KEY,TWITTER_CONSUMER_SECRET,OAUTH_TOKEN,OAUTH_SECRET);

$key = get_db_data();

// Start streaming
$sc = new MyUserConsumer(OAUTH_TOKEN, OAUTH_SECRET);
$sc->consume();

function reply_bot($tl)
{
    global $conn;
    $sname = $tl->user->screen_name;
    $text = $tl->text;
    $time = $tl->created_at;
    $status_id = $tl->id;
    debug_print($time." @".$sname.": ".$text."\n");
    if($sname != MYNAME){
        if(get_key_score($text) > 0 &&
           mb_strpos($text,MENTION_VALUE) === FALSE &&
           mb_strpos($text,MESSAGE_HEAD) === FALSE){
            debug_print("Hit key:".$text."\n");
            if(array_key_exists("retweeted_status",$tl)){
                $r_sname = $tl->retweeted_status->user->screen_name;
                $res = mb_strpos($r_sname,MYNAME);
            }else if(array_key_exists("quoted_status",$tl)){
                $r_sname = $tl->quoted_status->user->screen_name;
                $res = mb_strpos($r_sname,MYNAME);
            }else{
                $res = mb_strpos($text,MYNAME);
            }
            if($res !== FALSE) send_mention($conn,$status_id,$sname,$text);
        }
    }else{
        $tmp = $text;
        if(array_key_exists("quoted_status",$tl)){
            if($tl->quoted_status->user->screen_name != MYNAME){
                $tmp = $tl->quoted_status->text;
            }
        }
        $key = extract_keyword($tmp);
        debug_print("appending [".$key."]\n");
        if(get_key_score($key) == 0 &&
           mb_strpos($text,MENTION_VALUE) !== FALSE &&
           mb_strpos($key,MENTION_VALUE) === FALSE){
            append_keyword($conn,$key);
        }
    }
}

function send_mention($con,$status_id,$usr,$txt)
{
    $m = MENTION_VALUE.MENTION_HEAD.$usr.": ".$txt;
    if(mb_strlen($m) > 140) $m = mb_strimwidth($m,0,140,"...");
    $r = $con->post("statuses/update", ["status" => $m,"in_reply_to_status_id" => $status_id]);
    $log = "Tweeted at ".date("Ymd_His")."[".$m."]\n";
    file_put_contents(dirname(__FILE__)."/".LOG_FILE,$log,FILE_APPEND);
    debug_print($log);
    return $r;
}

function get_key_score($txt)
{
    global $key;
    $s = 0;
    if($txt != ""){
        foreach($key as $k){
            if(mb_strpos($txt,$k) !== FALSE) $s++;
        }
    }
    return $s;
}

function append_keyword($con,$txt)
{
    global $key;
    if($txt == "" || mb_strpos($txt,MENTION_VALUE) !== FALSE) return "";
    add_db_data($txt);
    $key = array();
    $key = get_db_data();
    $tw = MESSAGE_HEAD.$txt.MESSAGE_FOOT;
    $r = $con->post("statuses/update", ["status" => $tw]);
    $log = "Appended Keyword ".date("Ymd_His")."[".$txt."]\n";
    file_put_contents(dirname(__FILE__)."/".LOG_FILE,$log,FILE_APPEND);
    debug_print($log);
    return $txt;
}

function extract_keyword($txt)
{
    $r = "";
    $p = mb_strpos($txt,"@");
    $pp = mb_strpos($txt,"RT",$p);
    $ph = mb_strpos($txt,"#",$p);
    $pu = mb_strpos($txt,"http",$p);
    if($pu !== FALSE && $ph === FALSE) $ph = $pu;
    if($pp === FALSE){
        if($p === FALSE){
            $p = 0;
        }else{
            $pt = mb_strpos($txt,"@",$p + 1);
            if($pt !== FALSE) $p = $pt;
            $p = mb_strpos($txt," ",$p);
        }
        if($ph === FALSE){
            $r = trim(mb_substr($txt,$p));
        }else{
            $r = trim(mb_substr($txt,$p,$ph - $p));
        }
    }else{
        $p = mb_strpos($txt," ",$p);
        if($ph === FALSE){
            $pp = $pp - $p;
        }else{
            $pp = $ph - $p;
        }
        $r = trim(mb_substr($txt,$p,$pp));
    }
    return $r;
}

function get_db_data()
{
    $db = new SQLite3(dirname(__FILE__)."/".KEYWORD_DB);
    $key = array();
    $i = 0;
    $r = $db->query("SELECT BASE64 FROM KEYWORDS");
    while($row = $r->fetchArray(SQLITE3_ASSOC)){
        $key[$i] = base64_decode($row['BASE64']);
        $i++;
    }
    $db->close();
    return $key;
}

function add_db_data($txt)
{
    $db = new SQLite3(dirname(__FILE__)."/".KEYWORD_DB);
    $b = base64_encode($txt);
    $sql = "INSERT INTO KEYWORDS(WORD,BASE64) VALUES('".$txt."','".$b."')";
    $db->exec($sql);
    $db->close();
}

function debug_print($txt)
{
    if(defined("INDEBUG")) echo $txt;
}
