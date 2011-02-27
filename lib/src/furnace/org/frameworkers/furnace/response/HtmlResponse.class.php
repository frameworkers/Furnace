<?php
namespace org\frameworkers\furnace\response;

use org\frameworkers\furnace\core\StaticObject;
use org\frameworkers\furnace\config\Config;
use org\frameworkers\furnace\request\Request;
use org\frameworkers\furnace\response\Response;


class HtmlResponse extends Response {
	
	public $themePath;
	
	public $layoutFilePath;
	
	public $viewFilePaths;
	
	public $fileExtension;
	
	public $includedViews;
	
	public $notifications;
	
	public $rawJS;
	
	
	public function __construct( $context ) {
		
		// Store the context
		$this->context = $context;
		
		// Store the extension
		$this->fileExtension = Config::Get('htmlViewFileExtension');
		
		// Default theme path
		$this->themePath = 
			 Config::Get('applicationThemesDirectory') . '/'
			.Config::Get('theme');
		
		// Default layout file path
		$this->layoutFilePath = 
			$this->themePath . '/layouts/default.html';
			
		// Default view file 
		$this->setView($context->handlerName . $this->fileExtension);
			
		$this->includedViews = array();
		
		$this->notifications = isset($_SESSION['_notifications'])
			? $_SESSION['_notifications']
			: array();
	}
	
	public function render( ) {
		
		// Determine the render engine for the response
		$renderEngine    = ResponseTypes::EngineFor('html');
		
		// Create a renderer for the response
		$renderer        = new $renderEngine( $this );
		
		// Get the layout file as the outermost document
		if (!file_exists($this->layoutFilePath)) {
			$this->abort(
				"Unable to find layout file at: "
				.$this->layoutFilePath);
		}
		$document = file_get_contents($this->layoutFilePath);
		if(empty($document)) { $document = '[_content_]'; }
		
		// Insert any included views
		foreach ($this->includedViews as $ivLabel => $ivContents) {
			// Replace the zone tag with the included contents
			$document = str_replace("[_{$ivLabel}_]", $ivContents, $document);
		}
				
		// Process each defined zone
		foreach ($this->local_data as $zoneName => $zoneLocals) {
			// Get zone content
			if (!file_exists($this->viewFilePaths[$zoneName])) {
				$this->abort(
					"Unable to find view file for zone `{$zoneName}` at: "
					.$this->viewFilePaths[$zoneName]);
			}
			$content  = file_get_contents($this->viewFilePaths[$zoneName]);
			
			// Incorporate any `flash` messages into the local content
			$zoneLocals['_notifications'] = (isset($_SESSION['_notifications'][$zoneName]))
				? implode("\r\n",$_SESSION['_notifications'][$zoneName])
				: "";
				
			// Compile the zone
			$compiled = $renderer->compile( $content, $this->context, $zoneLocals);

			// Replace the zone tag with the compiled contents
			$document = str_replace("[_{$zoneName}_]", $compiled, $document);
		}
		
		// Final pass to catch any tags outside of defined zones
		$document = $renderer->compile( $document, $this->context, array());
		
		// Reset the stored `flash` messages container
		$_SESSION['_notifications'] = array();
		
		// Return the final, compiled response
		return $document;		
	}
		
	public function setLayout($layoutFilename) {
		return $this->layoutFilePath = 
			"{$this->themePath}/layouts/{$layoutFilename}";
	}
	
	public function setView($viewFilename,$zone = 'content') {
		$path = Config::Get('applicationViewsDirectory') 
			.(($this->context->controllerBaseName == 'default')
				? '/'
				: "/{$this->context->controllerBaseName}/");
				
		$extension = Config::Get('htmlViewFileExtension');
		$localBase = basename($viewFilename,$extension);
		
		if (is_dir($path . '/' . $localBase)) {
			$path .= "{$localBase}/";
		}
		
		$finalPath = $path . $viewFilename;
		
		$this->viewFilePaths[$zone] = $finalPath;
		return $finalPath;
	}
	
	public function setTheme($theme) {
		$this->themePath = Config::Get('applicationThemesDirectory')."/{$theme}";
		$this->context->urls['theme_base'] = 
			Config::Get('applicationUrlBase')."/themes/{$theme}";
	}
	
	/**
	 * If the file to include is part of a theme, then `$bLocal` is false
	 * (the default) and `$path` represents the portion of the path to the
	 * file starting from the theme's `js` directory. Examples:
	 *    $this->includeJavascript('five.js');      //-> [THEME_ROOT]/js/five.js
	 *    $this->includeJavascript('foo/seven.js'); //-> [THEME_ROOT]/js/foo/seven.js
	 * 
	 * If, on the other hand, the file to include is an asset local to a 
	 * particular view (somewhere in the application views directory heirarchy), then 
	 * `$bLocal` should be set to true and `$path` represents the portion of the path
	 * to the file starting from the application views directory. Examples:
	 *   // assume current view file is     /views/foo/index/index.html
	 *   // and there is a local js file at /views/foo/index/index.js
	 *   $this->includeJavascript('/foo/index/index.js',true);
	 * 
	 * @param string  $path   - The path to the file to include, relative either to
	 *                          the current theme (if $bLocal is false) or to the
	 *                          application views directory (if $bLocal is true)
	 * @param boolean $bLocal - Whether or not to look in the theme or the application
	 *                          views directory tree for this file. Default value is
	 *                          false, meaning look for the file in the current theme.
	 */
	public function includeJavascript($path,$bLocal = false) {
		if ($bLocal) { // File is somewhere in the application views hierarchy
			$url = "{$this->context->urls['view_base']}/" . ltrim($path,'/');
		} else {       // File is part of the current theme
			$url = "{$this->context->urls['theme_base']}/js/" . ltrim($path,'/');
		}
		// Add the file to the array of javascripts to include in the context
		$this->javascripts[$url] = 
				'<script type="text/javascript" src="'.$url.'"></script>';
	}
	
	public function includeStylesheet($path,$bLocal = false, $condition = null) {
		if ($bLocal) { // File is somewhere in the application views hierarchy
			$url = "{$this->context->urls['view_base']}/" . ltrim($path,'/');
		} else {       // File is part of the current theme
			$url = "{$this->context->urls['theme_base']}/css/" . ltrim($path,'/');
		}
		// Build a CSS snippet to insert
		$snippet = "<link rel=\"stylesheet\" type=\"text/css\" href=\"{$url}\">";
		if ($condition !== null) {
			$snippet .= "<!--[if {$condition}]>{$snippet}<![endif]-->";		
		}
		
		// Add the snippet to the array of stylesheets to include in the context
		$this->stylesheets[$url] = $snippet;
	}
	
	public function includeView(array $which, $zoneLabel, $type='html') {
		if (count($which) != 2 && count($which) != 3) { die('unsupported use of includeView'); }
		$controllerClassName = $which[0];
		$handlerName         = $which[1];
		$args                = isset($which[2]) ? $which[2] : array();
		
		// The array contains a controller and a view. Create a context 
		// object from this information
		$context = Request::CreateFromControllerAndHandler(
			$controllerClassName, $handlerName, $type, $args);

		// Execute the request and store the response
		$response = Response::Create( $context );
		
		// Store the request in the appropriate zone
		$this->includedViews[$zoneLabel] = $response;
	}
	
	public function getIncludedJavascripts() {
		$str = implode("\r\n",$this->javascripts);
		$str .= "\r\n\t<script type='text/javascript'>\r\n";
		$str .= "\t\tvar _context = " . StaticObject::toJsonString($this->context) . "\r\n";
		$str .= "\t\tvar _local   = " . StaticObject::toJsonString($this->local_data);
		$str .= "\r\n\t</script>\r\n";
		
		return $str;
	}
	
	public function getIncludedStylesheets() {
		return implode("\r\n",$this->stylesheets);
	}
	
	public function getNotifications() {
		$str = '';
		foreach ($this->notifications as $zoneMessages) {
			$str = implode("\r\n",$zoneMessages);
		}
		return $str;
	}
	
	public function set($key,$val,$zone = 'content') {
		$this->local_data[$zone][$key] = $val;
	}
	
	protected function flash($message,$title,$cssClass = "notify_info",$zone = 'content') {
		$title   = ($title == '') ? '' : "<h5>{$title}</h5>";
		$message = "<p>{$message}</p>";
		 
		$_SESSION['_notifications'][$zone][] = 
			"<div class='ff_notify {$cssClass}'>{$title}{$message}</div>";
	}

	public function success($message,$title = 'Success!',$zone = 'content') {
		$this->flash($message,$title,"notify_success",$zone);
	}
	
	public function warn($message,$title = 'Warning:',$zone = 'content') {
		$this->flash($message,$title,"notify_warn",$zone);
	}
	
	public function error($message,$title = 'An Error Occurred:',$zone = 'content') {
		$this->flash($message,$title,"notify_error",$zone);
	}
	
	public function info($message,$title = 'Information:',$zone = 'content') {
		$this->flash($message,$title,"notify_info",$zone);
	}
	
}