<?php
/**
 * @package SMF Zebroid Import
 * @author digger http://mysmf.ru
 * @copyright 2013
 * @license CC BY-NC-ND http://creativecommons.org/licenses/by-nc-nd/3.0/
 * @version 1.0
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
 * Mod settings area
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
                $txt['zebroid_posts'] . ': ' . $test['posts'] . '<br />' .
                $txt['zebroid_topics'] . ': ' . $test['topics'] . '<br />' .
                $txt['zebroid_boards'] . ': ' . $test['boards'] . '<br />';

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
        $result = importZebroidFile($_GET['import_file'], (!empty($_POST['category_id']) ? $_POST['category_id'] : ''), (!empty($_POST['zebroid_clear_html']) ? true : false));
        if ($result) {
            $context['zebroid_message'] = $txt['zebroid_file_import_success'] . '<br />' .
                $txt['zebroid_users'] . ': ' . $result['users'] . '<br />' .
                $txt['zebroid_posts'] . ': ' . $result['posts'] . '<br />' .
                $txt['zebroid_topics'] . ': ' . $result['topics'] . '<br />' .
                $txt['zebroid_boards'] . ': ' . $result['boards'] . '<br />';
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
 * Import data from xml Zebroid xml file
 * @param string $file loaded xml file name
 * @param int $categoryID default category id
 * @param bool $clearHtml remove html tags flag
 * @return int|bool count of processed items or false
 */
function importZebroidFile($file = '', $categoryID = 1, $clearHtml = true)
{
    global $cachedir;
    $result = array();
    ini_set('max_execution_time', '600');
    set_time_limit(600);

    $xml = simplexml_load_file($cachedir . '/' . $file);
    if (empty($xml)) return false;

    // Import users
    foreach ($xml->channel->user as $user) {
        $result['users'][(string)$user->name]['id'] = importZebroidUser($user);
        $result['users'][(string)$user->name]['email'] = $user->email;
    }

    // Import boards
    foreach ($xml->channel->forum as $board) {
        $result['boards'][(int)$board->id]['id'] = importZebroidBoard($board, $categoryID);
    }

    // Import topics
    foreach ($xml->channel->topic as $topic) {
        $result['topics'][(int)$topic->id]['id'] = importZebroidPost($topic, $result['users'][(string)$topic->author], $result['boards'][(int)$topic->parent_id]['id'], 0, $clearHtml);
        $result['topics'][(int)$topic->id]['board_id'] = $result['boards'][(int)$topic->parent_id]['id'];
    }

    // Import posts
    foreach ($xml->channel->post as $post) {
        $result['posts'][] = importZebroidPost($post, $result['users'][(string)$post->author], $result['topics'][(int)$post->parent_id]['board_id'], $result['topics'][(int)$post->parent_id]['id'], $clearHtml);
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
        'require' => 'nothing',
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
 * Import board from Zebroid xml object
 * @param string $board board object
 * @param int $categoryID default category id
 * @return bool|int new or current board id
 */
function importZebroidBoard($board = '', $categoryID = 1)
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
 * Import post from Zebroid xml object
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
function handleZebroidImportMessage($message = '')
{
    global $txt, $context;

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