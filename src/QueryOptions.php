<?php namespace Wongyip\Laravel\Queryable;

class QueryOptions
{
    public $sort;
    public $order = 'asc';
    public $limit;
    public $offset = 0;
    
    /**
     * Basic querying options (default: sort = 'asc', offset = 0).
     * 
     * @param array $properties  Override defaults
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
     * @return boolean
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
            return true;
        }
        return false;
    }
}