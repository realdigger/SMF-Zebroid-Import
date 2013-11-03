<?php
/**
 * @package SMF Zebroid Import
 * @author digger http://mysmf.ru
 * @copyright 2013
 * @license CC BY-NC-ND http://creativecommons.org/licenses/by-nc-nd/3.0/
 * @version 1.2
 */

if (!defined('SMF'))
    die('Hacking attempt...');

/**
 * Add mod admin area
 * @param array $admin_areas current admin areas
 */
function addZebroidAdminArea(&$admin_areas)
{
    global $txt;
    loadLanguage('ZebroidImport/');

    $admin_areas['config']['areas']['modsettings']['subsections']['zebroid_import'] = array($txt['zebroid_import']);
}

/**
 * Add mod admin action
 * @param array $subActions current admin subactions
 */
function addZebroidAdminAction(&$subActions)
{
    $subActions['zebroid_import'] = 'addZebroidSettings';
}

/**
 * Mod working area
 * @param bool $return_config config vars
 */
function addZebroidSettings($return_config = false)
{
    global $txt, $scripturl, $context, $cachedir, $sourcedir, $cat_tree;
    require_once($sourcedir . '/Subs-Boards.php');

    $config_vars = array();
    $context['page_title'] = $txt['zebroid_import'];
    $context['settings_message'] = '';
    $context['zebroid_message'] = '';

    if (isset($_GET['message']) && !empty($_GET['message'])) $context['zebroid_message'] = handleZebroidImportMessage($_GET['message']);

    if (empty($_GET['file']) && !isset($_GET['import_file'])) {
        if (empty($context['zebroid_message'])) $context['zebroid_message'] = $txt['zebroid_file_load_desc'];

        $context['settings_title'] = $txt['zebroid_title_load'];
        $context['settings_message'] = '
            <input type="file" name="zebroid_file" size="38" class="input_file" />
            <input type="hidden" name="' . $context['session_var'] . '" value="' . $context['session_id'] . '"/>';
        $txt['save'] = $txt['zebroid_button_load'];
        $context['post_url'] = $scripturl . '?action=admin;area=modsettings;sa=zebroid_import" method="post" enctype="multipart/form-data';
    }


    if (isset($_FILES['zebroid_file']) && is_uploaded_file($_FILES['zebroid_file']['tmp_name'])) {
        checkSession();
        if (move_uploaded_file($_FILES['zebroid_file']['tmp_name'], $cachedir . '/' . md5($_FILES['zebroid_file']['name']))) {
            redirectexit('action=admin;area=modsettings;sa=zebroid_import;file=' . md5($_FILES['zebroid_file']['name']) . ';' . $context['session_var'] . '=' . $context['session_id']);
        } else {
            redirectexit('action=admin;area=modsettings;sa=zebroid_import;message=load_error');
        }
    }

    // Test xml file
    if (isset($_GET['file']) && !empty($_GET['file'])) {
        $test = testZebroidFile($_GET['file']);
        if ($test) {
            $context['zebroid_message'] = $txt['zebroid_file_test_success'] . '<br />' .
                $txt['zebroid_users'] . ': ' . $test['users'] . '<br />' .
                $txt['zebroid_boards'] . ': ' . $test['boards'] . '<br />' .
                $txt['zebroid_topics'] . ': ' . $test['topics'] . '<br />' .
                $txt['zebroid_posts'] . ': ' . $test['posts'] . '<br />';

            // Generate categories list for select
            getBoardTree();
            if (empty($cat_tree)) redirectexit('action=admin;area=modsettings;sa=zebroid_import;message=no_categories_error');
            $selectCategory = '<select name="category_id">';
            foreach ($cat_tree as $categoryID => $category) {
                $selectCategory .= '
        <option value="' . $categoryID . '">' . $category['node']['name'] . '</option>';
            }
            $selectCategory .= '/<select>';

            $context['settings_title'] = $txt['zebroid_title_import'];
            $context['zebroid_message'] .= '
                <input type="hidden" name="' . $context['session_var'] . '" value="' . $context['session_id'] . '"/>
                <fieldset>
                    <legend>' . $txt['zebroid_import_options'] . '</legend>' .
                $txt['zebroid_default_category'] . '&nbsp;' . $selectCategory . '<br />' .
                $txt['zebroid_use_post_titles'] . '<input type="checkbox" name="zebroid_use_post_titles" value="checked" checked /><br />' .
                $txt['zebroid_clear_html'] . '<input type="checkbox" name="zebroid_clear_html" value="checked" checked />
                </fieldset>';
            $txt['save'] = $txt['zebroid_button_import'];
            $context['post_url'] = $scripturl . '?action=admin;area=modsettings;sa=zebroid_import;import_file=' . $_GET['file'];
        } else {
            unlink($cachedir . '/' . $_GET['file']);
            redirectexit('action=admin;area=modsettings;sa=zebroid_import;message=test_error');
        }
    }

    // Import xml file
    if (isset($_GET['import_file'])) {
        checkSession();
        $result = importZebroidFile(
            $_GET['import_file'],
            !empty($_POST['category_id']) ? $_POST['category_id'] : '',
            !empty($_POST['zebroid_clear_html']) ? true : false,
            !empty($_POST['zebroid_use_post_titles']) ? true : false
        );
        if ($result) {
            $context['zebroid_message'] = $txt['zebroid_file_import_success'] . '<br />' .
                $txt['zebroid_users'] . ': ' . $result['users'] . '<br />' .
                $txt['zebroid_boards'] . ': ' . $result['boards'] . '<br />' .
                $txt['zebroid_topics'] . ': ' . $result['topics'] . '<br />' .
                $txt['zebroid_posts'] . ': ' . $result['posts'] . '<br />';
            $txt['save'] = $txt['zebroid_button_success'];
            $context['post_url'] = $scripturl . '?action=admin;area=modsettings;sa=zebroid_import';
        } else
            redirectexit('action=admin;area=modsettings;sa=zebroid_import;message=import_error');
    }

    if ($context['zebroid_message']) $context['settings_message'] .= $context['zebroid_message'];
    prepareDBSettingContext($config_vars);
}

/**
 * Test Zebroid xml file and return count of items
 * @param string $file loaded xml file name
 * @return array|bool count of items or false
 */
function testZebroidFile($file = '')
{
    global $cachedir;
    ini_set('max_execution_time', '600');
    set_time_limit(600);

    $xml = simplexml_load_file($cachedir . '/' . $file);
    if (!$xml) return false;

    $result = array();
    $result['users'] = count($xml->users->user);
    $result['boards'] = count($xml->forums->forum);
    $result['topics'] = count($xml->items->topic);
    $result['posts'] = 0;
    foreach ($xml->items->topic as $topic)
        $result['posts'] = $result['posts'] += count($topic->post);

    if ($result) return $result;
    else return false;
}

/**
 * Import data from xml Zebroid xml file
 * @param string $file loaded xml file name
 * @param int $categoryID default category id
 * @param bool $clearHtml remove html tags flag
 * @param bool $zebroid_use_post_titles use post titles
 * @return int|bool count of processed items or false
 */
function importZebroidFile($file = '', $categoryID = 1, $clearHtml = true, $zebroid_use_post_titles = true)
{
    global $cachedir, $txt;
    $result = array();
    ini_set('max_execution_time', '600');
    set_time_limit(600);

    $xml = simplexml_load_file($cachedir . '/' . $file);
    if (empty($xml)) return false;

    // Import users
    foreach ($xml->users->user as $user) {
        $result['users'][(string)$user->name]['id'] = importZebroidUser($user);
        $result['users'][(string)$user->name]['email'] = $user->email;
    }

    // Import boards
    foreach ($xml->forums->forum as $board) {
        if ($board->parent_id == -1)
            $result['boards'][(int)$board->id]['id'] = importZebroidBoard($board, $categoryID);
    }

    // Import subboards
    foreach ($xml->forums->forum as $board) {
        if ($board->parent_id != -1)
            $result['boards'][(int)$board->id]['id'] = importZebroidBoard($board, $categoryID, $result['boards'][(int)$board->parent_id]['id']);
    }

    // Import topics
    $topicID = 0;
    foreach ($xml->items->topic as $topic) {
        $result['topics'][++$topicID] = importZebroidPost($topic, $result['users'][(string)$topic->author], $result['boards'][(int)$topic->parent_id]['id'], 0, $clearHtml);

        // Import posts
        foreach ($topic->post as $post) {
            if (!$zebroid_use_post_titles) $post->title = $txt['response_prefix'] . $topic->title;
            $result['posts'][] = importZebroidPost($post, $result['users'][(string)$post->author], $result['boards'][(int)$topic->parent_id]['id'], $result['topics'][$topicID], $clearHtml);
        }
    }

    // Update stats, clean cache and remove uploaded file
    clean_cache();
    updateStats('message');
    updateStats('topic');
    updateStats('member');

    if ($result) {
        $result['users'] = count($result['users']);
        $result['posts'] = count($result['posts']);
        $result['topics'] = count($result['topics']);
        $result['boards'] = count($result['boards']);
        return $result;
    } else
        return false;
}

/**
 * Import user from Zebroid xml object
 * @param string $user user object
 * @return bool|int new or current user id
 */
function importZebroidUser($user = '')
{
    global $sourcedir, $smcFunc;
    if (!$user) return false;

    require_once($sourcedir . '/Subs-Members.php');
    $username = mb_split('@', $user->email);
    $username = mb_substr($username[0], 0, 24, 'UTF-8');

    // Check if the email or username is in use already.
    $request = $smcFunc['db_query']('', '
		SELECT id_member
		FROM {db_prefix}members
		WHERE email_address = {string:email_address}
			OR member_name = {string:username}
		LIMIT 1',
        array(
            'email_address' => (string)$user->email,
            'username' => convertZebroidUTF8((string)$username),
        )
    );

    if ($smcFunc['db_num_rows']($request) != 0) {
        list ($memberID) = $smcFunc['db_fetch_row']($request);
        $smcFunc['db_free_result']($request);

        updateMemberData($memberID, array('last_login' => (int)$user->lastact,)); // Update last_login date for this user

        return (int)$memberID;
    }

    $regOptions = array(
        'interface' => 'admin',
        'username' => $username,
        'email' => $user->email,
        'password' => '',
        'check_reserved_name' => false,
        'check_password_strength' => false,
        'check_email_ban' => false,
        'send_welcome_email' => false,
        'memberGroup' => 0,
        'require' => 'nothing',
    );
    $regOptions['extra_register_vars'] = array(
        'real_name' => convertZebroidUTF8(mb_eregi_replace('_', ' ', mb_substr($user->name, 0, 199, 'UTF-8'))),
        'date_registered' => (int)$user->regdate,
        'is_activated' => 1,
        'last_login' => (int)$user->lastact,
        'total_time_logged_in' => rand(0, 86400),
        'hide_email' => 1,
    );

    $memberID = registerMember($regOptions);
    unset($regOptions);

    if (is_int($memberID)) return $memberID; else return false;
}

/**
 * Import board from Zebroid xml object
 * @param string $board board object
 * @param int $categoryID default category id
 * @param int $boardParentID parent board id
 * @return bool|int new or current board id
 */
function importZebroidBoard($board = '', $categoryID = 1, $boardParentID = 0)
{
    global $sourcedir, $smcFunc;
    if (!$board) return false;

    require_once($sourcedir . '/Subs-Boards.php');

    // Check if we have this board already
    $request = $smcFunc['db_query']('', '
		SELECT id_board
		FROM {db_prefix}boards
		WHERE name = {string:name}
		LIMIT 1',
        array(
            'name' => convertZebroidUTF8($board->name),
        )
    );

    if ($smcFunc['db_num_rows']($request) != 0) {
        list ($boardID) = $smcFunc['db_fetch_row']($request);
        $smcFunc['db_free_result']($request);

        return (int)$boardID;
    }

    $boardOptions = array(
        'board_name' => convertZebroidUTF8((string)$board->name),
        'move_to' => $boardParentID ? 'child' : 'bottom',
        'target_category' => $categoryID,
        'posts_count' => true,
        'override_theme' => false,
        'board_theme' => 0,
        'access_groups' => array(-1, 0),
        'board_description' => '',
        'profile' => 1,
        'moderators' => '',
        'inherit_permissions' => true,
        'dont_log' => true,
    );

    // Board have a parent?
    if ($boardParentID) $boardOptions['target_board'] = $boardParentID;

    $result = createBoard($boardOptions);
    if ($result) return (int)$result; else return false;
}

/**
 * Import post/topic from Zebroid xml object
 * @param string $post post object
 * @param array $author author's nick and email
 * @param int $boardID board id
 * @param int $topicID topic id (if topicID=0 create new topic)
 * @param bool $clearHtml remove html tags flag
 * @return bool|int new topic id to bool result
 */
function importZebroidPost($post = '', $author = array(), $boardID = 0, $topicID = 0, $clearHtml = true)
{
    global $sourcedir, $smcFunc;
    if (!$post) return false;

    require_once($sourcedir . '/Subs-Post.php');

    if ($clearHtml) $post->text = strip_tags($post->text, '<br>');
    $msgOptions = array(
        'body' => convertZebroidUTF8($post->text),
        'subject' => convertZebroidUTF8($post->title),
        'approved' => 1,
        'smileys_enabled' => true,
    );

    $topicOptions = array(
        'id' => $topicID,
        'board' => $boardID,
        'mark_as_read' => true,
        'is_approved' => true,
    );

    $posterOptions = array(
        'email' => $author['email'],
        'id' => $author['id'],
    );

    $result = createPost($msgOptions, $topicOptions, $posterOptions);

    // Update post time
    $request = $smcFunc['db_query']('', '
		UPDATE {db_prefix}messages
		SET poster_time = {int:poster_time}
		WHERE id_msg = {int:id_msg}
		  AND id_topic = {int:id_topic}',
        array(
            'poster_time' => (int)$post->time,
            'id_msg' => (int)$msgOptions['id'],
            'id_topic' => (int)$topicOptions['id'],
        )
    );

    if ($result) {
        updateMemberData($author['id'], array('posts' => '+'));
        if ($topicOptions['id'] != 0) return $topicOptions['id'];
        else return true;
    } else return false;

}

/**
 * Handle error and info messages
 * @param string $message message var
 * @return bool|string error/info message text or false
 */
function handleZebroidImportMessage($message = '')
{
    global $txt;

    if (!$message) return;

    switch ($message) {
        case 'load_error':
            return $txt['zebroid_file_load_error'];
        case 'test_success':
            return $txt['zebroid_file_test_success'];
        case 'test_error':
            return $txt['zebroid_file_test_error'];
        case 'no_categories_error':
            return $txt['zebroid_no_categories_error'];
        case 'import_success':
            return $txt['zebroid_file_import_success'];
        case 'import_error':
            return $txt['zebroid_file_import_error'];
        default:
            return false;
    }
}

/**
 * Convert text from utf-8 to cp1251 encoding for non utf8 forums
 * @param $text input text
 * @return string converted text, if needed.
 */
function convertZebroidUTF8($text)
{
    global $context;

    if (empty($context['utf8'])) return iconv('UTF-8', 'CP1251//TRANSLIT', $text);
    else return $text;
}