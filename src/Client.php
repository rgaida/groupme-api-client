<?php

namespace GroupMeApi;

class Client {
    /**
     * Maximum number of groups per request
     */
    const MAX_GROUPS_PER_REQUEST = 499;

    /**
     * Maximum length of primary group names
     */
    const MAX_PRI_NAME_LEN       = 140;

    /**
     * Maximum length of group descriptions
     */
    const MAX_GROUP_DESC_LEN       = 255;

    /**
     * Maximum length of message text
     */
    const MAX_MESSAGE_LEN = 1000;

    const HTTP_OK          = 200;
    const HTTP_BAD_REQUEST = 400;

    private $token;

    private $cache;
    private $useCache;
    private $cacheTimeout;

    /**
     * Class constructor
     *
     * @param string $token GroupMe API Token
     */
    public function __construct($token='') {
        $this->token = $token;
    }

    // IMAGE SERVICE METHODS

    /**
     * Uploads an image to the GroupMe Image Service
     *
     * @param string $image_file Filename fo the image file
     * @param string $mime Mime type of the image file (e.g. 'image/png')
     * @param string $name Optional image name or description
     *
     * @return string[] API result
     */
    public function uploadImage($image_file, $mime, $name = '') {
        $curl_file = new \CURLFile($image_file, $mime, $name);
        $payload = array('file' => $curl_file);
        return $this->request('POST', '/pictures', array(), $payload, true);
    }

    // BOT METHODS

    /**
     * Lists your existing bots
     *
     * @return string[] API result
     */
    public function getMyBots() {
        return $this->get('/bots');
    }

    /**
     * Gets the bot id of your bot within a group
     *
     * @param string $bot_name  Bot name
     * @param int    $group_id  Group id
     *
     * @return string Bot id
     */
    public function getBotIdInGroup($bot_name, $group_id) {
        $bots = $this->getMyBots()['response'];

        foreach($bots as $bot) {
            if ($bot['group_id'] == $group_id && $bot['name'] == $bot_name) {
                return $bot['bot_id'];
            }
        }

        return '';
    }

    /**
     * Creates a new bot
     *
     * @param string $bot_name     Name of the bot
     * @param int    $group_id     Group id where the bot will be used
     * @param string $avatar_url   Avatar image (GroupMe ImgService url)
     * @param string $callback_url Callback url
     *
     * @return string[] API result with bot id on success
     */
    public function createBot($bot_name, $group_id, $avatar_url='', $callback_url='') {
        $bot_info = array(
            'name' => $bot_name,
            'group_id' => $group_id,
            'avatar_url' => $avatar_url,
            'callback_url' => $callback_url
        );
        $payload = array('bot' => $bot_info);
        return $this->post('/bots', $payload);
    }

    /**
     * Sends a bot message
     *
     * @param string $bot_id      Bot id
     * @param string $text        Message to send
     * @param array  $attachments Message attachments
     *
     * @return string[] API result
     */
    public function sendBotMessage($bot_id, $text, array $attachments = array()) {

        $attachments = $this->verifyAttachments($attachments);

        $payload = array(
            'bot_id' => $bot_id,
            'text' => $this->encodeToUtf8($text),
            'attachments' => $attachments
        );
        return $this->post('/bots/post', $payload);
    }

    /**
     * Parses the message text and then sends it from a bot
     *
     * @param int    $bot_id Bot id
     * @param string $text   Message text
     *
     * @return mixed
     */
    public function parseBotMessage($bot_id, $text) {
        $emojification = \GroupMeApi\EmojiUtils::extractEmojiNamesFromText($text);
        $emoji_attachment = \GroupMeApi\AttachmentUtils::makeEmojiAttachment($emojification['charmap']);

        return $this->sendBotMessage($bot_id, $emojification['text'], array($emoji_attachment));
    }

    /**
     * Destroys a bot
     *
     * @param string $bot_id Bot id
     *
     * @return string[] API result
     */
    public function destroyBot($bot_id) {
        $payload = array('bot_id' => $bot_id);
        return $this->post('/bots/destroy', $payload);
    }

    // DIRECT MESSAGE METHODS

    /**
     * Summary of getOtherUserIdFromConversationId
     * @param mixed $conversation_id
     * @return mixed
     */
    public function getOtherUserIdFromConversationId($conversation_id) {
        $my_details = $this->getMyDetails();
        $my_user_id = $my_details['response']['id'];
        $user_ids = explode('+', $conversation_id);
        return $my_user_id==$user_ids[0] ? $user_ids[1] : $user_ids[0];
    }

    /**
     * Summary of getConversationIdFromOtherUserId
     * @param mixed $other_user_id
     * @return string
     */
    public function getConversationIdFromOtherUserId($other_user_id) {
        $my_details = $this->getMyDetails();
        $my_user_id = intval($my_details['response']['id']);
        $o_user_id = intval($other_user_id);
        return min($my_user_id,$o_user_id) . '+' . max($my_user_id,$o_user_id);
    }

    /**
     * Gets direct message chats
     *
     * Returns a paginated list of direct message chats, or conversations,
     * sorted by updated_at descending
     *
     * @param int $page     Page number
     * @param int $per_page Number of chats per page
     *
     * @return mixed
     */
    public function getDirectMessageChats($page = 1, $per_page = 10) {
        $query = array(
            'page' => $page,
            'per_page' => $per_page
        );
        return $this->get('/chats', $query);
    }

    /**
     * Fetches direct messages between two users
     *
     * @param string $other_user_id The other participant
     * @param int    $limit         Number of messages to retrieve
     *
     * @return mixed
     */
    public function getLatestDirectMessages($other_user_id, $limit = 20) {
        $query = array(
            'other_user_id' => $other_user_id,
            'limit' => $limit
        );
        return $this->get('/direct_messages', $query);
    }

    /**
     * Fetches 20 messages created before the given message ID
     *
     * @param string $other_user_id The other participant
     * @param string $message_id    Message id
     *
     * @return mixed
     */
    public function getDirectMessagesBefore($other_user_id, $message_id) {
        $query = array(
            'other_user_id' => $other_user_id,
            'before_id' => $message_id
        );
        return $this->get('/direct_messages', $query);
    }

    /**
     * Fetches 20 messages created after the given message ID
     *
     * @param string $other_user_id The other participant
     * @param string $message_id    Message id
     *
     * @return mixed
     */
    public function getDirectMessagesSince($other_user_id, $message_id) {
        $query = array(
            'other_user_id' => $other_user_id,
            'since_id' => $message_id
        );
        return $this->get('/direct_messages', $query);
    }

    /**
     * Sends a DM to another user
     *
     * @param string $other_user_id The other participant
     * @param string $text          Message text
     * @param array  $attachments   Message attachments
     * @param string $source_guid   Unique id
     *
     * @return mixed
     */
    public function sendDirectMessage($other_user_id, $text, array $attachments=array(),
        $source_guid=null) {

        $attachments = $this->verifyAttachments($attachments);

        $message_info = array(
            'recipient_id' => $other_user_id,
            'text' => $this->encodeToUtf8($text),
            'source_guid' => $source_guid ?: "D$other_user_id-".
                date('YmdHis-') . uniqid(),
            'attachments' => $attachments
        );
        $payload = array('direct_message' => $message_info);
        return $this->post('/direct_messages', $payload);
    }

    /**
     * Parses the message text and then sends it to another user
     *
     * @param int    $other_user_id The other participant
     * @param string $text          Message text
     * @param string $source_guid   Unique id
     *
     * @return mixed
     */
    public function parseDirectMessage($other_user_id, $text, $source_guid=null) {
        $emojification = \GroupMeApi\EmojiUtils::extractEmojiNamesFromText($text);
        $emoji_attachment = \GroupMeApi\AttachmentUtils::makeEmojiAttachment($emojification['charmap']);

        return $this->sendDirectMessage($other_user_id, $emojification['text'],
            array($emoji_attachment), $source_guid);
    }

    /**
     * Likes a message
     *
     * @param string $other_user_id The other participant
     * @param string $message_id    Message to like
     *
     * @return mixed
     */
    public function likeDirectMessage($other_user_id, $message_id) {
        $conversation_id = $this->getConversationIdFromOtherUserId($other_user_id);
        return $this->post("/messages/$conversation_id/$message_id/like");
    }

    /**
     * Unlikes a message
     *
     * @param string $other_user_id The other participant
     * @param string $message_id    Message to like
     *
     * @return mixed
     */
    public function unlikeDirectMessage($other_user_id, $message_id) {
        $conversation_id = $this->getConversationIdFromOtherUserId($other_user_id);
        return $this->post("/messages/$conversation_id/$message_id/unlike");
    }

    // GROUP METHODS

    /**
     * Checks if the authenticated user is a member
     * of a certain group
     *
     * @param mixed $group Group name or group id
     *
     * @return bool
     */
    public function isMemberOfGroup($group) {
        $res = $this->getAllGroups();

        if($res['meta']['code'] == self::HTTP_OK) {
            foreach($res['response'] as $g) {
                if ((is_numeric($group) && $g['id'] == $group) ||
                    (is_string($group) && $g['name'] == $group)) return true;
            }
        }

        return false;
    }

    /**
     * Gets a group by its id
     *
     * @param int $group_id
     *
     * @return array API response
     */
    function getGroupById($group_id) {
        return $this->get("/groups/$group_id");
    }

    /**
     * Gets a group by its name
     *
     * There is no check for ambiguous names in place!
     * The first match will be returned.
     *
     * @param mixed $name
     *
     * @return array API response
     */
    function getGroupByName($name) {
        $res = $this->getAllGroups();

        if($res['meta']['code'] == self::HTTP_OK)
            foreach($res['response'] as $group) {
                if ($group['name'] == $name)
                    return array(
                        'meta' => $res['meta'],
                        'response' => $group
                    );
            }

        return $res;
    }

    /**
     * Gets a group id by the group name
     *
     * @param string $name Group name
     *
     * @return mixed Group id or FALSE
     */
    public function getGroupIdByName($name) {
        $res = $this->getGroupByName($name);

        if($res['meta']['code'] == self::HTTP_OK)
            return $res['response']['id'];

        return FALSE;
    }

    /**
     * Gets a group name by its id
     *
     * @param int $group_id Group id
     *
     * @return mixed Group name or FALSE
     */
    public function getGroupNameById($group_id) {
        $res = $this->getGroupById($group_id);

        if($res['meta']['code'] == self::HTTP_OK)
            return $res['response']['name'];

        return FALSE;
    }

    /**
     * Lists the authenticated user's active groups
     *
     * @param int $page     Fetch a particular page of results (defaults to 1)
     * @param int $per_page Messages per page (defaults to 10)
     *
     * @return mixed
     */
    public function getGroups($page = 1, $per_page = 10) {
        $query = array(
            'page' => $page,
            'per_page' => $per_page
        );
        return $this->get('/groups', $query);
    }

    /**
     * Lists the maximum number of the authenticated user's active groups
     *
     * @return mixed
     */
    public function getAllGroups() {
        return $this->getGroups(1, self::MAX_GROUPS_PER_REQUEST);
    }

    /**
     * Lists the groups you have left but can rejoin
     *
     * @return mixed
     */
    public function getFormerGroups() {
        return $this->get('/groups/former');
    }

    /**
     * Creates a new group
     *
     * @param string $name        Primary name of the group (max 140 characters)
     * @param string $description A subheading for the group (max 255 characters)
     * @param string $image_url   GroupMe Image Service URL
     * @param bool   $share       If true, a share URL will be created
     *
     * @return mixed
     */
    public function createGroup($name, $description='', $image_url='', $share=false) {
        $payload = array(
            'name' => substr($name, 0, (strlen($name) <=
                self::MAX_PRI_NAME_LEN) ? strlen($name) : self::MAX_PRI_NAME_LEN),

            'description' => substr($description, 0, (strlen($description) <=
                self::MAX_GROUP_DESC_LEN) ? strlen($description) : self::MAX_GROUP_DESC_LEN),

            'image_url' => $image_url,
            'share' => boolval($share)
        );
        return $this->post('/groups', $payload);
    }

    /**
     * Retrieves group details
     *
     * @param string $group Group id or group name
     *
     * @return mixed
     */
    public function getGroupDetails($group) {
        if (is_numeric($group)) return $this->getGroupById($group);
        if (is_string($group)) return $this->getGroupByName($group);
        return array();
    }

    /**
     * Updates a group after creation
     *
     * $payload = array(
     *     'name'        => ...,
     *     'share'       => ...,
     *     'image_url'   => ...,
     *     'office_mode' => ...
     * );
     *
     * @param mixed $group_id
     * @param array $payload
     * @return mixed
     */
    public function updateGroupDetails($group_id, array $payload) {

        return $this->post("/groups/$group_id/update", $payload);
    }

    /**
     * Destroys a group
     *
     * @param string $group_id Group id
     *
     * @return mixed
     */
    public function destroyGroup($group_id) {
        return $this->post("/groups/$group_id/destroy");
    }

    /**
     * Joins a shared group
     *
     * @param string $group_id    Group id
     * @param string $share_token Share token
     *
     * @return mixed
     */
    public function joinGroup($group_id, $share_token) {
        return $this->post("/groups/$group_id/join/$share_token");
    }

    /**
     * Rejoins a group.
     *
     * Only works if you previously removed yourself
     *
     * @param string $group_id Group id
     *
     * @return mixed
     */
    public function rejoinGroup($group_id) {
        $payload = array('group_id' => $group_id);
        return $this->post('/groups/join', $payload);
    }

    /**
     * Gets a list of liked messages for a given period of time
     *
     * Messages are ranked in order of number of likes.
     *
     * @param string $group_id Group id
     * @param string $period Period of: 'day', 'week', or 'month'
     *
     * @return mixed
     */
    public function getLeaderboard($group_id, $period='day') {
        $query = array('period' => $period);
        return $this->get("/groups/$group_id/likes", $query);
    }

    /**
     * Gets a list of liked messages for a day
     *
     * Messages are ranked in order of number of likes.
     *
     * @param string $group_id Group id
     *
     * @return mixed
     */
    public function getLeaderboardForDay($group_id) {
        return $this->getLeaderboard($group_id, 'day');
    }

    /**
     * Gets a list of liked messages for a week
     *
     * Messages are ranked in order of number of likes.
     *
     * @param string $group_id Group id
     *
     * @return mixed
     */
    public function getLeaderboardForWeek($group_id) {
        return $this->getLeaderboard($group_id, 'week');
    }

    /**
     * Gets a list of liked messages for a month
     *
     * Messages are ranked in order of number of likes.
     *
     * @param string $group_id Group id
     *
     * @return mixed
     */
    public function getLeaderboardForMonth($group_id) {
        return $this->getLeaderboard($group_id, 'month');
    }

    /**
     * Fetches a list of messages you have liked
     *
     * @param string $group_id Group id
     *
     * @return mixed
     */
    public function getMyLikes($group_id) {
        return $this->get("/groups/$group_id/likes/mine");
    }

    /**
     * Fetches a list of messages others have liked
     *
     * @param string $group_id Group id
     *
     * @return mixed
     */
    public function getMyHits($group_id) {
        return $this->get("/groups/$group_id/likes/for_me");
    }

    /**
     * Adds members to a group
     *
     * To add a member, you must use one of the following
     * identifiers: user_id, phone_number, or email.
     *
     * $new_member = array(
     *     string $nickname, // required
     *     string $user_id,
     *     string $phone_number,
     *     string $email,
     *     string $guid
     * );
     *
     * @param string $group_id Group id
     * @param array  $members  One or more members to add
     *
     * @return mixed
     */
    public function addMembersToGroup($group_id, array $members) {
        $payload = array('members' => $members);
        return $this->post("/groups/$group_id/members/add", $payload);
    }

    /**
     * Gets the membership results from an add call
     *
     * @param string $group_id Group id
     * @param string $guid     The guid that's returned from an add request.
     *
     * @return mixed
     */
    public function getAddMembersToGroupResult($group_id, $guid) {
        return $this->get("/groups/$group_id/members/results/$guid");
    }

    /**
     * Updates your nickname in a group
     *
     * The nickname must be between 1 and 50 characters
     *
     * @param string $group_id Group id
     * @param string $nickname Nickname
     *
     * @return mixed
     */
    public function updateMyGroupMembership($group_id, $nickname) {
        $maxNickLen = 50;
        $nickname = (strlen($nickname) <= $maxNickLen) ? $nickname : substr($nickname, 0, $maxNickLen - 1);
        $membership_info = array('nickname' => $nickname);
        $payload = array('membership' => $membership_info);
        return $this->post("/groups/$group_id/memberships/update", $payload);
    }

    /**
     * Gets all members of a group
     *
     * @param int $group_id Group id
     *
     * @return array Group members or empty array
     */
    public function getGroupMembers($group_id) {
        $group = $this->getGroupDetails($group_id);
        if (is_array($group['response']['members']))
            return $group['response']['members'];

        return array();
    }

    /**
     * Removes a member (or yourself) from a group
     *
     * @param string $group_id Group id
     * @param string $user_id  User id
     *
     * @return mixed
     */
    public function removeGroupMember($group_id, $user_id) {
        return $this->post("/groups/$group_id/members/$user_id/remove");
    }

    /**
     * Retrieves messages for a group
     *
     * By default, messages are returned in groups of 20, ordered by
     * created_at descending. This can be raised or lowered by passing
     * a limit parameter, up to a maximum of 100 messages.
     *
     * @param int $group_id Group id
     * @param int $limit    Number of messages to retrieve
     *
     * @return mixed
     */
    public function getLatestGroupMessages($group_id, $limit=20) {
        $query = array('limit' => $limit);
        return $this->get("/groups/$group_id/messages", $query);
    }

    /**
     * Retrieves messages created before the given message ID
     *
     * @param int    $group_id   Group id
     * @param string $message_id Message id
     * @param int    $limit      Number of messages to retrieve
     *
     * @return mixed
     */
    public function getGroupMessagesBefore($group_id, $message_id, $limit=20) {
        $query = array(
            'before_id' => $message_id,
            'limit' => $limit
        );
        return $this->get("/groups/$group_id/messages", $query);
    }

    /**
     * Retrieves messages created immediately after the given message ID
     *
     * @param int    $group_id   Group id
     * @param string $message_id Message id
     * @param int    $limit      Number of messages to retrieve
     *
     * @return mixed
     */
    public function getGroupMessagesAfter($group_id, $message_id, $limit=20) {
        $query = array(
            'after_id' => $message_id,
            'limit' => $limit
        );
        return $this->get("/groups/$group_id/messages", $query);
    }

    /**
     * Retrieves most recent messages created after the given message ID
     *
     * @param int    $group_id   Group id
     * @param string $message_id Message id
     * @param int    $limit      Number of messages to retrieve
     *
     * @return mixed
     */
    public function getGroupMessagesSince($group_id, $message_id, $limit=20) {
        $query = array(
            'since_id' => $message_id,
            'limit' => $limit
        );
        return $this->get("/groups/$group_id/messages", $query);
    }

    /**
     * Sends a message to a group
     *
     * @param int    $group_id    Group id
     * @param string $text        Message text
     * @param string $source_guid Unique id
     * @param array  $attachments Message attachments
     *
     * @return mixed
     */
    public function sendGroupMessage($group_id, $text, array $attachments=array(),
        $source_guid=null) {

        $attachments = $this->verifyAttachments($attachments);

        $message_info = array(
            'text' => $text,
            'source_guid' => $source_guid ?: "G$group_id-" . date('YmdHis-') . uniqid(),
            'attachments' => $attachments
        );
        $payload = array('message' => $message_info);
        return $this->post("/groups/$group_id/messages", $payload);
    }

    /**
     * Parses the message text and then sends it to a group
     *
     * @param int    $group_id    Group id
     * @param string $text        Message text
     * @param string $source_guid Unique id
     *
     * @return mixed
     */
    public function parseGroupMessage($group_id, $text, $source_guid=null) {
        $emojification = \GroupMeApi\EmojiUtils::extractEmojiNamesFromText($text);
        $emoji_attachment = \GroupMeApi\AttachmentUtils::makeEmojiAttachment($emojification['charmap']);

        return $this->sendGroupMessage($group_id, $emojification['text'],
            array($emoji_attachment), $source_guid);
    }

    // USER METHODS

    /**
     * Gets details about the authenticated user
     *
     * @return mixed
     */
    public function getMyDetails() {
        return $this->get('/users/me');
    }

    /**
     * Updates attributes about your own account
     *
     * $payload = array(
     *     string $avatar_url URL to valid JPG/PNG/GIF image,
     *     string $name       Name must be of the form FirstName LastName,
     *     string $email      Email address. Must be in name@domain.com form,
     *     string $zip        Zip code
     * )
     *
     * @return mixed
     */
    public function updateMyDetails($payload) {
        return $this->post('/users/update', $payload);
    }

    /**
     * Enables SMS mode
     *
     * Enables SMS mode for N hours, where N is at most 48.
     * After N hours have elapsed, user will receive push notfications
     *
     * If the push notification ID/token that should be suppressed during
     * SMS mode is omitted, both SMS and push notifications
     * will be delivered to the device.
     *
     * @param mixed $duration N hour duration
     * @param string $registration_id Push notification ID/token
     *
     * @return mixed
     */
    public function enableSmsMode($duration, $registration_id) {
        $payload = array(
            'duration' => $duration,
            'registration_id' => $registration_id
        );
        return $this->post('/users/sms_mode', $payload);
    }

    /**
     * Disables SMS mode
     *
     * @return mixed
     */
    public function disableSmsMode() {
        return $this->post('/users/sms_mode/delete');
    }

    // ADDITIONAL METHODS

    /**
     * Looks up a member's id within a group
     *
     * @param int    $group_id      Group id
     * @param string $member_name   Member name
     * @param bool   $caseSensitive Name is case sensitive (default NO)
     *
     * @return mixed Member id or FALSE if not found
     */
    public function getGroupMemberId($group_id, $member_name, $caseSensitive = FALSE) {
        $group_members = $this->getGroupMembers($group_id);

        $member_name = (!$caseSensitive) ? strtolower($member_name) : $member_name;

        foreach ($group_members as $group_member) {
            $group_member_name = (!$caseSensitive) ?
                strtolower($group_member['nickname']) : $group_member['nickname'];

            if ($member_name == $group_member_name) return $group_member['user_id'];
        }

        return FALSE;
    }

    /**
     * Splits a large message which exceeds the allowed maximum number
     * of characters into smaller parts
     *
     * @param mixed $msg
     * @param mixed $delimiter
     * @param mixed $maxlen
     *
     * @return string[]
     */
    public static function splitLargeMessage($msg, $delimiter = "\n", $maxlen = self::MAX_MESSAGE_LEN) {
        if (strlen($msg) <= $maxlen) return array($msg);

        $items = explode($delimiter, $msg);

        $index = 0;
        $msgLength = 0;
        $res = array('');

        foreach ($items as $item) {
            if ($msgLength + strlen($item) + 1 >= $maxlen) {
                $res[++$index]  = '';
                $msgLength = 0;
            }

            $res[$index] .= "$item$delimiter";
            $msgLength += strlen($item) + 1;
        }

        return $res;
    }

    /**
     * Returns a mentions attachment for all members of a group if
     * message starts with "@all"
     * 
     * @param int $target_group   Group id to send the message to
     */
    public function getMentionsAllAttachment($target_group, $message) {
        if (substr($message, 0, 4) != "@all") return NULL;

        $group_members = $this->getGroupMembers($target_group);
        
        $res= array();

        foreach ($group_members as $group_member) {
            $res['ids'][] = $group_member['user_id'];
            $res['loc'][] = array(0, 4); // "@all ..."
        }

        return AttachmentUtils::makeMentionsAttachment($res['ids'], $res['loc']);
    }

    /**
     * Looks up member ids and member name positions
     * in the message string and returns a mentions
     * attachment
     *
     * @param int      $target_group Group id to send the message to
     * @param string[] $members      Member names to mention
     * @param string   $message      Message text
     *
     * @return array Mentions attachment
     */
    public function getMentionsAttachment($target_group, $members, $message) {
        $loci = AttachmentUtils::getUsernamePositions($message, $members, '@', TRUE);

        $group_members = $this->getGroupMembers($target_group);

        $member_ids = array();

        foreach ($group_members as $group_member) {
            $name = $group_member['nickname'];
            $id = $group_member['user_id'];

            if(in_array($name, $members)) $member_ids[$name] = $id;
        }

        $res = array('ids' => array(), 'loc' => array());

        // only use text positions from users who exist in the group

        foreach(array_keys($member_ids) as $index) {
            $res['ids'][] = $member_ids[$index];
            $res['loc'][] = $loci[$index];
        }

        if (count($res['ids']) > 0)
            return AttachmentUtils::makeMentionsAttachment($res['ids'], $res['loc']);
        else
            return NULL;
    }

    // CORE METHODS

    /**
     * Gets data from an endpoint
     *
     * @param string $endpoint API endpoint
     * @param array  $query    Request
     *
     * @return mixed
     */
    private function get($endpoint, array $query=array()) {
        return $this->request('GET', $endpoint, $query);
    }

    /**
     * Posts data to an endpoint
     *
     * @param string $endpoint API endpoint
     * @param array $payload   Payload
     *
     * @return mixed
     */
    private function post($endpoint, array $payload=array()) {
        return $this->request('POST', $endpoint, array(), $payload);
    }

    /**
     * Sends a curl post/get request
     *
     * @param string $method      POST or GET method
     * @param string $endpoint    Endpoint path
     * @param array  $query       Query
     * @param array  $payload     Payload
     * @param bool   $img_svc_url Image upload?
     *
     * @return mixed API result
     */
    private function request($method, $endpoint, array $query=array(),
        array $payload=array(), $img_svc_url=false) {

        if ($img_svc_url) {
            $base_url = 'https://image.groupme.com';
            $header = 'Content-Type: multipart/form-data';
        }
        else {
            $base_url = 'https://api.groupme.com/v3';
            $header = 'Content-Type: application/json';
        }

        $query['access_token'] = $this->token;

        $url = $base_url . $endpoint . '?' . http_build_query($query);

        if ($this->isCached($url)) {
            $result = $this->getFromCache($url);
        } else {

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_HTTPHEADER, array($header));
            curl_setopt($ch, CURLOPT_URL, $url);

            curl_setopt($ch, CURLOPT_TIMEOUT, 4);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'GroupMe API Client');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

            if ($method == 'POST') {
                $data = $img_svc_url ? $payload : json_encode($payload);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }

            $result = $this->cache($url, curl_exec($ch));

            curl_close($ch);
        }

        return json_decode($result, true);
    }

    /**
     * Removes invalid attachments
     *
     * @param array $attachments
     *
     * @return array Verified attachments
     */
    private function verifyAttachments($attachments) {
        if (is_null($attachments)) return array();

        $valid_attachments = array();

        foreach ($attachments as $attachment) {
            if (!is_null($attachment['type'])) {
                $valid_attachments[] = $attachment;
            }
        }

        return $valid_attachments;
    }

    // CACHE METHODS

    /**
     * Enables or disable caching of CURL GET responses
     *
     * @param bool $mode    If TRUE, responses will be cached
     * @param int  $timeout Timeout in seconds for cached content
     *
     */
    public function useResponseCaching($mode, $timeout = 300) {
        $this->useCache = $mode;
        $this->cacheTimeout = $timeout;
    }

    /**
     * Checks if an item is in the cache and not
     * timed out
     *
     * @param string $key Item key
     *
     * @return bool Returns true, if item is cached
     */
    private function isCached($key) {
        if (!$this->useCache) return FALSE;
        $idx = $this->getCacheIndex($key);
        if (isset($this->cache[$idx])) {
            return ($this->cache[$idx]['timestamp'] +
                $this->cacheTimeout > time());
        }
        return FALSE;
    }

    /**
     * Creates an index string from a given key
     *
     * Cache items are indexed by a hash value
     * generated from the key
     *
     * @param string $key Item key, e.g. request url, ...
     *
     * @return string Index string
     */
    private function getCacheIndex($key) {
        return md5($key);
    }

    /**
     * Gets an item from the cache
     *
     * @param string $key Item key
     *
     * @return mixed
     */
    private function getFromCache($key) {
        if ($this->isCached($key)) {
            return $this->cache[$this->getCacheIndex($key)]['data'];
        }

        return FALSE;
    }

    /**
     * Clears the cache
     */
    public function clearCache() {
        $this->cache = array();
    }

    /**
     * Removes outdated items from the cache
     */
    public function purgeCache() {
        foreach($this->cache as $key => $item) {
            if ($item['timestamp'] + $this->cacheTimeout < time()) {
                $this->cache[$key] = NULL;
            }
        }
    }

    /**
     * Puts data in the cache if caching is
     * enabled
     *
     * @param string $key  Item key
     * @param mixed  $data Item data
     *
     * @return mixed Returns $data
     */
    private function cache($key, $data) {
        if ($this->useCache) {
            $this->cache[$this->getCacheIndex($key)] =
                array('timestamp' => time(), 'data' => $data);
        }

        return $data;
    }

    // MISC METHODS

    /**
     * Converts an ISO string to UTF-8
     * 
     * @param string $string 
     * 
     * @return string UTF-8 encoded string
     */
    private function encodeToUtf8($string) {
        return mb_convert_encoding($string, "UTF-8", 
            mb_detect_encoding($string, "UTF-8, ISO-8859-1, ISO-8859-15", true));
    }
}