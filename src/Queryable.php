<?php namespace Wongyip\Laravel\Queryable;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Queryable
{
    /**
     * @var Model|Builder
     */
    private $model;
    /**
     * @var string
     */
    public $className;
    /**
     * @var array
     */
    public $params;
    /**
     * @var array
     */
    public $filterables;
    /**
     * @var array
     */
    public $forceOptions;
    /**
     * @var array
     */
    public $columns;
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
     * An abstract class facilitates typical Eloquent Model query tasks.
     * 
     * Note on options: setOptions() > $forceOptions > request()->input(...)
     * 
     * @author Yipli
     * 
     * @param string  $className     Class name of an Eloquent Model (or Eloquent Builder).
     * @param array   $params        Predefined query parameters.
     * @param array   $filterables   Accepted query parameters from the request.
     * @param array   $forceOptions  Set sort, order, limit, offset option(s) here to ignore certain inputs from the request.
     * @param array   $columns       Columns to get.
     */
    public function __construct($className, $params = null, $filterables = null, $forceOptions = null, $columns = null)
    {
        $this->className    = $className;
        $this->params       = $params;
        $this->filterables  = $filterables;
        $this->forceOptions = $forceOptions;
        $this->columns      = $columns;
        
        // Check check
        if (!class_exists($this->className)) {
            throw new \Exception('Class does not exists ' . $this->className);
        }
        if (!(new $this->className() instanceof Model)) {
            throw new \Exception('Class must be Eloquent Model: ' . $this->className);
        }
        
        // Conditions
        $this->conditions = self::makeConditions($this->params, $this->filterables);
        
        // Options
        $this->options = self::makeOptions($this->forceOptions);
        
        // Instantiate model and apply conditions. Note that conditions are set
        // on class instantiate, not changeable on runtime.
        $model = new $this->className;
        $this->model = $model->where($this->conditions);
    }
    
    /**
     * Sometimes result doesn't matter.
     * 
     * @return integer|NULL
     */
    public function count()
    {
        if ($this->model instanceof Model || $this->model instanceof Builder) {
            return $this->model->count();
        }
        return null;
    }
    
    /**
     * Execute the query.
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
        
        // Count
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
            
            // Sorting
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
     * Merge options from request with force options (override inputs).
     * Output array with 'sort', 'order', 'limit' and 'offset' elements.
     *
     * @param array $forceOptions  Optional, override input option(s) if given.
     *
     * @return QueryOptions
     */
    static function makeOptions($forceOptions = null)
    {
        // Get inputs
        $setOptions = [];
        $request = \Illuminate\Support\Facades\Request::getFacadeRoot();
        if ($request instanceof Request) {
            if ($request->has('sort')) {
                $setOptions['sort'] = $request->input('sort');
            }
            if ($request->has('order')) {
                $setOptions['order'] = $request->input('order');
            }
            if ($request->has('offset')) {
                $setOptions['offset'] = $request->input('offset');
            }
            if ($request->has('limit')) {
                $setOptions['limit'] = $request->input('limit');
            }
        }
        return new QueryOptions(array_merge($setOptions, is_array($forceOptions) ? $forceOptions : []));
    }
    
    /**
     * Parse and input filter into an Eloquent WHERE condition.
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
     * Change any options already set, including those in $forceOptions.
     *
     * @param array $overrideOptions
     */
    public function setOptions($overrideOptions)
    {
        $this->options->absorbExisting($overrideOptions);
    }
}