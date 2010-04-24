<?php
class ModelController extends Controller {
    
    public function __construct() {
        parent::__construct();
        $this->requireLogin();
        $this->setActiveMenuItem('main','model');
        $this->setTitle('Foundry :: Model');
        $this->set('pageTitle','Model');
    }
    
    public function index() {
        $this->init();
        
        $m = $this->getModel();
        $this->set('theModel',$m);

		$this->extensionAddStylesheet('org.frameworkers.fuel','index.css',true);

		try {
		    _db();
		    $this->set('dbConnectOk',true);
		} catch (FDatabaseException $fde) {
		    $this->set('dbConnectOk',false);
		    $this->set('dbConnectMessage',$fde->getMessage());
		}
    }
    
    public function editObject($name) {
        $this->init();
        
        $m = $this->getModel();
		if (!$m->objects[$name]) {
			die("Object '{$name}' is not defined in the model.");
		}
		$object = $m->objects[$name];
		
		$this->set('theModel',$m);
		$this->set('object',$object);
    }
    
    
    
    
    public function export($format="YML") {
		$this->init();
		$m = $this->getModel();
		$this->set('contents',$m->export($format));
	}
	
	public function saveModel() {
		file_put_contents(
			_furnace()->rootdir.
			"/app/model/model.yml",stripslashes($this->form['contents']));
			
		$this->flash("model changes saved. Don't forget to "
			."<a class=\"ff\" href=\"/fuel/model/generate/\">regenerate your model objects</a>!");
		$this->redirect("{$this->prefix}/model/");
	}
	
	public function forceGenerateObjects() { 
	    $this->generateObjects();
	    $this->flash("Model objects regenerated.");
	    $this->redirect("{$this->prefix}/model");
	}
	
    public function generateObjects() {
		$output = array();
		
		// Import required files
        $this->init();
		 
		 // Build a representation of the data
		 $model = $this->getModel();
		 
		 // Write the object code (individual and compiled)
		 $output[] =  "<h4>Generating PHP Object Code</h4><ul>";
		 foreach ($model->objects as $obj) {
		 	$output[] = "<li>Writing class file: {$obj->getName()}</li>";
		 	$outputFilePath = _furnace()->rootdir . "/app/model/objects/{$obj->getName()}.class.php";
		 	// Only if the object is not an extension and the file DOES NOT ALREADY EXIST:
		 	if (!$obj->getExtension() && !file_exists($outputFilePath)) {
		 		$model->generateUserPhpFile($obj->getName(),_furnace()->rootdir . "/app/model/objects/{$obj->getName()}.class.php");
		 	}
		 }
		 $output[] =  "<li> == creating base class file (model.php) == </li>";
		 $compiledOutput = "<?php\r\n{$model->compilePhp()}\r\n\r\n";
		 foreach ($model->objects as $o) {
		     if (false != $o->getExtension()) {
		        $compiledOutput .= "require(_furnace()->rootdir.'/app/plugins/extensions/{$o->getExtension()}/model/objects/{$o->getName()}.class.php');\r\n";   
		     } else {
		 	    $compiledOutput .= "require('objects/{$o->getName()}.class.php');\r\n";
		     }	
		 }
		 $compiledOutput .="\r\n?>";
		 file_put_contents(_furnace()->rootdir 
		 	. "/app/model/model.php",$compiledOutput);
		 $output[] =  "</ul>";
		 $output[] =  "<h4>Generating SQL Schema File</h4><ul>";
		 
		 // Write the SQL Schema file
		 $sqlOutputFile = fopen(_furnace()->rootdir . "/app/model/model.sql","w");
		 foreach ($model->tables as $t) {
		 	$output[] =  "<li>Writing table definition for: {$t->getName()}</li>";
			fwrite($sqlOutputFile,$t->toSqlString()."\r\n\r\n");
		 }
		 
		 // Add any required framework tables
		 if ($model->use_accounts) {
		 	$fAccount .= file_get_contents(dirname(dirname(dirname(__FILE__))) . '/libraries/generation/schemas/app_accounts.sql');
		 	$fAccount .= file_get_contents(dirname(dirname(dirname(__FILE__))) . '/libraries/generation/schemas/app_roles.sql');
		 	$fAccount .= file_get_contents(dirname(dirname(dirname(__FILE__))) . '/libraries/generation/schemas/app_logs.sql');
			fwrite($sqlOutputFile,$fAccount."\r\n\r\n");	    	    
		 }

		 // Close the output file
		 fclose($sqlOutputFile);
		 
		 $output[] =  "</ul>";
		 $output[] =  "<h5>Finished.</h5>";
		 $this->set('results',implode($output));

	}
	
	
    public function createObject() {
		if ($this->form) {

			$this->init();
			$objectType   = FModel::standardizeName($this->form['objectName']);
			$objectParent = FModel::standardizeName($this->form['objectParent']);

			// Validate the provided input
			if ("" == $objectType || 
				($objectParent != "FBaseObject" && $objectParent != "FAccount")
			) {
				$this->flash("<strong>Error!</strong> &nbsp;Please provide all required information before submitting!","error");
				$this->redirect("/fuel/model/");
			}
			
		    // Validate that the object does not yet exist
			if (isset($m->objects[$objectType])) {
			    $this->flash("<strong>Error!</strong> &nbsp;An object of type <code>{$objectType}</code> already exists!","error");
			    $this->redirect("/fuel/model/");
			}
			
			// Actually create the object
			$m = $this->getModel();

			$newObject = new FObj($objectType,$m->getModelData());
			$newObject->setParentClass($objectParent);
			$newObject->setDescription($this->form['description']);
			$m->objects[$objectType] = $newObject;
			
			// Add the required SQL table
			$tableName = FModel::standardizeTableName($objectType);
			$m->tables[$tableName] = new FSqlTable($objectType);
			
			// If the object extends FAccount, add the 'faccount_id' column
			if ("FAccount" == $objectParent) {
				$extra = array("min"=>0);
				$col   = new FSqlColumn(
					"faccount_id",
					FSqlColumn::convertToSqlType("integer",$extra),
					false,
					false,
					"Link to account details for this {$objectType}");
				
				$m->tables[$tableName]->addColumn($col,$extra);
			}
			
			// Write the changes to the model file
			$this->writeModelFile($m->export());
			
			// Execute SQL commands
			
			// If the object is derived from FAccount:
			if ("FAccount" == $objectParent) {
				
				// Verify that the required framework tables exist in the database
				$schema = $this->getSchema();
				
				if (!$schema->tableExists('app_accounts')) {
				    $sql = file_get_contents(dirname(dirname(dirname(__FILE__))) . '/libraries/generation/schemas/app_accounts.sql');
				    _db()->exec($sql);
				    $this->flash("Created required `app_accounts` table");
				}
			    if (!$schema->tableExists('app_roles')) {
				    $sql = file_get_contents(dirname(dirname(dirname(__FILE__))) . '/libraries/generation/schemas/app_roles.sql');
				    _db()->exec($sql);
				    $this->flash("Created required `app_roles` table");
				}
			    if (!$schema->tableExists('app_logs')) {
				    $sql = file_get_contents(dirname(dirname(dirname(__FILE__))) . '/libraries/generation/schemas/app_logs.sql');
				    _db()->exec($sql);
				    $this->flash("Created required `app_logs` table");
				}
			}
				
			// Create the object table itself
			_db()->exec($m->tables[$tableName]->toSqlString());
			
			
			// Regenerate PHP code
			$this->generateObjects();
			
			
			// Redirect to the new object's edit page
			$this->redirect("{$this->prefix}/model/editObject/{$objectType}");
		}
	}
	/*
    public function createObject() {
		if ($this->form) {

			$this->init();
			$objectType   = FModel::standardizeName($this->form['object_id']);
			$objectParent = FModel::standardizeName($this->form['parentClass']);
			$objectDesc   = $this->form['obj_description'];
			$objectTableName = strtolower($objectType[0]) . substr($objectType,1);

			// Validate the provided input
			if ("" == $objectType || 
				($objectParent != "FBaseObject" && $objectParent != "FAccount")
			) {
				$this->flash("<strong>Error!</strong> &nbsp;Please provide all required information before submitting!","error");
				$this->redirect("{$this->prefix}/model/");
			}
			// Actually create the object
			$m = $this->getModel();
			
			// Validate that the object does not yet exist
			if (false !== $m->objects[$objectType]) {
			    $this->flash("<strong>Error!</strong> &nbsp;An object of type <code>{$objectType}</code> already exists!","error");
			    $this->redirect("{$this->prefix}/model/");
			}
			$newObject = new FObj($objectType);
			$newObject->setParentClass($objectParent);
			$newObject->setDescription($objectDesc);
			$newObject->setTable(new FSqlTable($m->getPrimaryDatabaseName(),$objectTableName));
			$m->saveObject($newObject,true);
			
			die(); //-------------------
			
			// Add the required SQL table
			$tableName = FModel::standardizeTableName($objectType);
			$m->tables[$tableName] = new FSqlTable($objectType);
			
			// If the object extends FAccount, add the 'faccount_id' column
			if ("FAccount" == $objectParent) {
				$extra = array("min"=>0);
				$col   = new FSqlColumn(
					"faccount_id",
					FSqlColumn::convertToSqlType("integer",$extra),
					false,
					false,
					"Link to account details for this {$objectType}");
				
				$m->tables[$tableName]->addColumn($col,$extra);
			}
			
			// Write the changes to the model file
			$this->writeModelFile($m->export());
			
			// Execute SQL commands
			
			// If the object is derived from FAccount:
			if ("FAccount" == $objectParent) {
				
				// Verify that `app_accounts` and `app_roles` tables exist in the db
				$results = _db()->query("SHOW TABLES");
				$foundAppAccounts = false;
				$foundAppRoles    = false;
				while ($r = $results->fetchRow()) {
					if ("app_accounts" == $r[0]) {
						$foundAppAccounts = true;
					} else if ("app_roles" == $r[0]) {
						$foundAppRoles    = true;
					}
					if ($foundAppRoles && $foundAppAccounts) {
						break;
					}
				}
				
				// If they do not, create them:
				if (!$foundAppAccounts) {
					$appAccountsSql = <<<END
-- 
-- Table structure for table `app_accounts`
-- 

CREATE TABLE `app_account` (
  `account_id`          int(11) unsigned NOT NULL auto_increment COMMENT 'The unique id of this object in the database',
  `username`       varchar(20) NOT NULL COMMENT 'The username associated with this account',
  `password`       varchar(160) NOT NULL COMMENT 'The password for the account',
  `emailAddress`   varchar(80) NOT NULL COMMENT 'The email address associated with this account',
  `status`         varchar(20) NOT NULL COMMENT 'The status of this account',
  `secretQuestion` varchar(160) NOT NULL COMMENT 'The secret question for access to this account',
  `secretAnswer`   varchar(160) NOT NULL COMMENT 'The secret answer for the secret question',
  `objectClass`    varchar(50) NOT NULL COMMENT 'The class of the primary object associated with this account',
  `objectId`       int(11) unsigned NOT NULL COMMENT 'The id of the primary object associated with this account',
  `created`        datetime NOT NULL COMMENT 'When this account was created',
  `modified`       datetime NOT NULL COMMENT 'When this account was last modified',
  `lastLogin`      datetime NOT NULL COMMENT 'The last time this account logged in',
  `newPasswordKey` varchar(25) NOT NULL COMMENT 'A key for verifying forgot password attempts',
  PRIMARY KEY  (`account_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 COMMENT='table for application accounts' ;
END;
					_db()->exec($appAccountsSql);
					$this->flash("Created required `app_account` table");
				}
				if (!$foundAppRoles) {
					$appRolesSql = <<<END
-- 
-- Table structure for table `app_roles`
-- 

CREATE TABLE `app_role` (
  `account_id` int(10) unsigned NOT NULL,
  PRIMARY KEY  (`accountId`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='permissions table for application accounts';
END;
					_db()->exec($appRolesSql);
					$this->flash("Created required `app_role` table");
				}
			}
			_db()->exec($m->tables[$tableName]->toSqlString());
			
			
			// Regenerate PHP code
			$this->generateObjects();
			
			
			// Redirect to the new object's edit page
			$this->redirect("{$this->prefix}/model");
		}
	}*/
	
	
    public function addGenericAttribute() {
		if ($this->form) {
			
			$this->init();
			
			$objectClass = $this->form['objectClass'];
			
			$data = array("desc" => $this->form['attrDescription'],
				"type" => $this->form['attrType'],
				"size" => $this->form['attrSize'],
				"min"  => $this->form['attrMin'],
				"max"  => $this->form['attrMax'],
				"default" => $this->form['attrDefault'],
				"unique"  => (isset($this->form['attrUnique']) ? true : false)
			);
			$attr = new FObjAttr(FModel::standardizeAttributeName($this->form['attrName']),$data);

			
			// Prepare extra information about the attribute
			$columnExtraData = array(
				'size' => $attr->getSize(),
				'min'  => $attr->getMin(),
				'max'  => $attr->getMax());
				
			// Create an FSqlColumn instance for the attribute
			$column = new FSqlColumn(
				$attr->getName(),									/* name */
				FSqlColumn::convertToSqlType($attr->getType(),$columnExtraData), /* type */
				false,												/* null */
				false,												/* autoinc */
				$attr->getDescription());							/* description */

			// Handle uniqueness
			if ($attr->isUnique()) {
				$column->setKey("UNIQUE");
			}
			
			// Handle default value
			if (false === $attr->getDefaultValue()) {
				$column->setDefaultValue('0');	
			} else if (true === $attr->getDefaultValue()) {
				$column->setDefaultValue('1');
			} else {
				$column->setDefaultValue($attr->getDefaultValue());	
			}
			
			
			$m = $this->getModel();
			if (!isset($m->objects[$objectClass])) {
				die("Object '{$objectClass}' is not defined in the model.");
			}
			
			// Add the attribute and the column to the model
			$m->objects[$objectClass]->addAttribute($attr);
			$m->tables[FModel::standardizeTableName($objectClass)]->addColumn($column);
			
			// Write the changes to the model file
			$this->writeModelFile($m->export());
			
			// Execute SQL commands
			$tableName = FModel::standardizeTableName($objectClass);
			try {
				_db()->exec("ALTER TABLE `{$tableName}` ADD COLUMN {$column->toSqlString()}");
				if ($attr->isUnique()) {
					_db()->exec("ALTER TABLE `{$tableName}` ADD UNIQUE (`{$attr->getName()}`) ");
				}
			} catch (FDatabaseException $e) {
				die($e->__toString());	
			}
			
			// Regenerate PHP code
			$this->generateObjects();
					
			// Redirect to the edit page
			$this->flash("Added attribute '{$attr->getName()}' to object '{$objectClass}'");
			$this->redirect("{$this->prefix}/model/editObject/{$objectClass}");
		}
	}
	
    public function editField($ot='',$attr='') {
		$this->init();
		$this->extensionAddStylesheet('org.frameworkers.fuel','/pages/model/editField/editField.css');
		if ($this->form) {
			
		} else {
			$objectType = FModel::standardizeName($ot);
			$attrName   = FModel::standardizeAttributeName($attr);
			
			if ($objectType == '' || $attr='') {
				die("Not enough information provided.");
			}
			
			$m = $this->getModel();
			if (!isset($m->objects[$objectType])) {
				die("Object '{$objectType}' is not defined in the model.");
			}
			
			$attribute = false;
			foreach ($m->objects[$objectType]->getAttributes() as $a) {
				if ($a->getName() == $attrName) {
					$attribute = $a;
				}
			}
			
			if (! $attribute) {
				die("Attribute {$attrName} is not defined in object {$objectType}.");
			}
			// Process allowed value validation data (if any)
		    $attributeData = _model()->$objectType->attributeInfo($attrName);
			if (isset($attributeData['allowedValues'])) {
				$txt = '';
				foreach ($attributeData['allowedValues'] as $av) {
					$txt .= stripslashes($av['value'])."|".stripslashes($av['label'])."\r\n";
				}
				$this->set('allowedValuesData',$txt);
			}
			
			
			$this->set('object',$m->objects[$objectType]);
			$this->set('attr',$attribute);
		}
	}
	
    public function editAttribute() {
		if ($this->form) {

			// INITIAL SETUP
			$this->init();
			$objectType = FModel::standardizeName($this->form['objectType']);
			
			$m = $this->getModel();
			if (!isset($m->objects[$objectType]) ) {
				die("Object '{$objectType}' is not defined in the model.");
			}
			
			$attribute = false;
			$attributes =& $m->objects[$objectType]->getAttributes();
			for ($i =0; $i < count($attributes); $i++) {
				if ($attributes[$i]->getName() == $this->form['attrName']) {
					$attribute =& $attributes[$i];
					break;
				}
			}
			
			if (! $attribute) {
				die("Attribute {$this->form['attrName']} is not defined in object {$this->form['objectType']}.");
			}
			
			
			$column =& $m->tables[FModel::standardizeTableName($objectType)]->getColumn($this->form['attrName']);
			
			
			if ($this->form['action'] == "rename") {
				// RENAME AN ATTRIBUTE
				$tableName = FModel::standardizeTableName($objectType);
				$columnOldName = $column->getName();
				$newName = FModel::standardizeAttributeName($this->form['attrNewName']);
				$attribute->setName($newName);
				$column->setName($newName);

				try {
					$tableName = FModel::standardizeTableName($objectType);
					$query = "ALTER TABLE `{$tableName}` CHANGE COLUMN `{$columnOldName}` {$column->toSqlString()}";
					_db()->exec($query);
				} catch (FDatabaseException $e) {
					die($e->__toString());	
				}
		
				$this->flash("Renamed attribute");
			}
			
			if ($this->form['action'] == "setDefaultValue") {
			    // SET THE DEFAULT VALUE FOR AN ATTRIBUTE
			    $tableName = FModel::standardizeTableName($objectType);
			    $attribute->setDefaultValue($this->form['attrNewDefaultValue']);
			    $column->setDefaultValue($this->form['attrNewDefaultValue']);
			    try {
			        $query = "ALTER TABLE `{$tableName}` CHANGE COLUMN `{$column->getName()}` {$column->toSqlString()}";
			        _db()->exec($query);
			    } catch (FDatabaseException $e) {
			        echo $e;
			        exit();
			    }
			}
			
			if ($this->form['action'] == "validation") {
				
				// Process selected validation options
				$validationData = array();
				if (isset($this->form['optionFormat'])) {
					$validationData['format'] = array(
						'pattern' => (isset($this->form['formatPattern'])? $this->form['formatPattern'] : null),
						'negate'  => (isset($this->form['formatNegate']) ? true : false)
					);
				}
				if (isset($this->form['optionNumeric'])) {
					$validationData['numeric'] = array(
						'is' => (isset($this->form['numericIs'])? $this->form['numericIs'] : null),
						'min'=> (isset($this->form['numericMin'])? $this->form['numericMin'] : null),
						'max'=> (isset($this->form['numericMax'])? $this->form['numericMax'] : null)
					);
				}
				if (isset($this->form['optionLength'])) {
					$validationData['length'] = array(
						'is' => (isset($this->form['lengthIs'])? $this->form['lengthIs'] : null),
						'min'=> (isset($this->form['lengthMin'])? $this->form['lengthMin'] : null),
						'max'=> (isset($this->form['lengthMax'])? $this->form['lengthMax'] : null)
					);
				}
				if (isset($this->form['optionAllowedValues'])) {
					$validationData['allowedValues'] = array();
					$values = explode("\r\n",$this->form['allowedValues']);
					foreach ($values as $value) {
						list($v,$l) = explode('|',$value);
						if (!empty($l)) {
							$validationData['allowedValues'][] = array('value'=>trim(stripslashes($v)),'label'=>trim(stripslashes($l)));
						}
					}
				}
				
				// Store the validation data in the attribute
				$attribute->setValidation($validationData);	
				$this->flash("Set validation criteria for attribute");		
			}
			
			// Write changes to the model file
			$this->writeModelFile($m->export());
			$this->generateObjects();
			$this->redirect("{$this->prefix}/model/editObject/{$objectType}");
		}
	}
	
	public function deleteAttribute($objectClass,$attributeName) {
		$this->init();
		$tableName = FModel::standardizeTableName($objectClass);
		$m = $this->getModel();
		if (!isset($m->objects[$objectClass])) {
			die("Object '{$objectClass}' is not defined in the model.");
		}
		$object = $m->objects[$objectClass];
		if ($object->deleteAttribute($attributeName)) {
			// Write the changes to the model file
			$this->writeModelFile($m->export());
			
			// Execute SQL commands
			try {
				_db()->exec("ALTER TABLE `{$tableName}` DROP COLUMN `{$attributeName}` ");
				_db()->exec("ALTER TABLE `{$tableName}` DROP INDEX  `{$attributeName}` ");
			} catch (FDatabaseException $e) {
				// silently ignore
			}
			
			// Regenerate PHP code
			$this->generateObjects();
			
			// Redirect to the new object's edit page
			$this->flash("Deleted attribute '{$attributeName}'");
			$this->redirect("{$this->prefix}/model/editObject/{$objectClass}");
		} else {
			// Warn of the failure
			$this->flash("Delete failed: attribute '{$attributeName}' does not exist for object of type '{$objectClass}'.","error");
			$this->redirect("{$this->prefix}/model/editObject/{$objectClass}");
		}
	}
	
    public function addModelSpecificAttribute() {
		if ($this->form) {
			
			// Determine how to process this request, based on the multiplicity specified
			switch ($this->form['attrMultiple']) {
				case "M1":
					$this->addParent();
					exit();
				case "MM":
					$this->addPeer();
					exit();
				default:
					die("<b>FUEL:</b> Unknown value {$this->form['attrMultiple']} provided for `attrMultiple` ");	
			}
			
		}
	}
	
    public function addParent() {
		if ($this->form) {
			
			$this->init();
			$m = $this->getModel();
			if (!isset($m->objects[$this->form['objectClass']]) ) {
				die("Object '{$objectClass}' is not defined in the model.");
			}
			if (!isset($m->objects[$this->form['dependingClass'] ]) ) {
				die("Object '{$dependingClass}' is not defined in the model.");
			}
			$localObject =& $m->objects[$this->form['objectClass'] ];
		
			// Create a socket to service this dependency. This allows a child
			// object to call upon its parent
			$data = array(
				"desc"     => $this->form['description'],
				"reflects" => $this->form['matchVariable'],
				"required" => (isset($this->form['attrOptional']) ? false : true)
			); 
			$s = new FObjSocket(
				$this->form['socketName'],
				$this->form['objectClass'],
				$this->form['dependingClass'],$data);
			$localObject->addParent($s);
			
			$columnDescription = $s->getDescription();
			
			// Create the reflecting socket
			$foreignObject = $m->objects[$this->form['dependingClass'] ];
			$data = array(
				"desc"     => 'Auto generated reflection.',
				"reflects" => $this->form['socketName']
			);
			$s = new FObjSocket(FModel::standardizeAttributeName(
				$this->form['matchVariable']),
				$this->form['dependingClass'],
				$this->form['objectClass'],$data);
			$foreignObject->addChild($s);
	
				
			// SQL modifications here...
			$columnExtraData = array('min' => 0);
				
			$column = new FSqlColumn(
				FModel::standardizeAttributeName($this->form['socketName'])."_id",	/* name */
				'INT(11) UNSIGNED', 								/* type */
				false,												/* null */
				false,												/* autoinc */
				$columnDescription);								/* description */

			// Write the changes to the model file
			$this->writeModelFile($m->export());
			
			// Execute SQL commands
			try {
				$objectName = FModel::standardizeName($this->form['objectClass']);
				$tableName  = FModel::standardizeTableName($objectName);
				$q = "ALTER TABLE `{$tableName}` "
					."ADD COLUMN {$column->toSqlString()} AFTER `{$tableName}_id`";
				_db()->exec($q);
			} catch (FDatabaseException $e) {
				die($e->__toString());	
			}
			
			// Regenerate PHP code
			$this->generateObjects();
			
			if (isset($this->form['attrOptional'])) {
				$this->flash("Added relationship between '{$this->form['dependingClass']} and '{$this->form['objectClass']}' ");
			} else {
				$this->flash("Added dependency on '{$this->form['dependingClass']}' to '{$this->form['objectClass']}' ");
			}
			$this->redirect("{$this->prefix}/model/editObject/{$this->form['objectClass']}");
		}
	}
	
    public function addPeer() {
		if ($this->form) {
			$this->init();
			$m = $this->getModel();
			if (!isset($m->objects[$this->form['objectClass'] ])) {
				die("Object '{$objectClass}' is not defined in the model.");
			}
			if (!isset($m->objects[$this->form['dependingClass'] ])) {
				die("Object '{$objectClass}' is not defined in the model.");
			}
			$localObject   =& $m->objects[$this->form['objectClass'] ];
			$foreignObject =& $m->objects[$this->form['dependingClass'] ];
			
			// Create a socket to service this dependency. 
			$data = array(
				"desc"     => $this->form['description'],
				"reflects" => $this->form['matchVariable']
			);
			$s = new FObjSocket(
				$this->form['socketName'],
				$this->form['objectClass'],
				$this->form['dependingClass'],$data);
			$localObject->addPeer($s);		

			// Create a socket for the foreign object, only if the 
			// local object and foreign object are different types
			if ($localObject->getName() != $foreignObject->getName()) {
				$data = array(
					"desc"     => "Auto generated reflection.",
					"reflects" => $this->form['socketName']
				);
				$s = new FObjSocket(
					$this->form['matchVariable'],
					$this->form['dependingClass'],
					$this->form['objectClass'],$data);
				$foreignObject->addPeer($s);
			}
			
			
			// SQL modifications here...
			$lt = new FSqlTable($localObject->getPeer($this->form['socketName'])->getLookupTable(),true);
			$lc_pk1name = FModel::standardizeAttributeName($localObject->getName());
			$lc_pk2name = FModel::standardizeAttributeName($this->form['dependingClass']);
			
			if ($lc_pk1name == $lc_pk2name) {
				$lc_pk1name .= "1";
				$lc_pk2name .= "2";
			}
			
			$c1 = new FSqlColumn("{$lc_pk1name}_id","INT(11) UNSIGNED");
			$c1->setKey("PRIMARY");
			$c2 = new FSqlColumn("{$lc_pk2name}_id","INT(11) UNSIGNED");
			$c2->setKey("PRIMARY");
			$lt->addColumn($c1);
			$lt->addColumn($c2);
			
			$m->tables[$lt->getName()] = $lt;
			
			// Execute SQL Commands
			try {
				_db()->exec($lt->toSqlString()); 	
			} catch (FDatabaseException $e) {
				die($e->__toString());	
			}							

			// Write the changes to the model file
			$this->writeModelFile($m->export());
			
			// Regenerate PHP code
			$this->generateObjects();

			$this->flash("Added peer relationship between '{$this->form['dependingClass']}' and '{$this->form['objectClass']}' ");
			$this->redirect("{$this->prefix}/model/editObject/{$this->form['objectClass']}");
		}
	}
	
    public function editDependency() {
		
	}
	
	public function deleteDependency($objectClass,$foreignClass,$localAttribute,$foreignAttribute='') {
		$this->init();
		$m = $this->getModel();
		if (!isset($m->objects[$objectClass])) {
			die("Object '{$objectClass}' is not defined in the model.");
		}
		if (!isset($m->objects[$foreignClass])) {
			die("Object '{$foreignClass}' is not defined in the model.");
		}
		$localObject   =& $m->objects[$objectClass];
		$foreignObject =& $m->objects[$foreignClass];
		$parent   = $localObject->getParent($localAttribute);
		if (!$parent) {
			die("Object '{$objectClass}' has no parent relationship with '{$foreignClass}' named '{$localAttribute}'");
		}
		$reflects = $parent->doesReflect();

		// Try to delete the local socket
		if ($localObject->deleteParent($localAttribute) && $reflects) {
			
			// If that worked, try to delete the remote socket
			if ($foreignObject->deleteChild($foreignAttribute)) {
				
			} 
			
			// Write the changes to the model file
			$this->writeModelFile($m->export());
			
			// Execute SQL commands
			try {
				$objectName = $localObject->getName();
				$tableName  = FModel::standardizeTableName($objectName);
				$q = "ALTER TABLE `{$tableName}` DROP COLUMN `"
					. FModel::standardizeAttributeName($localAttribute)
					. "_id` ";
				_db()->exec($q);
			} catch (FDatabaseException $e) {
				die($e->__toString());	
			}
		
			// Regenerate PHP code
			$this->generateObjects();
			
			$this->flash("Deleted dependency on '{$foreignClass}' by '{$objectClass}' ");
		} else {
			$this->flash("Could not delete dependency on '{$foreignClass}' by '{$objectClass}' ","error");
		}
		$this->redirect("{$this->prefix}/model/editObject/{$objectClass}");
	}
	
    public function deleteMMRelationship($objectClass,$foreignClass,$localAttribute,$foreignAttribute='') {
		$this->init();
		$m = $this->getModel();
		if (!isset($m->objects[$objectClass])) {
			die("Object '{$objectClass}' is not defined in the model.");
		}
		if (!isset($m->objects[$foreignClass])) {
			die("Object '{$foreignClass}' is not defined in the model.");
		}
		$localObject   =& $m->objects[$objectClass];
		$foreignObject =& $m->objects[$foreignClass];
		
		$localPeer   = $localObject->getPeer($localAttribute);
		$reflects    = $localPeer->doesReflect();
		$lookupTable = $localPeer->getLookupTable();
		
		// Try to delete the local socket
		if ($localObject->deletePeer($localAttribute)) {
			// If that worked, try to delete the reflected socket
			if ($foreignObject->deletePeer($foreignAttribute) && $reflects) {
				
			}
			// Delete the SQL table
			try {
				$tableName  = FModel::standardizeTableName($lookupTable,true);
				$q = "DROP TABLE `{$tableName}`";
				_db()->exec($q);
			} catch (FDatabaseException $e) {
				die($e->__toString());	
			}
			
			// Write the changes to the model file
			$this->writeModelFile($m->export());
			
			// Regenerate PHP code
			$this->generateObjects();
	
			$this->flash("Deleted M-M relationship '{$localAttribute}' between '{$objectClass}', '{$foreignClass}' ");
		} else {
			$this->flash("Unable to delete M-M relationship '{$localAttribute}' between '{$objectClass}' and '{$foreignClass}' ","error");
		}
		
		$this->redirect("{$this->prefix}/model/editObject/{$objectClass}");
	}
	
    public function deleteObject($which) {
		$logMessage = '<ul>';
		$name = $which;
			
		$this->init();
		$m = $this->getModel();
		if (!isset($m->objects[$name])) {
			die("Object '{$name}' is not defined in the model.");
		}
		
		$object =& $m->objects[$name];
		$objectName = $object->getName();
		
		// Delete any children
		foreach ($object->getChildren() as $child) {
			// Delete from the database
			try {
				$foreignName = $child->getForeign();
				$tableName   = FModel::standardizeTableName($foreignName);
				$q = "ALTER TABLE `{$tableName}` DROP COLUMN `"
					. $child->getReflectVariable()
					. "_id` ";
				_db()->exec($q);
			} catch (FDatabaseException $e) {
				die($e->__toString());	
			}
			
			// Delete socket AND reflection from the model
			$localObj    = $child->getOwner();
			$foreignObj  = $child->getForeign();
			$foreignAttr = $child->getReflectVariable();
			
			$logMessage .= "<li>deleting {$child->getOwner()}::{$child->getName()}</li>";
			$object->deleteChild($child->getName());
			$logMessage .= "<li>deleting {$m->objects[$foreignObj]->getName()}::{$foreignAttr}</li>";
			$m->objects[$foreignObj]->deleteParent($foreignAttr);
		}

		// Delete any parents
		foreach ($object->getParents() as $parent) {
			
			// Delete from the database
			try {
				$tableName  = FModel::standardizeTableName($objectName);
				$q = "ALTER TABLE `{$tableName}` DROP COLUMN `"
					. $parent->getName()
					. "_id` ";
				_db()->exec($q);
			} catch (FDatabaseException $e) {
				die($e->__toString());	
			}
			
			// Delete socket AND reflection from the model
			$localObj    = $parent->getOwner();
			$foreignObj  = $parent->getForeign();
			$foreignAttr = $parent->getReflectVariable();
			
			$logMessage .=  "<li>deleting {$parent->getOwner()}::{$parent->getName()}</li>";
			$object->deleteParent($parent->getName());
			$logMessage .=  "<li>deleting {$m->objects[$foreignObj]->getName()}::{$foreignAttr}</li>";
			$m->objects[$foreignObj]->deleteChild($foreignAttr);
			
		}

		// Delete any peers
		foreach ($object->getPeers() as $peer) {
			
			// Delete from the database
			try {
				$q = "DROP TABLE `{$peer->getLookupTable()}`";
				_db()->exec($q);
			} catch (FDatabaseException $e) {
				die($e->__toString());	
			}
			
			// Delete socket AND reflection from the model
			$localObj    = $peer->getOwner();
			$foreignObj  = $peer->getForeign();
			$foreignAttr = $peer->getReflectVariable();
			
			$logMessage .= "<li>deleting {$peer->getOwner()}::{$peer->getName()}</li>";
			$object->deletePeer($peer->getName());
			if ($localObj != $foreignObj) {
				$logMessage .= "<li>deleting {$m->objects[$foreignObj]->getName()}::{$foreignAttr}</li>";
				$m->objects[$foreignObj]->deletePeer($foreignAttr);
			}
		}
		
		// Drop the object table
		$logMessage .= "<li>DELETING OBJECT: '{$name}' from the model</li></ul>";
		$tableName   = FModel::standardizeTableName($objectName);
		$q = "DROP TABLE `{$tableName}` ";
		_db()->exec($q);
		
		// Delete the object from the model
		unset($m->objects[$name]);
		
		// Write the changes to the model file
		$this->writeModelFile($m->export());
		
		// Regenerate PHP code
		$this->generateObjects();

		$this->flash("Deleted object `{$name}` from the model.<br/><h4>Log:</h4>{$logMessage}");
		$this->redirect("{$this->prefix}/model/");
	}
    
}
?>