<?php namespace Wongyip\Laravel\Queryable;

class QueryOptions
{
    public $sort;
    public $order;
    public $limit;
    public $offset;
    
    /**
     * Basic querying options.
     * 
     * @param array $properties  Initial options.
     */
    public function __construct($properties = null)
    {
        if ($properties) {
            $this->set($properties);
        }
    }
    
    /**
     * Absorb property-values / key-values from given object / array, which are already exist in $this.
     * 
     * NOTE: this method DOES NOT create new property.
     *
     * @param object|array $properties
     * @param boolean $update_null_value
     */
    public function set($properties, $update_null_value = true)
    {
        $properties = is_object($properties) ? $properties : (is_array($properties) ? (object) $properties: null);
        if (is_object($properties)) {
            foreach (get_object_vars($properties) as $property => $value) {
                if (property_exists($this, $property)) {
                    if (!is_null($value) || $update_null_value) {
                        $this->$property = $value;
                    }
                }
            }
        }
        $this->patch();
    }
    
    /**
     * Patch invaid options.
     */
    private function patch()
    {
        if ($this->limit) { 
            if (!$this->offset) {
                $this->offset = 0;
            }
        }
    }
}