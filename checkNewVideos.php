<?php
include 'simple_html_dom.php';
include 'config.php';

ignore_user_abort(true);
set_time_limit(5000);

function connectToDB()
{
    $dbConn = new PDO(DSN . ';dbname=' . dbname, username, password);
    $dbConn->exec("SET NAMES utf8");
    return $dbConn;
}

function exec_curl_request($handle)
{
    $response = curl_exec($handle);

    if ($response === false) {
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        error_log("Curl returned error $errno: $error\n");
        curl_close($handle);
        return false;
    }

    $http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));
    curl_close($handle);
    if ($http_code >= 500) {
        // do not wat to DDOS server if something goes wrong
        sleep(10);
        return false;
    } else if ($http_code != 200) {
        $response = json_decode($response, true);
        error_log("Request has failed with error {$response['error_code']}: {$response['description']}\n");
        if ($http_code == 401) {
            throw new Exception('Invalid access token provided');
        }
        return false;
    } else {
        $response = json_decode($response, true);
        if (isset($response['description'])) {
            error_log("Request was successfull: {$response['description']}\n");
        }
        $response = $response['result'];
    }

    return $response;
}

function apiRequest($method, $parameters)
{
    if (! is_string($method)) {
        error_log("Method name must be a string\n");
        return false;
    }

    if (! $parameters) {
        $parameters = array();
    } else if (! is_array($parameters)) {
        error_log("Parameters must be an array\n");
        return false;
    }
    
    foreach ($parameters as $key => &$val) {
        // encoding to JSON array parameters, for example reply_markup
        if (! is_numeric($val) && ! is_string($val)) {
            $val = json_encode($val);
        }
    }
    $url = API_URL . $method . '?' . http_build_query($parameters);
    $url = str_replace('%25', '%', $url); // quick fix to enable newline in messages. '%' musn't be replaced by http encoding to '%25'
    $handle = curl_init($url);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($handle, CURLOPT_TIMEOUT, 60);
    
    return exec_curl_request($handle);
}
function messageUser($chat_id, $text, $thumbUrl)
{
    apiRequest("sendPhoto", array(
        'parse_mode' => 'HTML',
        //'disable_web_page_preview' => true,
        'chat_id' => $chat_id,
        'photo' => $thumbUrl,
        "caption" => $text
    ));
}
function getChannelsVideoList($channel_url)
{
    $html = file_get_html($channel_url);
    if($html == false) return null;
    $videoList = $html->find('div.channel-videos-container');
    return $videoList;
}
function getSubscriberList($channel_id, $dbConn) 
{
    $stmt = $dbConn->prepare('SELECT chat_id FROM bitchuteNotifier.subscriptions WHERE channel_id = ?');
    $stmt->execute(array($channel_id));
    $subscriberList = array();
    while ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
        array_push($subscriberList, $user);
    }
    return $subscriberList;
}
function addVideo($channel_id, $video_url, $dbConn)
{
    $stmt = $dbConn->prepare('INSERT INTO `bitchuteNotifier`.`videos` (`channel_id`, `video_url`) VALUES (:channel_id, :video_url)');
    $stmt->bindParam(':channel_id', $channel_id);
    $stmt->bindParam(':video_url', $video_url);
    return $stmt->execute();
}
function getChannelList($dbConn) 
{
    $result = $dbConn->query('SELECT channels.channel_id, channels.channel_url, channels.channel_name FROM bitchuteNotifier.subscriptions INNER JOIN channels ON channels.channel_id = subscriptions.channel_id GROUP BY channel_id');
    return $result;
}
function notifyAllSubscriber($subscriberList, $messageText, $photoUrl) 
{
    foreach ($subscriberList as $subscriber) {
        messageUser($subscriber['chat_id'], $messageText, $photoUrl);
    }
}
function get_string_between($string, $start, $end){
    $string = " ".$string;
    $ini = strpos($string,$start);
    if ($ini == 0) return "";
    $ini += strlen($start);
    $len = strpos($string,$end,$ini) - $ini;
    return substr($string,$ini,$len);
}

$dbConn = connectToDB();

$channelList = getChannelList($dbConn);
while ($channel = $channelList->fetch(PDO::FETCH_ASSOC)) {
    $channel_id = $channel['channel_id'];
    $channel_url = $channel['channel_url'];
    $channel_name = $channel['channel_name'];
    
    $videoList = getChannelsVideoList($channel_url);
    if($videoList == null) continue;
    $subscriberList = getSubscriberList($channel_id, $dbConn);
    echo "Checking {$channel_name}\r\n";
    foreach($videoList  as $element){
         
        $video_url = $element->children(0)->children(1)->children(0)->children(1)->children(0)->href;
        $video_title = $element->children(0)->children(1)->children(0)->children(1)->children(0)->plaintext;
        $video_description = $element->children(0)->children(1)->children(0)->children(2)->children(0)->plaintext;
        $video_duration = $element->children(0)->children(0)->children(0)->children(0)->children(0)->children(5)->plaintext;
        $thumbUrl = get_string_between($element->children(0)->children(0)->children(0)->children(0)->children(0)->children(0), 'data-src="', '" onerror');
        
        $messageText = "<a href=\"$thumbUrl\">&#8204</a><b>$channel_name</b> - $video_title %0A%0A$video_description%0A%0Ahttps://www.bitchute.com$video_url $video_duration";
        $isNewUpload = addVideo($channel_id, $video_url, $dbConn);
        
        //echo $element->children(0)->children(0)->children(0)->children(0)->children(0)->children(0); //How to get video thumb url from this?
        
        if($isNewUpload) {
            notifyAllSubscriber($subscriberList, $messageText, $thumbUrl);
        }
    }
}
?>