<?php

namespace AE97\Panel;

use \AE97\Validate,
    \PDOException,
    \PDO;

class Factoids {

    private $database;

    public function __construct($database = null) {
        if ($database == null) {
            $_DATABASE = Config::getGlobal('database');
            $this->database = new PDO("mysql:host=" . $_DATABASE['host'] . ";dbname=" . $_DATABASE['factoiddb'], $_DATABASE['user'], $_DATABASE['pass'], array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8'"));
            $this->database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } else {
            $this->database = $database;
        }
    }

    public function editFactoid($id, $content) {
        Validate::param($id, 'id')->isNum();
        Validate::param($content, 'content')->notNull();
        try {
            $statement = $this->database->prepare("UPDATE factoids SET content = ? WHERE id = ?");
            $statement->execute(array($content, $id));
            return $statement->rowCount() > 0;
        } catch (PDOException $ex) {
            Utilities::logError($ex);
            return false;
        }
    }

    public function deleteFactoid($id) {
        Validate::param($id, 'id')->isNum();
        try {
            $statement = $this->database->prepare("DELETE FROM factoids WHERE id = ?");
            $statement->execute(array($id));
            return $statement->rowCount() > 0;
        } catch (PDOException $ex) {
            Utilities::logError($ex);
            return false;
        }
    }

    public function createFactoid($table, $key, $content) {
        Validate::param($table)->notNull();
        Validate::param($key)->notNull();
        Validate::param($content)->notNull();
        try {
            $statement = $this->database->prepare("INSERT INTO factoids (`name`,`game`,`content`) VALUES (?,?,?)");
            $statement->execute(array($key, $content, $table));
            return $statement->rowCount() > 0;
        } catch (PDOException $ex) {
            Utilities::logError($ex);
            return false;
        }
    }

    public function getDatabase($table = 'global') {
        try {
            $gameliststatement = $this->database->prepare("SELECT id,idname,displayname FROM games");
            $gameliststatement->execute();
            $gamelist = $gameliststatement->fetchAll();
            $statement = $this->database->prepare("SELECT factoids.id,factoids.name, factoids.content, games.displayname "
                    . "FROM factoids "
                    . "INNER JOIN games ON (factoids.game = games.id) "
                    . "WHERE games.idname = ?");
            $statement->execute(array(0 => $table));
            $factoids = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $ex) {
            Utilities::logError($ex);
            return array();
        }

        //TODO: Rewrite this logic, it can be made more effective
        $firstCounter = 0;
        foreach ($gamelist as $gameitem):
            $compiledGamelist[$firstCounter] = array('idname' => $gameitem['idname'], 'displayname' => $gameitem['displayname']);
            if ($compiledGamelist[$firstCounter]['idname'] === $table) {
                $gameAskedFor = $compiledGamelist[$firstCounter];
            }
            $firstCounter++;
        endforeach;
        $compiledFactoidlist = array();
        foreach ($factoids as $f):
            $compiledFactoidlist[] = array('id' => $f['id'], 'name' => $f['name'], 'content' => $f['content'], 'game' => $table == null ? $f['game'] : $table);
        endforeach;

        $collection = array();
        $collection['gamerequest'] = isset($gameAskedFor) ? $gameAskedFor : 'Global';
        $collection['games'] = isset($compiledGamelist) ? $compiledGamelist : array();
        $collection['factoids'] = $compiledFactoidlist;
        return $collection;
    }

    public function getFactoid($id) {
        try {
            $statement = $this->database->prepare("SELECT factoids.id AS id,name,content,games.displayname AS game "
                    . "FROM factoids "
                    . "INNER JOIN games ON factoids.game = games.id "
                    . "WHERE factoids.id=? "
                    . "LIMIT 1");
            $statement->execute(array($id));
            return $statement->fetch();
        } catch (PDOException $ex) {
            Utilities::logError($ex);
            return null;
        }
    }

    public function createDatabase($idName, $displayName = null) {
        Validate::param($idName)->notNull();
        if ($displayName == null) {
            $displayName = $idName;
        }

        try {
            $statement = $this->database->prepare("INSERT INTO games (`idname`,`displayname`) VALUES (?,?)");
            $statement->execute(array($idName, $displayName));
            return true;
        } catch (PDOException $ex) {
            Utilities::logError($ex);
            return false;
        }
    }

    public function renameFactoid($id, $newName) {
        Validate::param($id, 'id')->isNum();
        Validate::param($newName, 'name')->notNull();
        try {
            $statement = $this->database->prepare("UPDATE factoids SET name = ? WHERE id = ?");
            $statement->execute(array($newName, $id));
            return $statement->rowCount() > 0;
        } catch (PDOException $ex) {
            Utilities::logError($ex);
            return false;
        }
    }

    public function getGame($id = null) {
        try {
            if ($id != null) {
                Validate::param($id)->isNum();

                $statement = $this->database->prepare("SELECT idname AS id,displayname AS name FROM games INNER JOIN factoids ON factoids.game = games.id WHERE factoids.id = ?");
                return $statement->execute(array($id));
            } else {
                $statement = $this->database->prepare("SELECT idname AS id,displayname AS name FROM games");
                return $statement->execute();
            }
        } catch (PDOException $ex) {
            Utilities::logError($ex);
            return array();
        }
    }

    public function getDatabaseNames() {
        try {
            $statement = $this->database->prepare("SELECT idname, displayname FROM games");
            $statement->execute();
            return $statement->fetchAll();
        } catch (PDOException $ex) {
            Utilities::logError($ex);
            return array();
        }
    }

}