<?php
jimport('joomla.plugin.plugin');
class plgSystemHikapi extends JPlugin {

	/**
	 * Plugin constructor
	 * Initialize the plugin parameters if required.
	 */
	public function __construct(&$subject, $config) {
		parent::__construct($subject, $config);
		if(isset($this->params))
			return;

		$plugin = JPluginHelper::getPlugin('system', 'hikapi');
		if(version_compare(JVERSION,'2.5','<')) {
			jimport('joomla.html.parameter');
			$this->params = new JParameter(@$plugin->params);
		} else {
			$this->params = new JRegistry(@$plugin->params);
		}
	}

	/**
	 * Trigger redirection
	 */
	public function afterInitialise() {
		return $this->onAfterInitialise();
	}

	/**
	 * Trigger redirection
	 */
	public function afterRoute() {
		return $this->onAfterRoute();
	}

	/**
	 * Joomla Trigger : On After Initialise
	 */
	public function onAfterInitialise() {
		$app = JFactory::getApplication();
		if($app->isAdmin())
			return;

		// By default we process during the After Initialise.
		// If the option is deactivate, we exit the function so the "After Route" will check it.
		//
		if(!$this->params->get('after_init', 1))
			return;

		$this->process();
	}

	/**
	 * Joomla Trigger : On After Route
	 */
	public function onAfterRoute() {
		$app = JFactory::getApplication();
		if($app->isAdmin())
			return;

		// By default we process during the After Initialise.
		// If the option is activate, it means that it has been already processed so we do not need to do it twice !
		//
		if($this->params->get('after_init', 1))
			return;

		$this->process();
	}

	/**
	 * Processing function
	 */
	protected function process() {
		$uri = clone JUri::getInstance();
		$path = $uri->getPath();
		$base = JUri::base(true);

		// Retrive the called URL
		//
		$url = $path;
		if(strpos($url, $base) !== 0)
			return;

		$url = ltrim(substr($url, strlen($base)), '/');
		if(strpos($url, 'index.php') === 0)
			$url = ltrim(substr($url, strlen('index.php')), '/');

		// Check if the URL is for the API
		//
		$apiStart = $this->params->get('api_start', 'hikapi');
		if(empty($apiStart))
			$apiStart = 'hikapi';

		if(strpos($url, $apiStart.'/') !== 0)
			return;

		// Get the API call URL
		$call_url = '/' . ltrim(substr($url, strlen($apiStart.'/')), '/');

		// Include the API Helper (for potential future extends)
		require_once( dirname(__FILE__).DIRECTORY_SEPARATOR.'hikapihelper.php' );

		// If HikaShop is installed, we use the HikaShop API Helper (which extends from HikAPIHelper)
		$hikashopHelper = rtrim(JPATH_ADMINISTRATOR,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'components'.DIRECTORY_SEPARATOR.'com_hikashop'.DIRECTORY_SEPARATOR.'helpers'.DIRECTORY_SEPARATOR.'helper.php';
		if(file_exists($hikashopHelper)) {
			if(!include_once($hikashopHelper))
				return;
			$apiHelper = hikashop_get('helper.api', $this->params);
			if(empty($apiHelper))
				$apiHelper = new HikApiHelper($this->params);
		} else
			$apiHelper = new HikApiHelper($this->params);

		//
		$ret = $apiHelper->processQuery($call_url);

		// If the Query has not been processed, we generate an error message
		if($ret == false)
			$apiHelper->render(array(
				'error' => '',
				'message' => 'invalid request'
			));

		//
		exit;
	}
}