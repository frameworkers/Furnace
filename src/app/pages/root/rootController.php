<?php
class rootController extends Controller {
    
    // Application home (index) page. If the default routing table
    // (see: /app/config/routes.yml) is unchanged, this function will be
    // invoked on requests for '/', aka the home page.
    public function index() {
        
        //
        // TODO: prepare data to be passed to the corresponding view
        //

    }
    
    
    public function login() {
    	$afterLogin = isset($_SESSION['afterLogin'])
    		? $_SESSION['afterLogin']
    		: '/';
        $this->loadWidget('org.frameworkers','LoginBox');
        $lb = new LoginBox($this,$afterLogin);
        $this->set('loginBox',$lb->render());
    }
    
    public function logout() {
        FSessionManager::doLogout();
        $this->redirect("/");
    }
}

?>
