<?php

namespace App;

use App\Model\Meta;
use App\Model\Account;
use App\Model\Message;
use App\Exceptions\ClientException;
use App\Exceptions\ServerException;
use App\Exceptions\NotFoundException;
use App\Actions\MarkRead as MarkReadAction;

// Autoload application and vendor libraries
require __DIR__.'/../vendor/autoload.php';

// Turn on error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Tell PHP that we'll be outputting UTF-8 to the browser
mb_http_output('UTF-8');
// Tell PHP that we're using UTF-8 strings until the end
// of the script
mb_internal_encoding('UTF-8');

// Set up constants
define('GET', 'GET');
define('POST', 'POST');
define('INBOX', 'inbox');
define('THREAD', 'thread');
define('VIEWEXT', '.phtml');
define('STARRED', 'starred');
define('MAILBOX', 'mailbox');
define('BASEDIR', __DIR__.'/..');
define('ERR_TASK_ROLLBACK', 1010);
define('DIR', DIRECTORY_SEPARATOR);
define('VIEWDIR', BASEDIR.'/views');
define('DATE_DATABASE', 'Y-m-d h:i:s');

// Helper to load config files
function getConfig($file) {
    return include BASEDIR.'/config/'.$file.'.php';
}

// Helper function to get an item from an array
function get(array $list, string $key, $default = null) {
    return $list[$key] ?? $default;
}

// Load environment config
$config = parse_ini_file(BASEDIR.'/.env');
// Set the timezone now
date_default_timezone_set($config['APP_TIMEZONE']);

// Set up the database connection
Model::initDb(
    sprintf(
        'mysql:host=%s:%s;dbname=%s;charset=%s',
        $config['DB_HOST'],
        $config['DB_PORT'],
        $config['DB_DATABASE'],
        $config['DB_CHARSET']),
    $config['DB_USERNAME'],
    $config['DB_PASSWORD']);

// Pass the routes into the URL service
Url::setBase($config['APP_URL']);
// Save the timezone to the view library
View::setTimezone($config['APP_TIMEZONE']);

// Get the email address from the cookie (if set) and
// fetch the account. Otherwise, load the first active
// account in the database.
$email = isset($_COOKIE['email'])
    ? $_COOKIE['email']
    : null;
$account = $email
    ? (new Account)->getByEmail($email)
    : (new Account)->getFirstActive();

if (! $account) {
    throw new \Exception('No account found!');
}

$router = new Router;

// Helper function to render a mailbox
$renderMailbox = function ($id, $page = 1, $limit = 25) use ($account) {
    // Set up libraries
    $view = new View;
    $meta = Meta::getAll();
    $colors = getConfig('colors');
    $select = Url::getParam('select');
    $folders = new Folders($account, $colors);
    $messages = new Messages($account, $folders);
    $folderId = INBOX === $id || STARRED === $id
        ? $folders->getInboxId()
        : $id;
    $folder = $folders->getById($folderId);

    if (! $folder) {
        throw new ClientException("Folder #$id not found!");
    }

    // Get the message data
    list($flagged, $unflagged, $paging, $totals) = $messages->getThreads(
        $folderId,
        $page,
        $limit, [
            Message::SPLIT_FLAGGED => INBOX === $id,
            Message::ONLY_FLAGGED => STARRED === $id
        ]);

    session_start();
    header('Content-Type: text/html');
    header('Cache-Control: private, max-age=0, no-cache, no-store');

    // Render the inbox
    $view->render('mailbox', [
        'urlId' => $id,
        'view' => $view,
        'page' => $page,
        'meta' => $meta,
        'paging' => $paging,
        'select' => $select,
        'totals' => $totals,
        'flagged' => $flagged,
        'folders' => $folders,
        'folderId' => $folderId,
        'unflagged' => $unflagged,
        'showPaging' => INBOX !== $id,
        'mainHeading' => INBOX === $id
            ? 'Everything else'
            : $folder->name,
        'alert' => Session::get('alert')
    ]);
};

// Inbox
$router->get('/', function () use ($renderMailbox) {
    $renderMailbox(INBOX);
});

// Folder
$router->get('/folder/(\d+)', function ($id) use ($renderMailbox) {
    $renderMailbox($id);
});

// Starred messages in the inbox
$router->get('/starred/(\d+)', function ($page) use ($renderMailbox) {
    $renderMailbox(STARRED, $page);
});

// Folder page
$router->get('/folder/(\d+)/(\d+)', function ($id, $page) use ($renderMailbox) {
    $renderMailbox($id, $page);
});

// Update messages
$router->post('/update', function () use ($account) {
    $colors = getConfig('colors');
    $folders = new Folders($account, $colors);
    $actions = new Actions($folders, $_POST + $_GET);

    session_start();
    $actions->run();
});

// Undo an action or collection of actions
$router->post('/undo/(\d+)', function ($batchId) {
    session_start();
    (new Rollback)->run($batchId);
});

// Get the star HTML for a message
$router->get('/star/(\w+)/(\d+)/(\w+).html', function ($type, $id, $state) {
    header('Content-Type: text/html');
    header('Cache-Control: max-age=86400'); // one day

    (new View)->render('/star', [
        'id' => $id,
        'type' => $type,
        'flagged' => 'on' === $state
    ]);
});

// Set star flag on a message
$router->post('/star', function () use ($account) {
    $folders = new Folders($account, []);
    $type = Url::postParam('type', MAILBOX);
    $actions = new Actions($folders, $_POST + $_GET);

    $actions->runAction(
        'on' === Url::postParam('state', 'on')
            ? Actions::FLAG
            : Actions::UNFLAG,
        [
            Url::postParam('id', 0)
        ], [], [
            Message::ALL_SIBLINGS => MAILBOX === $type
        ]);

    (new View)->render('/star', [
        'id' => Url::postParam('id', 0),
        'type' => Url::postParam('type'),
        'flagged' => 'on' === Url::postParam('state', 'on')
    ]);
});

// Message thread
$router->get('/thread/(\d+)/(\d+)', function ($folderId, $threadId) use ($account) {
    // Set up libraries
    $view = new View;
    $colors = getConfig('colors');
    $select = Url::getParam('select');
    $folders = new Folders($account, $colors);
    // Load the thread object, this will throw an exception if
    // the thread is not found. Do this BEFORE we mark as read
    // so that we know which message to take the user to.
    $thread = new Thread($account, $folders, $threadId);

    // Mark this thread as read
    (new MarkReadAction)->run([$threadId], $folders);

    // Re-compute the un-read totals, as this may be changed now
    // Render the message thread
    session_start();
    $view->render('thread', [
        'view' => $view,
        'thread' => $thread,
        'folders' => $folders,
        'folderId' => $folderId,
        'meta' => Meta::getAll(),
        'alert' => Session::get('alert'),
        'totals' => (new Message)->getSizeCounts($account->id)
    ]);
});

// Original message
$router->get('/original/(\d+)', function ($messageId) use ($account) {
    header('Content-Type: text/plain');
    // Load the message, this will throw an exception if not found
    $message = (new Message)->getById($messageId, true);
    (new View)->clean($message->getOriginal());
});

// Handle 404s
$router->set404(function () {
    throw new NotFoundException;
});

// Process route
try {
    $router->run();
}
catch (NotFoundException $e) {
    header('HTTP/1.1 404 Not Found');
    echo '<h1>404 Page Not Found</h1>';
}
catch (ClientException $e) {
    header('HTTP/1.1 400 Bad Request');
    echo '<h1>400 Bad Request</h1>';
    echo '<p>'.$e->getMessage().'</p>';
}
catch (ServerException $e) {
    header('HTTP/1.1 500 Server Error');
    echo '<h1>500 Server Error</h1>';
    echo '<p>'.$e->getMessage().' [#'.$e->getCode().']</p>';
}
catch (Exception $e) {
    if (true !== $config['APP_DEBUG']) {
        header('HTTP/1.1 500 Server Error');
        echo '<h1>500 Server Error</h1>';
        echo '<p>'.$e->getMessage().' [#'.$e->getCode().']</p>';
    } else {
        throw $e;
    }
}
