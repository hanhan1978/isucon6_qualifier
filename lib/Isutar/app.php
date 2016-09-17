<?php
namespace Isutar\Web;

use Slim\Http\Request;
use Slim\Http\Response;
use PDO;
use PDOWrapper;

/*
ini_set('log_errors','On');
ini_set('error_log','/tmp/php_error_isutar.log');
*/

$container = new class extends \Slim\Container {
    public $dbh;
    public $db_isuda;
    public function __construct() {
        parent::__construct();

        $this->dbh = new PDOWrapper(new PDO(
            $_ENV['ISUTAR_DSN'],
            $_ENV['ISUTAR_DB_USER'] ?? 'isucon',
            $_ENV['ISUTAR_DB_PASSWORD'] ?? 'isucon',
            [ PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4" ]
        ));

        $this->db_isuda = new PDOWrapper(new PDO(
            $_ENV['ISUDA_DSN'],
            'isucon',
            'isucon',
            [ PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4" ]
        ));
 

    }
};
$app = new \Slim\App($container);

$app->get('/initialize', function (Request $req, Response $c) {
    $this->dbh->query('TRUNCATE star');
    return render_json($c, [
        'result' => 'ok',
    ]);
});

$app->get('/stars', function (Request $req, Response $c) {
    $stars = $this->dbh->select_all(
        'SELECT * FROM star WHERE keyword = ?'
    , $req->getParams()['keyword']);

    return render_json($c, [
        'stars' => $stars,
    ]);
});

$app->post('/stars', function (Request $req, Response $c) {
    $keyword = $req->getParams()['keyword'];

    $data = $this->db_isuda->select_all(
        'SELECT id FROM entry WHERE keyword = ?'
        , $keyword
    );
    if (empty($data)) {
        return $c->withStatus(404);
    }

    $this->dbh->query(
        'INSERT INTO star (keyword, user_name, created_at) VALUES (?, ?, NOW())',
        $keyword,
        $req->getParams()['user']
    );
    return render_json($c, [
        'result' => 'ok',
    ]);
});

$app->run();
