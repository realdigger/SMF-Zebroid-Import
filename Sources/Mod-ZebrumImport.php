<?php
/**
 * @package SMF Zebrum Import
 * @author digger http://mysmf.ru
 * @copyright 2013
 * @license CC BY-NC-ND http://creativecommons.org/licenses/by-nc-nd/3.0/
 * @version 1.0
 */

if (!defined('SMF'))
    die('Hacking attempt...');

/**
 * Add mod admin area
 * @param array $admin_areas array of current areas
 */
function addZebrumAdminArea(&$admin_areas)
{
    global $txt;
    loadLanguage('ZebrumImport/');

    $admin_areas['config']['areas']['modsettings']['subsections']['zebrum_import'] = array($txt['zebrum_import']);
}

/**
 * Add mod admin action
 * @param array $subActions current admin subactions
 */
function addZebrumAdminAction(&$subActions)
{
    $subActions['zebrum_import'] = 'addZebrumSettings';
}

/**
 * Mod settings area
 * @param bool $return_config config vars
 */
function addZebrumSettings($return_config = false)
{
    global $txt, $scripturl, $context, $cachedir, $sourcedir, $cat_tree;
    require_once($sourcedir . '/Subs-Boards.php');

    $context['page_title'] = $txt['zebrum_import'];
    $context['settings_message'] = '';
    $context['zebrum_message'] = '';

    if (isset($_GET['message']) && !empty($_GET['message'])) $context['zebrum_message'] = handleZebrumImportMessage($_GET['message']);

    if (empty($_GET['file']) && !isset($_GET['import_file'])) {
        if (empty($context['zebrum_message'])) $context['zebrum_message'] = $txt['zebrum_file_load_desc'];

        $context['settings_title'] = $txt['zebrum_title_load'];
        $context['settings_message'] = '
            <input type="file" name="zebrum_file" size="38" class="input_file" />
            <input type="hidden" name="' . $context['session_var'] . '" value="' . $context['session_id'] . '"/>';
        $txt['save'] = $txt['zebrum_button_load'];
        $context['post_url'] = $scripturl . '?action=admin;area=modsettings;sa=zebrum_import" method="post" enctype="multipart/form-data';
    }


    if (is_uploaded_file($_FILES['zebrum_file']['tmp_name'])) {
        checkSession();
        if (move_uploaded_file($_FILES['zebrum_file']['tmp_name'], $cachedir . '/' . md5($_FILES['zebrum_file']['name']))) {
            redirectexit('action=admin;area=modsettings;sa=zebrum_import;file=' . md5($_FILES['zebrum_file']['name']) . ';' . $context['session_var'] . '=' . $context['session_id']);
        } else {
            redirectexit('action=admin;area=modsettings;sa=zebrum_import;message=load_error');
        }
    }

    // Test xml file
    if (isset($_GET['file']) && !empty($_GET['file'])) {
        $test = testZebrumFile($_GET['file']);
        if ($test) {
            $context['zebrum_message'] = $txt['zebrum_file_test_success'] . '<br />' .
                $txt['zebrum_users'] . ': ' . $test['users'] . '<br />' .
                $txt['zebrum_posts'] . ': ' . $test['posts'] . '<br />' .
                $txt['zebrum_topics'] . ': ' . $test['topics'] . '<br />' .
                $txt['zebrum_boards'] . ': ' . $test['boards'] . '<br />';

            // Generate categories list for select
            getBoardTree();
            if (empty($cat_tree)) redirectexit('action=admin;area=modsettings;sa=zebrum_import;message=no_categories_error');
            $selectCategory = '<select name="category_id">';
            foreach ($cat_tree as $categoryID => $category) {
                $selectCategory .= '
        <option value="' . $categoryID . '">' . $category['node']['name'] . '</option>';
            }
            $selectCategory .= '/<select>';

            $context['settings_title'] = $txt['zebrum_title_import'];
            $context['zebrum_message'] .= '
                <input type="hidden" name="' . $context['session_var'] . '" value="' . $context['session_id'] . '"/>
                <fieldset>
                    <legend>' . $txt['zebrum_import_options'] . '</legend>' .
                $txt['zebrum_default_category'] . '&nbsp;' . $selectCategory . '<br />' .
                $txt['zebrum_clear_html'] . '<input type="checkbox" name="zebrum_clear_html" value="checked" checked />
                </fieldset>';
            $txt['save'] = $txt['zebrum_button_import'];
            $context['post_url'] = $scripturl . '?action=admin;area=modsettings;sa=zebrum_import;import_file=' . $_GET['file'];
        } else {
            unlink($cachedir . '/' . $_GET['file']);
            redirectexit('action=admin;area=modsettings;sa=zebrum_import;message=test_error');
        }
    }

    // Import xml file
    if (isset($_GET['import_file'])) {
        checkSession();
        $result = importZebrumFile($_GET['import_file'], (!empty($_POST['category_id']) ? $_POST['category_id'] : ''), (!empty($_POST['zebrum_clear_html']) ? true : false));
        if ($result) {
            $context['zebrum_message'] = $txt['zebrum_file_import_success'] . '<br />' .
                $txt['zebrum_users'] . ': ' . $result['users'] . '<br />' .
                $txt['zebrum_posts'] . ': ' . $result['posts'] . '<br />' .
                $txt['zebrum_topics'] . ': ' . $result['topics'] . '<br />' .
                $txt['zebrum_boards'] . ': ' . $result['boards'] . '<br />';
            $txt['save'] = $txt['zebrum_button_success'];
            $context['post_url'] = $scripturl . '?action=admin;area=modsettings;sa=zebrum_import';
        } else
            redirectexit('action=admin;area=modsettings;sa=zebrum_import;message=import_error');
    }

    if ($context['zebrum_message']) $context['settings_message'] .= $context['zebrum_message'];
}

/**
 * Test Zebrum xml file and return count of objects
 * @param string $file loaded xml file name
 * @return array|bool count of objects or false
 */
function testZebrumFile($file = '')
{
    global $cachedir;
    $result = array();
    ini_set('max_execution_time', '600');
    set_time_limit(600);

    $xml = simplexml_load_file($cachedir . '/' . $file);
    if (empty($xml)) return false;

    $result['users'] = count($xml->channel->user);
    $result['posts'] = count($xml->channel->post);
    $result['topics'] = count($xml->channel->topic);
    $result['boards'] = count($xml->channel->forum);

    if ($result) return $result;
    else return false;
}

/**
 * Import data from xml Zebrum xml file
 * @param string $file loaded xml file name
 * @param int $categoryID default category id
 * @param bool $clearHtml remove html tags flag
 * @return int|bool count of imported objects or false
 */
function importZebrumFile($file = '', $categoryID = 1, $clearHtml = true)
{
    global $cachedir;
    $result = array();
    ini_set('max_execution_time', '600');
    set_time_limit(600);

    $xml = simplexml_load_file($cachedir . '/' . $file);
    if (empty($xml)) return false;

    // Import users
    foreach ($xml->channel->user as $user) {
        $result['users'][(string)$user->name]['id'] = importZebrumUser($user);
        $result['users'][(string)$user->name]['email'] = $user->email;
    }

    // Import boards
    foreach ($xml->channel->forum as $board) {
        $result['boards'][(int)$board->id]['id'] = importZebrumBoard($board, $categoryID);
    }

    // Import topics
    foreach ($xml->channel->topic as $topic) {
        $result['topics'][(int)$topic->id]['id'] = importZebrumPost($topic, $result['users'][(string)$topic->author], $result['boards'][(int)$topic->parent_id]['id'], 0, $clearHtml);
        $result['topics'][(int)$topic->id]['board_id'] = $result['boards'][(int)$topic->parent_id]['id'];
    }

    // Import posts
    foreach ($xml->channel->post as $post) {
        $result['posts'][] = importZebrumPost($post, $result['users'][(string)$post->author], $result['topics'][(int)$post->parent_id]['board_id'], $result['topics'][(int)$post->parent_id]['id'], $clearHtml);
    }

    // Update stats & cache
    clean_cache();
    updateStats('message');
    updateStats('topic');
    updateStats('member');

    // Xml file not needed anymore
    unlink($cachedir . '/' . $file);

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
 * Import user from Zebrum xml object
 * @param string $user user object
 * @return bool|int new or current user id
 */
function importZebrumUser($user = '')
{
    global $sourcedir, $smcFunc;
    if (!$user) return false;

    require_once($sourcedir . '/Subs-Members.php');
    $username = mb_split('@', $user->email);
    $username = mb_substr($username[0], 0, 24, 'UTF-8');

    // Check if the email or username is in use.
    $request = $smcFunc['db_query']('', '
		SELECT id_member
		FROM {db_prefix}members
		WHERE email_address = {string:email_address}
			OR member_name = {string:username}
		LIMIT 1',
        array(
            'email_address' => (string)$user->email,
            'username' => (string)$username,
        )
    );

    if ($smcFunc['db_num_rows']($request) != 0) {
        list ($memberID) = $smcFunc['db_fetch_row']($request);
        $smcFunc['db_free_result']($request);

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
    );
    $regOptions['extra_register_vars'] = array(
        'real_name' => mb_eregi_replace('_', ' ', mb_substr($user->name, 0, 199, 'UTF-8')),
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
 * Import board from Zebrum xml object
 * @param string $board board object
 * @param int $categoryID default category id
 * @return bool|int new or current board id
 */
function importZebrumBoard($board = '', $categoryID = 1)
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
            'name' => (string)$board->name,
        )
    );

    if ($smcFunc['db_num_rows']($request) != 0) {
        list ($boardID) = $smcFunc['db_fetch_row']($request);
        $smcFunc['db_free_result']($request);

        return (int)$boardID;
    }

    $boardOptions = array(
        'board_name' => $board->name,
        'move_to' => 'bottom',
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

    $result = createBoard($boardOptions);
    if ($result) return (int)$result; else return false;
}

/**
 * Import post from Zebrum xml object
 * @param string $post post object
 * @param array $author author's nick & email
 * @param int $boardID board id
 * @param int $topicID topic id (if topicID=0 create new topic)
 * @return bool|int new topic id
 */
function importZebrumPost($post = '', $author = array(), $boardID = 0, $topicID = 0, $clearHtml = true)
{
    global $sourcedir, $smcFunc;
    if (!$post) return false;

    require_once($sourcedir . '/Subs-Post.php');

    if ($clearHtml) $post->text = strip_tags($post->text, '<br>');
    $msgOptions = array(
        'body' => $smcFunc['db_escape_string']($post->text),
        'subject' => $smcFunc['db_escape_string']($post->title),
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
function handleZebrumImportMessage($message = '')
{
    global $txt, $context;

    if (!$message) return;

    switch ($message) {
        case 'load_error':
            return $txt['zebrum_file_load_error'];
        case 'test_success':
            return $txt['zebrum_file_test_success'];
        case 'test_error':
            return $txt['zebrum_file_test_error'];
        case 'no_categories_error':
            return $txt['zebrum_no_categories_error'];
        case 'import_success':
            return $txt['zebrum_file_import_success'];
        case 'import_error':
            return $txt['zebrum_file_import_error'];
        default:
            return false;
    }
}