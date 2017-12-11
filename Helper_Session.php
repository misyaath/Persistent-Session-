<?php

namespace Persistent_Sessions;

use PDO;

trait Helper_Session
{

    /**
     * @var \PDO MySQL database connection
     */
    protected $db;

    /**
     * @var bool Determines whether to use transactions
     */
    protected $useTransactions;

    /**
     * @var int Unix timestamp indicating when session should expire
     */
    protected $expiry;

    /**
     * @var string Default table where session data is stored
     */
    protected $table_sess = 'sessions';

    /**
     * @var string Default column for session ID
     */
    protected $col_sid = 'sid';

    /**
     * @var string Default column for expiry timestamp
     */
    protected $col_expiry = 'expiry';

    /**
     * @var string Default column for session data
     */
    protected $col_data = 'data';


    /**
     * @var string Default host for database
     */
    protected $host = '127.0.0.1:8080';

    /**
     * @var string Default db_name for database
     */
    protected $db_name = 'db_name';
    /**
     * @var string usernane for database
     */
    protected $db_username = "username";
    /**
     * @var string Default password for database
     */
    protected $db_password = 'password';


    public function get_db_connection()
    {
        try {

            $this->db = new PDO( "mysql:host={$this->host};dbname={$this->db_name}", $this->db_username, $this->db_password, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ) );
        } catch ( Exception $e ) {
            throw new Exception( $e->getMessage(), $e->getCode() );
        }
    }

    /**
     * Executes an application-level lock on the database
     *
     * @param $session_id
     * @return \PDOStatement Prepared statement to release the lock
     */
    protected function getLock( $session_id )
    {
        $stmt = $this->db->prepare( 'SELECT GET_LOCK(:key, 50)' );
        $stmt->bindValue( ':key', $session_id );
        $stmt->execute();

        $releaseStmt = $this->db->prepare( 'DO RELEASE_LOCK(:key)' );
        $releaseStmt->bindValue( ':key', $session_id );

        return $releaseStmt;
    }

    /**
     * Registers new session ID in database when using transactions
     *
     * Exclusive-reading of non-existent rows does not block, so we need
     * to insert a row until the transaction is committed.
     *
     * @param \PDOStatement $selectStmt
     * @return string
     */
    protected function initializeRecord( \PDOStatement $selectStmt )
    {
        try {
            $sql = "INSERT INTO $this->table_sess ($this->col_sid, $this->col_expiry, $this->col_data)
                VALUES (:sid, :expiry, :data)";
            $insertStmt = $this->db->prepare( $sql );
            $insertStmt->bindParam( ':sid', $session_id );
            $insertStmt->bindParam( ':expiry', $this->expiry, \PDO::PARAM_INT );
            $insertStmt->bindValue( ':data', '' );
            $insertStmt->execute();
            return '';
        } catch ( \PDOException $e ) {
            // Catch duplicate key error if the session has already been created.
            if ( 0 === strpos( $e->getCode(), '23' ) ) {
                // Retrieve existing session data written by the current connection.
                $selectStmt->execute();
                $results = $selectStmt->fetch( \PDO::FETCH_ASSOC );
                if ( $results ) {
                    return $results[ $this->col_data ];
                }
                return '';
            }
            // Roll back transaction if the error was caused by something else.
            if ( $this->db->inTransaction() ) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    protected function getSessionData( $sessionID )
    {
        $sql = "SELECT $this->col_data
            FROM $this->table_sess WHERE $this->col_sid = :sid";
        $statement = $this->db->prepare( $sql );
        $statement->bindParam( ":sid", $sessionID );
        $statement->execute();

        $result = $statement->fetch( PDO::FETCH_ASSOC );
        return isset( $result[ $this->col_data ] ) ? $result[ $this->col_data ] : "";
    }

}