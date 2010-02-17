<?php
/*
 * frameworkers-foundation
 * 
 * FObjectCollection.class.php
 * Created on May 20, 2008
 *
 * Copyright 2008 Frameworkers.org. 
 * http://www.frameworkers.org
 */



class FCriteria {

    public $prior_op = null;
    public $field    = null;
    public $value    = null;
    public $comp     = '=';

    /**
     *
     * @param $prior_op: the boolean ('AND', 'OR', 'AND NOT', etc) to preceed this criteria
     * @param $field: the object attribute that this criteria applies to
     * @param $value: the value to use for comparison. If $value is an array, IN becomes the default comparison operator
     * @param $comp: the comparison operator (<,<=,=,>=,>,!=,NOT,IN) to use
     * @return unknown_type
     */

    public function __construct($prior_op = null,$field,$value,$comp='=') {
        $this->prior_op = $prior_op;
        $this->field    = $field;
        $this->value    = $value;
        $this->comp     = ('=' == $comp && is_array($value))
            ? "IN"
            : strtoupper($comp);
    }

    public function __toString() {
        switch ($this->comp) {
            	
            default:
                $s = " {$this->prior_op} `{$this->field}` {$this->comp} '{$this->value}' ";
                break;
        }

        return $s;
    }
}
 
/*
* Class: FObjectCollection
* Represents a collection of <FBaseObject> objects.
*/
 
abstract class FObjectCollection {
    
    protected $objectType;
    protected $objectTypeTable;
    protected $query;
    protected $output;
    
    public $data;
    
    
    

    public function __construct($objectType,$lookupTable,$baseFilter = null) {
        $this->data            = array();
        $this->objectType      = $objectType;
        $this->objectTypeTable = $lookupTable;
        $this->query      = new FQuery();
        $this->query->addTable($lookupTable);

        $this->output = 'objects';
        if ( $baseFilter ) {
            $this->query->addCondition(null,$baseFilter);
        }
    }

    // Specifies the type of data returned by 'get' calls
    // Options:
    //    object
    //    array
    //    collection
    //    query
    //    xml
    //    yml
    //    json
    public function output( $which = 'objects' ) {
        $this->output = $which;

        switch ($which) {
            case 'collection':
                return this;
            case 'objects':
                if (count($this->data) == 0) {
                    $this->runQuery('objects');
                }
                return array_values($this->data);     // strip o_* keys
                break;
            case 'query':
                return $this->query->select();
            default:
                throw new FException("Unknown output method provided to FObjectCollection::output()");
                break;
        }
    }
    
    // Return the first object in the collection
    public function first() {
        if (count($this->data) == 0) {
            $this->runQuery('objects');
        }
        
        if (count($this->data) == 0 ) {
            return false;                    // no objects in the collection
        } else {
            $keys = array_keys($this->data);
            return $this->data[$keys[0] ];   // always return the 1st object
        }
        
    }

    // Specifies a limit on the number of results to return, and optionally
    // specifies an offset (useful for pagination)
    public function limit( $limit, $offset = 0 ) {
        $this->query->setLimit($limit,$offset);
        return $this;
    }


    // Adds a filter to the collection
    // Examples:
    //    filter(key,value)               - key=value
    //    filter(key,comparator,value)    - key{comp}value where {$comp} is = != < <= > >=, etc
    //    filter(FCriteria)               - processes an FCriteria object to the query
    public function filter(/* variable arguments accepted */) {
        $num_args = func_num_args();
        switch ($num_args) {
            case 1:
                // Process an FCriteria object
                break;
            case 2:
                // Process a key=val filter
                $k = func_get_arg(0);
                $v = func_get_arg(1);
                if ($k == 'id') { $k = $this->getRealId(); }
                $this->query->addCondition(null, "{$k}='{$v}' ");
                break;
            case 3:
                // Process a key,comp,val filter
                $k = func_get_arg(0);
                $c = func_get_arg(1);
                $v = func_get_arg(2);
                if ($k == 'id') { $k = $this->getRealId(); }
                $this->query->addCondition(null, "{$k} {$c} {$v} ");
                break;
            default:
                throw new FException("Unexpected number of arguments for FObjectCollection::filter()");
                break;
        }
        return $this;
    }
    
    public function filterIn($key, $allowedValues, $bQuoteValues = false, $bNegate = false) {
        if ($key == 'id') { $key = $this->getRealId(); }
        $negate = ($bNegate) ? "NOT " : "";
        if ($bQuoteValues) {
            $this->query->addCondition(null, "( {$key} {$negate} IN (\"" . implode('","',$allowedValues) . '") )');
        } else {
            $this->query->addCondition(null, "( {$key} {$negate} IN (" . implode(',',$allowedValues) . ") )");
        }
        return $this;
    }
    
    public function each() {
        $this->runQuery();
        return $this;
    }
    
    public function expand($attribute,$fieldList = null,$indexKey='id') {
        if (count($this->data) == 0) { return $this; }
        $ot = $this->objectType;
        $fn = "get{$attribute}Info";
        $relationshipData = _model()->$ot->$fn();
        $loadAttribute    = "load{$attribute}";
        $setAttribute     = "set{$attribute}";
        $getAttribute     = "get{$attribute}";
        
        // Parent
        if ($relationshipData['role_l'] == "M1") {
            
            //TODO: Handle the case in which the
            // parent class is of the same type as the child
            //
            //
            if ($relationshipData['table_l'] != $relationshipData['table_f']) {
                $keys = $this->getKeys($relationshipData['key_l']);
                $q = "SELECT * "
                    ."FROM  `{$relationshipData['table_l']}`,`{$relationshipData['table_f']}` "
                    . (($relationshipData['base_f'] == 'FAccount') ? ",`app_accounts` " : '')
                    ."WHERE `{$relationshipData['table_l']}`.`{$relationshipData['column_l']}` = `{$relationshipData['table_f']}`.`{$relationshipData['column_f']}` "
                    ."AND   `{$relationshipData['table_f']}`.`{$relationshipData['table_f']}_id` IN (\"".implode("\",\"",$keys)."\") "
                    . (($relationshipData['base_f'] == 'FAccount') ? "AND {$relationshipData['table_l']}.faccount_id=app_accounts.faccount_id " : '');

                $result = _db($relationshipData['db_l'])->query($q,FDATABASE_FETCHMODE_ASSOC);
                while (false != ($unsortedParent = $result->fetchRow(FDATABASE_FETCHMODE_ASSOC))) {
                    $this->data['o_'.$unsortedParent[$relationshipData['table_f'].'_id'] ]
                        ->$setAttribute(new $relationshipData['object_f']($unsortedParent));
                }
            
            } else {
                $keys = $this->getKeys($relationshipData['key_f']);
                $q = "SELECT * "
                    ."FROM  `{$relationshipData['table_l']}` "
                    . (($relationshipData['base_f'] == 'FAccount') ? ",`app_accounts` " : '')
                    ."WHERE `{$relationshipData['table_l']}`.`{$relationshipData['table_l']}_id` IN (\"".implode("\",\"",$keys)."\") "
                    . (($relationshipData['base_f'] == 'FAccount') ? "AND {$relationshipData['table_l']}.faccount_id=app_accounts.faccount_id " : '');


                $result = _db($relationshipData['db_l'])->query($q,FDATABASE_FETCHMODE_ASSOC);
                while (false != ($unsortedParent = $result->fetchRow(FDATABASE_FETCHMODE_ASSOC))) {
                    foreach ($this->data as &$do) {
                        if ($do->$getAttribute(true) == $unsortedParent[$relationshipData['table_l'].'_id']) {
                            $do->$setAttribute(new $relationshipData['object_f']($unsortedParent));
                            break;
                        }
                    }
                }
            } 
        }
        
        // Pair
        if ($relationshipData['role_l'] == '11') {
            die("Furnace: FObjectCollection::expand() pair relations not implemented yet");
        }
        
        // Peer
        if ($relationshipData['role_l'] == "MM") {
            $keys = $this->getKeys($relationshipData['key_l']);
            
            // START HERE
            // Need to add a special attribute to relationshipData (in FModel, below) for this type so that we can
            // capture all 3 relevant tables: table_l table_f and table_lookup. Then, we need to
            // do a join on the tables to get the complete information, taking care to account for
            // the case in which table_l and table_f are the same table.
            
            if ($relationshipData['table_l'] != $relationshipData['table_f']) {
                die("Furnace: FObjectCollection::expand() different-type peers not implemented yet");
            } else {
                die("Furnace: FObjectCollection::expand() same-type peers not implemented yet"); 
            }
        }
        
        // Child
        if ($relationshipData['role_l'] == "1M") {
            
            $keys = $this->getKeys($indexKey);
            $q = "SELECT * FROM `{$relationshipData['table_l']}` "
                .(($relationshipData['base_f'] == 'FAccount') ? ',`app_accounts` ' : '')
                ."WHERE `{$relationshipData['column_l']}` IN (\"".implode("\",\"",$keys)."\")"
                .(($relationshipData['base_f'] == 'FAccount') ? "AND {$relationshipData['table_l']}.faccount_id=app_accounts.faccount_id " : '');
            $result = _db($relationshipData['db_l'])->query($q,FDATABASE_FETCHMODE_ASSOC);

            while (false != ($unsortedChild = $result->fetchrow(FDATABASE_FETCHMODE_ASSOC))) {
                $parentId = $unsortedChild[$relationshipData['column_l'] ];
                $this->data["o_{$parentId}"]->$loadAttribute(array("o_{$unsortedChild[$relationshipData['column_l'] ]}" => new $relationshipData['object_f']($unsortedChild)));    
            }
        }
        
        return $this;
    }
    
    protected function getKeys($keyAttribute = 'id') {
        $fn   = "get{$keyAttribute}";
        $keys = array();
        foreach ($this->data as $o) {
            $key  = $o->$fn(true); // bIdOnly = true;
            $keys[$key] = $key; 
        }
        return $keys;
    }
    
    protected function getRealId() {
        return $this->objectTypeTable . '_id';
    }


    public function get(/* variable arguments accepted */) {
        $num_args = func_num_args();
        switch ($num_args) {
            case 0:
                // Return the collection object, as-is
                break;
            case 1:
                // Get a single object using the provided objId
                $v = func_get_arg(0);
                if (false !== ($obj = $this->getSingleObjectByObjectId($v))) {
                    $this->data = array($obj);
                }
                break;
            case 2:
                // Get a single object using the provided key/value pair
                $k = func_get_arg(0);
                $v = func_get_arg(1);
                if (false !== ($obj = $this->getSingleObjectByAttribute($k,$v))) {
                    $this->data = array($obj);
                }
                break;
            default:
                throw new FException("Unexpected number of arguments for FObjectCollection::get()");
                return false;
        }
        return $this;

    }

    // This will return at most one object where objId=id
    protected function getSingleObjectByObjectId( $id ) {
        $this->filter('id',$id);
        $result = _db()->queryRow($this->query->select(),FDATABASE_FETCHMODE_ASSOC);
        if (null == $result) { return false; } 
        else {
            $t = $this->objectType;  
            return new $t($result);
        }
    }

    // This will return at most one object where attr=val
    protected function getSingleObjectByAttribute( $attr, $value ) {
        $this->filter($attr,$value);
        $result = _db()->queryRow($this->query->select(),FDATABASE_FETCHMODE_ASSOC);
        if (null == $result) { return false; }
        else {
            $t = $this->objectType;
            return new $t($result);
        }
    }
    
    protected function runQuery($output = 'objects') {
        $result = _db()->queryAll($this->query->select(),FDATABASE_FETCHMODE_ASSOC);
        if (null == $result) { return false; }
        else {
            $response = array();
            $t = $this->objectType;
            foreach ( $result as $r ) {
                $response['o_'.$r[$this->objectTypeTable.'_id'] ] = new $t($r);
            }
            $this->data = $response;
        }
    }
}
?>