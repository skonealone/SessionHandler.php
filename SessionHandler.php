<?php
/**
 * Author: skonealone <skonealone@gmail.com>
 * Date: 12/29/16
 * Time: 3:33 PM
 * Ref Url: http://php.net/manual/en/class.sessionhandler.php
 */

class SessionHandler implements \SessionHandlerInterface {

    private static $_instance; //The single instance


    /**
     * $_db PDO object holder
     * @var object
     */
    private $_db;


    /**
     * $_expiry Session LIfetime, default 2 Hours 
     * @var integer
     */

    private $_expiry                =  7200;

    /**
     * _table_name Tbale name for storing Session information
     * @var string
     */

    private $_table_name            = "sessions";

    /**
     * _session_id Holder for session_id
     * @var string
     */
    private $_session_id;

    /*
    Get an instance of the SessionHandler
    @return Instance
    */
    public static function getInstance() {
        if(!self::$_instance) { // If no instance then make one
            self::$_instance = new self();
        }
        return self::$_instance;
    }


    /**
     * __construct ,Pass configuration values to __setconfig, register session handlers and starts the sesssion
     * @param array $config array of configuartion params
     */

    public function __construct(array $config)
    {
        session_set_save_handler(
            array(&$this, 'open'),
            array(&$this, 'close'),
            array(&$this, 'read'),
            array(&$this, 'write'),
            array(&$this, 'destroy'),
            array(&$this, 'gc')
        );

        register_shutdown_function('session_write_close');

        $this->_setConfig($config);
        @session_start();
    }

    /**
     * open The open callback works like a constructor in classes and is executed when the session is being opened.
     * It is the first callback function executed when the session is started automatically or manually with session_start().
     * Return value is TRUE for success, FALSE for failure. 
     * @param  string $path Path for saving session file
     * @param  string $name  Session Name
     * @return boolean
     */

    public function open($path, $name)
    {
        return true;
    }

    /**
     * close The close callback works like a destructor in classes and is executed after the session write callback has been called.
     * It is also invoked when session_write_close() is called. Return value should be TRUE for success, FALSE for failure. 
     * @return boolean
     */
    public function close()
    {
        /*calling explicitly method gc(),that will clear all expired sessions*/
        //$this->gc();
        return true;
    }

    /**
     * _setConfig Sets up the configurations values passed in by __contsruct function and creates a session storage MySql table
     * @param Array $config Configs params holder
     */

    private function _setConfig($config)
    {
        $this->_db                  = $config['dbconnector'];
        $this->_expiry              = (isset($config['expiry']))? $config['expiry'] : $this->_expiry ;


        $stmt_create = "CREATE TABLE IF NOT EXISTS {$this->_table_name} (
                        `session_id` varchar(255) NOT NULL,
                        `session_data` longtext,
                        `last_updated` int(11) NOT NULL,
                        PRIMARY KEY (`session_id`),
                        INDEX sess_last_updated( last_updated )
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8";

        $this->_db->exec($stmt_create);


    }

    /**
     * read  The read callback must always return a session encoded (serialized) string,
     * or an empty string if there is no data to read.
     * This callback is called internally by PHP when the session starts or when session_start() is called.
     * Before this callback is invoked PHP will invoke the open callback. 
     * @param  string $id session_id
     */
    public function read($id)
    {
        $stmt = $this->_db->prepare('SELECT session_data FROM {$this->_table_name} WHERE session_id = :id AND last_updated < :expire');

        $expire = time() - (int) $this->_expire;

        $stmt->bindParam(':id',     $id,     \PDO::PARAM_STR);
        $stmt->bindParam(':expire', $expire, \PDO::PARAM_INT);

        if(!$stmt->execute()) {
            throw new \RuntimeException('Could not execute session read query.');
        }

        if($stmt->numCount() > 0) {
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row['session_data'];
        }

        return '';
    }

    /**
     * write The write callback is called when the session needs to be saved and closed.
     * This callback receives the current session ID a serialized version the $_SESSION superglobal.
     * The serialization method used internally by PHP is specified in the session.serialize_handler ini setting.
     * Here we are storing/updating the session data against the session id
     * @param  string $id   session id
     * @param  serilized data $data The serialized session data passed to this callback should be stored against the passed session ID
     * @return boolean
     */
    public function write($id, $data)
    {

        try
        {

            $sql = "INSERT INTO {$this->_table_name} 
                        (session_id, last_updated, session_data)
                        VALUES (:session_id, :last_updated, :session_data)
                        ON DUPLICATE KEY UPDATE session_data = VALUES(session_data), last_updated = VALUES(last_updated)";

            $time   = time();
            $stmt = $this->_db->prepare($sql);
            $stmt->bindParam(':session_id', $id , PDO::PARAM_STR);
            $stmt->bindParam(':last_updated', $time , PDO::PARAM_INT);
            $stmt->bindParam(':session_data', $data , PDO::PARAM_STR);

            if(!$stmt->execute()) {
                throw new \RuntimeException('Could not execute session write query.');
            }
            return true;
        }
        catch(PDOException $e)
        {
            // write this error message in log file!
            echo $e->getMessage();
            return false;
        }
        catch(Exception $e)
        {
            // write this error message in log file!
            echo $e->getMessage();
            return false;
        }
    }

    /**
     * destroy deletes the current session id from the database
     * @param  string $id session_id
     * @return boolean
     */
    public function destroy($id)
    {

        $stmt           = $this->_db->prepare("DELETE FROM {$this->_table_name} WHERE  session_id =  ?");
        return $stmt->execute(array($id));
    }

    /**
     * gc The garbage collector callback is invoked internally by PHP periodically in order to purge old session data.
     * The frequency is controlled by session.gc_probability and session.gc_divisor.
     * The value of lifetime which is passed to this callback can be set in session.gc_maxlifetime.
     * here we are calling this via _close(), to delete all expired sessions from DB
     * Return value should be TRUE for success, FALSE for failure. 
     * @return boolean
     */
    public function gc($max)
    {
        $ses_life       = time() - intval($max);
        $stmt           = $this->_db->prepare("DELETE FROM {$this->_table_name} WHERE  last_updated < ?");
        return $stmt->execute(array($ses_life));
    }

    // Magic method clone is empty to prevent duplication of SessionHandler
    private function __clone() { }

}

/***
 * Example Usage

Example:

    $db = Database::getInstance();
    $db = $db->getConnection();

    $config['dbconnector'] = $db;
    $config['expiry'] = ini_get('session.gc_maxlifetime');

    $s = SessionHandler::getInstance($config);

 * */
