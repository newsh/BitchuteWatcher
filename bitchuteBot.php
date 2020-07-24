 <?php
include 'simple_html_dom.php';
include 'config.php';

$content = file_get_contents("php://input");
$update = json_decode($content, true);

function contentIsBlockedInCountry($url) {
    if(!isValidUrl($url)) return false;
    $html = file_get_html($url);
    $divElement = $html->find('div[id=page-detail]');
    if($divElement != null)
        $detailtext = $divElement[0]->children(0)->children(0)->innertext;
    return preg_match("/This (video|channel) is unavailable as the contents have been deemed illegal by the authorities within your country./", $detailtext);
    
}
function isValidUrl($input)
{
    if (!filter_var($input, FILTER_VALIDATE_URL) || get_headers($input)[0] == "HTTP/1.1 404 Not Found") return false; 
    
    $isBitchuteUrl = preg_match('/(http)(s)*(:\/\/www.bitchute.com\/).*/', $input);
    
    $html = file_get_html($input);
    $nameParagraph = $html->find('p.name');
    $hasNameParagraph = (sizeof($nameParagraph) > 0) ? true: false;

    return $isBitchuteUrl && $hasNameParagraph;
}

function connectToDB()
{
    $db = new PDO(DSN . ';dbname=' . dbname, username, password);
    $db->exec("SET NAMES utf8");
    return $db;
}

function addChannelToDatabase($channel_name, $channel_url)
{
    $db = connectToDB();

    $stmt = $db->prepare('INSERT INTO channels (channel_name, channel_url) VALUES (:channel_name, :channel_url)');
    $stmt->bindParam(':channel_url', $channel_url);
    $stmt->bindParam(':channel_name', $channel_name);
    return $stmt->execute();
}

function addChannelToSubscriptions($chat_id, $channel_url)
{
    $db = connectToDB();

    // Query channel's channel_id
    $stmt = $db->prepare('SELECT channel_id FROM channels WHERE channel_url = ?');
    $stmt->execute([
        $channel_url
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $channel_id = $row['channel_id'];

    // Insert channel to subscriptions
    $stmt = $db->prepare('INSERT INTO subscriptions (chat_id, channel_id) VALUES (:chat_id, :channel_id)');
    $stmt->bindParam(':channel_id', $channel_id);
    $stmt->bindParam(':chat_id', $chat_id);
    return $stmt->execute();
}

function extractChannelName($channel_url)
{
    $html = file_get_html($channel_url);
    $pageBarContainer = $html->find('div.details');
    $channelName = $pageBarContainer[0]->children(0)->children(0)->innertext;
    if((strpos($channelName, 'email&#160;protected') !== false))
        $channelName = substr($pageBarContainer[0]->children(0)->children(0)->href,9,-1);
    return $channelName;
}

function extractChannelUrl($url)
{
    $html = file_get_html($url);
    $videoAuthor = $html->find('p.name');
    $channel_url = "https://www.bitchute.com" . $videoAuthor[0]->children(0)->href;
    return $channel_url;
}

function buildSubscriptionOverview($chat_id)
{
    $db = connectToDB();

    $subscriptionOverview = null;
    $stmt = $db->prepare('SELECT channel_name, channel_url FROM subscriptions JOIN channels ON subscriptions.channel_id = channels.channel_id WHERE chat_id = ?');
    $stmt->execute([
        $chat_id
    ]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $subscriptionOverview .= '<a href ="' . $row['channel_url'] . '">' . $row['channel_name'] . '</a>%0A';
    }

    return $subscriptionOverview;
}

function addVideoUrls($channel_url)
{
    $html = file_get_html($channel_url);
    $videoList = $html->find('div.channel-videos-container');
    $db = connectToDB();
    $stmt = $db->prepare('SELECT channel_id FROM channels WHERE channel_url = ?');
    $stmt->execute([
        $channel_url
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $channel_id = $row['channel_id'];

    foreach ($videoList as $element) {
        $videol_url = $element->children(0)
            ->children(1)
            ->children(0)
            ->children(1)
            ->children(0)->href;
        $stmt = $db->prepare('INSERT INTO videos (channel_id, video_url) VALUES (:channel_id, :video_url)');
        $stmt->bindParam(':channel_id', $channel_id);
        $stmt->bindParam(':video_url', $videol_url);
        $stmt->execute();
    }
}

function buildSubscriptionInlineKeyboard($chat_id, $pageNumber)
{
    $db = connectToDB();

    $startingIndex = $pageNumber * 7 - 7;
    $upperLimit = 7;

    $result = $db->query('SELECT subscriptions.channel_id, channel_name 
            FROM subscriptions JOIN channels ON subscriptions.channel_id = channels.channel_id 
            WHERE chat_id = ' . $chat_id . ' 
			ORDER BY channel_id LIMIT ' . $startingIndex . ',' . intval($upperLimit + 1));

    $rowCount = $result->rowCount();

    if ($rowCount > 0) {

        $showSubscriptionsKeyboard = array();
        for ($i = 1; $i <= $rowCount; $i ++) {
            $row = $result->fetch(PDO::FETCH_ASSOC);
            $string = null;
            $string .= html_entity_decode($row['channel_name'], ENT_QUOTES);
            $data = '{"delete_channel_id":"' . $row['channel_id'] . '"}';
            $inlineBtn = (object) array(
                'text' => $string,
                'callback_data' => $data
            );
            array_push($showSubscriptionsKeyboard, array(
                $inlineBtn
            ));
        }
        if ($pageNumber == 1 && $rowCount > $upperLimit) {
            array_pop($showSubscriptionsKeyboard);
            array_push($showSubscriptionsKeyboard, array(
                (object) array(
                    'text' => "››",
                    'callback_data' => '{"page":"2"}'
                )
            ));
        } else if ($pageNumber != 1 && $rowCount <= $upperLimit)
            array_push($showSubscriptionsKeyboard, array(
                (object) array(
                    'text' => "‹‹",
                    'callback_data' => '{"page":' . intval($pageNumber - 1) . '}'
                )
            ));
        else if ($pageNumber != 1 && $rowCount > $upperLimit) {
            array_pop($showSubscriptionsKeyboard);
            array_push($showSubscriptionsKeyboard, array(
                (object) array(
                    'text' => "‹‹",
                    'callback_data' => '{"page":' . intval($pageNumber - 1) . '}'
                ),
                array(
                    'text' => "››",
                    'callback_data' => '{"page":' . intval($pageNumber + 1) . '}'
                )
            ));
        }
        $btnMainMenu = (object) array(
            'text' => "Cancel",
            'callback_data' => '{"back":"cancel"}'
        );
        array_push($showSubscriptionsKeyboard, array(
            $btnMainMenu
        ));

        return $showSubscriptionsKeyboard;
    } else {
        return null;
    }
}
function deleteChannelFromSubscriptions($chat_id, $channel_id)
{
    $db = connectToDB();
    $stmt = $db->prepare('DELETE FROM subscriptions WHERE chat_id = ? and channel_id = ?');
    $stmt->execute([$chat_id, $channel_id]);
}
function updateInlineKeyboard($chat_id, $message_id, $text, $inlineKeyboard)
{
    apiRequest("editMessageText", array(
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'parse_mode' => 'HTML',
        "text" => $text,
        'reply_markup' => array(
            'inline_keyboard' => $inlineKeyboard
        )
    ));
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
        // do not want to DDOS server if something goes wrong
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
function sendMessage($chat_id, $text)
{
    apiRequest("sendMessage", array(
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'chat_id' => $chat_id,
        "text" => $text
    ));
}
function sendInlineKeyboard($chat_id, $text, $inlineKeyboard)
{
    apiRequest("sendMessage", array(
        'chat_id' => $chat_id,
        'parse_mode' => 'HTML',
        "text" => $text,
        'reply_markup' => array(
            'inline_keyboard' => $inlineKeyboard
        )
    ));
}
function processMessage($message)
{
	// process incoming message
    $text = $message['text'];
    $chat_id = $message['chat']['id'];

    if (isset($message['reply_to_message']['text'])) { // when user responds to bot by force_reply initiated by bot. Field 'reply_to_message' is empty otherwise.
    }
    if (isset($message['text'])) { // when user sends any text - Either by typing or pressing button

        if ($text == '/start') {
            sendMessage($chat_id, "<i> Work in progress...</i>%0A/list%0A/delete%0A/add");
        }
        else if ($text == '/list') {
            $subscriptionList = buildSubscriptionOverview($chat_id);
            if ($subscriptionList == null)
                sendMessage($chat_id, "You don't have any subscriptions yet.");
            else
                sendMessage($chat_id, "<b>Bitchute:</b>%0A" . $subscriptionList);
        } else if ($text == '/add') {
            sendMessage($chat_id, "Enter the channel URL");
        }
         else if ($text == "/delete") {
            $inlineKeyboard = buildSubscriptionInlineKeyboard($chat_id, 1);
            if ($inlineKeyboard) {
                sendInlineKeyboard($chat_id, "Select the channel you want to delete", $inlineKeyboard);
            } else
                sendMessage($chat_id, "No channels to delete!");
        } else {
            if(contentIsBlockedInCountry($text)) {
                sendMessage($chat_id, "This video/channel is unavailable for the bot's country of origin.");
            }
            else if (isValidUrl($text)) {
                $channel_url = extractChannelUrl($text);
                $channel_name = extractChannelName($channel_url);
                $isNewChannel = addChannelToDatabase($channel_name, $channel_url);
                $isNewSub = addChannelToSubscriptions($chat_id, $channel_url);
                
                if($isNewSub) 
                    sendMessage($chat_id, "Subscribed to <b>" . $channel_name . "</b>. You will be notified of new uploads.");
                else
                    sendMessage($chat_id, "You are already subscribed to <b>" . $channel_name . "</b>.");
                    
                if($isNewChannel)
                    addVideoUrls($channel_url);
            } 
            else 
                sendMessage($chat_id, "Could not find channel. Please check if url is correct.");;
        }
    } else { // user sends anything but text msg
             // ("sendMessage", array('chat_id' => $chat_id, "text" => 'I only read text messages, sorry!'));
    }
}
function processCallbackQuery($callback_query)
{
    $chat_id = $callback_query['from']['id'];
    $callbackData = $callback_query['data'];
    $message_id = $callback_query['message']['message_id'];
    $callbackData = json_decode($callbackData);

    if (($callbackData->page)) {
        $pageNumber = $callbackData->page;
        updateInlineKeyboard($chat_id, $message_id, 'Select the channel to delete.', buildSubscriptionInlineKeyboard($chat_id, $pageNumber));
    }
    else if($callbackData->delete_channel_id) {
        deleteChannelFromSubscriptions($chat_id, $callbackData->delete_channel_id);
        apiRequest("deleteMessage", array(
            "chat_id" => $chat_id,
            "message_id" => $message_id
        ));
        sendMessage($chat_id, "Channel removed from list!");
    }
    else if($callbackData->back) {
        
        apiRequest("deleteMessage", array(
            "chat_id" => $chat_id,
            "message_id" => $message_id
        ));
        sendMessage($chat_id, "Command canceled!");
        
    }
    apiRequest("answerCallbackQuery", array(
        "callback_query_id" => $callback_query['id']
    ));
}

if (! $update) {
    // receive wrong update, must not happen
    exit();
}
if (isset($update["message"])) {
    processMessage($update["message"]);
}
if (isset($update["callback_query"])) {
    processCallbackQuery($update["callback_query"]);
}









?>