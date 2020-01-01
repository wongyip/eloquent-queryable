<?php namespace Wongyip\Laravel\Queryable;

class QueryResult
{
    /**
     * @var array
     */
    public $rows;
    /**
     * @var int;
     */
    public $total;
    
    /**
     * Query Result Container.
     * 
     * @param number $total
     * @param array  $rows
     * @param array  $options
     * @param array  $params
     */
    public function __construct($total, $rows, $options = null, $params = null)
    {
        $this->total = $total;
        $this->rows = $rows;
        // Expose options
        if ($options) {
            $this->options = $options;
        }
        // Expose params
        if ($params) {
            $this->params = $params;
        }
    }
}