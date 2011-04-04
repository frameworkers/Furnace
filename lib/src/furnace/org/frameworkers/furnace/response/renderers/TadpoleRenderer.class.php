<?php
namespace org\frameworkers\furnace\response\renderers;

use org\frameworkers\furnace\interfaces\IAuthExtension;

use org\frameworkers\furnace\auth\Auth;
use org\frameworkers\furnace\config\Config;


use org\frameworkers\furnace\response\RenderEngine;
use vendors\org\crawwler\tadpole\TadpoleEngine;

class TadpoleRenderer extends RenderEngine {
	
	protected $tp;
	
	public function __construct( &$response ) {
		
		$this->response = $response;
		$this->tp = new TadpoleEngine();
		
	}
	
	public function compile( $content, $context, $locals ) {		

		$this->reset();
		
		$this->tp->page_data = $locals;
		
		if (Auth::Get()->getStatus() == IAuthExtension::AUTHENTICATED) {
			$this->tp->set('_user', Auth::Get());
		} else {
			$this->tp->set('_user', false);
		}
		$this->tp->set('_config',   Config::Get('*'));
		$this->tp->set('_context',  $context);
		$this->tp->set('_response', $this->response);
		$this->tp->set('%a',    $context->urls['url_base']);
		$this->tp->set('%theme',$context->urls['theme_base']);
		$this->tp->set('%view', $context->urls['view_base']);
			
		return $this->tp->compile($content);				
	}	

	public function reset() {
		$this->tp->reset();
	}
}