<?php

namespace Modules\Steamauth\Mappers;

use Ilch\Date;
use Ilch\Mapper;
use Modules\Steamauth\Models\Log;

function mist($mixed = [])
{
    ob_start();
    print_r($mixed);
    $content = [ "vardump" => ob_get_contents()];
    ob_end_clean();
    return $content;
}

class DbLog extends Mapper {
    /**
     * Shortcut for an info log message
     *
     * @param $message  string  The message
     * @param $data     mixed   Additional information
     *
     * @return int
     *
     **
     */
    public function info($message, $data = [])
    {
        return $this->log('info', $message, $data);
    }

    /**
     * Shortcut for an debug log message
     *
     * @param $message  string  The message
     * @param $data     mixed   Additional information
     *
     * @return int
     */
    public function dump($message, $data = [])
    {
        //        $data = mist($data);
        return $this->log('dump', $message, $data);
    }

    /**
     * @param $message
     * @param array $data
     * @return int
     */
    public function debug($message, $data = [])
    {
        return $this->log('debug', $message, $data);
    }

    /**
     * Shortcut for an error log message
     *
     * @param $message  string  The message
     * @param $data     mixed   Additional information
     *
     * @return int
     */
    public function error($message, $data = [])
    {
        return $this->log('error', $message, $data);
    }

    /**
     * Inserts a log message into the database
     *
     * @param $type     string  Log type (e.g. error, info, debug)
     * @param $message  string  The log message
     * @param $data     mixed   Additional information regarding the log message
     *
     * @return int
     */
    public function log($type, $message, $data)
    {
        if (! $this->isValidJson($data)) {
            $data = json_encode($data);
        }

        return $this->db()
        ->insert('steamauth_log')
        ->values([
            'type' => $type,
            'message' => $message,
            'data' => $data,
            'created_at' => (new Date())->toDb()
        ])
        ->execute();
    }

    /**
     * Get log messages
     *
     * @return \Ilch\Database\Mysql\Result
     */
    public function getAll()
    {
        return $this->db()
        ->select('*')
        ->from('steamauth_log')
        ->order(['created_at' => 'DESC'])
        ->limit(50)
        ->execute();
    }

    /**
     * Finds the log message with the given id
     *
     * @param $logId
     * @param string $fields
     *
     * @return Log|null
     */
    public function find($logId, $fields = '*')
    {
        return $this->db()
        ->select($fields)
        ->from('steamauth_log')
        ->where(['id' => $logId])
        ->limit(1)
        ->execute()
        ->fetchObject(Log::class, []);
    }

    /**
     * Clears the log
     *
     * @return int  Affected rows
     */
    public function clear()
    {
        return $this->db()->delete('steamauth_log')
        ->where(['id >' => 0])
        ->execute();
    }

    /**
     * Deletes the given log message
     *
     * @param $logId
     *
     * @return \Ilch\Database\Mysql\Result|int
     *
     * @throws \Exception
     */
    public function delete($logId)
    {
        $log = $this->find($logId);

        if (is_null($log)) {
            throw new \Exception('No log with id '. $logId . ' found.');
        }

        return $this->db()
        ->delete('steamauth_log')
        ->where(['id' => $log->getId()])
        ->execute();
    }

    /**
     * Checks if the value is valid json
     *
     * @param $value
     *
     * @return bool
     */
    protected function isValidJson($value)
    {
        $temp = @json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && ! is_null($temp);
    }
}