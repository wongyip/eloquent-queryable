<?php namespace Wongyip\Laravel\Queryable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class Queryable
{
    /**
     * Will tale these inputs from reques as $options.
     * @var array
     */
    static $acceptedOptions = ['sort', 'order', 'limit', 'offset'];
    /**
     * @var \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Query\Builder
     */
    protected $model;
    /**
     * @var string
     */
    protected $className;
    /**
     * Database table name.
     * @var string
     */
    protected $table;
    /**
     * @var array
     */
    protected $params;
    /**
     * Key-value pairs of display-sort columns mapping.
     * @var array
     */
    protected $realSortColumns;
    /**
     * @var array
     */
    protected $filterables;
    /**
     * @var array
     */
    protected $forceOptions;
    /**
     * @var array
     */
    protected $columns;
    /**
     * Where conditions accepted by the Eloquent\Model::where() method.
     * @var array
     */
    protected $conditions;
    /**
     * The current query options.
     * @var QueryOptions
     */
    protected $options;
    /**
     * Whether the conditions and options are parsed.
     * @var boolean
     */
    protected $parsed;
    
    /**
     * An abstract class facilitates typical Eloquent Model query tasks.
     * 
     * Note on options: setOptions() > $forceOptions > Request::input()
     * 
     * @author Wongyip
     * 
     * @param string  $className     Class name of an Eloquent Model.
     * @param array   $params        Predefined query parameters.
     * @param array   $filterables   Accepted query parameters from the request.
     * @param array   $forceOptions  Set sort, order, limit, offset option(s) here to ignore certain inputs from the request.
     * @param array   $columns       Columns to get.
     */
    public function __construct($className, $params = null, $filterables = null, $forceOptions = null, $columns = null)
    {   
        // Check check
        if (!class_exists($className)) {
            throw new \Exception('Class does not exists ' . $className);
        }
        $testModel = new $className();
        if (!(new $className() instanceof Model)) {
            throw new \Exception('Class must be Eloquent Model: ' . $className);
        }
        $this->table = $testModel->getTable();
        
        // Set up
        $this->className = $className;
        $this->forceOptions = is_array($forceOptions) ? $forceOptions : [];
        $this->setColumns($columns)->setFilterables($filterables)->setOptions([])->setParams($params);
    }
    
    /**
     * Sometimes result doesn't matter.
     * 
     * @return integer|NULL
     */
    public function count()
    {
        if ($model = $this->model()) {
            return $model->count();
        }
        return null;
    }
    
    /**
     * Execute the query and retrieve the result.
     *
     * @param boolean $outputOptions  Attach query options in result, default false.
     * @param boolean $outputParams   Attach query parameters in result, default false.
     * @throws \Exception
     * @return QueryResult
     */
    public function execute($outputOptions = false, $outputParams = false)
    {
        // Init
        $rows = [];
        
        // Count (and actually instantiate the model with conditions in place)
        if ($total = $this->count()) {
            
            // Localize
            $model   = $this->model;
            $offset  = $this->options->offset;
            $limit   = $this->options->limit;
            $sort    = $this->options->sort;
            $order   = $this->options->order;
            $columns = $this->columns;
            
            // Noop
            if (!is_null($offset) && !$limit) {
                throw new \Exception('LIMIT must not omitted when OFFSET is set.');
            }
            
            // Get the real sorting column.
            $sort = (is_array($this->realSortColumns) && key_exists($sort, $this->realSortColumns)) ? $this->realSortColumns[$sort] : $sort;
            
            // Make sure the sorting column exists to prevent query exception. 
            $sort = Schema::hasColumn($this->table, $sort) ? $sort : false;
            
            if ($sort) {
                $model = $order ? $model->orderBy($sort, $order) : $model->orderBy($sort);
            }

            // Paging
            if (!is_null($offset)) {
                $model = $model->skip($offset)->take($limit);
            }
            
            // SELECT
            $results = $model->get($columns);
            $rows = $results->toArray();
        }
        
        // Pack
        return new QueryResult(
            $total,
            $rows,
            $outputOptions ? $this->options : null,
            $outputParams ? $this->params : null
        );
    }
    
    /**
     * Retrieve the columns listing.
     * 
     * @return array
     */
    public function getColumns()
    {
        return $this->columns;
    }
    
    /**
     * Retrieve the filterable columns.
     * 
     * @return array
     */
    public function getFilterables()
    {
        return $this->filterables;
    }
    
    /**
     * Retrieve the options.
     *
     * @return QueryOptions
     */
    public function getOptions()
    {
        return $this->options;
    }
    
    /**
     * Retrieve the options.
     *
     * @return array
     */
    public function getParams()
    {
        return $this->params;
    }
    
    /**
     * Retrieve the display-sort columns mapping.
     *
     * @return array
     */
    public function getRealSorting()
    {
        return $this->realSortColumns;
    }
    
    /**
     * Compose an array of Eloquent WHERE coniditions based on given paramters and input filters.
     *
     * @param array $params       Preset where parameters, applied before filters.
     * @param array $filterables  List of filterable columns, unlisted filters are ignored, matching filters will added to where clause after $params.
     * @return array
     */
    static function makeConditions($params = null, $filterables = null)
    {
        // Presets
        $where = [];
        if (is_array($params)) {
            foreach ($params as $param => $value) {
                if (is_array($value)) {
                    $where[] = [$param, $value[0], $value[1]];
                }
                else {
                    $where[] = [$param, $value];
                }
            }
        }
        
        /**
         * @var Request $request
         */
        $request = \Illuminate\Support\Facades\Request::getFacadeRoot();
        
        // User filters from $_GET (take $_POST also)
        $filterables = is_array($filterables) ? $filterables : [];
        foreach ($filterables as $filter){
            // Parse condition if input has filterable value.
            if ($request->has($filter)){
                if ($condition = self::parseFilter($filter, $request->input($filter))) {
                    $where[] = $condition;
                }
            }
        }
        
        // Hola
        return $where;
    }
    
    /**
     * Merge QueryOptions from request with override options (override inputs).
     *
     * @param array $overrideOptions Optional, override input option(s) if given.
     * @return QueryOptions
     */
    static function makeOptions($overrideOptions = null)
    {
        // Get inputs
        $options = [];
        $request = \Illuminate\Support\Facades\Request::getFacadeRoot();
        if ($request instanceof Request) {
            foreach (self::$acceptedOptions as $key) { 
                if ($request->has($key)) {
                    $options[$key] = $request->input($key);
                }
            }
        }
        return new QueryOptions(array_merge($options, is_array($overrideOptions) ? $overrideOptions : []));
    }
    
    /**
     * Instantiate the model with conditions in place.
     *
     * @throws \Exception
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Query\Builder
     */
    protected function model()
    {
        $model = new $this->className;
        foreach ($this->conditions as $cond) {
            if (is_array($cond)) {
                if (count($cond) === 3) {
                    // Convert to whereIn()
                    if ($cond[1] === 'in') {
                        $model = $model->whereIn($cond[0], $cond[2]);
                    }
                    // Typical 3 params where()
                    else {
                        $model = $model->where($cond[0], $cond[1], $cond[2]);
                    }
                }
                elseif (count($cond) === 2) {
                    // Typical 2 params where()
                    $model = $model->where($cond[0], $cond[1]);
                }
                else {
                    throw new \Exception('Invalid number of parameters.');
                }
            }
        }
        $this->model = $model;
        return $this->model;
    }
    
    /**
     * Convert column@table back to table.column format.
     *
     * @param string $column
     * @return string
     */
    static function parseColumn($column)
    {
        $matches = [];
        if (preg_match("/(.*)@(.*)/", $column, $matches)) {
            $table = $matches[2];
            $column = $matches[1];
            return "$table.$column";
        }
        return $column;
    }
    
    /**
     * Parse and input filter into an Eloquent WHERE condition array.
     *
     * @todo documentations needed!!
     * 
     * @param string $filter
     * @param string $value
     * @return array|boolean|NULL
     */
    static function parseFilter($filter, $value)
    {
        // Init
        $cond = null;
        
        // Localize
        $value = self::prepValue($value);
        
        // Only process 0 | '0' | other non-empty value
        if (!empty($value) || $value === 0 || $value === '0') {
            // Comma denotes advanced filter
            if (strpos($filter, ',') === false) {
                $cond = [$filter, $value];
            }
            // Advanced filter
            else {
                $f = explode(',', $filter);
                if (count($f) < 2) {
                    logger('Invalid filter definition');
                    return false;
                }
                $column   = self::parseColumn($f[0]);
                $operator = $f[1];
                
                /**
                 * Modifiers (the 3rd param)
                 */
                $modifier = count($f) >= 3 ? $f[2] : null;
                switch ($modifier) {
                    case 'datetime':
                        if (in_array($operator, ['>', '>='])) {
                            // Input with time...
                            if (preg_match("/:/", $value)) {
                                // Without seconds
                                if (preg_match("/\s\d{2}:\d{2}$/", $value)) {
                                    $value .= ':00'; // 00 for >=
                                }
                            }
                            else {
                                $value .= ' 00:00:00';
                            }
                        }
                        elseif (in_array($operator, ['<', '<='])) {
                            // Input with time...
                            if (preg_match("/:/", $value)) {
                                // Without seconds
                                if (preg_match("/\s\d{2}:\d{2}$/", $value)) {
                                    $value .= ':59'; // 59 for <=
                                }
                            }
                            else {
                                $value .= ' 23:59:59';
                            }
                        }
                        break;
                }
                switch ($operator) {
                    case 'starts_with':
                    case 'begins_with':
                        $cond = [$column, 'LIKE', "$value%"];
                        break;
                    case 'ends_with':
                        $cond = [$column, 'LIKE', "%$value"];
                        break;
                    case '~':
                    case 'contains':
                    case 'include':
                    case 'like':
                        $cond = [$column, 'LIKE', "%$value%"];
                        break;
                    case 'func':
                    case 'function':
                        switch ($value) {
                            case 'isnull':
                                $cond = [$column, '=', null];
                                break;
                            case 'notnull':
                                $cond = [$column, '<>', null];
                                break;
                        }
                        break;
                    default:
                        // Standard operator, like >, <, >=, <= & etc.
                        $cond = [$column, $operator, $value];
                }
            }
        }
        return $cond;
    }
    
    /**
     * Translation of token-values if defined, or return the value untouched.
     *
     * @param string $value
     * @return string
     */
    static function prepValue($value)
    {
        switch ($value) {
            case '__TODAY':
                $value = date('Y-m-d');
                break;
            case '__NOW':
                $value = date('Y-m-d H:i:s');
                break;
        }
        return $value;
    }
    
    /**
     * Set the columns to retrieve from query.
     *
     * @param array $params
     * @return \Wongyip\Laravel\Queryable\Queryable
     */
    public function setColumns($columns)
    {
        $this->columns = $columns;
        return $this;
    }
    
    /**
     * Set the query params.
     *
     * @param array $params
     * @return \Wongyip\Laravel\Queryable\Queryable
     */
    public function setParams($params)
    {
        $this->params = $params;
        $this->conditions = self::makeConditions($this->params, $this->filterables);
        return $this;
    }
    
    /**
     * Set the filterable columns.
     *
     * @param array $filterables
     * @return \Wongyip\Laravel\Queryable\Queryable
     */
    public function setFilterables($filterables)
    {
        $this->filterables = $filterables;
        $this->conditions = self::makeConditions($this->params, $this->filterables);
        return $this;
    }
    
    /**
     * Set the query options.
     * Note that options set in $forceOptions will retains unless $overrideForceOptions is true.
     *
     * @param array   $queryOptions
     * @param boolean $overrideForceOptions
     * @return \Wongyip\Laravel\Queryable\Queryable
     */
    public function setOptions($queryOptions, $overrideForceOptions = false)
    {
        $queryOptions = $overrideForceOptions ? array_merge($this->forceOptions, $queryOptions) : array_merge($queryOptions, $this->forceOptions);
        $this->options = self::makeOptions($queryOptions);
        return $this;
    }
    
    /**
     * Set the display-sort columns mapping.
     * E.g. input ['field1' => 'field2'] for displaying field1 and sort with field2. 
     * 
     * @param array $replacements
     * @return \Wongyip\Laravel\Queryable\Queryable
     */
    public function setRealSorting($realSortColumns)
    {
        $this->realSortColumns = $realSortColumns;
        return $this;
    }
}