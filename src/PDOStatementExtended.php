<?php

namespace PHPPDOStats;

use PDO;
use PDOStatement;

class PDOStatementExtended extends PDOStatement
{

    /**
     * @var array Storage to save all executed data
     */
    private static $executes = [];

    /**
     * @var PDO saved PDO Instance for driver basedc escaping sequences
     */
    private $instance;

    /**
     * @var array $boundParams - array of replaced datas
     */
    private $boundParams = array();


    /**
     * @param PDO $pdoInstance - set this class for PDO::ATTR_STATEMENT_CLASS
     */
    public static function init(PDO $pdoInstance)
    {
        $pdoInstance->setAttribute(PDO::ATTR_STATEMENT_CLASS, [static::class, [$pdoInstance]]);
    }

    /**
     * @var array save the stacktrace where this statement has ben created
     */
    private $createStacktrace;


    protected function __construct(PDO $pdoInstance)
    {
        $this->createStacktrace = array_slice(debug_backtrace(), 1); // really we dont need this stack
        $this->instance = $pdoInstance;
    }

    /**
     * get all execute stats
     * @return array
     */
    public static function getExecutes(): array
    {
        return self::$executes;
    }

    /**
     * @param null $input_parameters
     * @return bool|void
     * @see PDOStatement::execute()
     */
    public function execute($input_parameters = null)
    {
        $start = microtime(true);
        parent::execute($input_parameters); // TODO: Change the autogenerated stub
        $elapsed = microtime(true) - $start;
        $this->setSendString();
        $this->addExecuteData($this->_sendString, $elapsed, $this->errorInfo(), $this->rowCount());
    }


    /**
     * save the execute stats to array
     * @param string $query
     * @param float $elapsed
     * @param array $errors
     * @param int $affectedRows
     */
    private function addExecuteData(string $query, float $elapsed, array $errors, int $affectedRows)
    {
        $data = [
            'created' => microtime(true),
            'query' => $query,
            'originalQuery' => $this->queryString,
            'elapsed' => $elapsed,
            'errors' => $errors,
            'affectedRows' => $affectedRows,
            'stacktrace' => array_slice(debug_backtrace(),1), //we dont need this stack
            'createStacktrace' => $this->createStacktrace
        ];
        self::$executes[] = $data;
        if($this->getLongQueryTime() <= $elapsed) {
            foreach($this->longQueryCallbacks as $callable) {
                call_user_func_array($callable, [$data]);
            }
        }
    }

    /**
     * @param mixed $parameter
     * @param mixed $variable
     * @param int $data_type
     * @param null $length
     * @param null $driver_options
     * @return bool
     * @see PDOStatement::bindParam()
     */
    public function bindParam($parameter, &$variable, $data_type = PDO::PARAM_STR, $length = null, $driver_options = null)
    {
        $this->boundParams[$parameter] = [
            'value' => &$variable,
            'type' => $data_type
        ];
        return parent::bindParam($parameter, $variable, $data_type, $length, $driver_options);
    }

    /**
     * @param mixed $parameter
     * @param mixed $value
     * @param int $data_type
     * @return bool
     * @see PDOStatement::bindValue()
     */
    public function bindValue($parameter, $value, $data_type = PDO::PARAM_STR)
    {
        $this->boundParams[$parameter] = [
            'value' => $value,
            'type' => $data_type
        ];
        return parent::bindValue($parameter, $value, $data_type);
    }


    /**
     * @var String null - the query which has been sent
     */
    private $_sendString = '';

    /**
     * get the query which has been sent
     * @return String
     */
    public function getSendString(): String
    {
        return $this->_sendString;
    }

    /**
     * Gets the value for the longquerytime - if query execute time exceeds this, all longquerytimecallbacks are called
     * @return float
     */
    public static function getLongQueryTime(): float
    {
        return self::$longQueryTime;
    }

    /**
     * sets the value for the longquerytime - if query execute time exceeds this, all longquerytimecallbacks are called
     * @param float $longQueryTime
     */
    public static function setLongQueryTime(float $longQueryTime)
    {
        self::$longQueryTime = $longQueryTime;
    }


    /**
     * get the correct escaped value for the param
     * @param $param
     * @return int|string
     */
    private function prepareParam($param)
    {
        if ($param['value'] == null) {
            return 'NULL';
        }
        if ($param['type'] === PDO::PARAM_INT) {
            return (int) $param['value'];
        }

        if ($this->instance instanceof PDO) {
            return $this->instance->quote($param['value'], $param['type']);
        }



        return "'" . addslashes($param['value']) . "'";


    }

    /**
     * replaces the placeholder inside the query
     * @param string $queryString
     * @param string $replaceKey
     * @param string $replaceValue
     * @return string
     */
    private function replaceMarker(string $queryString, string $replaceKey, string $replaceValue)
    {

        if (is_numeric($replaceKey)) {
            $replaceKey = "\?";
        } else {
            //no need for regex if we just need to check first char
            $replaceKey = $replaceKey[0] === ':' ? $replaceKey : ":" . $replaceKey;
        }

        $testParam = "/({$replaceKey}(?!\w))(?=(?:[^\"']|[\"'][^\"']*[\"'])*$)/";
        // Back references may be replaced in the resultant interpolatedQuery, so we need to sanitize that syntax
        $cleanBackRefCharMap = ['%' => '%%', '$' => '$%', '\\' => '\\%'];
        $backReferenceSafeReplValue = strtr($replaceValue, $cleanBackRefCharMap);
        $interpolatedString = preg_replace($testParam, $backReferenceSafeReplValue, $queryString, 1);
        return strtr($interpolatedString, array_flip($cleanBackRefCharMap));
    }


    /**
     * query has been sent, save the sent query
     */
    public function setSendString()
    {
        $query = $this->queryString;
        $params = $this->boundParams;
        uksort($params, function ($b, $a) {
            return strlen($a) - strlen($b); //sort by length DESC
        });

        foreach ($params as $key => $value) {
            $replaceValue = $this->prepareParam($value);
            $replaceKey = $key;
            $query = $this->replaceMarker($query, $replaceKey, $replaceValue);
        }

        $this->_sendString = $query;
    }

    private static $longQueryCallbacks = [];
    private static $longQueryTime = 0.01;

    /**
     * callbacks are called if query execute time exceeds longQueryTime
     * @param callable $func
     */
    public static function addLongQueryCallback(callable $func) {
        self::$longQueryCallbacks[] = $func;
    }

}