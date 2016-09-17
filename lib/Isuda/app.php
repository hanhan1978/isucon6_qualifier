<?php
namespace Isuda\Web;

use Slim\Http\Request;
use Slim\Http\Response;
use PDO;
use PDOWrapper;

$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);

//require_once 'isucon_lib/profiler/main.php';
//ini_set('log_errors','On');
//ini_set('error_log','/tmp/error_isuda.log');

function config($key) {
    static $conf;
    if ($conf === null) {
        $conf = [
            'dsn'           => $_ENV['ISUDA_DSN']         ?? 'dbi:mysql:db=isuda',
            'db_user'       => $_ENV['ISUDA_DB_USER']     ?? 'isucon',
            'db_password'   => $_ENV['ISUDA_DB_PASSWORD'] ?? 'isucon',
            'isutar_origin' => $_ENV['ISUTAR_ORIGIN']     ?? 'http://localhost:5001',
            'isupam_origin' => $_ENV['ISUPAM_ORIGIN']     ?? 'http://localhost:5050',
        ];
    }

    if (empty($conf[$key])) {
        exit("config value of $key undefined");
    }
    return $conf[$key];
}

$container = new class extends \Slim\Container {
    public $dbh;
    public $db_star;

    public function __construct() {
        parent::__construct();

        $this->dbh = new PDOWrapper(new PDO(
            $_ENV['ISUDA_DSN'],
            $_ENV['ISUDA_DB_USER'] ?? 'isucon',
            $_ENV['ISUDA_DB_PASSWORD'] ?? 'isucon',
            [ PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4" ]
        ));

        $this->db_star = new PDOWrapper(new PDO(
            $_ENV['ISUTAR_DSN'],
            'isucon',
            'isucon',
            [ PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4" ]
        ));

    }

    public function htmlify($content, $keyword) {
        if (!isset($content)) {
            return '';
        }
        $kw2sha = [];
        
        global $redis;
        $added = $redis->sMembers('hky_added');
        foreach($added as $k){
            $redis->zAdd('zkey_'.urlencode($keyword), mb_strlen($k), $k);
        }
        $keywords = $redis->zRevRange('zkey_'.urlencode($keyword), 0, -1);

        // NOTE: avoid pcre limitation "regular expression is too large at offset"
        $limit = 500;
        for ($i = 0; !empty($kwtmp = array_slice($keywords, $limit * $i, $limit)); $i++) {
            $re = implode('|', array_map(function ($keyword) { return quotemeta($keyword); }, $kwtmp));
            preg_replace_callback("/($re)/", function ($m) use (&$kw2sha) {
                $kw = $m[1];
                return $kw2sha[$kw] = "isuda_" . sha1($kw);
            }, $content);
        }
        $content = strtr($content, $kw2sha);
        $content = html_escape($content);
        foreach ($kw2sha as $kw => $hash) {
            $url = '/keyword/' . rawurlencode($kw);
            $link = sprintf('<a href="%s">%s</a>', $url, html_escape($kw));

            $content = preg_replace("/{$hash}/", $link, $content);
        }
        return nl2br($content, true);
    }

    public function load_stars($keyword) {
        $keyword = urldecode($keyword);
        $db_data = $this->db_star->select_all(
            'SELECT user_name FROM star WHERE keyword = ?'
            , $keyword
        );
        return $db_data;
    }
};
$container['view'] = function ($container) {
    $view = new \Slim\Views\Twig($_ENV['PHP_TEMPLATE_PATH'], []);
    $view->addExtension(new \Slim\Views\TwigExtension(
        $container['router'],
        $container['request']->getUri()
    ));
    return $view;
};
$container['stash'] = new \Pimple\Container;
$app = new \Slim\App($container);

$mw = [];
// compatible filter 'set_name'
$mw['set_name'] = function ($req, $c, $next) {
    $user_id = $_SESSION['user_id'] ?? null;
    if (isset($user_id)) {
        $this->get('stash')['user_id'] = $user_id;
        $this->get('stash')['user_name'] = $this->dbh->select_one(
            'SELECT name FROM user WHERE id = ?'
            , $user_id);
        if (!isset($this->get('stash')['user_name'])) {
            return $c->withStatus(403);
        }
    }
    return $next($req, $c);
};

$mw['authenticate'] = function ($req, $c, $next) {
    if (!isset($this->get('stash')['user_id'])) {
        return $c->withStatus(403);
    }
    return $next($req, $c);
};

$app->get('/initialize', function (Request $req, Response $c) {
    $this->dbh->query(
        'DELETE FROM entry WHERE id > 7101'
    );
    exec('/home/isucon/webapp/php/load_redisdata.sh 2>&1');

    $origin = config('isutar_origin');
    $url = "$origin/initialize";
    file_get_contents($url);
    return render_json($c, [
        'result' => 'ok',
    ]);
});

$app->get('/', function (Request $req, Response $c) {
    $PER_PAGE = 10;
    $page = $req->getQueryParams()['page'] ?? 1;

    $offset = $PER_PAGE * ($page-1);
    $entries = $this->dbh->select_all(
        'SELECT description, keyword FROM entry '.
        'ORDER BY updated_at DESC '.
        "LIMIT $PER_PAGE ".
        "OFFSET $offset"
    );
    foreach ($entries as &$entry) {
        $entry['html']  = $this->htmlify($entry['description'], $entry['keyword']);
        $entry['stars'] = $this->load_stars($entry['keyword']);
    }
    unset($entry);

    global $redis;

    $total_entries = $redis->get('entry_count');
    $last_page = ceil($total_entries / $PER_PAGE);
    $pages = range(max(1, $page-5), min($last_page, $page+5));

    $this->view->render($c, 'index.twig', [ 'entries' => $entries, 'page' => $page, 'last_page' => $last_page, 'pages' => $pages, 'stash' => $this->get('stash') ]);
})->add($mw['set_name'])->setName('/');

$app->get('/robots.txt', function (Request $req, Response $c) {
    return $c->withStatus(404);
});

$app->post('/keyword', function (Request $req, Response $c) {
    $keyword = $req->getParsedBody()['keyword'];
    if (!isset($keyword)) {
        return $c->withStatus(400)->write("'keyword' required");
    }
    $user_id = $this->get('stash')['user_id'];
    $description = $req->getParsedBody()['description'];

    if (is_spam_contents($description) || is_spam_contents($keyword)) {
        return $c->withStatus(400)->write('SPAM!');
    }
    $this->dbh->query(
        'INSERT INTO entry (author_id, keyword, description, created_at, updated_at)'
        .' VALUES (?, ?, ?, NOW(), NOW())'
        .' ON DUPLICATE KEY UPDATE'
        .' author_id = ?, keyword = ?, description = ?, updated_at = NOW()'
    , $user_id, $keyword, $description, $user_id, $keyword, $description);

    global $redis;
    $redis->zAdd('zkeywords', mb_strlen($keyword), $keyword);
    $redis->sAdd('hky_added', $keyword);
    $redis->incr('entry_count');

    return $c->withRedirect('/');
})->add($mw['authenticate'])->add($mw['set_name']);

$app->get('/register', function (Request $req, Response $c) {
    return $this->view->render($c, 'authenticate.twig', [
        'action' => 'register', 'stash' => $this->get('stash')
    ]);
})->add($mw['set_name'])->setName('/register');

$app->post('/register', function (Request $req, Response $c) {
    $name = $req->getParsedBody()['name'];
    $pw   = $req->getParsedBody()['password'];
    if ($name === '' || $pw === '') {
        return $c->withStatus(400);
    }
    $user_id = register($this->dbh, $name, $pw);

    $_SESSION['user_id'] = $user_id;
    return $c->withRedirect('/');
});

function register($dbh, $user, $pass) {
    $salt = random_string('....................');
    $dbh->query(
        'INSERT INTO user (name, salt, password, created_at)'
        .' VALUES (?, ?, ?, NOW())'
    , $user, $salt, sha1($salt . $pass));

    return $dbh->last_insert_id();
}

$app->get('/login', function (Request $req, Response $c) {
    return $this->view->render($c, 'authenticate.twig', [
        'action' => 'login', 'stash' => $this->get('stash')
    ]);
})->add($mw['set_name'])->setName('/login');

$app->post('/login', function (Request $req, Response $c) {
    $name = $req->getParsedBody()['name'];
    $row = $this->dbh->select_row(
        'SELECT id, salt, password FROM user'
        . ' WHERE name = ?'
    , $name);
    if (!$row || $row['password'] !== sha1($row['salt'].$req->getParsedBody()['password'])) {
        return $c->withStatus(403);
    }

    $_SESSION['user_id'] = $row['id'];
    return $c->withRedirect('/');
});

$app->get('/logout', function (Request $req, Response $c) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time()-60, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    return $c->withRedirect('/');
});

$app->get('/keyword/{keyword}', function (Request $req, Response $c) {
    $keyword = $req->getAttribute('keyword');
    if ($keyword === null) return $c->withStatus(400);

    $entry = $this->dbh->select_row(
        'SELECT description, keyword FROM entry'
        .' WHERE keyword = ?'
    , $keyword);
    if (empty($entry)) return $c->withStatus(404);
    $entry['html'] = $this->htmlify($entry['description'], $entry['keyword']);
    $entry['stars'] = $this->load_stars($entry['keyword']);

    return $this->view->render($c, 'keyword.twig', [
        'entry' => $entry, 'stash' => $this->get('stash')
    ]);
})->add($mw['set_name']);

$app->post('/keyword/{keyword}', function (Request $req, Response $c) {
    $keyword = $req->getAttribute('keyword');
    if ($keyword === null) return $c->withStatus(400);
    $delete = $req->getParsedBody()['delete'];
    if ($delete === null) return $c->withStatus(400);

    $entry = $this->dbh->select_row(
        'SELECT id FROM entry'
        .' WHERE keyword = ?'
    , $keyword);
    if (empty($entry)) return $c->withStatus(404);

    $this->dbh->query('DELETE FROM entry WHERE keyword = ?', $keyword);
    global $redis;
    $redis->zDelete('zkeywords', $keyword);
    $redis->sRem('hky_added', $keyword);
    $redis->decr('entry_count');
    return $c->withRedirect('/');
})->add($mw['authenticate'])->add($mw['set_name']);

function is_spam_contents($content) {
    $ua = new \GuzzleHttp\Client;
    $res = $ua->request('POST', config('isupam_origin'), [
        'form_params' => ['content' => $content]
    ])->getBody();
    $data = json_decode($res, true);
    return !$data['valid'];
}

$app->run();
