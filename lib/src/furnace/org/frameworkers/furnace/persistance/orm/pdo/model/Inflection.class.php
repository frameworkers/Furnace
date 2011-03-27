<?php
namespace org\frameworkers\furnace\persistance\orm\pdo\model;


use org\frameworkers\furnace\core\StaticObject;

class Inflection extends StaticObject {
	
	
	public static function ToSingular($string) {
		
		// Make singular by replacing 'ies' -> 'y'
		if ('ies' == substr($string,-3,3)) {
			return substr($string,0,-3) . 'y';
		}
		
		// Make singular by dropping the terminating 's'
		if ('s' == substr($string,-1,1)) {
			return substr($string,0,-1);
		}
		
		return $string;
		
	}
	
	
	
	
	
}