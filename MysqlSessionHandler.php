<?php

namespace Persistent_Sessions;

use SessionHandlerInterface;

/**
 * Class MysqlSessionHandler
 * @package Persistent_Sessions
 *
 * Custom session handler to store session data in MySQL/MariaDB
 */
class MysqlSessionHandler implements SessionHandlerInterface
{
    use Helper_Session;

    /**
     * An array to support multiple reads before closing (manual, non-standard usage)
     *
     * @var array Array of statements to release application-level locks
     */
    protected $unlockStatements = [ ];

    /**
     * @var bool True when PHP has initiated garbage collection
     */
    protected $collectGarbage = false;

    /**
     * Constructor
     *
     * By default, the session handler uses transactions, which requires
     * the use of the InnoDB engine. If the sessions table uses the MyISAM
     * engine, set the optional second argument to false.
     *
     * @param bool $useTransactions Determines whether to use transactions (default)
     */

    public function __construct( $useTransactions = true )
    {
        $this->useTransactions = $useTransactions;
        $this->expiry = time() + (int)ini_get( 'session.gc_maxlifetime' );
        $this->get_db_connection();

    }

    /**
     * Opens the session
     *
     * @param string $save_path
     * @param string $name
     * @return bool
     */

    public function open( $save_path, $name )
    {
        return true;
    }


    /**
     * Reads the session data
     *
     * @param string $session_id
     * @return string
     */

    public function read( $session_id )
    {
        try {
            if ( $this->useTransactions ) {
                // MySQL's default isolation, REPEATABLE READ, causes deadlock for different sessions.
                $this->db->exec( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' );
                $this->db->beginTransaction();
            } else {
                $this->unlockStatements[] = $this->getLock( $session_id );
            }
            $sql = "SELECT $this->col_expiry, $this->col_data
            FROM $this->table_sess WHERE $this->col_sid = :sid";
            // When using a transaction, SELECT FOR UPDATE is necessary
            // to avoid deadlock of connection that starts reading
            // before we write.
            if ( $this->useTransactions ) {
                $sql .= ' FOR UPDATE';
            }
            $selectStmt = $this->db->prepare( $sql );
            $selectStmt->bindParam( ':sid', $session_id );
            $selectStmt->execute();
            $results = $selectStmt->fetch( \PDO::FETCH_ASSOC );
            if ( $results ) {
                if ( $results[ $this->col_expiry ] < time() ) {
                    var_dump( $results );
                    // Return an empty string if data out of date
                    return '';
                }
                return $results[ $this->col_data ];
            }
            // We'll get this far only if there are no results, which means
            // the session hasn't yet been registered in the database.
            if ( $this->useTransactions ) {
                $this->initializeRecord( $selectStmt );
            }
            // Return an empty string if transactions aren't being used
            // and the session hasn't yet been registered in the database.
            return '';
        } catch ( \PDOException $e ) {
            if ( $this->db->inTransaction() ) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Writes the session data to the database
     *
     * @param string $session_id
     * @param string $data
     * @return bool
     */
    public function write( $session_id, $data )
    {
        try {
            $sql = "INSERT INTO $this->table_sess ($this->col_sid,
            $this->col_expiry, $this->col_data)
            VALUES (:sid, :expiry, :data)
            ON DUPLICATE KEY UPDATE
            $this->col_expiry = :expiry,
            $this->col_data = :data";
            $stmt = $this->db->prepare( $sql );
            $stmt->bindParam( ':expiry', $this->expiry, \PDO::PARAM_INT );
            $stmt->bindParam( ':data', $data );
            $stmt->bindParam( ':sid', $session_id );
            $stmt->execute();
            return true;
        } catch ( \PDOException $e ) {
            if ( $this->db->inTransaction() ) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    /**
     * Closes the session and writes the session data to the database
     *
     * @return bool
     */

    public function close()
    {
        if ( $this->db->inTransaction() ) {
            $this->db->commit();
        } elseif ( $this->unlockStatements ) {
            while ( $unlockStmt = array_shift( $this->unlockStatements ) ) {
                $unlockStmt->execute();
            }
        }
        if ( $this->collectGarbage ) {
            $sql = "DELETE FROM $this->table_sess WHERE $this->col_expiry < :time";
            $stmt = $this->db->prepare( $sql );
            $stmt->bindValue( ':time', time(), \PDO::PARAM_INT );
            $stmt->execute();
            $this->collectGarbage = false;
        }
        return true;
    }

    /**
     * Destroys the session
     *
     * @param int $session_id
     * @return bool
     */

    public function destroy( $session_id )
    {

        $sql = "DELETE FROM $this->table_sess WHERE $this->col_sid = :sid";
        try {
            $stmt = $this->db->prepare( $sql );
            $stmt->bindParam( ':sid', $session_id );
            $stmt->execute();
        } catch ( \PDOException $e ) {
            if ( $this->db->inTransaction() ) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return true;
    }

    /**
     * Garbage collection
     *
     * @param int $maxlifetime
     * @return bool
     */


    public function gc( $maxlifetime )
    {
        $this->collectGarbage = true;
        return true;
    }
}