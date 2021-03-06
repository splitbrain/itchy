<?php /** @noinspection SqlNoDataSourceInspection */

namespace splitbrain\itchy;

use Psr\Log\LoggerInterface;

class DataBase
{
    /** @var \PDO */
    protected $db;
    /** @var LoggerInterface */
    protected $logger;

    /**
     * Constructor
     */
    public function __construct($file, LoggerInterface $logger)
    {
        $this->logger = $logger;

        $exists = file_exists($file);
        $this->db = new \PDO(
            'sqlite:' . $file,
            null,
            null,
            [
                \PDO::ATTR_PERSISTENT => true,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
            ]
        );

        if (!$exists) {
            $this->initTables();
        }
    }

    /**
     * Simple query abstraction
     *
     * @param string $sql
     * @param array $params
     * @return array|false
     */
    public function query($sql, $params = [])
    {
        try {
            $stm = $this->db->prepare($sql);
            $stm->execute($params);
            $data = $stm->fetchAll(\PDO::FETCH_ASSOC);
            $stm->closeCursor();
        } catch (\PDOException $e) {
            $this->logger->debug($sql);
            $this->logger->debug(print_r($params, true));
            throw $e;
        }
        return $data;
    }

    /**
     * Insert or replace the given data into the table
     *
     * @param string $table
     * @param array $data
     * @return void
     */
    public function saveRecord($table, $data)
    {
        $columns = array_map(function ($column) {
            return '"' . $column . '"';
        }, array_keys($data));
        $values = array_values($data);
        $placeholders = array_pad([], count($columns), '?');

        /** @noinspection SqlResolve */
        $sql = 'REPLACE INTO "' . $table . '" (' . join(',', $columns) . ') VALUES (' . join(',', $placeholders) . ')';
        try {
            $stm = $this->db->prepare($sql);
            $stm->execute($values);
            $stm->closeCursor();
        } catch (\PDOException $e) {
            $this->logger->debug($sql);
            $this->logger->debug(print_r($values, true));
            throw $e;
        }
    }

    /**
     * Initializes the database from schema
     */
    protected function initTables()
    {
        $sql = file_get_contents(__DIR__ . '/schema.sql');
        $sql = explode(';', $sql);
        foreach ($sql as $statement) {
            $this->db->exec($statement);
        }
    }
}
